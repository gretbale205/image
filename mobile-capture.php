<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Focus Stacking - Fotoğraf Çekimi</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  body { -webkit-tap-highlight-color: transparent; }
  .shot-thumb { aspect-ratio: 1/1; object-fit: cover; }
</style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">

<div class="max-w-md mx-auto p-4 space-y-4">

  <header class="text-center py-3">
    <h1 class="text-xl font-bold">Odak Fotoğrafları</h1>
    <p class="text-xs text-gray-400 mt-1">Aynı nesneye farklı odaklarda çekim yapın</p>
  </header>

  <!-- SKU -->
  <div class="bg-gray-800 rounded-xl p-4 space-y-3">
    <label class="block text-sm font-medium text-gray-300">SKU / Ürün Kodu</label>
    <input type="text" id="sku" placeholder="URUN-12345"
           class="w-full px-4 py-3 bg-gray-700 rounded-lg border border-gray-600 focus:border-blue-500 focus:outline-none text-base">
  </div>

  <!-- Çekim Kılavuzu -->
  <div class="bg-blue-900/40 border border-blue-700 rounded-xl p-3 text-xs text-blue-200 space-y-1">
    <p class="font-semibold text-blue-100">📸 Nasıl Çekilir?</p>
    <p>1. Telefonu sabit tutun (tripod ideal).</p>
    <p>2. Ekranda <b>en yakın</b> noktaya dokunun → çek.</p>
    <p>3. Biraz daha <b>uzağa</b> dokunun → çek.</p>
    <p>4. En <b>uzak</b> noktaya dokunun → çek.</p>
    <p>5. En az <b>5–10 kare</b> önerilir.</p>
  </div>

  <!-- Çekim Butonu -->
  <button id="captureBtn"
          class="w-full bg-blue-600 active:bg-blue-700 text-white font-semibold py-5 rounded-2xl text-lg shadow-lg">
    📷 Fotoğraf Çek (<span id="count">0</span>)
  </button>

  <input type="file" id="cameraInput" accept="image/*" capture="environment" class="hidden">

  <!-- Çekilenler -->
  <div id="shotsContainer" class="grid grid-cols-3 gap-2"></div>

  <!-- Yükleme Butonu -->
  <button id="uploadBtn" disabled
          class="w-full bg-green-600 disabled:bg-gray-700 disabled:text-gray-500 text-white font-semibold py-4 rounded-2xl">
    Yükle ve İşlemi Başlat
  </button>

  <!-- Durum -->
  <div id="statusBox" class="hidden bg-gray-800 rounded-xl p-4 space-y-3">
    <div class="flex justify-between items-center">
      <span class="text-xs text-gray-400">İş No</span>
      <span id="jobIdDisplay" class="font-mono text-sm">--</span>
    </div>
    <div class="w-full bg-gray-700 rounded-full h-2">
      <div id="progressBar" class="bg-blue-500 h-2 rounded-full transition-all" style="width:0%"></div>
    </div>
    <div class="flex justify-between text-xs">
      <span id="stageText" class="text-gray-400">Bekleniyor...</span>
      <span id="progressText" class="text-gray-400">0%</span>
    </div>
  </div>

  <!-- Sonuç -->
  <div id="resultBox" class="hidden bg-gray-800 rounded-xl p-4 space-y-3">
    <p class="text-sm font-semibold text-green-400">✓ Önizleme Hazır</p>
    <img id="previewImage" class="w-full rounded-lg" alt="Önizleme">
    <button id="approveBtn" class="w-full bg-green-600 text-white font-semibold py-3 rounded-xl">
      Onayla ve Temizle
    </button>
  </div>

</div>

<script>
const API_BASE = (() => {
  const p = location.pathname;
  return p.substring(0, p.lastIndexOf('/')) + '/api';
})();

let shots = [];       // { file, url }
let currentJobId = null;
let pollInterval = null;

const captureBtn   = document.getElementById('captureBtn');
const cameraInput  = document.getElementById('cameraInput');
const shotsCont    = document.getElementById('shotsContainer');
const countSpan    = document.getElementById('count');
const uploadBtn    = document.getElementById('uploadBtn');

