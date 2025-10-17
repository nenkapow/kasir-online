<?php
// api/migrate.php
require __DIR__ . '/_init.php';     // sudah ada di proyek kita

header('Content-Type: text/plain; charset=utf-8');

try {
  $pdo = db();

  // Tambah kolom-kolom yang dibutuhkan checkout (aman dipanggil berulang)
  $sqls = [
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS method        VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS amount_paid   INT         NOT NULL DEFAULT 0      AFTER method",
    "ALTER TABLE sales ADD COLUMN IF NOT EXISTS change_amount INT         NOT NULL DEFAULT 0      AFTER amount_paid",
  ];

  foreach ($sqls as $sql) {
    $pdo->exec($sql);
    echo "OK: $sql\n";
  }

  echo "\nSelesai. Kamu bisa hapus file ini (api/migrate.php).";
} catch (Throwable $e) {
  http_response_code(500);
  echo "Gagal: " . $e->getMessage();
}
