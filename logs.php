<?php
// Basit log görüntüleyici — teşhis amaçlı, sonra sil
// Erişim: logs.php?key=DEGISTIR
const KEY = 'CHANGE_ME_RANDOM_32_CHARS';

if (($_GET['key'] ?? '') !== KEY) {
    http_response_code(403); exit('Forbidden');
}

header('Content-Type: text/html; charset=utf-8');
@set_time_limit(30);

$root = __DIR__;
$storage = $root . '/storage';
$logsDir = $storage . '/logs';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Logs</title>
<style>
body{background:#0a0a0c;color:#e4e4e7;font:13px ui-monospace,monospace;padding:16px;margin:0}
h1{font-size:15px;color:#fff;margin:0 0 12px}
h2{font-size:12px;color:#a1a1aa;text-transform:uppercase;margin:20px 0 8px;border-bottom:1px solid #27272a;padding-bottom:4px}
pre{background:#000;padding:12px;border-radius:8px;border:1px solid #27272a;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:11px;color:#d4d4d8;margin:0}
.file{background:#18181b;padding:8px 12px;border-radius:8px;margin-bottom:6px;display:flex;justify-content:space-between;border-left:3px solid #3b82f6;cursor:pointer;text-decoration:none;color:inherit}
.file:hover{background:#27272a}
.file .name{color:#93c5fd;font-weight:600}
.file .meta{color:#71717a;font-size:11px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.b-ok{background:#14532d;color:#86efac}
.b-err{background:#7f1d1d;color:#fca5a5}
.b-warn{background:#78350f;color:#fcd34d}
.btn{display:inline-block;background:#3b82f6;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;margin-right:6px}
.btn.red{background:#dc2626}
.btn.gray{background:#27272a}
.warn{background:#78350f;color:#fcd34d;padding:10px;border-radius:8px;font-size:12px;margin-bottom:12px}
.actions{margin-bottom:12px}
</style>
</head>
<body>

<h1>📜 FocusStack Logs & Process Viewer</h1>

<div class="actions">
  <a href="?key=<?= h(KEY) ?>" class="btn">🔄 Yenile</a>
  <a href="?key=<?= h(KEY) ?>&ps=1" class="btn gray">🧠 Python process kontrolü</a>
  <a href="?key=<?= h(KEY) ?>&jobs=1" class="btn gray">📊 Job durumu</a>
</div>

<?php if (isset($_GET['ps'])): ?>
  <h2>🧠 Python Process</h2>
  <?php
    $out = @shell_exec('ps aux 2>&1 | grep -E "worker.worker|python" | grep -v grep');
    $out = $out === null ? 'shell_exec yok veya çalıştırılamadı' : trim($out);
    if ($out === '') $out = '(hiç python/worker process yok — worker ÇÖKMÜŞ)';
  ?>
  <pre><?= h($out) ?></pre>
<?php endif; ?>

<?php if (isset($_GET['jobs'])): ?>
  <h2>📊 Job Durumu</h2>
  <?php
    try {
      require_once $root . '/app/db.php';
      $db = getDB();
      $rows = $db->query("SELECT id, sku, status, stage, progress, file_count, error_msg, created_at FROM jobs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
      echo '<table style="width:100%;border-collapse:collapse;font-size:11px">';
      echo '<tr style="background:#0b0e13"><th style="padding:6px;text-align:left">ID</th><th style="padding:6px;text-align:left">SKU</th><th style="padding:6px;text-align:left">Status</th><th style="padding:6px;text-align:left">Stage</th><th style="padding:6px;text-align:left">%</th><th style="padding:6px;text-align:left">Hata</th><th style="padding:6px;text-align:left">Tarih</th></tr>';
      foreach ($rows as $r) {
        $color = in_array($r['status'], ['failed']) ? '#fca5a5' : (in_array($r['status'], ['preview_ready','done','approved']) ? '#86efac' : '#fcd34d');
        echo '<tr style="border-bottom:1px solid #27272a">';
        echo '<td style="padding:5px;color:#fff">#' . (int)$r['id'] . '</td>';
        echo '<td style="padding:5px">' . h($r['sku']) . '</td>';
        echo '<td style="padding:5px;color:' . $color . ';font-weight:700">' . h($r['status']) . '</td>';
        echo '<td style="padding:5px">' . h($r['stage']) . '</td>';
        echo '<td style="padding:5px">' . (int)$r['progress'] . '%</td>';
        echo '<td style="padding:5px;color:#fca5a5;font-size:10px">' . h(mb_substr((string)$r['error_msg'], 0, 80)) . '</td>';
        echo '<td style="padding:5px;color:#71717a">' . h($r['created_at']) . '</td>';
        echo '</tr>';
      }
      echo '</table>';
    } catch (Throwable $e) {
      echo '<pre>DB hatası: ' . h($e->getMessage()) . '</pre>';
    }
  ?>
<?php endif; ?>

<h2>📁 storage/logs/ dosyaları</h2>
<?php
if (!is_dir($logsDir)) {
  echo '<div class="warn">storage/logs/ klasörü yok: ' . h($logsDir) . '</div>';
} else {
  $files = glob($logsDir . '/*') ?: [];
  usort($files, fn($a,$b) => filemtime($b) - filemtime($a));

  if (empty($files)) {
    echo '<div class="warn">Hiç log dosyası yok — worker hiç çalışmamış olabilir.</div>';
  } else {
    echo '<div style="margin-bottom:12px;color:#71717a;font-size:11px">Toplam: ' . count($files) . ' dosya (en yeni üstte)</div>';

    $view = $_GET['view'] ?? '';
    foreach ($files as $f) {
      $name = basename($f);
      $size = filesize($f);
      $mtime = date('Y-m-d H:i:s', filemtime($f));
      $isView = ($view === $name);
      $content = '';
      if ($isView) {
        $content = (string)@file_get_contents($f);
        if (strlen($content) > 100000) $content = substr($content, -100000);
      }

      echo '<a class="file" href="?key=' . h(KEY) . '&view=' . h(urlencode($name)) . '#view-' . h(md5($name)) . '">';
      echo '<span class="name">' . h($name) . '</span>';
      echo '<span class="meta">' . number_format($size) . ' byte · ' . h($mtime) . '</span>';
      echo '</a>';

      if ($isView) {
        echo '<div id="view-' . h(md5($name)) . '" style="margin:-4px 0 12px">';
        echo '<pre>' . h($content ?: '(dosya boş)') . '</pre>';
        echo '</div>';
      }
    }
  }
}
?>

<h2>📁 storage/debug/ (focus/depth maps)</h2>
<?php
$debugDir = $storage . '/debug';
if (is_dir($debugDir)) {
  $dbg = glob($debugDir . '/*') ?: [];
  if ($dbg) {
    foreach ($dbg as $d) {
      echo '<div class="file"><span class="name">' . h(basename($d)) . '</span><span class="meta">' . number_format(filesize($d)) . ' byte</span></div>';
    }
  } else {
    echo '<div class="warn">(boş)</div>';
  }
} else {
  echo '<div class="warn">storage/debug/ yok</div>';
}
?>

</body>
</html>