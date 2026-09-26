<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#09090b">
<title>Focus Stacking Kamera v5</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* ==================== RESET ==================== */
  *,*::before,*::after{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;padding:0;height:100%;overscroll-behavior:none}
  body{background:#09090b;color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;line-height:1.4}
  input,select,button{font-family:inherit}
  .hidden{display:none !important}

  /* ==================== MAIN PAGE ==================== */
  #mainPage{max-width:480px;margin:0 auto;padding:16px;padding-bottom:calc(24px + env(safe-area-inset-bottom))}
  #mainPage.hidden{display:none}
  .mp-header{text-align:center;padding:8px 0 16px}
  .mp-header h1{font-size:19px;font-weight:700;margin:0}
  .mp-header p{font-size:11px;color:#71717a;margin:4px 0 0}
  .mp-card{background:#131316;border:1px solid #1f1f23;border-radius:14px;padding:14px;margin-bottom:12px}
  .mp-label{font-size:11px;color:#a1a1aa;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:block}
  .mp-input,.mp-select{width:100%;background:#09090b;border:1px solid #27272a;color:#f4f4f5;border-radius:10px;padding:11px 13px;font-size:15px;outline:none}
  .mp-input:focus,.mp-select:focus{border-color:#3b82f6}
  .mp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .mp-btn{width:100%;min-height:50px;border:0;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:transform .06s}
  .mp-btn:active{transform:scale(.98)}
  .mp-btn:disabled{opacity:.4;cursor:not-allowed}
  .mp-btn.primary{background:#3b82f6;color:#fff}
  .mp-btn.green{background:#16a34a;color:#fff}
  .mp-btn.gray{background:#27272a;color:#e4e4e7}
  .mp-thumbs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:12px}
  .mp-thumb{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#000;border:1px solid #27272a;cursor:pointer}
  .mp-thumb img{width:100%;height:100%;object-fit:cover}
  .mp-thumb .num{position:absolute;top:3px;left:3px;background:rgba(0,0,0,.75);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px}
  .mp-empty{text-align:center;color:#52525b;padding:24px 12px;border:1px dashed #27272a;border-radius:12px;font-size:12px}
  .mp-flash{padding:10px 14px;border-radius:10px;margin-bottom:10px;font-size:13px}
  .mp-flash.info{background:#082f49;color:#7dd3fc}
  .mp-flash.err{background:#450a0a;color:#fca5a5}
  .mp-flash.ok{background:#052e16;color:#86efac}
  .mp-status{background:#131316;border:1px solid #1f1f23;border-radius:14px;padding:14px;margin-top:12px}
  .mp-progress{height:5px;background:#18181b;border-radius:5px;overflow:hidden;margin:10px 0}
  .mp-progress>div{height:100%;background:#3b82f6;transition:width .4s;width:0%}
  .mp-result{margin-top:12px}
  .mp-result img{width:100%;border-radius:10px;background:#000}

  /* ==================== CAMERA ==================== */
  #camOverlay{position:fixed;inset:0;z-index:9998;background:#000;display:flex;flex-direction:column}
  #camOverlay[hidden]{display:none}
  .cam-topbar{flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:max(10px,env(safe-area-inset-top)) 14px 10px;background:linear-gradient(180deg,rgba(0,0,0,.85),transparent);z-index:5;gap:8px}
  .cam-topbar .cam-btn-round{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;border:0;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px)}
  .cam-topbar .cam-mode-badge{background:rgba(0,0,0,.55);color:#fff;font-size:11px;font-weight:700;padding:6px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;backdrop-filter:blur(8px);white-space:nowrap}

  .cam-stage{flex:1;min-height:0;position:relative;background:#000;overflow:hidden;display:flex;align-items:center;justify-content:center;padding:5% 4%}
  .cam-stage-inner{position:relative;width:100%;height:100%;background:#000;border-radius:14px;overflow:hidden;display:flex;align-items:center;justify-content:center}
  .cam-stage-inner video{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}

  .cam-grid-overlay{position:absolute;inset:0;padding:5%;display:grid;gap:4px;pointer-events:none;transition:opacity .3s}
  .cam-grid-overlay[data-size="2"]{grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(2,1fr)}
  .cam-grid-overlay[data-size="3"]{grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(3,1fr)}
  .cam-grid-overlay[data-size="4"]{grid-template-columns:repeat(4,1fr);grid-template-rows:repeat(4,1fr)}
  .cam-grid-overlay.off{display:none}

  .cam-zone{position:relative;border:2px solid rgba(220,38,38,.55);border-radius:10px;background:rgba(220,38,38,.06);pointer-events:auto;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:border-color .2s,background .2s}
  .cam-zone::before{content:attr(data-num);position:absolute;top:5px;left:7px;color:rgba(255,255,255,.85);font-size:11px;font-weight:700;text-shadow:0 1px 4px rgba(0,0,0,.9);font-family:ui-monospace,Menlo,monospace}
  .cam-zone.done{border-color:rgba(34,197,94,.85);background:rgba(34,197,94,.14)}
  .cam-zone.done::before{color:#22c55e}
  .cam-zone.done::after{content:"✓";color:#22c55e;font-size:34px;font-weight:800;text-shadow:0 2px 10px rgba(0,0,0,.9)}
  .cam-zone.active{border-color:#facc15;background:rgba(250,204,21,.2);animation:camPulse .8s infinite}
  @keyframes camPulse{0%,100%{box-shadow:0 0 0 0 rgba(250,204,21,.7)}50%{box-shadow:0 0 0 14px rgba(250,204,21,0)}}

  .cam-free-reticle{position:absolute;width:80px;height:80px;border:2px solid #fff;border-radius:10px;transform:translate(-50%,-50%);pointer-events:none;opacity:0;transition:opacity .25s;box-shadow:0 0 0 1px rgba(0,0,0,.8), 0 0 20px rgba(255,255,255,.25);z-index:3}
  .cam-free-reticle.on{opacity:1}

  .cam-float-status{position:absolute;top:14px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.7);color:#fff;font-size:12px;font-weight:600;padding:6px 14px;border-radius:20px;backdrop-filter:blur(8px);z-index:4;pointer-events:none;display:flex;align-items:center;gap:8px;white-space:nowrap}
  .cam-float-status .dot{width:7px;height:7px;border-radius:50%;background:#22c55e}
  .cam-float-status.busy .dot{background:#f59e0b;animation:statusBlink .8s infinite}
  @keyframes statusBlink{50%{opacity:.3}}

  .cam-bottombar{flex-shrink:0;background:#09090b;border-top:1px solid rgba(255,255,255,.08);padding:10px 14px max(14px,env(safe-area-inset-bottom));display:flex;flex-direction:column;gap:10px}
  .cam-info-row{display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#a1a1aa}
  .cam-info-row b{color:#fff;font-family:ui-monospace,Menlo,monospace}

  .cam-modes{display:flex;gap:4px;background:#18181b;padding:3px;border-radius:10px;overflow-x:auto;scrollbar-width:none}
  .cam-modes::-webkit-scrollbar{display:none}
  .cam-modes button{flex:1;min-width:80px;background:transparent;color:#a1a1aa;border:0;padding:8px;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;white-space:nowrap}
  .cam-modes button.active{background:#3b82f6;color:#fff}

  .cam-main-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .cam-side-btn{width:52px;height:52px;border-radius:50%;background:#18181b;color:#e4e4e7;border:1px solid #27272a;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;font-family:inherit;transition:all .15s}
  .cam-side-btn.on{background:#3b82f6;border-color:#3b82f6;color:#fff}
  .cam-side-btn:disabled{opacity:.35}

  .cam-shutter-btn{flex:1;height:60px;border-radius:14px;background:#3b82f6;color:#fff;border:0;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-family:inherit}
  .cam-shutter-btn:disabled{opacity:.5}
  .cam-shutter-btn.green{background:#16a34a}
  .cam-shutter-btn.red{background:#dc2626}

  .cam-mini-thumbs{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
  .cam-mini-thumbs::-webkit-scrollbar{display:none}
  .cam-mini-thumbs:empty{display:none}
  .cam-mini-thumbs img{width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #27272a;flex-shrink:0;cursor:pointer}

  .cam-flash{position:fixed;inset:0;background:#fff;z-index:99999;pointer-events:none;opacity:0}
  .cam-flash.on{opacity:.85;transition:none}

  .cam-drawer{position:absolute;top:0;right:0;bottom:0;width:min(320px,80%);background:#0e0e11;border-left:1px solid #1f1f23;z-index:20;padding:max(16px,env(safe-area-inset-top)) 16px max(16px,env(safe-area-inset-bottom));transform:translateX(100%);transition:transform .25s;overflow-y:auto}
  .cam-drawer.open{transform:translateX(0)}
  .cam-drawer h3{font-size:12px;color:#a1a1aa;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px}
  .cam-drawer .row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:12px;border-bottom:1px solid rgba(255,255,255,.06);gap:8px}
  .cam-drawer .row:last-child{border-bottom:0}
  .cam-drawer .yes{color:#4ade80;font-weight:700}
  .cam-drawer .no{color:#f87171;font-weight:700}
  .cam-drawer .val{color:#a1a1aa;font-family:ui-monospace,Menlo,monospace;font-size:11px}
  .cam-drawer .close-drawer{position:absolute;top:max(12px,env(safe-area-inset-top));right:12px;width:36px;height:36px;border-radius:50%;background:#18181b;color:#fff;border:0;font-size:16px;cursor:pointer}
  .cam-drawer .section{margin-bottom:18px}
  .cam-drawer input[type=number]{background:#18181b;border:1px solid #27272a;color:#fff;padding:6px;border-radius:6px;font-family:monospace;width:80px}
  .cam-drawer select{width:100%;background:#18181b;border:1px solid #27272a;color:#fff;padding:8px 10px;border-radius:8px;font-size:13px}

  .cam-confirm{position:fixed;left:0;right:0;bottom:0;background:#0e0e11;border-top:1px solid #1f1f23;border-radius:20px 20px 0 0;padding:16px 16px max(20px,env(safe-area-inset-bottom));z-index:99998;transform:translateY(100%);transition:transform .3s}
  .cam-confirm.open{transform:translateY(0)}
  .cam-confirm h2{font-size:15px;margin:0 0 6px;font-weight:700}
  .cam-confirm p{font-size:12px;color:#a1a1aa;margin:0 0 14px}
  .cam-confirm .btn-row{display:flex;gap:8px}
  .cam-confirm button{flex:1;padding:12px;border-radius:10px;border:0;font-weight:600;cursor:pointer;font-size:14px;font-family:inherit}
  .cam-confirm .b-cancel{background:#27272a;color:#e4e4e7}
  .cam-confirm .b-danger{background:#dc2626;color:#fff}

  .cam-zoom-indicator{position:absolute;left:50%;bottom:12px;transform:translateX(-50%);background:rgba(0,0,0,.65);color:#fff;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;font-family:ui-monospace,Menlo,monospace;z-index:4;pointer-events:none;transition:opacity .25s;opacity:0}
  .cam-zoom-indicator.on{opacity:1}

  /* ============ BIG STATUS ============ */
  .big-status{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,.9);color:#fff;padding:16px 26px;border-radius:14px;font-size:15px;font-weight:800;z-index:5;display:none;text-align:center;border:2px solid #facc15;min-width:220px;box-shadow:0 12px 40px rgba(0,0,0,.7)}
  .big-status.on{display:block}
  .big-status .counter{font-size:36px;color:#facc15;display:block;margin-bottom:6px;font-family:ui-monospace,monospace}

  /* ============ LIGHTBOX ============ */
  .lightbox{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.98);display:flex;flex-direction:column;touch-action:pan-y;user-select:none;-webkit-user-select:none}
  .lightbox[hidden]{display:none}
  .lb-header{flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:max(10px,env(safe-area-inset-top)) 14px 10px;background:linear-gradient(180deg,rgba(0,0,0,.9),transparent);z-index:5;gap:8px}
  .lb-counter{color:#fff;font-size:13px;font-weight:800;padding:6px 14px;border-radius:20px;background:rgba(255,255,255,.12);font-family:ui-monospace,monospace}
  .lb-close{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;border:0;font-size:22px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:9999;position:relative}
  .lb-close:active{background:rgba(255,255,255,.32)}
  .lb-stage{flex:1;min-height:0;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:10px}
  .lb-stage img{max-width:100%;max-height:100%;object-fit:contain;display:block;transition:transform .15s;cursor:grab}
  .lb-nav{position:absolute;top:50%;transform:translateY(-50%);width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.14);color:#fff;border:0;font-size:24px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:6}
  .lb-nav:disabled{opacity:.25}
  .lb-nav.prev{left:10px}
  .lb-nav.next{right:10px}
  .lb-info{position:absolute;bottom:14px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.85);color:#fff;padding:8px 16px;border-radius:20px;font-size:12px;font-family:ui-monospace,monospace;display:flex;gap:14px;align-items:center;z-index:6;flex-wrap:wrap;justify-content:center;max-width:calc(100% - 40px)}
  .lb-toolbar{flex-shrink:0;display:flex;gap:6px;justify-content:center;align-items:center;padding:10px 14px max(14px,env(safe-area-inset-bottom));background:linear-gradient(0deg,rgba(0,0,0,.85),transparent);flex-wrap:wrap}
  .lb-toolbar button{background:rgba(255,255,255,.14);color:#fff;border:0;min-width:44px;height:44px;padding:0 14px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-family:inherit}
  .lb-toolbar button.green{background:#16a34a}
  .lb-dots{display:flex;gap:5px;justify-content:center;padding:0 14px 8px}
  .lb-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.25);transition:all .2s;cursor:pointer}
  .lb-dot.active{background:#22c55e;width:20px;border-radius:4px}

  @media(max-width:600px){
    .lb-nav{width:44px;height:44px;font-size:20px}
    .lb-nav.prev{left:6px}
    .lb-nav.next{right:6px}
  }
</style>
</head>
<body>

<main id="mainPage">
  <header class="mp-header">
    <h1>🎯 Focus Stacking v5</h1>
    <p>Adaptif odak tarama · POI grid · Lightbox</p>
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
          <option value="pyramid" selected>Pyramid</option>
          <option value="softmax">Softmax</option>
          <option value="dmap">DMap</option>
        </select>
      </div>
      <div>
        <label class="mp-label" for="bitdepthSel">Bit</label>
        <select id="bitdepthSel" class="mp-select">
          <option value="8" selected>8-bit</option>
          <option value="16">16-bit</option>
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
    <div class="mp-empty" id="mpEmpty">Henüz kare yok.</div>
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
    <div style="display:flex;justify-content:space-between;font-size:11px;color:#71717a">
      <span id="mpStageDetail"></span>
      <span id="mpProgressText">0%</span>
    </div>
  </div>

  <div class="mp-result" id="mpResult" hidden>
    <div class="mp-flash ok" style="margin-top:14px">✓ Önizleme hazır</div>
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
      <div class="cam-grid-overlay" id="camGrid" data-size="3"></div>
      <div class="cam-free-reticle" id="camFreeReticle"></div>
      <div class="cam-zoom-indicator" id="camZoomIndicator">1.0×</div>
      <div class="cam-float-status" id="camFloatStatus">
        <span class="dot"></span>
        <span id="camFloatText">Hazır</span>
      </div>
      <div class="big-status" id="bigStatus">
        <span class="counter" id="bigCounter">3</span>
        <span id="bigText">Hazır ol</span>
      </div>
    </div>
  </div>

  <div class="cam-bottombar">
    <div class="cam-info-row">
      <span>Kalan: <b id="camZoneRemaining">9</b> / <b id="camZoneTotal">9</b></span>
      <span>Keskinlik: <b id="camSharpness">–</b></span>
      <span id="camRes">–</span>
    </div>

    <div class="cam-modes" id="camModes">
      <button data-mode="focusSweep" class="active" id="btnModeFocus">🎯 Adaptif</button>
      <button data-mode="fixed" id="btnModeFixed">📸 Sabit</button>
      <button data-mode="poiGrid" id="btnModePOI">👆 POI</button>
      <button data-mode="manual" id="btnModeManual">✋ Manuel</button>
    </div>

    <div class="cam-main-row">
      <button class="cam-side-btn" id="camTorchBtn" title="Işık" disabled>🔦</button>
      <button class="cam-shutter-btn" id="camShutterBtn">🚀 Başlat</button>
      <button class="cam-side-btn" id="camSweepBtn" title="Hızlı Adaptif">⚡</button>
    </div>

    <div class="cam-mini-thumbs" id="camMiniThumbs"></div>
  </div>

  <div class="cam-drawer" id="camDrawer">
    <button class="close-drawer" id="camDrawerClose">✕</button>
    <div class="section">
      <h3>Kamera Yetenekleri</h3>
      <div id="camCapPanel"></div>
    </div>
    <div class="section">
      <h3>Odak Ayarları</h3>
      <div class="row"><span>Başlangıç (m)</span><input type="number" id="setStart" value="0.10" step="0.01"></div>
      <div class="row"><span>Bitiş (m)</span><input type="number" id="setEnd" value="0.30" step="0.01"></div>
      <div class="row"><span>Kare sayısı</span><input type="number" id="setCount" value="9" min="3" max="20"></div>
      <div class="row"><span>Bekleme (ms)</span><input type="number" id="setDelay" value="1000" step="100"></div>
      <div class="row"><span>Dağılım</span>
        <select id="setDist">
          <option value="log" selected>Logaritmik</option>
          <option value="linear">Linear</option>
        </select>
      </div>
    </div>
    <div class="section">
      <h3>Zoom</h3>
      <div class="row"><span>Mevcut</span><span class="val" id="camZoomVal">–</span></div>
      <input type="range" id="camZoomSlider" min="1" max="1" step="0.1" value="1" style="width:100%;margin-top:8px" disabled>
      <div class="row"><span>Aralık</span><span class="val" id="camZoomRange">–</span></div>
    </div>
    <div class="section">
      <h3>Yenile</h3>
      <button id="camRefreshCaps" style="width:100%;background:#27272a;color:#fff;border:0;padding:9px;border-radius:8px;cursor:pointer;font-size:12px">Yetenekleri Yenile</button>
    </div>
  </div>
</div>

<div class="cam-confirm" id="camConfirm">
  <h2 id="camConfirmTitle">Kareleri sil?</h2>
  <p id="camConfirmText">Bu işlem geri alınamaz.</p>
  <div class="btn-row">
    <button class="b-cancel" id="camConfirmCancel">İptal</button>
    <button class="b-danger" id="camConfirmOK">Sil</button>
  </div>
</div>

<div class="cam-flash" id="camFlash"></div>

<!-- LIGHTBOX -->
<div id="lightbox" class="lightbox" hidden>
  <div class="lb-header">
    <div class="lb-counter" id="lbCounter">1 / 9</div>
    <button class="lb-close" id="lbCloseBtn" type="button">✕</button>
  </div>
  <div class="lb-stage" id="lbStage">
    <button class="lb-nav prev" id="lbPrev" type="button">‹</button>
    <img id="lbImg" alt="">
    <button class="lb-nav next" id="lbNext" type="button">›</button>
    <div class="lb-info">
      <span id="lbDist" style="color:#86efac">—</span>
      <span id="lbSharp" style="color:#fbbf24">—</span>
      <span id="lbSize" style="color:#a1a1aa">—</span>
    </div>
  </div>
  <div class="lb-dots" id="lbDots"></div>
  <div class="lb-toolbar">
    <button id="lbZoomOut" type="button">−</button>
    <button id="lbZoomLabel" type="button">100%</button>
    <button id="lbZoomIn" type="button">+</button>
    <button class="green" id="lbDownload" type="button">📥</button>
    <button id="lbShare" type="button">📤</button>
  </div>
</div>

<script>
(function(){
'use strict';

// ============================================================
// YARDIMCILAR
// ============================================================
const API_BASE = (() => {
  const p = location.pathname;
  return p.substring(0, p.lastIndexOf('/')) + '/api';
})();
const $ = id => document.getElementById(id);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

// ============================================================
// DURUM
// ============================================================
const state = {
  stream: null,
  track: null,
  imageCapture: null,
  caps: {},
  supported: {},
  shots: [],
  currentJobId: null,
  pollInterval: null,
  busy: false,
  stackStop: false,
  focusSupported: false,
  focusRange: null,
  hasFocusControl: false,
  mode: 'focusSweep',
  zoomLevel: 1.0,
  lbIndex: 0,
  lbZoom: 1,
  pinchActive: false,
  pinchStartDist: 0,
  pinchStartZoom: 1,
};

// ============================================================
// BEEP / FLASH / VIB
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
  el.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.4);max-width:90vw;text-align:center;';
  document.body.appendChild(el);
  setTimeout(() => el.remove(), ms);
}

// ============================================================
// ⭐ LIGHTBOX — GLOBAL (IIFE DIŞI değil ama window'a atanmış)
// ============================================================
function getValidShots() { return state.shots.filter(s => s.url); }

function openLightbox(index) {
  const valid = getValidShots();
  if (!valid.length) return;
  const target = state.shots[index];
  let vi = valid.indexOf(target);
  if (vi < 0) vi = 0;
  state.lbIndex = vi;
  state.lbZoom = 1;
  renderLightbox();
  $('lightbox').hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeLightbox() {
  $('lightbox').hidden = true;
  document.body.style.overflow = '';
  state.lbZoom = 1;
}

function renderLightbox() {
  const valid = getValidShots();
  if (!valid.length) { closeLightbox(); return; }
  state.lbIndex = clamp(state.lbIndex, 0, valid.length - 1);
  const s = valid[state.lbIndex];
  const img = $('lbImg');
  img.src = s.url;
  img.style.transform = 'scale(' + state.lbZoom + ')';
  $('lbCounter').textContent = (state.lbIndex + 1) + ' / ' + valid.length;
  $('lbDist').textContent = s.meta?.distance ? '🎯 ' + Number(s.meta.distance).toFixed(3) + ' m' : '—';
  $('lbSharp').textContent = s.meta?.sharp ? '📊 ' + Number(s.meta.sharp).toFixed(1) : '—';
  $('lbSize').textContent = s.meta?.poi ? `(${s.meta.poi.x.toFixed(2)},${s.meta.poi.y.toFixed(2)})` : '—';
  $('lbZoomLabel').textContent = Math.round(state.lbZoom * 100) + '%';
  const dots = $('lbDots');
  dots.innerHTML = '';
  valid.forEach((_, i) => {
    const d = document.createElement('div');
    d.className = 'lb-dot' + (i === state.lbIndex ? ' active' : '');
    d.onclick = () => { state.lbIndex = i; state.lbZoom = 1; renderLightbox(); };
    dots.appendChild(d);
  });
  $('lbPrev').disabled = state.lbIndex === 0;
  $('lbNext').disabled = state.lbIndex === valid.length - 1;
}

function lightboxPrev() { if (state.lbIndex > 0) { state.lbIndex--; state.lbZoom = 1; renderLightbox(); } }
function lightboxNext() { const v = getValidShots(); if (state.lbIndex < v.length - 1) { state.lbIndex++; state.lbZoom = 1; renderLightbox(); } }
function lightboxZoom(dir) { state.lbZoom = clamp(state.lbZoom + dir * 0.25, 0.5, 5); renderLightbox(); }
function lightboxZoomReset() { state.lbZoom = 1; renderLightbox(); }
function lightboxDownload() {
  const s = getValidShots()[state.lbIndex];
  if (!s) return;
  const a = document.createElement('a');
  a.href = s.url;
  a.download = 'focus_' + (state.lbIndex + 1) + '.jpg';
  a.click();
}
async function lightboxShare() {
  const s = getValidShots()[state.lbIndex];
  if (!s || !navigator.share) return;
  try {
    const f = new File([s.blob], 'focus_' + (state.lbIndex + 1) + '.jpg', { type: 'image/jpeg' });
    if (navigator.canShare && !navigator.canShare({ files: [f] })) return;
    await navigator.share({ files: [f], title: 'Focus' });
  } catch (e) {}
}

// ⭐ IIFE İÇİNDEKİ FONKSİYONLARI GLOBAL'E AT (HTML onclick için)
window.openLightbox = openLightbox;
window.closeLightbox = closeLightbox;
window.lightboxPrev = lightboxPrev;
window.lightboxNext = lightboxNext;
window.lightboxZoom = lightboxZoom;
window.lightboxZoomReset = lightboxZoomReset;
window.lightboxDownload = lightboxDownload;
window.lightboxShare = lightboxShare;

// ⭐ LIGHTBOX EVENT LISTENERS (inline onclick yerine)
document.addEventListener('DOMContentLoaded', () => {
  $('lbCloseBtn')?.addEventListener('click', closeLightbox);
  $('lbPrev')?.addEventListener('click', lightboxPrev);
  $('lbNext')?.addEventListener('click', lightboxNext);
  $('lbZoomOut')?.addEventListener('click', () => lightboxZoom(-1));
  $('lbZoomIn')?.addEventListener('click', () => lightboxZoom(1));
  $('lbZoomLabel')?.addEventListener('click', lightboxZoomReset);
  $('lbDownload')?.addEventListener('click', lightboxDownload);
  $('lbShare')?.addEventListener('click', lightboxShare);
});

// Klavye
document.addEventListener('keydown', (e) => {
  if ($('lightbox').hidden) return;
  if (e.key === 'ArrowLeft') lightboxPrev();
  else if (e.key === 'ArrowRight') lightboxNext();
  else if (e.key === 'Escape') closeLightbox();
});

// Swipe
let lbSwipeX = 0;
document.addEventListener('DOMContentLoaded', () => {
  $('lbStage')?.addEventListener('touchstart', (e) => {
    if (e.touches.length === 1) lbSwipeX = e.touches[0].clientX;
  }, { passive: true });
  $('lbStage')?.addEventListener('touchend', (e) => {
    if (e.changedTouches.length !== 1) return;
    const dx = e.changedTouches[0].clientX - lbSwipeX;
    if (Math.abs(dx) > 60) { if (dx < 0) lightboxNext(); else lightboxPrev(); }
  });
});

// ============================================================
// KAMERA AÇ / KAPAT
// ============================================================
async function openCamera() {
  $('mainPage').classList.add('hidden');
  $('camOverlay').hidden = false;
  document.body.style.overflow = 'hidden';
  updateCamFloat('Kamera açılıyor…', true);

  const video = $('preview');
  const tries = [
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 30, max: 30 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
    { video: { facingMode: { ideal: 'environment' } }, audio: false },
    { video: true, audio: false }
  ];

  let stream = null;
  for (const c of tries) {
    try { stream = await navigator.mediaDevices.getUserMedia(c); break; } catch (_) {}
  }
  if (!stream) { alert('Kamera açılamadı'); closeCamera(); return; }
  state.stream = stream;
  video.srcObject = stream;

  await new Promise(res => {
    if (video.readyState >= 1) return res();
    const t = setTimeout(res, 5000);
    video.onloadedmetadata = () => { clearTimeout(t); res(); };
  });
  try { await video.play(); } catch (_) {}
  for (let i = 0; i < 25; i++) { if (video.videoWidth) break; await sleep(200); }

  state.track = stream.getVideoTracks()[0];
  state.supported = navigator.mediaDevices.getSupportedConstraints ? navigator.mediaDevices.getSupportedConstraints() : {};
  try { state.caps = state.track.getCapabilities ? state.track.getCapabilities() : {}; } catch (_) { state.caps = {}; }

  if ('ImageCapture' in window && state.track) {
    try { state.imageCapture = new ImageCapture(state.track); } catch (_) { state.imageCapture = null; }
  }

  state.focusSupported = !!(state.supported.focusDistance && state.caps.focusDistance &&
    Number.isFinite(state.caps.focusDistance.min) && Number.isFinite(state.caps.focusDistance.max));
  state.focusRange = state.caps.focusDistance || null;
  state.hasFocusControl = !!(state.caps.focusMode && Array.isArray(state.caps.focusMode) && state.caps.focusMode.includes('manual'));

  if (!state.focusSupported || !state.hasFocusControl) {
    state.mode = 'manual';
  }

  $('camRes').textContent = video.videoWidth + '×' + video.videoHeight;
  renderCapPanel();
  buildGrid(3);
  updateCounters();
  renderMiniThumbs();
  initFocusSlider();

  $('camShutterBtn').disabled = false;
  $('camSweepBtn').disabled = false;

  updateCamFloat('Hazır · Bir mod seç', false);
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
    ['focusDistance', sup.focusDistance, caps.focusDistance ? (Number(caps.focusDistance.min).toFixed(2) + '–' + Number(caps.focusDistance.max).toFixed(2)) : ''],
    ['pointsOfInterest', sup.pointsOfInterest, ''],
    ['zoom', sup.zoom, caps.zoom ? (caps.zoom.min + '–' + caps.zoom.max) : ''],
    ['torch', sup.torch, caps.torch ? '✓' : ''],
  ];
  $('camCapPanel').innerHTML = items.map(([n, s, v]) =>
    '<div class="row"><span>' + n + '</span><span style="color:' + (s ? '#4ade80' : '#f87171') + '">' + (s ? '✓' : '✗') + ' ' + (v || '') + '</span></div>'
  ).join('');
}

// ============================================================
// FOCUS
// ============================================================
function normalizeFocus(value) {
  if (!state.focusRange) return value;
  const min = Number(state.focusRange.min), max = Number(state.focusRange.max);
  const step = state.focusRange.step || 0.01;
  let v = Math.max(min, Math.min(max, value));
  v = min + Math.round((v - min) / step) * step;
  return v;
}

async function setFocusMeters(m) {
  if (!state.track || state.track.readyState !== 'live') return false;
  const v = normalizeFocus(m);
  try {
    await state.track.applyConstraints({ focusMode: 'manual' });
    await sleep(30);
    await state.track.applyConstraints({ focusDistance: v });
    await sleep(120);
    await state.track.applyConstraints({ focusDistance: v });
    return { requested: v, actual: v };
  } catch (e) { return false; }
}

function initFocusSlider() {
  const slider = $('camZoomSlider');
  if (!slider || !state.focusRange) return;
  slider.min = 0;
  slider.max = 100;
  slider.value = 0;
  slider.disabled = false;
  slider.oninput = () => {
    const t = Number(slider.value) / 100;
    const min = Number(state.focusRange.min);
    const max = Number(state.focusRange.max);
    const v = min * Math.pow(max / min, t);
    $('camZoomVal').textContent = v.toFixed(3) + 'm';
    clearTimeout(window.__ft);
    window.__ft = setTimeout(() => setFocusMeters(v), 250);
  };
  $('camZoomVal').textContent = Number(state.focusRange.min).toFixed(3) + 'm';
  $('camZoomRange').textContent = Number(state.focusRange.min).toFixed(2) + '–' + Number(state.focusRange.max).toFixed(2);
}

// ============================================================
// CAPTURE
// ============================================================
async function captureFrame() {
  if (state.imageCapture) {
    try {
      const blob = await state.imageCapture.takePhoto();
      if (blob && blob.size > 0) return blob;
    } catch (e) {}
  }
  return new Promise(res => {
    const v = $('preview');
    const c = document.createElement('canvas');
    c.width = v.videoWidth || 1280;
    c.height = v.videoHeight || 720;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    c.toBlob(b => res(b), 'image/jpeg', 0.95);
  });
}

async function computeSharpness(blob) {
  const bitmap = await createImageBitmap(blob);
  const maxW = 500;
  const scale = Math.min(1, maxW / bitmap.width);
  const w = Math.floor(bitmap.width * scale);
  const h = Math.floor(bitmap.height * scale);
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  const ctx = c.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(bitmap, 0, 0, w, h);
  const data = ctx.getImageData(0, 0, w, h).data;
  const gray = new Float32Array(w * h);
  for (let i = 0, p = 0; i < data.length; i += 4, p++) gray[p] = .299*data[i] + .587*data[i+1] + .114*data[i+2];
  if (bitmap.close) bitmap.close();
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
  if (!n) return 0;
  const m = sum/n;
  return Math.max(0, sum2/n - m*m);
}

// ============================================================
// MODLAR
// ============================================================
async function runAdaptiveSweep() {
  if (!state.focusSupported) { alert('Bu cihaz odak kontrolü desteklemiyor.'); return; }
  state.stackStop = false;
  state.busy = true;
  $('camShutterBtn').disabled = true;
  $('camSweepBtn').disabled = true;
  $('camShutterBtn').textContent = '⏳ Çekiliyor…';

  const start = Number($('setStart').value) || 0.10;
  const end = Number($('setEnd').value) || 0.30;
  const count = Number($('setCount').value) || 9;
  const delay = Number($('setDelay').value) || 1000;
  const distType = $('setDist').value;

  const coarseCount = Math.min(5, count);
  const coarseDist = [];
  for (let i = 0; i < coarseCount; i++) {
    const t = i / (coarseCount - 1);
    coarseDist.push(distType === 'log' ? start * Math.pow(end / start, t) : start + (end - start) * t);
  }

  let peak = null, peakSharp = 0;
  updateCamFloat('Coarse sweep…', true);

  for (let i = 0; i < coarseDist.length; i++) {
    if (state.stackStop) break;
    const d = coarseDist[i];
    showBigStatus((i+1) + '/' + coarseCount, d.toFixed(3) + ' m');
    const f = await setFocusMeters(d);
    if (!f) continue;
    await sleep(delay);
    const blob = await captureFrame();
    const sharp = await computeSharpness(blob);
    if (sharp > peakSharp) { peakSharp = sharp; peak = f; }
    $('camSharpness').textContent = Math.round(sharp);
  }

  if (peak) {
    updateCamFloat('Fine sweep…', true);
    const fineMin = peak.requested * 0.75;
    const fineMax = peak.requested * 1.25;
    const fineCount = Math.max(3, count - coarseCount);
    for (let i = 0; i < fineCount; i++) {
      if (state.stackStop) break;
      const t = i / (fineCount - 1);
      const d = fineMin * Math.pow(fineMax / fineMin, t);
      showBigStatus('Fine ' + (i+1) + '/' + fineCount, d.toFixed(3) + ' m');
      const f = await setFocusMeters(d);
      if (!f) continue;
      await sleep(delay);
      const blob = await captureFrame();
      const sharp = await computeSharpness(blob);
      addShot(blob, { mode: 'adaptive', distance: d, sharp: sharp });
      $('camSharpness').textContent = Math.round(sharp);
    }
  }

  hideBigStatus();
  $('camShutterBtn').disabled = false;
  $('camSweepBtn').disabled = false;
  $('camShutterBtn').textContent = '🚀 Başlat';
  state.busy = false;
  updateCamFloat('Tamamlandı', false);
  beep(2000, 200, 0.2);
}

async function runFixedStack() {
  if (!state.focusSupported) { alert('Bu cihaz odak kontrolü desteklemiyor.'); return; }
  state.stackStop = false;
  state.busy = true;
  $('camShutterBtn').disabled = true;
  $('camSweepBtn').disabled = true;
  $('camShutterBtn').textContent = '⏳ Çekiliyor…';

  const start = Number($('setStart').value) || 0.10;
  const end = Number($('setEnd').value) || 0.30;
  const count = Number($('setCount').value) || 9;
  const delay = Number($('setDelay').value) || 1000;
  const distType = $('setDist').value;

  for (let i = 0; i < count; i++) {
    if (state.stackStop) break;
    const t = i / (count - 1);
    const d = distType === 'log' ? start * Math.pow(end / start, t) : start + (end - start) * t;
    showBigStatus((i+1) + '/' + count, d.toFixed(3) + ' m');
    const f = await setFocusMeters(d);
    if (!f) continue;
    await sleep(delay);
    const blob = await captureFrame();
    const sharp = await computeSharpness(blob);
    addShot(blob, { mode: 'fixed', distance: d, sharp: sharp });
  }

  hideBigStatus();
  $('camShutterBtn').disabled = false;
  $('camSweepBtn').disabled = false;
  $('camShutterBtn').textContent = '🚀 Başlat';
  state.busy = false;
}

async function runPOIGrid() {
  state.stackStop = false;
  state.busy = true;
  $('camShutterBtn').disabled = true;
  $('camSweepBtn').disabled = true;
  $('camShutterBtn').textContent = '⏳ Çekiliyor…';

  const gridSize = 3;
  const points = [];
  for (let gy = 0; gy < gridSize; gy++) {
    for (let gx = 0; gx < gridSize; gx++) {
      points.push({ x: (gx + 0.5) / gridSize, y: (gy + 0.5) / gridSize, idx: gy * gridSize + gx });
    }
  }

  const cells = document.querySelectorAll('.cam-zone');
  cells.forEach(c => c.classList.remove('active', 'done'));

  for (let i = 0; i < points.length; i++) {
    if (state.stackStop) break;
    const p = points[i];
    if (cells[p.idx]) cells[p.idx].classList.add('active');
    showBigStatus((i+1) + '/9', `(${p.x.toFixed(2)}, ${p.y.toFixed(2)})`);

    try {
      const c = { advanced: [] };
      if (state.caps.focusMode && state.caps.focusMode.includes('single-shot')) {
        c.advanced.push({ focusMode: 'single-shot' });
      } else if (state.caps.focusMode && state.caps.focusMode.includes('continuous')) {
        c.advanced.push({ focusMode: 'continuous' });
      }
      c.advanced.push({ pointsOfInterest: [{ x: p.x, y: p.y }] });
      await state.track.applyConstraints(c);
    } catch (e) {
      try {
        await state.track.applyConstraints({
          focusMode: 'single-shot',
          pointsOfInterest: [{ x: p.x, y: p.y }]
        });
      } catch (e2) { continue; }
    }

    await sleep(1500);
    const blob = await captureFrame();
    const sharp = await computeSharpness(blob);
    addShot(blob, { mode: 'poiGrid', poi: p, sharp });
    if (cells[p.idx]) {
      cells[p.idx].classList.remove('active');
      cells[p.idx].classList.add('done');
    }
  }

  hideBigStatus();
  $('camShutterBtn').disabled = false;
  $('camSweepBtn').disabled = false;
  $('camShutterBtn').textContent = '🚀 Başlat';
  state.busy = false;
}

async function runManual() {
  if (state.busy) return;
  state.busy = true;
  $('camShutterBtn').disabled = true;
  const blob = await captureFrame();
  const sharp = await computeSharpness(blob);
  addShot(blob, { mode: 'manual', sharp: sharp });
  $('camShutterBtn').disabled = false;
  state.busy = false;
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
  const total = state.mode === 'poiGrid' ? 9 : (Number($('setCount').value) || 9);
  const done = state.shots.length;
  $('camZoneRemaining').textContent = Math.max(0, total - done);
  $('camZoneTotal').textContent = total;
}

function renderMiniThumbs() {
  const w = $('camMiniThumbs');
  if (!w) return;
  w.innerHTML = '';
  state.shots.slice(-8).forEach(s => {
    const img = document.createElement('img');
    img.src = s.url;
    img.onclick = () => openLightbox(state.shots.indexOf(s));
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
    wrap.innerHTML = `<img src="${s.url}" alt=""><span class="num">${i+1}</span>`;
    wrap.onclick = () => openLightbox(i);
    thumbs.appendChild(wrap);
  });
}

// ============================================================
// BIG STATUS
// ============================================================
function showBigStatus(counter, text) {
  const el = $('bigStatus');
  $('bigCounter').textContent = counter;
  $('bigText').textContent = text;
  el.classList.add('on');
}
function hideBigStatus() {
  $('bigStatus').classList.remove('on');
}
function updateCamFloat(text, busy) {
  const el = $('camFloatStatus');
  if (!el) return;
  el.classList.toggle('busy', !!busy);
  const t = $('camFloatText');
  if (t) t.textContent = text;
}

// ============================================================
// GRID
// ============================================================
function buildGrid(size) {
  const g = $('camGrid');
  g.className = 'cam-grid-overlay';
  g.dataset.size = size;
  g.innerHTML = '';
  const total = size * size;
  for (let i = 0; i < total; i++) {
    const c = document.createElement('div');
    c.className = 'cam-zone';
    c.dataset.idx = i;
    c.dataset.num = String(i + 1);
    g.appendChild(c);
  }
}

// ============================================================
// MOD SWITCH
// ============================================================
function switchMode(mode) {
  state.mode = mode;
  ['btnModeFocus','btnModeFixed','btnModePOI','btnModeManual'].forEach(id => {
    const el = $(id);
    if (!el) return;
    const m = id.replace('btnMode','').toLowerCase();
    el.classList.toggle('active', mode === m);
  });
  $('camModeBadge').textContent = mode === 'focusSweep' ? '🎯 ADAPTİF' : mode === 'fixed' ? '📸 SABİT' : mode === 'poiGrid' ? '👆 POI' : '✋ MANUEL';
  $('camShutterBtn').textContent = mode === 'manual' ? '📸 Çek' : '🚀 Başlat';
  if (mode === 'poiGrid') buildGrid(3);
}

// ============================================================
// EVENT LISTENERS
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  $('openCamBtn')?.addEventListener('click', openCamera);
  $('camCloseBtn')?.addEventListener('click', closeCamera);

  $('camShutterBtn')?.addEventListener('click', () => {
    if (state.busy) return;
    if (state.mode === 'focusSweep') runAdaptiveSweep();
    else if (state.mode === 'fixed') runFixedStack();
    else if (state.mode === 'poiGrid') runPOIGrid();
    else runManual();
  });

  $('camSweepBtn')?.addEventListener('click', () => {
    if (state.busy) state.stackStop = true;
    else runAdaptiveSweep();
  });

  $('btnModeFocus')?.addEventListener('click', () => switchMode('focusSweep'));
  $('btnModeFixed')?.addEventListener('click', () => switchMode('fixed'));
  $('btnModePOI')?.addEventListener('click', () => switchMode('poiGrid'));
  $('btnModeManual')?.addEventListener('click', () => switchMode('manual'));

  $('camSettingsBtn')?.addEventListener('click', () => $('camDrawer').classList.toggle('open'));
  $('camDrawerClose')?.addEventListener('click', () => $('camDrawer').classList.remove('open'));
  $('camRefreshCaps')?.addEventListener('click', () => renderCapPanel());

  $('camTorchBtn')?.addEventListener('click', async () => {
    if (!state.track || !state.supported.torch) return;
    const on = !$('camTorchBtn').classList.contains('on');
    try {
      await state.track.applyConstraints({ advanced: [{ torch: on }] });
      $('camTorchBtn').classList.toggle('on', on);
    } catch (e) {}
  });

  $('mpClearBtn')?.addEventListener('click', () => {
    if (!confirm('Tüm kareleri sil?')) return;
    state.shots.forEach(s => { try { URL.revokeObjectURL(s.url); } catch(_){} });
    state.shots = [];
    renderMainThumbs();
    renderMiniThumbs();
    updateCounters();
  });

  $('camConfirmCancel')?.addEventListener('click', () => {
    $('camConfirm').classList.remove('open');
  });
  $('camConfirmOK')?.addEventListener('click', () => {
    $('camConfirm').classList.remove('open');
  });
});

// ============================================================
// UPLOAD
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  $('uploadBtn')?.addEventListener('click', async () => {
    const sku = ($('skuInput')?.value || '').trim();
    if (!sku) { alert('SKU girin'); $('skuInput')?.focus(); return; }
    if (state.shots.length < 2) { alert('En az 2 kare gerekli'); return; }

    const method = $('methodSel')?.value || 'pyramid';
    const bitdepth = $('bitdepthSel')?.value || '8';

    const btn = $('uploadBtn');
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Yükleniyor…';

    const fd = new FormData();
    fd.append('sku', sku);
    fd.append('user_ref', 'web_camera_v5');
    fd.append('method', method);
    fd.append('bitdepth', bitdepth);
    state.shots.forEach((s, i) => {
      fd.append('images[]', s.blob, `shot_${String(i+1).padStart(3,'0')}.jpg`);
    });

    try {
      const res = await fetch(API_BASE + '/jobs-create.php', { method: 'POST', body: fd });
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); }
      catch { throw new Error('Sunucu JSON dönmedi: ' + raw.substring(0, 200)); }
      if (!data.success) throw new Error(data.error || 'Bilinmeyen hata');

      state.currentJobId = data.job_id;
      showToast(`✅ İş #${data.job_id} kuyruğa eklendi (${data.count || data.file_count || state.shots.length} kare)`);

      state.shots.forEach(s => { try { URL.revokeObjectURL(s.url); } catch(_){} });
      state.shots.length = 0;
      renderMainThumbs();

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

  $('approveBtn')?.addEventListener('click', async () => {
    if (!state.currentJobId) return;
    const fd = new FormData();
    fd.append('job_id', state.currentJobId);
    const res = await fetch(API_BASE + '/jobs-approve.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) { alert('Onaylandı'); location.reload(); }
    else alert('Hata: ' + (data.error || ''));
  });
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
    } catch (e) {}
  }, 2000);
}

// ============================================================
// PINCH-TO-ZOOM
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  const stage = $('camStageInner');
  const indicator = $('camZoomIndicator');
  if (!stage) return;

  function showZoomIndicator(z) {
    if (!indicator) return;
    indicator.textContent = Number(z).toFixed(1) + '×';
    indicator.classList.add('on');
    clearTimeout(window.__zoomIndTimer);
    window.__zoomIndTimer = setTimeout(() => { indicator.classList.remove('on'); }, 1200);
  }

  async function applyZoom(z) {
    z = Math.max(1, Math.min(8, z));
    state.zoomLevel = z;
    if (state.track && state.supported.zoom) {
      try { await state.track.applyConstraints({ advanced: [{ zoom: z }] }); } catch (_) {}
    }
    showZoomIndicator(z);
  }

  stage.addEventListener('touchstart', (e) => {
    if (e.touches.length === 2) {
      e.preventDefault();
      state.pinchActive = true;
      const t1 = e.touches[0], t2 = e.touches[1];
      state.pinchStartDist = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
      state.pinchStartZoom = state.zoomLevel;
    }
  }, { passive: false });

  stage.addEventListener('touchmove', (e) => {
    if (e.touches.length === 2 && state.pinchActive) {
      e.preventDefault();
      const t1 = e.touches[0], t2 = e.touches[1];
      const dist = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
      if (state.pinchStartDist > 0) {
        applyZoom(state.pinchStartZoom * (dist / state.pinchStartDist));
      }
    }
  }, { passive: false });

  stage.addEventListener('touchend', (e) => {
    if (e.touches.length < 2) { state.pinchActive = false; state.pinchStartDist = 0; }
  }, { passive: true });

  stage.addEventListener('touchcancel', () => { state.pinchActive = false; state.pinchStartDist = 0; });
});

window.addEventListener('beforeunload', () => {
  if (state.pollInterval) clearInterval(state.pollInterval);
  if (state.stream) state.stream.getTracks().forEach(t => t.stop());
  state.shots.forEach(s => { try { URL.revokeObjectURL(s.url); } catch(_){} });
});

document.addEventListener('DOMContentLoaded', () => {
  renderMainThumbs();
  switchMode('focusSweep');
});

})();
</script>
</body>
</html>