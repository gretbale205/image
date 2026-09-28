<?php
/**
 * admin-worker.php — Manuel worker tetikleme paneli
 *
 * Worker çalıştırma işi index.php'ye delege edilir:
 *   POST index.php { csrf, action: 'worker_spawn', back: '/admin-worker.php' }
 *
 * NOT: index.php worker'ı arka planda (async) spawn ediyorsa,
 *      bu sayfa artık işin BİTMESİNİ beklemez; sadece tetikler.
 *      Gerçek zamanlı ilerleme için index.php'nin iş listesine bakın.
 */
header('Content-Type: text/html; charset=utf-8');

// CSRF token: önce cookie'den dene (yaygın isimler), yoksa boş
$csrfToken = $_COOKIE['csrf_token']
          ?? $_COOKIE['csrf']
          ?? $_COOKIE['XSRF-TOKEN']
          ?? '';
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
        <p class="text-xs text-gray-400">
            Worker <span class="text-yellow-400">index.php?action=worker_spawn</span> üzerinden tetiklenir.
        </p>
    </div>

    <div class="bg-gray-800 rounded-lg p-5 space-y-3">
        <button id="runBtn"
                class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-lg text-lg">
            ▶ Worker'ı Tetikle
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
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;

const out      = document.getElementById('output');
const timerEl  = document.getElementById('timer');
let timerInt   = null;

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
    if (!CSRF_TOKEN) {
        out.textContent = 'HATA: CSRF token bulunamadı (cookie okunamadı).';
        return;
    }

    out.textContent = '[tetikleniyor] ' + new Date().toLocaleTimeString();
    const t0 = Date.now();
    setTimer(t0);

    try {
        const body = new URLSearchParams({
            csrf:   CSRF_TOKEN,
            action: 'worker_spawn',
            back:   '/admin-worker.php'
        });

        const res = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            // index.php form POST sonrası redirect edebilir; takip et
            redirect: 'follow',
            credentials: 'same-origin'
        });

        const text = await res.text();
        out.textContent += '\n[HTTP ' + res.status + ']\n';
        // HTML dönebileceği için ilk 2000 karakterle sınırla
        out.textContent += text.length > 2000 ? text.slice(0, 2000) + '\n…(kısaltıldı)' : text;
    } catch (e) {
        out.textContent += '\nHATA: ' + e.message;
    } finally {
        stopTimer();
        out.textContent += '\n\n[toplam: ' + ((Date.now() - t0) / 1000).toFixed(1) + ' sn]';
    }
});

document.getElementById('statusBtn').addEventListener('click', async () => {
    try {
        const res  = await fetch('api/jobs-list.php', { credentials: 'same-origin' });
        const text = await res.text();
        out.textContent = '[HTTP ' + res.status + ']\n' + text;
    } catch (e) {
        out.textContent = 'HATA: ' + e.message;
    }
});
</script>
</body>
</html>