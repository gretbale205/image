#!/usr/bin/env python3
"""
Focus Stacking Worker.
Kullanım:
  python -m worker.worker              # sürekli çalış
  python -m worker.worker --once       # kuyruğu boşalt, çık (cron için)

v3.2:
  - PID bazlı log: storage/logs/worker_pid_<PID>.log
  - Method başına ayrı ayar
  - Progress callback → DB'ye aşama aşama yüzde
  - İptal edilen iş 'cancelled' kalır, hata olsa bile 'failed'e çevrilmez

v3.3:
  - Çıkışta worker.pid otomatik temizlenir (atexit + finally)
  - SIGKILL hariç tüm çıkışlarda temizlik garanti
"""
import atexit
import os
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


# ============================================================
# METHOD AYARLARI
# ============================================================
METHOD_CONFIG = {
    "softmax": {
        "label": "Softmax (Hızlı)",
        "preview_max_size": 1600,
        "jpeg_quality": 95,
        "align": True,
    },
    "dmap": {
        "label": "DMap (Derinlik)",
        "preview_max_size": 1600,
        "jpeg_quality": 95,
        "align": True,
    },
    "pyramid": {
        "label": "Pyramid (En Kaliteli)",
        "preview_max_size": 2000,
        "jpeg_quality": 96,
        "align": True,
    },
}


# ============================================================
# PID BAZLI LOG
# ============================================================
_LOG_FILE_PATH = None
_LOG_HANDLE = None


def _setup_worker_log():
    global _LOG_FILE_PATH, _LOG_HANDLE

    pid = os.getpid()
    log_dir = Path("storage/logs")

    try:
        log_dir.mkdir(parents=True, exist_ok=True)
    except Exception:
        log_dir = Path("/tmp")

    _LOG_FILE_PATH = log_dir / f"worker_pid_{pid}.log"

    try:
        _LOG_HANDLE = open(_LOG_FILE_PATH, "a", buffering=1, encoding="utf-8")
    except Exception:
        _LOG_HANDLE = None

    _log(f"[log] Bu worker'ın PID log dosyası: {_LOG_FILE_PATH}")
    _log(f"[log] cwd={os.getcwd()}  pid={pid}")


def _log(msg, level="INFO"):
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] [{level}] {msg}"

    try:
        print(line, flush=True)
    except Exception:
        pass

    if _LOG_HANDLE is not None:
        try:
            _LOG_HANDLE.write(line + "\n")
            _LOG_HANDLE.flush()
        except Exception:
            pass


def _sig(sig, frame):
    global RUNNING
    _log("[worker] Kapatma sinyali alındı...", "WARN")
    RUNNING = False


signal.signal(signal.SIGINT, _sig)
signal.signal(signal.SIGTERM, _sig)


# ============================================================
# PID DOSYASI TEMİZLİĞİ
# ============================================================
def _cleanup_pid_file():
    """
    Çıkışta storage/worker.pid dosyasını sil — SADECE bu PID'e aitse.
    Başka bir worker zaten başlamışsa dokunmaz.
    """
    try:
        pid_file = Path("storage/worker.pid")
        if not pid_file.exists():
            return

        try:
            content = pid_file.read_text(encoding="utf-8").strip()
            current_pid = int(content) if content else 0
        except Exception:
            current_pid = 0

        if current_pid == os.getpid():
            try:
                pid_file.unlink()
                _log(f"[cleanup] worker.pid temizlendi (PID={os.getpid()})")
            except Exception as e:
                _log(f"[cleanup] worker.pid silinemedi: {e}", "WARN")
        else:
            _log(f"[cleanup] worker.pid başka PID'e ait "
                 f"({current_pid}), dokunulmadı", "WARN")

    except Exception as e:
        try:
            _log(f"[cleanup] PID temizleme hatası: {e}", "WARN")
        except Exception:
            pass


def _close_log_handle():
    """Log dosyasını düzgün kapat (buffer flush)."""
    global _LOG_HANDLE
    if _LOG_HANDLE is not None:
        try:
            _LOG_HANDLE.flush()
            _LOG_HANDLE.close()
        except Exception:
            pass
        _LOG_HANDLE = None


