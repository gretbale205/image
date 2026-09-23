#!/usr/bin/env python3
"""
Focus Stacking Worker
Redis kuyruğunu dinler, işleri alır, focus stacking yapar.
Redis yoksa MySQL'den 'queued' işleri çeker.
"""
import json
import signal
import sys
import time
import traceback
from pathlib import Path

import redis
import cv2
from PIL import Image

from .config import (
    REDIS_HOST, REDIS_PORT, REDIS_DB, REDIS_QUEUE,
    SOURCES_DIR, OUTPUT_DIR, PREVIEW_DIR,
    PREVIEW_MAX_SIZE, POLL_INTERVAL,
)
from . import db
from .focus_stack import stack_images

RUNNING = True


def _signal_handler(sig, frame):
    global RUNNING
    print("\n[worker] Kapatma sinyali alındı, güvenli çıkış...")
    RUNNING = False


signal.signal(signal.SIGINT, _signal_handler)
signal.signal(signal.SIGTERM, _signal_handler)


# ----------------------------------------------------------------------
# Redis
# ----------------------------------------------------------------------
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
    """Blocking olmayan pop (BRPOPLPUSH kullanılabilir ama basit tutalım)."""
    try:
        item = r.lpop(REDIS_QUEUE)
        return int(item) if item else None
    except Exception:
        return None


# ----------------------------------------------------------------------
# DB fallback
# ----------------------------------------------------------------------
def pop_db_job():
    with db.get_db() as conn:
        cur = conn.cursor(dictionary=True)
        # Atomik olarak queued → processing yap
        cur.execute("""
            SELECT id FROM jobs
            WHERE status = 'queued'
            ORDER BY id ASC LIMIT 1
            FOR UPDATE
        """)
        row = cur.fetchone()
        if not row:
            cur.close()
            return None
        job_id = row['id']
        cur.execute("UPDATE jobs SET status = 'processing' WHERE id = %s", (job_id,))
        cur.close()
        return job_id


# ----------------------------------------------------------------------
# İşleme
# ----------------------------------------------------------------------
def process_job(job_id):
    print(f"\n[worker] ══ Job #{job_id} işleniyor ══")

    try:
        db.update_job(job_id, status='processing', stage='starting', progress=5)

        # 1) Kaynak dosyaları bul
        files = db.get_job_files(job_id, role='source')
        if not files:
            raise Exception("Kaynak dosya bulunamadı")

        image_paths = [Path(f['path']) for f in files]
        missing = [p for p in image_paths if not p.exists()]
        if missing:
            raise Exception(f"Eksik dosyalar: {missing}")

        print(f"[worker] {len(image_paths)} görüntü yüklenecek")
        db.update_job(job_id, stage='loading', progress=15)

        # 2) Focus stacking
        output_dir = OUTPUT_DIR / str(job_id)
        output_dir.mkdir(parents=True, exist_ok=True)
        stacked_path = output_dir / f"stacked_{job_id}.jpg"

        db.update_job(job_id, stage='stacking', progress=40)

        ok = stack_images(image_paths, stacked_path)
        if not ok or not stacked_path.exists():
            raise Exception("Focus stacking başarısız")

        # 3) DB'ye kaydet
        db.add_job_file(job_id, 'stacked', str(stacked_path), stacked_path.stat().st_size)
        db.update_job(job_id, stage='preview', progress=75)

        # 4) Önizleme üret
        preview_dir = PREVIEW_DIR / str(job_id)
        preview_dir.mkdir(parents=True, exist_ok=True)
        preview_path = preview_dir / f"preview_{job_id}.jpg"

        img = Image.open(stacked_path)
        img.thumbnail((PREVIEW_MAX_SIZE, PREVIEW_MAX_SIZE), Image.LANCZOS)
        img.convert('RGB').save(preview_path, 'JPEG', quality=88)

        db.add_job_file(job_id, 'preview', str(preview_path), preview_path.stat().st_size)

        # 5) Tamamlandı
        db.update_job(
            job_id,
            status='preview_ready',
            stage='completed',
            progress=100,
            error_msg=None
        )
        db.add_audit_log(job_id, 'stacking_completed')

        print(f"[worker] ✓ Job #{job_id} tamamlandı → {stacked_path}")

    except Exception as e:
        err = f"{type(e).__name__}: {e}"
        print(f"[worker] ✗ Job #{job_id} HATA: {err}")
        traceback.print_exc()
        try:
            db.update_job(job_id, status='failed', stage='error',
                          progress=0, error_msg=err[:500])
            db.add_audit_log(job_id, f'failed: {err[:200]}')
        except Exception as db_err:
            print(f"[worker] DB güncelleme hatası: {db_err}")


# ----------------------------------------------------------------------
# Ana döngü
# ----------------------------------------------------------------------
def main():
    print("[worker] Focus Stacking Worker başlatıldı")
    print(f"[worker] Kaynak dizin : {SOURCES_DIR}")
    print(f"[worker] Çıktı dizin  : {OUTPUT_DIR}")

    r = get_redis()
    if r:
        print(f"[worker] Redis bağlı  : {REDIS_HOST}:{REDIS_PORT}/{REDIS_DB}")
    else:
        print("[worker] ⚠ Redis yok, MySQL polling modunda çalışıyor")

    while RUNNING:
        job_id = None

        # 1) Redis'ten dene
        if r:
            try:
                job_id = pop_redis_job(r)
            except Exception:
                r = None

        # 2) Redis boş/yoksa DB'den dene
        if job_id is None:
            try:
                job_id = pop_db_job()
            except Exception as e:
                print(f"[worker] DB polling hatası: {e}")

        if job_id:
            process_job(job_id)
        else:
            # Boşta bekle
            for _ in range(POLL_INTERVAL * 2):
                if not RUNNING:
                    break
                time.sleep(0.5)

    print("[worker] Çıkış yapıldı")


if __name__ == "__main__":
    main()