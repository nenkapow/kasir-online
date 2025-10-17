<?php
require __DIR__ . '/_init.php';
header('Content-Type: text/plain; charset=utf-8');

function ensureColumn(PDO $pdo, string $table, string $column, string $ddl) {
  $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$column]);
  $exists = (int)$st->fetchColumn() > 0;
  if ($exists) { echo "SKIP: $table.$column sudah ada.\n"; return; }
  $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
  echo "OK  : Tambah kolom $table.$column ($ddl)\n";
}

try {
  $pdo = db();

  // Tambahkan kolom subtotal di tabel detail transaksi
  ensureColumn($pdo, 'sales_items', 'subtotal', 'INT NOT NULL DEFAULT 0 AFTER price');

  echo "\nSelesai. Kamu boleh hapus file ini (api/migrate_items.php).";
} catch (Throwable $e) {
  http_response_code(500);
  echo "Gagal: " . $e->getMessage();
}
