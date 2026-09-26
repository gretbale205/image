"""
Focus stacking pipeline orchestrator.

Public API:
    stack_images(image_paths, output_path, **opts) -> bool
"""

from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Sequence

import cv2
import numpy as np

from .alignment import align_stack, AlignmentResult
from .preprocessing import preprocess, PreprocessConfig
from .focus_maps import compute_focus_maps
from .blending import (
    compute_weights,
    blend_weighted,
    compute_depth_map,
    blend_by_depth,
    pyramid_blend,
    BlendConfig,
)
from .quality import StackingMetrics

logger = logging.getLogger(__name__)

_JPEG_QUALITY = 95
_PREVIEW_MAX_DIM = {
    "fast": 800,
    "balanced": 1200,
    "quality": 1600,
}

VALID_METHODS = {"softmax", "dmap", "pyramid"}
VALID_BITDEPTHS = {8, 16}


# ---------------------------------------------------------------------------
# I/O
# ---------------------------------------------------------------------------

def _load_image(path: Path, bitdepth: int = 8) -> np.ndarray:
    """
    Load image as BGR.

    bitdepth=8:
        uint8

    bitdepth=16:
        preserve uint16 whenever source contains 16-bit data.
        8-bit sources remain uint8.
    """
    path = Path(path)

    if not path.exists():
        raise FileNotFoundError(path)

    data = np.fromfile(str(path), dtype=np.uint8)
    if data.size == 0:
        raise ValueError(f"Boş görüntü dosyası: {path}")

    if bitdepth == 16:
        img = cv2.imdecode(
            data,
            cv2.IMREAD_ANYDEPTH | cv2.IMREAD_ANYCOLOR,
        )
    else:
        img = cv2.imdecode(
            data,
            cv2.IMREAD_COLOR,
        )

    if img is None:
        raise ValueError(f"Görüntü decode edilemedi: {path}")

    # OpenCV color images are BGR.
    if img.ndim == 2:
        img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
    elif img.ndim == 3 and img.shape[2] == 4:
        img = cv2.cvtColor(img, cv2.COLOR_BGRA2BGR)

    return img


