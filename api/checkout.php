<?php
require_once __DIR__ . '/_init.php';

try {
  // Ambil FormData
  $method = trim($_POST['method'] ?? 'cash');
  $note   = trim($_POST['note'] ?? '');
  $total  = (float)($_POST['total'] ?? 0);
  $paid   = (float)($_POST['amount_paid'] ?? 0);

  $itemsJson = $_POST['items'] ?? '[]';
  $items = json_decode($itemsJson, true);
  if (!is_array($items) || !count($items)) throw new Exception('Items kosong.');

  if ($total < 0) $total = 0.0;
  if ($paid  < 0) $paid  = 0.0;
  $change = max(0, $paid - $total);

  $inv = 'TRX-'.gmdate('Ymd-His');

  $pdo->beginTransaction();

  // header penjualan
  $st = $pdo->prepare("INSERT INTO sales (invoice_code, method, note, total, amount_paid, change_amount, created_at)
                       VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
  $st->execute([$inv, $method, $note, $total, $paid, $change]);
  $sid = (int)$pdo->lastInsertId();

  $itStmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price, subtotal) VALUES (?,?,?,?,?)");
  $getP   = $pdo->prepare("SELECT stock FROM products WHERE id=? FOR UPDATE");
  $updP   = $pdo->prepare("UPDATE products SET stock=?, updated_at=UTC_TIMESTAMP() WHERE id=?");

  foreach ($items as $it) {
    $pid = (int)($it['product_id'] ?? $it['id'] ?? 0);
    $qty = max(1, (int)($it['qty'] ?? 0));
    $prc = max(0, (float)($it['price'] ?? 0));
    $sub = $qty * $prc;

    if ($pid <= 0) throw new Exception('Produk tidak valid.');

    // lock + cek stok
    $getP->execute([$pid]);
    $row = $getP->fetch();
    if (!$row) throw new Exception("Produk ID $pid tidak ditemukan.");
    $stok = (int)$row['stock'];
    if ($stok < $qty) throw new Exception('Stok tidak cukup');

    // kurangi stok
    $updP->execute([$stok - $qty, $pid]);

    // detail
    $itStmt->execute([$sid, $pid, $qty, $prc, $sub]);
  }

  $pdo->commit();
  json(['ok' => true, 'invoice_code' => $inv, 'change' => $change]);
} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  json(['ok' => false, 'error' => $e->getMessage()], 400);
}
