<?php
// api/product_save.php
// Tambah / Edit produk.
// - Insert: boleh set stock awal (opsional).
// - Update: stok TIDAK diubah (stok hanya lewat pembelian).
// - sell_price bisa diubah; cost_price tetap dari pembelian.
// - barcode opsional, unik (boleh NULL/kosong).

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

/** Ambil body JSON bila Content-Type: application/json */
function get_json_body(): ?array {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
      $j = json_decode($raw, true);
      if (is_array($j)) return $j;
    }
  }
  return null;
}

try {
  $pdo = db();

  // Terima data dari JSON atau FormData/x-www-form-urlencoded
  $src  = get_json_body() ?? $_POST;

  $id        = isset($src['id']) ? trim((string)$src['id']) : '';
  $sku       = trim((string)($src['sku']  ?? ''));
  $name      = trim((string)($src['name'] ?? ''));
  // Back-compat: kalau UI lama kirim 'price', anggap itu sell_price
  $sellPrice = (float)($src['sell_price'] ?? ($src['price'] ?? 0));
  // stok hanya dipakai saat INSERT
  $stock     = isset($src['stock']) ? (int)$src['stock'] : 0;
  // barcode opsional; kosong -> NULL
  $barcode   = $src['barcode'] ?? null;
  $barcode   = is_string($barcode) ? trim($barcode) : null;
  if ($barcode === '') $barcode = null;
  if ($barcode !== null && strlen($barcode) > 64) {
    throw new Exception('Barcode maksimal 64 karakter.');
  }

  if ($sku === '' || $name === '') {
    throw new Exception('SKU dan Nama wajib diisi.');
  }
  if ($sellPrice < 0) {
    throw new Exception('Harga jual tidak boleh negatif.');
  }
  if ($stock < 0) {
    throw new Exception('Stok awal tidak boleh negatif.');
  }

  // === INSERT ===
  if ($id === '' || $id === '0') {
    // SKU unik?
    $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
    $cek->execute([$sku]);
    if ((int)$cek->fetchColumn() > 0) {
      throw new Exception('SKU sudah digunakan.');
    }

    // Barcode unik (jika ada)
    if ($barcode !== null) {
      $cekB = $pdo->prepare("SELECT COUNT(*) FROM products WHERE barcode = ?");
      $cekB->execute([$barcode]);
      if ((int)$cekB->fetchColumn() > 0) {
        throw new Exception('Barcode sudah dipakai produk lain.');
      }
    }

    // Catatan: kolom legacy "price" tetap diisi = sell_price
    // cost_price awal = 0 (nanti diupdate dari pembelian)
    $stmt = $pdo->prepare("
      INSERT INTO products (sku, barcode, name, price, sell_price, cost_price, stock)
      VALUES (?, ?, ?, ?, ?, 0, ?)
    ");
    $stmt->execute([$sku, $barcode, $name, $sellPrice, $sellPrice, $stock]);

    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // === UPDATE (tanpa ubah stok/cost_price) ===
  $id = (int)$id;

  // SKU unik untuk selain dirinya
  $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?");
  $cek->execute([$sku, $id]);
  if ((int)$cek->fetchColumn() > 0) {
    throw new Exception('SKU sudah digunakan oleh produk lain.');
  }

  // Barcode unik (boleh NULL)
  if ($barcode !== null) {
    $cekB = $pdo->prepare("SELECT COUNT(*) FROM products WHERE barcode = ? AND id <> ?");
    $cekB->execute([$barcode, $id]);
    if ((int)$cekB->fetchColumn() > 0) {
      throw new Exception('Barcode sudah dipakai produk lain.');
    }
  }

  $stmt = $pdo->prepare("
    UPDATE products
       SET sku = ?,
           barcode = ?,
           name = ?,
           price = ?,       -- legacy
           sell_price = ?   -- harga jual aktif
     WHERE id = ?
  ");
  $stmt->execute([$sku, $barcode, $name, $sellPrice, $sellPrice, $id]);

  echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
