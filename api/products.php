<?php
require __DIR__ . '/_init.php';

require_auth(); // hormati APP_REQUIRE_PIN / session autologin

$q = trim($_GET['q'] ?? '');
$pdo = db();

try {
  if ($q !== '') {
    $st = $pdo->prepare(
      "SELECT id, sku, name, price, stock
       FROM products
       WHERE name LIKE :q OR sku LIKE :q
       ORDER BY name
       LIMIT 100"
    );
    $st->execute([':q' => "%{$q}%"]);
  } else {
    $st = $pdo->query(
      "SELECT id, sku, name, price, stock
       FROM products
       ORDER BY name
       LIMIT 100"
    );
  }

  json(['ok' => true, 'data' => $st->fetchAll()]);
} catch (Throwable $e) {
  json(['ok' => false, 'error' => $e->getMessage()], 500);
}
