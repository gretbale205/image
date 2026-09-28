<?php
/**
 * api.php — Tek dosyada tüm endpoint'ler
 * ?action=upload|status|preview|approve|reject|download
 */
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'upload':   actionUpload();   break;
    case 'status':   actionStatus();   break;
    case 'preview':  actionPreview();  break;
    case 'approve':  actionApprove();  break;
    case 'reject':   actionReject();   break;
    case 'download': actionDownload(); break;
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Bilinmeyen action']);
}


// ════════════════════════════════════════════════
// UPLOAD — dosyaları kaydet, iş oluştur, worker'ı arka planda tetikle
// ════════════════════════════════════════════════
function actionUpload() {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST gerekli']);
        return;
    }

    $sku = trim($_POST['sku'] ?? '');
    $files = $_FILES['images'] ?? null;

    if (!$sku) {
        echo json_encode(['success' => false, 'error' => 'SKU gerekli']);
        return;
    }
    if (!$files || empty($files['name'][0])) {
        echo json_encode(['success' => false, 'error' => 'En az 2 fotoğraf gerekli']);
        return;
    }
    if (!defined('STORAGE_PATH')) {
        echo json_encode(['success' => false, 'error' => 'STORAGE_PATH tanımsız']);
        return;
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        $count = count($files['name']);
        $stmt = $db->prepare("INSERT INTO jobs (user_ref, sku, file_count, status, stage) VALUES ('web', ?, ?, 'queued', 'uploaded')");
        $stmt->execute([$sku, $count]);
        $jobId = (int)$db->lastInsertId();

        $sourceDir = rtrim(STORAGE_PATH, '/\\') . "/sources/$jobId";
        if (!is_dir($sourceDir)) mkdir($sourceDir, 0755, true);

        $fileStmt = $db->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (?, 'source', ?, ?)");
        $saved = 0;
        $allowedExt = ['jpg','jpeg','png','tif','tiff','bmp','webp'];

        foreach ($files['tmp_name'] as $i => $tmp) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmp)) continue;

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) continue;

            $target = "$sourceDir/" . sprintf('%03d.%s', $i+1, $ext);
            if (move_uploaded_file($tmp, $target)) {
                $fileStmt->execute([$jobId, $target, filesize($target)]);
                $saved++;
            }
        }

        if ($saved < 2) throw new Exception("En az 2 dosya kaydedilmeli, kaydedilen: $saved");

        $db->prepare("UPDATE jobs SET file_count = ? WHERE id = ?")->execute([$saved, $jobId]);
        $db->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'uploaded')")->execute([$jobId]);

        $db->commit();

        // Worker'ı arka planda tetikle (kullanıcıyı bekletmez)
        triggerWorker();

        echo json_encode([
            'success' => true,
            'job_id'  => $jobId,
            'count'   => $saved,
        ]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}


// ════════════════════════════════════════════════
// Worker'ı arka planda çalıştır (fire & forget)
// ════════════════════════════════════════════════
function triggerWorker() {
    // 1. Proje dizinini api.php'nin bulunduğu klasörden dinamik olarak al
    $PROJECT = __DIR__;
    
    // 2. Python venv ve log yollarını belirle
    $PYTHON  = $PROJECT . '/worker/venv/bin/python';
    $LOG     = $PROJECT . '/worker_debug.log'; // Kolay erişim için log projenin köküne yazılır

    // Python sanal ortamı (venv) var mı kontrol et
    if (!file_exists($PYTHON)) {
        error_log("Worker çalıştırılamadı: Python venv bulunamadı ($PYTHON)");
        file_put_contents($LOG, date('[Y-m-d H:i:s] ') . "HATA: Python venv bulunamadı: $PYTHON\n", FILE_APPEND);
        return;
    }

    // 3. HOME dizinini dinamik tespit et
    $homeDir = getenv('HOME') ?: '/home/' . explode('/', trim($PROJECT, '/'))[1];

    // 4. Komutu oluştur (PYTHONPATH eklenerek alt dizin ve modül çakışmaları önlendi)
    $cmd = sprintf(
        'cd %s && HOME=%s PYTHONPATH=%s PATH=/usr/local/bin:/usr/bin:/bin nohup %s -m worker.worker --once >> %s 2>&1 &',
        escapeshellarg($PROJECT),
        escapeshellarg($homeDir),
        escapeshellarg($PROJECT),
        escapeshellarg($PYTHON),
        escapeshellarg($LOG)
    );

    // Arka planda çalıştır
    exec($cmd);
}


