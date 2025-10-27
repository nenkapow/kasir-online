<?php
declare(strict_types=1);

/**
 * Jalankan sekali lewat browser untuk menambah kolom yang hilang
 * tanpa CMD/phpMyAdmin. Akan:
 * - Tambah products.barcode (VARCHAR(64) NULL, UNIQUE)
 * - Tambah sales.invoice_code (VARCHAR(32) NOT NULL, UNIQUE)
 */

require_once __DIR__ . '/_init.php';

// optional: butuh PIN kalau APP_REQUIRE_PIN=1
require_auth();

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $column]);
  return (int)$st->fetchColumn() > 0;
}

function hasIndex(PDO $pdo, string $table, string $index): bool {
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $index]);
  return (int)$st->fetchColumn() > 0;
}

$changes = [];

/* 1) products.barcode */
if (!hasColumn($pdo, 'products', 'barcode')) {
  // kolom nullable supaya fleksibel; nanti diisi/diupdate dari UI
  $pdo->exec("ALTER TABLE products ADD COLUMN barcode VARCHAR(64) NULL AFTER sku");
  $changes[] = 'ADD products.barcode';
}
if (!hasIndex($pdo, 'products', 'uniq_products_barcode')) {
  try {
    $pdo->exec("CREATE UNIQUE INDEX uniq_products_barcode ON products (barcode)");
    $changes[] = 'INDEX uniq_products_barcode';
  } catch (Throwable $e) {
    // kalau ada NULL/duplikat lama, UNIQUE bisa gagal—abaikan dulu.
  }
}

/* 2) sales.invoice_code — diperlukan saat checkout (error kamu barusan) */
if (!hasColumn($pdo, 'sales', 'invoice_code')) {
  $pdo->exec("ALTER TABLE sales ADD COLUMN invoice_code VARCHAR(32) NOT NULL AFTER id");
  $changes[] = 'ADD sales.invoice_code';
}
if (!hasIndex($pdo, 'sales', 'uniq_sales_invoice_code')) {
  try {
    $pdo->exec("CREATE UNIQUE INDEX uniq_sales_invoice_code ON sales (invoice_code)");
    $changes[] = 'INDEX uniq_sales_invoice_code';
  } catch (Throwable $e) { /* abaikan kalau sudah ada */ }
}

json(['ok'=>true,'applied'=>$changes]);
