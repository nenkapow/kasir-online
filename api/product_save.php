<?php
require __DIR__ . '/_init.php';
require_auth();

function post($key, $default=null) {
  return $_POST[$key] ?? $default;
}

$id    = trim((string)post('id', ''));
$sku   = trim((string)post('sku', ''));
$name  = trim((string)post('name', ''));
$price = (int)post('price', 0);
$stock = (int)post('stock', 0);

if ($sku === '' || $name === '') {
  json(['ok'=>false, 'error'=>'SKU dan Nama wajib diisi'], 400);
}

$pdo = db();

try {
  if ($id !== '') {
    // UPDATE
    $st = $pdo->prepare("UPDATE products SET sku=:sku, name=:name, price=:price, stock=:stock WHERE id=:id");
    $st->execute([
      ':sku'=>$sku, ':name'=>$name, ':price'=>$price, ':stock'=>$stock, ':id'=>$id
    ]);
  } else {
    // INSERT
    $st = $pdo->prepare("INSERT INTO products (sku, name, price, stock) VALUES (:sku,:name,:price,:stock)");
    $st->execute([':sku'=>$sku, ':name'=>$name, ':price'=>$price, ':stock'=>$stock]);
    $id = (string)$pdo->lastInsertId();
  }

  // return product terbaru
  $row = $pdo->prepare("SELECT id, sku, name, price, stock FROM products WHERE id=:id");
  $row->execute([':id'=>$id]);
  json(['ok'=>true, 'data'=>$row->fetch()]);
} catch (Throwable $e) {
  json(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
