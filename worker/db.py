"""MySQL erişim katmanı."""
from contextlib import contextmanager
import mysql.connector
from .config import DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS


@contextmanager
def get_db():
    conn = mysql.connector.connect(
        host=DB_HOST, port=DB_PORT, database=DB_NAME,
        user=DB_USER, password=DB_PASS, autocommit=False
    )
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def update_job(job_id, **fields):
    if not fields:
        return
    sets = ", ".join(f"{k} = %s" for k in fields)
    values = list(fields.values()) + [job_id]
    with get_db() as conn:
        cur = conn.cursor()
        cur.execute(f"UPDATE jobs SET {sets} WHERE id = %s", values)
        cur.close()


def get_job_files(job_id, role="source"):
    with get_db() as conn:
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT * FROM job_files WHERE job_id = %s AND role = %s ORDER BY id",
            (job_id, role)
        )
        rows = cur.fetchall()
        cur.close()
        return rows


def add_job_file(job_id, role, path, size=None):
    with get_db() as conn:
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO job_files (job_id, role, path, size) VALUES (%s, %s, %s, %s)",
            (job_id, role, str(path), size)
        )
        cur.close()


def add_audit_log(job_id, action, user_ref=None):
    with get_db() as conn:
        cur = conn.cursor()
        cur.execute("SHOW COLUMNS FROM audit_log LIKE 'user_ref'")
        has_user_ref = cur.fetchone() is not None
        if has_user_ref:
            cur.execute(
                "INSERT INTO audit_log (job_id, user_ref, action) VALUES (%s, %s, %s)",
                (job_id, user_ref, action)
            )
        else:
            cur.execute(
                "INSERT INTO audit_log (job_id, action) VALUES (%s, %s)",
                (job_id, action)
            )
        cur.close()


def pop_queued_job():
    """Atomik olarak kuyruktan bir iş çek. Yoksa None."""
    with get_db() as conn:
        cur = conn.cursor(dictionary=True)
        cur.execute(
            "SELECT id FROM jobs WHERE status = 'queued' "
            "ORDER BY id ASC LIMIT 1 FOR UPDATE"
        )
        row = cur.fetchone()
        if not row:
            cur.close()
            return None
        job_id = int(row['id'])
        cur.execute(
            "UPDATE jobs SET status='processing', stage='starting', progress=5 WHERE id=%s",
            (job_id,)
        )
        cur.close()
        return job_id


def get_job(job_id):
    with get_db() as conn:
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT * FROM jobs WHERE id = %s", (job_id,))
        row = cur.fetchone()
        cur.close()
        return row


def get_job_status(job_id):
    """Job'un mevcut status değerini döndür (yoksa None)."""
    try:
        with get_db() as conn:
            cur = conn.cursor()
            cur.execute("SELECT status FROM jobs WHERE id = %s", (job_id,))
            row = cur.fetchone()
            cur.close()
            return row[0] if row else None
    except Exception:
        return None


def is_job_cancelled(job_id):
    """Job kullanıcı tarafından iptal edilmişse True."""
    return get_job_status(job_id) == 'cancelled'