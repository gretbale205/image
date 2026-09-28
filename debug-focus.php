<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#09090b">
<title>POI Grid Focus</title>
<style>
*{box-sizing:border-box}
body{background:#09090b;color:#e4e4e7;font-family:Inter,system-ui,sans-serif;margin:0;padding:10px}
h1{font-size:16px;color:#fff;margin:0 0 10px}
.card{background:#131316;border:1px solid #27272a;border-radius:10px;padding:12px;margin-bottom:10px}
.btn{width:100%;padding:12px;border:0;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;font-family:inherit;margin-bottom:6px}
.btn:disabled{opacity:.4}
.btn.green{background:#16a34a;color:#fff}
.btn.red{background:#dc2626;color:#fff}
.btn.gray{background:#27272a;color:#e4e4e7}
.btn.orange{background:#d97706;color:#fff}
.row{display:flex;gap:6px;flex-wrap:wrap}
.row>*{flex:1;min-width:80px}
.cam-wrap{position:relative;background:#000;border-radius:10px;overflow:hidden;aspect-ratio:3/4;max-height:70vh}
video{width:100%;height:100%;object-fit:cover;display:block}
.grid-overlay{position:absolute;inset:5%;display:grid;gap:3px;pointer-events:none;z-index:3}
.grid-overlay.sz3{grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(3,1fr)}
.cell{border:2px solid rgba(79,140,255,.45);border-radius:8px;position:relative;transition:all .2s}
.cell .num{position:absolute;top:4px;left:6px;color:#fff;font-size:11px;font-weight:800;text-shadow:0 1px 4px #000}
.cell.active{border-color:#facc15;background:rgba(250,204,21,.2);animation:pulse .8s infinite}
.cell.done{border-color:#22c55e;background:rgba(34,197,94,.15)}
.cell.done::after{content:"✓";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#22c55e;font-size:32px;font-weight:900}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(250,204,21,.6)}50%{box-shadow:0 0 0 12px rgba(250,204,21,0)}}
.status{padding:10px;border-radius:8px;background:#1a2540;color:#8fb9ff;text-align:center;font-weight:700;margin-bottom:10px;font-size:13px}
.status.ok{background:#052e16;color:#86efac}
.status.bad{background:#351116;color:#ff9da7}
.status.warn{background:#35280e;color:#ffd27d}
pre{background:#000;padding:10px;border-radius:8px;font:11px monospace;color:#a1a1aa;white-space:pre-wrap;word-break:break-all;max-height:240px;overflow:auto;margin:0}
.thumbs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.thumb{background:#000;border:2px solid #27272a;border-radius:8px;padding:4px;cursor:pointer}
.thumb.best{border-color:#22c55e;box-shadow:0 0 12px rgba(34,197,94,.4)}
.thumb img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:5px;display:block}
.thumb .lbl{font-size:10px;text-align:center;padding:3px;font-family:monospace;font-weight:700;color:#e4e4e7}
.thumb .sh{font-size:10px;text-align:center;color:#facc15;font-family:monospace}
</style>
</head>
<body>

<h1>🎯 POI Grid Focus</h1>

<div class="card">
  <div class="status" id="status">Hazır — BAŞLAT</div>
  <div class="row">
    <button class="btn green" id="btnStart" onclick="runGrid()">🚀 9 NOKTAYA ODAKLAN</button>
    <button class="btn red" id="btnStop" onclick="stopFlag=true" disabled>⏹ DURDUR</button>
  </div>
</div>

<div class="card">
  <div class="cam-wrap">
    <video id="video" autoplay playsinline muted></video>
    <div class="grid-overlay sz3" id="grid"></div>
  </div>
  <div id="videoInfo" style="text-align:center;font:11px monospace;color:#8e9aaa;margin-top:6px">—</div>
</div>

<div class="card">
  <h3 style="font-size:13px;margin:0 0 8px">📊 Sonuçlar</h3>
  <div id="results"></div>
  <div class="thumbs" id="thumbs" style="margin-top:10px"></div>
</div>

<div class="card">
  <h3 style="font-size:13px;margin:0 0 8px">📜 Log</h3>
  <pre id="log">Hazır.</pre>
</div>

<script>
let stream = null;
let track = null;
let imageCapture = null;
let caps = {};
let photos = [];
let stopFlag = false;

const $ = id => document.getElementById(id);
const sleep = ms => new Promise(r => setTimeout(r, ms));

function log(m) {
  const el = $('log');
  el.textContent += '\n[' + new Date().toLocaleTimeString() + '] ' + m;
  el.scrollTop = el.scrollHeight;
}
function setStatus(m, c='') {
  $('status').textContent = m;
  $('status').className = 'status ' + c;
}

async function runGrid() {
  if (stream) { stream.getTracks().forEach(t=>t.stop()); stream = null; }
  stopFlag = false;
  $('btnStart').disabled = true;
  $('btnStop').disabled = false;
  $('thumbs').innerHTML = '';
  $('results').innerHTML = '';
  photos = [];

  log('══════════════════════════════');
  log('POI GRID BAŞLIYOR');
  log('══════════════════════════════');

  // 1) KAMERA
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false
    });
  } catch(e) {
    log('❌ Kamera: ' + e.name);
    setStatus('❌ Kamera açılamadı', 'bad');
    $('btnStart').disabled = false;
    return;
  }

  const video = $('video');
  video.srcObject = stream;
  await new Promise(r => { if (video.readyState>=1) return r(); video.onloadedmetadata = r; setTimeout(r,5000); });
  try { await video.play(); } catch(_){}
  for (let i=0; i<25; i++) { if (video.videoWidth) break; await sleep(200); }

  track = stream.getVideoTracks()[0];
  caps = track.getCapabilities ? track.getCapabilities() : {};
  if ('ImageCapture' in window) try { imageCapture = new ImageCapture(track); } catch(_){}

  log('✅ Video: ' + video.videoWidth + '×' + video.videoHeight);
  log('📊 focusMode: ' + JSON.stringify(caps.focusMode || 'YOK'));
  log('📊 pointsOfInterest: ' + (caps.pointsOfInterest ? 'VAR' : 'YOK'));
  $('videoInfo').textContent = video.videoWidth + '×' + video.videoHeight;

  const hasPOI = caps.pointsOfInterest || true; // Bazı cihazlar destekler ama capability'de göstermez
  if (!caps.focusMode || !caps.focusMode.includes('single-shot')) {
    log('⚠ single-shot desteklenmiyor — continuous denenecek');
  }

  // 2) 3x3 GRID NOKTALARI
  const gridSize = 3;
  const points = [];
  const cells = document.querySelectorAll('.cell');
  cells.forEach(c => { c.classList.remove('active','done'); });

  for (let gy = 0; gy < gridSize; gy++) {
    for (let gx = 0; gx < gridSize; gx++) {
      points.push({
        x: (gx + 0.5) / gridSize,  // 0.167, 0.5, 0.833
        y: (gy + 0.5) / gridSize,
        idx: gy * gridSize + gx
      });
    }
  }

  log('');
  log('3×3 = 9 nokta:');
  points.forEach(p => log('  #' + (p.idx+1) + ' → (' + p.x.toFixed(2) + ', ' + p.y.toFixed(2) + ')'));

  // 3) HER NOKTAYA ODAKLAN VE ÇEK
  for (let i = 0; i < points.length; i++) {
    if (stopFlag) break;
    const p = points[i];
    if (cells[p.idx]) cells[p.idx].classList.add('active');
    setStatus('📍 Nokta ' + (i+1) + '/9 — (' + p.x.toFixed(2) + ', ' + p.y.toFixed(2) + ')', 'warn');
    log('');
    log('━━━ NOKTA #' + (i+1) + ' ━━━');
    log('POI: x=' + p.x.toFixed(3) + ', y=' + p.y.toFixed(3));

    // Single-shot AF + POI
    try {
      const c = { advanced: [] };

      if (caps.focusMode && caps.focusMode.includes('single-shot')) {
        c.advanced.push({ focusMode: 'single-shot' });
      } else if (caps.focusMode && caps.focusMode.includes('continuous')) {
        c.advanced.push({ focusMode: 'continuous' });
      }

      c.advanced.push({ pointsOfInterest: [{ x: p.x, y: p.y }] });

      await track.applyConstraints(c);
      log('  ✅ AF + POI uygulandı');

      const s = track.getSettings();
      log('  📊 focusMode=' + (s.focusMode || '?') + ' · focusDistance=' + (s.focusDistance ? s.focusDistance.toFixed(3) + 'm' : '?'));

    } catch(e) {
      log('  ❌ applyConstraints HATA: ' + e.name + ' — ' + e.message);

      // Alternatif: advanced olmadan
      try {
        await track.applyConstraints({
          focusMode: 'single-shot',
          pointsOfInterest: [{ x: p.x, y: p.y }]
        });
        log('  ✅ advanced olmadan çalıştı');
      } catch(e2) {
        log('  ❌ O da olmadı: ' + e2.message);
        continue;
      }
    }

    // AF oturması için bekle
    await sleep(1500);

    // İlk frame at
    try { if (imageCapture && imageCapture.grabFrame) await imageCapture.grabFrame(); } catch(_){}
    await sleep(150);

    // Foto çek
    let blob = null;
    try {
      if (imageCapture && imageCapture.takePhoto) {
        try { blob = await imageCapture.takePhoto(); } catch(e) {
          log('  ⚠ takePhoto fail, canvas');
        }
      }
      if (!blob) {
        const c = document.createElement('canvas');
        c.width = video.videoWidth;
        c.height = video.videoHeight;
        c.getContext('2d').drawImage(video, 0, 0);
        blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.95));
      }
    } catch(e) {
      log('  ❌ Capture hata: ' + e.message);
      continue;
    }

    if (!blob) { log('  ❌ Blob yok'); continue; }

    // Sharpness ölç
    const sharp = await quickSharp(blob);
    const s = track.getSettings();
    const usedDistance = s.focusDistance || 0;

    log('  📸 sharp=' + sharp.toFixed(1) + ' · gerçek mesafe=' + usedDistance.toFixed(3) + 'm');

    // Kaydet
    const url = URL.createObjectURL(blob);
    photos.push({ blob, url, poi: p, sharp, distance: usedDistance });

    if (cells[p.idx]) {
      cells[p.idx].classList.remove('active');
      cells[p.idx].classList.add('done');
    }

    // Thumbnail
    const d = document.createElement('div');
    d.className = 'thumb';
    d.innerHTML = '<img src="' + url + '">' +
      '<div class="lbl">#' + (i+1) + ' (' + p.x.toFixed(2) + ',' + p.y.toFixed(2) + ')</div>' +
      '<div class="sh">🎯 ' + sharp.toFixed(0) + '</div>' +
      '<div class="sh" style="color:#86efac">' + usedDistance.toFixed(3) + 'm</div>';
    $('thumbs').appendChild(d);

    await sleep(200);
  }

  // 4) ANALİZ
  log('');
  log('══════════════════════════════');
  log('ANALİZ');
  log('══════════════════════════════');

  if (!photos.length) {
    setStatus('❌ Hiç foto yok', 'bad');
    $('btnStart').disabled = false;
    return;
  }

  const sharpList = photos.map(p => p.sharp);
  const distList = photos.map(p => p.distance);
  const max = Math.max(...sharpList);
  const min = Math.min(...sharpList);
  const best = photos.find(p => p.sharp === max);

  const dMax = Math.max(...distList);
  const dMin = Math.min(...distList);
  const distRange = dMax - dMin;

  log('Sharpness: min=' + min.toFixed(1) + ', max=' + max.toFixed(1) + ' (fark=' + ((max-min)/min*100).toFixed(1) + '%)');
  log('Mesafe: min=' + dMin.toFixed(3) + 'm, max=' + dMax.toFixed(3) + 'm (aralık=' + (distRange*1000).toFixed(0) + 'mm)');
  log('En iyi nokta: #' + photos.indexOf(best) + ' (' + best.poi.x.toFixed(2) + ', ' + best.poi.y.toFixed(2) + ')');

  // Tablo
  let html = '<table style="width:100%;font:11px monospace;border-collapse:collapse">';
  html += '<tr style="background:#0b0e13"><th style="padding:6px;text-align:left;color:#8e9aaa">#</th><th style="padding:6px;text-align:left;color:#8e9aaa">POI</th><th style="padding:6px;text-align:left;color:#8e9aaa">Mesafe</th><th style="padding:6px;text-align:left;color:#8e9aaa">Sharp</th></tr>';
  photos.forEach((p, i) => {
    const isBest = p === best;
    html += '<tr style="' + (isBest ? 'background:#052e16;color:#86efac;font-weight:700' : 'border-bottom:1px solid #27272a') + '">' +
      '<td style="padding:5px">' + (i+1) + (isBest?' ⭐':'') + '</td>' +
      '<td style="padding:5px">(' + p.poi.x.toFixed(2) + ',' + p.poi.y.toFixed(2) + ')</td>' +
      '<td style="padding:5px;color:#86efac">' + p.distance.toFixed(3) + 'm</td>' +
      '<td style="padding:5px;color:#facc15">' + p.sharp.toFixed(1) + '</td>' +
      '</tr>';
  });
  html += '</table>';
  $('results').innerHTML = html;

  // En iyi thumb'i işaretle
  const bestIdx = photos.indexOf(best);
  const thumbEls = $('thumbs').children;
  if (thumbEls[bestIdx]) thumbEls[bestIdx].classList.add('best');

  // Karar
  if (distRange > 0.005) { // > 5mm mesafe farkı
    setStatus('✅ FARKLI MESAFELERE ODAKLANDI — ' + (distRange*1000).toFixed(0) + 'mm aralık. En iyi #' + (bestIdx+1), 'ok');
  } else {
    setStatus('⚠ Tüm noktalar aynı mesafeye odaklandı (düz nesne olabilir)', 'warn');
  }

  $('btnStart').disabled = false;
  $('btnStop').disabled = true;
}

// Hızlı sharpness (Laplacian variance)
async function quickSharp(blob) {
  const bmp = await createImageBitmap(blob);
  const maxW = 500;
  const scale = Math.min(1, maxW / bmp.width);
  const w = Math.floor(bmp.width * scale);
  const h = Math.floor(bmp.height * scale);
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const ctx = c.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(bmp, 0, 0, w, h);
  const data = ctx.getImageData(0, 0, w, h).data;
  const gray = new Float32Array(w * h);
  for (let i=0, p=0; i<data.length; i+=4, p++) {
    gray[p] = .299*data[i] + .587*data[i+1] + .114*data[i+2];
  }
  // Merkez %60
  const x0 = Math.floor(w*0.2), y0 = Math.floor(h*0.2);
  const x1 = Math.floor(w*0.8), y1 = Math.floor(h*0.8);
  let sum=0, sum2=0, n=0;
  for (let y=y0+1; y<y1-1; y++) {
    for (let x=x0+1; x<x1-1; x++) {
      const i = y*w + x;
      const lap = gray[i-w] + gray[i+w] + gray[i-1] + gray[i+1] - 4*gray[i];
      sum += lap; sum2 += lap*lap; n++;
    }
  }
  if (bmp.close) bmp.close();
  if (!n) return 0;
  const m = sum/n;
  return Math.max(0, sum2/n - m*m);
}

// Grid hücrelerini oluştur
(function buildGrid() {
  const g = $('grid');
  g.innerHTML = '';
  for (let i = 0; i < 9; i++) {
    const c = document.createElement('div');
    c.className = 'cell';
    c.innerHTML = '<span class="num">' + (i+1) + '</span>';
    g.appendChild(c);
  }
})();

log('Hazır. Ürünü ekrana koy, sonra "🚀 9 NOKTAYA ODAKLAN" bas.');
</script>
</body>
</html>