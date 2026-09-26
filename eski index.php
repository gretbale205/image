<?php
// ============================================================
// FocusStack — v3.1
// - Desktop: 2 sütun (kaydırmasız)
// - Mobile: tek sütun
// - Method / Bitdepth seçimi
// - Lightbox + mercek + kamera
// - ⭐ v3.1: 502 fix — worker asenkron çalışır
// ============================================================
@set_time_limit(600);
@ini_set('memory_limit', '2G');
@ini_set('max_input_time', '600');

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------- .ENV ----------
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
load_env(__DIR__ . '/.env');
function env(string $k, string $d=''): string { $v = getenv($k); return ($v===false||$v==='') ? $d : $v; }
function env_int(string $k, int $d): int { $v = getenv($k); return ($v===false||$v==='') ? $d : (int)$v; }

// ---------- AYARLAR ----------
define('DB_HOST', env('DB_HOST','localhost'));
define('DB_PORT', env_int('DB_PORT',3306));
define('DB_NAME', env('DB_NAME','focusstack'));
define('DB_USER', env('DB_USER',''));
define('DB_PASS', env('DB_PASS',''));
define('STORAGE_ROOT', env('STORAGE_ROOT', __DIR__ . '/storage'));
define('PYTHON_BIN',   __DIR__ . '/worker/venv/bin/python');
define('LOG_FILE',     STORAGE_ROOT . '/logs/index_worker.log');

const MAX_FILE_MB  = 25;
const MAX_TOTAL_MB = 400;
const MAX_FILES    = 50;
const ALLOWED_EXT  = ['jpg','jpeg','png','tif','tiff','bmp','webp'];
const ALLOWED_METHODS = ['pyramid','softmax','dmap'];
const ALLOWED_BITDEPTH = [8,16];

// ---------- YARDIMCI ----------
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($m, $t='info') { $_SESSION['flash'][] = ['m'=>$m,'t'=>$t]; }
function flashes() { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/**
 * ⭐ ASENKRON WORKER BAŞLATICI
 * 502 Bad Gateway sorununu çözer.
 * PHP beklemez, worker arka planda çalışır.
 * İlerleme jobs-status.php polling ile takip edilir.
 */
function run_worker(): string {
    if (!is_file(PYTHON_BIN) || !is_executable(PYTHON_BIN)) {
        return "HATA: python bulunamadı: " . PYTHON_BIN;
    }

    $logDir = STORAGE_ROOT . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    $logFile = $logDir . '/worker_' . date('Ymd_His') . '.log';

    // nohup + & → arka planda başlat, PHP anında döner
    $cmd = sprintf(
        'cd %s && HOME=%s PATH=/usr/local/bin:/usr/bin:/bin nohup %s -m worker.worker --once > %s 2>&1 &',
        escapeshellarg(__DIR__),
        escapeshellarg(getenv('HOME') ?: '/tmp'),
        escapeshellarg(PYTHON_BIN),
        escapeshellarg($logFile)
    );

    // exec ile çalıştır (shell_exec gibi beklemez)
    @exec($cmd);

    // Global LOG_FILE'a da yaz (log takibi için)
    $stamp = '[' . date('Y-m-d H:i:s') . "] Worker arka planda başlatıldı: " . basename($logFile) . "\n";
    @file_put_contents(LOG_FILE, $stamp, FILE_APPEND);

    return "✅ Worker arka planda başlatıldı.\nLog: " . basename($logFile) . "\n\nİlerlemeyi yukarıdaki iş durumu bölümünden takip edebilirsiniz.";
}

function badge($st) {
    $cls = ['queued'=>'b-queued','processing'=>'b-proc','preview_ready'=>'b-ok',
            'done'=>'b-ok','approved'=>'b-app','rejected'=>'b-rej','failed'=>'b-fail'];
    return $cls[$st] ?? 'b-queued';
}
function badge_label($st) {
    return [
        'queued'        => 'Kuyrukta',
        'processing'    => 'İşleniyor',
        'preview_ready' => 'Hazır',
        'done'          => 'Tamam',
        'approved'      => 'Onaylandı',
        'rejected'      => 'Reddedildi',
        'failed'        => 'Hata',
    ][$st] ?? $st;
}
function home_url(): string {
    $p = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if ($p === false || $p === '' || $p === '?') $p = './';
    return $p;
}
$HOME_URL = home_url();

// ---------- CSRF ----------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
function csrf_check(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

// ---------- POST ----------
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok'=>false,'error'=>'CSRF doğrulaması başarısız']);
            exit;
        }
        flash('CSRF doğrulaması başarısız', 'error');
        header('Location: ' . $HOME_URL); exit;
    }

    $action = $_POST['action'] ?? '';

    // ============ UPLOAD ============
    if ($action === 'upload') {
        $pdo = null;
        try {
            $sku = trim((string)($_POST['sku'] ?? ''));
            if ($sku === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $sku) || mb_strlen($sku) > 64)
                throw new Exception('SKU geçersiz');

            $method = (string)($_POST['method'] ?? 'softmax');
            if (!in_array($method, ALLOWED_METHODS, true)) $method = 'softmax';

            $bitdepth = (int)($_POST['bitdepth'] ?? 8);
            if (!in_array($bitdepth, ALLOWED_BITDEPTH, true)) $bitdepth = 8;

            if (empty($_FILES['images']['name'][0])) throw new Exception('Dosya seçilmedi');
            if (count($_FILES['images']['name']) > MAX_FILES) throw new Exception('Max '.MAX_FILES.' dosya');

            $valid = []; $total = 0;
            foreach ($_FILES['images']['name'] as $i => $name) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $size = (int)$_FILES['images']['size'][$i];
                $tmp  = $_FILES['images']['tmp_name'][$i];
                if ($size <= 0 || $size > MAX_FILE_MB*1024*1024) continue;
                $total += $size;
                if ($total > MAX_TOTAL_MB*1024*1024) throw new Exception('Toplam boyut aşımı');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ALLOWED_EXT, true)) continue;
                $fi = finfo_open(FILEINFO_MIME_TYPE); $m = finfo_file($fi, $tmp); finfo_close($fi);
                if (strpos((string)$m, 'image/') !== 0) continue;
                if (@getimagesize($tmp) === false) continue;
                $valid[] = ['tmp'=>$tmp, 'ext'=>$ext];
            }
            if (!$valid) throw new Exception('Geçerli dosya yok');

            $pdo = db();
            $pdo->beginTransaction();

            try {
                $pdo->prepare("INSERT INTO jobs (user_ref, sku, file_count, status, stage, method, output_bitdepth)
                               VALUES ('web', ?, ?, 'queued', 'uploaded', ?, ?)")
                    ->execute([$sku, count($valid), $method, $bitdepth]);
            } catch (Throwable $t) {
                $pdo->prepare("INSERT INTO jobs (user_ref, sku, file_count, status, stage)
                               VALUES ('web', ?, ?, 'queued', 'uploaded')")
                    ->execute([$sku, count($valid)]);
            }
            $jid = (int)$pdo->lastInsertId();

            $dir = STORAGE_ROOT . '/sources/' . $jid;
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $ins = $pdo->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (?, 'source', ?, ?)");
            $n = 0;
            foreach ($valid as $k => $v) {
                $t = $dir . '/' . sprintf('%03d_%s.%s', $k+1, bin2hex(random_bytes(3)), $v['ext']);
                if (!move_uploaded_file($v['tmp'], $t)) continue;
                @chmod($t, 0644);
                $ins->execute([$jid, $t, filesize($t)]);
                $n++;
            }
            if ($n === 0) throw new Exception('Dosya yazılamadı');

            $pdo->prepare("UPDATE jobs SET file_count=? WHERE id=?")->execute([$n, $jid]);
            try {
                $has = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'user_ref'")->fetch();
                if ($has) $pdo->prepare("INSERT INTO audit_log (job_id, user_ref, action) VALUES (?, 'web', 'job_created')")->execute([$jid]);
                else       $pdo->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'job_created')")->execute([$jid]);
            } catch (Throwable $t) {}
            $pdo->commit();

            @run_worker();

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true, 'job_id'=>$jid, 'files'=>$n]);
                exit;
            }
            flash("İş #$jid oluşturuldu ($n dosya).", 'success');
            header('Location: ?job=' . $jid); exit;

        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
                exit;
            }
            flash('Hata: '.$e->getMessage(), 'error');
            header('Location: ' . $HOME_URL); exit;
        }
    }

    if ($action === 'run_worker') {
        $out = run_worker();
        $_SESSION['worker_output'] = $out;
        flash('Worker arka planda başlatıldı', 'info');
        $back = $_POST['back'] ?? $HOME_URL;
        header('Location: ' . $back); exit;
    }

    if ($action === 'approve') {
        $jid = (int)($_POST['job_id'] ?? 0);
        try {
            db()->prepare("UPDATE jobs SET status='approved', approved_at=NOW() WHERE id=?")->execute([$jid]);
            try { db()->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'approved')")->execute([$jid]); } catch (Throwable $t) {}
            flash("İş #$jid onaylandı", 'success');
        } catch (Throwable $e) { flash('Hata: '.$e->getMessage(), 'error'); }
        header('Location: ?job=' . $jid); exit;
    }

    if ($action === 'reject') {
        $jid = (int)($_POST['job_id'] ?? 0);
        try {
            try {
                db()->prepare("UPDATE jobs SET status='rejected' WHERE id=?")->execute([$jid]);
            } catch (Throwable $t) {
                db()->prepare("UPDATE jobs SET status='failed' WHERE id=?")->execute([$jid]);
            }
            try { db()->prepare("INSERT INTO audit_log (job_id, action) VALUES (?, 'rejected')")->execute([$jid]); } catch (Throwable $t) {}
            flash("İş #$jid reddedildi", 'success');
        } catch (Throwable $e) { flash('Hata: '.$e->getMessage(), 'error'); }
        header('Location: ' . $HOME_URL); exit;
    }

    if ($action === 'retry') {
        $jid = (int)($_POST['job_id'] ?? 0);
        try {
            db()->prepare("UPDATE jobs SET status='queued', stage='uploaded', progress=0, error_msg=NULL WHERE id=?")->execute([$jid]);
            flash("İş #$jid yeniden kuyruğa alındı", 'success');
        } catch (Throwable $e) { flash('Hata: '.$e->getMessage(), 'error'); }
        header('Location: ?job=' . $jid); exit;
    }
}

