<?php
require __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

// Ambil rentang tanggal (lokal Asia/Jakarta)
$from = $_GET['from'] ?? $_POST['from'] ?? '';
$to   = $_GET['to']   ?? $_POST['to']   ?? '';

if (!$from || !$to) {
  $from = $to = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
}

// Konversi ke UTC: [from_local 00:00, to_local +1 hari 00:00)
$tzLocal = new DateTimeZone('Asia/Jakarta');
$tzUTC   = new DateTimeZone('UTC');

$fromLocal = DateTime::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', $tzLocal);
$toLocal   = DateTime::createFromFormat('Y-m-d H:i:s', $to   . ' 00:00:00', $tzLocal);

if (!$fromLocal || !$toLocal) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Format tanggal salah (YYYY-MM-DD)']);
  exit;
}

$toLocal->modify('+1 day');

$fromUTC = (clone $fromLocal)->setTimezone($tzUTC)->format('Y-m-d H:i:s');
$toUTC   = (clone $toLocal)->setTimezone($tzUTC)->format('Y-m-d H:i:s');

try {
  $pdo = db();

  // Ringkasan harian (tanggal ditampilkan sebagai WIB)
  $sqlDaily = "
    SELECT
      DATE(CONVERT_TZ(s.created_at, '+00:00', '+07:00')) AS tgl,
      COUNT(*)  AS trx,
      SUM(s.total) AS omzet
    FROM sales s
    WHERE s.created_at >= :fromUTC AND s.created_at < :toUTC
    GROUP BY tgl
    ORDER BY tgl ASC
  ";
  $stDaily = $pdo->prepare($sqlDaily);
  $stDaily->execute([':fromUTC' => $fromUTC, ':toUTC' => $toUTC]);
  $daily = $stDaily->fetchAll(PDO::FETCH_ASSOC);

  // Produk terlaris
  $sqlTop = "
    SELECT
      p.name AS produk,
      SUM(si.qty)            AS qty,
      SUM(si.qty * si.price) AS penjualan
    FROM sale_items si
    JOIN sales s    ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE s.created_at >= :fromUTC AND s.created_at < :toUTC
    GROUP BY si.product_id
    ORDER BY penjualan DESC, qty DESC, produk ASC
    LIMIT 50
  ";
  $stTop = $pdo->prepare($sqlTop);
  $stTop->execute([':fromUTC' => $fromUTC, ':toUTC' => $toUTC]);
  $top = $stTop->fetchAll(PDO::FETCH_ASSOC);

  // kembalikan data + alias utk kompatibilitas
  echo json_encode([
    'ok'          => true,
    'range'       => ['from' => $from, 'to' => $to],
    'daily'       => $daily,
    'top'         => $top,
    'summary'     => $daily, // alias
    'bestsellers' => $top     // alias
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
