<?php
/**
 * debug-worker.php — Sadece GELİŞTİRME için. Prod'da SİL.
 *
 * Erişim:  https://.../debug-worker.php?key=DEGISTIR
 *
 * Ne yapar:
 *   1. .env dosyasını okur
 *   2. Python venv'i kontrol eder
 *   3. Worker modüllerini kontrol eder
 *   4. Import testi yapar
 *   5. worker --once çalıştırır
 *   6. Son 5 job'ı DB'den gösterir
 */

// ⚠️ BURAYI DEĞİŞTİR — uzun, tahmin edilemez bir string yap
const DEBUG_KEY = '312';

if (!isset($_GET['key']) || !hash_equals(DEBUG_KEY, (string) $_GET['key'])) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ini_set('display_errors', '1');
error_reporting(E_ALL);

$PROJECT = __DIR__;

// ---------------------------------------------------------------
// 0) .env yükle
// ---------------------------------------------------------------
echo "=== 0. .ENV YÜKLEME ===\n";
$envFile = $PROJECT . '/.env';
if (!is_readable($envFile)) {
    echo "  UYARI: .env dosyası bulunamadı: $envFile\n";
    echo "  Devam ediliyor; env değişkenleri başka yoldan gelmiş olabilir.\n";
} else {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        $parts = explode('=', $line, 2);
        $k = trim($parts[0]);
        $v = trim($parts[1], " \t\"'");
        if ($k !== '') {
            putenv("$k=$v");
        }
    }
    echo "  .env yüklendi.\n";
}

// ---------------------------------------------------------------
// 1) Kritik env değişkenleri
// ---------------------------------------------------------------
echo "\n=== 1. ENV DEĞİŞKENLERİ ===\n";
$check = [
    'STACK_PROJECT_ROOT',
    'STACK_DB_HOST', 'STACK_DB_PORT', 'STACK_DB_NAME', 'STACK_DB_USER',
    'STACK_DB_PASSWORD',
    'STACK_STORAGE_ROOT',
];
foreach ($check as $k) {
    $v = getenv($k);
    if ($k === 'STACK_DB_PASSWORD' && $v !== false && $v !== '') {
        $v = str_repeat('*', min(strlen($v), 12)) . ' (uzunluk=' . strlen($v) . ')';
    }
    printf("  %-25s = %s\n", $k, $v !== false && $v !== '' ? $v : '(YOK)');
}

// ---------------------------------------------------------------
// 2) Python venv kontrolü
// ---------------------------------------------------------------
echo "\n=== 2. PYTHON VENV ===\n";
$python = $PROJECT . '/worker/venv/bin/python';
printf("  Yol: %s\n", $python);
printf("  Var mı?        %s\n", file_exists($python) ? 'EVET' : 'HAYIR');
printf("  Executable mi? %s\n", is_executable($python) ? 'EVET' : 'HAYIR');

if (!file_exists($python)) {
    echo "  → Devam edilemez. venv yolu yanlış veya silinmiş.\n";
    exit;
}

// ---------------------------------------------------------------
// 3) Worker dosya kontrolü
// ---------------------------------------------------------------
echo "\n=== 3. WORKER DOSYALARI ===\n";
$files = [
    'worker/__init__.py',
    'worker/config.py',
    'worker/worker.py',
    'worker/db.py',
    'worker/focus_stack.py',
    'worker/alignment.py',
    'worker/focus_maps.py',
    'worker/preprocessing.py',
    'worker/blending.py',
    'worker/quality.py',
];
foreach ($files as $f) {
    $path = $PROJECT . '/' . $f;
    if (file_exists($path)) {
        printf("  %-30s VAR (%d byte)\n", $f, filesize($path));
    } else {
        printf("  %-30s YOK  ← EKSİK\n", $f);
    }
}

