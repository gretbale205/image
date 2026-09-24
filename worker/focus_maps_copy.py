"""Multi-measure focus map: Laplacian + Tenengrad + local variance."""
from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Sequence

import cv2
import numpy as np
from scipy import ndimage

logger = logging.getLogger(__name__)


@dataclass
class FocusMapsConfig:
    laplacian_weight: float = 0.5
    tenengrad_weight: float = 0.3
    variance_weight: float = 0.2
    gaussian_sigma: float = 0.8          # 1.0 yerine 0.8
    local_var_window: int = 7
    min_texture_threshold: float = 0.01  # 0.02 yerine 0.01


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
) -> tuple[np.ndarray, np.ndarray]:
    """
    Returns:
      focus_maps: (N, H, W) float32 in [0,1], higher = sharper
      texture:    (H, W) max focus across frames
    """
    cfg = cfg or FocusMapsConfig()
    N = len(images)
    if N == 0:
        raise ValueError("empty stack")

    H, W = images[0].shape[:2]
    lap = np.empty((N, H, W), dtype=np.float32)
    ten = np.empty((N, H, W), dtype=np.float32)
    var = np.empty((N, H, W), dtype=np.float32)

    for i, img in enumerate(images):
        g = _gray(img).astype(np.float32) / 255.0
        if cfg.gaussian_sigma > 0:
            g = cv2.GaussianBlur(g, (0, 0), cfg.gaussian_sigma)
        lap[i] = _laplacian(g)
        ten[i] = _tenengrad(g)
        var[i] = _local_var(g, cfg.local_var_window)

    lap, ten, var = _normalize_global(lap), _normalize_global(ten), _normalize_global(var)

    w = (cfg.laplacian_weight, cfg.tenengrad_weight, cfg.variance_weight)
    tot = sum(w)
    focus = (w[0] * lap + w[1] * ten + w[2] * var) / tot

    texture = np.max(focus, axis=0)
    focus = np.where(texture[None] < cfg.min_texture_threshold, 0.0, focus)
    return focus.astype(np.float32), texture.astype(np.float32)

def compute_depth_map(focus_maps: np.ndarray, texture: np.ndarray,
                      consistency_level: int = 2,
                      smooth_sigma: float = 2.0) -> np.ndarray:
    """
    Focus map'lerden derinlik haritası (DMap) oluşturur ve
    komşu piksel tutarlılık filtresini uygular.
    
    Args:
        focus_maps: (N, H, W) her karenin odak haritası
        texture: (H, W) maksimum odak değeri (güven için)
        consistency_level: 0 (kapalı), 1 (orta), 2 (güçlü)
        smooth_sigma: derinlik haritası yumuşatma miktarı
    
    Returns:
        depth_map: (H, W) float32, her pikselin en keskin olduğu kare indeksi
    """
    N, H, W = focus_maps.shape
    
    # 1) Her piksel için en yüksek odak değerine sahip kareyi bul
    depth = np.argmax(focus_maps, axis=0).astype(np.float32)
    
    # 2) Düşük güvenli (texture'ı az) bölgeleri işaretle
    low_conf = texture < 0.01
    depth[low_conf] = np.nan
    
    # 3) Komşu piksel tutarlılığı (consistency filter)
    if consistency_level > 0:
        # Nan'ları doldur (en yakın komşudan)
        depth_filled = np.nan_to_num(depth, nan=0.0)
        
        # Medyan filtre ile komşularla tutarlılığı sağla
        if consistency_level == 2:
            # Güçlü: 5x5 medyan + 3 iterasyon
            for _ in range(3):
                depth_filled = cv2.medianBlur(depth_filled.astype(np.float32), 5)
        else:
            # Orta: 3x3 medyan + 1 iterasyon
            depth_filled = cv2.medianBlur(depth_filled.astype(np.float32), 3)
        
        depth = depth_filled
    
    # 4) Derinlik haritasını yumuşat (geçişleri yumuşat)
    if smooth_sigma > 0:
        depth = cv2.GaussianBlur(depth, (0, 0), smooth_sigma)
    
    # 5) 0-1 aralığına normalize et (isteğe bağlı)
    # depth = depth / (N - 1)
    
    return depth.astype(np.float32)


def blend_with_depth_map(images: np.ndarray, depth_map: np.ndarray,
                         sharpness_threshold: float = 0.0) -> np.ndarray:
    """
    Derinlik haritasını kullanarak görüntüleri harmanlar.
    Her piksel için, depth_map'te belirtilen kareyi kullanır,
    ancak geçişlerde yumuşak harmanlama yapar.
    
    Args:
        images: (N, H, W, C) float32 [0, 1] veya uint8
        depth_map: (H, W) float32, her piksel için kare indeksi
        sharpness_threshold: altında kalan kareleri harmanlamadan çıkar
    
    Returns:
        result: (H, W, C) uint8 veya float32
    """
    N, H, W = depth_map.shape
    
    # Depth map'i int'e çevir ve sınırla
    depth_idx = np.clip(np.round(depth_map), 0, N - 1).astype(np.int32)
    
    # Sonucu oluştur
    if images.dtype == np.uint8:
        result = np.zeros((H, W, images.shape[3]), dtype=np.uint8)
    else:
        result = np.zeros((H, W, images.shape[3]), dtype=np.float32)
    
    for i in range(N):
        mask = (depth_idx == i)
        if np.any(mask):
            result[mask] = images[i][mask]
    
    return result

