"""MySQL erişim katmanı."""

from contextlib import contextmanager

import mysql.connector

from .config import (
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_USER,
    DB_PASS,
)


@contextmanager
def get_db():

    conn = mysql.connector.connect(
        host=DB_HOST,
        port=DB_PORT,
        database=DB_NAME,
        user=DB_USER,
        password=DB_PASS,
        autocommit=False,
    )

    try:

        yield conn

        conn.commit()

    except Exception:

        conn.rollback()

        raise

    finally:

        conn.close()


# ---------------------------------------------------------------------------
# Job
# ---------------------------------------------------------------------------

def update_job(
    job_id,
    **fields,
):

    if not fields:
        return

    sets = ", ".join(
        f"{k} = %s"
        for k in fields
    )

    values = (
        list(fields.values())
        + [job_id]
    )

    with get_db() as conn:

        cur = conn.cursor()

        cur.execute(
            f"""
            UPDATE jobs
            SET {sets}
            WHERE id = %s
            """,
            values,
        )

        cur.close()


def get_job(job_id):

    with get_db() as conn:

        cur = conn.cursor(
            dictionary=True
        )

        cur.execute(
            """
            SELECT *
            FROM jobs
            WHERE id = %s
            """,
            (job_id,),
        )

        row = cur.fetchone()

        cur.close()

        return row


# ---------------------------------------------------------------------------
# Job claim
# ---------------------------------------------------------------------------

def claim_job(
    job_id,
    stage="loading",
    progress=15,
):
    """
    queued -> processing geçişini atomik yapar.

    True:
        Job bu worker tarafından claim edildi.

    False:
        Job artık queued değil.
    """

    with get_db() as conn:

        cur = conn.cursor()

        cur.execute(
            """
            UPDATE jobs
            SET
                status = 'processing',
                stage = %s,
                progress = %s,
                error_msg = NULL
            WHERE id = %s
              AND status = 'queued'
            """,
            (
                stage,
                progress,
                job_id,
            ),
        )

        claimed = (
            cur.rowcount == 1
        )

        cur.close()

        return claimed


# ---------------------------------------------------------------------------
# DB queue fallback
# ---------------------------------------------------------------------------

def pop_queued_job():
    """
    DB kuyruğundan atomik olarak bir queued job alır.

    Job burada processing durumuna geçirilir.
    """

    with get_db() as conn:

        cur = conn.cursor(
            dictionary=True
        )

        cur.execute(
            """
            SELECT id
            FROM jobs
            WHERE status = 'queued'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
            """
        )

        row = cur.fetchone()

        if not row:

            cur.close()

            return None

        job_id = int(
            row["id"]
        )

        cur.execute(
            """
            UPDATE jobs
            SET
                status = 'processing',
                stage = 'starting',
                progress = 5,
                error_msg = NULL
            WHERE id = %s
              AND status = 'queued'
            """,
            (job_id,),
        )

        if cur.rowcount != 1:

            cur.close()

            return None

        cur.close()

        return job_id


# ---------------------------------------------------------------------------
# Job files
# ---------------------------------------------------------------------------

def get_job_files(
    job_id,
    role="source",
):

    with get_db() as conn:

        cur = conn.cursor(
            dictionary=True
        )

        cur.execute(
            """
            SELECT *
            FROM job_files
            WHERE job_id = %s
              AND role = %s
            ORDER BY id
            """,
            (
                job_id,
                role,
            ),
        )

        rows = cur.fetchall()

        cur.close()

        return rows


def add_job_file(
    job_id,
    role,
    path,
    size=None,
):

    with get_db() as conn:

        cur = conn.cursor()

        cur.execute(
            """
            INSERT INTO job_files
                (job_id, role, path, size)
            VALUES
                (%s, %s, %s, %s)
            """,
            (
                job_id,
                role,
                str(path),
                size,
            ),
        )

        cur.close()


# ---------------------------------------------------------------------------
# Audit
# ---------------------------------------------------------------------------

def add_audit_log(
    job_id,
    action,
    user_ref=None,
):

    with get_db() as conn:

        cur = conn.cursor()

        cur.execute(
            "SHOW COLUMNS FROM audit_log LIKE 'user_ref'"
        )

        has_user_ref = (
            cur.fetchone()
            is not None
        )

        if has_user_ref:

            cur.execute(
                """
                INSERT INTO audit_log
                    (job_id, user_ref, action)
                VALUES
                    (%s, %s, %s)
                """,
                (
                    job_id,
                    user_ref,
                    action,
                ),
            )

        else:

            cur.execute(
                """
                INSERT INTO audit_log
                    (job_id, action)
                VALUES
                    (%s, %s)
                """,
                (
                    job_id,
                    action,
                ),
            )

        cur.close()