<?php
/**
 * worker-cron.php
 * Python worker'ı tetikler. CloudPanel cron: her dakika.
 *
 * Güvenlik:
 *   - Web'den erişildiğinde STACK_WORKER_SECRET header'ı zorunlu.
 *   - CLI'da (cron) doğrudan çalışır.
 *
 * Env:
 *   STACK_PROJECT_ROOT  (zorunlu) — proje kök dizini
 *   STACK_PYTHON_BIN    (opsiyonel) — varsayılan: <root>/worker/venv/bin/python
 *   STACK_WORKER_SECRET (web için zorunlu)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(1500);

$isCli = (PHP_SAPI === 'cli');

// ============================================================
// 1) Web erişimi için secret kontrolü
// ============================================================
if (!$isCli) {
    $expected = getenv('STACK_WORKER_SECRET');
    $provided = $_SERVER['HTTP_X_WORKER_SECRET'] ?? '';

    if (!$expected || !is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        // Detay sızdırma
        echo "Forbidden\n";
        error_log('[worker-cron] Yetkisiz erişim denemesi from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        exit(0);
    }
}

// ============================================================
// 2) Proje yolu
// ============================================================
$PROJECT = getenv('STACK_PROJECT_ROOT');
if (!$PROJECT) {
    // Fallback: bu dosyanın iki üst dizini
    $PROJECT = dirname(dirname(__DIR__));
}
$PROJECT = rtrim($PROJECT, '/');

if (!is_dir($PROJECT)) {
    error_log("[worker-cron] Proje dizini yok: $PROJECT");
    echo "HATA: proje dizini bulunamadı.\n";
    exit(1);
}

$PYTHON = getenv('STACK_PYTHON_BIN') ?: ($PROJECT . '/worker/venv/bin/python');
if (!is_file($PYTHON) || !is_executable($PYTHON)) {
    error_log("[worker-cron] Python bulunamadı: $PYTHON");
    echo "HATA: venv python bulunamadı.\n";
    exit(1);
}

// ============================================================
// 3) Kilit (proje başına unique)
// ============================================================
$lockHash = substr(sha1($PROJECT), 0, 12);
$lockFile = sys_get_temp_dir() . "/focus_worker_{$lockHash}.lock";
$fp = @fopen($lockFile, 'c');
if ($fp === false) {
    error_log("[worker-cron] Lock dosyası açılamadı: $lockFile");
    echo "HATA: kilit oluşturulamadı.\n";
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Başka worker çalışıyor.\n";
    fclose($fp);
    exit(0);
}

// Kilit sahipliğini garanti altına al
register_shutdown_function(function () use ($fp) {
    @flock($fp, LOCK_UN);
    @fclose($fp);
});

// ============================================================
// 4) Worker'ı çalıştır
// ============================================================
$HOME = getenv('HOME') ?: null;

$envPrefix = '';
if ($HOME !== null) {
    $envPrefix .= 'HOME=' . escapeshellarg($HOME) . ' ';
}
$envPrefix .= 'PATH=' . escapeshellarg('/usr/local/bin:/usr/bin:/bin') . ' ';

$cmd = sprintf(
    'cd %s && %s%s -m worker.worker --once 2>&1',
    escapeshellarg($PROJECT),
    $envPrefix,
    escapeshellarg($PYTHON)
);

$startTs = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Worker başlatılıyor...\n";

// stdout yakala, aynı zamanda log'a yaz
$output = shell_exec($cmd);
$elapsed = microtime(true) - $startTs;

if ($output === null) {
    // shell_exec null = komut hiç çalışmadı / izin yok
    error_log("[worker-cron] shell_exec null döndü. cmd=$cmd");
    echo "\n[" . date('Y-m-d H:i:s') . "] HATA: worker çalıştırılamadı.\n";
    exit(1);
}

// Çıktıyı sınırla (aşırı büyük log'a karşı)
if (strlen($output) > 512 * 1024) {
    $output = substr($output, 0, 512 * 1024)
            . "\n... [çıktı kısaltıldı, " . strlen($output) . " byte]";
}

echo $output;
printf(
    "\n[%s] Worker çıktı. Süre: %.2fs\n",
    date('Y-m-d H:i:s'),
    $elapsed
);

// Log dosyasına da yaz (cron logları için)
$logDir = $PROJECT . '/storage/logs';
if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
    $logLine = sprintf(
        "[%s] elapsed=%.2fs exit_ok=1 cmd=%s\n",
        date('Y-m-d H:i:s'),
        $elapsed,
        $cmd
    );
    @file_put_contents($logDir . '/worker-cron.log', $logLine, FILE_APPEND);
}

exit(0);