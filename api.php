function triggerWorker() {
    $PROJECT = __DIR__;
    $PYTHON  = $PROJECT . '/worker/venv/bin/python';
    $LOG     = $PROJECT . '/worker_debug.log'; // Logu proje içine aldık, rahatça okuyabilirsiniz

    if (!file_exists($PYTHON)) {
        file_put_contents($LOG, date('[Y-m-d H:i:s] ') . "HATA: Python venv bulunamadı: $PYTHON\n", FILE_APPEND);
        return;
    }

    $homeDir = getenv('HOME') ?: '/home/' . explode('/', trim($PROJECT, '/'))[1];

    // PYTHONPATH ekleyerek Python'un modülü alt dizinde doğru bulmasını sağlıyoruz
    $cmd = sprintf(
        'cd %s && HOME=%s PYTHONPATH=%s PATH=/usr/local/bin:/usr/bin:/bin nohup %s -m worker.worker --once >> %s 2>&1 &',
        escapeshellarg($PROJECT),
        escapeshellarg($homeDir),
        escapeshellarg($PROJECT),
        escapeshellarg($PYTHON),
        escapeshellarg($LOG)
    );

    exec($cmd);
}