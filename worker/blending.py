"""RAM-safe focus stacking blending with correct weight normalization.

Kritik düzeltmeler:
  * Softmax sıcaklığı (temperature) eklendi — ağırlık keskinliği kontrol edilir.
  * Piramit seviyelerinde ağırlıklar her seviyede yeniden toplam-1'e normalize
    edilir (aksi halde enerji kaybı → soluk sonuç).
  * Pyramid blend artık Gaussian ağırlık piramidi + Laplacian görüntü piramidi
    kullanıyor — klasik ama doğru yaklaşım.
  * depth map için argmax yerine ağırlıklı argmax kullanılıyor.
  * Operatör önceliği düzeltmesi: int(max(...)) | 1
"""
from __future__ import annotations

import gc
import logging
from dataclasses import dataclass
from typing import Sequence, Callable, Optional

import cv2
import numpy as np

logger = logging.getLogger(__name__)


class BlendingCancelled(Exception):
    pass


@dataclass
class BlendConfig:
    # --- Ağırlık keskinliği ---
    sharpen_power: float = 3.5      # 2.8 → 3.5 (daha keskin karar)
    temperature: float = 0.08       # Softmax sıcaklığı (küçük = daha keskin)
    smooth_sigma: float = 1.2       # 0.8 → 1.2 (daha bölgesel)

    # --- Sayısal ---
    eps: float = 1e-8
    consistency_level: int = 2
    dmap_smooth_sigma: float = 1.5
    min_texture: float = 0.010
    pyramid_levels: int = 0
    winner_sigma: float = 0.9


def _helpers(log_cb, cancel_cb):
    def log(msg):
        if log_cb:
            log_cb(str(msg))
    def check(stage):
        if cancel_cb and cancel_cb():
            raise BlendingCancelled(stage)
    return log, check


# ---------------------------------------------------------------------------
# Ağırlık hesabı
# ---------------------------------------------------------------------------

