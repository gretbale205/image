<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

$jobId = (int)($_GET['job_id'] ?? 0);
$role  = $_GET['role'] ?? 'preview';

if (!$jobId) {
    http_response_code(400);
    exit('Job ID eksik');
}

// Enum'daki geçerli değerler
$allowedRoles = ['source', 'preview', 'master', 'web'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    exit('Geçersiz role');
}

$db = getDB();
$stmt = $db->prepare("SELECT path FROM job_files WHERE job_id = ? AND role = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$jobId, $role]);
$file = $stmt->fetch();

if (!$file || !file_exists($file['path'])) {
    http_response_code(404);
    exit('Görsel bulunamadı');
}

$ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));

// PHP 7.4 uyumlu: match() yerine switch
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        $mime = 'image/jpeg';
        break;
    case 'png':
        $mime = 'image/png';
        break;
    case 'webp':
        $mime = 'image/webp';
        break;
    case 'tif':
    case 'tiff':
        $mime = 'image/tiff';
        break;
    default:
        $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file['path']));
header('Cache-Control: no-cache, must-revalidate');
readfile($file['path']);