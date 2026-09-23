<?php
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

$jobId = $_POST['job_id'] ?? null;
if (!$jobId) {
    json_response(['error' => 'Job ID eksik'], 400);
}

$db = getDB();

try {
    $db->beginTransaction();

    // 1. İş Durumunu Onaylandı Yap
    $stmt = $db->prepare("UPDATE jobs SET status = 'approved', approved_at = NOW() WHERE id = ?");
    $stmt->execute([$jobId]);

    // 2. Kaynak Fotoğrafları Geri Dönüşüme Taşı
    $sourceDir = STORAGE_PATH . "/sources/{$jobId}";
    $recycleDir = STORAGE_PATH . "/recycle/{$jobId}";

    if (is_dir($sourceDir)) {
        if (!is_dir(dirname($recycleDir))) {
            mkdir(dirname($recycleDir), 0755, true);
        }
        rename($sourceDir, $recycleDir);
    }

    // 3. Log Kaydı
    $logStmt = $db->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'approved_and_recycled')");
    $logStmt->execute([$jobId]);

    $db->commit();
    json_response(['success' => true, 'message' => 'Onaylandı, kaynak fotoğraflar geri dönüşüme taşındı.']);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    json_response(['error' => $e->getMessage()], 500);
}