<?php
require_once __DIR__ . '/config.php';
check_pin();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $q = $_GET['q'] ?? '';
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE :q OR sku LIKE :q ORDER BY name LIMIT 100");
        $stmt->execute([':q' => "%$q%"]);
    } else {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY name LIMIT 200");
    }
    ok($stmt->fetchAll());
}

if ($method === 'POST') {
    $data = json_input();
    $id = $data['id'] ?? null;
    $sku = trim($data['sku'] ?? '');
    $name = trim($data['name'] ?? '');
    $price = intval($data['price'] ?? 0);
    $stock = intval($data['stock'] ?? 0);
    if ($name === '') fail('Name required');
    if ($id) {
        $stmt = $pdo->prepare("UPDATE products SET sku=:sku, name=:name, price=:price, stock=:stock WHERE id=:id");
        $stmt->execute([':sku'=>$sku, ':name'=>$name, ':price'=>$price, ':stock'=>$stock, ':id'=>$id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (sku,name,price,stock) VALUES (:sku,:name,:price,:stock)");
        $stmt->execute([':sku'=>$sku, ':name'=>$name, ':price'=>$price, ':stock'=>$stock]);
        $id = $pdo->lastInsertId();
    }
    ok(['id'=>$id]);
}

if ($method === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
    $id = intval($qs['id'] ?? 0);
    if (!$id) fail('Missing id');
    $stmt = $pdo->prepare("DELETE FROM products WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    ok(['deleted'=>$id]);
}

fail('Method not allowed', 405);
?>
