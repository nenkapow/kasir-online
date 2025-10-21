<?php
// api/purchase_create.php
require __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');

// (opsional) kalau kamu pakai PIN header, aktifkan:
// if (!check_pin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'PIN salah']); exit; }

function read_json_body() {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

$body = array_merge($_POST ?? [], read_json_body());

$supplier = trim($body['supplier_name'] ?? '');
$note     = trim($body['note'] ?? '');
$items    = $body['items'] ?? null; // array of { sku|product_id, qty, price }

if (!is_array($items) || !count($items)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'items kosong']);
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  // ====== Generate invoice_code unik per hari ======
  $today = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Ymd');
  $prefix = 'PB-'.$today.'-';
  $sqlMax = "SELECT MAX(invoice_code) AS maxcode FROM purchases WHERE invoice_code LIKE :pfx";
  $stMax  = $pdo->prepare($sqlMax);
  $stMax->execute([':pfx'=>$prefix.'%']);
  $rowMax = $stMax->fetch(PDO::FETCH_ASSOC);
  $lastNo = 0;
  if (!empty($rowMax['maxcode'])) {
    $lastNo = intval(substr($rowMax['maxcode'], -3)); // 3 digit
  }
  $invoice = $prefix . str_pad((string)($lastNo+1), 3, '0', STR_PAD_LEFT);

  // ====== Insert header purchases ======
  $stIns = $pdo->prepare("INSERT INTO purchases (invoice_code, supplier_name, note) VALUES (:inv,:supp,:note)");
  $stIns->execute([':inv'=>$invoice, ':supp'=>$supplier, ':note'=>$note]);
  $purchase_id = (int)$pdo->lastInsertId();

  // ====== Siapkan statement yang sering dipakai ======
  $stFindBySku   = $pdo->prepare("SELECT id, sku, name, price, stock FROM products WHERE sku = :sku FOR UPDATE");
  $stFindById    = $pdo->prepare("SELECT id, sku, name, price, stock FROM products WHERE id  = :id  FOR UPDATE");
  $stInsItem     = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, price) VALUES (:pid,:prod,:qty,:price)");
  $stUpdStock    = $pdo->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id");

  $cleanItems = [];
  foreach ($items as $i) {
    $sku  = isset($i['sku']) ? trim((string)$i['sku']) : '';
    $pid  = isset($i['product_id']) ? (int)$i['product_id'] : 0;
    $qty  = max(0, (int)($i['qty'] ?? 0));
    $price= (float)($i['price'] ?? 0);

    if ($qty <= 0 || $price < 0) {
      throw new RuntimeException('Qty/price tidak valid.');
    }

    // Ambil product
    if ($pid > 0) {
      $stFindById->execute([':id'=>$pid]);
      $p = $stFindById->fetch(PDO::FETCH_ASSOC);
    } else {
      if ($sku === '') throw new RuntimeException('SKU atau product_id wajib ada.');
      $stFindBySku->execute([':sku'=>$sku]);
      $p = $stFindBySku->fetch(PDO::FETCH_ASSOC);
    }
    if (!$p) throw new RuntimeException('Produk tidak ditemukan (sku/id).');

    $prod_id = (int)$p['id'];

    // Insert item
    $stInsItem->execute([
      ':pid'  => $purchase_id,
      ':prod' => $prod_id,
      ':qty'  => $qty,
      ':price'=> $price
    ]);

    // Update stok
    $stUpdStock->execute([':qty'=>$qty, ':id'=>$prod_id]);

    $cleanItems[] = [
      'product_id' => $prod_id,
      'sku'        => $p['sku'],
      'name'       => $p['name'],
      'qty'        => $qty,
      'price'      => $price,
      'subtotal'   => round($qty * $price, 2),
    ];
  }

  // ====== Update total header ======
  $pdo->prepare("
    UPDATE purchases p
       SET p.total = (SELECT IFNULL(SUM(subtotal),0) FROM purchase_items WHERE purchase_id = p.id)
     WHERE p.id = :id
  ")->execute([':id'=>$purchase_id]);

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'purchase' => [
      'id'           => $purchase_id,
      'invoice_code' => $invoice,
      'supplier_name'=> $supplier,
      'note'         => $note,
      'items'        => $cleanItems,
      'total'        => array_sum(array_column($cleanItems,'subtotal')),
      'created_at'   => (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s')
    ]
  ]);
} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
