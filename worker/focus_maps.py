"""Multi-measure focus map: Laplacian + Tenengrad + multi-scale local variance."""
from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Sequence

import cv2
import numpy as np

logger = logging.getLogger(__name__)


@dataclass
class FocusMapsConfig:
    laplacian_weight: float = 0.5
    tenengrad_weight: float = 0.3
    variance_weight: float = 0.2
    gaussian_sigma: float = 0.8
    local_var_window: int = 7                     # geriye dönük uyumluluk
    variance_scales: tuple[int, ...] = (3, 7, 15) # çok-ölçekli pencereler
    min_texture_threshold: float = 0.01


def _gray(img: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img


def _laplacian(g: np.ndarray) -> np.ndarray:
    return np.abs(cv2.Laplacian(g, cv2.CV_32F, ksize=3))


def _tenengrad(g: np.ndarray) -> np.ndarray:
    gx = cv2.Sobel(g, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(g, cv2.CV_32F, 0, 1, ksize=3)
    return np.sqrt(gx * gx + gy * gy)


def _local_var(g: np.ndarray, window: int) -> np.ndarray:
    """Tek ölçekli yerel varyans (geriye dönük uyumluluk)."""
    k = (window, window)
    m1 = cv2.blur(g, k)
    m2 = cv2.blur(g * g, k)
    return np.maximum(m2 - m1 * m1, 0.0)


def _multi_scale_variance(g: np.ndarray, scales: Sequence[int] = (3, 7, 15)) -> np.ndarray:
    """3 farklı pencere boyutunda varyans hesapla, piksel-bazlı maksimumu al.
    Farklı detay ölçeklerini (ince anten/kıl + kalın kenar) aynı anda yakalar."""
    result = np.zeros_like(g, dtype=np.float32)
    for k in scales:
        k = int(k)
        if k < 3:
            continue
        if k % 2 == 0:      # çift pencere → tek yap
            k += 1
        m1 = cv2.blur(g, (k, k))
        m2 = cv2.blur(g * g, (k, k))
        v = np.maximum(m2 - m1 * m1, 0.0)
        result = np.maximum(result, v)
    return result


def _normalize_global(m: np.ndarray) -> np.ndarray:
    lo, hi = np.percentile(m, 1.0), np.percentile(m, 99.0)
    if hi - lo < 1e-8:
        return np.zeros_like(m, dtype=np.float32)
    return np.clip((m - lo) / (hi - lo), 0.0, 1.0).astype(np.float32)


def compute_focus_maps(
    images: Sequence[np.ndarray],
    cfg: FocusMapsConfig | None = None,
) -> tuple[np.ndarray, np.ndarray]:
    """Returns: (focus_maps (N,H,W) f32, texture (H,W) f32)"""
    cfg = cfg or FocusMapsConfig()
    N = len(images)
    if N == 0:
        raise ValueError("empty stack")

    H, W = images[0].shape[:2]
    lap = np.empty((N, H, W), dtype=np.float32)
    ten = np.empty((N, H, W), dtype=np.float32)
    var = np.empty((N, H, W), dtype=np.float32)

    for i, img in enumerate(images):
        g = _gray(img).astype(np.float32)
        # uint8 girdi ise 0-255 aralığını 0-1'e indir
        if g.max() > 1.5:
            g /= 255.0
        if cfg.gaussian_sigma > 0:
            g = cv2.GaussianBlur(g, (0, 0), cfg.gaussian_sigma)
        lap[i] = _laplacian(g)
        ten[i] = _tenengrad(g)
        var[i] = _multi_scale_variance(g, cfg.variance_scales)

    lap, ten, var = _normalize_global(lap), _normalize_global(ten), _normalize_global(var)

    w = (cfg.laplacian_weight, cfg.tenengrad_weight, cfg.variance_weight)
    tot = sum(w) or 1.0
    focus = (w[0] * lap + w[1] * ten + w[2] * var) / tot

    texture = np.max(focus, axis=0)
    focus = np.where(texture[None] < cfg.min_texture_threshold, 0.0, focus)
    return focus.astype(np.float32), texture.astype(np.float32)