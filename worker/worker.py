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

        out_dir = OUTPUT_DIR / str(job_id)
        out_dir.mkdir(parents=True, exist_ok=True)
        stacked = out_dir / f"stacked_{job_id}.jpg"

        db.update_job(job_id, stage='stacking', progress=35)

        t0 = time.time()
        ok = stack_images(image_paths, stacked)
        elapsed = round(time.time() - t0, 1)

        if not ok or not stacked.exists():
            raise Exception("Stacking başarısız")

        print(f"[worker] Stacking tamamlandı ({elapsed}s)")

        # ⚠️ DEĞİŞTİ: 'stacked' yerine 'master' (enum'da var)
        db.add_job_file(job_id, 'master', str(stacked), stacked.stat().st_size)

        db.update_job(job_id, stage='preview', progress=85)
        prev_dir = PREVIEW_DIR / str(job_id)
        prev_dir.mkdir(parents=True, exist_ok=True)
        prev_path = prev_dir / f"preview_{job_id}.jpg"

        img = Image.open(stacked)
        img.thumbnail((PREVIEW_MAX_SIZE, PREVIEW_MAX_SIZE), Image.LANCZOS)
        img.convert('RGB').save(prev_path, 'JPEG', quality=88)

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

            # ⚠️ DEĞİŞTİ: 64 karakter sınırı
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