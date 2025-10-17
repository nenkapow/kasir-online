<?php
// api/migrate.php
require __DIR__ . '/_init.php';

header('Content-Type: text/plain; charset=utf-8');

function ensureColumn(PDO $pdo, string $table, string $column, string $ddl) {
  // cek apakah kolom sudah ada
  $sql = "SELECT COUNT(*)
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = :t
            AND COLUMN_NAME  = :c";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$column]);
  $exists = (int)$st->fetchColumn() > 0;

  if ($exists) {
    echo "SKIP: Kolom `$column` sudah ada di `$table`.\n";
    return;
  }

  // tambah kolom (ddl = definisi kolom saja, tanpa kata 'ADD COLUMN')
  $ddlSql = "ALTER TABLE `$table` ADD COLUMN $ddl";
  $pdo->exec($ddlSql);
  echo "OK  : Tambah kolom `$column` -> $ddl\n";
}

try {
  $pdo = db();

  // pastikan tabel `sales` ada
  $chk = $pdo->query("SHOW TABLES LIKE 'sales'")->fetch();
  if (!$chk) {
    throw new Exception("Tabel `sales` tidak ditemukan. Pastikan instalasi schema sudah jalan.");
  }

  // Tambahkan kolom-kolom yang dibutuhkan checkout
  ensureColumn($pdo, 'sales', 'method',
    "method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total");

  ensureColumn($pdo, 'sales', 'amount_paid',
    "amount_paid INT NOT NULL DEFAULT 0 AFTER method");

  ensureColumn($pdo, 'sales', 'change_amount',
    "change_amount INT NOT NULL DEFAULT 0 AFTER amount_paid");

  echo "\nSelesai. Kamu boleh hapus file ini (api/migrate.php).";
} catch (Throwable $e) {
  http_response_code(500);
  echo "Gagal: " . $e->getMessage();
}