// ---------- VERİ ----------
$flashes = flashes();
$workerOutput = $_SESSION['worker_output'] ?? null;
unset($_SESSION['worker_output']);

$selectedId = isset($_GET['job']) ? (int)$_GET['job'] : 0;

$jobs = []; $dbError = null;
try {
    try {
        $jobs = db()->query("SELECT *, method, output_bitdepth FROM jobs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {
        $jobs = db()->query("SELECT * FROM jobs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as &$jj) { $jj['method'] = null; $jj['output_bitdepth'] = null; }
        unset($jj);
    }
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$J = null; $files = []; $logs = []; $previewUrl = $masterUrl = null;
if ($selectedId) {
    try {
        try {
            $st = db()->prepare("SELECT *, method, output_bitdepth FROM jobs WHERE id=?"); $st->execute([$selectedId]);
        } catch (Throwable $t) {
            $st = db()->prepare("SELECT * FROM jobs WHERE id=?"); $st->execute([$selectedId]);
        }
        $J = $st->fetch(PDO::FETCH_ASSOC);
        if ($J && !array_key_exists('method', $J)) { $J['method'] = null; $J['output_bitdepth'] = null; }
        if ($J) {
            $st = db()->prepare("SELECT * FROM job_files WHERE job_id=? ORDER BY id"); $st->execute([$selectedId]);
            $files = $st->fetchAll(PDO::FETCH_ASSOC);
            try {
                $st = db()->prepare("SELECT * FROM audit_log WHERE job_id=? ORDER BY id"); $st->execute([$selectedId]);
                $logs = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $t) {}
            foreach ($files as $f) {
                if ($f['role'] === 'preview') $previewUrl = 'api/get-image.php?job_id='.$selectedId.'&role=preview&t='.time();
                if ($f['role'] === 'master')  $masterUrl  = 'api/get-image.php?job_id='.$selectedId.'&role=master&t='.time();
            }
        }
    } catch (Throwable $e) {}
}

$lastLog = '';
if (is_file(LOG_FILE)) $lastLog = (string)@file_get_contents(LOG_FILE);
if ($lastLog && strlen($lastLog) > 8000) $lastLog = substr($lastLog, -8000);

$autoRefresh = false;
if ($J && in_array($J['status'], ['queued','processing'], true)) $autoRefresh = true;

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#09090b">
<title>FocusStack<?= $J ? ' #'.(int)$J['id'] : '' ?></title>
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{
    background:#09090b;color:#f4f4f5;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    font-size:15px;line-height:1.5;
    overscroll-behavior-y:none;
}
a{color:#60a5fa;text-decoration:none}
a:hover{text-decoration:underline}

@media (min-width:1024px){
    body{height:100vh;overflow:hidden}
    .app-grid{
        display:grid;
        grid-template-columns:400px 1fr;
        height:100vh;
        grid-template-rows:auto 1fr;
    }
    .app-header{grid-column:1 / -1}
    .app-side{
        border-right:1px solid #1f1f23;
        overflow-y:auto;
        padding:20px;
        background:#0c0c0f;
    }
    .app-main{
        overflow-y:auto;
        padding:20px 28px;
        background:#09090b;
    }
    .app-side::-webkit-scrollbar,
    .app-main::-webkit-scrollbar{width:8px}
    .app-side::-webkit-scrollbar-thumb,
    .app-main::-webkit-scrollbar-thumb{background:#27272a;border-radius:4px}
}

@media (max-width:1023px){
    .app-grid{display:block}
    .app-side{padding:0}
    .app-main{padding:0 0 40px}
    .mobile-hide{display:none !important}
}

.app-header{
    position:sticky;top:0;z-index:50;
    background:rgba(9,9,11,.92);
    backdrop-filter:saturate(180%) blur(14px);
    -webkit-backdrop-filter:saturate(180%) blur(14px);
    border-bottom:1px solid #1f1f23;
    padding:12px 20px;
    padding-top:max(12px,env(safe-area-inset-top));
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;
}
.app-header .brand{font-weight:700;font-size:17px;letter-spacing:-.2px}
.app-header .brand span{color:#71717a;font-weight:400;font-size:12px;margin-left:6px}
.app-header .close{
    background:#18181b;border:1px solid #27272a;color:#e4e4e7;
    width:38px;height:38px;border-radius:50%;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:17px;cursor:pointer;text-decoration:none;flex-shrink:0;
}
.app-header .close:active{background:#27272a;transform:scale(.95)}

.container{max-width:900px;margin:0 auto;padding:16px}
@media(min-width:1024px){.container{padding:0}}

h2{
    font-size:11px;color:#a1a1aa;text-transform:uppercase;
    letter-spacing:.8px;margin:20px 0 10px;font-weight:600;
}
h2:first-child{margin-top:0}

.card{
    background:#131316;border:1px solid #1f1f23;
    border-radius:14px;padding:16px;margin-bottom:14px;
}
.card.tight{padding:10px}

label{display:block;font-size:12px;color:#a1a1aa;margin-bottom:6px;font-weight:500}
input[type=text],input[type=file],select{
    background:#09090b;border:1px solid #27272a;color:#f4f4f5;
    border-radius:10px;padding:12px 14px;font-size:15px;
    width:100%;outline:none;font-family:inherit;
}
input[type=text]:focus,select:focus{border-color:#3b82f6}
select{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path fill='%2371717a' d='M6 8L0 0h12z'/></svg>");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px}

.opts-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}
@media(max-width:520px){.opts-row{grid-template-columns:1fr}}

.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-height:46px;padding:11px 16px;
    border:0;border-radius:12px;font-size:14px;font-weight:600;
    cursor:pointer;font-family:inherit;
    transition:transform .05s ease,background .15s ease;
    text-decoration:none;
}
.btn:active{transform:scale(.98)}
.btn.primary{background:#3b82f6;color:#fff}
.btn.green{background:#16a34a;color:#fff}
.btn.red{background:#dc2626;color:#fff}
.btn.gray{background:#27272a;color:#e4e4e7}
.btn.full{width:100%}
.btn.lg{min-height:52px;font-size:15px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn.sm{min-height:36px;padding:8px 12px;font-size:12px}

.flash{
    padding:12px 14px;border-radius:12px;margin-bottom:12px;
    font-size:14px;border:1px solid transparent;
}
.flash.success{background:#052e16;color:#86efac;border-color:#14532d}
.flash.error{background:#450a0a;color:#fca5a5;border-color:#7f1d1d}
.flash.info{background:#082f49;color:#7dd3fc;border-color:#0c4a6e}

.upload-row{display:flex;gap:8px;flex-wrap:wrap}
.upload-row .btn{flex:1;min-width:130px}

.photo-grid{
    display:grid;grid-template-columns:repeat(4,1fr);
    gap:6px;margin:12px 0;
}
@media(min-width:520px){.photo-grid{grid-template-columns:repeat(6,1fr)}}
@media(min-width:1024px){.photo-grid{grid-template-columns:repeat(5,1fr)}}
.photo-tile{
    position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;
    background:#000;border:1px solid #27272a;
}
.photo-tile img{width:100%;height:100%;object-fit:cover;display:block}
.photo-tile .num{
    position:absolute;top:4px;left:4px;
    background:rgba(0,0,0,.72);color:#fff;
    font-size:10px;font-weight:700;padding:2px 5px;border-radius:5px;
}
.photo-tile .del{
    position:absolute;top:4px;right:4px;
    width:22px;height:22px;border-radius:50%;
    background:rgba(220,38,38,.92);color:#fff;border:0;
    font-size:13px;line-height:1;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
}
.photo-empty{
    text-align:center;color:#52525b;padding:20px 8px;
    border:1px dashed #27272a;border-radius:12px;margin:10px 0;
    font-size:12px;
}

.jobrow{
    display:flex;gap:12px;align-items:center;
    padding:11px;border:1px solid #1f1f23;border-radius:12px;
    margin-bottom:8px;background:#18181b;
    text-decoration:none;color:inherit;
    transition:border-color .15s;
}
.jobrow:active{background:#1f1f23}
.jobrow.active{border-color:#3b82f6;background:#0c1e3a}
.jobrow .thumb{
    width:56px;height:56px;border-radius:10px;overflow:hidden;
    background:#000;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    color:#3f3f46;font-size:10px;
}
.jobrow .thumb img{width:100%;height:100%;object-fit:cover}
.jobrow .meta{flex:1;min-width:0}
.jobrow .meta .title{
    font-weight:600;color:#fff;font-size:14px;
    display:flex;align-items:center;gap:8px;
    margin-bottom:2px;
}
.jobrow .meta .sub{
    font-size:11px;color:#a1a1aa;
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.jobrow .meta .date{font-size:10px;color:#52525b;margin-top:2px}
.jobrow .err{font-size:11px;color:#f87171;margin-top:3px}

.badge{
    display:inline-block;padding:3px 9px;border-radius:20px;
    font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
}
.b-queued{background:#78350f;color:#fbbf24}
.b-proc{background:#0c4a6e;color:#38bdf8}
.b-ok{background:#14532d;color:#4ade80}
.b-app{background:#1e3a8a;color:#93c5fd}
.b-rej{background:#4c1d24;color:#fda4af}
.b-fail{background:#7f1d1d;color:#fca5a5}

.detail-head{
    background:linear-gradient(180deg,#131316,#0c0c0f);
    border:1px solid #1f1f23;border-radius:14px;
    padding:16px;margin-bottom:14px;
}
.detail-head .sku{font-size:22px;font-weight:700;color:#fff;letter-spacing:-.4px;margin:4px 0 8px}
.detail-head .meta{
    display:flex;gap:8px;align-items:center;flex-wrap:wrap;
    font-size:12px;color:#a1a1aa;margin-top:8px;
}
.progress{height:6px;background:#18181b;border-radius:6px;overflow:hidden;margin-top:12px}
.progress > div{height:100%;background:#3b82f6;transition:width .4s ease}

.preview-box{
    background:#000;border:1px solid #1f1f23;border-radius:12px;
    overflow:hidden;display:flex;align-items:center;justify-content:center;
    min-height:180px;position:relative;
}
.preview-box img{
    max-width:100%;max-height:65vh;display:block;
    cursor:zoom-in;
}
.preview-box .zoom-badge{
    position:absolute;bottom:8px;right:8px;
    background:rgba(0,0,0,.7);color:#fff;font-size:11px;
    padding:4px 10px;border-radius:20px;
    backdrop-filter:blur(8px);
    pointer-events:none;
}

.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.actions .btn{flex:1;min-width:120px}

.filelist{font-size:12px;color:#a1a1aa}
.filelist div{padding:5px 0;border-bottom:1px solid #18181b}
.filelist div:last-child{border-bottom:0}

pre,textarea{
    width:100%;background:#000;border:1px solid #27272a;border-radius:10px;
    padding:12px;font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:11px;color:#a1a1aa;white-space:pre-wrap;word-break:break-all;
    max-height:260px;overflow:auto;margin:0;
}
textarea{resize:vertical;min-height:80px}

.pill{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 8px;border-radius:12px;
    background:#18181b;color:#a1a1aa;
    font-size:10px;font-weight:600;
    border:1px solid #27272a;
}

/* ==== KAMERA ==== */
.camera-overlay{
    position:fixed;inset:0;background:#000;z-index:9999;
    display:flex;flex-direction:column;
}
.camera-overlay[hidden]{display:none}
.camera-view{position:relative;flex:1;overflow:hidden;background:#000}
.camera-view video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.cam-top{
    position:absolute;top:0;left:0;right:0;
    padding:max(14px,env(safe-area-inset-top)) 16px 14px;
    display:flex;align-items:center;justify-content:space-between;
    color:#fff;z-index:5;
    background:linear-gradient(180deg,rgba(0,0,0,.55),transparent);
    pointer-events:none;
}
.cam-top .counter{
    background:rgba(0,0,0,.55);backdrop-filter:blur(8px);
    padding:6px 12px;border-radius:20px;
    font-size:13px;font-weight:600;letter-spacing:.3px;
}
.cam-top .close{
    pointer-events:auto;
    background:rgba(0,0,0,.55);backdrop-filter:blur(8px);
    border:0;color:#fff;width:40px;height:40px;border-radius:50%;
    font-size:20px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
}
.cam-frame{
    position:absolute;inset:0;
    display:flex;align-items:center;justify-content:center;
    pointer-events:none;z-index:2;
}
.cam-frame::before{
    content:"";
    width:70%;height:70%;max-width:420px;max-height:420px;
    border:2px dashed rgba(255,255,255,.55);
    border-radius:18px;
    box-shadow:0 0 0 9999px rgba(0,0,0,.18);
}
.cam-bottom{
    padding:16px 16px max(24px,env(safe-area-inset-bottom));
    background:linear-gradient(0deg,rgba(0,0,0,.85),transparent);
    z-index:5;
}
.cam-thumbs{
    display:flex;gap:6px;overflow-x:auto;
    padding-bottom:12px;margin-bottom:8px;
    scrollbar-width:none;
}
.cam-thumbs::-webkit-scrollbar{display:none}
.cam-thumbs:empty{display:none}
.cam-thumbs img{
    width:52px;height:52px;object-fit:cover;border-radius:8px;
    border:1px solid #333;flex-shrink:0;
}
.cam-controls{
    display:flex;align-items:center;justify-content:space-between;
    gap:20px;
}
.cam-side{
    width:56px;height:56px;border-radius:50%;
    background:rgba(255,255,255,.12);color:#fff;border:0;
    display:flex;align-items:center;justify-content:center;
    font-size:18px;cursor:pointer;
    backdrop-filter:blur(8px);
}
.cam-side:active{background:rgba(255,255,255,.25)}
.shutter{
    width:76px;height:76px;border-radius:50%;
    border:5px solid #fff;background:#fff;
    box-shadow:0 0 0 4px rgba(255,255,255,.22);
    cursor:pointer;transition:transform .07s;
}
.shutter:active{transform:scale(.9)}
.shutter:disabled{opacity:.4;cursor:not-allowed}
.cam-hint{
    position:absolute;bottom:130px;left:50%;transform:translateX(-50%);
    color:#fff;font-size:13px;background:rgba(0,0,0,.55);
    padding:6px 14px;border-radius:20px;backdrop-filter:blur(8px);
    z-index:3;white-space:nowrap;
}

/* ==== LIGHTBOX ==== */
.lightbox{
    position:fixed;top:0;right:0;bottom:0;left:0;
    z-index:10000;
    background:rgba(0,0,0,.97);
    display:flex;flex-direction:column;
    touch-action:none;
    user-select:none;-webkit-user-select:none;
}
.lightbox[hidden]{display:none}
.lb-stage{
    flex:1;position:relative;overflow:hidden;
    display:flex;align-items:center;justify-content:center;
    cursor:crosshair;
}
.lb-stage img{
    max-width:100%;max-height:100%;
    display:block;
    transform-origin:center center;
    user-select:none;-webkit-user-drag:none;
    will-change:transform;
    pointer-events:none;
}
.lb-lens{
    position:absolute;
    width:200px;height:200px;
    border-radius:50%;
    border:3px solid #fff;
    box-shadow:0 0 0 3px rgba(0,0,0,.6), 0 8px 30px rgba(0,0,0,.75);
    background-repeat:no-repeat;
    background-color:#000;
    pointer-events:none;
    z-index:5;
    transform:translate(-50%,-50%);
}
.lb-lens[hidden]{display:none}
.lb-close{
    position:absolute;
    top:max(16px,env(safe-area-inset-top));
    right:16px;
    width:44px;height:44px;border-radius:50%;
    background:rgba(255,255,255,.14);color:#fff;border:0;
    font-size:22px;cursor:pointer;z-index:20;
    display:flex;align-items:center;justify-content:center;
    backdrop-filter:blur(8px);
}
.lb-close:active{background:rgba(255,255,255,.3)}
.lb-toolbar{
    display:flex;gap:6px;justify-content:center;align-items:center;
    padding:12px 12px max(16px,env(safe-area-inset-bottom));
    background:linear-gradient(0deg,rgba(0,0,0,.7),transparent);
    flex-wrap:wrap;
    z-index:10;
}
.lb-toolbar button, .lb-toolbar a{
    background:rgba(255,255,255,.14);color:#fff;border:0;
    min-width:44px;height:44px;padding:0 14px;border-radius:10px;
    font-size:15px;font-weight:600;cursor:pointer;
    backdrop-filter:blur(8px);
    display:inline-flex;align-items:center;justify-content:center;
    font-family:inherit;text-decoration:none;
}
.lb-toolbar button:active, .lb-toolbar a:active{background:rgba(255,255,255,.3)}
.lb-toolbar #lbZoomVal{
    color:#fff;font-size:13px;font-weight:600;
    min-width:56px;text-align:center;
}
.lb-hint{
    position:absolute;bottom:86px;left:50%;transform:translateX(-50%);
    color:#fff;font-size:12px;background:rgba(0,0,0,.6);
    padding:6px 14px;border-radius:20px;
    pointer-events:none;opacity:.9;
    backdrop-filter:blur(8px);
    white-space:nowrap;
    z-index:8;
}
</style>
</head>
<body>

<!-- ===================== LIGHTBOX ===================== -->
<div id="lightbox" class="lightbox" hidden role="dialog" aria-modal="true">
    <button type="button" class="lb-close" id="lbClose" aria-label="Kapat">✕</button>
    <div class="lb-stage" id="lbStage">
        <img id="lbImg" alt="">
        <div class="lb-lens" id="lbLens" hidden></div>
    </div>
    <div class="lb-hint">🔍 Sürükle: mercek · Tekerlek: yakınlaştır · Çift tıkla: sıfırla</div>
    <div class="lb-toolbar">
        <button type="button" id="lbZoomOut">−</button>
        <span id="lbZoomVal">100%</span>
        <button type="button" id="lbZoomIn">+</button>
        <button type="button" id="lbToggleLens" title="Mercek">🔍</button>
        <button type="button" id="lbReset" title="Sıfırla">↺</button>
        <a id="lbDownload" href="#" download>📥</a>
    </div>
</div>

<!-- ===================== CAMERA ===================== -->
<div id="cameraOverlay" class="camera-overlay" hidden>
    <div class="camera-view">
        <video id="video" autoplay playsinline muted></video>
        <canvas id="canvas" hidden></canvas>
        <div class="cam-frame"></div>
        <div class="cam-top">
            <button type="button" class="close" id="camClose">✕</button>
            <div class="counter"><span id="camCount">0</span> fotoğraf</div>
            <div style="width:40px"></div>
        </div>
        <div class="cam-hint" id="camHint">Netlik halkasını kaydırarak farklı bölgelere odaklan</div>
    </div>
    <div class="cam-bottom">
        <div class="cam-thumbs" id="camThumbs"></div>
        <div class="cam-controls">
            <button type="button" class="cam-side" id="camLastDel" title="Son fotoğrafı sil">↶</button>
            <button type="button" class="shutter" id="shutter" aria-label="Fotoğraf Çek"></button>
            <div style="width:56px"></div>
        </div>
    </div>
</div>

<div class="app-grid">

    <header class="app-header">
        <div class="brand">🎯 FocusStack <span><?= $J ? '#'.(int)$J['id'] : '' ?></span></div>
        <?php if ($J): ?>
            <a class="close" href="<?= h($HOME_URL) ?>" aria-label="Ana sayfa" title="Ana sayfa">✕</a>
        <?php endif; ?>
    </header>

    <aside class="app-side">
        <div class="container">
        <?php foreach ($flashes as $f): ?>
            <div class="flash <?= h($f['t']) ?>"><?= h($f['m']) ?></div>
        <?php endforeach; ?>

        <?php if ($dbError): ?>
            <div class="flash error"><b>DB Hatası:</b><br><?= h($dbError) ?></div>
        <?php endif; ?>

        <?php if (!$isSecure && empty($_SERVER['HTTP_X_FORWARDED_PROTO'])): ?>
            <div class="flash info">ℹ️ Kamera için <b>HTTPS</b> gerekir.</div>
        <?php endif; ?>

        <?php if (!$J): ?>

            <h2>➕ Yeni İş</h2>
            <div class="card">
                <label for="skuInput">SKU</label>
                <input type="text" id="skuInput" placeholder="örn: blk1258" maxlength="64" autocomplete="off" inputmode="text">

                <div class="opts-row">
                    <div>
                        <label for="methodSel">Yöntem</label>
                        <select id="methodSel">
                            <option value="pyramid">Pyramid (En Kaliteli)</option>
                            <option value="softmax">Softmax (Hızlı)</option>
                            <option value="dmap">DMap (Derinlik)</option>
                        </select>
                    </div>
                    <div>
                        <label for="bitdepthSel">Bit Derinliği</label>
                        <select id="bitdepthSel">
                            <option value="8">8-bit (JPEG)</option>
                            <option value="16">16-bit (PNG)</option>
                        </select>
                    </div>
                </div>

                <div class="photo-empty" id="photoEmpty" style="margin-top:12px">
                    Henüz fotoğraf yok. Kamerayı aç veya galeriden seç.
                </div>
                <div class="photo-grid" id="photoGrid"></div>

                <div class="upload-row">
                    <button type="button" class="btn primary lg" id="openCamera">📷 Kamera</button>
                    <button type="button" class="btn gray lg" id="pickGallery">🖼️ Galeri</button>
                </div>
                <input type="file" id="fileInput" accept="image/*" multiple hidden>

                <div style="margin-top:10px">
                    <button type="button" class="btn green full lg" id="uploadBtn" disabled>
                        🚀 Stack Oluştur
                    </button>
                </div>
                <div style="text-align:center;color:#52525b;font-size:11px;margin-top:8px">
                    Max <?= MAX_FILES ?> dosya · <?= MAX_FILE_MB ?>MB/dosya
                </div>
            </div>

        <?php else: ?>

            <h2>⚡ İşlemler</h2>
            <div class="card">
                <div class="actions" style="margin-top:0">
                    <form method="post" style="flex:1;min-width:120px"><input type="hidden" name="csrf" value="<?= h($CSRF) ?>"><input type="hidden" name="action" value="run_worker"><input type="hidden" name="back" value="?job=<?= (int)$J['id'] ?>"><button class="btn green full" type="submit">▶ Worker</button></form>
                    <form method="post" style="flex:1;min-width:120px"><input type="hidden" name="csrf" value="<?= h($CSRF) ?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="job_id" value="<?= (int)$J['id'] ?>"><button class="btn gray full" type="submit">🔄 Yeniden Dene</button></form>
                </div>
                <?php if ($previewUrl): ?>
                <div class="actions">
                    <form method="post" style="flex:1;min-width:120px"><input type="hidden" name="csrf" value="<?= h($CSRF) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="job_id" value="<?= (int)$J['id'] ?>"><button class="btn primary full" type="submit">✅ Onayla</button></form>
                    <form method="post" style="flex:1;min-width:120px"><input type="hidden" name="csrf" value="<?= h($CSRF) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="job_id" value="<?= (int)$J['id'] ?>"><button class="btn red full" type="submit">❌ Reddet</button></form>
                </div>
                <?php endif; ?>
                <div style="margin-top:12px">
                    <a class="btn gray full sm" href="<?= h($HOME_URL) ?>">← Ana Sayfa</a>
                </div>
            </div>

        <?php endif; ?>

        <h2>⚙️ Worker</h2>
        <div class="card">
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="run_worker">
                <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                <button class="btn green full" type="submit">▶ Kuyruğu Çalıştır</button>
            </form>
            <div style="font-size:11px;color:#71717a;margin-top:8px">
                Worker arka planda çalışır. İlerleme sayfayı yenileyerek takip edilir.
            </div>
            <?php if ($workerOutput !== null): ?>
                <h2 style="margin-top:14px">Bu Çalıştırma</h2>
                <textarea readonly><?= h($workerOutput) ?></textarea>
            <?php endif; ?>
            <?php if ($lastLog): ?>
                <h2 style="margin-top:14px">Son Worker Log</h2>
                <textarea readonly><?= h($lastLog) ?></textarea>
            <?php endif; ?>
        </div>
        </div>
    </aside>

    <main class="app-main">
        <div class="container">

        <?php if (!$J): ?>

            <h2>📋 İşler (<?= count($jobs) ?>)</h2>
            <div class="card tight">
            <?php if (empty($jobs)): ?>
                <p style="color:#71717a;margin:8px 4px">Henüz iş yok.</p>
            <?php else: foreach ($jobs as $j):
                $jid = (int)$j['id'];
                $hasPrev = in_array($j['status'], ['preview_ready','approved','done'], true);
                $jm = $j['method'] ?? null;
                $jb = $j['output_bitdepth'] ?? null;
            ?>
                <a class="jobrow" href="?job=<?= $jid ?>">
                    <div class="thumb">
                        <?php if ($hasPrev): ?>
                            <img src="api/get-image.php?job_id=<?= $jid ?>&role=preview&t=<?= time() ?>" alt="" loading="lazy">
                        <?php else: ?>yok<?php endif; ?>
                    </div>
                    <div class="meta">
                        <div class="title">#<?= $jid ?> · <?= h($j['sku']) ?></div>
                        <div class="sub">
                            <span class="badge <?= badge($j['status']) ?>"><?= h(badge_label($j['status'])) ?></span>
                            <span>%<?= (int)$j['progress'] ?></span>
                            <span><?= (int)$j['file_count'] ?> dosya</span>
                            <?php if ($jm): ?><span class="pill"><?= h($jm) ?></span><?php endif; ?>
                            <?php if ($jb): ?><span class="pill"><?= (int)$jb ?>-bit</span><?php endif; ?>
                        </div>
                        <?php if (!empty($j['error_msg'])): ?>
                            <div class="err">⚠ <?= h(mb_substr($j['error_msg'], 0, 70)) ?></div>
                        <?php endif; ?>
                        <div class="date"><?= h($j['created_at']) ?></div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
            </div>

        <?php else: ?>

            <?php
            $jid = (int)$J['id'];
            $sources = array_values(array_filter($files, fn($f) => $f['role'] === 'source'));
            $masters = array_values(array_filter($files, fn($f) => $f['role'] === 'master'));
            $prevs   = array_values(array_filter($files, fn($f) => $f['role'] === 'preview'));
            $jm = $J['method'] ?? null;
            $jb = $J['output_bitdepth'] ?? null;
            ?>

            <div class="detail-head">
                <div style="font-size:11px;color:#71717a;text-transform:uppercase;letter-spacing:.6px">İş #<?= $jid ?></div>
                <div class="sku"><?= h($J['sku']) ?></div>
                <span class="badge <?= badge($J['status']) ?>"><?= h(badge_label($J['status'])) ?></span>
                <span style="color:#a1a1aa;margin-left:8px;font-size:12px"><?= h($J['stage']) ?> · %<?= (int)$J['progress'] ?></span>
                <?php if ($jm): ?><span class="pill" style="margin-left:6px"><?= h($jm) ?></span><?php endif; ?>
                <?php if ($jb): ?><span class="pill" style="margin-left:4px"><?= (int)$jb ?>-bit</span><?php endif; ?>

                <div class="progress"><div style="width:<?= (int)$J['progress'] ?>%"></div></div>

                <div class="meta">
                    <span>Oluşturuldu: <?= h($J['created_at']) ?></span>
                    <?php if (!empty($J['approved_at'])): ?><span>· Onay: <?= h($J['approved_at']) ?></span><?php endif; ?>
                </div>

                <?php if (!empty($J['error_msg'])): ?>
                    <h2 style="margin-top:14px;color:#fca5a5">⚠ Hata</h2>
                    <textarea readonly><?= h($J['error_msg']) ?></textarea>
                <?php endif; ?>
            </div>

            <h2>📷 Sonuç</h2>
            <div class="card">
                <?php if ($previewUrl): ?>
                    <?php $lbSrc = $masterUrl ?: $previewUrl; ?>
                    <div class="preview-box">
                        <img src="<?= h($previewUrl) ?>"
                             alt="Sonuç"
                             data-lightbox="<?= h($lbSrc) ?>"
                             data-lightbox-full="<?= h($lbSrc) ?>"
                             data-download="<?= h($lbSrc) ?>">
                        <div class="zoom-badge">🔍 Büyüt</div>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn gray"
                                data-lightbox="<?= h($lbSrc) ?>"
                                data-lightbox-full="<?= h($lbSrc) ?>"
                                data-download="<?= h($lbSrc) ?>">🔍 Önizle</button>
                        <a class="btn primary" href="<?= h($lbSrc) ?>" download>📥 İndir</a>
                    </div>
                <?php else: ?>
                    <p style="color:#71717a;margin:0">Henüz çıktı yok.</p>
                <?php endif; ?>
            </div>

            <h2>📁 Kaynak (<?= count($sources) ?>)</h2>
            <div class="card">
                <div class="filelist">
                    <?php foreach ($sources as $f): ?>
                        <div><?= h(basename($f['path'])) ?> · <?= number_format((int)$f['size']/1024, 0) ?> KB</div>
                    <?php endforeach; ?>
                    <?php if (!$sources): ?><div style="color:#71717a">Yok</div><?php endif; ?>
                </div>
                <?php if ($masters || $prevs): ?>
                    <h2 style="margin-top:12px">📤 Çıktılar</h2>
                    <div class="filelist">
                        <?php foreach (array_merge($masters, $prevs) as $f): ?>
                            <div><?= h($f['role']) ?> → <?= h(basename($f['path'])) ?> · <?= number_format((int)$f['size']/1024, 0) ?> KB</div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <h2>📜 Log (<?= count($logs) ?>)</h2>
            <div class="card">
                <?php if ($logs): ?>
                    <textarea readonly><?php foreach ($logs as $l):
                        $line = '[' . ($l['created_at'] ?? '?') . '] ' . ($l['action'] ?? '?');
                        if (!empty($l['user_ref'])) $line .= ' — user=' . $l['user_ref'];
                        echo h($line) . "\n";
                    endforeach; ?></textarea>
                <?php else: ?>
                    <p style="color:#71717a;margin:0">Kayıt yok.</p>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        </div>
    </main>
</div>

<script>
// ============================================================
// KAMERA + UPLOAD
// ============================================================
(function(){
    'use strict';
    const CSRF       = <?= json_encode($CSRF) ?>;
    const MAX_FILES  = <?= MAX_FILES ?>;
    const photos = [];

    const cameraOverlay = document.getElementById('cameraOverlay');
    const video         = document.getElementById('video');
    const canvas        = document.getElementById('canvas');
    const shutter       = document.getElementById('shutter');
    const camClose      = document.getElementById('camClose');
    const camCount      = document.getElementById('camCount');
    const camThumbs     = document.getElementById('camThumbs');
    const camLastDel    = document.getElementById('camLastDel');
    const camHint       = document.getElementById('camHint');

    const openCameraBtn = document.getElementById('openCamera');
    const pickGalleryBtn= document.getElementById('pickGallery');
    const fileInput     = document.getElementById('fileInput');
    const photoGrid     = document.getElementById('photoGrid');
    const photoEmpty    = document.getElementById('photoEmpty');
    const uploadBtn     = document.getElementById('uploadBtn');
    const skuInput      = document.getElementById('skuInput');
    const methodSel     = document.getElementById('methodSel');
    const bitdepthSel   = document.getElementById('bitdepthSel');

    let stream = null;
    const facingMode = 'environment';

    async function startCamera() {
        stopCamera();
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: facingMode }, width:{ideal:1920}, height:{ideal:1080} },
                audio: false
            });
            video.srcObject = stream;
            await video.play().catch(()=>{});
        } catch (err) {
            let msg = 'Kamera açılamadı.';
            if (err.name === 'NotAllowedError') msg = 'Kamera izni reddedildi.';
            else if (err.name === 'NotFoundError') msg = 'Kamera bulunamadı.';
            else if (err.name === 'NotReadableError') msg = 'Kamera meşgul.';
            else if (location.protocol !== 'https:' && location.hostname !== 'localhost')
                msg = 'Kamera için HTTPS gerekir. Şu an: ' + location.protocol;
            else msg += ' ' + err.message;
            alert(msg);
            closeCamera();
        }
    }
    function stopCamera() {
        if (stream) { stream.getTracks().forEach(t=>t.stop()); stream=null; }
        if (video) video.srcObject = null;
    }
    async function openCamera() {
        if (photos.length >= MAX_FILES) { alert('Maksimum '+MAX_FILES+' fotoğraf.'); return; }
        cameraOverlay.hidden = false;
        document.body.style.overflow = 'hidden';
        renderCamThumbs();
        await startCamera();
        setTimeout(()=>{ if(camHint) camHint.style.opacity='0'; }, 4000);
    }
    function closeCamera() {
        stopCamera();
        cameraOverlay.hidden = true;
        document.body.style.overflow = '';
        renderMainGrid();
    }
    function takePhoto() {
        if (!video.videoWidth) { alert('Kamera hazır değil.'); return; }
        if (photos.length >= MAX_FILES) { alert('Maksimum '+MAX_FILES+' fotoğraf.'); return; }
        const w = video.videoWidth, h = video.videoHeight;
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);
        canvas.toBlob((blob)=>{
            if (!blob) return;
            const idx = photos.length + 1;
            const name = 'stack_'+String(idx).padStart(3,'0')+'_'+Date.now()+'.jpg';
            photos.push(new File([blob], name, { type:'image/jpeg', lastModified:Date.now() }));
            renderCamThumbs();
            updateUploadBtn();
            if (navigator.vibrate) navigator.vibrate(15);
        }, 'image/jpeg', 0.92);
    }
    function renderCamThumbs() {
        if (!camCount) return;
        camCount.textContent = photos.length;
        camThumbs.innerHTML = '';
        const start = Math.max(0, photos.length - 6);
        for (let i=start; i<photos.length; i++) {
            const url = URL.createObjectURL(photos[i]);
            const img = document.createElement('img');
            img.src = url; img.alt = '';
            img.onload = ()=>URL.revokeObjectURL(url);
            camThumbs.appendChild(img);
        }
        camThumbs.scrollLeft = camThumbs.scrollWidth;
    }
    function renderMainGrid() {
        if (!photoGrid) return;
        photoGrid.innerHTML = '';
        photoEmpty.style.display = photos.length ? 'none' : 'block';
        photos.forEach((file, i)=>{
            const url = URL.createObjectURL(file);
            const tile = document.createElement('div');
            tile.className = 'photo-tile';
            tile.innerHTML =
                '<img src="'+url+'" alt="">'+
                '<span class="num">'+String(i+1).padStart(2,'0')+'</span>'+
                '<button type="button" class="del" data-idx="'+i+'">✕</button>';
            photoGrid.appendChild(tile);
        });
        updateUploadBtn();
    }
    function updateUploadBtn() {
        if (!uploadBtn) return;
        uploadBtn.disabled = photos.length === 0;
        uploadBtn.textContent = photos.length ? '🚀 Stack Oluştur ('+photos.length+')' : '🚀 Stack Oluştur';
    }

    if (photoGrid) photoGrid.addEventListener('click', (e)=>{
        const btn = e.target.closest('.del'); if (!btn) return;
        const idx = parseInt(btn.dataset.idx, 10); if (isNaN(idx)) return;
        photos.splice(idx, 1); renderMainGrid();
    });
    if (camLastDel) camLastDel.addEventListener('click', ()=>{
        if (!photos.length) return;
        photos.pop(); renderCamThumbs(); updateUploadBtn();
    });
    if (pickGalleryBtn) pickGalleryBtn.addEventListener('click', ()=>fileInput.click());
    if (fileInput) fileInput.addEventListener('change', ()=>{
        const files = Array.from(fileInput.files||[]);
        for (const f of files) {
            if (photos.length >= MAX_FILES) break;
            if (!f.type.startsWith('image/')) continue;
            const idx = photos.length + 1;
            const ext = (f.name.split('.').pop()||'jpg').toLowerCase();
            const name = 'pick_'+String(idx).padStart(3,'0')+'_'+Date.now()+'.'+ext;
            try { photos.push(new File([f], name, { type: f.type })); } catch(_){ photos.push(f); }
        }
        fileInput.value = '';
        renderMainGrid();
    });
    if (uploadBtn) uploadBtn.addEventListener('click', async ()=>{
        const sku = (skuInput.value||'').trim();
        if (!sku) { alert('Lütfen SKU girin.'); skuInput.focus(); return; }
        if (!photos.length) { alert('En az bir fotoğraf çekin.'); return; }

        const method = methodSel ? methodSel.value : 'softmax';
        const bitdepth = bitdepthSel ? bitdepthSel.value : '8';

        const oldText = uploadBtn.textContent;
        uploadBtn.disabled = true;
        uploadBtn.textContent = '⏳ Yükleniyor…';
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'upload');
        fd.append('sku', sku);
        fd.append('method', method);
        fd.append('bitdepth', bitdepth);
        photos.forEach((file)=>fd.append('images[]', file, file.name));
        try {
            const res = await fetch('', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin' });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Yükleme başarısız');
            photos.length = 0;
            window.location.href = '?job=' + data.job_id;
        } catch (err) {
            alert('Hata: ' + err.message);
            uploadBtn.disabled = false;
            uploadBtn.textContent = oldText;
        }
    });

    if (openCameraBtn) openCameraBtn.addEventListener('click', openCamera);
    if (camClose) camClose.addEventListener('click', closeCamera);
    if (shutter)  shutter.addEventListener('click', takePhoto);
    document.addEventListener('keydown', (e)=>{
        if (cameraOverlay && !cameraOverlay.hidden && (e.code==='Space'||e.key===' ')) {
            e.preventDefault(); takePhoto();
        }
        if (cameraOverlay && !cameraOverlay.hidden && e.key==='Escape') closeCamera();
    });
    window.addEventListener('beforeunload', stopCamera);
    if (photoGrid) renderMainGrid();
})();

// ============================================================
// LIGHTBOX
// ============================================================
(function(){
    'use strict';
    const lightbox = document.getElementById('lightbox');
    if (!lightbox) return;

    const stage    = document.getElementById('lbStage');
    const img      = document.getElementById('lbImg');
    const lens     = document.getElementById('lbLens');
    const btnClose = document.getElementById('lbClose');
    const btnIn    = document.getElementById('lbZoomIn');
    const btnOut   = document.getElementById('lbZoomOut');
    const zoomVal  = document.getElementById('lbZoomVal');
    const btnReset = document.getElementById('lbReset');
    const btnLens  = document.getElementById('lbToggleLens');
    const btnDl    = document.getElementById('lbDownload');

    const LENS_ZOOM = 2.6;
    let scale = 1;
    let tx = 0, ty = 0;
    let lensEnabled = true;
    let isPanning = false;
    let lastX = 0, lastY = 0;
    const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

    function applyTransform() {
        img.style.transform = 'translate('+tx+'px,'+ty+'px) scale('+scale+')';
        zoomVal.textContent = Math.round(scale*100) + '%';
        if (scale > 1.02) lens.hidden = true;
    }
    function resetView() { scale = 1; tx = 0; ty = 0; applyTransform(); }
    function setScale(s, cx, cy) {
        s = Math.max(1, Math.min(10, s));
        if (cx !== undefined && cy !== undefined) {
            const r = s / scale;
            tx = cx - (cx - tx) * r;
            ty = cy - (cy - ty) * r;
        }
        scale = s;
        if (scale <= 1.001) { tx = 0; ty = 0; }
        applyTransform();
    }

    function openLightbox(src, fullSrc, dlSrc) {
        img.src = src;
        resetView();
        lens.hidden = true;
        lightbox.hidden = false;
        document.body.style.overflow = 'hidden';
        if (btnDl) btnDl.href = dlSrc || fullSrc || src;
    }
    function closeLightbox() {
        lightbox.hidden = true;
        document.body.style.overflow = '';
        img.src = '';
        lens.hidden = true;
    }

    document.addEventListener('click', (e)=>{
        const el = e.target.closest('[data-lightbox]');
        if (!el) return;
        e.preventDefault();
        const src = el.dataset.lightbox;
        const full = el.dataset.lightboxFull || src;
        const dl = el.dataset.download || full;
        openLightbox(full || src, full, dl);
    });

    btnClose.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', (e)=>{
        if (!lightbox.hidden && e.key === 'Escape') closeLightbox();
    });

    btnIn.addEventListener('click', ()=>setScale(scale * 1.4, 0, 0));
    btnOut.addEventListener('click', ()=>{
        setScale(scale / 1.4, 0, 0);
        if (scale <= 1.001) { tx=0; ty=0; applyTransform(); }
    });
    btnReset.addEventListener('click', resetView);
    btnLens.addEventListener('click', ()=>{
        lensEnabled = !lensEnabled;
        btnLens.style.background = lensEnabled ? '' : 'rgba(255,255,255,.05)';
        if (!lensEnabled) lens.hidden = true;
    });

    stage.addEventListener('wheel', (e)=>{
        e.preventDefault();
        const rect = stage.getBoundingClientRect();
        const cx = e.clientX - (rect.left + rect.width/2);
        const cy = e.clientY - (rect.top + rect.height/2);
        const delta = -e.deltaY * 0.0025;
        setScale(scale * (1 + delta), cx, cy);
    }, { passive: false });

    function updateLens(clientX, clientY) {
        if (!lensEnabled || scale > 1.02 || !img.naturalWidth) { lens.hidden = true; return; }
        const stageRect = stage.getBoundingClientRect();
        const imgRect = img.getBoundingClientRect();
        if (imgRect.width < 10) { lens.hidden = true; return; }
        const xInImg = clientX - imgRect.left;
        const yInImg = clientY - imgRect.top;
        if (xInImg < 0 || yInImg < 0 || xInImg > imgRect.width || yInImg > imgRect.height) { lens.hidden = true; return; }

        const size = Math.max(140, Math.min(240, window.innerWidth * 0.42));
        lens.style.width = size + 'px';
        lens.style.height = size + 'px';
        const z = LENS_ZOOM;
        const bgW = imgRect.width * z;
        const bgH = imgRect.height * z;
        const bgX = -(xInImg * z - size/2);
        const bgY = -(yInImg * z - size/2);
        lens.style.backgroundImage = 'url("'+img.src+'")';
        lens.style.backgroundSize = bgW + 'px ' + bgH + 'px';
        lens.style.backgroundPosition = bgX + 'px ' + bgY + 'px';

        const offsetY = isTouch ? -150 : 0;
        let lx = clientX - stageRect.left;
        let ly = clientY - stageRect.top + offsetY;
        const halfSize = size / 2;
        lx = Math.min(stageRect.width - halfSize, Math.max(halfSize, lx));
        ly = Math.min(stageRect.height - halfSize, Math.max(halfSize, ly));
        lens.style.left = lx + 'px';
        lens.style.top  = ly + 'px';
        lens.hidden = false;
    }

    stage.addEventListener('mousemove', (e)=>{
        if (lightbox.hidden || isPanning) return;
        updateLens(e.clientX, e.clientY);
    });
    stage.addEventListener('mouseleave', ()=>{ lens.hidden = true; });

    stage.addEventListener('mousedown', (e)=>{
        if (scale > 1.02 && !lightbox.hidden) {
            isPanning = true;
            lastX = e.clientX; lastY = e.clientY;
            stage.style.cursor = 'grabbing';
            lens.hidden = true;
            e.preventDefault();
        }
    });
    window.addEventListener('mousemove', (e)=>{
        if (!isPanning) return;
        tx += e.clientX - lastX;
        ty += e.clientY - lastY;
        lastX = e.clientX; lastY = e.clientY;
        applyTransform();
    });
    window.addEventListener('mouseup', ()=>{
        if (isPanning) { isPanning = false; stage.style.cursor = 'crosshair'; }
    });

    let tStartDist = 0, tStartScale = 1, tLastX = 0, tLastY = 0, tPan = false, tLastTap = 0;

    stage.addEventListener('touchstart', (e)=>{
        if (e.touches.length === 2) {
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            tStartDist = Math.hypot(dx, dy);
            tStartScale = scale;
            tPan = false;
            lens.hidden = true;
        } else if (e.touches.length === 1) {
            if (scale > 1.02) {
                tPan = true;
                tLastX = e.touches[0].clientX;
                tLastY = e.touches[0].clientY;
            } else {
                updateLens(e.touches[0].clientX, e.touches[0].clientY);
            }
        }
    }, { passive: true });

    stage.addEventListener('touchmove', (e)=>{
        if (e.touches.length === 2) {
            const dx = e.touches[0].clientX - e.touches[1].clientX;
            const dy = e.touches[0].clientY - e.touches[1].clientY;
            const dist = Math.hypot(dx, dy);
            if (tStartDist > 0) setScale(tStartScale * (dist / tStartDist), 0, 0);
            e.preventDefault();
        } else if (e.touches.length === 1) {
            const t = e.touches[0];
            if (tPan) {
                tx += t.clientX - tLastX;
                ty += t.clientY - tLastY;
                tLastX = t.clientX; tLastY = t.clientY;
                applyTransform();
                e.preventDefault();
            } else {
                updateLens(t.clientX, t.clientY);
            }
        }
    }, { passive: false });

    stage.addEventListener('touchend', (e)=>{
        if (e.touches.length === 0) tPan = false;
        tStartDist = 0;
        const now = Date.now();
        if (e.changedTouches.length === 1 && now - tLastTap < 300) resetView();
        tLastTap = now;
    });
    stage.addEventListener('touchcancel', ()=>{ tPan = false; tStartDist = 0; });
    stage.addEventListener('dblclick', (e)=>{ e.preventDefault(); resetView(); });

    window.addEventListener('beforeunload', ()=>{
        lightbox.hidden = true;
        document.body.style.overflow = '';
    });
})();

// ============================================================
// OTOMATİK YENİLEME
// ============================================================
<?php if ($autoRefresh): ?>
(function(){
    let count = 0;
    const t = setInterval(() => {
        if (document.hidden) return;
        count++;
        if (count > 120) { clearInterval(t); return; }
        
        // Sadece status'u fetch ile çek (tam reload yok)
            fetch('api/jobs-status.php?job_id=' + <?= (int)($J['id'] ?? 0) ?>)
            .then(r => r.json())
            .then(d => {
                if (d.status && d.status !== 'processing') location.reload();
            });


    }, 5000);
})();
<?php endif; ?>
</script>
</body>
</html>