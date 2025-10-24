<?php
require_once __DIR__ . '/_init.php';

try {
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input');
  if (stripos($ct, 'application/json') === false || !$raw) {
    throw new Exception('Body harus JSON.');
  }
  $body = json_decode($raw, true);
  if (!is_array($body)) throw new Exception('JSON tidak valid.');

  $supplier = trim((string)($body['supplier_name'] ?? ''));
  $note     = trim((string)($body['note'] ?? ''));
  $items    = $body['items'] ?? [];
  if (!is_array($items) || !count($items)) throw new Exception('Item kosong.');

  // Buat invoice code
  $inv = 'PB-'.gmdate('Ymd-His');

  $pdo->beginTransaction();

  // Insert header
  $st = $pdo->prepare("INSERT INTO purchases (invoice_code, supplier_name, note, total, created_at)
                       VALUES (?, ?, ?, 0, UTC_TIMESTAMP())");
  $st->execute([$inv, $supplier, $note]);
  $pid = (int)$pdo->lastInsertId();

  $total = 0;

  $qSel = $pdo->prepare("SELECT id, stock, COALESCE(cost_price,0) AS cost_price FROM products WHERE sku=? LIMIT 1");
  $qUpd = $pdo->prepare("UPDATE products
                          SET stock = ?, cost_price = ?, sell_price = COALESCE(?, sell_price), price = COALESCE(?, price), updated_at = UTC_TIMESTAMP()
                        WHERE id=?");
  $qItem = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, price, subtotal) VALUES (?,?,?,?,?)");

  foreach ($items as $it) {
    $sku   = trim((string)($it['sku'] ?? ''));
    $name  = trim((string)($it['name'] ?? ''));
    $qty   = max(1, (int)($it['qty'] ?? 0));
    $price = max(0, (float)($it['price'] ?? 0));       // harga beli
    $sellP = isset($it['sell_price']) ? (float)$it['sell_price'] : null; // boleh null

    if ($sku === '' || $name === '') throw new Exception('SKU/Nama item tidak valid.');

    // pastikan produk ada
    $qSel->execute([$sku]);
    $p = $qSel->fetch();
    if (!$p) {
      // kalau belum ada, buat produk baru dengan stok 0 dulu
      $ins = $pdo->prepare("INSERT INTO products (sku, name, stock, cost_price, sell_price, price, created_at)
                            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
      $ins->execute([$sku, $name, 0, 0, $sellP ?? 0, $sellP ?? 0]);
      $p = ['id' => (int)$pdo->lastInsertId(), 'stock'=>0, 'cost_price'=>0];
    }

    $pidProd = (int)$p['id'];
    $oldStock = (int)$p['stock'];
    $oldCost  = (float)$p['cost_price'];

    // moving average cost
    $newStock = $oldStock + $qty;
    $newCost  = $newStock > 0
      ? (($oldStock * $oldCost) + ($qty * $price)) / $newStock
      : $price;

    // update produk (stok + cost_price; optional sell_price)
    $qUpd->execute([
      $newStock,
      $newCost,
      ($sellP !== null && $sellP > 0) ? $sellP : null,  // kalau null → COALESCE keep lama
      ($sellP !== null && $sellP > 0) ? $sellP : null,
      $pidProd
    ]);

    // simpan detail pembelian
    $sub = $qty * $price;
    $qItem->execute([$pid, $pidProd, $qty, $price, $sub]);
    $total += $sub;
  }

  // update total
  $pdo->prepare("UPDATE purchases SET total=? WHERE id=?")->execute([$total, $pid]);

  $pdo->commit();
  json(['ok' => true, 'invoice_code' => $inv, 'total' => $total]);
} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  json(['ok' => false, 'error' => $e->getMessage()], 400);
}
