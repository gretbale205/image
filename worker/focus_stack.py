"""Focus stacking pipeline orchestrator.

Public API (worker.py tarafından çağrılır):

    stack_images(image_paths, output_path, **opts) -> bool

v3.2: log_cb / progress_cb / cancel_cb desteği.
"""
from __future__ import annotations

import logging
import time
import traceback
from pathlib import Path
from typing import Sequence, Callable, Optional

import cv2
import numpy as np
from PIL import Image

from .alignment import align_stack, AlignmentResult
from .preprocessing import preprocess, PreprocessConfig
from .focus_maps import compute_focus_maps, FocusMapsCancelled
from .blending import (
    compute_weights, blend_weighted,
    compute_depth_map, blend_by_depth,
    BlendConfig,
)
from .quality import StackingMetrics

logger = logging.getLogger(__name__)

_JPEG_QUALITY = 95
_PREVIEW_MAX_DIM = {"fast": 800, "balanced": 1200, "quality": 1600}


# -------------------------------------------------------------------------
# Pre-processing: pozlama normalizasyonu (haze kaynağını keser)
# -------------------------------------------------------------------------

def _normalize_exposures(images: list[np.ndarray]) -> list[np.ndarray]:
    """
    Kareler arası auto-exposure farkını giderir.
    Referans = medyan kare. Diğerleri mean/std match ile hizalanır.
    Haze problemi büyük ölçüde buradan geliyor.
    """
    if len(images) < 2:
        return images

    ref_idx = len(images) // 2
    ref = images[ref_idx].astype(np.float32)

    out = []
    for i, img in enumerate(images):
        if i == ref_idx:
            out.append(img)
            continue

        arr = img.astype(np.float32)

        if arr.ndim == 2:
            s_mean, s_std = arr.mean(), arr.std() + 1e-6
            r_mean, r_std = ref.mean(), ref.std() + 1e-6
            arr = (arr - s_mean) * (r_std / s_std) + r_mean
        else:
            for c in range(arr.shape[2]):
                s_mean = arr[..., c].mean()
                s_std = arr[..., c].std() + 1e-6
                r_mean = ref[..., c].mean()
                r_std = ref[..., c].std() + 1e-6
                arr[..., c] = (arr[..., c] - s_mean) * (r_std / s_std) + r_mean

        out.append(np.clip(arr, 0, 255).astype(np.uint8))

    return out


# -------------------------------------------------------------------------
# Post-processing: dehaze + auto levels + unsharp mask
# -------------------------------------------------------------------------

def _auto_levels(img: np.ndarray, low: float = 0.3, high: float = 99.7) -> np.ndarray:
    """Percentile-based auto levels (per-channel)."""
    out = np.zeros_like(img)
    if img.ndim == 2:
        lo = np.percentile(img, low)
        hi = np.percentile(img, high)
        if hi - lo > 1:
            return np.clip((img.astype(np.float32) - lo) * 255 / (hi - lo), 0, 255).astype(np.uint8)
        return img.copy()

    for c in range(img.shape[2]):
        ch = img[..., c]
        lo = np.percentile(ch, low)
        hi = np.percentile(ch, high)
        if hi - lo > 1:
            out[..., c] = np.clip((ch.astype(np.float32) - lo) * 255 / (hi - lo), 0, 255).astype(np.uint8)
        else:
            out[..., c] = ch
    return out


def _unsharp_mask(img: np.ndarray, amount: float = 0.9, radius: float = 1.3) -> np.ndarray:
    """Kenarları belirginleştirir, ürün detaylarını öne çıkarır."""
    img_f = img.astype(np.float32)
    blur = cv2.GaussianBlur(img_f, (0, 0), radius)
    sharp = img_f + amount * (img_f - blur)
    return np.clip(sharp, 0, 255).astype(np.uint8)


