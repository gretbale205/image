#!/usr/bin/env python3
"""
Focus Stacking Worker.

Kullanım:
    python -m worker.worker
    python -m worker.worker --once
"""

from __future__ import annotations

import signal
import sys
import time
import traceback
from pathlib import Path

import cv2
import numpy as np
import redis

from .config import (
    REDIS_HOST,
    REDIS_PORT,
    REDIS_DB,
    REDIS_QUEUE,
    OUTPUT_DIR,
    PREVIEW_DIR,
    PREVIEW_MAX_SIZE,
    POLL_INTERVAL,
)

from . import db
from .focus_stack import stack_images


RUNNING = True

REDIS_PROCESSING_QUEUE = f"{REDIS_QUEUE}:processing"

VALID_METHODS = {
    "softmax",
    "pyramid",
    "dmap",
}

VALID_BITDEPTHS = {
    8,
    16,
}


# ---------------------------------------------------------------------------
# Signals
# ---------------------------------------------------------------------------

def _sig(sig, frame):
    global RUNNING

    print(
        "\n[worker] Kapatma sinyali alındı...",
        flush=True,
    )

    RUNNING = False


signal.signal(signal.SIGINT, _sig)
signal.signal(signal.SIGTERM, _sig)


# ---------------------------------------------------------------------------
# Redis
# ---------------------------------------------------------------------------

def get_redis():
    """
    Sadece Redis bağlantısını kontrol eder.

    ÖNEMLİ:
    Burada kesinlikle L/MOVE yapılmaz.
    İş claim işlemi yalnızca claim_redis_job()
    içerisinde yapılır.
    """

    try:
        r = redis.Redis(
            host=REDIS_HOST,
            port=REDIS_PORT,
            db=REDIS_DB,
            socket_connect_timeout=2,
            socket_timeout=2,
            decode_responses=False,
        )

        r.ping()

        print(
            f"[worker] Redis bağlantısı OK: "
            f"{REDIS_HOST}:{REDIS_PORT}",
            flush=True,
        )

        return r

    except Exception as e:
        print(
            f"[worker] Redis bağlantı hatası: {e}",
            flush=True,
        )

        return None


def _decode_job_id(item):
    if item is None:
        return None

    if isinstance(item, bytes):
        item = item.decode(
            "utf-8",
            errors="replace",
        )

    try:
        return int(item)

    except (TypeError, ValueError):
        print(
            f"[worker] Geçersiz Redis job id: {item}",
            flush=True,
        )

        return None


def claim_redis_job(
    r,
    blocking=False,
):
    """
    Redis ana kuyruğundan işi atomik olarak alır.

    REDIS_QUEUE
        ↓
    REDIS_PROCESSING_QUEUE

    İş burada gerçekten claim edilir.
    """

    try:

        if blocking:

            item = r.blmove(
                REDIS_QUEUE,
                REDIS_PROCESSING_QUEUE,
                max(1, int(POLL_INTERVAL)),
                "LEFT",
                "RIGHT",
            )

        else:

            item = r.lmove(
                REDIS_QUEUE,
                REDIS_PROCESSING_QUEUE,
                "LEFT",
                "RIGHT",
            )

        job_id = _decode_job_id(item)

        if job_id is not None:

            print(
                f"[worker] Redis job claim edildi: "
                f"#{job_id}",
                flush=True,
            )

        return job_id

    except redis.ResponseError as e:

        if "unknown command" in str(e).lower():

            print(
                "[worker] Redis sürümü LMOVE/BLMOVE "
                "desteklemiyor. Redis >= 6.2 gerekli.",
                flush=True,
            )

        else:

            print(
                f"[worker] Redis claim hatası: {e}",
                flush=True,
            )

        return None

    except Exception as e:

        print(
            f"[worker] Redis claim hatası: {e}",
            flush=True,
        )

        return None


