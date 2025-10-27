<?php
declare(strict_types=1);
// api/report.php — Ringkasan penjualan + produk terlaris (orders + order_items)

require_once __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();

  // ---- ambil tanggal ----
  $start = $_GET['start'] ?? $_POST['start'] ?? date('Y-m-d');
  $end   = $_GET['end']   ?? $_POST['end']   ?? $start;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    throw new Exception('Format tanggal harus YYYY-MM-DD');
  }

  // ---- helper: cek kolom ada/tidak ----
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $colExists = function(string $table, string $col) use ($pdo,$db): bool {
    $q = "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=? AND table_name=? AND column_name=?";
    $st = $pdo->prepare($q);
    $st->execute([$db, $table, $col]);
    return (bool)$st->fetchColumn();
  };

  // Tabel/kolom yang kita pakai
  $tblOrders = 'orders';
  $tblItems  = 'order_items';
  $tblProd   = 'products';

  // Nama kolom waktu di orders (fallback berurutan)
  $tsCols = ['created_at','createdAt','ts','created'];
  $tsCol  = null;
  foreach ($tsCols as $c) {
    if ($colExists($tblOrders, $c)) { $tsCol=$c; break; }
  }
  if (!$tsCol) throw new Exception("Kolom waktu tidak ditemukan di '$tblOrders'.");

  // Kolom total di orders
  $totalCol = $colExists($tblOrders,'total') ? 'total' : null;
  if (!$totalCol) throw new Exception("Kolom 'total' tidak ada di '$tblOrders'.");

  // Kolom qty/price di order_items
  $qtyCol   = $colExists($tblItems,'qty')   ? 'qty'   : null;
  $priceCol = $colExists($tblItems,'price') ? 'price' : null;
  if (!$qtyCol || !$priceCol) throw new Exception("Kolom qty/price tidak ada di '$tblItems'.");

  // ---- range waktu (inklusif) ----
  $startDT = $start.' 00:00:00';
  // end exclusive = end + 1 hari
  $endEx   = (new DateTime($end))->modify('+1 day')->format('Y-m-d').' 00:00:00';

  // ---- summary harian ----
  // Gunakan WIB untuk grup harian, tapi tetap filter UTC (server) aman.
  $sumSql = "
    SELECT
      DATE(CONVERT_TZ($tsCol, '+00:00', '+07:00')) AS d_wib,
      COUNT(*) AS count_tx,
      SUM($totalCol) AS amount
    FROM $tblOrders
    WHERE $tsCol >= ? AND $tsCol < ?
    GROUP BY DATE(CONVERT_TZ($tsCol, '+00:00', '+07:00'))
    ORDER BY d_wib ASC
  ";
  $st = $pdo->prepare($sumSql);
  $st->execute([$startDT, $endEx]);
  $summary = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $summary[] = [
      'date'       => $r['d_wib'],
      'count'      => (int)$r['count_tx'],
      'total'      => (float)$r['amount'],
    ];
  }

  // ---- top produk ----
  $topSql = "
    SELECT p.name,
           SUM(oi.$qtyCol)    AS qty,
           SUM(oi.$qtyCol * oi.$priceCol) AS revenue
    FROM $tblItems oi
    JOIN $tblOrders o ON o.id = oi.order_id
    LEFT JOIN $tblProd p ON p.id = oi.product_id
    WHERE o.$tsCol >= ? AND o.$tsCol < ?
    GROUP BY oi.product_id, p.name
    HAVING qty > 0
    ORDER BY qty DESC, revenue DESC
    LIMIT 100
  ";
  $st = $pdo->prepare($topSql);
  $st->execute([$startDT, $endEx]);
  $top = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $top[] = [
      'name'    => $r['name'] ?? '(Tanpa Nama)',
      'qty'     => (int)$r['qty'],
      'revenue' => (float)$r['revenue'],
    ];
  }

  echo json_encode(['ok'=>true,'summary'=>$summary,'top_products'=>$top], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
