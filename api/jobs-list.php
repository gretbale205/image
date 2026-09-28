<?php
/**
 * api/worker-list.php — Çalışan worker'ları JSON olarak döner.
 * index.php'ye POST action eklemek yerine bağımsız endpoint.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/config.php';

// Yetki: sadece giriş yapmış kullanıcılar
if (empty($_SESSION['csrf'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz']);
    exit;
}

function rrmdir_recursive_local(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($p) ? rrmdir_recursive_local($p) : @unlink($p);
    }
    @rmdir($dir);
}

function list_workers_api(): array {
    $workers = [];
    $storage = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('STORAGE_ROOT') ? STORAGE_ROOT : '');

    if (!$storage) return $workers;

    // Ana PID dosyası
    $pidFile = $storage . '/worker.pid';
    if (is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid > 0) {
            $workers[] = [
                'pid'     => $pid,
                'running' => is_dir('/proc/' . $pid),
                'source'  => 'worker.pid',
                'started' => @filemtime($pidFile) ?: 0,
            ];
        }
    }

    // worker_pid_*.log dosyaları
    foreach (glob($storage . '/logs/worker_pid_*.log') as $file) {
        if (preg_match('/worker_pid_(\d+)\.log$/', $file, $m)) {
            $pid = (int)$m[1];
            $exists = false;
            foreach ($workers as $w) if ($w['pid'] === $pid) { $exists = true; break; }
            if ($exists) continue;
            $workers[] = [
                'pid'     => $pid,
                'running' => is_dir('/proc/' . $pid),
                'source'  => basename($file),
                'started' => @filemtime($file) ?: 0,
            ];
        }
    }
    return $workers;
}

echo json_encode(['ok' => true, 'workers' => list_workers_api()]);