def _dehaze(img: np.ndarray, strength: float = 0.35) -> np.ndarray:
    """
    Hafif dehaze — sisli görünümü alır.
    Dark channel prior'ın yumuşatılmış versiyonu; ürün fotoğrafını bozmaz.
    """
    img_f = img.astype(np.float32) / 255.0

    # Dark channel
    if img_f.ndim == 3:
        dark = np.min(img_f, axis=2)
    else:
        dark = img_f

    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15))
    dark = cv2.erode(dark, kernel)

    # Atmosferik ışık
    n = max(1, int(dark.size * 0.001))
    flat = dark.ravel()
    idx = np.argpartition(flat, -n)[-n:]

    if img_f.ndim == 3:
        atm = np.array([img_f[..., c].ravel()[idx].mean() for c in range(img_f.shape[2])])
        norm = img_f / (atm[None, None, :] + 1e-6)
        t = 1.0 - strength * np.min(norm, axis=2)
        t = np.clip(t, 0.35, 1.0)
        out = np.zeros_like(img_f)
        for c in range(img_f.shape[2]):
            out[..., c] = (img_f[..., c] - atm[c]) / t + atm[c]
    else:
        atm = img_f.ravel()[idx].mean()
        t = 1.0 - strength * (img_f / (atm + 1e-6))
        t = np.clip(t, 0.35, 1.0)
        out = (img_f - atm) / t + atm

    return np.clip(out * 255, 0, 255).astype(np.uint8)


def _post_process(img: np.ndarray, do_dehaze: bool = True,
                  do_levels: bool = True, do_sharpen: bool = True) -> np.ndarray:
    """Stacking sonrası kalite artırıcı zincir."""
    out = img.copy()
    if do_dehaze:
        try: out = _dehaze(out, strength=0.35)
        except Exception: pass
    if do_levels:
        try: out = _auto_levels(out, low=0.3, high=99.7)
        except Exception: pass
    if do_sharpen:
        try: out = _unsharp_mask(out, amount=0.9, radius=1.3)
        except Exception: pass
    return out




def _load_image(path: Path, bitdepth: int = 8) -> np.ndarray:
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


def stack_images(
    image_paths: Sequence[Path | str],
    output_path: Path | str,
    *,
    quality: str = "balanced",
    align: bool = True,
    method: str = "softmax",
    output_bitdepth: int = 8,
    debug_dir: Path | str | None = None,
    log_cb: Optional[Callable[[str], None]] = None,
    progress_cb: Optional[Callable[[int, str], None]] = None,
    cancel_cb: Optional[Callable[[], bool]] = None,
) -> bool:

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
                _log(f"[stack] progress_cb hatası: {e}")

    def _cancelled() -> bool:
        if cancel_cb:
            try:
                return bool(cancel_cb())
            except Exception:
                return False
        return False

    def _check_cancel(stage_name: str) -> bool:
        if _cancelled():
            _log(f"[stack] ⛔ İPTAL: {stage_name}")
            return True
        return False

    output_path = Path(output_path)
    t0 = time.perf_counter()

    paths = [Path(p) for p in image_paths]
    if len(paths) < 2:
        _log(f"[stack] HATA: en az 2 görüntü gerekli ({len(paths)})")
        return False

    if method not in ("softmax", "dmap", "pyramid"):
        _log(f"[stack] HATA: bilinmeyen method={method}")
        return False
    if output_bitdepth not in (8, 16):
        _log(f"[stack] HATA: geçersiz bitdepth={output_bitdepth}")
        return False

    _log(f"[stack] ═══ BAŞLA: N={len(paths)}  method={method}  "
         f"bitdepth={output_bitdepth}  align={align}")

    # 1) Yükle
    if _check_cancel("before_load"):
        return False
    _prog(10, "loading")
    _t = time.perf_counter()
    try:
        raw = [_load_image(p, bitdepth=output_bitdepth) for p in paths]
    except Exception as e:
        _log(f"[stack] HATA okuma: {e}")
        _log(traceback.format_exc())
        return False

    if not raw or raw[0] is None:
        _log("[stack] HATA: görüntüler yüklenemedi")
        return False

    _log(f"[stack] 1) yükleme: {time.perf_counter()-_t:.2f}s  "
         f"ilk={raw[0].shape}  dtype={raw[0].dtype}")

    # → Kareler arası pozlama farkını gider (haze kaynağı)
    raw = _normalize_exposures(raw)
    N = len(raw)

    # 2) Resize
    if _check_cancel("before_resize"):
        return False
    _prog(20, "resize")
    _t = time.perf_counter()
    H, W = _common_size(raw)
    work = _resize_all(raw, (H, W))
    _log(f"[stack] 2) resize: {time.perf_counter()-_t:.2f}s  hedef={W}x{H}  N={N}")

    # 3) Preprocess
    if _check_cancel("before_preprocess"):
        return False
    _prog(25, "preprocess")
    _t = time.perf_counter()
    try:
        pre = preprocess(work, PreprocessConfig())
    except Exception as e:
        _log(f"[stack] HATA preprocess: {type(e).__name__}: {e}")
        _log(traceback.format_exc())
        return False
    _log(f"[stack] 3) preprocess: {time.perf_counter()-_t:.2f}s")

    # 4) Alignment
    if _check_cancel("before_align"):
        return False
    _prog(40, "alignment")

    align_result: AlignmentResult | None = None
    _t = time.perf_counter()
    if align:
        try:
            align_result = align_stack(
                pre,
                max_preview_dim=_PREVIEW_MAX_DIM.get(quality, 1200),
            )
            aligned = align_result.aligned
            _log(f"[stack] 4) alignment: {time.perf_counter()-_t:.2f}s  "
                 f"ref={align_result.reference_index}  "
                 f"mean={float(np.mean(align_result.scores)):.3f}  "
                 f"min={float(np.min(align_result.scores)):.3f}")
        except Exception as e:
            _log(f"[stack] 4) alignment HATA: {e}  → hizasız devam")
            aligned = pre
    else:
        aligned = pre
        _log(f"[stack] 4) alignment: kapalı")

    # 5) Focus input
    if _check_cancel("before_focus"):
        return False
    _prog(55, "focus_maps")

    _t = time.perf_counter()
    if output_bitdepth == 16:
        focus_input = [cv2.convertScaleAbs(img, alpha=255.0 / 65535.0)
                       if img.dtype == np.uint16 else img
                       for img in aligned]
    else:
        focus_input = aligned

    _log(f"[stack] 5) focus_input hazır: {time.perf_counter()-_t:.2f}s  "
         f"N={len(focus_input)}  shape={focus_input[0].shape}  "
         f"dtype={focus_input[0].dtype}")

    # 6) Focus maps (canlı progress + cancel)
    _t = time.perf_counter()

    def _fm_progress(i, n):
        pct = 55 + int(20 * i / max(n, 1))
        _prog(pct, f"focus_maps {i}/{n}")

    try:
        focus_maps, texture = compute_focus_maps(
            focus_input,
            progress_cb=_fm_progress,
            cancel_cb=_cancelled,
            log_cb=_log,
        )
    except FocusMapsCancelled:
        _log("[stack] focus_maps kullanıcı tarafından iptal edildi")
        return False
    except Exception as e:
        _log(f"[stack] HATA focus_maps: {type(e).__name__}: {e}")
        _log(traceback.format_exc())
        return False

    _log(f"[stack] 6) focus_maps: {time.perf_counter()-_t:.2f}s  "
         f"shape={focus_maps.shape}  "
         f"min={float(focus_maps.min()):.4f}  "
         f"max={float(focus_maps.max()):.4f}")

