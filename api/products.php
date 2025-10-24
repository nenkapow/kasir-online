<?php
// api/products.php
// List & lookup produk (mendukung ?q= dan ?barcode=)

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $q       = trim($_GET['q'] ?? '');
    $barcode = trim($_GET['barcode'] ?? '');
    $rows    = [];

    // Helper normalize: UPC-A (12) <-> EAN-13 (13)
    $alts = [];
    if ($barcode !== '') {
        $b = preg_replace('/\s+/', '', $barcode);
        $alts[] = $b;

        if (preg_match('/^\d{12}$/', $b)) {        // UPC-A -> EAN-13
            $alts[] = '0' . $b;
        }
        if (preg_match('/^\d{13}$/', $b) && $b[0]==='0') { // EAN-13 -> UPC-A
            $alts[] = substr($b, 1);
        }
        $alts = array_values(array_unique($alts));
    }

    if ($barcode !== '') {
        // Exact lookup by barcode (dengan alternatif)
        $in = implode(',', array_fill(0, count($alts), '?'));
        $sql = "
            SELECT
                id,
                sku,
                name,
                CAST(stock AS SIGNED) AS stock,
                CAST(COALESCE(sell_price, price, 0) AS DECIMAL(12,2)) AS sell_price,
                CAST(COALESCE(cost_price, 0)          AS DECIMAL(12,2)) AS cost_price,
                barcode
            FROM products
            WHERE barcode IN ($in)
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($alts);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Pencarian umum (?q=) – cari di sku, name, barcode
        $params = [];
        $where  = '';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where = "WHERE sku LIKE ? OR name LIKE ? OR barcode LIKE ?";
            $params = [$like, $like, $like];
        }

        $sql = "
            SELECT
                id,
                sku,
                name,
                CAST(stock AS SIGNED) AS stock,
                CAST(COALESCE(sell_price, price, 0) AS DECIMAL(12,2)) AS sell_price,
                CAST(COALESCE(cost_price, 0)          AS DECIMAL(12,2)) AS cost_price,
                barcode
            FROM products
            $where
            ORDER BY name ASC
            LIMIT 1000
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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
