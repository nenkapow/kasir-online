<?php
// api/purchase_get.php
require __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id wajib']); exit; }

try {
  $pdo = db();
  $stH = $pdo->prepare("SELECT * FROM purchases WHERE id = :id");
  $stH->execute([':id'=>$id]);
  $h = $stH->fetch(PDO::FETCH_ASSOC);
  if (!$h) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

  $stI = $pdo->prepare("
    SELECT pi.*, p.sku, p.name 
    FROM purchase_items pi
    JOIN products p ON p.id = pi.product_id
    WHERE pi.purchase_id = :id
    ORDER BY pi.id ASC
  ");
  $stI->execute([':id'=>$id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true, 'header'=>$h, 'items'=>$items]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
