<?php
// api/products.php
// List produk untuk POS & Kelola Produk.
// Output: id, sku, barcode, name, stock, sell_price, cost_price, dan price (alias sell_price)

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $pdo = db();

    $q = trim($_GET['q'] ?? '');
    $params = [];
    $where  = '';

    if ($q !== '') {
        // cari di sku / name / barcode  (dengan 1 exact match barcode juga)
        $where = "WHERE (sku LIKE ? OR name LIKE ? OR barcode LIKE ? OR barcode = ?)";
        $like  = '%' . $q . '%';
        $params = [$like, $like, $like, $q];
    }

    $sql = "
        SELECT
            id,
            sku,
            barcode,
            name,
            CAST(stock AS SIGNED) AS stock,
            /* fallback ke kolom price lama jika sell_price masih null */
            COALESCE(sell_price, price, 0) AS sell_price,
            COALESCE(cost_price, 0)        AS cost_price
        FROM products
        $where
        ORDER BY name ASC
        LIMIT 1000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $r['id']         = (int)($r['id'] ?? 0);
        $r['sku']        = (string)($r['sku'] ?? '');
        $r['barcode']    = $r['barcode'] === null ? null : (string)$r['barcode'];
        $r['name']       = (string)($r['name'] ?? '');
        $r['stock']      = (int)($r['stock'] ?? 0);
        $r['sell_price'] = (float)($r['sell_price'] ?? 0);
        $r['cost_price'] = (float)($r['cost_price'] ?? 0);
        // Kompat: alias 'price' utk UI POS lama
        $r['price']      = (float)$r['sell_price'];
    }
    unset($r);

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
