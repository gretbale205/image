<?php
/**
 * jobs-approve.php
 * Focus Stacking - İşi onayla ve kaynak fotoğrafları geri dönüşüme taşı.
 *
 * POST: job_id (int, zorunlu)
 *
 * Yanıt:
 *   - 200 { success:true, message:"...", job_id:X }
 *   - 400 { success:false, error:"..." }
 *   - 404 { success:false, error:"..." }
 *   - 409 { success:false, error:"..." }  -> geçersiz durum geçişi
 *   - 500 { success:false, error:"..." }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        error_log('[jobs-approve] FATAL: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode(['success' => false, 'error' => 'Sunucu hatası.']);
    }
});

try {
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[jobs-approve] Bağımlılık: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Sunucu yapılandırma hatası.']);
    exit;
}

if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

// ============================================================
// 1) Metot + parametre
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'error' => 'Geçersiz istek metodu'], 405);
}

$jobIdRaw = $_POST['job_id'] ?? null;
if (!is_scalar($jobIdRaw)) {
    json_response(['success' => false, 'error' => 'Geçersiz job_id'], 400);
}
$jobId = (int) $jobIdRaw;
if ($jobId <= 0) {
    json_response(['success' => false, 'error' => 'Geçersiz job_id'], 400);
}

$userRef = isset($_POST['user_ref']) && is_scalar($_POST['user_ref'])
    ? substr(trim((string) $_POST['user_ref']), 0, 64)
    : 'system';
if ($userRef === '') $userRef = 'system';

// ============================================================
// 2) STORAGE_PATH
// ============================================================
if (!defined('STORAGE_PATH')) {
    error_log('[jobs-approve] STORAGE_PATH tanımsız');
    json_response(['success' => false, 'error' => 'Sunucu yapılandırma hatası.'], 500);
}

$storageRoot = realpath(STORAGE_PATH);
if ($storageRoot === false) {
    error_log('[jobs-approve] STORAGE_PATH realpath: ' . STORAGE_PATH);
    json_response(['success' => false, 'error' => 'Sunucu yapılandırma hatası.'], 500);
}
$storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);

// ============================================================
// 3) DB işlemleri
// ============================================================
$db = getDB();

$sourceDir  = null;
$recycleDir = null;

try {
    $db->beginTransaction();

    // 3a) Kilitle ve mevcut durumu oku (race önler)
    $sel = $db->prepare("SELECT id, status FROM jobs WHERE id = ? FOR UPDATE");
    $sel->execute([$jobId]);
    $job = $sel->fetch();

    if (!$job) {
        $db->rollBack();
        json_response(['success' => false, 'error' => 'İş bulunamadı'], 404);
    }

    // 3b) Durum geçişi doğrulaması
    //     Sadece preview_ready veya done durumundan approve edilebilir.
    //     Kendi şemana göre listeyi genişlet/daralt:
    $approvableStates = ['preview_ready', 'done'];
    if (!in_array($job['status'], $approvableStates, true)) {
        $db->rollBack();
        error_log(sprintf(
            '[jobs-approve] Geçersiz durum geçişi: job=%d status=%s',
            $jobId, $job['status']
        ));
        json_response([
            'success' => false,
            'error'   => "Bu iş onaylanamaz. Mevcut durum: {$job['status']}",
        ], 409);
    }

    // 3c) Kaynak klasörü mevcut mu?
    $sourceDir  = $storageRoot . '/sources/' . $jobId;
    $recycleDir = $storageRoot . '/recycle/' . $jobId;

    $sourceExists = is_dir($sourceDir);

    // 3d) Kaynak varsa recycle'e taşı
    if ($sourceExists) {
        $recycleParent = dirname($recycleDir);
        if (!is_dir($recycleParent)) {
            if (!@mkdir($recycleParent, 0755, true) && !is_dir($recycleParent)) {
                throw new RuntimeException('recycle dizini oluşturulamadı');
            }
        }

        // Recycle hedefi zaten varsa (yeniden approve) unique suffix ekle
        if (file_exists($recycleDir)) {
            $recycleDir .= '_' . date('Ymd_His');
        }

        if (!@rename($sourceDir, $recycleDir)) {
            throw new RuntimeException('Kaynak klasörü geri dönüşüme taşınamadı');
        }
    }

    // 3e) DB güncelle
    $upd = $db->prepare(
        "UPDATE jobs SET status = 'approved', approved_at = NOW() WHERE id = ?"
    );
    $upd->execute([$jobId]);

    // 3f) Audit log (user_ref varsa ekle)
    try {
        $hasUserRef = false;
        $colStmt = $db->query("SHOW COLUMNS FROM audit_log LIKE 'user_ref'");
        if ($colStmt && $colStmt->fetch() !== false) {
            $hasUserRef = true;
        }
        if ($hasUserRef) {
            $logStmt = $db->prepare(
                "INSERT INTO audit_log (job_id, user_ref, action)
                 VALUES (?, ?, 'approved_and_recycled')"
            );
            $logStmt->execute([$jobId, $userRef]);
        } else {
            $logStmt = $db->prepare(
                "INSERT INTO audit_log (job_id, action)
                 VALUES (?, 'approved_and_recycled')"
            );
            $logStmt->execute([$jobId]);
        }
    } catch (Throwable $t) {
        error_log('[jobs-approve] audit_log: ' . $t->getMessage());
    }

    $db->commit();

    json_response([
        'success'      => true,
        'job_id'       => $jobId,
        'recycled'     => $sourceExists,
        'message'      => 'Onaylandı, kaynak fotoğraflar geri dönüşüme taşındı.',
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        try { $db->rollBack(); } catch (Throwable $t) {}
    }

    error_log(sprintf(
        '[jobs-approve] HATA: %s @ %s:%d',
        $e->getMessage(), $e->getFile(), $e->getLine()
    ));

    // RuntimeException → validation hatası (kullanıcıya mesaj gösterilebilir)
    $isValidation = $e instanceof RuntimeException;
    $status = $isValidation ? 400 : 500;
    $msg    = $isValidation ? $e->getMessage() : 'İş onaylanamadı. Lütfen tekrar deneyin.';

    json_response(['success' => false, 'error' => $msg], $status);
}