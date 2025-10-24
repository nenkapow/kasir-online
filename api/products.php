<?php
// List produk untuk POS, Pembelian, dan Kelola Produk.
// Fitur: ?q= (nama/SKU), ?barcode= (exact). Selalu include kolom barcode.

require_once __DIR__ . '/_init.php';

try {
  $q = trim($_GET['q'] ?? '');
  $barcode = trim($_GET['barcode'] ?? '');

  $params = [];
  $where  = [];

  if ($q !== '') {
    $where[] = '(sku LIKE ? OR name LIKE ? OR barcode LIKE ?)';
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
  }
  if ($barcode !== '') {
    $where[] = 'barcode = ?';
    $params[] = $barcode;
  }

  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $sql = "
    SELECT
      id,
      sku,
      name,
      COALESCE(barcode, '') AS barcode,
      CAST(stock AS SIGNED) AS stock,
      CAST(COALESCE(sell_price, price, 0) AS DECIMAL(12,2)) AS sell_price,
      CAST(COALESCE(cost_price, 0)       AS DECIMAL(12,2)) AS cost_price
    FROM products
    $whereSql
    ORDER BY name ASC
    LIMIT 1000
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();

  foreach ($rows as &$r) {
    $r['id']         = (int)$r['id'];
    $r['stock']      = (int)$r['stock'];
    $r['sell_price'] = (float)$r['sell_price'];
    $r['cost_price'] = (float)$r['cost_price'];
    $r['price']      = $r['sell_price']; // alias utk UI lama
  }

  json(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
  json(['ok' => false, 'error' => $e->getMessage()], 500);
}
