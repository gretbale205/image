<?php
/**
 * admin-worker.php — Manuel worker tetikleme paneli
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Worker Kontrol Paneli</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen p-6 font-mono">
<div class="max-w-4xl mx-auto space-y-4">

    <div class="bg-gray-800 rounded-lg p-5">
        <h1 class="text-xl font-bold mb-1">Worker Kontrol</h1>
        <p class="text-xs text-gray-400">Kuyruktaki işleri manuel tetikleyin.</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-5 space-y-3">
        <button id="runBtn"
                class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-lg text-lg">
            ▶ Kuyruğu İşle
        </button>
        <button id="statusBtn"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg">
            🔄 İş Durumlarını Göster
        </button>
    </div>

    <div class="bg-black rounded-lg p-4 border border-gray-700">
        <div class="flex justify-between items-center mb-2">
            <span class="text-xs text-gray-500 uppercase">Çıktı</span>
            <span id="timer" class="text-xs text-gray-500"></span>
        </div>
        <pre id="output" class="text-xs text-green-400 whitespace-pre-wrap max-h-96 overflow-auto">Hazır.</pre>
    </div>

</div>

<script>
const out = document.getElementById('output');
const timerEl = document.getElementById('timer');
let timerInt = null;

function log(msg) {
    out.textContent += '\n' + msg;
    out.scrollTop = out.scrollHeight;
}

function setTimer(startTime) {
    if (timerInt) clearInterval(timerInt);
    timerInt = setInterval(() => {
        const s = ((Date.now() - startTime) / 1000).toFixed(1);
        timerEl.textContent = s + ' sn';
    }, 100);
}

function stopTimer() {
    if (timerInt) clearInterval(timerInt);
    timerInt = null;
}

document.getElementById('runBtn').addEventListener('click', async () => {
    out.textContent = '[başlatılıyor] ' + new Date().toLocaleTimeString();
    const t0 = Date.now();
    setTimer(t0);

    try {
        // Worker'ı senkron çalıştır, uzun sürebilir
        const res = await fetch('api/worker-run.php', { method: 'POST' });
        const text = await res.text();
        out.textContent += '\n' + text;
    } catch (e) {
        out.textContent += '\nHATA: ' + e.message;
    } finally {
        stopTimer();
        out.textContent += '\n\n[toplam: ' + ((Date.now() - t0) / 1000).toFixed(1) + ' sn]';
    }
});

document.getElementById('statusBtn').addEventListener('click', async () => {
    try {
        const res = await fetch('api/jobs-list.php');
        const text = await res.text();
        out.textContent = text;
    } catch (e) {
        out.textContent = 'HATA: ' + e.message;
    }
});
</script>
</body>
</html>