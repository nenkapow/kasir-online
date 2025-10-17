<?php
require_once __DIR__ . '/config.php';
check_pin();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_input();
    $items = $data['items'] ?? [];
    $payment = $data['payment_method'] ?? 'cash';
    $note = $data['note'] ?? null;

    if (!is_array($items) || count($items) == 0) fail('No items');

    $pdo->beginTransaction();
    try {
        // calculate total
        $total = 0;
        foreach ($items as $it) {
            $qty = intval($it['qty'] ?? 1);
            $price = intval($it['price'] ?? 0);
            $total += $qty * $price;
        }
        $stmt = $pdo->prepare("INSERT INTO sales (total, payment_method, note) VALUES (:total,:payment,:note)");
        $stmt->execute([':total'=>$total, ':payment'=>$payment, ':note'=>$note]);
        $sale_id = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price) VALUES (:sale_id,:product_id,:qty,:price)");
        $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - :qty WHERE id = :pid");

        foreach ($items as $it) {
            $pid = intval($it['product_id']);
            $qty = intval($it['qty']);
            $price = intval($it['price']);
            $stmtItem->execute([':sale_id'=>$sale_id, ':product_id'=>$pid, ':qty'=>$qty, ':price'=>$price]);
            $stmtStock->execute([':qty'=>$qty, ':pid'=>$pid]);
        }

        $pdo->commit();
        ok(['sale_id'=>$sale_id, 'total'=>$total]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Error: '.$e->getMessage(), 500);
    }
}

if ($method === 'GET') {
    // recent sales
    $limit = intval($_GET['limit'] ?? 50);
    $stmt = $pdo->prepare("SELECT id, created_at, total, payment_method, note FROM sales ORDER BY id DESC LIMIT :lim");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    ok($stmt->fetchAll());
}

fail('Method not allowed', 405);
?>
