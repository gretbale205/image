"""Multi-measure focus map: Laplacian + Tenengrad + local variance.

v3.2: progress_cb / cancel_cb desteği — uzun hesaplarda canlı yüzde
      ve iptal kontrolü.
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Sequence, Callable, Optional

import cv2
import numpy as np

logger = logging.getLogger(__name__)


class FocusMapsCancelled(Exception):
    """Kullanıcı iptal ettiğinde fırlatılır (focus_stack yakalar)."""
    pass


@dataclass
class FocusMapsConfig:
    laplacian_weight: float = 0.5
    tenengrad_weight: float = 0.3
    variance_weight: float = 0.2
    gaussian_sigma: float = 0.8
    local_var_window: int = 7
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
    k = (window, window)
    m1 = cv2.blur(g, k)
    m2 = cv2.blur(g * g, k)
    return np.maximum(m2 - m1 * m1, 0.0)


def _normalize_global(m: np.ndarray) -> np.ndarray:
    lo, hi = np.percentile(m, 1.0), np.percentile(m, 99.0)
    if hi - lo < 1e-8:
        return np.zeros_like(m, dtype=np.float32)
    return np.clip((m - lo) / (hi - lo), 0.0, 1.0).astype(np.float32)


def compute_focus_maps(
    images: Sequence[np.ndarray],
    cfg: FocusMapsConfig | None = None,
    *,
    progress_cb: Optional[Callable[[int, int], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
    log_cb: Optional[Callable[[str], None]] = None,
) -> tuple[np.ndarray, np.ndarray]:
    """
    Returns: (focus_maps (N,H,W) f32, texture (H,W) f32)

    progress_cb(i, N): i. görüntü tamamlandı (i = 1..N)
    cancel_cb() -> bool: True dönerse FocusMapsCancelled fırlatır
    log_cb(msg): canlı log satırı (worker PID loguna gider)
    """
    cfg = cfg or FocusMapsConfig()
    N = len(images)
    if N == 0:
        raise ValueError("empty stack")

    def _log(msg: str) -> None:
        try:
            print(msg, flush=True)
        except Exception:
            pass
        if log_cb:
            try:
                log_cb(msg)
            except Exception:
                pass

    def _cancelled() -> bool:
        if cancel_cb:
            try:
                return bool(cancel_cb())
            except Exception:
                return False
        return False

    H, W = images[0].shape[:2]
    lap = np.empty((N, H, W), dtype=np.float32)
    ten = np.empty((N, H, W), dtype=np.float32)
    var = np.empty((N, H, W), dtype=np.float32)

    _log(f"[focus_maps] başlıyor: N={N}  {W}x{H}")

    t_start = time.perf_counter()
    for i, img in enumerate(images):
        if _cancelled():
            _log(f"[focus_maps] ⛔ İPTAL: {i+1}/{N} (henüz başlamadan)")
            raise FocusMapsCancelled(f"cancelled at {i+1}/{N}")

        t0 = time.perf_counter()

        g = _gray(img).astype(np.float32)
        if g.max() > 1.5:
            g /= 255.0
        if cfg.gaussian_sigma > 0:
            g = cv2.GaussianBlur(g, (0, 0), cfg.gaussian_sigma)

        lap[i] = _laplacian(g)
        ten[i] = _tenengrad(g)
        var[i] = _local_var(g, cfg.local_var_window)

        dt = time.perf_counter() - t0
        _log(f"[focus_maps] {i+1}/{N} tamamlandı ({dt:.1f}s)")

        if progress_cb:
            try:
                progress_cb(i + 1, N)
            except Exception as e:
                _log(f"[focus_maps] progress_cb hatası: {e}")

    total = time.perf_counter() - t_start
    _log(f"[focus_maps] normalize başlıyor (toplam {total:.1f}s)")

    lap = _normalize_global(lap)
    ten = _normalize_global(ten)
    var = _normalize_global(var)

    w = (cfg.laplacian_weight, cfg.tenengrad_weight, cfg.variance_weight)
    tot = sum(w) or 1.0
    focus = (w[0] * lap + w[1] * ten + w[2] * var) / tot

    texture = np.max(focus, axis=0)
    focus = np.where(texture[None] < cfg.min_texture_threshold, 0.0, focus)

    _log(f"[focus_maps] bitti: toplam {time.perf_counter()-t_start:.1f}s")

    return focus.astype(np.float32), texture.astype(np.float32)