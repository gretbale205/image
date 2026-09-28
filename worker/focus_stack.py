"""Focus stacking orchestration with correct normalization order.

Kritik düzeltmeler:
  * Odak haritaları GLOBAL normalize edildikten SONRA ağırlık hesaplanıyor.
  * Debug modunda ara çıktılar (odak haritaları, ağırlıklar) PNG olarak kaydedilir.
  * Alignment skorları loglanır — düşük skorlu kareler işaretlenir.
  * Post-process daha hafif (aşırı unsharp → halo yapar).
"""
from __future__ import annotations

import gc
import logging
import time
from pathlib import Path
from typing import Sequence, Callable, Optional

import cv2
import numpy as np
from PIL import Image

from .alignment import align_stack, AlignmentResult
from .preprocessing import preprocess, PreprocessConfig
from .focus_maps import compute_focus_maps, FocusMapsCancelled
from .blending import (
    compute_weights,
    blend_weighted,
    compute_depth_map,
    blend_by_depth,
    pyramid_blend,
    BlendConfig,
    BlendingCancelled,
)
from .quality import StackingMetrics

logger = logging.getLogger(__name__)

_JPEG_QUALITY = 95
_PREVIEW_MAX_DIM = {"fast": 900, "balanced": 1400, "quality": 1800}


def _load_image(path: Path, bitdepth: int = 8) -> np.ndarray:
    data = np.fromfile(str(path), dtype=np.uint8)
    if bitdepth == 16:
        img = cv2.imdecode(data, cv2.IMREAD_ANYDEPTH | cv2.IMREAD_COLOR)
    else:
        img = cv2.imdecode(data, cv2.IMREAD_COLOR)

    if img is None:
        with Image.open(path) as im:
            img = cv2.cvtColor(np.array(im.convert("RGB")), cv2.COLOR_RGB2BGR)
    if img is None:
        raise ValueError(f"image could not be decoded: {path}")
    return img


