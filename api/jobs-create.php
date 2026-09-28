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
 *   - 201 { success: true, job_id: X, file_count: N, count: N, queued: bool, message: ... }
 *   - 400 { success: false, error: "..." }   -> doğrulama hatası
 *   - 405 { success: false, error: "..." }   -> yanlış metot
 *   - 413 { success: false, error: "..." }   -> dosya/boyut limiti
 *   - 415 { success: false, error: "..." }   -> desteklenmeyen tür
 *   - 500 { success: false, error: "..." }   -> sunucu hatası
 */

// ============================================================
// HATA GÖRÜNÜRLÜĞÜ
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// PHP çökerse JSON dönmesi için shutdown handler
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        // Detayları log'a yaz, istemciye jenerik mesaj dön
        error_log('[jobs-create] FATAL: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'error'   => 'Sunucu hatası. Lütfen tekrar deneyin.',
        ]);
    }
});

// ============================================================
// LİMİTLER (tek yerde)
// ============================================================
define('MAX_FILE_BYTES',      25 * 1024 * 1024);    // 25 MB / dosya
define('MAX_TOTAL_BYTES',    400 * 1024 * 1024);    // 400 MB / iş
define('MAX_FILES',           50);                  // en fazla dosya sayısı
define('MAX_SKU_LEN',         64);
define('MAX_USER_REF_LEN',    64);

define('ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp']);

// finfo'nun farklı sistemlerde döndürebileceği tüm varyantlar
define('ALLOWED_MIME', [
    'image/jpeg',
    'image/png',
    'image/tiff',
    'image/webp',
    'image/bmp',
    'image/x-ms-bmp',   // bazı sistemler BMP için bunu döner
    'image/x-portable-anymap', // nadir, güvenlik için izinli değilse kaldır
]);

// ============================================================
// BAĞIMLILIKLAR
// ============================================================
try {
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/db.php';
    require_once __DIR__ . '/../app/redis.php';
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[jobs-create] Bağımlılık: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Sunucu yapılandırma hatası.',
    ]);
    exit;
}

// ============================================================
// YARDIMCI: json yanıt
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
// YARDIMCI: $_FILES['images'] tek dosya bile olsa array'a normalize et
// ============================================================
function normalize_files_array(?array $files): ?array {
    if (!$files || !isset($files['name'])) return null;
    if (is_array($files['name'])) return $files;
    // Tek dosya gönderilmişse sarmala
    return [
        'name'     => [$files['name']],
        'type'     => [$files['type']     ?? ''],
        'tmp_name' => [$files['tmp_name'] ?? ''],
        'error'    => [$files['error']    ?? UPLOAD_ERR_NO_FILE],
        'size'     => [$files['size']     ?? 0],
    ];
}

// ============================================================
// YARDIMCI: tek dosya doğrulaması
// Dönüş: null (ok) veya hata mesajı
// ============================================================
function validate_uploaded_image(string $tmpName, int $size, string $origName): ?string {
    // 1) Boyut limiti
    if ($size <= 0) {
        return "Dosya boş: {$origName}";
    }
    if ($size > MAX_FILE_BYTES) {
        $mb = round(MAX_FILE_BYTES / 1048576, 1);
        return "Dosya çok büyük (limit {$mb} MB): {$origName}";
    }

    // 2) Gerçek upload mı?
    if (!is_uploaded_file($tmpName)) {
        return "Geçerli bir yükleme değil: {$origName}";
    }

    // 3) Uzantı kontrolü
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        return "Desteklenmeyen uzantı ({$ext}): {$origName}";
    }

    // 4) MIME kontrolü (içerikten)
    if (!function_exists('finfo_open')) {
        return "Sunucu MIME kontrolünü desteklemiyor (finfo eksik).";
    }
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi === false) {
        return "MIME denetleyici başlatılamadı.";
    }
    $mime = finfo_file($fi, $tmpName);
    finfo_close($fi);

    if ($mime === false || !in_array($mime, ALLOWED_MIME, true)) {
        return "Desteklenmeyen içerik türü (" . ($mime ?: 'bilinmiyor') . "): {$origName}";
    }

    // 5) Görüntü header doğrulaması
    $info = @getimagesize($tmpName);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return "Dosya geçerli bir görüntü değil: {$origName}";
    }

    return null;
}