def ack_redis_job(
    r,
    job_id,
):
    """
    İş terminal duruma geldikten sonra
    processing kuyruğundan kaldırır.
    """

    try:

        removed = r.lrem(
            REDIS_PROCESSING_QUEUE,
            1,
            str(job_id),
        )

        if removed:

            print(
                f"[worker] Redis ACK: "
                f"Job #{job_id}",
                flush=True,
            )

        else:

            print(
                f"[worker] Redis ACK bulunamadı: "
                f"Job #{job_id}",
                flush=True,
            )

        return bool(removed)

    except Exception as e:

        print(
            f"[worker] Redis ACK hatası "
            f"job={job_id}: {e}",
            flush=True,
        )

        return False


# ---------------------------------------------------------------------------
# Job options
# ---------------------------------------------------------------------------

def _get_job_options(job_id):

    method = "softmax"
    bitdepth = 8

    try:

        row = db.get_job(job_id)

    except Exception as e:

        print(
            "[worker] get_job hatası, "
            f"default kullanılacak: {e}",
            flush=True,
        )

        return method, bitdepth

    if not row:
        return method, bitdepth

    try:

        m = row.get("method")

        if m in VALID_METHODS:
            method = m

    except Exception:
        pass

    try:

        b = row.get("output_bitdepth")

        if b is not None:

            b = int(b)

            if b in VALID_BITDEPTHS:
                bitdepth = b

    except (TypeError, ValueError):

        pass

    return method, bitdepth


# ---------------------------------------------------------------------------
# Preview
# ---------------------------------------------------------------------------

def _write_preview(
    master_path: Path,
    preview_path: Path,
):

    img = cv2.imread(
        str(master_path),
        cv2.IMREAD_UNCHANGED,
    )

    if img is None:

        raise IOError(
            f"Preview için master okunamadı: "
            f"{master_path}"
        )

    # ---------------------------------------------------------------
    # Grayscale
    # ---------------------------------------------------------------

    if img.ndim == 2:

        if img.dtype == np.uint16:

            img8 = (
                img.astype(np.float32)
                / 257.0
            ).clip(
                0,
                255,
            ).astype(np.uint8)

        elif img.dtype == np.uint8:

            img8 = img

        else:

            finite = np.isfinite(img)

            if np.any(finite):

                max_value = float(
                    np.nanmax(img[finite])
                )

            else:

                max_value = 1.0

            if max_value <= 1.001:

                img8 = (
                    np.clip(img, 0, 1)
                    * 255
                ).astype(np.uint8)

            else:

                img8 = (
                    np.clip(
                        img / max_value,
                        0,
                        1,
                    )
                    * 255
                ).astype(np.uint8)

        rgb = cv2.cvtColor(
            img8,
            cv2.COLOR_GRAY2RGB,
        )

    # ---------------------------------------------------------------
    # Color
    # ---------------------------------------------------------------

    else:

        if img.dtype == np.uint16:

            img8 = (
                img.astype(np.float32)
                / 257.0
            ).clip(
                0,
                255,
            ).astype(np.uint8)

        elif img.dtype == np.uint8:

            img8 = img

        else:

            finite = np.isfinite(img)

            if np.any(finite):

                max_value = float(
                    np.nanmax(img[finite])
                )

            else:

                max_value = 1.0

            if max_value <= 1.001:

                img8 = (
                    np.clip(img, 0, 1)
                    * 255
                ).astype(np.uint8)

            else:

                img8 = (
                    np.clip(
                        img / max_value,
                        0,
                        1,
                    )
                    * 255
                ).astype(np.uint8)

        rgb = cv2.cvtColor(
            img8,
            cv2.COLOR_BGR2RGB,
        )

    # ---------------------------------------------------------------
    # Resize
    # ---------------------------------------------------------------

    h, w = rgb.shape[:2]

    max_dim = int(PREVIEW_MAX_SIZE)

    if max(h, w) > max_dim:

        scale = (
            max_dim
            / float(max(h, w))
        )

        new_w = max(
            1,
            int(round(w * scale)),
        )

        new_h = max(
            1,
            int(round(h * scale)),
        )

        rgb = cv2.resize(
            rgb,
            (new_w, new_h),
            interpolation=cv2.INTER_AREA,
        )

    # ---------------------------------------------------------------
    # JPEG
    # ---------------------------------------------------------------

    preview_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    bgr = cv2.cvtColor(
        rgb,
        cv2.COLOR_RGB2BGR,
    )

    ok = cv2.imwrite(
        str(preview_path),
        bgr,
        [
            cv2.IMWRITE_JPEG_QUALITY,
            88,
        ],
    )

    if not ok or not preview_path.exists():

        raise IOError(
            f"Preview yazılamadı: "
            f"{preview_path}"
        )


