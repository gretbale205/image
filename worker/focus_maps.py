"""Focus-energy engine with SML, true entropy and GLOBAL cross-image normalization.

Kritik düzeltmeler:
  * _local_entropy artık gerçek entropi hesaplıyor (histogram tabanlı).
  * SML (Sum of Modified Laplacian) eklendi — makro/mücevherat için en iyi ölçüt.
  * GLOBAL normalizasyon: Tüm karelerin ham skorları birleştirilip TEK seferde
    normalize ediliyor. Böylece kareler arası karşılaştırma doğru çalışıyor.
  * Per-image percentile normalizasyonu KALDIRILDI (yan etkisi yukarıdaki bug).
  * Son odak haritası üzerine daha geniş Gaussian (bölgesel karar için).
"""
from __future__ import annotations

import gc
import logging
from dataclasses import dataclass
from typing import Sequence, Callable, Optional

import cv2
import numpy as np

logger = logging.getLogger(__name__)


class FocusMapsCancelled(Exception):
    pass


@dataclass
class FocusMapsConfig:
    # --- Ağırlıklar (toplamları ~1.0) ---
    sml_weight: float = 0.35           # Sum of Modified Laplacian (yeni, güçlü)
    tenengrad_weight: float = 0.20
    variance_weight: float = 0.15
    entropy_weight: float = 0.15
    laplacian_weight: float = 0.15     # Klasik Laplacian (yedek)

    # --- Skor hesabı ---
    gaussian_sigma: float = 0.6        # Ön-yumuşatma (gürültü bastırma)
    sml_window: int = 5                # SML komşuluk penceresi
    local_var_window: int = 9
    entropy_window: int = 11
    entropy_bins: int = 16

    # --- Son harita yumuşatma (bölgesel karar için ŞART) ---
    energy_sigma: float = 2.2          # 0.65 → 2.2 (bu kritik!)

    # --- Global normalizasyon ---
    percentile_low: float = 0.5
    percentile_high: float = 99.5

    # --- Doku maskesi ---
    min_texture_threshold: float = 0.010   # 0.030 → 0.010 (daha az agresif)


def _gray(img: np.ndarray) -> np.ndarray:
    if img.ndim == 3:
        return cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return img


# ---------------------------------------------------------------------------
# Odak ölçütleri
# ---------------------------------------------------------------------------

def _modified_laplacian(g: np.ndarray) -> np.ndarray:
    """Klasik ML: |Lx| + |Ly| (Nayar & Nakagawa)."""
    k = np.array([-1.0, 2.0, -1.0], dtype=np.float32)
    lx = cv2.filter2D(g, cv2.CV_32F, k.reshape(1, 3))
    ly = cv2.filter2D(g, cv2.CV_32F, k.reshape(3, 1))
    return np.abs(lx) + np.abs(ly)


def _sml(g: np.ndarray, window: int = 5) -> np.ndarray:
    """Sum of Modified Laplacian — yerel pencere içindeki ML toplamı.
    Klasik ML'den daha sağlam, özellikle düşük dokulu bölgelerde."""
    ml = _modified_laplacian(g)
    k = np.ones((window, window), dtype=np.float32)
    return cv2.filter2D(ml, cv2.CV_32F, k, borderType=cv2.BORDER_REFLECT)


