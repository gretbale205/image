<?php
/**
 * jobs-create.php
 * Focus Stacking - Yeni iş oluşturma endpoint'i
 *
 * POST parametreleri:
 *   - sku        : Ürün kodu (zorunlu)
 *   - user_ref   : Kullanıcı referansı (opsiyonel, varsayılan 'system')
 *   - images[]   : Odak fotoğrafları (en az 1 adet)
 *
 * Dönüş:
 *   - 201 { success: true, job_id: X, message: ... }
 *   - 4xx/5xx { success: false, error: "..." }
 */

// ============================================================
// HATA GÖRÜNÜRLÜĞÜ (Sorun çözülünce kaldırın veya kapatın)
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);   // JSON bozulmasın diye 0; log'a yazacağız
ini_set('log_errors', 1);

// JSON header'ı en baştan gönderelim
header('Content-Type: application/json; charset=utf-8');

// PHP çökerse bile JSON dönmesi için shutdown handler
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Daha önce çıktı verildiyse dokunma
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'FATAL: ' . $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line'],
        ]);
    }
});

// ============================================================
// BAĞIMLILIKLAR (try içinde çağırarak require hatalarını yakalıyoruz)
// ============================================================
try {
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/db.php';
    require_once __DIR__ . '/../app/redis.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Bağımlılık yüklenemedi: ' . $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit;
}

// ============================================================
// YARDIMCI: json yanıt (helpers.php'de yoksa yedek)
// ============================================================
if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

