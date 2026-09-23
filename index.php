<?php
/**
 * index.php — Fotoğraf yükleme / önizleme / onay arayüzü
 * api.php ile aynı klasörde çalışır.
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Görsel İşleme</title>
<style>
  :root {
    --bg:#0f1115; --panel:#171a21; --border:#2a2f3a;
    --text:#e6e8ee; --muted:#8a92a3; --accent:#4f8cff;
    --ok:#2ecc71; --err:#ff5c5c; --warn:#f5a623;
  }
  * { box-sizing:border-box; }
  body {
    margin:0; background:var(--bg); color:var(--text);
    font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;
    display:flex; justify-content:center; padding:32px 16px;
  }
  .wrap { width:100%; max-width:760px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:var(--muted); margin-bottom:24px; font-size:13px; }
  .card {
    background:var(--panel); border:1px solid var(--border);
    border-radius:12px; padding:20px; margin-bottom:16px;
  }
  label { display:block; font-size:12px; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
  input[type=text], input[type=file] {
    width:100%; padding:10px 12px; background:#0d0f14;
    border:1px solid var(--border); border-radius:8px; color:var(--text);
    font-size:14px; margin-bottom:14px;
  }
  input[type=file]::file-selector-button {
    background:var(--accent); color:#fff; border:0; border-radius:6px;
    padding:6px 12px; margin-right:10px; cursor:pointer;
  }
  button {
    background:var(--accent); color:#fff; border:0; border-radius:8px;
    padding:10px 18px; font-size:14px; font-weight:600; cursor:pointer;
    transition:opacity .15s;
  }
  button:disabled { opacity:.5; cursor:not-allowed; }
  button.ghost { background:transparent; border:1px solid var(--border); color:var(--text); }
  button.ok   { background:var(--ok); }
  button.err  { background:var(--err); }
  .row { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
  .status { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--muted); }
  .dot { width:8px; height:8px; border-radius:50%; background:var(--muted); }
  .dot.run { background:var(--warn); animation:pulse 1s infinite; }
  .dot.ok  { background:var(--ok); }
  .dot.err { background:var(--err); }
  @keyframes pulse { 50% { opacity:.3; } }
  .bar { height:6px; background:#0d0f14; border-radius:3px; overflow:hidden; margin-top:10px; }
  .bar > i { display:block; height:100%; width:0; background:var(--accent); transition:width .3s; }
  .preview { margin-top:16px; text-align:center; }
  .preview img {
    max-width:100%; max-height:420px; border-radius:8px;
    border:1px solid var(--border);
  }
  .msg { padding:10px 12px; border-radius:8px; font-size:13px; margin-top:12px; display:none; }
  .msg.show { display:block; }
  .msg.ok  { background:rgba(46,204,113,.12); color:var(--ok); }
  .msg.err { background:rgba(255,92,92,.12);  color:var(--err); }
</style>
</head>
<body>
<div class="wrap">

  <h1>Görsel İşleme</h1>
  <div class="sub">Fotoğrafları yükle, işlenmesini bekle, önizlemeyi onayla veya reddet.</div>

  <!-- YÜKLEME -->
  <div class="card" id="uploadCard">
    <label for="sku">SKU / Referans</label>
    <input type="text" id="sku" placeholder="Örn: URN-12345" autocomplete="off">

    <label for="images">Fotoğraflar (en az 2)</label>
    <input type="file" id="images" accept="image/*" multiple>

    <div class="row">
      <button id="btnUpload">Yükle ve İşle</button>
    </div>

    <div class="msg" id="uploadMsg"></div>
  </div>

  <!-- DURUM -->
  <div class="card" id="statusCard" style="display:none;">
    <div class="status">
      <span class="dot" id="dot"></span>
      <span id="statusText">Bekleniyor…</span>
    </div>
    <div class="bar"><i id="progressBar"></i></div>

    <div class="preview" id="previewBox" style="display:none;">
      <img id="previewImg" alt="Önizleme">
    </div>

    <div class="row" id="actionRow" style="display:none;">
      <button class="ok"  id="btnApprove">Onayla</button>
      <button class="err" id="btnReject">Reddet</button>
      <button class="ghost" id="btnReset">Yeni İş</button>
    </div>

    <div class="msg" id="statusMsg"></div>
  </div>

</div>

<script>
const API = 'api.php';
let currentJob = null;
let pollTimer  = null;

const $ = id => document.getElementById(id);
const setMsg = (el, text, type) => {
  el.textContent = text;
  el.className = 'msg show ' + (type || '');
};
const clearMsg = el => { el.className = 'msg'; el.textContent = ''; };

// ─── YÜKLEME ────────────────────────────────────
$('btnUpload').addEventListener('click', async () => {
  const sku = $('sku').value.trim();
  const files = $('images').files;
  const msg = $('uploadMsg');
  clearMsg(msg);

  if (!sku)          return setMsg(msg, 'SKU gerekli.', 'err');
  if (files.length < 2) return setMsg(msg, 'En az 2 fotoğraf seç.', 'err');

  const fd = new FormData();
  fd.append('action', 'upload');
  fd.append('sku', sku);
  for (const f of files) fd.append('images[]', f);

  $('btnUpload').disabled = true;
  setMsg(msg, 'Yükleniyor…', '');

  try {
    const r = await fetch(API, { method:'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Yükleme başarısız');

    currentJob = j.job_id;
    setMsg(msg, `Yüklendi. İş #${j.job_id} oluşturuldu (${j.count} dosya).`, 'ok');
    $('statusCard').style.display = 'block';
    startPolling(j.job_id);
  } catch (e) {
    setMsg(msg, e.message, 'err');
  } finally {
    $('btnUpload').disabled = false;
  }
});

// ─── DURUM POLLING ──────────────────────────────
function startPolling(jobId) {
  stopPolling();
  tick(jobId);
  pollTimer = setInterval(() => tick(jobId), 2000);
}
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

async function tick(jobId) {
  try {
    const r = await fetch(`${API}?action=status&job_id=${jobId}&t=${Date.now()}`);
    const j = await r.json();
    if (j.error) throw new Error(j.error);

    renderStatus(j);

    if (j.status === 'approved' || j.status === 'rejected' || j.status === 'failed') {
      stopPolling();
    }
  } catch (e) {
    setMsg($('statusMsg'), e.message, 'err');
    stopPolling();
  }
}

function renderStatus(j) {
  const dot  = $('dot');
  const txt  = $('statusText');
  const bar  = $('progressBar');
  const pv   = $('previewBox');
  const img  = $('previewImg');
  const act  = $('actionRow');

  const progress = Math.max(0, Math.min(100, parseInt(j.progress || 0)));

  // Etiketler
  const labels = {
    uploaded:  'Yüklendi, kuyruğa alındı…',
    queued:    'Kuyrukta bekliyor…',
    processing:'İşleniyor…',
    preview:   'Önizleme hazır — onay bekliyor',
    approved:  'Onaylandı ✓',
    rejected:  'Reddedildi',
    failed:    'Hata: ' + (j.error_msg || 'bilinmeyen'),
  };
  txt.textContent = labels[j.status] || `${j.status} (${j.stage || ''})`;

  dot.className = 'dot';
  if (j.status === 'approved') dot.classList.add('ok');
  else if (j.status === 'failed' || j.status === 'rejected') dot.classList.add('err');
  else if (j.status === 'processing' || j.status === 'queued') dot.classList.add('run');

  bar.style.width = progress + '%';

  // Önizleme
  if (j.preview_url && j.status !== 'approved' && j.status !== 'rejected') {
    img.src = j.preview_url;
    pv.style.display = 'block';
    act.style.display = 'flex';
  } else if (j.status === 'approved') {
    pv.style.display = 'none';
    act.style.display = 'none';
    setMsg($('statusMsg'), 'İş onaylandı. Master dosya kaydedildi.', 'ok');
  } else if (j.status === 'rejected') {
    pv.style.display = 'none';
    act.style.display = 'none';
    setMsg($('statusMsg'), 'İş reddedildi, dosyalar silindi.', 'err');
  } else {
    pv.style.display = 'none';
    act.style.display = 'none';
  }
}

// ─── ONAY / RED ─────────────────────────────────
$('btnApprove').addEventListener('click', () => decide('approve'));
$('btnReject').addEventListener('click',  () => decide('reject'));

async function decide(action) {
  if (!currentJob) return;
  const msg = $('statusMsg');
  clearMsg(msg);
  $('btnApprove').disabled = $('btnReject').disabled = true;

  try {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('job_id', currentJob);

    const r = await fetch(API, { method:'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'İşlem başarısız');

    stopPolling();
    tick(currentJob); // son durumu çek
  } catch (e) {
    setMsg(msg, e.message, 'err');
  } finally {
    $('btnApprove').disabled = $('btnReject').disabled = false;
  }
}

// ─── YENİ İŞ ────────────────────────────────────
$('btnReset').addEventListener('click', () => {
  stopPolling();
  currentJob = null;
  $('statusCard').style.display = 'none';
  $('uploadCard').style.display = 'block';
  $('sku').value = '';
  $('images').value = '';
  $('previewImg').src = '';
  clearMsg($('uploadMsg'));
  clearMsg($('statusMsg'));
});
</script>
</body>
</html>