# 7) Blend
    if _check_cancel("before_blend"):
        return False
    _prog(75, "blending")
    _log("[stack] 7) blending başlıyor (bu adım uzun sürebilir)")

    from .blending import (
        pyramid_blend, BlendConfig, BlendingCancelled,
    )

    bcfg = BlendConfig()
    _t = time.perf_counter()
    depth_map = None
    weights = None

    if method == "dmap":
        try:
            depth_map = compute_depth_map(focus_maps, texture, bcfg)
            _log(f"[stack] 7a) depth_map: {time.perf_counter()-_t:.2f}s")
            if _check_cancel("before_blend_by_depth"):
                return False
            _t = time.perf_counter()

            result = blend_by_depth(
                aligned, depth_map, hard=False,
                log_cb=_log, cancel_cb=_cancelled,
            )
            _log(f"[stack] 7b) blend_by_depth: {time.perf_counter()-_t:.2f}s")
        except BlendingCancelled:
            _log("[stack] blend_by_depth kullanıcı tarafından iptal edildi")
            return False
        except Exception as e:
            _log(f"[stack] HATA dmap blend: {type(e).__name__}: {e}")
            _log(traceback.format_exc())
            return False

    elif method == "pyramid":
        try:
            weights = compute_weights(
                focus_maps, bcfg,
                log_cb=_log, cancel_cb=_cancelled,
            )
            _log(f"[stack] 7a) weights: {time.perf_counter()-_t:.2f}s")
            if _check_cancel("before_pyramid_blend"):
                return False

            _t = time.perf_counter()

            def _pyr_progress(i, n):
                # 78 → 90 aralığında göster
                pct = 78 + int(12 * i / max(n, 1))
                _prog(pct, f"pyramid_blend {i}/{n}")

            result = pyramid_blend(
                aligned, weights,
                levels=bcfg.pyramid_levels,
                log_cb=_log,
                progress_cb=_pyr_progress,
                cancel_cb=_cancelled,
            )
            _log(f"[stack] 7b) pyramid_blend: {time.perf_counter()-_t:.2f}s")
        except BlendingCancelled:
            _log("[stack] pyramid_blend kullanıcı tarafından iptal edildi")
            return False
        except Exception as e:
            _log(f"[stack] HATA pyramid blend: {type(e).__name__}: {e}")
            _log(traceback.format_exc())
            return False

    else:  # softmax
        try:
            weights = compute_weights(
                focus_maps, bcfg,
                log_cb=_log, cancel_cb=_cancelled,
            )
            _log(f"[stack] 7a) weights: {time.perf_counter()-_t:.2f}s")
            if _check_cancel("before_blend_weighted"):
                return False

            _t = time.perf_counter()
            result = blend_weighted(
                aligned, weights,
                log_cb=_log, cancel_cb=_cancelled,
            )
            _log(f"[stack] 7b) blend_weighted: {time.perf_counter()-_t:.2f}s")
        except BlendingCancelled:
            _log("[stack] blend_weighted kullanıcı tarafından iptal edildi")
            return False
        except Exception as e:
            _log(f"[stack] HATA softmax blend: {type(e).__name__}: {e}")
            _log(traceback.format_exc())
            return False

    _prog(90, "blend_done")

    # -----------------------------------------------------------------
    # 7c) Post-process: dehaze + auto levels + unsharp
    # -----------------------------------------------------------------
    _t = time.perf_counter()
    try:
        # Sadece 8-bit için; 16-bit'te de çalışır ama lineer kalır
        if result.dtype == np.uint8:
            result = _post_process(result)
        else:
            # 16-bit için float'a çevir, post-prod, geri çevir
            r16 = result if result.dtype == np.uint16 else (result * 65535).astype(np.uint16)
            r8 = (r16 >> 8).astype(np.uint8)
            r8 = _post_process(r8)
            result = (r8.astype(np.uint16) * 257)
        _log(f"[stack] 7c) post-process: {time.perf_counter()-_t:.2f}s")
    except Exception as e:
        _log(f"[stack] post-process uyarı: {e}")


    # 8) Debug
    if debug_dir is not None:
        try:
            d = Path(debug_dir)
            d.mkdir(parents=True, exist_ok=True)
            if method == "dmap" and depth_map is not None:
                depth_norm = depth_map / max(N - 1, 1)
                cv2.imwrite(str(d / "depth_map.png"),
                            (depth_norm * 255).astype(np.uint8))
            elif weights is not None:
                depth = np.argmax(focus_maps, axis=0).astype(np.float32)
                cv2.imwrite(str(d / "depth_map.png"),
                            (depth / max(N - 1, 1) * 255).astype(np.uint8))
                cv2.imwrite(str(d / "weight_max.png"),
                            (np.max(weights, axis=0) * 255).astype(np.uint8))
            cv2.imwrite(str(d / "texture.png"),
                        (texture * 255).astype(np.uint8))
        except Exception as e:
            _log(f"[stack] debug uyarı: {e}")

    # 9) Yaz
    if _check_cancel("before_write"):
        return False
    _prog(90, "writing")

    _t = time.perf_counter()
    try:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        ext = output_path.suffix.lower()

        if output_bitdepth == 16:
            if ext not in (".tif", ".tiff", ".png"):
                output_path = output_path.with_suffix(".png")

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
            _log(f"[stack] HATA: master yazılamadı: {output_path}")
            return False

        size_kb = output_path.stat().st_size / 1024
        _log(f"[stack] 8) yazma: {time.perf_counter()-_t:.2f}s  "
             f"dosya={output_path.name}  boyut={size_kb:.1f} KB")

    except Exception as e:
        _log(f"[stack] HATA yazma: {type(e).__name__}: {e}")
        _log(traceback.format_exc())
        return False

    # 10) Metrik
    elapsed = time.perf_counter() - t0
    try:
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
    except Exception as e:
        _log(f"[stack] metrik uyarı: {e}")

    _prog(100, "done")
    _log(f"[stack] ═══ BİTİŞ: {elapsed:.2f}s  N={N}  {W}x{H}  "
         f"{method}/{output_bitdepth}bit")

    return True