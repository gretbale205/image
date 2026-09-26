"""Optional denoise + exposure normalization."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Sequence

import cv2
import numpy as np


@dataclass
class PreprocessConfig:
    denoise: bool = False  # slow; keep off by default
    denoise_h: float = 6.0
    exposure_normalize: bool = True
    max_gain: float = 1.15
    reference_index: int = 0


def _median_gray(gray: np.ndarray) -> float:
    return float(np.percentile(gray, 50.0)) + 1e-6


def preprocess(images: Sequence[np.ndarray],
               cfg: PreprocessConfig | None = None) -> list[np.ndarray]:
    cfg = cfg or PreprocessConfig()
    out = [img.copy() for img in images]

    if cfg.denoise:
        for i, img in enumerate(out):
            if img.ndim == 3:
                out[i] = cv2.fastNlMeansDenoisingColored(img, None, cfg.denoise_h,
                                                         cfg.denoise_h, 7, 21)
            else:
                out[i] = cv2.fastNlMeansDenoising(img, None, cfg.denoise_h, 7, 21)

    if cfg.exposure_normalize and len(out) > 1:
        ref = out[cfg.reference_index]
        ref_g = cv2.cvtColor(ref, cv2.COLOR_BGR2GRAY) if ref.ndim == 3 else ref
        ref_med = _median_gray(ref_g)
        for i, img in enumerate(out):
            if i == cfg.reference_index:
                continue
            g = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
            gain = ref_med / _median_gray(g)
            gain = float(np.clip(gain, 1.0 / cfg.max_gain, cfg.max_gain))
            out[i] = np.clip(img.astype(np.float32) * gain, 0, 255).astype(np.uint8)

    return out