def _common_size(images: Sequence[np.ndarray]) -> tuple[int, int]:
    """
    Median height/width.
    """
    hs = sorted(int(i.shape[0]) for i in images)
    ws = sorted(int(i.shape[1]) for i in images)

    return (
        hs[len(hs) // 2],
        ws[len(ws) // 2],
    )


def _resize_all(
    images: Sequence[np.ndarray],
    hw: tuple[int, int],
) -> list[np.ndarray]:
    h, w = hw

    out: list[np.ndarray] = []

    for img in images:
        if img.shape[:2] == (h, w):
            out.append(img)
        else:
            out.append(
                cv2.resize(
                    img,
                    (w, h),
                    interpolation=cv2.INTER_AREA,
                )
            )

    return out


# ---------------------------------------------------------------------------
# Numeric conversion
# ---------------------------------------------------------------------------

def _to_float01(img: np.ndarray) -> np.ndarray:
    """
    Normalize image to float32 [0,1].
    """
    if img.dtype == np.uint8:
        return img.astype(np.float32) / 255.0

    if img.dtype == np.uint16:
        return img.astype(np.float32) / 65535.0

    arr = img.astype(np.float32, copy=False)

    finite = np.isfinite(arr)
    if not np.any(finite):
        return np.zeros_like(arr, dtype=np.float32)

    max_value = float(np.nanmax(arr[finite]))

    if max_value <= 1.001:
        return np.clip(arr, 0.0, 1.0)

    if max_value <= 255.0:
        return np.clip(arr / 255.0, 0.0, 1.0)

    return np.clip(arr / 65535.0, 0.0, 1.0)


def _to_uint8(img: np.ndarray) -> np.ndarray:
    """
    Convert uint8/uint16/float image to uint8 safely.
    """
    if img.dtype == np.uint8:
        return img

    if img.dtype == np.uint16:
        return (img.astype(np.float32) / 257.0).clip(
            0, 255
        ).astype(np.uint8)

    arr = img.astype(np.float32)

    finite = np.isfinite(arr)
    if not np.any(finite):
        return np.zeros(arr.shape, dtype=np.uint8)

    max_value = float(np.nanmax(arr[finite]))

    if max_value <= 1.001:
        arr *= 255.0
    elif max_value <= 255.0:
        pass
    else:
        arr *= 255.0 / max_value

    return np.clip(arr, 0, 255).astype(np.uint8)


def _to_uint16(img: np.ndarray) -> np.ndarray:
    """
    Convert result to uint16 without accidental 8-bit truncation.
    """
    if img.dtype == np.uint16:
        return img

    if img.dtype == np.uint8:
        return (
            img.astype(np.uint16) * 257
        )

    arr = img.astype(np.float32)

    finite = np.isfinite(arr)
    if not np.any(finite):
        return np.zeros(arr.shape, dtype=np.uint16)

    max_value = float(np.nanmax(arr[finite]))

    if max_value <= 1.001:
        arr *= 65535.0
    elif max_value <= 255.0:
        arr *= 257.0
    elif max_value <= 65535.0:
        pass
    else:
        arr *= 65535.0 / max_value

    return np.clip(arr, 0, 65535).astype(np.uint16)


# ---------------------------------------------------------------------------
# Post processing
# ---------------------------------------------------------------------------

def _unsharp(
    img: np.ndarray,
    amount: float = 0.5,
    radius: float = 1.2,
) -> np.ndarray:
    """
    Unsharp mask preserving the original numeric range/dtype.
    """
    if amount <= 0.0:
        return img

    if radius <= 0:
        return img

    original_dtype = img.dtype

    # Work internally in [0,1].
    work = _to_float01(img)

    blur = cv2.GaussianBlur(
        work,
        (0, 0),
        sigmaX=radius,
    )

    sharp = cv2.addWeighted(
        work,
        1.0 + float(amount),
        blur,
        -float(amount),
        0.0,
    )

    sharp = np.clip(sharp, 0.0, 1.0)

    if original_dtype == np.uint8:
        return np.round(sharp * 255.0).astype(np.uint8)

    if original_dtype == np.uint16:
        return np.round(sharp * 65535.0).astype(np.uint16)

    # Float result is kept normalized.
    return sharp.astype(np.float32)


# ---------------------------------------------------------------------------
# Main pipeline
# ---------------------------------------------------------------------------

def stack_images(
    image_paths: Sequence[Path | str],
    output_path: Path | str,
    *,
    quality: str = "balanced",
    align: bool = True,
    method: str = "softmax",
    output_bitdepth: int = 8,
    debug_dir: Path | str | None = None,
    unsharp_amount: float = 0.5,
    unsharp_radius: float = 1.2,
) -> bool:

    output_path = Path(output_path)
    t0 = time.perf_counter()

    paths = [Path(p) for p in image_paths]

    if len(paths) < 2:
        logger.error(
            "stack_images: en az 2 görüntü gerekli (%d)",
            len(paths),
        )
        print(
            f"[stack] HATA: en az 2 görüntü gerekli ({len(paths)})"
        )
        return False

    if method not in VALID_METHODS:
        logger.error(
            "stack_images: bilinmeyen method=%s",
            method,
        )
        print(
            f"[stack] HATA: bilinmeyen method={method}"
        )
        return False

    if output_bitdepth not in VALID_BITDEPTHS:
        logger.error(
            "stack_images: geçersiz output_bitdepth=%s",
            output_bitdepth,
        )
        print(
            f"[stack] HATA: geçersiz bitdepth={output_bitdepth}"
        )
        return False

    # Normalize extension before any processing.
    if output_bitdepth == 16:
        if output_path.suffix.lower() not in {
            ".png",
            ".tif",
            ".tiff",
        }:
            output_path = output_path.with_suffix(".png")
    else:
        if output_path.suffix.lower() not in {
            ".jpg",
            ".jpeg",
            ".png",
        }:
            output_path = output_path.with_suffix(".jpg")

    print(
        f"[stack] ═══ BAŞLA: "
        f"N={len(paths)} "
        f"method={method} "
        f"bitdepth={output_bitdepth} "
        f"align={align}"
    )

    # ------------------------------------------------------------------
    # 1. Load
    # ------------------------------------------------------------------

    _t = time.perf_counter()

    try:
        raw = [
            _load_image(
                p,
                bitdepth=output_bitdepth,
            )
            for p in paths
        ]
    except Exception as e:
        logger.exception(
            "Görüntü okuma hatası: %s",
            e,
        )
        print(f"[stack] HATA okuma: {e}")
        return False

    if not raw:
        print("[stack] HATA: görüntüler yüklenemedi")
        return False

    if any(img is None or img.size == 0 for img in raw):
        print("[stack] HATA: boş görüntü bulundu")
        return False

    print(
        f"[stack] 1) yükleme: "
        f"{time.perf_counter() - _t:.2f}s  "
        f"ilk={raw[0].shape}  "
        f"dtype={raw[0].dtype}"
    )

    N = len(raw)

    # ------------------------------------------------------------------
    # 2. Common size
    # ------------------------------------------------------------------

    _t = time.perf_counter()

    H, W = _common_size(raw)
    work = _resize_all(raw, (H, W))

    print(
        f"[stack] 2) resize: "
        f"{time.perf_counter() - _t:.2f}s  "
        f"hedef={W}x{H} N={N}"
    )

    logger.info(
        "stack: N=%d size=%dx%d quality=%s method=%s bitdepth=%d",
        N,
        W,
        H,
        quality,
        method,
        output_bitdepth,
    )

    # ------------------------------------------------------------------
    # 3. Preprocess
    # ------------------------------------------------------------------

    _t = time.perf_counter()

    try:
        pre = preprocess(
            work,
            PreprocessConfig(),
        )
    except Exception as e:
        logger.exception(
            "Preprocess hatası: %s",
            e,
        )
        print(
            f"[stack] HATA preprocess: "
            f"{type(e).__name__}: {e}"
        )
        return False

    print(
        f"[stack] 3) preprocess: "
        f"{time.perf_counter() - _t:.2f}s"
    )

    # ------------------------------------------------------------------
    # 4. Alignment
    # ------------------------------------------------------------------

    align_result: AlignmentResult | None = None

    _t = time.perf_counter()

    if align:
        try:
            align_result = align_stack(
                pre,
                max_preview_dim=_PREVIEW_MAX_DIM.get(
                    quality,
                    1200,
                ),
            )

            aligned = align_result.aligned

            scores = np.asarray(
                align_result.scores,
                dtype=np.float32,
            )

            logger.info(
                "alignment: ref=%d mean=%.3f min=%.3f",
                align_result.reference_index,
                float(np.mean(scores)),
                float(np.min(scores)),
            )

            print(
                f"[stack] 4) alignment: "
                f"{time.perf_counter() - _t:.2f}s "
                f"ref={align_result.reference_index} "
                f"mean={float(np.mean(scores)):.3f} "
                f"min={float(np.min(scores)):.3f}"
            )

        except Exception as e:
            logger.exception(
                "Alignment başarısız: %s",
                e,
            )
            print(
                f"[stack] 4) alignment HATA: {e} "
                f"→ hizasız devam"
            )
            aligned = pre

    else:
        aligned = pre

        print(
            "[stack] 4) alignment: kapalı"
        )

    # ------------------------------------------------------------------
    # 5. Focus maps
    # ------------------------------------------------------------------

    _t = time.perf_counter()

    if output_bitdepth == 16:
        focus_input = [
            _to_uint8(img)
            for img in aligned
        ]
    else:
        focus_input = aligned

    print(
        f"[stack] 5) focus_input: "
        f"{time.perf_counter() - _t:.2f}s "
        f"N={len(focus_input)} "
        f"shape={focus_input[0].shape} "
        f"dtype={focus_input[0].dtype}"
    )

    _t = time.perf_counter()

    try:
        focus_maps, texture = compute_focus_maps(
            focus_input
        )
    except Exception as e:
        logger.exception(
            "focus_maps hatası: %s",
            e,
        )
        print(
            f"[stack] HATA focus_maps: "
            f"{type(e).__name__}: {e}"
        )
        return False

    print(
        f"[stack] 6) focus_maps: "
        f"{time.perf_counter() - _t:.2f}s "
        f"shape={focus_maps.shape} "
        f"min={float(focus_maps.min()):.4f} "
        f"max={float(focus_maps.max()):.4f}"
    )

    # ------------------------------------------------------------------
    # 6. Blend
    # ------------------------------------------------------------------

    bcfg = BlendConfig()

    depth_map = None
    weights = None

    _t = time.perf_counter()

    try:

        if method == "dmap":

            depth_map = compute_depth_map(
                focus_maps,
                texture,
                bcfg,
            )

            print(
                f"[stack] 7a) depth_map: "
                f"{time.perf_counter() - _t:.2f}s"
            )

            _t = time.perf_counter()

            result = blend_by_depth(
                aligned,
                depth_map,
                hard=False,
            )

            print(
                f"[stack] 7b) blend_by_depth: "
                f"{time.perf_counter() - _t:.2f}s"
            )

        elif method == "pyramid":

            weights = compute_weights(
                focus_maps,
                bcfg,
            )

            print(
                f"[stack] 7a) pyramid weights: "
                f"{time.perf_counter() - _t:.2f}s"
            )

            _t = time.perf_counter()

            result = pyramid_blend(
                aligned,
                weights,
                levels=bcfg.pyramid_levels,
            )

            print(
                f"[stack] 7b) pyramid_blend: "
                f"{time.perf_counter() - _t:.2f}s"
            )

        else:

            weights = compute_weights(
                focus_maps,
                bcfg,
            )

            print(
                f"[stack] 7a) weights: "
                f"{time.perf_counter() - _t:.2f}s"
            )

            _t = time.perf_counter()

            result = blend_weighted(
                aligned,
                weights,
            )

            print(
                f"[stack] 7b) blend_weighted: "
                f"{time.perf_counter() - _t:.2f}s"
            )

    except Exception as e:
        logger.exception(
            "%s blend hatası: %s",
            method,
            e,
        )
        print(
            f"[stack] HATA {method} blend: "
            f"{type(e).__name__}: {e}"
        )
        return False

    # ------------------------------------------------------------------
    # 6b. Unsharp
    # ------------------------------------------------------------------

    if unsharp_amount > 0.0:
        _t = time.perf_counter()

        try:
            result = _unsharp(
                result,
                amount=unsharp_amount,
                radius=unsharp_radius,
            )

            print(
                f"[stack] 7c) unsharp: "
                f"{time.perf_counter() - _t:.2f}s "
                f"dtype={result.dtype}"
            )

        except Exception as e:
            logger.warning(
                "Unsharp başarısız: %s",
                e,
            )
            print(
                f"[stack] 7c) unsharp UYARI: "
                f"{e} → atlandı"
            )

    # ------------------------------------------------------------------
    # 7. Debug
    # ------------------------------------------------------------------

    if debug_dir is not None:

        try:
            d = Path(debug_dir)
            d.mkdir(
                parents=True,
                exist_ok=True,
            )

            if method == "dmap":
                depth_norm = (
                    np.asarray(depth_map, dtype=np.float32)
                    / max(N - 1, 1)
                )

                cv2.imwrite(
                    str(d / "depth_map.png"),
                    np.clip(
                        depth_norm * 255.0,
                        0,
                        255,
                    ).astype(np.uint8),
                )

            else:
                depth = np.argmax(
                    focus_maps,
                    axis=0,
                ).astype(np.float32)

                cv2.imwrite(
                    str(d / "depth_map.png"),
                    (
                        depth
                        / max(N - 1, 1)
                        * 255.0
                    ).astype(np.uint8),
                )

                if weights is not None:
                    cv2.imwrite(
                        str(d / "weight_max.png"),
                        (
                            np.max(
                                weights,
                                axis=0,
                            )
                            * 255.0
                        ).clip(
                            0,
                            255,
                        ).astype(np.uint8),
                    )

            cv2.imwrite(
                str(d / "texture.png"),
                (
                    np.clip(
                        texture,
                        0,
                        1,
                    )
                    * 255.0
                ).astype(np.uint8),
            )

        except Exception as e:
            logger.warning(
                "Debug çıktı hatası: %s",
                e,
            )

    # ------------------------------------------------------------------
    # 8. Output
    # ------------------------------------------------------------------

    _t = time.perf_counter()

    try:
        output_path.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        if output_bitdepth == 16:

            out_img = _to_uint16(result)

            ext = output_path.suffix.lower()

            if ext not in {
                ".png",
                ".tif",
                ".tiff",
            }:
                output_path = (
                    output_path.with_suffix(".png")
                )

            ok = cv2.imwrite(
                str(output_path),
                out_img,
                [
                    cv2.IMWRITE_PNG_COMPRESSION,
                    3,
                ]
                if output_path.suffix.lower() == ".png"
                else [],
            )

        else:

            out_img = _to_uint8(result)

            ext = output_path.suffix.lower()

            if ext in {"", ".jpg", ".jpeg"}:

                if not ext:
                    output_path = (
                        output_path.with_suffix(".jpg")
                    )

                ok = cv2.imwrite(
                    str(output_path),
                    out_img,
                    [
                        cv2.IMWRITE_JPEG_QUALITY,
                        _JPEG_QUALITY,
                    ],
                )

            else:

                ok = cv2.imwrite(
                    str(output_path),
                    out_img,
                )

        if not ok or not output_path.exists():
            raise IOError(
                f"Master yazılamadı: {output_path}"
            )

        size_kb = (
            output_path.stat().st_size / 1024
        )

        print(
            f"[stack] 8) yazma: "
            f"{time.perf_counter() - _t:.2f}s "
            f"dosya={output_path.name} "
            f"boyut={size_kb:.1f} KB "
            f"dtype={out_img.dtype}"
        )

    except Exception as e:
        logger.exception(
            "Master yazma hatası: %s",
            e,
        )
        print(
            f"[stack] HATA yazma: "
            f"{type(e).__name__}: {e}"
        )
        return False

    # ------------------------------------------------------------------
    # 9. Metrics
    # ------------------------------------------------------------------

    elapsed = (
        time.perf_counter() - t0
    )

    scores = (
        np.asarray(
            align_result.scores,
            dtype=np.float32,
        )
        if align_result is not None
        else np.asarray([], dtype=np.float32)
    )

    metrics = StackingMetrics(
        frame_count=N,
        width=W,
        height=H,
        alignment_scores=scores,
        alignment_mean=(
            float(np.mean(scores))
            if scores.size
            else 0.0
        ),
        alignment_min=(
            float(np.min(scores))
            if scores.size
            else 0.0
        ),
        alignment_reference_index=(
            align_result.reference_index
            if align_result is not None
            else 0
        ),
        method=(
            f"{method}_{quality}"
            f"_bit{output_bitdepth}"
        ),
        processing_time_s=elapsed,
    )

    logger.info(
        metrics.log_line()
    )

    print(
        f"[stack] ═══ BİTİŞ: "
        f"{elapsed:.2f}s "
        f"N={N} "
        f"{W}x{H} "
        f"{method}/{output_bitdepth}bit"
    )

    return True