# ---------------------------------------------------------------------------
# Job processing
# ---------------------------------------------------------------------------

def process_job(job_id):

    print(
        f"\n[worker] ══ Job #{job_id} işleniyor ══",
        flush=True,
    )

    try:

        # ---------------------------------------------------------------
        # Job
        # ---------------------------------------------------------------

        row = db.get_job(job_id)

        if not row:

            raise RuntimeError(
                f"Job #{job_id} DB'de bulunamadı"
            )

        current_status = row.get("status")

        print(
            f"[worker] Job #{job_id} mevcut durum: "
            f"status={current_status}, "
            f"stage={row.get('stage')}, "
            f"progress={row.get('progress')}",
            flush=True,
        )

        # ---------------------------------------------------------------
        # Terminal durum
        # ---------------------------------------------------------------

        if current_status in {
            "preview_ready",
            "completed",
        }:

            print(
                f"[worker] Job #{job_id} "
                f"zaten tamamlanmış: "
                f"{current_status}",
                flush=True,
            )

            return True

        # ---------------------------------------------------------------
        # Processing başlangıcı
        #
        # Redis'ten gelen job veya DB fallback job'u
        # burada processing olarak kabul edilir.
        #
        # Artık "processing ise çık" YOK.
        # ---------------------------------------------------------------

        db.update_job(
            job_id,
            status="processing",
            stage="loading",
            progress=15,
            error_msg=None,
        )

        # ---------------------------------------------------------------
        # Source files
        # ---------------------------------------------------------------

        files = db.get_job_files(
            job_id,
            role="source",
        )

        if len(files) < 2:

            raise RuntimeError(
                f"En az 2 dosya gerekli, "
                f"bulunan: {len(files)}"
            )

        image_paths = [
            Path(f["path"])
            for f in files
        ]

        missing = [
            str(p)
            for p in image_paths
            if not p.exists()
        ]

        if missing:

            raise RuntimeError(
                f"Eksik dosyalar: {missing}"
            )

        print(
            f"[worker] "
            f"{len(image_paths)} görüntü işlenecek",
            flush=True,
        )

        # ---------------------------------------------------------------
        # Options
        # ---------------------------------------------------------------

        method, bitdepth = (
            _get_job_options(job_id)
        )

        print(
            f"[worker] method={method} "
            f"bitdepth={bitdepth}",
            flush=True,
        )

        # ---------------------------------------------------------------
        # Output
        # ---------------------------------------------------------------

        out_dir = (
            OUTPUT_DIR / str(job_id)
        )

        out_dir.mkdir(
            parents=True,
            exist_ok=True,
        )

        if bitdepth == 16:

            stacked = (
                out_dir
                / f"stacked_{job_id}.png"
            )

        else:

            stacked = (
                out_dir
                / f"stacked_{job_id}.jpg"
            )

        # ---------------------------------------------------------------
        # Stack
        # ---------------------------------------------------------------

        db.update_job(
            job_id,
            status="processing",
            stage="stacking",
            progress=35,
        )

        print(
            f"[worker] Job #{job_id} "
            "stack_images() başlıyor...",
            flush=True,
        )

        t0 = time.perf_counter()

        ok = stack_images(
            image_paths,
            stacked,
            method=method,
            output_bitdepth=bitdepth,
        )

        elapsed = round(
            time.perf_counter() - t0,
            1,
        )

        if not ok or not stacked.exists():

            raise RuntimeError(
                "Stacking başarısız"
            )

        print(
            f"[worker] Stacking tamamlandı "
            f"({elapsed}s)",
            flush=True,
        )

        # ---------------------------------------------------------------
        # Master record
        # ---------------------------------------------------------------

        db.add_job_file(
            job_id,
            "master",
            str(stacked),
            stacked.stat().st_size,
        )

        # ---------------------------------------------------------------
        # Preview
        # ---------------------------------------------------------------

        db.update_job(
            job_id,
            status="processing",
            stage="preview",
            progress=85,
        )

        prev_dir = (
            PREVIEW_DIR / str(job_id)
        )

        prev_path = (
            prev_dir
            / f"preview_{job_id}.jpg"
        )

        _write_preview(
            stacked,
            prev_path,
        )

        db.add_job_file(
            job_id,
            "preview",
            str(prev_path),
            prev_path.stat().st_size,
        )

        # ---------------------------------------------------------------
        # Completed
        # ---------------------------------------------------------------

        db.update_job(
            job_id,
            status="preview_ready",
            stage="completed",
            progress=100,
            error_msg=None,
        )

        db.add_audit_log(
            job_id,
            "stacking_completed",
        )

        print(
            f"[worker] ✓ Job #{job_id} tamamlandı",
            flush=True,
        )

        return True

    except Exception as e:

        err = (
            f"{type(e).__name__}: {e}"
        )

        print(
            f"[worker] ✗ Job #{job_id} "
            f"HATA: {err}",
            flush=True,
        )

        traceback.print_exc()

        try:

            db.update_job(
                job_id,
                status="failed",
                stage="error",
                progress=0,
                error_msg=err[:500],
            )

            db.add_audit_log(
                job_id,
                ("failed: " + err)[:60],
            )

        except Exception as db_err:

            print(
                "[worker] DB hata güncellemesi "
                f"başarısız: {db_err}",
                flush=True,
            )

        return False


