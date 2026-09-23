<?php
/**
 * worker-cron.php — Python worker'ı tetikler
 * CloudPanel cron: her dakika çalışır.
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(1500);

// Kilit: aynı anda 2 tane çalışmasın
$lockFile = sys_get_temp_dir() . '/focus_worker.lock';
$fp = fopen($lockFile, 'c');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Başka worker çalışıyor, çıkılıyor.\n";
    exit(0);
}

$PROJECT = '/home/sularkuyumculuk/htdocs/www.sularkuyumculuk.com/pro/image';
$PYTHON  = $PROJECT . '/worker/venv/bin/python';
$HOME    = '/home/sularkuyumculuk';

if (!file_exists($PYTHON)) {
    echo "HATA: venv python bulunamadı: $PYTHON\n";
    exit(1);
}

$cmd = sprintf(
    'cd %s && HOME=%s PATH=/usr/local/bin:/usr/bin:/bin %s -m worker.worker --once 2>&1',
    escapeshellarg($PROJECT),
    escapeshellarg($HOME),
    escapeshellarg($PYTHON)
);

echo "[" . date('Y-m-d H:i:s') . "] Worker başlatılıyor...\n";
$output = shell_exec($cmd);
echo $output;
echo "\n[" . date('Y-m-d H:i:s') . "] Worker çıktı.\n";

flock($fp, LOCK_UN);
fclose($fp);