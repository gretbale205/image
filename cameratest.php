<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kamera Test</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;background:#0a0a0c;color:#e5e5e5;padding:16px;font-size:13px;line-height:1.5}
h1{font-size:16px;margin:0 0 12px;color:#fff}
.row{padding:8px 12px;margin-bottom:6px;border-radius:8px;background:#18181b;border-left:3px solid #27272a}
.row.ok{border-left-color:#16a34a}
.row.fail{border-left-color:#dc2626}
.row.warn{border-left-color:#f59e0b}
.row.info{border-left-color:#3b82f6}
.key{color:#a1a1aa;font-size:11px}
.val{color:#fff;font-weight:600;word-break:break-all}
.btn{background:#3b82f6;color:#fff;border:0;padding:14px 20px;border-radius:10px;font-size:15px;font-weight:600;width:100%;margin-top:12px;cursor:pointer}
.btn:active{transform:scale(.98)}
pre{background:#000;padding:12px;border-radius:8px;overflow:auto;font-size:11px;color:#a1a1aa;white-space:pre-wrap;word-break:break-all}
video{width:100%;border-radius:8px;background:#000;display:block;margin-top:12px}
</style>
</head>
<body>
<h1>🔍 Kamera Diagnostic</h1>
<div id="report"></div>

<button class="btn" id="testBtn">Kamerayı Test Et</button>
<video id="preview" autoplay playsinline muted></video>

<script>
(function(){
'use strict';

const report = document.getElementById('report');
const testBtn = document.getElementById('testBtn');
const preview = document.getElementById('preview');

function addRow(cls, key, val) {
  const d = document.createElement('div');
  d.className = 'row ' + cls;
  d.innerHTML = `<div class="key">${key}</div><div class="val">${val || '—'}</div>`;
  report.appendChild(d);
}

// --- ORTAM BİLGİSİ ---
addRow('info', 'URL', location.href);
addRow('info', 'Protocol', location.protocol);
addRow('info', 'Secure Context', window.isSecureContext ? '✅ EVET' : '❌ HAYIR');
addRow('info', 'User Agent', navigator.userAgent);
addRow('info', 'Platform', navigator.platform || '(yok)');
addRow('info', 'Vendor', navigator.vendor || '(yok)');
addRow('info', 'Dil', navigator.language);

// --- API DESTEĞİ ---
const hasMedia = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
addRow(hasMedia ? 'ok' : 'fail', 'navigator.mediaDevices', hasMedia ? '✅ VAR' : '❌ YOK');

const hasGUM = hasMedia && typeof navigator.mediaDevices.getUserMedia === 'function';
addRow(hasGUM ? 'ok' : 'fail', 'getUserMedia()', hasGUM ? '✅ FONKSİYON' : '❌ YOK');

const hasConst = hasMedia && typeof navigator.mediaDevices.getSupportedConstraints === 'function';
addRow(hasConst ? 'ok' : 'fail', 'getSupportedConstraints()', hasConst ? '✅ VAR' : '❌ YOK');

const hasImageCap = typeof window.ImageCapture !== 'undefined';
addRow(hasImageCap ? 'ok' : 'warn', 'ImageCapture', hasImageCap ? '✅ VAR' : '⚠ YOK (canvas fallback)');

const hasPerm = !!navigator.permissions;
addRow(hasPerm ? 'ok' : 'warn', 'navigator.permissions', hasPerm ? '✅ VAR' : '⚠ YOK');

// --- PERMISSIONS API ---
if (navigator.permissions && navigator.permissions.query) {
  navigator.permissions.query({ name: 'camera' })
    .then(p => {
      addRow('info', 'Permission query (camera)', 'State: ' + p.state);
    })
    .catch(e => {
      addRow('warn', 'Permission query (camera)', 'Hata: ' + e.name + ' - ' + e.message);
    });
} else {
  addRow('warn', 'Permission query', 'Permissions API yok');
}

// --- DESTEKLENEN CONSTRAINT'LER ---
if (hasConst) {
  try {
    const sup = navigator.mediaDevices.getSupportedConstraints();
    const wanted = ['focusMode','focusDistance','pointsOfInterest','zoom','torch','exposureMode','whiteBalanceMode','width','height','facingMode'];
    const list = wanted.map(k => `${k}: ${sup[k] ? '✓' : '✗'}`).join('  |  ');
    addRow('info', 'Supported constraints', list);
  } catch (e) {
    addRow('fail', 'getSupportedConstraints hata', e.message);
  }
}

// --- KAMERA TESTİ ---
testBtn.addEventListener('click', async () => {
  testBtn.disabled = true;
  testBtn.textContent = 'Test ediliyor…';

  // Önce enumerateDevices (izin öncesi çalışır ama label boş olur)
  if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
    try {
      const devs = await navigator.mediaDevices.enumerateDevices();
      const cams = devs.filter(d => d.kind === 'videoinput');
      addRow(cams.length ? 'ok' : 'fail', 'Kamera sayısı',
        cams.length ? cams.length + ' cihaz' : '❌ Kamera bulunamadı (izin olmayabilir)');
      cams.forEach((c, i) => {
        addRow('info', `Kamera ${i+1}`, (c.label || '(izin yok - label gizli)') + ' | id: ' + c.deviceId.slice(0, 20));
      });
    } catch (e) {
      addRow('fail', 'enumerateDevices hata', e.name + ': ' + e.message);
    }
  }

  // getUserMedia
  try {
    addRow('info', 'getUserMedia çağrısı', 'Başlatıldı…');
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    });
    addRow('ok', 'getUserMedia sonucu', '✅ BAŞARILI');
    const track = stream.getVideoTracks()[0];
    if (track) {
      const settings = track.getSettings ? track.getSettings() : {};
      const caps = track.getCapabilities ? track.getCapabilities() : {};
      addRow('info', 'Track label', track.label || '(yok)');
      addRow('info', 'Track settings', JSON.stringify(settings).slice(0, 200));
      addRow('info', 'Track capabilities', JSON.stringify(caps).slice(0, 300));
    }
    preview.srcObject = stream;
    await preview.play().catch(() => {});
  } catch (e) {
    addRow('fail', 'getUserMedia HATASI', e.name + ': ' + e.message);
    // Detaylı yorum
    let hint = '';
    if (e.name === 'NotAllowedError') {
      hint = 'Tarayıcı izni reddedilmiş. Kullanıcı izin vermemiş VEYA sistem seviyesinde engellenmiş (Tecno HiOS Privacy Guard).';
    } else if (e.name === 'NotFoundError') {
      hint = 'Kamera cihazı bulunamadı.';
    } else if (e.name === 'NotReadableError' || e.name === 'TrackStartError') {
      hint = 'Kamera başka uygulama tarafından kullanılıyor.';
    } else if (e.name === 'OverconstrainedError') {
      hint = 'İstenen kamera özellikleri desteklenmiyor.';
    } else if (e.name === 'SecurityError') {
      hint = 'Güvenlik hatası. HTTPS gerekli olabilir.';
    }
    addRow('warn', 'Yorum', hint);

    // Permissions API ile izin durumunu tekrar kontrol et
    if (navigator.permissions && navigator.permissions.query) {
      try {
        const p = await navigator.permissions.query({ name: 'camera' });
        addRow('info', 'Permission state (hata sonrası)', p.state);
      } catch (_) {}
    }
  }

  testBtn.textContent = 'Test Tamamlandı';
});

})();
</script>
</body>
</html>