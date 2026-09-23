<?php
/**
 * diag.php — DB şema tanılama
 * Geçici dosya, iş bitince silin.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

echo "PHP sürümü: " . PHP_VERSION . "\n";
echo "PDO sürücüleri: " . implode(', ', PDO::getAvailableDrivers()) . "\n\n";

// helpers.php ve db.php yükleniyor mu?
try {
    require_once __DIR__ . '/app/helpers.php';
    echo "✓ helpers.php yüklendi\n";
} catch (Throwable $e) {
    echo "✗ helpers.php hata: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/app/db.php';
    echo "✓ db.php yüklendi\n";
} catch (Throwable $e) {
    echo "✗ db.php hata: " . $e->getMessage() . "\n";
}

// getDB() fonksiyonu var mı?
echo "\ngetDB() var mı? " . (function_exists('getDB') ? 'EVET' : 'HAYIR') . "\n";

if (!function_exists('getDB')) {
    echo "→ db.php içeriğini bana gönderin.\n";
    exit;
}

// Bağlantıyı test et
try {
    $db = getDB();
    echo "✓ getDB() başarılı, sınıf: " . get_class($db) . "\n";
} catch (Throwable $e) {
    echo "✗ getDB() hata: " . $e->getMessage() . "\n";
    echo "Dosya: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

// Veritabanını öğren
try {
    $row = $db->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    echo "Aktif DB: " . ($row['db'] ?? '?') . "\n";
    echo "MySQL sürümü: " . $db->query("SELECT VERSION()")->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "✗ DB bilgisi alınamadı: " . $e->getMessage() . "\n";
}

// Tabloları listele
echo "\n=== TABLOLAR ===\n";
try {
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) echo "  - $t\n";
} catch (Throwable $e) {
    echo "✗ Tablo listesi alınamadı: " . $e->getMessage() . "\n";
    exit;
}

// Her tablonun sütunları
foreach (['jobs', 'job_files', 'audit_log'] as $table) {
    echo "\n=== $table SÜTUNLARI ===\n";
    try {
        $cols = $db->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cols)) {
            echo "  (tablo yok veya sütun yok)\n";
            continue;
        }
        foreach ($cols as $c) {
            printf("  %-20s %-30s null=%-3s default=%s\n",
                $c['Field'], $c['Type'], $c['Null'], $c['Default'] ?? 'NULL');
        }
    } catch (Throwable $e) {
        echo "  ✗ Hata: " . $e->getMessage() . "\n";
    }
}

// ALTER TABLE testi (küçük bir şema değişikliği, geri alacağız)
echo "\n=== ALTER TABLE YETKİ TESTİ ===\n";
try {
    $db->exec("ALTER TABLE `jobs` MODIFY COLUMN `stage` VARCHAR(64) NULL");
    echo "✓ ALTER TABLE yetkisi VAR\n";
} catch (Throwable $e) {
    echo "✗ ALTER TABLE hatası: " . $e->getMessage() . "\n";
}

// job_files.role test insert
echo "\n=== role TEST INSERT ===\n";
try {
    $db->exec("DELETE FROM job_files WHERE job_id = 999998");
    $db->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (999998, 'stacked', '/x.jpg', 0)")->execute();
    echo "✓ role='stacked' çalışıyor\n";
    $db->exec("DELETE FROM job_files WHERE job_id = 999998");
} catch (Throwable $e) {
    echo "✗ role='stacked' hata: " . $e->getMessage() . "\n";
}

try {
    $db->prepare("INSERT INTO job_files (job_id, role, path, size) VALUES (999998, 'preview', '/x.jpg', 0)")->execute();
    echo "✓ role='preview' çalışıyor\n";
    $db->exec("DELETE FROM job_files WHERE job_id = 999998");
} catch (Throwable $e) {
    echo "✗ role='preview' hata: " . $e->getMessage() . "\n";
}

echo "\n=== BİTTİ ===\n";