<?php
require __DIR__ . '/_init.php';
header('Content-Type: text/plain; charset=utf-8');

function tableExists(PDO $pdo, string $table): bool {
  $q = "SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t";
  $st = $pdo->prepare($q);
  $st->execute([':t' => $table]);
  return (int)$st->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
  $q = "SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($q);
  $st->execute([':t' => $table, ':c' => $column]);
  return (int)$st->fetchColumn() > 0;
}

function addColumnSmart(PDO $pdo, string $table, string $column, string $baseDDL, string $afterMaybe = null) {
  if (columnExists($pdo, $table, $column)) {
    echo "SKIP: $table.$column sudah ada.\n";
    return;
  }
  // Kalau after column ada, pakai AFTER; kalau tidak, tambah biasa.
  if ($afterMaybe && columnExists($pdo, $table, $afterMaybe)) {
    $sql = "ALTER TABLE `$table` ADD COLUMN $baseDDL AFTER `$afterMaybe`";
  } else {
    $sql = "ALTER TABLE `$table` ADD COLUMN $baseDDL";
  }
  $pdo->exec($sql);
  echo "OK  : Tambah kolom $table.$column ($baseDDL"
     . ($afterMaybe ? " [after $afterMaybe jika ada]" : "")
     . ")\n";
}

try {
  $pdo = db();

  // Cari tabel detail transaksi yang ada
  $candidates = ['sales_items','sale_items','sales_detail','sale_details','order_items'];
  $detail = null;
  foreach ($candidates as $t) {
    if (tableExists($pdo, $t)) { $detail = $t; break; }
  }
  if (!$detail) {
    echo "Gagal: Tidak menemukan tabel detail transaksi. Coba cek nama tabelmu (sales_items/sales_detail, dsb).\n";
    exit;
  }
  echo "Pakai tabel detail: $detail\n";

  // Tambahkan kolom subtotal (INT), coba AFTER price kalau ada
  addColumnSmart($pdo, $detail, 'subtotal', 'INT NOT NULL DEFAULT 0', 'price');

  echo "\nSelesai. Kamu boleh hapus file ini (api/migrate_items.php).";
} catch (Throwable $e) {
  http_response_code(500);
  echo "Gagal: " . $e->getMessage();
}