def _tenengrad(g: np.ndarray) -> np.ndarray:
    gx = cv2.Sobel(g, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(g, cv2.CV_32F, 0, 1, ksize=3)
    return gx * gx + gy * gy


def _laplacian_energy(g: np.ndarray) -> np.ndarray:
    lap = cv2.Laplacian(g, cv2.CV_32F, ksize=3)
    return lap * lap


def _local_variance(g: np.ndarray, window: int) -> np.ndarray:
    k = (int(window), int(window))
    mean = cv2.boxFilter(g, cv2.CV_32F, k, normalize=True)
    mean2 = cv2.boxFilter(g * g, cv2.CV_32F, k, normalize=True)
    return np.maximum(mean2 - mean * mean, 0.0)


def _local_entropy(g: np.ndarray, window: int = 11, bins: int = 16) -> np.ndarray:
    """GERÇEK yerel entropi (histogram tabanlı).

    Eski versiyon yanlışlıkla varyans hesaplıyordu. Bu versiyon her piksel
    çevresindeki yerel histogramdan Shannon entropisini hesaplar:
        H = -sum(p * log2(p + eps))
    """
    u8 = np.clip(g * 255.0, 0, 255).astype(np.uint8)

    # Kuantize et (bin sayısına indir)
    q = (u8.astype(np.float32) * (bins / 256.0)).astype(np.uint8)

    # Her bin için "1-hot" görüntü oluştur, sonra boxFilter ile yerel yoğunluk
    ent = np.zeros(g.shape, dtype=np.float32)
    eps = 1e-6
    k = (int(window), int(window))
    for b in range(bins):
        mask = (q == b).astype(np.float32)
        p = cv2.boxFilter(mask, cv2.CV_32F, k, normalize=True)
        ent -= p * np.log2(p + eps)
    return np.maximum(ent, 0.0)


# ---------------------------------------------------------------------------
# Normalizasyon
# ---------------------------------------------------------------------------

def _global_normalize(stack: np.ndarray, low: float, high: float) -> np.ndarray:
    """TÜM kareler üzerinden tek seferde normalize eder.

    stack: (N, H, W) float32
    Dönüş: aynı boyutta, [0,1] aralığında global min-max.
    """
    lo = float(np.percentile(stack, low))
    hi = float(np.percentile(stack, high))
    if hi - lo < 1e-7:
        return np.zeros_like(stack, dtype=np.float32)
    out = (stack - lo) / (hi - lo)
    return np.clip(out, 0.0, 1.0).astype(np.float32, copy=False)


# ---------------------------------------------------------------------------
# Ana fonksiyon
# ---------------------------------------------------------------------------

def compute_focus_maps(
    images: Sequence[np.ndarray],
    cfg: FocusMapsConfig | None = None,
    *,
    progress_cb: Optional[Callable[[int, int], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
    log_cb: Optional[Callable[[str], None]] = None,
) -> tuple[np.ndarray, np.ndarray]:
    """Her kare için odak haritası + global doku haritası döner.

    Dönüş:
        focus  : (N, H, W) float32, [0,1] aralığında GLOBAL normalize edilmiş
        texture: (H, W) float32, tüm karelerin max odak değeri
    """
    cfg = cfg or FocusMapsConfig()
    n = len(images)
    if n == 0:
        raise ValueError("empty stack")

    h, w = images[0].shape[:2]

    def log(msg: str) -> None:
        if log_cb:
            try:
                log_cb(msg)
            except Exception:
                pass

    # Ağırlıkları normalize et
    total_w = (
        cfg.sml_weight
        + cfg.tenengrad_weight
        + cfg.variance_weight
        + cfg.entropy_weight
        + cfg.laplacian_weight
    )
    if total_w <= 0:
        total_w = 1.0
    w_sml = cfg.sml_weight / total_w
    w_tng = cfg.tenengrad_weight / total_w
    w_var = cfg.variance_weight / total_w
    w_ent = cfg.entropy_weight / total_w
    w_lap = cfg.laplacian_weight / total_w

    log(f"[focus_maps] n={n} size={w}x{h} "
        f"weights(sml={w_sml:.2f},tng={w_tng:.2f},var={w_var:.2f},"
        f"ent={w_ent:.2f},lap={w_lap:.2f})")

    # ---------- PASS 1: Her karenin HAM skorunu hesapla ----------
    raw = np.empty((n, h, w), dtype=np.float32)

    for i, img in enumerate(images):
        if cancel_cb and cancel_cb():
            raise FocusMapsCancelled(f"cancelled at {i + 1}/{n}")

        g = _gray(img).astype(np.float32)
        if g.max() > 1.5:
            g /= 255.0

        # Gürültü bastırma (hafif ön yumuşatma)
        if cfg.gaussian_sigma > 0:
            g = cv2.GaussianBlur(g, (0, 0), cfg.gaussian_sigma)

        sml = _sml(g, cfg.sml_window)
        tng = _tenengrad(g)
        var = _local_variance(g, cfg.local_var_window)
        ent = _local_entropy(g, cfg.entropy_window, cfg.entropy_bins)
        lap = _laplacian_energy(g)

        # Ham (normalize edilmemiş) skor — pozlama farklarını yok etmek için
        # her kanal kendi max'ı ile bölünür, ama SADECE bu kare içinde.
        # Global karşılaştırma zaten aşağıda yapılacak.
        def _safe(m):
            mx = float(m.max())
            return m / mx if mx > 1e-9 else m

        score = (
            w_sml * _safe(sml)
            + w_tng * _safe(tng)
            + w_var * _safe(var)
            + w_ent * _safe(ent)
            + w_lap * _safe(lap)
        )

        raw[i] = score
        if progress_cb:
            progress_cb(i + 1, n)

    # ---------- PASS 2: GLOBAL normalizasyon ----------
    # KRİTİK: Tüm kareler birlikte normalize edilir. Bu olmadan her kare
    # kendi içinde [0,1]'e sığar ve kareler arası karşılaştırma bozulur.
    log("[focus_maps] global normalization...")
    focus = _global_normalize(raw, cfg.percentile_low, cfg.percentile_high)
    del raw
    gc.collect()

    # ---------- PASS 3: Son bölgesel yumuşatma ----------
    # Küçük sigma → benekli harita → füzyon ortalamaya kayar (BUG).
    # Büyük sigma → bölgesel tutarlılık → net sonuç.
    if cfg.energy_sigma > 0:
        # DÜZELTME: int(...) içine al, sonra | 1 uygula (operatör önceliği)
        ksize = int(max(3.0, cfg.energy_sigma * 6.0)) | 1
        for i in range(n):
            if cancel_cb and cancel_cb():
                raise FocusMapsCancelled(f"cancelled at smoothing {i + 1}/{n}")
            focus[i] = cv2.GaussianBlur(
                focus[i], (ksize, ksize), cfg.energy_sigma
            )
        # Blur sonrası tekrar global normalize et (değer aralığı kayabilir)
        focus = _global_normalize(focus, 0.0, 100.0)

    # ---------- Doku haritası ----------
    texture = np.max(focus, axis=0)
    # Çok düşük dokulu pikselleri işaretle (sonradan füzyonda kullanılır)
    focus[:, texture < cfg.min_texture_threshold] = 0.0

    # Debug: her karenin ortalama skoru
    for i in range(n):
        log(f"[focus_maps] frame {i}: mean={focus[i].mean():.4f} "
            f"max={focus[i].max():.4f} nonzero={(focus[i] > 0.05).mean() * 100:.1f}%")

    return focus, texture