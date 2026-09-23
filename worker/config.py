"""
Worker yapılandırması.
DB bilgilerini app/db.php'den kopyalayın.
"""
import os
from pathlib import Path

# Proje kök dizini: /home/sularkuyumculuk/htdocs/www.sularkuyumculuk.com/pro/image
BASE_DIR = Path(__file__).resolve().parent.parent

# ============================================================
# MySQL — app/db.php'deki değerlerle AYNI olmalı
# ============================================================
DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = int(os.getenv("DB_PORT", 3306))
DB_NAME = "focusstack"        # ← app/db.php'den kopyalayın
DB_USER = "focusstackadmin"  # ←
DB_PASS = "Elsp92l88TqDpETlYfQO"      # ←

# ============================================================
# Redis (opsiyonel, PHP zaten push ediyorsa gerekli)
# ============================================================
REDIS_HOST = os.getenv("REDIS_HOST", "127.0.0.1")
REDIS_PORT = int(os.getenv("REDIS_PORT", 6379))
REDIS_DB   = int(os.getenv("REDIS_DB", 0))
REDIS_QUEUE = "focus_stack_queue"

# ============================================================
# Storage — helpers.php'deki STORAGE_PATH ile AYNI olmalı
# ============================================================
STORAGE_PATH = Path(os.getenv("STORAGE_PATH", str(BASE_DIR / "storage")))
SOURCES_DIR  = STORAGE_PATH / "sources"
OUTPUT_DIR   = STORAGE_PATH / "outputs"
PREVIEW_DIR  = STORAGE_PATH / "previews"

# Önizleme boyutu (px)
PREVIEW_MAX_SIZE = 1200

# Boşta bekleme (sadece sürekli modda)
POLL_INTERVAL = 3