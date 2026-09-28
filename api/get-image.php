<?php
/**
 * get-image.php
 * Focus Stacking - Job'a ait görüntüyü güvenli şekilde sunar.
 *
 * GET parametreleri:
 *   - job_id : int (zorunlu)
 *   - role   : source | preview | master | web  (varsayılan: preview)
 *
 * Yanıt:
 *   - 200 : image binary
 *   - 400 : { success:false, error:"..." }
 *   - 404 : { success:false, error:"..." }
 *   - 415 : { success:false, error:"..." }
 *   - 500 : { success:false, error:"..." }
 */

// ============================================================
// BAĞIMLILIKLAR
// ============================================================
try {
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[get-image] Bağımlılık: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Sunucu yapılandırma hatası.']);
    exit;
}

// ============================================================
// YARDIMCI: hata dönüşü (JSON + nosniff)
// ============================================================
function image_error(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ============================================================
// 1) Parametre doğrulama
// ============================================================
$jobIdRaw = $_GET['job_id'] ?? null;
$roleRaw  = $_GET['role']   ?? 'preview';

// Array/obje enjeksiyonuna karşı (örn. ?job_id[]=1)
if (!is_scalar($jobIdRaw) || !is_scalar($roleRaw)) {
    image_error(400, 'Geçersiz parametre');
}

$jobId = (int) $jobIdRaw;
$role  = trim((string) $roleRaw);

if ($jobId <= 0) {
    image_error(400, 'Geçersiz job_id');
}

// Enum whitelist — DB şemasıyla senkron
$allowedRoles = ['source', 'preview', 'master', 'web'];
if (!in_array($role, $allowedRoles, true)) {
    image_error(400, 'Geçersiz role');
}

// ============================================================
// 2) STORAGE_PATH kontrolü
// ============================================================
if (!defined('STORAGE_PATH')) {
    error_log('[get-image] STORAGE_PATH tanımlı değil');
    image_error(500, 'Sunucu yapılandırma hatası.');
}

$storageRoot = realpath(STORAGE_PATH);
if ($storageRoot === false) {
    error_log('[get-image] STORAGE_PATH realpath başarısız: ' . STORAGE_PATH);
    image_error(500, 'Sunucu yapılandırma hatası.');
}
$storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);

// ============================================================
// 3) DB'den yolu al
// ============================================================
try {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT path FROM job_files
         WHERE job_id = ? AND role = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$jobId, $role]);
    $file = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[get-image] DB: ' . $e->getMessage());
    image_error(500, 'Sunucu hatası.');
}

if (!$file || empty($file['path'])) {
    image_error(404, 'Görsel bulunamadı');
}

// ============================================================
// 4) Storage sandbox — path traversal savunması
// ============================================================
$realPath = realpath($file['path']);
if ($realPath === false) {
    // Dosya disk'ten silinmiş veya yol bozuk
    error_log('[get-image] realpath başarısız: ' . $file['path']);
    image_error(404, 'Görsel bulunamadı');
}

// STORAGE_PATH altında mı? (strncmp = PHP 7.4 uyumlu)
$prefix = $storageRoot . DIRECTORY_SEPARATOR;
if (strncmp($realPath, $prefix, strlen($prefix)) !== 0) {
    error_log('[get-image] Sandbox ihlali! job_id=' . $jobId
              . ' role=' . $role
              . ' resolved=' . $realPath
              . ' root=' . $storageRoot);
    image_error(403, 'Erişim reddedildi');
}

if (!is_file($realPath) || !is_readable($realPath)) {
    image_error(404, 'Görsel bulunamadı');
}

// ============================================================
// 5) MIME tespiti (finfo + extension fallback)
// ============================================================
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

// Uzantıdan beklenen MIME (fallback)
$extMimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'tif'  => 'image/tiff',
    'tiff' => 'image/tiff',
    'bmp'  => 'image/bmp',
];

if (!isset($extMimeMap[$ext])) {
    image_error(415, 'Desteklenmeyen görüntü türü');
}

// finfo ile gerçek içerik türünü tespit et
$detectedMime = null;
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi !== false) {
        $m = finfo_file($fi, $realPath);
        finfo_close($fi);
        if (is_string($m) && $m !== '') {
            $detectedMime = strtolower($m);
        }
    }
}

// İzin verilen görüntü MIME'leri
$allowedImageMimes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/tiff',
    'image/bmp',
    'image/x-ms-bmp',   // bazı sistemler BMP için bunu döner
];

$serveMime = null;
if ($detectedMime !== null && in_array($detectedMime, $allowedImageMimes, true)) {
    // finfo doğru bir görüntü türü döndürdüyse onu kullan
    $serveMime = $detectedMime;
} elseif ($detectedMime === null) {
    // finfo yok/başarısız → uzantıya güven
    $serveMime = $extMimeMap[$ext];
} else {
    // finfo "image/*" olmayan bir şey döndürdü (örn. text/html) → reddet
    error_log('[get-image] MIME uyuşmazlığı: ext=' . $ext
              . ' detected=' . $detectedMime
              . ' path=' . $realPath);
    image_error(415, 'Görüntü içeriği doğrulanamadı');
}

// ============================================================
// 6) Yanıt başlıkları + gövde
// ============================================================
$filesize = filesize($realPath);
if ($filesize === false) {
    image_error(500, 'Dosya okunamadı');
}

header('Content-Type: ' . $serveMime);
header('Content-Length: ' . $filesize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');

// Koşullu istek desteği (tarayıcı cache'i için)
$etag = '"' . md5($realPath . '|' . $filesize . '|' . filemtime($realPath)) . '"';
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($realPath)) . ' GMT');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// Çıktı tamponunu kapat (büyük dosyalar için)
while (ob_get_level() > 0) { ob_end_clean(); }

readfile($realPath);
exit;