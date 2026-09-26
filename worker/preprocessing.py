"""Denoise + exposure normalization via histogram matching."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Sequence

import cv2
import numpy as np


@dataclass
class PreprocessConfig:
    denoise: bool = False
    denoise_h: float = 6.0
    exposure_normalize: bool = True
    max_gain: float = 1.15
    use_histogram_match: bool = True   # ⭐ gerçek histogram eşleştirme (default AÇIK)
    reference_index: int = 0


def _median_gray(gray: np.ndarray) -> float:
    return float(np.percentile(gray, 50.0)) + 1e-6


def _match_histogram(src: np.ndarray, ref: np.ndarray) -> np.ndarray:
    """
    Basit histogram eşleştirme (scikit-image bağımlılığı olmadan).
    Her kanal için CDF tabanlı lookup table uygular.
    """
    out = np.empty_like(src)
    if src.ndim == 2:
        channels = [(src, ref, 0)]
    else:
        channels = [(src[..., c], ref[..., c], c) for c in range(src.shape[2])]

    for s_ch, r_ch, c in channels:
        s_hist, _ = np.histogram(s_ch.flatten(), bins=256, range=(0, 256))
        r_hist, _ = np.histogram(r_ch.flatten(), bins=256, range=(0, 256))
        s_cdf = np.cumsum(s_hist).astype(np.float64)
        r_cdf = np.cumsum(r_hist).astype(np.float64)
        s_cdf /= s_cdf[-1] if s_cdf[-1] > 0 else 1.0
        r_cdf /= r_cdf[-1] if r_cdf[-1] > 0 else 1.0

        # Lookup table: her src değeri için en yakın ref CDF değeri
        lut = np.zeros(256, dtype=np.uint8)
        r_idx = 0
        for s_val in range(256):
            while r_idx < 255 and r_cdf[r_idx] < s_cdf[s_val]:
                r_idx += 1
            lut[s_val] = r_idx

        mapped = cv2.LUT(s_ch, lut)
        if src.ndim == 2:
            out = mapped
        else:
            out[..., c] = mapped

    return out


def _fallback_gain(img: np.ndarray, ref: np.ndarray, max_gain: float) -> np.ndarray:
    """Histogram match başarısız olursa medyan-gain yöntemine düş."""
    g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
    rg = cv2.cvtColor(ref, cv2.COLOR_BGR2GRAY) if ref.ndim == 3 else ref
    gain = float(np.clip(_median_gray(rg) / _median_gray(g),
                         1.0 / max_gain, max_gain))
    return np.clip(img.astype(np.float32) * gain, 0, 255).astype(np.uint8)


def preprocess(images: Sequence[np.ndarray],
               cfg: PreprocessConfig | None = None) -> list[np.ndarray]:
    cfg = cfg or PreprocessConfig()
    out = [img.copy() for img in images]

    # 1) Denoise (opsiyonel)
    if cfg.denoise:
        for i, img in enumerate(out):
            if img.ndim == 3:
                out[i] = cv2.fastNlMeansDenoisingColored(img, None, cfg.denoise_h,
                                                         cfg.denoise_h, 7, 21)
            else:
                out[i] = cv2.fastNlMeansDenoising(img, None, cfg.denoise_h, 7, 21)

    # 2) Exposure normalization
    if cfg.exposure_normalize and len(out) > 1:
        ref_idx = int(np.clip(cfg.reference_index, 0, len(out) - 1))
        ref = out[ref_idx]

        if cfg.use_histogram_match:
            # ✅ YENİ: gerçek histogram eşleştirme (tek geçiş, doğru sıralama)
            result: list[np.ndarray] = []
            for i, img in enumerate(out):
                if i == ref_idx:
                    result.append(ref.copy())
                    continue
                try:
                    result.append(_match_histogram(img, ref))
                except Exception as e:
                    # Histogram match başarısız → basit gain'e düş
                    result.append(_fallback_gain(img, ref, cfg.max_gain))
            out = result

        else:
            # Eski basit median-gain yöntemi
            ref_g = cv2.cvtColor(ref, cv2.COLOR_BGR2GRAY) if ref.ndim == 3 else ref
            ref_med = _median_gray(ref_g)
            for i, img in enumerate(out):
                if i == ref_idx:
                    continue
                g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
                gain = ref_med / _median_gray(g)
                gain = float(np.clip(gain, 1.0 / cfg.max_gain, cfg.max_gain))
                out[i] = np.clip(img.astype(np.float32) * gain, 0, 255).astype(np.uint8)

    return out