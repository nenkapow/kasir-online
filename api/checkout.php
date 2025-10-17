<?php
require __DIR__ . '/_init.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

$inraw = file_get_contents('php://input');
$in    = json_decode($inraw, true);

$method      = $in['method']      ?? 'cash';
$note        = trim((string)($in['note'] ?? ''));
$items       = $in['items']       ?? [];
$amountPaid  = (int)($in['amount_paid'] ?? 0);

if (!is_array($items) || count($items) === 0) {
  echo json_encode(['ok'=>false,'error'=>'Item kosong']); exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // hitung total + cek stok
  $total = 0;
  $sel = $pdo->prepare("SELECT id, name, stock FROM products WHERE id=:id FOR UPDATE");
  foreach ($items as $it) {
    $pid = (int)($it['id'] ?? 0);
    $qty = (int)($it['qty'] ?? 0);
    $price = (int)($it['price'] ?? 0);
    if ($pid<=0 || $qty<=0 || $price<0) throw new Exception('Data item tidak valid');

    $sel->execute([':id'=>$pid]);
    $row = $sel->fetch();
    if (!$row) throw new Exception('Produk tidak ditemukan');
    if ((int)$row['stock'] < $qty) throw new Exception("Stok tidak cukup untuk {$row['name']}");

    $total += $qty * $price;
  }

  if ($amountPaid < $total) {
    throw new Exception('Nominal bayar kurang dari total');
  }
  $change = $amountPaid - $total;

  // simpan sales
  $insSale = $pdo->prepare(
    "INSERT INTO sales (total, method, amount_paid, change_amount, note, created_at)
     VALUES (:t,:m,:ap,:ch,:n,NOW())"
  );
  $insSale->execute([
    ':t'=>$total, ':m'=>$method, ':ap'=>$amountPaid, ':ch'=>$change, ':n'=>$note
  ]);
  $sale_id = (int)$pdo->lastInsertId();

  // simpan item + kurangi stok
  $insItem  = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price, subtotal) VALUES (:sid,:pid,:q,:p,:s)");
  $updStock = $pdo->prepare("UPDATE products SET stock = stock - :q WHERE id=:pid");

  foreach ($items as $it) {
    $pid = (int)$it['id']; $qty=(int)$it['qty']; $price=(int)$it['price'];
    $insItem->execute([':sid'=>$sale_id, ':pid'=>$pid, ':q'=>$qty, ':p'=>$price, ':s'=>$qty*$price]);
    $updStock->execute([':q'=>$qty, ':pid'=>$pid]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'data'=>[
    'sale_id'=>$sale_id,
    'total'=>$total,
    'amount_paid'=>$amountPaid,
    'change'=>$change
  ]]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
