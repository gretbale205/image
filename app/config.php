<?php
/**
 * app/config.php — Merkezi yapılandırma
 *
 * NOT: Aşağıdaki değerleri kendi ortamınıza göre doldurun.
 * Bu dosyayı .gitignore'a ekleyin.
 */

// ---------- Veritabanı ----------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'focusstack');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ---------- Yollar ----------
define('BASE_PATH',    __DIR__ . '/..');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('TRASH_PATH',   STORAGE_PATH . '/trash');
define('LOG_PATH',     STORAGE_PATH . '/logs');

// ---------- Redis (opsiyonel) ----------
define('REDIS_HOST', getenv('REDIS_HOST') ?: '127.0.0.1');
define('REDIS_PORT', (int)(getenv('REDIS_PORT') ?: 6379));

// ---------- Ayarlar ----------
define('TRASH_RETENTION_DAYS', 7);
define('WORKER_RESTART_GRACE', 3);  // SIGTERM → SIGKILL bekleme süresi (sn)