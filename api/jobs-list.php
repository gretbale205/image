<?php
/**
 * jobs-list.php — Tüm işleri listeler
 */
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

$db = getDB();
$jobs = $db->query("
    SELECT id, sku, status, stage, progress, file_count, 
           error_msg, created_at, updated_at
    FROM jobs 
    ORDER BY id DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

echo "═══════════════════════════════════════════════════\n";
echo " İŞLER (son 50)\n";
echo "═══════════════════════════════════════════════════\n\n";

foreach ($jobs as $j) {
    printf("#%-4d  %-14s  %-12s  %3d%%  %s\n",
        $j['id'],
        $j['status'],
        $j['stage'] ?? '-',
        $j['progress'],
        $j['sku']
    );
    if ($j['error_msg']) {
        printf("       ⚠ %s\n", substr($j['error_msg'], 0, 100));
    }
}

// Kuyrukta bekleyen
$queued = $db->query("SELECT COUNT(*) FROM jobs WHERE status='queued'")->fetchColumn();
echo "\n═══════════════════════════════════════════════════\n";
echo " Kuyrukta bekleyen: $queued iş\n";
echo "═══════════════════════════════════════════════════\n";