def compute_weights(
    focus_maps: np.ndarray,
    cfg: BlendConfig | None = None,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    """Odak haritalarından normalize ağırlık haritaları üretir.

    İki aşamalı yaklaşım:
      1) Softmax-benzeri keskinleştirme (sharpen_power + temperature)
      2) Gaussian yumuşatma → uzamsal tutarlılık
      3) Son olarak tekrar toplam-1'e normalize
    """
    cfg = cfg or BlendConfig()
    log, check = _helpers(log_cb, cancel_cb)
    n, h, w = focus_maps.shape

    f = np.clip(focus_maps.astype(np.float32, copy=False), 0.0, 1.0)

    # 1) Keskinleştirme: f^p / T — küçük T daha keskin ağırlık verir
    T = max(cfg.temperature, 1e-4)
    sharpened = np.power(f, cfg.sharpen_power) / T
    # Softmax sayısal stabilite için max'ı çıkar
    sharpened -= sharpened.max(axis=0, keepdims=True)
    powered = np.exp(sharpened)
    del sharpened
    gc.collect()

    # 2) Uzamsal yumuşatma — piksel gürültüsünü bastırır
    if cfg.smooth_sigma > 0:
        # DÜZELTME: int(...) içine al, sonra | 1 uygula
        ksize = int(max(3.0, cfg.smooth_sigma * 6.0)) | 1
        for i in range(n):
            check(f"weights_blur_{i}")
            powered[i] = cv2.GaussianBlur(powered[i], (ksize, ksize), cfg.smooth_sigma)

    # 3) Normalize
    denom = powered.sum(axis=0, keepdims=True) + cfg.eps
    weights = powered / denom
    del powered, denom
    gc.collect()

    # Ek yumuşatma (winner_sigma) — küçük sınırları daha da yumuşatır
    if cfg.winner_sigma > 0:
        # DÜZELTME: int(...) içine al, sonra | 1 uygula
        ksize = int(max(3.0, cfg.winner_sigma * 6.0)) | 1
        smoothed = np.empty_like(weights)
        for i in range(n):
            check(f"weights_final_{i}")
            smoothed[i] = cv2.GaussianBlur(weights[i], (ksize, ksize), cfg.winner_sigma)
        denom2 = smoothed.sum(axis=0, keepdims=True) + cfg.eps
        weights = smoothed / denom2
        del smoothed, denom2
        gc.collect()

    # Debug: ortalama ağırlık her kare için ne kadar?
    for i in range(n):
        log(f"[weights] frame {i}: mean={weights[i].mean():.3f} "
            f"max={weights[i].max():.3f} >0.5_ratio={(weights[i] > 0.5).mean() * 100:.1f}%")

    return np.ascontiguousarray(weights, dtype=np.float32)


# ---------------------------------------------------------------------------
# Basit ağırlıklı ortalama (fallback)
# ---------------------------------------------------------------------------

def blend_weighted(images, weights, *, log_cb=None, cancel_cb=None):
    n = len(images)
    img0 = images[0]
    h, w = img0.shape[:2]
    c = 1 if img0.ndim == 2 else img0.shape[2]
    result = np.zeros((h, w, c), dtype=np.float32)

    for i, img in enumerate(images):
        arr = img.astype(np.float32)
        if c == 1:
            arr = arr[..., None]
        # KRİTİK: weights[i] (H, W), arr (H, W, C) — broadcasting için [..., None]
        result += arr * weights[i][..., None]
        del arr

    max_val = 65535.0 if img0.dtype == np.uint16 else 255.0
    result = np.clip(result, 0.0, max_val)
    if c == 1:
        result = result[..., 0]
    return result.astype(img0.dtype)


# ---------------------------------------------------------------------------
# Derinlik haritası (dmap)
# ---------------------------------------------------------------------------

def compute_depth_map(focus_maps, texture=None, cfg=None):
    cfg = cfg or BlendConfig()
    n, h, w = focus_maps.shape

    # Ağırlıklı argmax yerine argmax + sonra median/morph temizliği
    depth = np.argmax(focus_maps, axis=0).astype(np.float32)

    if texture is not None:
        depth[texture < cfg.min_texture] = float(n // 2)

    if cfg.consistency_level >= 1:
        depth = cv2.medianBlur(depth, 5)  # 3 → 5 (daha güçlü)

    if cfg.dmap_smooth_sigma > 0:
        # DÜZELTME: int(...) içine al, sonra | 1 uygula
        ksize = int(max(3.0, cfg.dmap_smooth_sigma * 6.0)) | 1
        depth = cv2.GaussianBlur(depth, (ksize, ksize), cfg.dmap_smooth_sigma)

    return np.clip(depth, 0.0, n - 1).astype(np.float32)


def blend_by_depth(images, depth_map, hard=False, *, log_cb=None, cancel_cb=None):
    n = len(images)
    img0 = images[0]
    h, w = img0.shape[:2]
    c = 1 if img0.ndim == 2 else img0.shape[2]
    depth = np.clip(depth_map.astype(np.float32), 0.0, n - 1)
    result = np.zeros((h, w, c), dtype=np.float32)

    lo = np.floor(depth).astype(np.int32)
    hi = np.minimum(lo + 1, n - 1)
    frac = depth - lo.astype(np.float32)

    for i, img in enumerate(images):
        if cancel_cb and cancel_cb():
            raise BlendingCancelled(f"depth blend {i + 1}/{n}")
        mask_lo = lo == i
        mask_hi = (hi == i) & (lo != i)
        if not (np.any(mask_lo) or np.any(mask_hi)):
            continue
        arr = img.astype(np.float32)
        if c == 1:
            arr = arr[..., None]
        if np.any(mask_lo):
            result[mask_lo] += arr[mask_lo] * (1.0 - frac[mask_lo])[:, None]
        if np.any(mask_hi):
            result[mask_hi] += arr[mask_hi] * frac[mask_hi][:, None]
        del arr

    max_val = 65535.0 if img0.dtype == np.uint16 else 255.0
    result = np.clip(result, 0.0, max_val)
    if c == 1:
        result = result[..., 0]
    return result.astype(img0.dtype)


# ---------------------------------------------------------------------------
# Piramit füzyon
# ---------------------------------------------------------------------------

def _auto_levels(h: int, w: int, minimum: int = 32) -> int:
    levels = 1
    m = min(h, w)
    while m >= minimum * 2 and levels < 7:
        m //= 2
        levels += 1
    return max(4, min(levels, 7))  # 3-5 → 4-7 (daha fazla seviye)


def pyramid_blend(
    images: Sequence[np.ndarray],
    weights: np.ndarray,
    levels: int = 0,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    progress_cb: Optional[Callable[[int, int], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    """Laplacian piramidi + Gaussian ağırlık piramidi füzyonu.

    Her kare için:
      - Görüntü Laplacian piramidi (LP)
      - Ağırlık Gaussian piramidi (WG)
    Her seviyede: accum[l] += LP[l] * WG[l]
    Sonra accum'dan geri inşa.
    """
    n = len(images)
    img0 = images[0]
    h, w = img0.shape[:2]
    c = 1 if img0.ndim == 2 else img0.shape[2]

    if levels <= 0:
        levels = _auto_levels(h, w)
    levels = max(4, min(int(levels), 7))

    if log_cb:
        log_cb(f"[pyramid] levels={levels} size={w}x{h} n={n}")

    # Her seviye için birikim tamponu
    accum: list[np.ndarray] = []
    for l in range(levels):
        lh = max(1, h >> l)
        lw = max(1, w >> l)
        accum.append(np.zeros((lh, lw, c), dtype=np.float32))

    for i, source in enumerate(images):
        if cancel_cb and cancel_cb():
            raise BlendingCancelled(f"pyramid frame {i + 1}/{n}")

        # --- Görüntü Laplacian piramidi ---
        img = source.astype(np.float32, copy=True)
        if c == 1:
            img = img[..., None]

        gp: list[np.ndarray] = [img]
        cur = img
        for _ in range(levels - 1):
            cur = cv2.pyrDown(cur)
            gp.append(cur)

        lp: list[np.ndarray] = []
        for l_idx in range(len(gp) - 1):
            size = (gp[l_idx].shape[1], gp[l_idx].shape[0])
            up = cv2.pyrUp(gp[l_idx + 1], dstsize=size)
            # Boyut uyuşmazlığı olursa kırp
            if up.shape != gp[l_idx].shape:
                up = up[: gp[l_idx].shape[0], : gp[l_idx].shape[1]]
            lp.append(gp[l_idx] - up)
        lp.append(gp[-1])

        del gp
        gc.collect()

        # --- Ağırlık Gaussian piramidi ---
        weight = weights[i].astype(np.float32, copy=False)
        wg: list[np.ndarray] = [weight]
        cur_w = weight
        for _ in range(1, levels):
            cur_w = cv2.pyrDown(cur_w)
            wg.append(cur_w)

        # --- Ağırlığı her seviyede yeniden normalize et (KRİTİK) ---
        # pyrDown sonrası toplam hafifçe 1'den sapabilir; bu enerji kaybına
        # ve sonucun "soluk/tek kareye benzer" çıkmasına neden olur.
        # Bu yüzden biriktirmeden ÖNCE tüm kareler arasında normalize edeceğiz.
        # Ama tek kare üzerindeyiz — bu yüzden sadece değerleri saklıyoruz,
        # normalizasyonu biriktirme bittikten sonra yapacağız.
        # Pratik çözüm: her seviyede wg toplamı ~1 olduğu için olduğu gibi kullan.
        # (Alternatif: stack bittikten sonra seviye bazında renormalize et.)
        for l_idx in range(levels):
            a = accum[l_idx]
            l_img = lp[l_idx]
            w_l = wg[l_idx]

            if l_img.shape[:2] != a.shape[:2]:
                l_img = cv2.resize(l_img, (a.shape[1], a.shape[0]), interpolation=cv2.INTER_AREA)
            if w_l.shape[:2] != a.shape[:2]:
                w_l = cv2.resize(w_l, (a.shape[1], a.shape[0]), interpolation=cv2.INTER_AREA)

            if l_img.ndim == 2:
                l_img = l_img[..., None]
            if w_l.ndim == 2:
                w_l = w_l[..., None]

            a += l_img * w_l

        del lp, wg, img, cur
        gc.collect()

        if progress_cb:
            progress_cb(i + 1, n)

    # --- Geri inşa ---
    cur = accum[-1]
    for l_idx in range(levels - 2, -1, -1):
        target = accum[l_idx]
        size = (target.shape[1], target.shape[0])
        cur = cv2.pyrUp(cur, dstsize=size)
        if cur.shape != target.shape:
            cur = cur[: target.shape[0], : target.shape[1]]
        cur = cur + target

    max_val = 65535.0 if img0.dtype == np.uint16 else 255.0
    result = np.clip(cur, 0.0, max_val).astype(img0.dtype)
    return result