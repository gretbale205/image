<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Focus Sweep v2</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0a0a0c;color:#e4e4e7;font:14px/1.5 -apple-system,sans-serif;padding:14px}
h1{font-size:16px;color:#fff;margin:0 0 12px}
.card{background:#131316;border:1px solid #27272a;border-radius:12px;padding:14px;margin-bottom:12px}
.title{font-weight:700;font-size:14px;margin-bottom:10px;color:#fff}
button{border:0;border-radius:8px;padding:12px;background:#3b82f6;color:#fff;font-weight:700;cursor:pointer;font-size:14px;font-family:inherit;width:100%;margin-bottom:6px}
button:disabled{opacity:.4}
button.green{background:#16a34a}
button.red{background:#dc2626}
button.gray{background:#27272a}
button.orange{background:#d97706}
input,select{width:100%;background:#0a0a0c;border:1px solid #27272a;color:#fff;padding:10px;border-radius:8px;font-family:monospace;font-size:14px;margin-bottom:6px}
.video-wrap{position:relative;background:#000;border-radius:8px;overflow:hidden;margin-bottom:10px;aspect-ratio:3/4;display:flex;align-items:center;justify-content:center}
video{width:100%;height:100%;object-fit:contain;display:block;background:#000}
.status{padding:10px;border-radius:8px;background:#1a2540;color:#8fb9ff;font-weight:700;text-align:center;margin-bottom:10px;font-size:13px}
.status.ok{background:#092c20;color:#74e0b2}
.status.bad{background:#351116;color:#ff9da7}
.status.warn{background:#35280e;color:#ffd27d}
.cap-row{display:flex;justify-content:space-between;padding:5px 0;font:12px monospace;border-bottom:1px solid #27272a}
.cap-row span:first-child{color:#8e9aaa}
.green{color:#4ade80}.red{color:#f87171}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:8px}
.grid3 label{font-size:11px;color:#8e9aaa;display:block;margin-bottom:2px}
.thumbs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:10px}
.thumb{background:#000;border:1px solid #27272a;border-radius:8px;padding:4px}
.thumb img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:5px;display:block}
.thumb .label{font-size:10px;color:#fff;text-align:center;padding:3px;font-family:monospace;font-weight:700}
.log{height:240px;overflow:auto;background:#05070a;border:1px solid #27272a;border-radius:8px;padding:8px;font:11px ui-monospace,monospace}
.log div{padding:2px 0;border-bottom:1px solid #0f1319}
.l-ok{color:#71ddb0}.l-bad{color:#ff909b}.l-warn{color:#ffd078}.l-info{color:#8fb9ff}.l-sec{color:#d8b4fe;font-weight:700}
.big-status{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.9);color:#fff;padding:16px 24px;border-radius:12px;font-size:16px;font-weight:800;z-index:10;display:none;text-align:center;border:2px solid #facc15;min-width:200px}
.big-status.on{display:block}
.big-status .num{font-size:36px;color:#facc15;display:block;margin-bottom:6px}
.focus-bar{height:6px;background:#0a0a0c;border-radius:3px;margin-top:6px;overflow:hidden}
.focus-bar>div{height:100%;background:#3b82f6;width:0%;transition:width .3s}
</style>
</head>
<body>

<h1>🔬 Focus Sweep v2 — Samsung</h1>

<!-- ADIM 1 -->
<div class="card">
  <div class="title">1️⃣ Kamera Başlat (1280×720)</div>
  <button class="green" id="btnStart" onclick="startCam()">📷 Kamerayı Aç</button>
  <button class="gray" id="btnStop" onclick="stopCam()" disabled>⏹ Durdur</button>
  <div class="status" id="status">Hazır</div>
</div>

<!-- VIDEO -->
<div class="card" id="camCard" style="display:none">
  <div class="title">2️⃣ Canlı Görüntü</div>
  <div class="video-wrap">
    <video id="video" autoplay playsinline muted></video>
    <div class="big-status" id="bigStatus">
      <span class="num" id="bigNum">3</span>
      <span id="bigText">Hazır ol</span>
    </div>
  </div>
  <div style="text-align:center;font-size:11px;color:#8e9aaa" id="videoInfo"></div>
</div>

<!-- CAPABILITIES -->
<div class="card" id="capsCard" style="display:none">
  <div class="title">3️⃣ Desteklenen Özellikler</div>
  <div id="capsList"></div>
</div>

<!-- MANUEL FOCUS -->
<div class="card" id="manualCard" style="display:none">
  <div class="title">4️⃣ Manuel Focus Test</div>
  <label style="font-size:12px;color:#8e9aaa;display:block;margin-bottom:6px">
    Focus mesafesi: <b id="focusValLabel" style="color:#fff;font-size:14px">0.15</b> metre
  </label>
  <input type="range" id="focusSlider" min="0" max="100" value="5">
  <div class="focus-bar"><div id="focusBar"></div></div>
  <div class="grid3" style="margin-top:10px">
    <button class="orange" onclick="setFocusMeters(0.12)">0.12 m</button>
    <button class="orange" onclick="setFocusMeters(0.2)">0.20 m</button>
    <button class="orange" onclick="setFocusMeters(0.5)">0.50 m</button>
  </div>
  <button class="gray" onclick="testFocusVisible()">👁 Adım Adım Test (0.12 → 0.3 → 0.6 → 1.0)</button>
</div>

<!-- FOCUS SWEEP -->
<div class="card" id="sweepCard" style="display:none">
  <div class="title">5️⃣ Otomatik Focus Sweep</div>
  <div class="grid3">
    <div>
      <label>Başlangıç (m)</label>
      <input id="sweepMin" type="number" value="0.12" step="0.01">
    </div>
    <div>
      <label>Bitiş (m)</label>
      <input id="sweepMax" type="number" value="1.0" step="0.01">
    </div>
    <div>
      <label>Kare</label>
      <input id="sweepCount" type="number" value="9" min="3" max="20">
    </div>
  </div>
  <div class="grid3">
    <div>
      <label>Bekleme (ms)</label>
      <input id="sweepDelay" type="number" value="1200" step="100">
    </div>
    <div>
      <label>Ölçek</label>
      <select id="sweepScale">
        <option value="log" selected>Log (makro)</option>
        <option value="linear">Linear</option>
      </select>
    </div>
    <div style="display:flex;align-items:flex-end">
      <button class="green" style="margin:0" onclick="runSweep()" id="btnSweep">🚀 Başlat</button>
    </div>
  </div>
  <div class="status" id="sweepStatus" style="margin-top:10px">Hazır</div>
  <div class="thumbs" id="thumbs"></div>
  <div style="margin-top:10px">
    <button class="orange" onclick="downloadAll()">📥 Tümünü İndir</button>
    <button class="red" onclick="clearPhotos()">🗑 Temizle</button>
  </div>
</div>

<!-- LOG -->
<div class="card">
  <div class="title">📜 Log</div>
  <button class="gray" onclick="document.getElementById('log').innerHTML=''">Temizle</button>
  <div class="log" id="log" style="margin-top:8px"></div>
</div>

<script>
let stream = null;
let track = null;
let caps = {};
let supported = {};
let photos = [];
let trackBroken = false;

const $ = id => document.getElementById(id);

function log(m, c='info') {
  const d = document.createElement('div');
  d.className = 'l-' + c;
  d.textContent = '[' + new Date().toLocaleTimeString() + '] ' + m;
  $('log').appendChild(d);
  $('log').scrollTop = $('log').scrollHeight;
}
function setStatus(s, c='') {
  $('status').textContent = s;
  $('status').className = 'status ' + c;
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ============================================
// KAMERA BAŞLAT
// ============================================
async function startCam() {
  if (stream) stopCam();
  $('btnStart').disabled = true;
  setStatus('İzin isteniyor…', 'warn');
  trackBroken = false;

  // 1280x720 — canlı görüntü için ideal
  const tries = [
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 960 }, height: { ideal: 540 } }, audio: false },
    { video: { facingMode: 'environment' }, audio: false },
    { video: true, audio: false }
  ];

  stream = null;
  for (const c of tries) {
    try {
      stream = await navigator.mediaDevices.getUserMedia(c);
      log('✅ Kamera: ' + JSON.stringify(c.video), 'ok');
      break;
    } catch (e) {
      log('⚠ Deneme başarısız: ' + e.name, 'warn');
    }
  }

  if (!stream) {
    setStatus('❌ Kamera açılamadı', 'bad');
    $('btnStart').disabled = false;
    return;
  }

  const video = $('video');
  video.srcObject = stream;
  await video.play().catch(()=>{});
  await sleep(500);

  track = stream.getVideoTracks()[0];
  supported = navigator.mediaDevices.getSupportedConstraints ? navigator.mediaDevices.getSupportedConstraints() : {};
  try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch(e) { caps = {}; }

  log('📐 Video boyutu: ' + video.videoWidth + '×' + video.videoHeight, 'ok');
  $('videoInfo').textContent = video.videoWidth + ' × ' + video.videoHeight;

  if (!video.videoWidth) {
    log('⚠ Video hazır değil, bekleniyor…', 'warn');
    await sleep(1500);
    log('📐 Yeni boyut: ' + video.videoWidth + '×' + video.videoHeight, 'info');
    $('videoInfo').textContent = video.videoWidth + ' × ' + video.videoHeight;
  }

  setStatus('✅ Kamera aktif — ' + video.videoWidth + '×' + video.videoHeight, 'ok');
  $('btnStop').disabled = false;
  $('camCard').style.display = 'block';
  $('capsCard').style.display = 'block';
  $('manualCard').style.display = 'block';
  $('sweepCard').style.display = 'block';

  renderCaps();
  setupSlider();
}

function stopCam() {
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
  track = null;
  $('video').srcObject = null;
  $('btnStart').disabled = false;
  $('btnStop').disabled = true;
  setStatus('Durduruldu', '');
}

// ============================================
// CAPABILITIES
// ============================================
function renderCaps() {
  const items = [
    ['focusMode', supported.focusMode, caps.focusMode ? caps.focusMode.join(', ') : ''],
    ['focusDistance', supported.focusDistance, caps.focusDistance ? (caps.focusDistance.min.toFixed(3) + ' – ' + caps.focusDistance.max.toFixed(2) + ' m') : ''],
    ['pointsOfInterest', supported.pointsOfInterest, ''],
    ['zoom', supported.zoom, caps.zoom ? (caps.zoom.min + ' – ' + caps.zoom.max) : ''],
    ['exposureMode', supported.exposureMode, caps.exposureMode ? caps.exposureMode.join(', ') : ''],
  ];
  let html = '';
  for (const [name, sup, val] of items) {
    html += '<div class="cap-row"><span>' + name + '</span><span class="' + (sup ? 'green' : 'red') + '">' + (sup ? '✅' : '❌') + ' ' + (val || '') + '</span></div>';
  }
  $('capsList').innerHTML = html;
}

// ============================================
// FOCUS — DÜZELTİLMİŞ
// ============================================
function setupSlider() {
  $('focusSlider').addEventListener('input', onSlider);
  onSlider();
}

function onSlider() {
  if (!caps.focusDistance) return;
  const t = Number($('focusSlider').value) / 100;
  const min = caps.focusDistance.min, max = caps.focusDistance.max;
  const v = min * Math.pow(max / min, t);
  $('focusValLabel').textContent = v.toFixed(3);
  $('focusBar').style.width = (t * 100) + '%';
  clearTimeout(window.__ft);
  window.__ft = setTimeout(() => setFocusMeters(v), 300);
}

// === KRİTİK: Focus değerini step'e yuvarla + aralığa sıkıştır ===
function normalizeFocusDistance(value) {
  if (!caps.focusDistance) return value;
  const min = caps.focusDistance.min;
  const max = caps.focusDistance.max;
  const step = caps.focusDistance.step || 0.01;

  // Aralığa sıkıştır
  let v = Math.max(min, Math.min(max, value));

  // Step'in katına yuvarla
  const steps = Math.round((v - min) / step);
  v = min + steps * step;

  return v;
}

async function setFocusMeters(m) {
  if (!track) {
    log('❌ Track yok', 'bad');
    return false;
  }

  // Track state kontrolü
  if (track.readyState !== 'live') {
    log('❌ Track canlı değil: ' + track.readyState, 'bad');
    return false;
  }

  const normalized = normalizeFocusDistance(m);

  try {
    // 1) Focus mode manual
    await track.applyConstraints({ advanced: [{ focusMode: 'manual' }] });
    await sleep(50);

    // 2) Focus distance
    await track.applyConstraints({ advanced: [{ focusDistance: normalized }] });
    await sleep(150);

    const s = track.getSettings();
    const actual = s.focusDistance ? s.focusDistance.toFixed(3) : '?';
    log('🎯 Focus: ' + m.toFixed(3) + ' → ' + actual + ' m (norm: ' + normalized.toFixed(3) + ')', 'ok');
    return true;

  } catch (e) {
    log('❌ Focus hatası: ' + e.name + ' — ' + e.message, 'warn');

    // Track bozuldu mu?
    if (e.name === 'OperationError' || e.name === 'InvalidStateError') {
      log('   ⚠ Track bozuldu — kamera yeniden başlatılıyor…', 'warn');
      trackBroken = true;
      // Otomatik yenile
      await sleep(500);
      await startCam();
      await sleep(1000);
      // Tekrar dene
      try {
        await track.applyConstraints({ advanced: [{ focusMode: 'manual' }] });
        await track.applyConstraints({ advanced: [{ focusDistance: normalized }] });
        await sleep(150);
        log('   ✅ Yeniden başlatma sonrası focus OK', 'ok');
        return true;
      } catch (e2) {
        log('   ❌ Yeniden deneme de başarısız: ' + e2.message, 'bad');
        return false;
      }
    }
    return false;
  }
}

async function testFocusVisible() {
  log('━━━ Adım Adım Focus Testi', 'sec');
  const distances = [0.12, 0.2, 0.35, 0.6, 1.0];
  for (const d of distances) {
    const ok = await setFocusMeters(d);
    if (!ok) {
      log('⚠ ' + d + ' m başarısız, devam ediliyor', 'warn');
      continue;
    }
    log('👁 Focus = ' + d + ' m — EKRANA BAK, netlik değişti mi?', 'info');
    await sleep(1500);
  }
  log('❓ Netlik değişti mi? Hangi değerlerde görüntü netleşti?', 'warn');
}

// ============================================
// FOCUS SWEEP
// ============================================
async function runSweep() {
  if (!track || !caps.focusDistance) {
    alert('Focus desteklenmiyor');
    return;
  }

  const min = Number($('sweepMin').value) || 0.12;
  const max = Number($('sweepMax').value) || 1.0;
  const count = Number($('sweepCount').value) || 9;
  const delay = Number($('sweepDelay').value) || 1200;
  const scale = $('sweepScale').value;

  photos = [];
  $('thumbs').innerHTML = '';
  $('btnSweep').disabled = true;
  $('sweepStatus').textContent = 'Başlıyor…';
  $('sweepStatus').className = 'status warn';

  const distances = [];
  for (let i = 0; i < count; i++) {
    let t = i / (count - 1);
    let v = scale === 'log' ? min * Math.pow(max / min, t) : min + (max - min) * t;
    distances.push(v);
  }

  log('━━━ SWEEP: ' + count + ' kare, ' + min + 'm → ' + max + 'm', 'sec');
  log('Mesafeler: ' + distances.map(d => d.toFixed(3)).join(', '), 'info');

  let success = 0;
  const startAll = Date.now();

  for (let i = 0; i < distances.length; i++) {
    const d = distances[i];
    $('bigStatus').classList.add('on');
    $('bigNum').textContent = (i + 1) + '/' + count;
    $('bigText').textContent = d.toFixed(3) + ' m';
    $('sweepStatus').textContent = '📸 ' + (i+1) + '/' + count + ' — ' + d.toFixed(3) + ' m';
    $('sweepStatus').className = 'status warn';

    const ok = await setFocusMeters(d);
    if (!ok) {
      log('⏭ Kare ' + (i+1) + ' atlandı', 'warn');
      continue;
    }

    // Lens oturması bekle
    await sleep(delay);

    // Capture
    const blob = await captureFrame();
    const url = URL.createObjectURL(blob);
    photos.push({ blob, url, distance: d, index: i+1 });
    success++;

    const t = document.createElement('div');
    t.className = 'thumb';
    t.innerHTML =
      '<img src="' + url + '">' +
      '<div class="label">#' + (i+1) + '<br>' + d.toFixed(3) + ' m</div>';
    $('thumbs').appendChild(t);

    log('   ✅ ' + (i+1) + ' — ' + d.toFixed(3) + ' m (' + Math.round(blob.size/1024) + ' KB)', 'ok');
  }

  $('bigStatus').classList.remove('on');
  const elapsed = Math.round((Date.now() - startAll) / 1000);
  $('sweepStatus').textContent = '✅ Bitti — ' + success + '/' + count + ' kare / ' + elapsed + ' sn';
  $('sweepStatus').className = success > 0 ? 'status ok' : 'status bad';
  $('btnSweep').disabled = false;
  log('🎉 Sweep: ' + success + '/' + count + ' kare / ' + elapsed + ' sn', success > 0 ? 'ok' : 'bad');
}

function captureFrame() {
  return new Promise(resolve => {
    const v = $('video');
    const c = document.createElement('canvas');
    c.width = v.videoWidth || 1280;
    c.height = v.videoHeight || 720;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    c.toBlob(b => resolve(b), 'image/jpeg', 0.95);
  });
}

function downloadAll() {
  photos.forEach((p, i) => {
    setTimeout(() => {
      const a = document.createElement('a');
      a.href = p.url;
      a.download = 'focus_' + String(p.index).padStart(2,'0') + '_' + p.distance.toFixed(3) + 'm.jpg';
      a.click();
    }, i * 300);
  });
  log('📥 ' + photos.length + ' foto indirildi', 'ok');
}

function clearPhotos() {
  photos = [];
  $('thumbs').innerHTML = '';
  log('🗑 Temizlendi', 'info');
}

log('Hazır — "Kamerayı Aç" ile başla', 'info');
</script>
</body>
</html>