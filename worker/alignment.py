"""ECC-based alignment with affine motion (lens breathing correction)."""
from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Sequence

import cv2
import numpy as np

logger = logging.getLogger(__name__)


@dataclass
class AlignmentResult:
    aligned: list[np.ndarray]
    transforms: list[np.ndarray]
    scores: list[float]
    fallback_used: list[bool]
    reference_index: int


def _to_gray_f32(img: np.ndarray) -> np.ndarray:
    if img.ndim == 3:
        img = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    g = img.astype(np.float32)
    if g.max() > 1.5:
        g /= 255.0
    return g


def _downscale(img: np.ndarray, max_dim: int) -> tuple[np.ndarray, float]:
    h, w = img.shape[:2]
    if max(h, w) <= max_dim:
        return img, 1.0
    s = max_dim / float(max(h, w))
    return cv2.resize(img, (int(round(w * s)), int(round(h * s))),
                      interpolation=cv2.INTER_AREA), s


def _pick_reference(images: Sequence[np.ndarray]) -> int:
    """Ortanca kare: komşu karelerle minimum hareket → daha iyi alignment."""
    return len(images) // 2


def _scale_translation_to_full(warp: np.ndarray, preview_scale: float) -> np.ndarray:
    """Translation bileşenini full-res'e ölçekle. AFFINE/HOMOGRAPHY için de doğru."""
    out = warp.astype(np.float32).copy()
    if preview_scale <= 0 or preview_scale == 1.0:
        return out
    out[0, 2] /= preview_scale
    out[1, 2] /= preview_scale
    return out


def align_stack(
    images: Sequence[np.ndarray],
    *,
    max_preview_dim: int = 1600,
    motion: int = cv2.MOTION_AFFINE,   # ⚡ EUCLIDEAN → AFFINE
    max_iter: int = 500,
    eps: float = 1e-7,
    gauss_filter: int = 5,
    min_score: float = 0.75,
) -> AlignmentResult:
    if len(images) < 2:
        raise ValueError("align_stack requires >= 2 images")

    ref_idx = _pick_reference(images)
    logger.info("alignment reference_index=%d (median)", ref_idx)

    previews, scales = [], []
    for img in images:
        small, s = _downscale(img, max_preview_dim)
        previews.append(_to_gray_f32(small))
        scales.append(s)

    ref_prev = previews[ref_idx]
    h_ref, w_ref = ref_prev.shape

    aligned: list[np.ndarray] = []
    transforms: list[np.ndarray] = []
    scores: list[float] = []
    fallbacks: list[bool] = []

    for i, img in enumerate(images):
        if i == ref_idx:
            aligned.append(img.copy())
            transforms.append(np.eye(2, 3, dtype=np.float32))
            scores.append(1.0)
            fallbacks.append(False)
            continue

        src = previews[i]
        if src.shape != ref_prev.shape:
            src = cv2.resize(src, (w_ref, h_ref), interpolation=cv2.INTER_AREA)

        warp = np.eye(2, 3, dtype=np.float32)
        try:
            cc, warp = cv2.findTransformECC(
                ref_prev, src, warp, motion,
                (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, max_iter, eps),
                None, gauss_filter,
            )
            cc = float(cc)
        except cv2.error as e:
            logger.warning("ECC failed for frame %d: %s -> identity", i, e)
            cc = 0.0
            warp = np.eye(2, 3, dtype=np.float32)

        warp_full = _scale_translation_to_full(warp, scales[ref_idx])

        aligned_img = cv2.warpAffine(
            img, warp_full, (img.shape[1], img.shape[0]),
            flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
            borderMode=cv2.BORDER_REPLICATE,
        )

        aligned.append(aligned_img)
        transforms.append(warp_full)
        scores.append(cc)
        fallbacks.append(cc < min_score)

    return AlignmentResult(
        aligned=aligned, transforms=transforms, scores=scores,
        fallback_used=fallbacks, reference_index=ref_idx,
    )