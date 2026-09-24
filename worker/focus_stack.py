"""Focus stacking pipeline orchestrator.

Public API (worker.py tarafından çağrılır):

    stack_images(image_paths, output_path, **opts) -> bool

İçeride alignment / preprocessing / focus_maps / blending / quality
modülleri kullanılır.
"""
from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Sequence

import cv2
import numpy as np
from PIL import Image

from .alignment import align_stack, AlignmentResult
from .preprocessing import preprocess, PreprocessConfig
from .focus_maps import compute_focus_maps
from .blending import (
    compute_weights, blend_weighted,
    compute_depth_map, blend_by_depth,
    BlendConfig,
)
from .quality import StackingMetrics

logger = logging.getLogger(__name__)

_JPEG_QUALITY = 95
_PREVIEW_MAX_DIM = {"fast": 800, "balanced": 1200, "quality": 1600}


# ---------------------------------------------------------------------------
# I/O
# ---------------------------------------------------------------------------

def _load_image(path: Path, bitdepth: int = 8) -> np.ndarray:
    """
    Görüntü oku.
    bitdepth=8  -> uint8 [0,255] (JPEG, PNG, 8-bit TIFF)
    bitdepth=16 -> uint16 [0,65535] (16-bit TIFF, 16-bit PNG)
    """
    data = np.fromfile(str(path), dtype=np.uint8)

    if bitdepth == 16:
        img = cv2.imdecode(data, cv2.IMREAD_ANYDEPTH | cv2.IMREAD_COLOR)
        if img is None:
            img = cv2.imdecode(data, cv2.IMREAD_COLOR)
        if img is None:
            with Image.open(path) as im:
                arr = np.array(im)
                if arr.dtype == np.uint16:
                    img = cv2.cvtColor(arr, cv2.COLOR_RGB2BGR) if arr.ndim == 3 else arr
                else:
                    img = cv2.cvtColor(np.array(im.convert("RGB")), cv2.COLOR_RGB2BGR)
    else:
        img = cv2.imdecode(data, cv2.IMREAD_COLOR)
        if img is None:
            with Image.open(path) as im:
                img = cv2.cvtColor(np.array(im.convert("RGB")), cv2.COLOR_RGB2BGR)

    return img