// ============================================================
// ANA İŞLEM
// ============================================================
try {

    // 1) Metot
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['success' => false, 'error' => 'Geçersiz istek metodu'], 405);
    }

    // 2) Content-Length kaba kontrolü (upload başlamadan reddet)
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $contentLength > MAX_TOTAL_BYTES) {
        json_response([
            'success' => false,
            'error'   => 'Toplam yükleme boyutu limiti aşıldı.',
        ], 413);
    }

    // 3) Parametreler
    $sku     = isset($_POST['sku']) ? trim((string) $_POST['sku']) : '';
    $userRef = isset($_POST['user_ref']) ? trim((string) $_POST['user_ref']) : 'system';
    if ($userRef === '') $userRef = 'system';

    // 4) SKU / user_ref doğrulama + sanitize
    if ($sku === '') {
        json_response(['success' => false, 'error' => 'SKU zorunludur'], 400);
    }
    if (mb_strlen($sku) > MAX_SKU_LEN) {
        json_response(['success' => false, 'error' => 'SKU çok uzun (max ' . MAX_SKU_LEN . ')'], 400);
    }
    if (mb_strlen($userRef) > MAX_USER_REF_LEN) {
        json_response(['success' => false, 'error' => 'user_ref çok uzun (max ' . MAX_USER_REF_LEN . ')'], 400);
    }
    // Sadece güvenli karakterler
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $sku)) {
        json_response(['success' => false, 'error' => 'SKU geçersiz karakter içeriyor'], 400);
    }
    if (!preg_match('/^[A-Za-z0-9_.\-@]+$/', $userRef)) {
        json_response(['success' => false, 'error' => 'user_ref geçersiz karakter içeriyor'], 400);
    }

    // 5) Dosyalar
    $files = normalize_files_array($_FILES['images'] ?? null);
    if (!$files) {
        json_response(['success' => false, 'error' => 'En az 1 fotoğraf yüklenmelidir'], 400);
    }

    $fileCount = count($files['name']);
    if ($fileCount === 0) {
        json_response(['success' => false, 'error' => 'En az 1 fotoğraf yüklenmelidir'], 400);
    }
    if ($fileCount > MAX_FILES) {
        json_response([
            'success' => false,
            'error'   => 'En fazla ' . MAX_FILES . ' dosya yüklenebilir (gönderilen: ' . $fileCount . ')',
        ], 413);
    }

    // 6) STORAGE_PATH
    if (!defined('STORAGE_PATH')) {
        error_log('[jobs-create] STORAGE_PATH tanımlı değil');
        json_response(['success' => false, 'error' => 'Sunucu yapılandırma hatası.'], 500);
    }
    if (!is_dir(STORAGE_PATH)) {
        if (!@mkdir(STORAGE_PATH, 0755, true)) {
            error_log('[jobs-create] mkdir failed: ' . STORAGE_PATH);
            json_response(['success' => false, 'error' => 'Depolama dizini oluşturulamadı.'], 500);
        }
    }
    if (!is_writable(STORAGE_PATH)) {
        error_log('[jobs-create] not writable: ' . STORAGE_PATH);
        json_response(['success' => false, 'error' => 'Depolama dizini yazılabilir değil.'], 500);
    }

    // 7) DB
    $db = getDB();

    // 8) Transaction
    $db->beginTransaction();

    // 9) Job kaydı
    $stmt = $db->prepare(
        "INSERT INTO jobs (user_ref, sku, file_count, status, stage)
         VALUES (?, ?, ?, 'queued', 'uploaded')"
    );
    $stmt->execute([$userRef, $sku, $fileCount]);
    $jobId = (int) $db->lastInsertId();
    if ($jobId <= 0) {
        throw new RuntimeException('Job ID alınamadı');
    }

    // 10) Kaynak klasörü
    $sourceDir = rtrim(STORAGE_PATH, '/\\') . "/sources/{$jobId}";
    if (!is_dir($sourceDir) && !@mkdir($sourceDir, 0755, true)) {
        throw new RuntimeException("Kaynak klasörü oluşturulamadı");
    }

    // 11) Dosyaları doğrula + kaydet
    $fileStmt   = $db->prepare(
        "INSERT INTO job_files (job_id, role, path, size) VALUES (?, 'source', ?, ?)"
    );
    $savedCount = 0;
    $warnings   = [];
    $totalBytes = 0;

    foreach ($files['tmp_name'] as $idx => $tmpName) {
        $origName = (string) ($files['name'][$idx]     ?? '');
        $errCode  = (int)    ($files['error'][$idx]    ?? UPLOAD_ERR_NO_FILE);
        $size     = (int)    ($files['size'][$idx]     ?? 0);

        // Boş slot atla
        if ($origName === '' && $errCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errCode !== UPLOAD_ERR_OK) {
            $warnings[] = "Dosya #{$idx} ({$origName}) yüklenemedi. Kod: {$errCode}";
            continue;
        }

        // Toplam boyut kontrolü
        $totalBytes += $size;
        if ($totalBytes > MAX_TOTAL_BYTES) {
            throw new RuntimeException('Toplam yükleme boyutu limiti aşıldı.');
        }

        // İçerik doğrulaması (MIME + header + boyut + uzantı)
        $err = validate_uploaded_image($tmpName, $size, $origName);
        if ($err !== null) {
            $warnings[] = $err;
            continue;
        }

        // Güvenli isim: kullanıcı adını ASLA path'te kullanma
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $filename = sprintf('%03d_%s.%s', $idx + 1, bin2hex(random_bytes(4)), $ext);
        $target   = $sourceDir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $target)) {
            $warnings[] = "Dosya #{$idx} taşınamadı";
            continue;
        }

        // İzinleri daralt
        @chmod($target, 0644);

        $fileStmt->execute([$jobId, $target, (int) filesize($target)]);
        $savedCount++;
    }

    if ($savedCount === 0) {
        throw new RuntimeException(
            'Hiçbir dosya kaydedilemedi. ' .
            (empty($warnings) ? '' : 'Detay: ' . implode(' | ', array_slice($warnings, 0, 3)))
        );
    }

    // Gerçek kaydedilen sayı
    $upd = $db->prepare("UPDATE jobs SET file_count = ? WHERE id = ?");
    $upd->execute([$savedCount, $jobId]);

    // 12) Audit log (şema toleranslı)
    try {
        $hasUserRef = false;
        $colStmt = $db->query("SHOW COLUMNS FROM audit_log LIKE 'user_ref'");
        if ($colStmt && $colStmt->fetch() !== false) {
            $hasUserRef = true;
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
    } catch (Throwable $t) {
        // Audit log hatası iş akışını bozmasın
        error_log('[jobs-create] audit_log: ' . $t->getMessage());
    }

    // 13) Commit
    $db->commit();

    // 14) Redis push (graceful)
    $queued = false;
    if (function_exists('getRedis')) {
        try {
            $redis = getRedis();
            if ($redis) {
                $redis->rPush('focus_stack_queue', (string) $jobId);
                $queued = true;
            }
        } catch (Throwable $e) {
            error_log('[jobs-create] Redis push: ' . $e->getMessage());
        }
    }

    // 15) Başarılı yanıt
    //     Hem file_count hem count dönüyoruz (frontend uyumsuzluğunu kapatmak için)
    json_response([
        'success'    => true,
        'job_id'     => $jobId,
        'file_count' => $savedCount,
        'count'      => $savedCount,   // <-- eski frontend uyumu için
        'queued'     => $queued,
        'warnings'   => $warnings,
        'message'    => 'İş başarıyla kuyruğa alındı',
    ], 201);

} catch (Throwable $e) {

    // Rollback
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        try { $db->rollBack(); } catch (Throwable $t) {}
    }

    // Yarım kalan kaynak klasörünü temizle
    if (isset($jobId) && isset($sourceDir) && is_dir($sourceDir)) {
        foreach (glob($sourceDir . '/*') as $f) { @unlink($f); }
        @rmdir($sourceDir);
    }

    // Logla (tam detay)
    error_log(sprintf(
        '[jobs-create] HATA: %s @ %s:%d',
        $e->getMessage(), $e->getFile(), $e->getLine()
    ));

    // İstemciye jenerik mesaj dön (file/line sızdırma)
    // Ama validation hataları mesaj olarak faydalı — onları ayırt edelim:
    $isValidation = $e instanceof RuntimeException
        && (str_contains($e->getMessage(), 'Dosya')
            || str_contains($e->getMessage(), 'limit')
            || str_contains($e->getMessage(), 'kaydedilemedi'));

    $status = $isValidation ? 400 : 500;
    $msg    = $isValidation ? $e->getMessage() : 'İş oluşturulamadı. Lütfen tekrar deneyin.';

    json_response([
        'success' => false,
        'error'   => $msg,
    ], $status);
}