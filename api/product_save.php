<?php
// api/product_save.php
// Tambah / Edit produk.
// - Tambah: boleh set stock awal (optional).
// - Edit: stok TIDAK diubah di sini (stok hanya lewat pembelian).
// - sell_price dapat diubah; cost_price berasal dari pembelian.

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

// Helper: ambil body JSON kalau header-nya application/json
function get_json_body() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) return $j;
        }
    }
    return null;
}

try {
    // Terima data dari FormData/x-www-form-urlencoded (default) atau JSON
    $body = get_json_body();
    $src  = $body ?? $_POST;

    $id        = isset($src['id']) ? trim((string)$src['id']) : '';
    $sku       = trim((string)($src['sku']  ?? ''));
    $name      = trim((string)($src['name'] ?? ''));
    // Backward-compat: jika UI lama kirim 'price', anggap itu sell_price
    $sellPrice = $src['sell_price'] ?? ($src['price'] ?? 0);
    $sellPrice = (float)$sellPrice;
    // stok hanya dipakai saat INSERT
    $stock     = isset($src['stock']) ? (int)$src['stock'] : 0;

    if ($sku === '' || $name === '') {
        throw new Exception('SKU dan Nama wajib diisi.');
    }
    if ($sellPrice < 0) {
        throw new Exception('Harga jual tidak boleh negatif.');
    }

    if ($id === '' || $id === '0') {
        // ===== INSERT =====
        // SKU unik?
        $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
        $cek->execute([$sku]);
        if ($cek->fetchColumn() > 0) {
            throw new Exception('SKU sudah digunakan.');
        }

        // Masukkan kedua kolom harga: price (legacy) & sell_price (baru).
        // cost_price awal = 0; nanti di-update dari pembelian.
        // NOTE: kalau skema kamu tidak punya kolom 'price', tidak masalah selama kolom itu nullable/default 0.
        $stmt = $pdo->prepare("
            INSERT INTO products (sku, name, price, sell_price, cost_price, stock)
            VALUES (?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$sku, $name, $sellPrice, $sellPrice, $stock]);

        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== UPDATE (tanpa ubah stok) =====
    $id = (int)$id;

    // SKU unik untuk selain dirinya
    $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?");
    $cek->execute([$sku, $id]);
    if ($cek->fetchColumn() > 0) {
        throw new Exception('SKU sudah digunakan oleh produk lain.');
    }

    $stmt = $pdo->prepare("
        UPDATE products
           SET sku = ?, name = ?, price = ?, sell_price = ?
         WHERE id = ?
    ");
    $stmt->execute([$sku, $name, $sellPrice, $sellPrice, $id]);

    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