# atexit: normal + exception + sys.exit() çıkışlarında çalışır.
# (SIGTERM/SIGINT sinyalleri → signal handler RUNNING=False yapar →
#  main döngü kırılır → main biter → atexit devreye girer.)
atexit.register(_cleanup_pid_file)
atexit.register(_close_log_handle)


# ============================================================
# Redis / DB
# ============================================================
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


# ============================================================
# Method / Bitdepth
# ============================================================
def _get_job_options(job_id):
    method = "softmax"
    bitdepth = 8

    try:
        row = db.get_job(job_id)
    except Exception as e:
        _log(f"get_job hatası, default kullanılacak: {e}", "WARN")
        return method, bitdepth

    if not row:
        return method, bitdepth

    try:
        m = row.get("method")
        if m in ("softmax", "dmap", "pyramid"):
            method = m
    except Exception:
        pass

    try:
        b = row.get("output_bitdepth")
        if b is not None:
            b = int(b)
            if b in (8, 16):
                bitdepth = b
    except (TypeError, ValueError):
        pass

    return method, bitdepth


# ============================================================
# İptal
# ============================================================
class JobCancelled(Exception):
    """Kullanıcı işi iptal ettiğinde fırlatılır."""
    pass


def _check_cancelled(job_id, stage=""):
    try:
        status = db.get_job_status(job_id)
    except Exception as e:
        _log(f"cancel kontrolü başarısız (job={job_id}): {e}", "WARN")
        return
    if status == "cancelled":
        raise JobCancelled(f"Job #{job_id} iptal edildi (stage={stage})")


# ============================================================
# PROCESS
# ============================================================
def process_job(job_id):
    _log(f"══ Job #{job_id} işleniyor ══")

    try:
        # Başlarken zaten iptal edilmiş mi?
        _check_cancelled(job_id, stage="start")

        db.update_job(job_id, status='processing', stage='loading', progress=5)

        files = db.get_job_files(job_id, role='source')
        if len(files) < 2:
            raise Exception(f"En az 2 dosya gerekli, bulunan: {len(files)}")

        image_paths = [Path(f['path']) for f in files]
        missing = [str(p) for p in image_paths if not p.exists()]
        if missing:
            raise Exception(f"Eksik dosyalar: {missing}")

        _log(f"{len(image_paths)} görüntü işlenecek")

        method, bitdepth = _get_job_options(job_id)
        cfg = METHOD_CONFIG.get(method, METHOD_CONFIG["softmax"])
        _log(f"method={method} ({cfg['label']})  bitdepth={bitdepth}")

        _check_cancelled(job_id, stage="after_options")

        out_dir = OUTPUT_DIR / str(job_id)
        out_dir.mkdir(parents=True, exist_ok=True)

        if bitdepth == 16:
            stacked = out_dir / f"stacked_{job_id}.png"
        else:
            stacked = out_dir / f"stacked_{job_id}.jpg"

        db.update_job(job_id, stage='stacking', progress=10)

        _log("▶ stack_images başlıyor")

        def _progress(pct, stage_name):
            try:
                db.update_job(job_id, progress=int(pct), stage=str(stage_name)[:40])
            except Exception as e:
                _log(f"progress güncelleme hatası: {e}", "WARN")

        def _cancel_check():
            try:
                status = db.get_job_status(job_id)
            except Exception:
                return False
            return status == "cancelled"

        def _stack_log(msg):
            _log(str(msg))

        t0 = time.time()
        ok = stack_images(
            image_paths,
            stacked,
            method=method,
            output_bitdepth=bitdepth,
            align=cfg.get("align", True),
            log_cb=_stack_log,
            progress_cb=_progress,
            cancel_cb=_cancel_check,
        )
        elapsed = round(time.time() - t0, 1)

        if not ok:
            # İptalden mi döndü, gerçek hata mı?
            if db.is_job_cancelled(job_id):
                raise JobCancelled(f"Job #{job_id} stacking sırasında iptal edildi")
            raise Exception("Stacking başarısız")

        if not stacked.exists():
            raise Exception("Master dosya oluşmadı")

        _log(f"✔ stack_images tamamlandı ({elapsed}s)")

        _check_cancelled(job_id, stage="after_stacking")

        db.add_job_file(job_id, 'master', str(stacked), stacked.stat().st_size)

        # --- Preview ---
        db.update_job(job_id, stage='preview', progress=92)
        prev_dir = PREVIEW_DIR / str(job_id)
        prev_dir.mkdir(parents=True, exist_ok=True)
        prev_path = prev_dir / f"preview_{job_id}.jpg"

        img = Image.open(stacked)
        if img.mode not in ("RGB", "L"):
            img = img.convert("RGB")
        elif img.mode == "L":
            img = img.convert("RGB")

        if img.mode == "I;16" or "16" in str(img.mode):
            img = img.point(lambda v: v * (1.0 / 256)).convert("RGB")

        max_size = cfg.get("preview_max_size", PREVIEW_MAX_SIZE)
        img.thumbnail((max_size, max_size), Image.LANCZOS)
        img.save(prev_path, 'JPEG', quality=cfg.get("jpeg_quality", 88))

        db.add_job_file(job_id, 'preview', str(prev_path), prev_path.stat().st_size)

        db.update_job(
            job_id, status='preview_ready', stage='completed',
            progress=100, error_msg=None
        )
        db.add_audit_log(job_id, 'stacking_completed')

        _log(f"✓ Job #{job_id} tamamlandı")

    except JobCancelled as e:
        # Kullanıcı iptal etti → status 'cancelled' KALSIN, ezme!
        _log(f"✗ Job #{job_id} İPTAL: {e}", "WARN")
        try:
            db.add_audit_log(job_id, 'cancelled_during_processing')
        except Exception:
            pass

    except Exception as e:
        err = f"{type(e).__name__}: {e}"
        _log(f"✗ Job #{job_id} HATA: {err}", "ERROR")
        _log(traceback.format_exc(), "ERROR")

        # ⚠️ KRİTİK: kullanıcı iptal ettiyse 'failed' yazma!
        try:
            if db.is_job_cancelled(job_id):
                _log(f"Job #{job_id} zaten 'cancelled' — 'failed' yazılmadı", "WARN")
                return
        except Exception:
            pass

        try:
            db.update_job(job_id, status='failed', stage='error',
                          progress=0, error_msg=err[:500])
            db.add_audit_log(job_id, ('failed: ' + err)[:60])
        except Exception as db_err:
            _log(f"DB hata güncellemesi başarısız: {db_err}", "ERROR")


