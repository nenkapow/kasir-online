<?php
// api/purchase_list.php
require __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');

$from = $_GET['from'] ?? $_POST['from'] ?? '';
$to   = $_GET['to']   ?? $_POST['to']   ?? '';

$tzLocal = new DateTimeZone('Asia/Jakarta');
if (!$from || !$to) {
  $from = $to = (new DateTime('now',$tzLocal))->format('Y-m-d');
}

try {
  $pdo = db();
  // gunakan created_at (UTC di DB) → tampilkan sebagai WIB di SELECT
  $st = $pdo->prepare("
    SELECT
      id, invoice_code, supplier_name, total,
      CONVERT_TZ(created_at, '+00:00', '+07:00') AS created_at_wib
    FROM purchases
    WHERE created_at >= CONVERT_TZ(:fromD, '+07:00','+00:00')
      AND created_at <  CONVERT_TZ(DATE_ADD(:toD, INTERVAL 1 DAY), '+07:00','+00:00')
    ORDER BY id DESC
  ");
  $st->execute([':fromD'=>$from, ':toD'=>$to]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true, 'range'=>['from'=>$from,'to'=>$to], 'data'=>$rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
