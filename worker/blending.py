"""Blending: softmax + DMap (memory-safe) + multi-band pyramid.

v3.2: progress_cb / cancel_cb / log_cb desteği.
  - compute_weights: her görüntü Gaussian blur sonrası progress
  - blend_weighted / blend_by_depth: her görüntü sonrası progress
  - pyramid_blend: her görüntü pyramid + her seviye sonrası progress
  - cancel_cb True dönerse BlendingCancelled fırlatır
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Sequence, Callable, Optional

import cv2
import numpy as np

logger = logging.getLogger(__name__)


class BlendingCancelled(Exception):
    """Kullanıcı iptal ettiğinde fırlatılır (focus_stack yakalar)."""
    pass


@dataclass
class BlendConfig:
    # Softmax parametreleri
    sharpen_power: float = 1.5
    smooth_sigma: float = 1.5
    eps: float = 1e-8

    # DMap parametreleri
    consistency_level: int = 2
    dmap_smooth_sigma: float = 1.5
    min_texture: float = 0.01

    # Pyramid parametreleri
    pyramid_levels: int = 5


# ---------------------------------------------------------------------------
# Callback yardımcıları
# ---------------------------------------------------------------------------

def _mk_helpers(
    log_cb: Optional[Callable[[str], None]],
    progress_cb: Optional[Callable[[int, str], None]],
    cancel_cb: Optional[Callable[[], bool]],
):
    def _log(msg: str) -> None:
        try:
            print(msg, flush=True)
        except Exception:
            pass
        if log_cb:
            try:
                log_cb(str(msg))
            except Exception:
                pass

    def _prog(pct: int, stage: str) -> None:
        if progress_cb:
            try:
                progress_cb(int(pct), str(stage))
            except Exception as e:
                _log(f"[blend] progress_cb hatası: {e}")

    def _cancelled() -> bool:
        if cancel_cb:
            try:
                return bool(cancel_cb())
            except Exception:
                return False
        return False

    def _check(stage_name: str) -> None:
        if _cancelled():
            _log(f"[blend] ⛔ İPTAL: {stage_name}")
            raise BlendingCancelled(stage_name)

    return _log, _prog, _cancelled, _check


# ---------------------------------------------------------------------------
# Softmax (PMax benzeri)
# ---------------------------------------------------------------------------

def compute_weights(
    focus_maps: np.ndarray,
    cfg: BlendConfig | None = None,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    cfg = cfg or BlendConfig()
    _log, _prog, _cancelled, _check = _mk_helpers(log_cb, None, cancel_cb)

    powered = np.power(np.clip(focus_maps, 0.0, 1.0), cfg.sharpen_power)
    denom = powered.sum(axis=0, keepdims=True) + cfg.eps
    weights = powered / denom

    if cfg.smooth_sigma > 0:
        N = weights.shape[0]
        sm = np.empty_like(weights)
        _log(f"[blend] compute_weights: {N} görüntü Gaussian blur")
        for i in range(N):
            _check(f"weights_blur_{i+1}/{N}")
            sm[i] = cv2.GaussianBlur(weights[i], (0, 0), cfg.smooth_sigma)
            _log(f"[blend] weights blur {i+1}/{N}")
        weights = sm / (sm.sum(axis=0, keepdims=True) + cfg.eps)

    return weights.astype(np.float32)


def blend_weighted(
    images: Sequence[np.ndarray],
    weights: np.ndarray,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    N = len(images)
    if N == 0:
        raise ValueError("empty images")

    _log, _prog, _cancelled, _check = _mk_helpers(log_cb, None, cancel_cb)

    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    acc = np.zeros((H, W, C), dtype=np.float32)
    _log(f"[blend] blend_weighted: {N} görüntü")

    for i, img in enumerate(images):
        _check(f"blend_weighted_{i+1}/{N}")
        arr = img.astype(np.float32)
        if C == 1:
            arr = arr[..., None]
        acc += arr * weights[i][..., None]
        _log(f"[blend] weighted {i+1}/{N}")

    acc = np.clip(acc, 0.0, max_val)
    if C == 1:
        acc = acc[..., 0]
    return acc.astype(np.float32) if is_float else acc.astype(np.uint8)


# ---------------------------------------------------------------------------
# DMap — Depth map approach (bellek-safe)
# ---------------------------------------------------------------------------

def compute_depth_map(
    focus_maps: np.ndarray,
    texture: np.ndarray | None = None,
    cfg: BlendConfig | None = None,
) -> np.ndarray:
    cfg = cfg or BlendConfig()
    N, H, W = focus_maps.shape

    depth = np.argmax(focus_maps, axis=0).astype(np.float32)

    if texture is not None:
        low_conf = texture < cfg.min_texture
        if np.any(low_conf):
            depth[low_conf] = float(N // 2)

    if cfg.consistency_level > 0:
        if cfg.consistency_level == 1:
            depth = cv2.medianBlur(depth, 3)
        else:
            depth = cv2.medianBlur(depth, 5)
            depth = cv2.medianBlur(depth, 5)

    if cfg.dmap_smooth_sigma > 0:
        depth = cv2.GaussianBlur(depth, (0, 0), cfg.dmap_smooth_sigma)

    return np.clip(depth, 0.0, N - 1).astype(np.float32)


def blend_by_depth(
    images: Sequence[np.ndarray],
    depth_map: np.ndarray,
    hard: bool = False,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    """
    Depth map kullanarak harmanla. BELLEK-SAF: kare başına tek geçiş.
    """
    N = len(images)
    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    _log, _prog, _cancelled, _check = _mk_helpers(log_cb, None, cancel_cb)

    depth = np.clip(depth_map.astype(np.float32), 0.0, N - 1)
    result = np.zeros((H, W, C), dtype=np.float32)

    _log(f"[blend] blend_by_depth: {N} görüntü (hard={hard})")

    if hard:
        idx = np.round(depth).astype(np.int32)
        for i in range(N):
            _check(f"depth_hard_{i+1}/{N}")
            m = (idx == i)
            if not np.any(m):
                continue
            arr = images[i].astype(np.float32)
            if C == 1:
                arr = arr[..., None]
            result[m] = arr[m]
            _log(f"[blend] depth {i+1}/{N}")
    else:
        lo = np.clip(np.floor(depth).astype(np.int32), 0, N - 1)
        hi = np.clip(lo + 1, 0, N - 1)
        frac = (depth - lo.astype(np.float32)).astype(np.float32)

        for i in range(N):
            _check(f"depth_lerp_{i+1}/{N}")
            m_lo = (lo == i)
            m_hi = (hi == i) & (lo != i)
            if not (np.any(m_lo) or np.any(m_hi)):
                continue

            arr = images[i].astype(np.float32)
            if C == 1:
                arr = arr[..., None]

            if np.any(m_lo):
                w_lo = (1.0 - frac[m_lo])[..., None]
                result[m_lo] += arr[m_lo] * w_lo
            if np.any(m_hi):
                w_hi = frac[m_hi][..., None]
                result[m_hi] += arr[m_hi] * w_hi
            _log(f"[blend] depth {i+1}/{N}")

    result = np.clip(result, 0.0, max_val)
    if C == 1:
        result = result[..., 0]
    return result.astype(np.float32) if is_float else result.astype(np.uint8)


# ---------------------------------------------------------------------------
# Multi-band pyramid blending (en yavaş adım — tam progress + cancel)
# ---------------------------------------------------------------------------

def _gaussian_pyramid(img: np.ndarray, levels: int) -> list[np.ndarray]:
    pyr = [img]
    cur = img
    for _ in range(levels - 1):
        down = cv2.pyrDown(cur)
        # pyrDown, (H,W,1) → (H/2,W/2) düşürür; kanal boyutunu geri koy
        if cur.ndim == 3 and down.ndim == 2:
            down = down[..., None]
        cur = down
        pyr.append(cur)
    return pyr


def _laplacian_pyramid_from_gauss(gp: list[np.ndarray]) -> list[np.ndarray]:
    lp = []
    for i in range(len(gp) - 1):
        size = (gp[i].shape[1], gp[i].shape[0])
        up = cv2.pyrUp(gp[i + 1], dstsize=size)
        lp.append(gp[i].astype(np.float32) - up.astype(np.float32))
    lp.append(gp[-1].astype(np.float32))
    return lp


def pyramid_blend(
    images: Sequence[np.ndarray],
    weights: np.ndarray,
    levels: int = 5,
    *,
    log_cb: Optional[Callable[[str], None]] = None,
    progress_cb: Optional[Callable[[int, int], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> np.ndarray:
    """
    Multi-band Laplacian pyramid blending.

    progress_cb(i, N): i. görüntü tamamlandı (1..N)
    cancel_cb() -> bool: True dönerse BlendingCancelled fırlatır
    """
    N = len(images)
    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    _log, _prog, _cancelled, _check = _mk_helpers(log_cb, None, cancel_cb)

    _log(f"[blend] pyramid_blend BAŞLIYOR: N={N}  {W}x{H}  levels={levels}  C={C}")

    # Akümülatör seviyeleri
    accum = []
    for l in range(levels):
        h = max(1, H >> l)
        w = max(1, W >> l)
        accum.append(np.zeros((h, w, C), dtype=np.float32))

    t_all = time.perf_counter()

    for i in range(N):
        _check(f"pyramid_before_img_{i+1}/{N}")

        t_img = time.perf_counter()
        _log(f"[blend] pyramid {i+1}/{N} başlıyor")

        img = images[i].astype(np.float32)
        if C == 1:
            img = img[..., None]

        # Laplacian pyramid of image
        gp = _gaussian_pyramid(img, levels)
        lp = _laplacian_pyramid_from_gauss(gp)

        # Gaussian pyramid of weight
        wgt = weights[i][..., None].astype(np.float32)
        wp = _gaussian_pyramid(wgt, levels)

        # Her seviye için akümüle et, seviyeler arasında cancel kontrolü
        for l in range(levels):
            _check(f"pyramid_img{i+1}_lvl{l+1}/{levels}")

            lh, lw = accum[l].shape[:2]

            if lp[l].shape[:2] != (lh, lw):
                lp_l = cv2.resize(lp[l], (lw, lh), interpolation=cv2.INTER_AREA)
            else:
                lp_l = lp[l]

            if wp[l].shape[:2] != (lh, lw):
                wp_l = cv2.resize(wp[l], (lw, lh), interpolation=cv2.INTER_AREA)
            else:
                wp_l = wp[l]

            # Kanal boyutu güvenlik düzeltmesi
            if lp_l.ndim == 2:
                lp_l = lp_l[..., None]
            if wp_l.ndim == 2:
                wp_l = wp_l[..., None]

            accum[l] += lp_l * wp_l

        dt = time.perf_counter() - t_img
        _log(f"[blend] pyramid {i+1}/{N} tamamlandı ({dt:.1f}s)")

        if progress_cb:
            try:
                progress_cb(i + 1, N)
            except Exception as e:
                _log(f"[blend] progress_cb hatası: {e}")

    _log(f"[blend] pyramid toplam akümülasyon: {time.perf_counter()-t_all:.1f}s")

    # Collapse
    _check("pyramid_before_collapse")
    _log("[blend] pyramid collapse başlıyor")

    t_col = time.perf_counter()
    cur = accum[-1]
    for l in range(levels - 2, -1, -1):
        _check(f"pyramid_collapse_lvl{l+1}")
        size = (accum[l].shape[1], accum[l].shape[0])
        cur = cv2.pyrUp(cur, dstsize=size) + accum[l]
        _log(f"[blend] collapse seviye {l+1} tamam")

    _log(f"[blend] collapse toplam: {time.perf_counter()-t_col:.1f}s")

    cur = np.clip(cur, 0.0, max_val)
    if C == 1:
        cur = cur[..., 0]
    return cur.astype(np.float32) if is_float else cur.astype(np.uint8)