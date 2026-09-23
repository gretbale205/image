<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

$jobId = $_GET['job_id'] ?? null;
if (!$jobId) {
    json_response(['error' => 'Job ID eksik'], 400);
}

$db = getDB();
$stmt = $db->prepare("SELECT id, sku, status, stage, progress, error_msg FROM jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    json_response(['error' => 'İş bulunamadı'], 404);
}

// Önizleme görseli var mı kontrol et
$fileStmt = $db->prepare("SELECT path FROM job_files WHERE job_id = ? AND role = 'preview' LIMIT 1");
$fileStmt->execute([$jobId]);
$previewFile = $fileStmt->fetch();

$job['preview_url'] = $previewFile ? '/api/get-image.php?job_id='.$jobId.'&role=preview' : null;

json_response($job);