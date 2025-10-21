<?php
// api/products.php
// List produk untuk POS & Kelola Produk.
// Output: id, sku, name, stock, sell_price, cost_price, dan price (alias sell_price)

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $q = trim($_GET['q'] ?? '');
    $params = [];
    $where  = '';

    if ($q !== '') {
        $where = "WHERE sku LIKE ? OR name LIKE ?";
        $like  = '%' . $q . '%';
        $params = [$like, $like];
    }

    // Ambil sell_price/cost_price kalau ada; fallback ke price lama jika sell_price NULL
    $sql = "
        SELECT
            id,
            sku,
            name,
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

    // Kompatibilitas lama: alias 'price' = sell_price
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['stock']      = (int)$r['stock'];
        $r['sell_price'] = (float)$r['sell_price'];
        $r['cost_price'] = (float)$r['cost_price'];
        $r['price']      = $r['sell_price']; // alias untuk UI lama
    }

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
