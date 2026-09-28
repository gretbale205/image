<?php
/**
 * get-image.php — v2 (self-contained)
 * index.php ile aynı .env / aynı STORAGE_ROOT kullanır.
 */
error_reporting(0);
ini_set('display_errors', '0');

// Çıktı tamponlarını temizle (BOM/boşluk varsa görsel bozulmasın)
while (ob_get_level() > 0) { ob_end_clean(); }

// ---------- .ENV (index.php ile aynı yükleyici) ----------
function load_env(string $path): void {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}
load_env(__DIR__ . '/../.env');

function env(string $k, string $d=''): string { $v = getenv($k); return ($v===false||$v==='') ? $d : $v; }
function env_int(string $k, int $d): int { $v = getenv($k); return ($v===false||$v==='') ? $d : (int)$v; }

// ---------- AYARLAR (index.php ile aynı) ----------
$STORAGE_ROOT = env('STORAGE_ROOT', dirname(__DIR__) . '/storage');

// ---------- DB ----------
function img_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . env('DB_HOST','localhost')
                . ';port=' . env_int('DB_PORT', 3306)
                . ';dbname=' . env('DB_NAME','focusstack')
                . ';charset=utf8mb4',
            env('DB_USER',''),
            env('DB_PASS',''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

// ---------- Hata dönüşü ----------
function img_error(int $status, string $msg): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Parametreler ----------
$jobIdRaw  = $_GET['job_id']  ?? null;
$roleRaw   = $_GET['role']    ?? 'preview';
$fileIdRaw = $_GET['file_id'] ?? null;

if (!is_scalar($jobIdRaw) || !is_scalar($roleRaw)
    || ($fileIdRaw !== null && !is_scalar($fileIdRaw))) {
    img_error(400, 'Geçersiz parametre');
}

$jobId = (int)$jobIdRaw;
$role  = trim((string)$roleRaw);
if ($jobId <= 0) img_error(400, 'Geçersiz job_id');

$fileId = 0;
if ($fileIdRaw !== null && $fileIdRaw !== '') {
    $fileId = (int)$fileIdRaw;
    if ($fileId <= 0) img_error(400, 'Geçersiz file_id');
}

$allowedRoles = ['source', 'preview', 'master', 'web'];
if (!in_array($role, $allowedRoles, true)) img_error(400, 'Geçersiz role');

// ---------- DB'den yolu al ----------
try {
    $db = img_db();
    if ($fileId > 0) {
        $stmt = $db->prepare(
            "SELECT path FROM job_files
             WHERE id = ? AND job_id = ? AND role = ?
             LIMIT 1"
        );
        $stmt->execute([$fileId, $jobId, $role]);
    } else {
        $stmt = $db->prepare(
            "SELECT path FROM job_files
             WHERE job_id = ? AND role = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$jobId, $role]);
    }
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[get-image] DB: ' . $e->getMessage());
    img_error(500, 'Sunucu hatası (DB).');
}

if (!$file || empty($file['path'])) {
    img_error(404, 'Görsel kaydı bulunamadı');
}

$dbPath = $file['path'];

// Mutlak yol mu? Değilse STORAGE_ROOT'a göre çöz.
if ($dbPath[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $dbPath)) {
    // Relative path - proje köküne göre
    $dbPath = dirname(__DIR__) . '/' . ltrim($dbPath, '/\\');
}

$realPath = realpath($dbPath);
if ($realPath === false || !is_file($realPath)) {
    error_log('[get-image] Dosya yok. job_id=' . $jobId
              . ' role=' . $role
              . ' db_path=' . $file['path']
              . ' tried=' . $dbPath);
    img_error(404, 'Görsel diskte bulunamadı');
}

// ---------- Sandbox kontrolü (yumuşak) ----------
$storageReal = realpath($STORAGE_ROOT);
$inSandbox = true;
if ($storageReal !== false) {
    $prefix = rtrim($storageReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($realPath, $prefix, strlen($prefix)) !== 0) {
        $inSandbox = false;
    }
}

if (!$inSandbox) {
    error_log('[get-image] SANDBOX DIŞI: ' . $realPath
              . ' root=' . $storageReal);
    img_error(403, 'Erişim reddedildi');
}

if (!is_readable($realPath)) {
    img_error(403, 'Dosya okunamıyor (izin)');
}

// ---------- MIME ----------
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$extMimeMap = [
    'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
    'webp'=>'image/webp','tif'=>'image/tiff','tiff'=>'image/tiff','bmp'=>'image/bmp',
];
if (!isset($extMimeMap[$ext])) img_error(415, 'Desteklenmeyen tür');

$serveMime = $extMimeMap[$ext];
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi !== false) {
        $m = @finfo_file($fi, $realPath);
        finfo_close($fi);
        if (is_string($m) && strpos($m, 'image/') === 0) {
            $serveMime = $m;
        }
    }
}

// ---------- Yanıt ----------
$filesize = filesize($realPath);
if ($filesize === false || $filesize === 0) {
    img_error(500, 'Dosya boyutu okunamadı');
}

header('Content-Type: ' . $serveMime);
header('Content-Length: ' . $filesize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

$etag = '"' . md5($realPath . '|' . $filesize . '|' . filemtime($realPath)) . '"';
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($realPath)) . ' GMT');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

while (ob_get_level() > 0) { ob_end_clean(); }
readfile($realPath);
exit;