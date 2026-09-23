<?php
/**
 * fix-schema.php — job_files.role ve audit_log.action sütunlarını düzeltir
 * SADECE BİR KEZ çalıştırın, sonra silin.
 */
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/db.php';

$db = getDB();

function showColumn(PDO $db, string $table, string $column) {
    $s = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$column]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        printf("  %s.%s → %s | Null: %s | Default: %s\n",
            $table, $column, $row['Type'], $row['Null'], $row['Default'] ?? 'NULL');
    } else {
        printf("  %s.%s → SÜTUN YOK!\n", $table, $column);
    }
}

echo "=== MEVCUT DURUM ===\n";
showColumn($db, 'job_files', 'role');
showColumn($db, 'audit_log', 'action');
echo "\n";

// ============================================================
// 1) job_files.role sütununu düzelt
// ============================================================
echo "=== DÜZELTME 1: job_files.role ===\n";
try {
    $db->exec("ALTER TABLE `job_files` 
               MODIFY COLUMN `role` VARCHAR(32) NOT NULL");
    echo "✓ role VARCHAR(32) yapıldı\n";
} catch (Throwable $e) {
    echo "✗ Hata: " . $e->getMessage() . "\n";
}

// ============================================================
// 2) audit_log.action sütununu düzelt
// ============================================================
echo "\n=== DÜZELTME 2: audit_log.action ===\n";
try {
    $db->exec("ALTER TABLE `audit_log` 
               MODIFY COLUMN `action` VARCHAR(255) NOT NULL");
    echo "✓ action VARCHAR(255) yapıldı\n";
} catch (Throwable $e) {
    echo "✗ Hata: " . $e->getMessage() . "\n";
}

// ============================================================
// 3) Diğer sütunları da güvenceye alalım
// ============================================================
echo "\n=== DÜZELTME 3: Diğer sütunlar ===\n";

// jobs.status ve stage daha uzun olabilir
try {
    $db->exec("ALTER TABLE `jobs`
               MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
               MODIFY COLUMN `stage`  VARCHAR(32) NULL");
    echo "✓ jobs.status ve jobs.stage genişletildi\n";
} catch (Throwable $e) {
    echo "✗ jobs: " . $e->getMessage() . "\n";
}

// job_files.path TEXT olsun (uzun yollar için)
try {
    $db->exec("ALTER TABLE `job_files`
               MODIFY COLUMN `path` VARCHAR(512) NOT NULL");
    echo "✓ job_files.path VARCHAR(512) yapıldı\n";
} catch (Throwable $e) {
    echo "✗ job_files.path: " . $e->getMessage() . "\n";
}

// audit_log.user_ref VARCHAR olsun
try {
    $db->exec("ALTER TABLE `audit_log`
               MODIFY COLUMN `user_ref` VARCHAR(64) NULL");
    echo "✓ audit_log.user_ref genişletildi\n";
} catch (Throwable $e) {
    // Sütun yoksa sorun değil
    echo "  (audit_log.user_ref yok veya zaten uygun)\n";
}

// ============================================================
// 4) Son durum
// ============================================================
echo "\n=== YENİ DURUM ===\n";
showColumn($db, 'job_files', 'role');
showColumn($db, 'audit_log', 'action');
showColumn($db, 'jobs', 'status');
showColumn($db, 'jobs', 'stage');

// ============================================================
// 5) Test kayıtları ekle
// ============================================================
echo "\n=== TEST ===\n";
try {
    $db->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (999999, 'stacked', '/test/path.jpg', 0)")->execute();
    echo "✓ role='stacked' insert OK\n";
} catch (Throwable $e) {
    echo "✗ role='stacked' hata: " . $e->getMessage() . "\n";
}
try {
    $db->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (999999, 'preview', '/test/path.jpg', 0)")->execute();
    echo "✓ role='preview' insert OK\n";
} catch (Throwable $e) {
    echo "✗ role='preview' hata: " . $e->getMessage() . "\n";
}

// Test kayıtlarını temizle
$db->exec("DELETE FROM job_files WHERE job_id = 999999");

echo "\n=== BİTTİ ===\n";
echo "Bu dosyayı şimdi silin: fix-schema.php\n";