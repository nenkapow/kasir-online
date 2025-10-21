<?php
// api/product_save.php
// Tambah / Edit produk.
// - Tambah: boleh set stock awal.
// - Edit: stock TIDAK bisa diubah (stok hanya lewat pembelian).
// - sell_price bisa diubah; cost_price otomatis dari pembelian (bukan di sini).

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Terima dari FormData (x-www-form-urlencoded / multipart)
    $id        = isset($_POST['id']) ? trim($_POST['id']) : '';
    $sku       = trim($_POST['sku'] ?? '');
    $name      = trim($_POST['name'] ?? '');
    // backward-compat: ada yang masih kirim 'price' → anggap sell_price
    $sellPrice = isset($_POST['sell_price']) ? $_POST['sell_price'] : ($_POST['price'] ?? 0);
    $sellPrice = (float)$sellPrice;
    $stock     = isset($_POST['stock']) ? (int)$_POST['stock'] : 0;

    if ($sku === '' || $name === '') {
        throw new Exception('SKU dan Nama wajib diisi.');
    }
    if ($sellPrice < 0) {
        throw new Exception('Harga jual tidak boleh negatif.');
    }

    if ($id === '' || $id === '0') {
        // ====== INSERT ======
        // SKU unik?
        $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
        $cek->execute([$sku]);
        if ($cek->fetchColumn() > 0) {
            throw new Exception('SKU sudah digunakan.');
        }

        // cost_price diisi sama dgn sell_price saat awal (bisa 0), nanti akan ditimpa dr pembelian
        $stmt = $pdo->prepare("
            INSERT INTO products (sku, name, stock, sell_price, cost_price)
            VALUES (?, ?, ?, ?, COALESCE(cost_price, 0))
        ");
        // karena COALESCE(cost_price,0) tidak bisa di VALUES, maka pakai angka langsung
        $stmt = $pdo->prepare("
            INSERT INTO products (sku, name, stock, sell_price, cost_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sku, $name, $stock, $sellPrice, 0]);

        $newId = (int)$pdo->lastInsertId();

        echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ====== UPDATE (tanpa ubah stok) ======
    $id = (int)$id;

    // SKU unik untuk selain dirinya
    $cek = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id <> ?");
    $cek->execute([$sku, $id]);
    if ($cek->fetchColumn() > 0) {
        throw new Exception('SKU sudah digunakan oleh produk lain.');
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET sku = ?, name = ?, sell_price = ?
        WHERE id = ?
    ");
    $stmt->execute([$sku, $name, $sellPrice, $id]);

    // stok sengaja tidak diubah di sini
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
