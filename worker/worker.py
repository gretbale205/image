#!/usr/bin/env python3
"""
Focus Stacking Worker.
Kullanım:
  python -m worker.worker              # sürekli çalış
  python -m worker.worker --once       # kuyruğu boşalt, çık (cron için)
"""
import signal
import sys
import time
import traceback
from pathlib import Path

import redis
from PIL import Image

from .config import (
    REDIS_HOST, REDIS_PORT, REDIS_DB, REDIS_QUEUE,
    SOURCES_DIR, OUTPUT_DIR, PREVIEW_DIR,
    PREVIEW_MAX_SIZE, POLL_INTERVAL,
)
from . import db
from .focus_stack import stack_images

RUNNING = True


def _sig(sig, frame):
    global RUNNING
    print("\n[worker] Kapatma sinyali alındı...")
    RUNNING = False


signal.signal(signal.SIGINT, _sig)
signal.signal(signal.SIGTERM, _sig)


def get_redis():
    try:
        r = redis.Redis(
            host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB,
            socket_connect_timeout=2, socket_timeout=2
        )
        r.ping()
        return r
    except Exception:
        return None


def pop_redis_job(r):
    try:
        item = r.lpop(REDIS_QUEUE)
        return int(item) if item else None
    except Exception:
        return None


def _get_job_options(job_id):
    """
    Job'un method / output_bitdepth seçeneklerini DB'den oku.
    Şema eski olabilir (sütunlar yok) → default döndür.
    """
    method = "softmax"
    bitdepth = 8
    try:
        row = db.get_job(job_id)
    except Exception as e:
        print(f"[worker] get_job hatası, default kullanılacak: {e}")
        return method, bitdepth

    if not row:
        return method, bitdepth

    # method
    try:
        m = row.get("method")
        if m in ("softmax", "dmap"):
            method = m
    except Exception:
        pass

    # bitdepth
    try:
        b = row.get("output_bitdepth")
        if b is not None:
            b = int(b)
            if b in (8, 16):
                bitdepth = b
    except (TypeError, ValueError):
        pass

    return method, bitdepth


def process_job(job_id):
    print(f"\n[worker] ══ Job #{job_id} işleniyor ══")

    try:
        db.update_job(job_id, status='processing', stage='loading', progress=15)

        files = db.get_job_files(job_id, role='source')
        if len(files) < 2:
            raise Exception(f"En az 2 dosya gerekli, bulunan: {len(files)}")

        image_paths = [Path(f['path']) for f in files]
        missing = [str(p) for p in image_paths if not p.exists()]
        if missing:
            raise Exception(f"Eksik dosyalar: {missing}")

        print(f"[worker] {len(image_paths)} görüntü işlenecek")

        # --- Method / Bitdepth DB'den oku ---
        method, bitdepth = _get_job_options(job_id)
        print(f"[worker] method={method}  bitdepth={bitdepth}")

        out_dir = OUTPUT_DIR / str(job_id)
        out_dir.mkdir(parents=True, exist_ok=True)

        # 16-bit ise PNG, değilse JPG
        if bitdepth == 16:
            stacked = out_dir / f"stacked_{job_id}.png"
        else:
            stacked = out_dir / f"stacked_{job_id}.jpg"

        db.update_job(job_id, stage='stacking', progress=35)

        t0 = time.time()
        ok = stack_images(
            image_paths,
            stacked,
            method=method,
            output_bitdepth=bitdepth,
        )
        elapsed = round(time.time() - t0, 1)

        if not ok or not stacked.exists():
            raise Exception("Stacking başarısız")

        print(f"[worker] Stacking tamamlandı ({elapsed}s)")

        db.add_job_file(job_id, 'master', str(stacked), stacked.stat().st_size)

        # --- Preview üret ---
        db.update_job(job_id, stage='preview', progress=85)
        prev_dir = PREVIEW_DIR / str(job_id)
        prev_dir.mkdir(parents=True, exist_ok=True)
        prev_path = prev_dir / f"preview_{job_id}.jpg"

        # Master 16-bit PNG olabilir → PIL 16-bit PNG'yi okur ama
        # preview her zaman 8-bit RGB JPEG olsun (tarayıcı uyumu)
        img = Image.open(stacked)
        if img.mode not in ("RGB", "L"):
            img = img.convert("RGB")
        elif img.mode == "L":
            img = img.convert("RGB")
        # 16-bit → 8-bit indirgeme
        if img.mode == "I;16" or (hasattr(img, "mode") and "16" in str(img.mode)):
            img = img.point(lambda v: v * (1.0 / 256)).convert("RGB")

        img.thumbnail((PREVIEW_MAX_SIZE, PREVIEW_MAX_SIZE), Image.LANCZOS)
        img.save(prev_path, 'JPEG', quality=88)

        db.add_job_file(job_id, 'preview', str(prev_path), prev_path.stat().st_size)

        db.update_job(
            job_id, status='preview_ready', stage='completed',
            progress=100, error_msg=None
        )
        db.add_audit_log(job_id, 'stacking_completed')

        print(f"[worker] ✓ Job #{job_id} tamamlandı")

    except Exception as e:
        err = f"{type(e).__name__}: {e}"
        print(f"[worker] ✗ Job #{job_id} HATA: {err}")
        traceback.print_exc()
        try:
            db.update_job(job_id, status='failed', stage='error',
                          progress=0, error_msg=err[:500])
            db.add_audit_log(job_id, ('failed: ' + err)[:60])
        except Exception as db_err:
            print(f"[worker] DB hata güncellemesi başarısız: {db_err}")


def run_once():
    """Kuyruğu tamamen boşalt ve çık (cron için)."""
    print("[worker] --once modu: kuyruk boşaltılıyor")

    r = get_redis()

    # Önce Redis
    if r:
        while RUNNING:
            job_id = pop_redis_job(r)
            if job_id is None:
                break
            process_job(job_id)

    # Sonra DB
    while RUNNING:
        try:
            job_id = db.pop_queued_job()
        except Exception as e:
            print(f"[worker] DB hata: {e}")
            break
        if job_id is None:
            break
        process_job(job_id)

    print("[worker] --once modu bitti")


def run_forever():
    print("[worker] Sürekli mod")
    r = get_redis()
    if r:
        print(f"[worker] Redis bağlı: {REDIS_HOST}:{REDIS_PORT}")
    else:
        print("[worker] Redis yok, DB polling")

    while RUNNING:
        job_id = None
        if r:
            job_id = pop_redis_job(r)
        if job_id is None:
            try:
                job_id = db.pop_queued_job()
            except Exception as e:
                print(f"[worker] DB polling hatası: {e}")

        if job_id:
            process_job(job_id)
        else:
            for _ in range(POLL_INTERVAL * 2):
                if not RUNNING:
                    break
                time.sleep(0.5)

    print("[worker] Çıkış")


if __name__ == "__main__":
    if "--once" in sys.argv:
        run_once()
    else:
        run_forever()