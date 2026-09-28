<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#09090b">
<title>Focus Stacking Kamera v4</title>
<style>
:root{--bg:#09090b;--panel:#131316;--line:#27272a;--text:#e8e8ea;--muted:#71717a;--blue:#3b82f6;--green:#16a34a;--orange:#d97706;--red:#dc2626;--yellow:#facc15}
*{box-sizing:border-box}
body{margin:0;background:#09090b;color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif}
.hidden{display:none !important}

#mainPage{max-width:480px;margin:0 auto;padding:16px;padding-bottom:calc(24px + env(safe-area-inset-bottom))}
.mp-header{text-align:center;padding:8px 0 16px}
.mp-header h1{font-size:19px;font-weight:700;margin:0}
.mp-header p{font-size:11px;color:var(--muted);margin:4px 0 0}
.mp-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:12px}
.mp-label{font-size:11px;color:#a1a1aa;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:block}
.mp-input,.mp-select{width:100%;background:#09090b;border:1px solid var(--line);color:#fff;border-radius:10px;padding:11px 13px;font-size:15px;outline:none;font-family:inherit}
.mp-input:focus,.mp-select:focus{border-color:var(--blue)}
.mp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mp-btn{width:100%;min-height:50px;border:0;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit}
.mp-btn:active{transform:scale(.98)}
.mp-btn:disabled{opacity:.4;cursor:not-allowed}
.mp-btn.primary{background:var(--blue);color:#fff}
.mp-btn.green{background:var(--green);color:#fff}
.mp-btn.gray{background:var(--line);color:#e4e4e7}
.mp-thumbs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:12px}
.mp-thumb{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#000;border:1px solid var(--line)}
.mp-thumb img{width:100%;height:100%;object-fit:cover}
.mp-thumb .num{position:absolute;top:3px;left:3px;background:rgba(0,0,0,.75);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px}
.mp-empty{text-align:center;color:#52525b;padding:24px 12px;border:1px dashed var(--line);border-radius:12px;font-size:12px}
.mp-status{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px;margin-top:12px}
.mp-progress{height:5px;background:#18181b;border-radius:5px;overflow:hidden;margin:10px 0}
.mp-progress>div{height:100%;background:var(--blue);transition:width .4s;width:0%}
.mp-result{margin-top:12px}
.mp-result img{width:100%;border-radius:10px;background:#000}

#camOverlay{position:fixed;inset:0;z-index:9998;background:#000;display:flex;flex-direction:column}
#camOverlay[hidden]{display:none}

.cam-topbar{flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:max(10px,env(safe-area-inset-top)) 14px 10px;background:linear-gradient(180deg,rgba(0,0,0,.85),transparent);z-index:5;gap:8px}
.cam-topbar .cam-btn-round{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;border:0;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px)}
.cam-topbar .cam-mode-badge{background:rgba(0,0,0,.55);color:#fff;font-size:11px;font-weight:700;padding:6px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;backdrop-filter:blur(8px);white-space:nowrap}

.cam-stage{flex:1;min-height:0;position:relative;background:#000;overflow:hidden;display:flex;align-items:center;justify-content:center;padding:5%}
.cam-stage-inner{position:relative;width:100%;height:100%;background:#000;border-radius:14px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.cam-stage-inner video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}

.grid-overlay{position:absolute;inset:0;display:none;z-index:3;pointer-events:none;padding:0}
.grid-overlay.active{display:grid;gap:3px}
.grid-overlay.sz3{grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(3,1fr)}
.grid-overlay.sz2{grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(2,1fr)}
.cell{border:2px solid rgba(79,140,255,.45);border-radius:8px;position:relative;transition:all .2s;pointer-events:auto;cursor:pointer;background:rgba(0,0,0,.05)}
.cell .num{position:absolute;top:4px;left:6px;color:rgba(255,255,255,.9);font-size:11px;font-weight:800;text-shadow:0 1px 4px #000,0 0 4px #000;font-family:ui-monospace,monospace}
.cell.active{border-color:var(--yellow);background:rgba(250,204,21,.2);animation:pulse .8s infinite}
.cell.done{border-color:#22c55e;background:rgba(34,197,94,.15)}
.cell.done::after{content:"✓";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#22c55e;font-size:36px;font-weight:900;text-shadow:0 2px 8px #000}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(250,204,21,.6)}50%{box-shadow:0 0 0 14px rgba(250,204,21,0)}}

.big-status{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.9);color:#fff;padding:16px 26px;border-radius:14px;font-size:15px;font-weight:800;z-index:5;display:none;text-align:center;border:2px solid var(--yellow);min-width:220px;box-shadow:0 12px 40px rgba(0,0,0,.7)}
.big-status.active{display:block}
.big-status .counter{font-size:36px;color:var(--yellow);display:block;margin-bottom:6px;font-family:ui-monospace,monospace}

.live-badge{position:absolute;top:14px;left:14px;background:rgba(220,38,38,.9);color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;z-index:4;display:flex;align-items:center;gap:5px}
.live-badge .dot{width:7px;height:7px;border-radius:50%;background:#fff;animation:blink 1.5s infinite}
@keyframes blink{50%{opacity:.3}}

.cam-bottombar{flex-shrink:0;background:#09090b;border-top:1px solid rgba(255,255,255,.08);padding:10px 14px max(14px,env(safe-area-inset-bottom));display:flex;flex-direction:column;gap:10px}
.cam-info-row{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#a1a1aa}
.cam-info-row b{color:#fff;font-family:ui-monospace,monospace}
.cam-modes{display:flex;gap:4px;background:#18181b;padding:3px;border-radius:10px}
.cam-modes button{flex:1;background:transparent;color:#a1a1aa;border:0;padding:8px;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
.cam-modes button.active{background:var(--blue);color:#fff}
.cam-modes button:disabled{opacity:.3}
.cam-main-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.cam-side-btn{width:52px;height:52px;border-radius:50%;background:#18181b;color:#e4e4e7;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;font-family:inherit}
.cam-side-btn.on{background:var(--blue);border-color:var(--blue);color:#fff}
.cam-side-btn:disabled{opacity:.35}
.cam-shutter-btn{flex:1;height:60px;border-radius:14px;background:var(--blue);color:#fff;border:0;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-family:inherit}
.cam-shutter-btn:disabled{opacity:.5}
.cam-shutter-btn.green{background:var(--green)}
.cam-shutter-btn.red{background:var(--red)}
.cam-mini-thumbs{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.cam-mini-thumbs::-webkit-scrollbar{display:none}
.cam-mini-thumbs:empty{display:none}
.cam-mini-thumbs img{width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--line);flex-shrink:0}

.cam-flash{position:fixed;inset:0;background:#fff;z-index:99999;pointer-events:none;opacity:0}
.cam-flash.on{opacity:.85;transition:none}

.focus-controls{padding:10px 0;border-top:1px solid rgba(255,255,255,.08);border-bottom:1px solid rgba(255,255,255,.08);margin:0 0 6px}
.focus-controls label{font-size:11px;color:#a1a1aa;display:block;margin-bottom:4px;font-family:ui-monospace,monospace}
.focus-controls label b{color:#fff}
input[type=range]{width:100%;accent-color:var(--blue);height:26px}

@media(max-width:900px){.mp-grid2{grid-template-columns:1fr}}
</style>
</head>
<body>

<main id="mainPage">
  <header class="mp-header">
    <h1>🎯 Focus Stacking Kamera v4</h1>
    <p>Otomatik odak tarama · Yüksek çözünürlük</p>
  </header>

  <div id="mpFlashes"></div>

  <div class="mp-card">
    <label class="mp-label" for="skuInput">SKU / Ürün Kodu</label>
    <input type="text" id="skuInput" class="mp-input" placeholder="URUN-12345" autocomplete="off" maxlength="64">
  </div>

  <div class="mp-card">
    <div class="mp-grid2">
      <div>
        <label class="mp-label" for="methodSel">Yöntem</label>
        <select id="methodSel" class="mp-select">
          <option value="pyramid" selected>Pyramid (En Kaliteli)</option>
          <option value="softmax">Softmax (Hızlı)</option>
          <option value="dmap">DMap (Derinlik)</option>
        </select>
      </div>
      <div>
        <label class="mp-label" for="bitdepthSel">Bit</label>
        <select id="bitdepthSel" class="mp-select">
          <option value="8" selected>8-bit (JPEG)</option>
          <option value="16">16-bit (PNG)</option>
        </select>
      </div>
    </div>
  </div>

  <button id="openCamBtn" class="mp-btn primary" style="min-height:60px;font-size:16px">
    📷 Kamerayı Aç ve Çek
  </button>

  <div class="mp-card" style="margin-top:14px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <label class="mp-label" style="margin:0">Çekilen Kareler (<span id="mpCount">0</span>)</label>
      <button id="mpClearBtn" style="background:transparent;border:0;color:#f87171;font-size:11px;font-weight:600;cursor:pointer;display:none">Tümünü Sil</button>
    </div>
    <div class="mp-empty" id="mpEmpty">Henüz kare yok. Kamerayı açıp çekmeye başla.</div>
    <div class="mp-thumbs" id="mpThumbs"></div>
  </div>

  <button id="uploadBtn" class="mp-btn green" disabled style="margin-top:4px;min-height:56px">
    🚀 Yükle ve Stack Oluştur
  </button>

  <div class="mp-status" id="mpStatus" hidden>
    <div style="display:flex;justify-content:space-between;font-size:12px;color:#a1a1aa">
      <span>İş <b style="color:#fff" id="mpJobId">--</b></span>
      <span id="mpStage">Bekleniyor…</span>
    </div>
    <div class="mp-progress"><div id="mpProgress"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted)">
      <span id="mpStageDetail"></span>
      <span id="mpProgressText">0%</span>
    </div>
  </div>

  <div class="mp-result hidden" id="mpResult" hidden>
    <div style="background:#052e16;color:#86efac;padding:10px;border-radius:10px;margin-top:14px;font-size:13px">✓ Önizleme hazır</div>
    <img id="mpPreviewImg" alt="Önizleme">
    <button id="approveBtn" class="mp-btn green" style="margin-top:10px">✅ Onayla ve Temizle</button>
  </div>
</main>

<div id="camOverlay" hidden>

  <div class="cam-topbar">
    <button class="cam-btn-round" id="camCloseBtn">✕</button>
    <span class="cam-mode-badge" id="camModeBadge">HAZIR</span>
    <button class="cam-btn-round" id="camSettingsBtn">⚙</button>
  </div>

  <div class="cam-stage">
    <div class="cam-stage-inner" id="camStageInner">
      <video id="preview" autoplay playsinline muted></video>
      <div class="live-badge"><span class="dot"></span> LIVE</div>
      <div class="grid-overlay" id="gridOverlay"></div>
      <div class="big-status" id="bigStatus">
        <span class="counter" id="bigCounter">3</span>
        <span id="bigText">Hazır ol</span>
      </div>
    </div>
  </div>

  <div class="cam-bottombar">

    <div class="cam-info-row">
      <span>Kalan: <b id="camRemaining">9</b>/<b id="camTotal">9</b></span>
      <span id="camMode">—</span>
      <span id="camRes">—</span>
    </div>

    <div class="cam-modes" id="camModes">
      <button data-mode="focusSweep" class="active" id="btnModeFocus">🎯 Odak Tarama</button>
      <button data-mode="manual" id="btnModeManual">👆 Manuel</button>
    </div>

    <div class="focus-controls hidden" id="focusControls">
      <label>Odak mesafesi: <b id="focusValLabel">0.15</b> m</label>
      <input type="range" id="focusSlider" min="0" max="100" value="0">
    </div>

    <div class="cam-main-row">
      <button class="cam-side-btn" id="camTorchBtn" title="Işık" disabled>🔦</button>
      <button class="cam-shutter-btn" id="camShutterBtn">🚀 9 Kare Çek</button>
      <button class="cam-side-btn" id="camOneShotBtn" title="Tek Kare" disabled>1️⃣</button>
    </div>

    <div class="cam-mini-thumbs" id="camMiniThumbs"></div>

  </div>

  <div class="cam-drawer hidden" id="camDrawer" style="position:absolute;top:0;right:0;bottom:0;width:min(320px,80%);background:#0e0e11;border-left:1px solid var(--line);z-index:20;padding:max(16px,env(safe-area-inset-top)) 16px max(16px,env(safe-area-inset-bottom));overflow-y:auto">
    <button id="camDrawerClose" style="position:absolute;top:max(12px,env(safe-area-inset-top));right:12px;width:36px;height:36px;border-radius:50%;background:#18181b;color:#fff;border:0;font-size:16px;cursor:pointer">✕</button>
    <div style="margin-top:40px">
      <h3 style="font-size:12px;color:#a1a1aa;text-transform:uppercase;margin:0 0 8px">Kamera Yetenekleri</h3>
      <div id="camCapPanel" style="font-size:11px;font-family:monospace;line-height:1.8"></div>
    </div>
    <div style="margin-top:16px">
      <h3 style="font-size:12px;color:#a1a1aa;text-transform:uppercase;margin:0 0 8px">Odak Tarama Ayarları</h3>
      <label style="font-size:11px;color:#a1a1aa;display:block;margin:8px 0 4px">Başlangıç (m)</label>
      <input type="number" id="setStart" value="0.12" step="0.01" style="width:100%;background:#18181b;border:1px solid var(--line);color:#fff;padding:8px;border-radius:8px;font-family:monospace">
      <label style="font-size:11px;color:#a1a1aa;display:block;margin:8px 0 4px">Bitiş (m)</label>
      <input type="number" id="setEnd" value="1.0" step="0.01" style="width:100%;background:#18181b;border:1px solid var(--line);color:#fff;padding:8px;border-radius:8px;font-family:monospace">
      <label style="font-size:11px;color:#a1a1aa;display:block;margin:8px 0 4px">Kare sayısı</label>
      <input type="number" id="setCount" value="9" min="3" max="20" style="width:100%;background:#18181b;border:1px solid var(--line);color:#fff;padding:8px;border-radius:8px;font-family:monospace">
      <label style="font-size:11px;color:#a1a1aa;display:block;margin:8px 0 4px">Bekleme (ms)</label>
      <input type="number" id="setDelay" value="1200" step="100" style="width:100%;background:#18181b;border:1px solid var(--line);color:#fff;padding:8px;border-radius:8px;font-family:monospace">
    </div>
  </div>
</div>

<div class="cam-flash" id="camFlash"></div>

<script>
(function(){
'use strict';

const API_BASE = (() => {
  const p = location.pathname;
  return p.substring(0, p.lastIndexOf('/')) + '/api';
})();

const $ = id => document.getElementById(id);
const sleep = ms => new Promise(r => setTimeout(r, ms));

// ============================================================
// STATE
// ============================================================
const state = {
  stream: null,
  track: null,
  imageCapture: null,
  caps: {},
  supported: {},
  mode: 'focusSweep',
  shots: [],
  busy: false,
  stackStop: false,
  focusSupported: false,
  focusRange: null,
  currentJobId: null,
  pollInterval: null,
  hasFocusControl: false,
};

// ============================================================
// YARDIMCILAR
// ============================================================
let audioCtx = null;
function beep(freq = 900, dur = 70, vol = 0.12) {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const o = audioCtx.createOscillator();
    const g = audioCtx.createGain();
    o.frequency.value = freq; o.type = 'sine'; g.gain.value = vol;
    o.connect(g); g.connect(audioCtx.destination);
    o.start(); setTimeout(() => o.stop(), dur);
  } catch (_) {}
}
function flash() {
  const f = $('camFlash'); if (!f) return;
  f.classList.add('on');
  setTimeout(() => f.classList.remove('on'), 70);
}
function vib(ms) { if (navigator.vibrate) navigator.vibrate(ms); }

function showToast(msg, ms = 3500) {
  const el = document.createElement('div');
  el.textContent = msg;
  el.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.4);max-width:90vw;text-align:center;transition:opacity .3s;';
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; }, ms - 300);
  setTimeout(() => el.remove(), ms);
}

// ============================================================
// KAMERA AÇ
// ============================================================
async function openCamera() {
  $('mainPage').classList.add('hidden');
  const ov = $('camOverlay');
  ov.hidden = false;
  document.body.style.overflow = 'hidden';

  const video = $('preview');

  // ★ YÜKSEK ÇÖZÜNÜRLÜK DENEMELERİ ★
  const tries = [
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 960 }, height: { ideal: 540 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' } }, audio: false },
    { video: true, audio: false }
  ];

  let stream = null;
  for (const c of tries) {
    try {
      stream = await navigator.mediaDevices.getUserMedia(c);
      console.log('Kamera açıldı:', JSON.stringify(c.video));
      break;
    } catch (e) {
      console.warn('Deneme başarısız:', e.name);
    }
  }
  if (!stream) { alert('Kamera açılamadı'); closeCamera(); return; }
  state.stream = stream;

  video.srcObject = stream;

  // Metadata bekle
  await new Promise(res => {
    if (video.readyState >= 1) return res();
    video.onloadedmetadata = res;
    setTimeout(res, 5000);
  });
  try { await video.play(); } catch(_) {}

  // Boyut gelene kadar bekle
  for (let i = 0; i < 20; i++) { if (video.videoWidth) break; await sleep(250); }

  state.track = stream.getVideoTracks()[0];
  state.supported = navigator.mediaDevices.getSupportedConstraints ? navigator.mediaDevices.getSupportedConstraints() : {};
  try { state.caps = state.track.getCapabilities ? state.track.getCapabilities() : {}; } catch(_) { state.caps = {}; }

  // Ayarları logla
  const settings = state.track.getSettings ? state.track.getSettings() : {};
  console.log('Kamera ayarları:', settings.width + '×' + settings.height, 'fps:', settings.frameRate);

  // ★ ÇÖZÜNÜRLÜĞÜ YÜKSELTMEYE ÇALIŞ ★
  try {
    const maxW = state.caps.width ? state.caps.width.max : 0;
    const maxH = state.caps.height ? state.caps.height.max : 0;
    if (maxW && maxW > video.videoWidth) {
      console.log('Yüksek çözünürlük denemesi:', maxW + '×' + maxH);
      await state.track.applyConstraints({
        width: { ideal: maxW },
        height: { ideal: maxH }
      });
      await sleep(500);
      console.log('Yeni boyut:', video.videoWidth + '×' + video.videoHeight);
    }
  } catch (e) {
    console.warn('Çözünürlük yükseltilemedi:', e.name);
  }

  // ImageCapture
  if ('ImageCapture' in window && state.track) {
    try {
      state.imageCapture = new ImageCapture(state.track);
      console.log('ImageCapture hazır');

      try {
        const pc = await state.imageCapture.getPhotoCapabilities();
        console.log('Foto yetenekleri:', JSON.stringify({
          maxWidth: pc.imageWidth ? pc.imageWidth.max : '?',
          maxHeight: pc.imageHeight ? pc.imageHeight.max : '?'
        }));
      } catch (_) {}
    } catch (e) { state.imageCapture = null; }
  }

  // Focus desteği
  state.focusSupported = !!(state.supported.focusDistance && state.caps.focusDistance &&
    Number.isFinite(state.caps.focusDistance.min) && Number.isFinite(state.caps.focusDistance.max));
  state.focusRange = state.caps.focusDistance || null;
  state.hasFocusControl = !!(state.caps.focusMode && Array.isArray(state.caps.focusMode) && state.caps.focusMode.includes('manual'));

  // Mod seç
  if (state.focusSupported && state.hasFocusControl) {
    state.mode = 'focusSweep';
    $('btnModeFocus').classList.add('active');
    $('btnModeManual').classList.remove('active');
    $('focusControls').classList.remove('hidden');
    $('camModeBadge').textContent = '🎯 ODAK TARAMA';
    initFocusSlider();
  } else {
    state.mode = 'manual';
    $('btnModeFocus').classList.remove('active');
    $('btnModeManual').classList.add('active');
    $('focusControls').classList.add('hidden');
    $('camModeBadge').textContent = '👆 MANUEL';
  }

  $('camRes').textContent = video.videoWidth + '×' + video.videoHeight;
  $('camMode').textContent = state.focusSupported ? 'Focus destekli' : 'Focus yok';
  renderCapPanel();
  buildGrid(3);
  updateCounters();
  renderMiniThumbs();

  $('camShutterBtn').disabled = false;
  $('camOneShotBtn').disabled = false;
  $('camShutterBtn').textContent = state.mode === 'focusSweep' ? '🚀 9 Kare Çek' : '📸 Grid Çek';
}

function closeCamera() {
  state.stackStop = true;
  if (state.stream) state.stream.getTracks().forEach(t => t.stop());
  state.stream = null;
  state.track = null;
  state.imageCapture = null;
  $('preview').srcObject = null;
  $('camOverlay').hidden = true;
  $('mainPage').classList.remove('hidden');
  document.body.style.overflow = '';
  renderMainThumbs();
}

function renderCapPanel() {
  const caps = state.caps || {};
  const sup = state.supported || {};
  const items = [
    ['focusMode', sup.focusMode, caps.focusMode ? caps.focusMode.join(',') : ''],
    ['focusDistance', sup.focusDistance, caps.focusDistance ? (caps.focusDistance.min.toFixed(2) + '–' + caps.focusDistance.max.toFixed(2)) : ''],
    ['pointsOfInterest', sup.pointsOfInterest, ''],
    ['zoom', sup.zoom, caps.zoom ? (caps.zoom.min + '–' + caps.zoom.max) : ''],
    ['exposureMode', sup.exposureMode, ''],
    ['torch', sup.torch, caps.torch ? '✓' : ''],
    ['width.max', '—', caps.width ? caps.width.max : '?'],
    ['height.max', '—', caps.height ? caps.height.max : '?'],
  ];
  $('camCapPanel').innerHTML = items.map(([n, s, v]) =>
    '<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #27272a"><span style="color:#8e9aaa">' + n + '</span><span style="color:' + (s === '—' || s ? '#4ade80' : '#f87171') + '">' + (s === '—' ? '' : (s ? '✓' : '✗')) + ' ' + (v || '') + '</span></div>'
  ).join('');
}

// ============================================================
// FOCUS SLIDER
// ============================================================
function initFocusSlider() {
  const slider = $('focusSlider');
  slider.addEventListener('input', () => {
    if (!state.focusRange) return;
    const t = Number(slider.value) / 100;
    const min = state.focusRange.min;
    const max = state.focusRange.max;
    const v = min * Math.pow(max / min, t);
    $('focusValLabel').textContent = v.toFixed(3);
    clearTimeout(window.__ft);
    window.__ft = setTimeout(() => setFocusMeters(v), 250);
  });
}

function normalizeFocus(value) {
  if (!state.focusRange) return value;
  const min = state.focusRange.min, max = state.focusRange.max;
  const step = state.focusRange.step || 0.01;
  let v = Math.max(min, Math.min(max, value));
  v = min + Math.round((v - min) / step) * step;
  return v;
}

async function setFocusMeters(m) {
  if (!state.track || state.track.readyState !== 'live') return false;
  const v = normalizeFocus(m);
  try {
    await state.track.applyConstraints({ advanced: [{ focusMode: 'manual' }] });
    await sleep(30);
    await state.track.applyConstraints({ advanced: [{ focusDistance: v }] });
    await sleep(120);
    return true;
  } catch (e) {
    console.warn('focus err', e.name, e.message);
    return false;
  }
}

// ============================================================
// CAPTURE
// ============================================================
async function captureFrame() {
  // 1) ImageCapture varsa tam çözünürlük
  if (state.imageCapture) {
    try {
      const blob = await state.imageCapture.takePhoto();
      if (blob && blob.size > 0) {
        const bmp = await createImageBitmap(blob);
        console.log('takePhoto:', bmp.width + 'x' + bmp.height, Math.round(blob.size/1024) + 'KB');
        return blob;
      }
    } catch (e) {
      console.warn('takePhoto fail:', e.name, e.message);
    }
  }
  // 2) Canvas fallback
  return new Promise(res => {
    const v = $('preview');
    const c = document.createElement('canvas');
    c.width = v.videoWidth || 1280;
    c.height = v.videoHeight || 720;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    c.toBlob(b => res(b), 'image/jpeg', 0.95);
  });
}

// ============================================================
// 9-KARE FOCUS SWEEP
// ============================================================
async function runFocusSweep() {
  if (!state.focusSupported || !state.focusRange) {
    alert('Bu cihaz otomatik odak tarama desteklemiyor.');
    return;
  }

  state.stackStop = false;
  state.busy = true;
  $('camShutterBtn').disabled = true;
  $('camOneShotBtn').disabled = true;
  $('camShutterBtn').textContent = '⏳ Çekiliyor…';

  const start = Number($('setStart').value) || 0.12;
  const end   = Number($('setEnd').value) || 1.0;
  const count = Number($('setCount').value) || 9;
  const delay = Number($('setDelay').value) || 1200;

  const distances = [];
  for (let i = 0; i < count; i++) {
    const t = i / (count - 1);
    distances.push(start * Math.pow(end / start, t));
  }

  $('camTotal').textContent = count;
  updateCounters();
  buildGrid(3);
  $('gridOverlay').classList.add('active');

  const cells = document.querySelectorAll('.cell');

  for (let i = 0; i < distances.length; i++) {
    if (state.stackStop) break;

    const d = distances[i];

    $('bigStatus').classList.add('active');
    $('bigCounter').textContent = (i+1) + '/' + count;
    $('bigText').textContent = d.toFixed(3) + ' m';

    cells.forEach(c => c.classList.remove('active'));
    if (cells[i]) cells[i].classList.add('active');

    await setFocusMeters(d);
    await sleep(delay);

    beep(1400, 60, 0.15);
    flash();
    vib(20);
    const blob = await captureFrame();

    addShot(blob, { mode: 'focusSweep', distance: d, index: i+1 });

    if (cells[i]) {
      cells[i].classList.remove('active');
      cells[i].classList.add('done');
    }

    beep(1900, 100, 0.18);
    await sleep(300);
  }

  $('bigStatus').classList.remove('active');
  $('camShutterBtn').disabled = false;
  $('camOneShotBtn').disabled = false;
  $('camShutterBtn').textContent = state.mode === 'focusSweep' ? '🚀 9 Kare Çek' : '📸 Grid Çek';
  state.busy = false;
}

// ============================================================
// MANUEL GRID
// ============================================================
function buildGrid(size) {
  const g = $('gridOverlay');
  g.className = 'grid-overlay sz' + size + (g.classList.contains('active') ? ' active' : '');
  g.innerHTML = '';
  for (let i = 0; i < size * size; i++) {
    const c = document.createElement('div');
    c.className = 'cell';
    c.dataset.idx = i;
    c.innerHTML = '<span class="num">' + (i+1) + '</span>';
    c.onclick = () => onCellClick(i);
    g.appendChild(c);
  }
}
function onCellClick(i) {
  if (state.mode !== 'manual') return;
  runManualSingle(i);
}

async function runManualSingle(i) {
  if (state.busy) return;
  state.busy = true;
  $('camShutterBtn').disabled = true;

  const cells = document.querySelectorAll('.cell');
  cells.forEach(c => c.classList.remove('active'));
  cells[i].classList.add('active');

  $('bigStatus').classList.add('active');
  for (let s = 3; s > 0; s--) {
    $('bigCounter').textContent = s;
    $('bigText').textContent = 'Bölge ' + (i+1);
    await sleep(800);
  }
  $('bigCounter').textContent = '📸';
  $('bigText').textContent = 'Çekiliyor…';

  beep(1400, 60, 0.15);
  flash();
  vib(20);
  const blob = await captureFrame();
  addShot(blob, { mode: 'manual', cell: i+1 });

  cells[i].classList.remove('active');
  cells[i].classList.add('done');
  $('bigStatus').classList.remove('active');
  $('camShutterBtn').disabled = false;
  state.busy = false;
}

async function runManualAll() {
  if (state.busy) return;
  const cells = document.querySelectorAll('.cell');
  for (let i = 0; i < cells.length; i++) {
    if (state.stackStop) break;
    await runManualSingle(i);
  }
}

// ============================================================
// SHOT MANAGEMENT
// ============================================================
function addShot(blob, meta) {
  const url = URL.createObjectURL(blob);
  state.shots.push({ blob, url, meta });
  renderMiniThumbs();
  renderMainThumbs();
  updateCounters();
}

function updateCounters() {
  const remaining = Math.max(0, 9 - state.shots.length);
  $('camRemaining').textContent = remaining;
  $('camTotal').textContent = Math.max(9, state.shots.length);
}

function renderMiniThumbs() {
  const w = $('camMiniThumbs');
  w.innerHTML = '';
  state.shots.slice(-8).forEach(s => {
    const img = document.createElement('img');
    img.src = s.url;
    w.appendChild(img);
  });
  w.scrollLeft = w.scrollWidth;
}

function renderMainThumbs() {
  const c = $('mpCount'); if (c) c.textContent = state.shots.length;
  const empty = $('mpEmpty');
  const thumbs = $('mpThumbs');
  const clearBtn = $('mpClearBtn');
  const upload = $('uploadBtn');

  if (empty) empty.style.display = state.shots.length ? 'none' : 'block';
  if (clearBtn) clearBtn.style.display = state.shots.length ? 'inline' : 'none';
  if (upload) upload.disabled = state.shots.length < 2;

  if (!thumbs) return;
  thumbs.innerHTML = '';
  state.shots.forEach((s, i) => {
    const wrap = document.createElement('div');
    wrap.className = 'mp-thumb';
    wrap.innerHTML = '<img src="' + s.url + '" alt=""><span class="num">' + (i+1) + '</span>';
    thumbs.appendChild(wrap);
  });
}

// ============================================================
// EVENT LISTENERS
// ============================================================
$('openCamBtn')?.addEventListener('click', openCamera);
$('camCloseBtn')?.addEventListener('click', closeCamera);

$('camShutterBtn')?.addEventListener('click', () => {
  if (state.mode === 'focusSweep') runFocusSweep();
  else runManualAll();
});

$('camOneShotBtn')?.addEventListener('click', async () => {
  if (state.busy) return;
  state.busy = true;
  beep(1400, 60, 0.15);
  flash();
  const blob = await captureFrame();
  addShot(blob, { mode: 'single' });
  beep(1900, 100, 0.18);
  state.busy = false;
});

$('btnModeFocus')?.addEventListener('click', () => {
  if (!state.focusSupported || !state.hasFocusControl) { alert('Bu cihaz manuel odak desteklemiyor'); return; }
  state.mode = 'focusSweep';
  $('btnModeFocus').classList.add('active');
  $('btnModeManual').classList.remove('active');
  $('focusControls').classList.remove('hidden');
  $('camShutterBtn').textContent = '🚀 9 Kare Çek';
  $('camModeBadge').textContent = '🎯 ODAK TARAMA';
});

$('btnModeManual')?.addEventListener('click', () => {
  state.mode = 'manual';
  $('btnModeManual').classList.add('active');
  $('btnModeFocus').classList.remove('active');
  $('focusControls').classList.add('hidden');
  $('camShutterBtn').textContent = '📸 Grid Çek';
  $('camModeBadge').textContent = '👆 MANUEL';
});

$('camSettingsBtn')?.addEventListener('click', () => $('camDrawer').classList.toggle('hidden'));
$('camDrawerClose')?.addEventListener('click', () => $('camDrawer').classList.add('hidden'));

$('camTorchBtn')?.addEventListener('click', async () => {
  if (!state.track || !state.supported.torch) return;
  const on = !$('camTorchBtn').classList.contains('on');
  try {
    await state.track.applyConstraints({ advanced: [{ torch: on }] });
    $('camTorchBtn').classList.toggle('on', on);
  } catch (e) { alert('Işık kontrolü başarısız'); }
});

// ============================================================
// MP ANA SAYFA
// ============================================================
$('mpClearBtn')?.addEventListener('click', () => {
  if (!confirm('Tüm kareleri sil?')) return;
  state.shots.forEach(s => URL.revokeObjectURL(s.url));
  state.shots = [];
  renderMainThumbs();
  renderMiniThumbs();
  updateCounters();
});

$('uploadBtn')?.addEventListener('click', async () => {
  const sku = ($('skuInput')?.value || '').trim();
  if (!sku) { alert('SKU gir'); return; }
  if (state.shots.length < 2) { alert('En az 2 kare gerekli'); return; }

  const method = $('methodSel')?.value || 'pyramid';
  const bitdepth = $('bitdepthSel')?.value || '8';

  const btn = $('uploadBtn');
  const oldText = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ Yükleniyor…';

  const fd = new FormData();
  fd.append('sku', sku);
  fd.append('user_ref', 'web_camera_v4');
  fd.append('method', method);
  fd.append('bitdepth', bitdepth);
  state.shots.forEach((s, i) => {
    fd.append('images[]', s.blob, 'shot_' + String(i+1).padStart(3, '0') + '.jpg');
  });

  try {
    const res = await fetch(API_BASE + '/jobs-create.php', { method: 'POST', body: fd });
    const raw = await res.text();
    let data;
    try { data = JSON.parse(raw); }
    catch { throw new Error('Sunucu JSON dönmedi: ' + raw.substring(0, 200)); }
    if (!data.success) throw new Error(data.error || 'Bilinmeyen hata');

    state.currentJobId = data.job_id;
    showToast('✅ İş #' + data.job_id + ' kuyruğa eklendi (' + (data.count || data.file_count || state.shots.length) + ' kare)');

    state.shots.forEach(s => URL.revokeObjectURL(s.url));
    state.shots = [];
    renderMainThumbs();

    btn.disabled = true;
    btn.textContent = '🚀 Yükle ve Stack Oluştur';
    if ($('skuInput')) $('skuInput').value = '';

    const statusEl = $('mpStatus');
    statusEl.hidden = false;
    $('mpJobId').textContent = '#' + state.currentJobId;
    startPolling(state.currentJobId);
  } catch (e) {
    alert('Hata: ' + e.message);
    btn.disabled = false;
    btn.textContent = oldText;
  }
});

function startPolling(jobId) {
  if (state.pollInterval) clearInterval(state.pollInterval);
  state.pollInterval = setInterval(async () => {
    try {
      const res = await fetch(API_BASE + '/jobs-status.php?job_id=' + jobId);
      const data = await res.json();
      if (data.error) return;
      const p = data.progress ?? data.job?.progress ?? 0;
      $('mpProgress').style.width = p + '%';
      $('mpProgressText').textContent = p + '%';
      $('mpStage').textContent = data.stage || data.job?.stage || 'İşleniyor';
      $('mpStageDetail').textContent = data.status || data.job?.status || '';

      const st = data.status ?? data.job?.status;
      if (st === 'preview_ready' || st === 'done') {
        clearInterval(state.pollInterval);
        state.pollInterval = null;
        $('mpResult').hidden = false;
        const url = data.preview_url ?? data.job?.preview_url;
        $('mpPreviewImg').src = url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
        $('uploadBtn').textContent = '✅ Tamamlandı';
      } else if (st === 'failed') {
        clearInterval(state.pollInterval);
        state.pollInterval = null;
        alert('İş başarısız: ' + (data.error_msg || data.job?.error_msg || ''));
        $('uploadBtn').disabled = false;
        $('uploadBtn').textContent = '🚀 Yükle ve Stack Oluştur';
      }
    } catch (e) { console.error(e); }
  }, 2000);
}

$('approveBtn')?.addEventListener('click', async () => {
  if (!state.currentJobId) return;
  const fd = new FormData();
  fd.append('job_id', state.currentJobId);
  const res = await fetch(API_BASE + '/jobs-approve.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) { alert('Onaylandı'); location.reload(); }
  else alert('Hata: ' + (data.error || ''));
});

window.addEventListener('beforeunload', () => {
  if (state.pollInterval) clearInterval(state.pollInterval);
  if (state.stream) state.stream.getTracks().forEach(t => t.stop());
  state.shots.forEach(s => { try { URL.revokeObjectURL(s.url); } catch(_){} });
});

renderMainThumbs();

})();
</script>
</body>
</html>