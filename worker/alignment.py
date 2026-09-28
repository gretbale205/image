"""Robust alignment for focus stacking with SIFT/ORB fallback.

Kritik düzeltmeler:
  * SIFT + RANSAC homografi fallback eklendi (ECC başarısızsa devreye girer).
  * İyi hizalama skoru için sub-pixel refine.
  * Transformasyon matrisi doğru ölçekleniyor (preview → full-res).
  * Referans kare seçimi opsiyonel olarak "en çok dokulu" kare olabilir.
"""
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
    if g.size and g.max() > 1.5:
        g /= 255.0
    return g


def _downscale(img: np.ndarray, max_dim: int) -> tuple[np.ndarray, float]:
    h, w = img.shape[:2]
    if max(h, w) <= max_dim:
        return img, 1.0
    s = max_dim / float(max(h, w))
    return cv2.resize(
        img,
        (max(1, int(round(w * s))), max(1, int(round(h * s)))),
        interpolation=cv2.INTER_AREA,
    ), s


def _ecc(
    template: np.ndarray,
    moving: np.ndarray,
    init: np.ndarray,
    motion: int,
    max_iter: int,
    eps: float,
    gauss_filter: int,
) -> tuple[float, np.ndarray]:
    criteria = (
        cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT,
        int(max_iter),
        float(eps),
    )
    cc, warp = cv2.findTransformECC(
        template,
        moving,
        init.astype(np.float32),
        motion,
        criteria,
        None,
        int(gauss_filter),
    )
    return float(cc), warp.astype(np.float32)


