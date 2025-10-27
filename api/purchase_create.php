<?php
declare(strict_types=1);

// api/purchase_create.php
// Simpan pembelian + item. Tidak mengisi kolom generated (subtotal).

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

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

  // helpers schema
  $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $colExists = function(string $table, string $col) use ($pdo,$dbName): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=? AND table_name=? AND column_name=?";
    $st = $pdo->prepare($sql);
    $st->execute([$dbName,$table,$col]);
    return (bool)$st->fetchColumn();
  };

  $body = get_json_body() ?? $_POST;
  $supplier = trim((string)($body['supplier_name'] ?? ''));
  $note     = trim((string)($body['note'] ?? ''));
  $items    = $body['items'] ?? [];

  if (!is_array($items) || !count($items)) {
    throw new Exception('Item pembelian kosong.');
  }

  // Normalisasi items {sku, qty, price, hj?}
  $norm = [];
  foreach ($items as $i) {
    $sku   = trim((string)($i['sku']   ?? ''));
    $qty   = (int)($i['qty']  ?? 0);
    $price = (float)($i['price'] ?? 0);
    $hj    = isset($i['hj']) ? (float)$i['hj'] : null; // HJ (baru) opsional
    if ($sku === '' || $qty <= 0) continue;
    $norm[] = compact('sku','qty','price','hj');
  }
  if (!count($norm)) throw new Exception('Item tidak valid.');

  // Ambil product_id by SKU
  $findProd = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
  $total = 0.0;
  foreach ($norm as $n) {
    $findProd->execute([$n['sku']]);
    $pid = (int)$findProd->fetchColumn();
    if ($pid <= 0) throw new Exception("SKU {$n['sku']} tidak ditemukan.");
    $total += $n['qty'] * $n['price'];
  }

  // transaksi
  $pdo->beginTransaction();

  // Buat invoice_code jika kolom tersedia
  $hasInvoiceCode = $colExists('purchases','invoice_code');
  $invoice = 'PB-' . date('Ymd-His');

  if ($hasInvoiceCode) {
    $st = $pdo->prepare("INSERT INTO purchases (invoice_code, supplier_name, note, total)
                         VALUES (?,?,?,?)");
    $st->execute([$invoice, $supplier, $note, $total]);
  } else {
    $st = $pdo->prepare("INSERT INTO purchases (supplier_name, note, total)
                         VALUES (?,?,?)");
    $st->execute([$supplier, $note, $total]);
  }
  $purchaseId = (int)$pdo->lastInsertId();

  // Siapkan insert ke purchase_items TANPA subtotal (biarkan generated)
  $hasHJNew = $colExists('purchase_items','sell_price_new');

  if ($hasHJNew) {
    $insItem = $pdo->prepare("
      INSERT INTO purchase_items (purchase_id, product_id, qty, price, sell_price_new)
      SELECT ?, id, ?, ?, ?
      FROM products WHERE sku = ?
    ");
  } else {
    $insItem = $pdo->prepare("
      INSERT INTO purchase_items (purchase_id, product_id, qty, price)
      SELECT ?, id, ?, ?
      FROM products WHERE sku = ?
    ");
  }

  // Update stok & harga
  $updStock = $pdo->prepare("UPDATE products SET stock = stock + ?, cost_price = ? WHERE sku = ?");
  $updHJ    = $pdo->prepare("UPDATE products SET sell_price = ?, updated_at = NOW() WHERE sku = ?");

  foreach ($norm as $n) {
    if ($hasHJNew) {
      $insItem->execute([$purchaseId, $n['qty'], $n['price'], ($n['hj'] ?? null), $n['sku']]);
    } else {
      $insItem->execute([$purchaseId, $n['qty'], $n['price'], $n['sku']]);
    }
    // stok bertambah, modal di-set harga beli terakhir
    $updStock->execute([$n['qty'], $n['price'], $n['sku']]);
    // jika ada input HJ baru & >0 → update HJ produk
    if (isset($n['hj']) && $n['hj'] > 0) {
      $updHJ->execute([$n['hj'], $n['sku']]);
    }
  }

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'purchase_id' => $purchaseId,
    'invoice_code' => $hasInvoiceCode ? $invoice : null,
    'total' => $total
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (_) {} }
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