# ============================================================
# MODLAR
# ============================================================
def run_once():
    _log("--once modu: kuyruk boşaltılıyor")

    r = get_redis()

    if r:
        while RUNNING:
            job_id = pop_redis_job(r)
            if job_id is None:
                break
            process_job(job_id)

    while RUNNING:
        try:
            job_id = db.pop_queued_job()
        except Exception as e:
            _log(f"DB hata: {e}", "ERROR")
            break
        if job_id is None:
            break
        process_job(job_id)

    _log("--once modu bitti")


def run_forever():
    _log("Sürekli mod")
    r = get_redis()
    if r:
        _log(f"Redis bağlı: {REDIS_HOST}:{REDIS_PORT}")
    else:
        _log("Redis yok, DB polling")

    while RUNNING:
        job_id = None
        if r:
            job_id = pop_redis_job(r)
        if job_id is None:
            try:
                job_id = db.pop_queued_job()
            except Exception as e:
                _log(f"DB polling hatası: {e}", "ERROR")

        if job_id:
            process_job(job_id)
        else:
            for _ in range(POLL_INTERVAL * 2):
                if not RUNNING:
                    break
                time.sleep(0.5)

    _log("Çıkış")


# ============================================================
# MAIN
# ============================================================
if __name__ == "__main__":
    _setup_worker_log()
    try:
        if "--once" in sys.argv:
            run_once()
        else:
            run_forever()
    except KeyboardInterrupt:
        _log("KeyboardInterrupt — kapatılıyor", "WARN")
    except Exception as e:
        _log(f"FATAL: {type(e).__name__}: {e}", "ERROR")
        _log(traceback.format_exc(), "ERROR")
        raise
    finally:
        # atexit zaten kayıtlı ama burada da garantiye alıyoruz;
        # idempotent olduğu için iki kez çağrılması sorun değil.
        _cleanup_pid_file()
        _log("[worker] kapanıyor")
        _close_log_handle()