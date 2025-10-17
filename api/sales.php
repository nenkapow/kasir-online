<?php
// api/sales.php
require_once __DIR__.'/config.php';
require_auth();
$pdo = db();

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json; charset=utf-8');

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $d = json_decode($body, true) ?? [];
    $items = $d['items'] ?? [];
    $payment = $d['payment'] ?? 'cash';
    $note = $d['note'] ?? '';

    if (!is_array($items) || count($items) === 0) {
        fail('No items provided', 400);
    }

    try {
        $pdo->beginTransaction();

        // create sale
        $stmt = $pdo->prepare("INSERT INTO sales (payment, note, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$payment, $note]);
        $sale_id = $pdo->lastInsertId();

        // insert items
        $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, sku, name, price, qty) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $it) {
            $stmtItem->execute([$sale_id, $it['sku'], $it['name'], $it['price'], $it['qty']]);
            // (opsional) kurangi stok
            $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE sku = ?");
            $stmtStock->execute([$it['qty'], $it['sku']]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $sale_id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Failed to save sale: '.$e->getMessage(), 500);
    }
    exit;
}

// GET /api/sales.php?limit=10  => list recent sales
if ($method === 'GET') {
    $limit = intval($_GET['limit'] ?? 10);
    $stmt = $pdo->prepare("SELECT * FROM sales ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