def _common_size(images: Sequence[np.ndarray]) -> tuple[int, int]:
    """En küçük değil, medyan yükseklik/genişlik."""
    hs = sorted(int(i.shape[0]) for i in images)
    ws = sorted(int(i.shape[1]) for i in images)
    return (hs[len(hs) // 2], ws[len(ws) // 2])


def _resize_all(images: Sequence[np.ndarray], hw: tuple[int, int]) -> list[np.ndarray]:
    h, w = hw
    out: list[np.ndarray] = []
    for img in images:
        if img.shape[:2] == (h, w):
            out.append(img)
        else:
            out.append(cv2.resize(img, (w, h), interpolation=cv2.INTER_AREA))
    return out


# ---------------------------------------------------------------------------
# Ana pipeline
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
) -> bool:
    """
    Focus stacking.

    Args:
        method: "softmax" (PMax benzeri) veya "dmap" (derinlik haritası)
        output_bitdepth: 8 (JPEG/PNG) veya 16 (TIFF/PNG).
    """
    output_path = Path(output_path)
    t0 = time.perf_counter()

    paths = [Path(p) for p in image_paths]
    if len(paths) < 2:
        logger.error("stack_images: en az 2 görüntü gerekli (%d)", len(paths))
        print(f"[stack] HATA: en az 2 görüntü gerekli ({len(paths)})")
        return False

    if method not in ("softmax", "dmap", "pyramid"):
        logger.error("stack_images: bilinmeyen method=%s", method)
        print(f"[stack] HATA: bilinmeyen method={method}")
        return False
    if output_bitdepth not in (8, 16):
        logger.error("stack_images: geçersiz output_bitdepth=%s", output_bitdepth)
        print(f"[stack] HATA: geçersiz bitdepth={output_bitdepth}")
        return False

    print(f"[stack] ═══ BAŞLA: N={len(paths)}  method={method}  bitdepth={output_bitdepth}  align={align}")

    # -----------------------------------------------------------------
    # 1) Yükle
    # -----------------------------------------------------------------
    _t = time.perf_counter()
    try:
        raw = [_load_image(p, bitdepth=output_bitdepth) for p in paths]
    except Exception as e:
        logger.exception("Görüntü okuma hatası: %s", e)
        print(f"[stack] HATA okuma: {e}")
        return False

    if not raw or raw[0] is None:
        print("[stack] HATA: görüntüler yüklenemedi")
        return False

    print(f"[stack] 1) yükleme: {time.perf_counter()-_t:.2f}s  "
          f"ilk={raw[0].shape}  dtype={raw[0].dtype}  "
          f"boyutlar={sorted(set((i.shape[0], i.shape[1]) for i in raw))}")

    N = len(raw)
    _t = time.perf_counter()
    H, W = _common_size(raw)
    work = _resize_all(raw, (H, W))
    print(f"[stack] 2) resize: {time.perf_counter()-_t:.2f}s  hedef={W}x{H}  N={N}")

    logger.info("stack: N=%d size=%dx%d quality=%s method=%s bitdepth=%d",
                N, W, H, quality, method, output_bitdepth)

    # -----------------------------------------------------------------
    # 2) Preprocess
    # -----------------------------------------------------------------
    _t = time.perf_counter()
    pre = preprocess(work, PreprocessConfig())
    print(f"[stack] 3) preprocess: {time.perf_counter()-_t:.2f}s")

    # -----------------------------------------------------------------
    # 3) Alignment
    # -----------------------------------------------------------------
    align_result: AlignmentResult | None = None
    _t = time.perf_counter()
    if align:
        try:
            align_result = align_stack(
                pre,
                max_preview_dim=_PREVIEW_MAX_DIM.get(quality, 1200),
            )
            aligned = align_result.aligned
            logger.info(
                "alignment: ref=%d mean=%.3f min=%.3f",
                align_result.reference_index,
                float(np.mean(align_result.scores)),
                float(np.min(align_result.scores)),
            )
            print(f"[stack] 4) alignment: {time.perf_counter()-_t:.2f}s  "
                  f"ref={align_result.reference_index}  "
                  f"mean={float(np.mean(align_result.scores)):.3f}  "
                  f"min={float(np.min(align_result.scores)):.3f}")
        except Exception as e:
            logger.exception("Alignment başarısız, hizasız devam: %s", e)
            print(f"[stack] 4) alignment HATA: {e}  → hizasız devam")
            aligned = pre
    else:
        aligned = pre
        print(f"[stack] 4) alignment: kapalı")

    # -----------------------------------------------------------------
    # 4) Focus maps
    # -----------------------------------------------------------------
    _t = time.perf_counter()

    if output_bitdepth == 16:
        focus_input = [cv2.convertScaleAbs(img, alpha=255.0 / 65535.0)
                       if img.dtype == np.uint16 else img
                       for img in aligned]
    else:
        focus_input = aligned

    print(f"[stack] 5) focus_input hazır: {time.perf_counter()-_t:.2f}s  "
          f"tip={type(focus_input).__name__}  N={len(focus_input)}  "
          f"shape={focus_input[0].shape}  dtype={focus_input[0].dtype}")

    _t = time.perf_counter()
    try:
        focus_maps, texture = compute_focus_maps(focus_input)
    except Exception as e:
        logger.exception("focus_maps hatası: %s", e)
        print(f"[stack] HATA focus_maps: {type(e).__name__}: {e}")
        import traceback
        traceback.print_exc()
        return False

    print(f"[stack] 6) focus_maps: {time.perf_counter()-_t:.2f}s  "
          f"focus_maps.shape={focus_maps.shape}  "
          f"min={float(focus_maps.min()):.4f}  "
          f"max={float(focus_maps.max()):.4f}  "
          f"mean={float(focus_maps.mean()):.4f}")

    # -----------------------------------------------------------------
    # 5) Blend
    # -----------------------------------------------------------------
    from .blending import pyramid_blend, BlendConfig

    bcfg = BlendConfig()
    _t = time.perf_counter()

    if method == "dmap":
        try:
            depth_map = compute_depth_map(focus_maps, texture, bcfg)
            print(f"[stack] 7a) depth_map: {time.perf_counter()-_t:.2f}s")
            _t = time.perf_counter()
            result = blend_by_depth(aligned, depth_map, hard=False)
            print(f"[stack] 7b) blend_by_depth: {time.perf_counter()-_t:.2f}s")
        except Exception as e:
            logger.exception("DMap blend hatası: %s", e)
            print(f"[stack] HATA dmap blend: {type(e).__name__}: {e}")
            import traceback; traceback.print_exc()
            return False

    elif method == "pyramid":
        try:
            weights = compute_weights(focus_maps, bcfg)
            print(f"[stack] 7a) weights: {time.perf_counter()-_t:.2f}s")
            _t = time.perf_counter()
            result = pyramid_blend(aligned, weights, levels=bcfg.pyramid_levels)
            print(f"[stack] 7b) pyramid_blend: {time.perf_counter()-_t:.2f}s")
        except Exception as e:
            logger.exception("Pyramid blend hatası: %s", e)
            print(f"[stack] HATA pyramid blend: {type(e).__name__}: {e}")
            import traceback; traceback.print_exc()
            return False

    else:  # softmax
        try:
            weights = compute_weights(focus_maps, bcfg)
            print(f"[stack] 7a) weights: {time.perf_counter()-_t:.2f}s")
            _t = time.perf_counter()
            result = blend_weighted(aligned, weights)
            print(f"[stack] 7b) blend_weighted: {time.perf_counter()-_t:.2f}s")
        except Exception as e:
            logger.exception("Softmax blend hatası: %s", e)
            print(f"[stack] HATA softmax blend: {type(e).__name__}: {e}")
            import traceback; traceback.print_exc()
            return False

    # -----------------------------------------------------------------
    # 6) Debug
    # -----------------------------------------------------------------
    if debug_dir is not None:
        try:
            d = Path(debug_dir)
            d.mkdir(parents=True, exist_ok=True)

            if method == "dmap":
                depth_norm = depth_map / max(N - 1, 1)
                cv2.imwrite(str(d / "depth_map.png"),
                            (depth_norm * 255).astype(np.uint8))
            else:
                depth = np.argmax(focus_maps, axis=0).astype(np.float32)
                cv2.imwrite(str(d / "depth_map.png"),
                            (depth / max(N - 1, 1) * 255).astype(np.uint8))
                cv2.imwrite(str(d / "weight_max.png"),
                            (np.max(weights, axis=0) * 255).astype(np.uint8))

            cv2.imwrite(str(d / "texture.png"),
                        (texture * 255).astype(np.uint8))
        except Exception as e:
            logger.warning("Debug çıktı hatası: %s", e)
            print(f"[stack] debug uyarı: {e}")

    # -----------------------------------------------------------------
    # 7) Çıktı yaz
    # -----------------------------------------------------------------
    _t = time.perf_counter()
    try:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        ext = output_path.suffix.lower()

        if output_bitdepth == 16:
            if ext not in (".tif", ".tiff", ".png"):
                output_path = output_path.with_suffix(".png")
                logger.info("16-bit çıktı: uzantı .png olarak değiştirildi")

            if result.dtype == np.uint8:
                out_img = (result.astype(np.uint16) * 257)
            elif result.dtype == np.uint16:
                out_img = result
            elif result.dtype == np.float32:
                if result.max() <= 1.001:
                    out_img = (np.clip(result, 0, 1) * 65535).astype(np.uint16)
                else:
                    out_img = np.clip(result, 0, 65535).astype(np.uint16)
            else:
                out_img = result.astype(np.uint16)

            ok = cv2.imwrite(str(output_path), out_img)
        else:
            if ext in (".jpg", ".jpeg", ""):
                if not ext:
                    output_path = output_path.with_suffix(".jpg")
                out_img = result if result.dtype == np.uint8 else result.astype(np.uint8)
                ok = cv2.imwrite(
                    str(output_path), out_img,
                    [cv2.IMWRITE_JPEG_QUALITY, _JPEG_QUALITY],
                )
            else:
                out_img = result if result.dtype == np.uint8 else result.astype(np.uint8)
                ok = cv2.imwrite(str(output_path), out_img)

        if not ok or not output_path.exists():
            logger.error("Master yazılamadı: %s", output_path)
            print(f"[stack] HATA: master yazılamadı: {output_path}")
            return False

        size_kb = output_path.stat().st_size / 1024
        print(f"[stack] 8) yazma: {time.perf_counter()-_t:.2f}s  "
              f"dosya={output_path.name}  boyut={size_kb:.1f} KB")

    except Exception as e:
        logger.exception("Master yazma hatası: %s", e)
        print(f"[stack] HATA yazma: {type(e).__name__}: {e}")
        import traceback
        traceback.print_exc()
        return False

    # -----------------------------------------------------------------
    # 8) Metrik
    # -----------------------------------------------------------------
    elapsed = time.perf_counter() - t0
    metrics = StackingMetrics(
        frame_count=N, width=W, height=H,
        alignment_scores=(align_result.scores if align_result else []),
        alignment_mean=float(np.mean(align_result.scores)) if align_result else 0.0,
        alignment_min=float(np.min(align_result.scores)) if align_result else 0.0,
        alignment_reference_index=(align_result.reference_index if align_result else 0),
        method=f"{method}_{quality}_bit{output_bitdepth}",
        processing_time_s=elapsed,
    )
    logger.info(metrics.log_line())
    print(f"[stack] ═══ BİTİŞ: {elapsed:.2f}s  N={N}  {W}x{H}  {method}/{output_bitdepth}bit")

    return True