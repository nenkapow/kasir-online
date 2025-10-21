<?php
// api/purchase_create.php
// Create purchase (stok masuk) dari form purchases.html
// - Wajib SKU produk sudah ada (tidak membuat produk baru)
// - Tambah stok = stok + qty
// - Update cost_price = harga_beli terakhir
// - Simpan total pembelian

require_once __DIR__ . '/_init.php'; // pastikan ini mengisi $pdo (PDO MySQL) & header JSON default

header('Content-Type: application/json; charset=utf-8');

try {
    // Ambil payload JSON
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Payload tidak valid.');
    }

    $supplier = trim($data['supplier_name'] ?? '');
    $note     = trim($data['note'] ?? '');
    $items    = $data['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
        throw new Exception('Minimal 1 item pembelian.');
    }

    // Validasi item
    $cleanItems = [];
    foreach ($items as $i => $it) {
        $sku   = trim($it['sku'] ?? '');
        $name  = trim($it['name'] ?? ''); // tidak dipakai untuk lookup, hanya fallback info
        $qty   = (int)($it['qty'] ?? 0);
        $price = (float)($it['price'] ?? 0);

        if ($sku === '') {
            throw new Exception("Baris ".($i+1).": SKU wajib diisi.");
        }
        if ($qty <= 0) {
            throw new Exception("Baris ".($i+1).": Qty harus > 0.");
        }
        if ($price < 0) {
            throw new Exception("Baris ".($i+1).": Harga tidak boleh negatif.");
        }

        $cleanItems[] = [
            'sku'   => $sku,
            'name'  => $name,
            'qty'   => $qty,
            'price' => $price,
        ];
    }

    // Mulai transaksi
    $pdo->beginTransaction();

    // Buat invoice_code sederhana: PB-YYYYMMDD-RAND4
    $invoice = 'PB-' . date('Ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

    // Insert nota pembelian (total sementara 0)
    $stmt = $pdo->prepare("INSERT INTO purchases (invoice_code, supplier_name, total, note) VALUES (?, ?, 0, ?)");
    $stmt->execute([$invoice, $supplier ?: null, $note ?: null]);
    $purchase_id = (int)$pdo->lastInsertId();

    // Siapkan statement yang dipakai berulang
    $qFindProduct = $pdo->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
    $qInsertItem  = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
    $qUpdateProd  = $pdo->prepare("UPDATE products SET stock = stock + ?, cost_price = ? WHERE id = ?");

    $grand = 0.0;

    foreach ($cleanItems as $it) {
        // Cari product_id dari SKU
        $qFindProduct->execute([$it['sku']]);
        $pid = $qFindProduct->fetchColumn();
        if (!$pid) {
            throw new Exception("Produk dengan SKU '{$it['sku']}' tidak ditemukan. Silakan buat produk terlebih dulu.");
        }

        // Insert item
        $qInsertItem->execute([$purchase_id, $pid, $it['qty'], $it['price']]);

        // Update stok & cost_price (modal) pakai harga beli terakhir
        $qUpdateProd->execute([$it['qty'], $it['price'], $pid]);

        $grand += ($it['qty'] * $it['price']);
    }

    // Update total nota
    $stmt = $pdo->prepare("UPDATE purchases SET total = ? WHERE id = ?");
    $stmt->execute([$grand, $purchase_id]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'purchase_id' => $purchase_id,
        'invoice_code' => $invoice,
        'total' => $grand,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