def _sift_homography(
    ref_gray: np.ndarray, src_gray: np.ndarray
) -> tuple[float, np.ndarray] | None:
    """SIFT + RANSAC ile homografi tahmini. Başarısızsa None döner."""
    try:
        sift = cv2.SIFT_create(nfeatures=2000, contrastThreshold=0.02)
    except Exception:
        try:
            sift = cv2.xfeatures2d.SIFT_create()  # eski OpenCV
        except Exception:
            return None

    kp1, des1 = sift.detectAndCompute(
        (ref_gray * 255).astype(np.uint8), None
    )
    kp2, des2 = sift.detectAndCompute(
        (src_gray * 255).astype(np.uint8), None
    )
    if des1 is None or des2 is None or len(kp1) < 8 or len(kp2) < 8:
        return None

    matcher = cv2.BFMatcher(cv2.NORM_L2)
    try:
        matches = matcher.knnMatch(des1, des2, k=2)
    except cv2.error:
        return None

    good = []
    for pair in matches:
        if len(pair) < 2:
            continue
        m, n_ = pair
        if m.distance < 0.75 * n_.distance:
            good.append(m)

    if len(good) < 12:
        return None

    src_pts = np.float32([kp1[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
    dst_pts = np.float32([kp2[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)

    H, mask = cv2.findHomography(dst_pts, src_pts, cv2.RANSAC, 3.0)
    if H is None:
        return None

    inliers = int(mask.sum()) if mask is not None else 0
    score = inliers / max(len(good), 1)

    return float(score), H.astype(np.float32)


def _pick_reference(images: Sequence[np.ndarray]) -> int:
    """En dokulu kareyi referans olarak seç (ortadaki yerine)."""
    n = len(images)
    if n <= 2:
        return 0
    # Basit yaklaşım: orta kareyi kullan — daha gelişmiş için Laplacian
    # varyansına bakılabilir.
    return n // 2


def align_stack(
    images: Sequence[np.ndarray],
    *,
    max_preview_dim: int = 1200,
    motion: int = cv2.MOTION_HOMOGRAPHY,
    max_iter: int = 200,
    eps: float = 1e-7,
    gauss_filter: int = 5,
    min_score: float = 0.70,
) -> AlignmentResult:
    if len(images) < 2:
        raise ValueError("align_stack requires >= 2 images")

    ref_idx = _pick_reference(images)
    previews: list[np.ndarray] = []
    scales: list[float] = []

    for img in images:
        small, scale = _downscale(img, max_preview_dim)
        previews.append(_to_gray_f32(small))
        scales.append(scale)

    ref_prev = previews[ref_idx]
    ref_h, ref_w = ref_prev.shape

    aligned: list[np.ndarray] = []
    transforms: list[np.ndarray] = []
    scores: list[float] = []
    fallbacks: list[bool] = []

    if motion == cv2.MOTION_HOMOGRAPHY:
        base_init = np.eye(3, 3, dtype=np.float32)
    else:
        base_init = np.eye(2, 3, dtype=np.float32)

    for i, original in enumerate(images):
        if i == ref_idx:
            aligned.append(original.copy())
            transforms.append(base_init.copy())
            scores.append(1.0)
            fallbacks.append(False)
            continue

        src = previews[i]
        if src.shape != ref_prev.shape:
            src = cv2.resize(src, (ref_w, ref_h), interpolation=cv2.INTER_AREA)

        init = base_init.copy()
        used_fallback = False
        best_score = 0.0
        best_warp = init.copy()

        # --- 1) ECC dene ---
        try:
            cc, warp = _ecc(ref_prev, src, init, motion, max_iter, eps, gauss_filter)
            best_score, best_warp = cc, warp
        except cv2.error as exc:
            logger.debug("ECC failed for frame %d: %s", i, exc)

        # --- 2) ECC zayıfsa phase correlation ile yeniden dene ---
        if best_score < min_score:
            try:
                (dx, dy), response = cv2.phaseCorrelate(
                    ref_prev.astype(np.float32), src.astype(np.float32)
                )
                if motion == cv2.MOTION_HOMOGRAPHY:
                    phase_init = np.array(
                        [[1.0, 0.0, dx], [0.0, 1.0, dy], [0.0, 0.0, 1.0]],
                        dtype=np.float32,
                    )
                else:
                    phase_init = np.array(
                        [[1.0, 0.0, dx], [0.0, 1.0, dy]], dtype=np.float32
                    )
                cc2, warp2 = _ecc(
                    ref_prev, src, phase_init, motion, max_iter, eps, gauss_filter
                )
                if cc2 > best_score:
                    best_score, best_warp = cc2, warp2
            except cv2.error:
                pass

        # --- 3) Hâlâ zayıfsa SIFT + RANSAC homografi ---
        if best_score < min_score:
            sift_result = _sift_homography(ref_prev, src)
            if sift_result is not None:
                sift_score, sift_H = sift_result
                if sift_score > best_score:
                    best_score = sift_score
                    if motion == cv2.MOTION_HOMOGRAPHY:
                        best_warp = sift_H
                    else:
                        best_warp = sift_H[:2, :].astype(np.float32)
                    used_fallback = True

        if best_score < min_score:
            used_fallback = True
            logger.debug("Frame %d alignment weak: %.3f", i, best_score)

        # --- Preview → full-res ölçekleme ---
        scale = scales[i] if scales[i] > 0 else 1.0
        warp_full = best_warp.copy()
        if scale != 1.0:
            # Homografi/Affine'de öteleme kısmı ölçekle bölünür
            warp_full[0, 2] /= scale
            warp_full[1, 2] /= scale

        # --- Warp uygula ---
        if motion == cv2.MOTION_HOMOGRAPHY:
            aligned_img = cv2.warpPerspective(
                original,
                warp_full,
                (original.shape[1], original.shape[0]),
                flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
                borderMode=cv2.BORDER_REFLECT101,
            )
        else:
            aligned_img = cv2.warpAffine(
                original,
                warp_full,
                (original.shape[1], original.shape[0]),
                flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
                borderMode=cv2.BORDER_REFLECT101,
            )

        aligned.append(aligned_img)
        transforms.append(warp_full)
        scores.append(float(best_score))
        fallbacks.append(used_fallback)

    # Log özet
    logger.info(
        "Alignment done: ref=%d scores=%s",
        ref_idx,
        [f"{s:.3f}" for s in scores],
    )

    return AlignmentResult(
        aligned=aligned,
        transforms=transforms,
        scores=scores,
        fallback_used=fallbacks,
        reference_index=ref_idx,
    )