captureBtn.addEventListener('click', () => cameraInput.click());

cameraInput.addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (!file) return;
  shots.push({ file, url: URL.createObjectURL(file) });
  renderShots();
  // Aynı dosyayı tekrar seçebilmek için input'u sıfırla
  cameraInput.value = '';
});

function renderShots() {
  countSpan.innerText = shots.length;
  uploadBtn.disabled = shots.length < 2;
  shotsCont.innerHTML = '';
  shots.forEach((s, i) => {
    const wrap = document.createElement('div');
    wrap.className = 'relative';
    wrap.innerHTML = `
      <img src="${s.url}" class="shot-thumb w-full rounded-lg border border-gray-600">
      <span class="absolute top-1 left-1 bg-black/70 text-white text-xs px-1.5 py-0.5 rounded">${i+1}</span>
      <button data-idx="${i}" class="del-btn absolute top-1 right-1 bg-red-600 text-white text-xs w-6 h-6 rounded-full">✕</button>
    `;
    shotsCont.appendChild(wrap);
  });
  document.querySelectorAll('.del-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      shots.splice(parseInt(btn.dataset.idx), 1);
      renderShots();
    });
  });
}

uploadBtn.addEventListener('click', async () => {
  const sku = document.getElementById('sku').value.trim();
  if (!sku) { alert('SKU girin'); return; }
  if (shots.length < 2) { alert('En az 2 fotoğraf gerekli'); return; }

  uploadBtn.disabled = true;
  uploadBtn.innerText = 'Yükleniyor...';

  const fd = new FormData();
  fd.append('sku', sku);
  shots.forEach((s, i) => {
    // Orijinal dosya adını sıralı hale getir
    fd.append('images[]', s.file, `shot_${String(i+1).padStart(3,'0')}.jpg`);
  });

  try {
    const res = await fetch(API_BASE + '/jobs-create.php', { method: 'POST', body: fd });
    const raw = await res.text();
    let data;
    try { data = JSON.parse(raw); }
    catch { throw new Error('Sunucu JSON dönmedi: ' + raw.substring(0,200)); }

    if (!data.success) throw new Error(data.error || 'Bilinmeyen hata');

    currentJobId = data.job_id;
    document.getElementById('statusBox').classList.remove('hidden');
    document.getElementById('jobIdDisplay').innerText = '#' + currentJobId;
    startPolling(currentJobId);

  } catch (err) {
    alert('Hata: ' + err.message);
    uploadBtn.disabled = false;
    uploadBtn.innerText = 'Yükle ve İşlemi Başlat';
  }
});

function startPolling(jobId) {
  if (pollInterval) clearInterval(pollInterval);
  pollInterval = setInterval(async () => {
    try {
      const res = await fetch(API_BASE + '/jobs-status.php?job_id=' + jobId);
      const data = await res.json();
      if (data.error) return;

      document.getElementById('stageText').innerText = data.stage || 'İşleniyor';
      document.getElementById('progressText').innerText = data.progress + '%';
      document.getElementById('progressBar').style.width = data.progress + '%';

      if (data.status === 'preview_ready') {
        clearInterval(pollInterval);
        document.getElementById('resultBox').classList.remove('hidden');
        document.getElementById('previewImage').src =
          data.preview_url + (data.preview_url.includes('?') ? '&' : '?') + 't=' + Date.now();
        uploadBtn.innerText = 'Tamamlandı';
      } else if (data.status === 'failed') {
        clearInterval(pollInterval);
        alert('İşlem başarısız: ' + (data.error_msg || 'Bilinmeyen hata'));
        uploadBtn.disabled = false;
        uploadBtn.innerText = 'Yükle ve İşlemi Başlat';
      }
    } catch (e) { console.error(e); }
  }, 2000);
}

document.getElementById('approveBtn').addEventListener('click', async () => {
  if (!currentJobId) return;
  const fd = new FormData();
  fd.append('job_id', currentJobId);
  const res = await fetch(API_BASE + '/jobs-approve.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    alert('Onaylandı');
    location.reload();
  } else {
    alert('Hata: ' + data.error);
  }
});
</script>
</body>
</html>