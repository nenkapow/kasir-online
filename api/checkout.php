<?php
require __DIR__ . '/_init.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

/**
 * Baca input: dukung JSON (application/json) dan form-url-encoded.
 */
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input');

if (stripos($ct, 'application/json') !== false) {
  $in = json_decode($raw, true);
  if (!is_array($in)) $in = [];
} else {
  // x-www-form-urlencoded / multipart
  $in = $_POST;
  if (isset($in['items']) && is_string($in['items'])) {
    $try = json_decode($in['items'], true);
    if (is_array($try)) $in['items'] = $try;
  }
}

// --- DEBUG ke log (aman untuk tracing; hapus kalau tidak perlu) ---
error_log('checkout CT=' . $ct);
error_log('checkout raw=' . substr($raw ?? '', 0, 500));
error_log('checkout items count=' . (is_array($in['items'] ?? null) ? count($in['items']) : 0));

$method     = $in['method']       ?? 'cash';
$note       = trim((string)($in['note'] ?? ''));
$items      = $in['items']        ?? [];
$amountPaid = (int)($in['amount_paid'] ?? 0);

if (!is_array($items) || count($items) === 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Item kosong']);
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Hitung total & cek stok (lock row)
  $total = 0;
  $sel   = $pdo->prepare("SELECT id, name, stock FROM products WHERE id=:id FOR UPDATE");
  foreach ($items as $it) {
    // frontend boleh kirim "id" atau "product_id"
    $pid   = (int)($it['product_id'] ?? $it['id'] ?? 0);
    $qty   = (int)($it['qty']   ?? 0);
    $price = (int)($it['price'] ?? 0);

    if ($pid <= 0 || $qty <= 0 || $price < 0) {
      throw new Exception('Data item tidak valid');
    }

    $sel->execute([':id'=>$pid]);
    $row = $sel->fetch();
    if (!$row)                     throw new Exception('Produk tidak ditemukan');
    if ((int)$row['stock'] < $qty) throw new Exception("Stok tidak cukup untuk {$row['name']}");

    $total += $qty * $price;
  }

  if ($amountPaid < $total) {
    throw new Exception('Nominal bayar kurang dari total');
  }
  $change = $amountPaid - $total;

  // Simpan sales
  $insSale = $pdo->prepare(
    "INSERT INTO sales (total, method, amount_paid, change_amount, note, created_at)
     VALUES (:t, :m, :ap, :ch, :n, NOW())"
  );
  $insSale->execute([
    ':t'=>$total, ':m'=>$method, ':ap'=>$amountPaid, ':ch'=>$change, ':n'=>$note
  ]);
  $sale_id = (int)$pdo->lastInsertId();

  // Simpan item + kurangi stok
  $insItem  = $pdo->prepare(
    "INSERT INTO sale_items (sale_id, product_id, qty, price, subtotal)
     VALUES (:sid, :pid, :q, :p, :s)"
  );
  $updStock = $pdo->prepare("UPDATE products SET stock = stock - :q WHERE id=:pid");

  foreach ($items as $it) {
    $pid   = (int)($it['product_id'] ?? $it['id']);
    $qty   = (int)$it['qty'];
    $price = (int)$it['price'];

    $insItem->execute([
      ':sid'=>$sale_id, ':pid'=>$pid, ':q'=>$qty, ':p'=>$price, ':s'=>$qty*$price
    ]);
    $updStock->execute([':q'=>$qty, ':pid'=>$pid]);
  }

  $pdo->commit();

  echo json_encode([
    'ok'   => true,
    'data' => [
      'sale_id'     => $sale_id,
      'total'       => $total,
      'amount_paid' => $amountPaid,
      'change'      => $change
    ]
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