// ════════════════════════════════════════════════
// STATUS — iş durumunu döndür
// ════════════════════════════════════════════════
function actionStatus() {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/app/db.php';

    $jobId = (int)($_GET['job_id'] ?? 0);
    if (!$jobId) {
        echo json_encode(['error' => 'job_id gerekli']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, status, stage, progress, error_msg FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo json_encode(['error' => 'İş bulunamadı']);
        return;
    }

    // Preview var mı?
    $f = $db->prepare("SELECT id FROM job_files WHERE job_id = ? AND role = 'preview' LIMIT 1");
    $f->execute([$jobId]);
    $hasPreview = (bool)$f->fetch();

    $job['progress']    = (int)$job['progress'];
    $job['preview_url'] = $hasPreview
        ? "api.php?action=preview&job_id=$jobId&role=preview&v=" . time()
        : null;

    echo json_encode($job);
}


// ════════════════════════════════════════════════
// PREVIEW / DOWNLOAD — resmi gönder
// ════════════════════════════════════════════════
function actionPreview() {
    serveImage(false);
}
function actionDownload() {
    serveImage(true);
}

function serveImage($forceDownload = false) {
    require_once __DIR__ . '/app/db.php';

    $jobId = (int)($_GET['job_id'] ?? 0);
    $role  = $_GET['role'] ?? ($forceDownload ? 'master' : 'preview');

    $allowed = ['source', 'preview', 'master', 'web'];
    if (!$jobId || !in_array($role, $allowed, true)) {
        http_response_code(400);
        exit('Geçersiz istek');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT path FROM job_files WHERE job_id = ? AND role = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$jobId, $role]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !file_exists($row['path'])) {
        http_response_code(404);
        exit('Dosya bulunamadı');
    }

    $path = $row['path'];
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',  'webp' => 'image/webp',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'bmp' => 'image/bmp',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));

    if ($forceDownload) {
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    } else {
        header('Cache-Control: no-cache, must-revalidate');
    }
    readfile($path);
}


// ════════════════════════════════════════════════
// APPROVE — master'ı sakla, gerisini sil
// ════════════════════════════════════════════════
function actionApprove() {
    header('Content-Type: application/json; charset=utf-8');

    $jobId = (int)($_POST['job_id'] ?? 0);
    if (!$jobId) {
        echo json_encode(['success' => false, 'error' => 'job_id gerekli']);
        return;
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        // 1) Status güncelle
        $db->prepare("UPDATE jobs SET status='approved', approved_at=NOW() WHERE id=?")
           ->execute([$jobId]);

        // 2) Master dosyasının yolunu bul
        $s = $db->prepare("SELECT path FROM job_files WHERE job_id=? AND role='master' ORDER BY id DESC LIMIT 1");
        $s->execute([$jobId]);
        $master = $s->fetch(PDO::FETCH_ASSOC);

        if (!$master || !file_exists($master['path'])) {
            throw new Exception('Master görseli bulunamadı');
        }

        // 3) sources ve previews klasörlerini sil
        foreach (['sources', 'previews'] as $sub) {
            $dir = STORAGE_PATH . "/$sub/$jobId";
            if (is_dir($dir)) rrmdir($dir);
        }

        // 4) DB'den source ve preview kayıtlarını sil
        $db->prepare("DELETE FROM job_files WHERE job_id=? AND role IN ('source','preview')")
           ->execute([$jobId]);

        // 5) Audit
        $db->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'approved')")->execute([$jobId]);

        $db->commit();

        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}


// ════════════════════════════════════════════════
// REJECT — her şeyi sil
// ════════════════════════════════════════════════
function actionReject() {
    header('Content-Type: application/json; charset=utf-8');

    $jobId = (int)($_POST['job_id'] ?? 0);
    if (!$jobId) {
        echo json_encode(['success' => false, 'error' => 'job_id gerekli']);
        return;
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        // 1) Dosya sisteminden tüm klasörleri sil
        foreach (['sources', 'outputs', 'previews'] as $sub) {
            $dir = STORAGE_PATH . "/$sub/$jobId";
            if (is_dir($dir)) rrmdir($dir);
        }

        // 2) DB'den job_files sil (CASCADE olsa da garanti)
        $db->prepare("DELETE FROM job_files WHERE job_id=?")->execute([$jobId]);

        // 3) Job kaydını sil
        $db->prepare("DELETE FROM jobs WHERE id=?")->execute([$jobId]);

        $db->commit();

        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}


// ════════════════════════════════════════════════
// Yardımcı: klasörü içeriğiyle sil
// ════════════════════════════════════════════════
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}