<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/db.php';

$db = getDB();

// 1) Hatalı 'stacked' kayıtlarını sil (varsa)
try {
    $n = $db->exec("DELETE FROM job_files WHERE role = 'stacked'");
    echo "Silinen 'stacked' kayıt: $n\n";
} catch (Throwable $e) {
    echo "stacked temizlik: " . $e->getMessage() . "\n";
}

// 2) Başarısız işleri sıfırla
$stmt = $db->prepare("
    UPDATE jobs 
    SET status='queued', stage='uploaded', progress=0, 
        error_msg=NULL, locked_at=NULL, locked_by=NULL
    WHERE status IN ('failed','processing')
");
$stmt->execute();
echo "Sıfırlanan iş: " . $stmt->rowCount() . "\n";

// 3) Her iş için job_files kayıtlarını göster
$jobs = $db->query("SELECT id, status, stage FROM jobs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "\nİşler:\n";
foreach ($jobs as $j) {
    $cnt = $db->prepare("SELECT role, COUNT(*) AS n FROM job_files WHERE job_id = ? GROUP BY role");
    $cnt->execute([$j['id']]);
    $roles = $cnt->fetchAll(PDO::FETCH_ASSOC);
    $roleStr = implode(', ', array_map(fn($r) => "{$r['role']}({$r['n']})", $roles));
    printf("  #%d  status=%-14s  stage=%-10s  files: %s\n",
        $j['id'], $j['status'], $j['stage'] ?? '-', $roleStr ?: 'yok');
}