def _common_size(images):
    hs = sorted(i.shape[0] for i in images)
    ws = sorted(i.shape[1] for i in images)
    return int(hs[len(hs) // 2]), int(ws[len(ws) // 2])


def _resize_all(images, hw):
    h, w = hw
    return [
        img if img.shape[:2] == (h, w)
        else cv2.resize(img, (w, h), interpolation=cv2.INTER_AREA)
        for img in images
    ]


def _unsharp(img, amount=0.15, radius=0.6):
    """Daha hafif unsharp — aşırı halo yapmaz."""
    f = img.astype(np.float32)
    blur = cv2.GaussianBlur(f, (0, 0), radius)
    return np.clip(f + amount * (f - blur), 0, 255).astype(np.uint8)


def _save_debug(debug_dir: Path, name: str, arr: np.ndarray) -> None:
    """Ara çıktıları PNG olarak kaydet (görselleştirme için)."""
    try:
        debug_dir.mkdir(parents=True, exist_ok=True)
        a = arr.astype(np.float32)
        a = a - a.min()
        if a.max() > 1e-9:
            a /= a.max()
        a = (a * 255).astype(np.uint8)
        cv2.imwrite(str(debug_dir / name), a)
    except Exception as exc:
        logger.debug("debug save failed %s: %s", name, exc)


def stack_images(
    image_paths: Sequence[Path | str],
    output_path: Path | str,
    *,
    quality: str = "balanced",
    align: bool = True,
    method: str = "pyramid",
    output_bitdepth: int = 8,
    debug_dir: Path | str | None = None,
    log_cb: Optional[Callable[[str], None]] = None,
    progress_cb: Optional[Callable[[int, str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> bool:
    output_path = Path(output_path)
    started = time.perf_counter()
    metrics = StackingMetrics()

    def log(msg):
        logger.info("%s", msg)
        if log_cb:
            log_cb(str(msg))

    def prog(pct, stage):
        if progress_cb:
            progress_cb(int(pct), str(stage))

    def cancelled():
        return bool(cancel_cb()) if cancel_cb else False

    paths = [Path(p) for p in image_paths]
    if len(paths) < 2:
        log("[stack] need at least 2 images")
        return False

    method = str(method or "pyramid").lower().strip()
    log(f"[stack] START N={len(paths)} method={method} quality={quality}")
    metrics.frame_count = len(paths)
    metrics.method = method

    # --- Load ---
    prog(10, "loading")
    try:
        raw = [_load_image(p, output_bitdepth) for p in paths]
    except Exception as exc:
        log(f"[stack] load ERROR: {exc}")
        return False

    h, w = _common_size(raw)
    metrics.width = w
    metrics.height = h
    work = _resize_all(raw, (h, w))
    del raw
    gc.collect()

    # --- Preprocess ---
    prog(25, "preprocess")
    try:
        pre = preprocess(work, PreprocessConfig())
    except Exception as exc:
        log(f"[stack] preprocess fallback: {exc}")
        pre = work

    # --- Alignment ---
    prog(40, "alignment")
    align_result: AlignmentResult | None = None
    if align:
        try:
            align_result = align_stack(
                pre,
                max_preview_dim=_PREVIEW_MAX_DIM.get(quality, 1400),
                motion=cv2.MOTION_HOMOGRAPHY,
            )
            aligned = align_result.aligned
            metrics.alignment_scores = list(align_result.scores)
            metrics.alignment_mean = float(np.mean(align_result.scores))
            metrics.alignment_min = float(np.min(align_result.scores))
            metrics.alignment_reference_index = align_result.reference_index
            log(f"[stack] alignment scores: "
                f"{[f'{s:.3f}' for s in align_result.scores]}")
        except Exception as exc:
            log(f"[stack] alignment failed, using raw: {exc}")
            aligned = pre
    else:
        aligned = pre
    del pre, work
    gc.collect()

    # --- Focus maps (GLOBAL normalize dahil) ---
    prog(55, "focus_maps")
    try:
        focus_maps, texture = compute_focus_maps(
            aligned,
            progress_cb=lambda i, t: prog(55 + int(15 * i / t), f"focus_maps {i}/{t}"),
            cancel_cb=cancelled,
            log_cb=log,
        )
    except FocusMapsCancelled:
        log("[stack] cancelled at focus maps")
        return False
    except Exception as exc:
        log(f"[stack] focus map ERROR: {exc}")
        return False

    if debug_dir:
        dbg = Path(debug_dir)
        for i in range(len(focus_maps)):
            _save_debug(dbg, f"focus_{i:02d}.png", focus_maps[i])
        _save_debug(dbg, "texture.png", texture)

    # --- Blending ---
    prog(75, "blending")
    cfg = BlendConfig(
        sharpen_power=3.5,
        temperature=0.08,
        smooth_sigma=1.2,
        winner_sigma=0.9,
        consistency_level=2,
        dmap_smooth_sigma=1.5,
        pyramid_levels=0,  # auto
    )

    try:
        if method == "dmap":
            depth_map = compute_depth_map(focus_maps, texture, cfg)
            if debug_dir:
                _save_debug(Path(debug_dir), "depth.png", depth_map)
            del focus_maps, texture
            gc.collect()
            result = blend_by_depth(
                aligned, depth_map, hard=False, log_cb=log, cancel_cb=cancelled
            )
        else:
            weights = compute_weights(
                focus_maps, cfg, log_cb=log, cancel_cb=cancelled
            )
            if debug_dir:
                dbg = Path(debug_dir)
                for i in range(len(weights)):
                    _save_debug(dbg, f"weight_{i:02d}.png", weights[i])
            del focus_maps, texture
            gc.collect()

            if method == "weighted":
                result = blend_weighted(
                    aligned, weights, log_cb=log, cancel_cb=cancelled
                )
            else:
                result = pyramid_blend(
                    aligned,
                    weights,
                    levels=cfg.pyramid_levels,
                    log_cb=log,
                    progress_cb=lambda i, t: prog(75 + int(15 * i / t), f"pyramid {i}/{t}"),
                    cancel_cb=cancelled,
                )
            del weights
            gc.collect()
    except BlendingCancelled:
        log("[stack] cancelled at blending")
        return False
    except Exception as exc:
        log(f"[stack] blend ERROR: {exc}")
        return False

    # --- Post-process & Save ---
    prog(95, "writing")
    if output_bitdepth == 8 and result.dtype == np.uint8:
        result = _unsharp(result)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    if output_bitdepth == 16:
        ok = cv2.imwrite(str(output_path), result.astype(np.uint16))
    else:
        ok = cv2.imwrite(
            str(output_path),
            result.astype(np.uint8),
            [cv2.IMWRITE_JPEG_QUALITY, _JPEG_QUALITY],
        )

    metrics.processing_time_s = time.perf_counter() - started
    log(metrics.log_line())

    prog(100, "done")
    return ok