# ---------------------------------------------------------------------------
# Redis processing
# ---------------------------------------------------------------------------

def process_redis_job(
    r,
    job_id,
):

    try:

        success = process_job(
            job_id
        )

        # İş başarıyla veya application-level
        # failure ile terminal duruma geldi.
        ack_redis_job(
            r,
            job_id,
        )

        return success

    except BaseException:

        # Gerçek process interruption durumunda
        # ACK yapma.
        #
        # Böylece Redis processing queue'da kalır
        # ve manuel/recovery mekanizması ile tekrar
        # ele alınabilir.
        raise


# ---------------------------------------------------------------------------
# DB queue
# ---------------------------------------------------------------------------

def pop_db_job():

    try:

        return db.pop_queued_job()

    except Exception as e:

        print(
            f"[worker] DB queue hatası: {e}",
            flush=True,
        )

        return None


# ---------------------------------------------------------------------------
# Once
# ---------------------------------------------------------------------------

def run_once():

    print(
        "[worker] --once modu başladı",
        flush=True,
    )

    r = get_redis()

    # ---------------------------------------------------------------
    # Redis queue
    # ---------------------------------------------------------------

    if r:

        while RUNNING:

            job_id = claim_redis_job(
                r,
                blocking=False,
            )

            if job_id is None:
                break

            process_redis_job(
                r,
                job_id,
            )

    # ---------------------------------------------------------------
    # DB fallback
    # ---------------------------------------------------------------

    while RUNNING:

        job_id = pop_db_job()

        if job_id is None:
            break

        process_job(
            int(job_id)
        )

    print(
        "[worker] --once modu bitti",
        flush=True,
    )


# ---------------------------------------------------------------------------
# Forever
# ---------------------------------------------------------------------------

def run_forever():

    print(
        "[worker] Sürekli mod",
        flush=True,
    )

    r = get_redis()

    if r:

        print(
            f"[worker] Redis bağlı: "
            f"{REDIS_HOST}:{REDIS_PORT}",
            flush=True,
        )

    else:

        print(
            "[worker] Redis yok, DB polling",
            flush=True,
        )

    while RUNNING:

        # -----------------------------------------------------------
        # Redis
        # -----------------------------------------------------------

        if r:

            job_id = claim_redis_job(
                r,
                blocking=True,
            )

            if job_id is not None:

                process_redis_job(
                    r,
                    job_id,
                )

                continue

        # -----------------------------------------------------------
        # DB fallback
        # -----------------------------------------------------------

        if r is None:

            job_id = pop_db_job()

            if job_id is not None:

                process_job(
                    int(job_id)
                )

                continue

        time.sleep(0.5)

    print(
        "[worker] Çıkış",
        flush=True,
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == "__main__":

    if "--once" in sys.argv:

        run_once()

    else:

        run_forever()