// ---------------------------------------------------------------
// 4) Python import testi
// ---------------------------------------------------------------
echo "\n=== 4. IMPORT TESTİ ===\n";
$importScript = <<<'PY'
import sys
sys.path.insert(0, '.')
try:
    from worker import config
    print("  [OK] config:", config.DB_HOST, "|", config.SOURCES_DIR)
except Exception as e:
    print("  [FAIL] config:", type(e).__name__, e)
    sys.exit(1)

try:
    from worker import db
    print("  [OK] db")
except Exception as e:
    print("  [FAIL] db:", type(e).__name__, e)

try:
    from worker import focus_stack
    print("  [OK] focus_stack")
except Exception as e:
    print("  [FAIL] focus_stack:", type(e).__name__, e)

try:
    from worker import worker
    print("  [OK] worker")
except Exception as e:
    print("  [FAIL] worker:", type(e).__name__, e)
    sys.exit(1)
PY;

$tmpScript = $PROJECT . '/storage/_debug_import.py';
@mkdir(dirname($tmpScript), 0755, true);
file_put_contents($tmpScript, $importScript);

$cmd = sprintf(
    'cd %s && HOME=%s %s %s 2>&1',
    escapeshellarg($PROJECT),
    escapeshellarg(getenv('HOME') ?: '/tmp'),
    escapeshellarg($python),
    escapeshellarg($tmpScript)
);
echo "  CMD: $cmd\n\n";
$out = shell_exec($cmd);
echo $out !== null ? $out : "  shell_exec NULL döndü\n";

@unlink($tmpScript);

// ---------------------------------------------------------------
// 5) worker --once çalıştır (opsiyonel, ?run=1 ile)
// ---------------------------------------------------------------
if (isset($_GET['run']) && $_GET['run'] === '1') {
    echo "\n=== 5. WORKER --once ===\n";
    $cmd = sprintf(
        'cd %s && HOME=%s PATH=/usr/local/bin:/usr/bin:/bin %s -m worker.worker --once 2>&1',
        escapeshellarg($PROJECT),
        escapeshellarg(getenv('HOME') ?: '/tmp'),
        escapeshellarg($python)
    );
    echo "  CMD: $cmd\n\n";
    $t0 = microtime(true);
    $out = shell_exec($cmd);
    $dt = round(microtime(true) - $t0, 2);
    if ($out === null) {
        echo "  HATA: shell_exec null döndü.\n";
    } else {
        if (strlen($out) > 100000) {
            $out = substr($out, 0, 100000) . "\n...[kısaltıldı]";
        }
        echo $out;
    }
    echo "\n  Süre: {$dt}s\n";
} else {
    echo "\n=== 5. WORKER --once ===\n";
    echo "  Atlandı. Çalıştırmak için URL'ye &run=1 ekle.\n";
}

// ---------------------------------------------------------------
// 6) Son 5 job
// ---------------------------------------------------------------
echo "\n=== 6. SON 5 JOB ===\n";
try {
    require_once $PROJECT . '/app/db.php';
    $db = getDB();
    $rows = $db->query(
        "SELECT id, status, stage, progress, error_msg
         FROM jobs ORDER BY id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        printf("  Job #%-4d  %-15s  %-12s  %3d%%  %s\n",
            $r['id'], $r['status'], $r['stage'], (int)$r['progress'],
            $r['error_msg'] ? '| ' . substr($r['error_msg'], 0, 80) : ''
        );
    }
} catch (Throwable $e) {
    echo "  DB hatası: " . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------
// 7) Disk durumu
// ---------------------------------------------------------------
echo "\n=== 7. STORAGE DURUMU ===\n";
$storage = getenv('STACK_STORAGE_ROOT') ?: ($PROJECT . '/storage');
foreach (['sources/4', 'outputs/4', 'previews/4'] as $sub) {
    $p = $storage . '/' . $sub;
    if (is_dir($p)) {
        $n = count(glob($p . '/*'));
        printf("  %-20s  %d dosya\n", $sub, $n);
    } else {
        printf("  %-20s  YOK\n", $sub);
    }
}

echo "\n=== BİTTİ ===\n";