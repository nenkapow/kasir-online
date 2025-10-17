<?php
// Installer aman-ulang (idempotent)
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

try {
  $pdo->beginTransaction();

  // Matikan pengecekan FK sementara
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

  // PRODUCTS
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS products (
      id INT AUTO_INCREMENT PRIMARY KEY,
      sku VARCHAR(64) UNIQUE,
      name VARCHAR(200) NOT NULL,
      price INT NOT NULL DEFAULT 0,
      stock INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // SALES (dibuat sebelum sale_items)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sales (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      total INT NOT NULL DEFAULT 0,
      payment_method VARCHAR(32) DEFAULT 'cash',
      note VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // SALE_ITEMS (FK ke sales & products)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sale_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      sale_id BIGINT NOT NULL,
      product_id INT NOT NULL,
      qty INT NOT NULL DEFAULT 1,
      price INT NOT NULL DEFAULT 0,
      CONSTRAINT fk_si_sale  FOREIGN KEY (sale_id)   REFERENCES sales(id)    ON DELETE CASCADE,
      CONSTRAINT fk_si_prod  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // Index tambahan
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_name ON products(name)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sales_created ON sales(created_at)");

  // Aktifkan lagi FK check
  $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

  $pdo->commit();
  echo "OK: Struktur tabel siap. Demi keamanan, hapus file api/install.php ya.";
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Gagal: ".$e->getMessage();
}
