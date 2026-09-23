"""
Focus stacking motoru.
Bu sistemde focus-stack binary yok → OpenCV fallback çalışır.
"""
import shutil
import subprocess
from pathlib import Path

import cv2
import numpy as np


def _focus_stack_binary(images, output_path):
    """focus-stack binary varsa kullan."""
    bin_path = shutil.which("focus-stack")
    if not bin_path:
        return False
    cmd = [bin_path, "--output", str(output_path)] + [str(p) for p in images]
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        return r.returncode == 0 and output_path.exists()
    except Exception as e:
        print(f"[focus-stack] binary hatası: {e}")
        return False


def _sharpness_map(gray):
    """Laplacian varyansı — piksel bazlı keskinlik."""
    lap = cv2.Laplacian(gray, cv2.CV_64F, ksize=3)
    return np.abs(lap)


def _focus_stack_opencv(image_paths, output_path):
    """OpenCV tabanlı focus stacking."""
    N = len(image_paths)
    if N < 2:
        raise ValueError("En az 2 görüntü gerekli")

    print(f"[stacking] {N} görüntü okunuyor...")
    imgs = []
    for p in image_paths:
        img = cv2.imread(str(p), cv2.IMREAD_COLOR)
        if img is None:
            raise IOError(f"Okunamadı: {p}")
        imgs.append(img)

    # Ortak boyut
    h = min(i.shape[0] for i in imgs)
    w = min(i.shape[1] for i in imgs)
    print(f"[stacking] Hedef boyut: {w}x{h}")
    imgs = [cv2.resize(i, (w, h), interpolation=cv2.INTER_AREA) for i in imgs]

    stack = np.stack(imgs, axis=0).astype(np.float32)

    # Keskinlik haritaları
    print("[stacking] Keskinlik haritaları hesaplanıyor...")
    sharp = []
    for i in imgs:
        g = cv2.cvtColor(i, cv2.COLOR_BGR2GRAY)
        s = _sharpness_map(g)
        # Yumuşat (blok geçişlerini önler)
        s = cv2.GaussianBlur(s.astype(np.float32), (15, 15), 0)
        sharp.append(s)

    sharp = np.stack(sharp, axis=0)     # (N, H, W)

    # En keskin indeksi bul
    print("[stacking] Ağırlık haritaları hesaplanıyor...")
    idx = np.argmax(sharp, axis=0)      # (H, W)

    # Ağırlık haritaları (one-hot)
    weights = np.zeros_like(sharp)
    for n in range(N):
        weights[n] = (idx == n).astype(np.float32)

    # Yumuşat
    weights = np.stack(
        [cv2.GaussianBlur(w, (9, 9), 0) for w in weights], axis=0
    )

    # Normalize
    wsum = weights.sum(axis=0, keepdims=True)
    wsum[wsum == 0] = 1.0
    weights /= wsum

    # Harmanlama
    print("[stacking] Harmanlama...")
    out = np.zeros_like(stack[0])
    for n in range(N):
        out += stack[n] * weights[n][..., None]

    out = np.clip(out, 0, 255).astype(np.uint8)

    output_path = Path(output_path)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    cv2.imwrite(str(output_path), out, [cv2.IMWRITE_JPEG_QUALITY, 92])
    print(f"[stacking] Kaydedildi: {output_path}")
    return True


def stack_images(image_paths, output_path):
    """Ana giriş noktası."""
    image_paths = [Path(p) for p in image_paths]
    output_path = Path(output_path)

    if _focus_stack_binary(image_paths, output_path):
        return True

    return _focus_stack_opencv(image_paths, output_path)