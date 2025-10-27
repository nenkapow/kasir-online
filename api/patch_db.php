<?php
declare(strict_types=1);

/**
 * api/patch_db.php
 * Patch DB sekali jalan (idempotent) untuk menambah kolom yang dibutuhkan app:
 * - products.updated_at
 * - products.barcode (unique, nullable)
 * - sales.invoice_code (atau coba di tabel sejenis: transactions / orders)
 *
 * Jalankan SEKALI via browser, lalu hapus file ini.
 */

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

$out = ['ok' => true, 'applied' => [], 'notes' => []];

try {
  $pdo = db();

  // Helpers
  $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();

  $tableExists = function(string $table) use ($pdo, $dbName): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = ? AND table_name = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$dbName, $table]);
    return (bool)$st->fetchColumn();
  };

  $columnExists = function(string $table, string $col) use ($pdo, $dbName): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ? AND column_name = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$dbName, $table, $col]);
    return (bool)$st->fetchColumn();
  };

  // ---- Patch for products ----
  if ($tableExists('products')) {
    // products.barcode
    if (!$columnExists('products', 'barcode')) {
      $pdo->exec("ALTER TABLE products
        ADD COLUMN `barcode` VARCHAR(64) NULL UNIQUE AFTER `sku`");
      $out['applied'][] = 'products.add_barcode';
    }

    // products.updated_at
    if (!$columnExists('products', 'updated_at')) {
      $pdo->exec("ALTER TABLE products
        ADD COLUMN `updated_at` TIMESTAMP NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP");
      $out['applied'][] = 'products.add_updated_at';
    }
  } else {
    $out['notes'][] = 'Table products tidak ditemukan';
  }

  // ---- Patch for sales-like table: invoice_code ----
  $candidates = ['sales', 'transactions', 'orders'];
  $usedTable = null;
  foreach ($candidates as $t) {
    if ($tableExists($t)) { $usedTable = $t; break; }
  }
  if ($usedTable) {
    if (!$columnExists($usedTable, 'invoice_code')) {
      $pdo->exec("ALTER TABLE `{$usedTable}`
        ADD COLUMN `invoice_code` VARCHAR(32) NULL UNIQUE AFTER `id`");
      $out['applied'][] = "{$usedTable}.add_invoice_code";
    }
    $out['notes'][] = "Penjualan terdeteksi di tabel: {$usedTable}";
  } else {
    $out['notes'][] = 'Tabel penjualan tidak ditemukan (sales/transactions/orders).';
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