// ============================================================
// ANA İŞLEM
// ============================================================
try {

    // 1) Metot kontrolü
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'error' => 'Geçersiz istek metodu'], 405);
    }

    // 2) Parametreler
    $sku     = isset($_POST['sku']) ? trim($_POST['sku']) : null;
    $userRef = isset($_POST['user_ref']) ? trim($_POST['user_ref']) : 'system';
    $files   = $_FILES['images'] ?? null;

    // 3) Doğrulama
    if (!$sku || $sku === '') {
        json_response(['success' => false, 'error' => 'SKU zorunludur'], 400);
    }

    if (!$files || !isset($files['name']) || empty($files['name'][0])) {
        json_response(['success' => false, 'error' => 'En az 1 fotoğraf yüklenmelidir'], 400);
    }

    // 4) STORAGE_PATH sabiti kontrolü
    if (!defined('STORAGE_PATH')) {
        json_response([
            'success' => false,
            'error'   => 'STORAGE_PATH sabiti tanımlı değil (app/helpers.php veya config dosyasını kontrol edin)'
        ], 500);
    }

    // 5) Storage dizini yazılabilir mi?
    if (!is_dir(STORAGE_PATH)) {
        if (!@mkdir(STORAGE_PATH, 0755, true)) {
            json_response([
                'success' => false,
                'error'   => 'STORAGE_PATH dizini oluşturulamadı: ' . STORAGE_PATH
            ], 500);
        }
    }
    if (!is_writable(STORAGE_PATH)) {
        json_response([
            'success' => false,
            'error'   => 'STORAGE_PATH yazılabilir değil: ' . STORAGE_PATH
        ], 500);
    }

    // 6) DB bağlantısı
    $db = getDB();

    // 7) Transaction başlat
    $db->beginTransaction();

    // 8) Job kaydı
    $stmt = $db->prepare(
        "INSERT INTO jobs (user_ref, sku, file_count, status, stage)
         VALUES (?, ?, ?, 'queued', 'uploaded')"
    );
    $stmt->execute([$userRef, $sku, count($files['name'])]);
    $jobId = (int) $db->lastInsertId();

    if ($jobId <= 0) {
        throw new Exception('Job ID alınamadı (lastInsertId = 0)');
    }

    // 9) Kaynak klasörü
    $sourceDir = rtrim(STORAGE_PATH, '/\\') . "/sources/{$jobId}";
    if (!is_dir($sourceDir)) {
        if (!@mkdir($sourceDir, 0755, true)) {
            throw new Exception("Kaynak klasörü oluşturulamadı: {$sourceDir}");
        }
    }

    // 10) Dosyaları kaydet
    $fileStmt   = $db->prepare(
        "INSERT INTO job_files (job_id, role, path, size) VALUES (?, 'source', ?, ?)"
    );
    $savedCount = 0;
    $errors     = [];

    $allowedExt = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'];

    foreach ($files['tmp_name'] as $idx => $tmpName) {
        $origName = $files['name'][$idx]  ?? '';
        $errCode  = $files['error'][$idx] ?? UPLOAD_ERR_NO_FILE;

        if ($errCode !== UPLOAD_ERR_OK) {
            $errors[] = "Dosya #{$idx} ({$origName}) yüklenemedi. Kod: {$errCode}";
            continue;
        }

        if (!is_uploaded_file($tmpName)) {
            $errors[] = "Dosya #{$idx} ({$origName}) geçerli bir upload değil.";
            continue;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = "Dosya #{$idx} ({$origName}) desteklenmeyen uzantı: {$ext}";
            continue;
        }

        $filename   = sprintf('%03d.%s', $idx + 1, $ext);
        $targetPath = $sourceDir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            $errors[] = "Dosya #{$idx} taşınamadı: {$targetPath}";
            continue;
        }

        $fileStmt->execute([$jobId, $targetPath, (int) filesize($targetPath)]);
        $savedCount++;
    }

    // Hiç dosya kaydedilemediyse işi iptal et
    if ($savedCount === 0) {
        throw new Exception('Hiçbir dosya kaydedilemedi. Detay: ' . implode(' | ', $errors));
    }

    // file_count'u gerçek kaydedilen sayıyla güncelle
    $upd = $db->prepare("UPDATE jobs SET file_count = ? WHERE id = ?");
    $upd->execute([$savedCount, $jobId]);

    // 11) Audit log
    //    NOT: audit_log tablonuzda user_ref sütunu yoksa bu satırı silin.
    //    Sütun var mı kontrol edelim, yoksa user_ref'siz insert deneyelim.
    $hasUserRef = false;
    try {
        $colStmt = $db->query("SHOW COLUMNS FROM audit_log LIKE 'user_ref'");
        $hasUserRef = $colStmt && $colStmt->fetch() !== false;
    } catch (Throwable $t) {
        $hasUserRef = false;
    }

    if ($hasUserRef) {
        $logStmt = $db->prepare(
            "INSERT INTO audit_log (job_id, user_ref, action) VALUES (?, ?, 'job_created')"
        );
        $logStmt->execute([$jobId, $userRef]);
    } else {
        $logStmt = $db->prepare(
            "INSERT INTO audit_log (job_id, action) VALUES (?, 'job_created')"
        );
        $logStmt->execute([$jobId]);
    }

    // 12) Commit
    $db->commit();

    // 13) Redis kuyruğuna at (opsiyonel)
    $queued = false;
    try {
        if (function_exists('getRedis')) {
            $redis = getRedis();
            $redis->rPush('focus_stack_queue', (string) $jobId);
            $queued = true;
        }
    } catch (Throwable $e) {
        // Redis yoksa sorun değil, log'a yaz
        error_log('[jobs-create] Redis push hatası: ' . $e->getMessage());
    }

    // 14) Başarılı yanıt
    json_response([
        'success'    => true,
        'job_id'     => $jobId,
        'file_count' => $savedCount,
        'queued'     => $queued,
        'warnings'   => $errors,      // atlanan dosyalar varsa
        'message'    => 'İş başarıyla kuyruğa alındı'
    ], 201);

} catch (Throwable $e) {

    // Transaction açıksa geri al
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        try { $db->rollBack(); } catch (Throwable $t) {}
    }

    // Yüklenen dosyaları temizle (yarım kalan)
    if (isset($jobId) && isset($sourceDir) && is_dir($sourceDir)) {
        foreach (glob($sourceDir . '/*') as $f) { @unlink($f); }
        @rmdir($sourceDir);
    }

    // Hata logla
    error_log('[jobs-create] HATA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    // JSON dön
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
    exit;
}