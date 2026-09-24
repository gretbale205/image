"""Worker configuration. Hardcoded values, no env needed."""
from __future__ import annotations

from pathlib import Path

# ---------------------------------------------------------------------------
# Proje kökü (bu dosyanın iki üst dizini)
# ---------------------------------------------------------------------------
PROJECT_ROOT = Path(__file__).resolve().parent.parent


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_NAME = "focusstack"
DB_USER = "focusstackadmin"
DB_PASS = "oABi9BwMIYtLlBZupx5u"


# ---------------------------------------------------------------------------
# Redis (kullanmasak da worker.py import ediyor)
# ---------------------------------------------------------------------------
REDIS_HOST = "127.0.0.1"
REDIS_PORT = 6379
REDIS_DB = 0
REDIS_QUEUE = "focus_stack_queue"


# ---------------------------------------------------------------------------
# Storage
# ---------------------------------------------------------------------------
_STORAGE_ROOT = PROJECT_ROOT / "storage"
SOURCES_DIR = _STORAGE_ROOT / "sources"
OUTPUT_DIR = _STORAGE_ROOT / "outputs"
PREVIEW_DIR = _STORAGE_ROOT / "previews"
RECYCLE_DIR = _STORAGE_ROOT / "recycle"
DEBUG_DIR = _STORAGE_ROOT / "debug"


# ---------------------------------------------------------------------------
# Worker davranışı
# ---------------------------------------------------------------------------
PREVIEW_MAX_SIZE = 1600
POLL_INTERVAL = 5