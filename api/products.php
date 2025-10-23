<?php
// api/products.php
// List produk untuk POS, kelola produk, & lookup by barcode.
// Output: id, sku, name, stock, sell_price, cost_price, price(=sell_price), barcode

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $q       = trim($_GET['q'] ?? '');
    $barcode = trim($_GET['barcode'] ?? ''); // <- NEW
    $params  = [];
    $where   = '';

    if ($barcode !== '') {
        // Pencarian barcode EXACT (tercepat untuk hasil scan)
        $where  = "WHERE barcode = ?";
        $params = [$barcode];
    } elseif ($q !== '') {
        // Pencarian umum (nama/SKU/Barcode like)
        $where  = "WHERE sku LIKE ? OR name LIKE ? OR barcode LIKE ?";
        $like   = '%' . $q . '%';
        $params = [$like, $like, $like];
    }

    $sql = "
        SELECT
            id,
            sku,
            name,
            barcode,                                   -- NEW
            CAST(stock AS SIGNED) AS stock,
            CAST(COALESCE(sell_price, price, 0) AS DECIMAL(12,2)) AS sell_price,
            CAST(COALESCE(cost_price, 0)          AS DECIMAL(12,2)) AS cost_price
        FROM products
        $where
        ORDER BY name ASC
        LIMIT 1000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['stock']      = (int)$r['stock'];
        $r['sell_price'] = (float)$r['sell_price'];
        $r['cost_price'] = (float)$r['cost_price'];
        $r['price']      = $r['sell_price']; // alias untuk UI lama
        $r['barcode']    = $r['barcode'] ?? null;
    }

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
