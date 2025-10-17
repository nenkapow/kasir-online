<?php
require __DIR__ . '/_init.php';
require_auth(); // pastikan user sudah di-session-kan

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);

$method = $in['method'] ?? 'cash';
$note   = trim((string)($in['note'] ?? ''));
$items  = $in['items'] ?? [];

if (!is_array($items) || count($items) === 0) {
  echo json_encode(['ok'=>false,'error'=>'Item kosong']); exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // hitung total & validasi stok
  $total = 0;
  foreach ($items as $it) {
    $pid = (int)($it['id'] ?? 0);
    $qty = (int)($it['qty'] ?? 0);
    $price = (int)($it['price'] ?? 0);
    if ($pid <= 0 || $qty <= 0) throw new Exception('Data item tidak valid');

    // cek stok
    $st = $pdo->prepare("SELECT stock, name FROM products WHERE id=:id FOR UPDATE");
    $st->execute([':id'=>$pid]);
    $row = $st->fetch();
    if (!$row) throw new Exception('Produk tidak ditemukan');
    if ((int)$row['stock'] < $qty) throw new Exception("Stok tidak cukup untuk {$row['name']}");

    $total += $qty * $price;
  }

  // insert sales
  $st = $pdo->prepare("INSERT INTO sales (total, method, note, created_at) VALUES (:t,:m,:n,NOW())");
  $st->execute([':t'=>$total, ':m'=>$method, ':n'=>$note]);
  $sale_id = (int)$pdo->lastInsertId();

  // insert items & kurangi stok
  $insItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price, subtotal) VALUES (:sid,:pid,:q,:p,:s)");
  $updStock= $pdo->prepare("UPDATE products SET stock = stock - :q WHERE id=:pid");

  foreach ($items as $it) {
    $pid = (int)$it['id']; $qty=(int)$it['qty']; $price=(int)$it['price'];
    $insItem->execute([
      ':sid'=>$sale_id, ':pid'=>$pid, ':q'=>$qty, ':p'=>$price, ':s'=>$qty*$price
    ]);
    $updStock->execute([':q'=>$qty, ':pid'=>$pid]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'data'=>['sale_id'=>$sale_id]]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
