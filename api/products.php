<?php
// api/products.php
// List produk untuk POS & Kelola Produk.
// Mendukung pencarian bebas (?q=) dan lookup barcode exact (?barcode=).
// Output: id, sku, name, stock, sell_price, cost_price, barcode
//         + alias 'price' = sell_price (kompat lama)

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $q       = trim($_GET['q'] ?? '');
    $barcode = trim($_GET['barcode'] ?? '');

    $params = [];
    $where  = [];

    if ($barcode !== '') {
        // lookup exact barcode
        $where[] = "barcode = ?";
        $params[] = $barcode;
    } elseif ($q !== '') {
        // cari di SKU/Nama
        $where[] = "(sku LIKE ? OR name LIKE ?)";
        $like    = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            id,
            sku,
            name,
            CAST(stock AS SIGNED) AS stock,
            CAST(COALESCE(sell_price, price, 0) AS DECIMAL(12,2)) AS sell_price,
            CAST(COALESCE(cost_price, 0)          AS DECIMAL(12,2)) AS cost_price,
            COALESCE(barcode, '') AS barcode
        FROM products
        $whereSql
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
        $r['price']      = $r['sell_price']; // alias utk UI lama
        $r['barcode']    = (string)$r['barcode'];
    }

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
