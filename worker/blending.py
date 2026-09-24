"""Blending: softmax + DMap (memory-safe) + multi-band pyramid."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Sequence

import cv2
import numpy as np


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
# Softmax (PMax benzeri)
# ---------------------------------------------------------------------------

def compute_weights(focus_maps: np.ndarray,
                    cfg: BlendConfig | None = None) -> np.ndarray:
    cfg = cfg or BlendConfig()
    powered = np.power(np.clip(focus_maps, 0.0, 1.0), cfg.sharpen_power)
    denom = powered.sum(axis=0, keepdims=True) + cfg.eps
    weights = powered / denom

    if cfg.smooth_sigma > 0:
        sm = np.empty_like(weights)
        for i in range(weights.shape[0]):
            sm[i] = cv2.GaussianBlur(weights[i], (0, 0), cfg.smooth_sigma)
        weights = sm / (sm.sum(axis=0, keepdims=True) + cfg.eps)

    return weights.astype(np.float32)


def blend_weighted(images: Sequence[np.ndarray], weights: np.ndarray) -> np.ndarray:
    N = len(images)
    if N == 0:
        raise ValueError("empty images")
    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    acc = np.zeros((H, W, C), dtype=np.float32)
    for i, img in enumerate(images):
        arr = img.astype(np.float32)
        if C == 1:
            arr = arr[..., None]
        acc += arr * weights[i][..., None]

    acc = np.clip(acc, 0.0, max_val)
    if C == 1:
        acc = acc[..., 0]
    return acc.astype(np.float32) if is_float else acc.astype(np.uint8)


# ---------------------------------------------------------------------------
# DMap — Depth map approach (bellek-safe)
# ---------------------------------------------------------------------------

def compute_depth_map(focus_maps: np.ndarray,
                      texture: np.ndarray | None = None,
                      cfg: BlendConfig | None = None) -> np.ndarray:
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


def blend_by_depth(images: Sequence[np.ndarray], depth_map: np.ndarray,
                   hard: bool = False) -> np.ndarray:
    """
    Depth map kullanarak harmanla. BELLEK-SAF: kare başına tek geçiş.
    """
    N = len(images)
    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    depth = np.clip(depth_map.astype(np.float32), 0.0, N - 1)

    result = np.zeros((H, W, C), dtype=np.float32)

    if hard:
        idx = np.round(depth).astype(np.int32)
        for i in range(N):
            m = (idx == i)
            if not np.any(m):
                continue
            arr = images[i].astype(np.float32)
            if C == 1:
                arr = arr[..., None]
            result[m] = arr[m]
    else:
        lo = np.clip(np.floor(depth).astype(np.int32), 0, N - 1)
        hi = np.clip(lo + 1, 0, N - 1)
        frac = (depth - lo.astype(np.float32)).astype(np.float32)  # [0,1)

        for i in range(N):
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

    result = np.clip(result, 0.0, max_val)
    if C == 1:
        result = result[..., 0]
    return result.astype(np.float32) if is_float else result.astype(np.uint8)


# ---------------------------------------------------------------------------
# Multi-band pyramid blending (YENİ — en kaliteli yöntem)
# ---------------------------------------------------------------------------

def _gaussian_pyramid(img: np.ndarray, levels: int) -> list[np.ndarray]:
    pyr = [img]
    cur = img
    for _ in range(levels - 1):
        cur = cv2.pyrDown(cur)
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


def pyramid_blend(images: Sequence[np.ndarray],
                  weights: np.ndarray,
                  levels: int = 5) -> np.ndarray:
    """
    Multi-band Laplacian pyramid blending.
    weights: (N,H,W) sum=1 across N
    Bellek-safe: kare başına pyramid hesaplanır, akümülatöre eklenir.
    """
    N = len(images)
    img0 = images[0]
    H, W = img0.shape[:2]
    C = 1 if img0.ndim == 2 else img0.shape[2]
    is_float = img0.dtype == np.float32
    max_val = 1.0 if is_float else 255.0

    # Akümülatör seviyeleri
    accum = []
    for l in range(levels):
        h = max(1, H >> l)
        w = max(1, W >> l)
        accum.append(np.zeros((h, w, C), dtype=np.float32))

    for i in range(N):
        img = images[i].astype(np.float32)
        if C == 1:
            img = img[..., None]

        gp = _gaussian_pyramid(img, levels)
        lp = _laplacian_pyramid_from_gauss(gp)

        wgt = weights[i][..., None].astype(np.float32)  # (H,W,1)
        wp = _gaussian_pyramid(wgt, levels)

        for l in range(levels):
            # Boyutları eşitle (pyrDown çift sayı olmayabilir)
            lh, lw = accum[l].shape[:2]
            if lp[l].shape[:2] != (lh, lw):
                lp_l = cv2.resize(lp[l], (lw, lh), interpolation=cv2.INTER_AREA)
            else:
                lp_l = lp[l]
            if wp[l].shape[:2] != (lh, lw):
                wp_l = cv2.resize(wp[l], (lw, lh), interpolation=cv2.INTER_AREA)
            else:
                wp_l = wp[l]
            accum[l] += lp_l * wp_l

    # Collapse
    cur = accum[-1]
    for l in range(levels - 2, -1, -1):
        size = (accum[l].shape[1], accum[l].shape[0])
        cur = cv2.pyrUp(cur, dstsize=size) + accum[l]

    cur = np.clip(cur, 0.0, max_val)
    if C == 1:
        cur = cur[..., 0]
    return cur.astype(np.float32) if is_float else cur.astype(np.uint8)