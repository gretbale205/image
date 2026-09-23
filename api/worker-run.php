<?php
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(1800);

// Basit güvenlik: ?key=xxx ile korunabilir
$SECRET = 'gizli-anahtar-12345';  // Kendi anahtarınızı yazın
if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit('Yetkisiz');
}

$PROJECT = '/home/sularkuyumculuk/htdocs/www.sularkuyumculuk.com/pro/image';
$PYTHON  = $PROJECT . '/worker/venv/bin/python';
$HOME    = '/home/sularkuyumculuk';

$cmd = sprintf(
    'cd %s && HOME=%s PATH=/usr/local/bin:/usr/bin:/bin %s -m worker.worker --once 2>&1',
    escapeshellarg($PROJECT),
    escapeshellarg($HOME),
    escapeshellarg($PYTHON)
);

echo shell_exec($cmd);