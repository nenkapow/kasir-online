<?php
declare(strict_types=1);
// api/report.php — Laporan penjualan (summary harian + produk terlaris)

require_once __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();

  $start = $_GET['start'] ?? $_POST['start'] ?? date('Y-m-d');
  $end   = $_GET['end']   ?? $_POST['end']   ?? $start;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    throw new Exception('Format tanggal harus YYYY-MM-DD');
  }

  $tblOrders = 'orders';
  $tblItems  = 'order_items';
  $tblProd   = 'products';

  // cek nama database aktif
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

  // helper cek kolom
  $colExists = function(string $table, string $col) use ($pdo,$db): bool {
    $q = "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=? AND table_name=? AND column_name=?";
    $st = $pdo->prepare($q);
    $st->execute([$db,$table,$col]);
    return (bool)$st->fetchColumn();
  };

  // ------ DETEKSI KOLOM WAKTU DI 'orders' ------
  // 1) kandidat nama umum (prioritas)
  $prefer = ['created_at','createdAt','order_at','orderAt','ts','created','date','order_date'];
  $tsCol = null;
  foreach ($prefer as $c) {
    if ($colExists($tblOrders,$c)) { $tsCol = $c; break; }
  }
  // 2) kalau belum dapat, ambil kolom bertipe datetime/timestamp/date mana pun
  if (!$tsCol) {
    $q = "SELECT column_name, data_type
          FROM information_schema.columns
          WHERE table_schema=? AND table_name=? AND data_type IN ('datetime','timestamp','date')
          ORDER BY ordinal_position ASC";
    $st = $pdo->prepare($q);
    $st->execute([$db,$tblOrders]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $tsCol = $row['column_name'];
  }
  if (!$tsCol) {
    throw new Exception("Tidak ada kolom bertipe waktu (DATETIME/TIMESTAMP/DATE) di tabel '$tblOrders'.");
  }

  // ------ kolom lain yang diperlukan ------
  $totalCol = $colExists($tblOrders,'total') ? 'total' : null;
  if (!$totalCol) throw new Exception("Kolom 'total' tidak ada di '$tblOrders'.");

  $qtyCol   = $colExists($tblItems,'qty')   ? 'qty'   : null;
  $priceCol = $colExists($tblItems,'price') ? 'price' : null;
  if (!$qtyCol || !$priceCol) throw new Exception("Kolom qty/price tidak ada di '$tblItems'.");

  // ------ range waktu (inklusif) ------
  $startDT = $start.' 00:00:00';
  $endEx   = (new DateTime($end))->modify('+1 day')->format('Y-m-d').' 00:00:00';

  // Jika kolom bertipe DATE, cukup bandingkan DATE
  $isDateOnly = false;
  $st = $pdo->prepare("SELECT data_type FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?");
  $st->execute([$db,$tblOrders,$tsCol]);
  $dtype = strtolower((string)$st->fetchColumn());
  if ($dtype === 'date') $isDateOnly = true;

  // ------ SUMMARY HARIAN ------
  if ($isDateOnly) {
    // kolom DATE langsung dipakai, end inklusif
    $sumSql = "
      SELECT $tsCol AS d_wib, COUNT(*) AS count_tx, SUM($totalCol) AS amount
      FROM $tblOrders
      WHERE $tsCol >= ? AND $tsCol <= ?
      GROUP BY $tsCol
      ORDER BY $tsCol ASC
    ";
    $sumParams = [$start, $end];
  } else {
    // anggap UTC di server, konversi ke WIB saat group by
    $sumSql = "
      SELECT DATE(CONVERT_TZ($tsCol, '+00:00', '+07:00')) AS d_wib,
             COUNT(*) AS count_tx, SUM($totalCol) AS amount
      FROM $tblOrders
      WHERE $tsCol >= ? AND $tsCol < ?
      GROUP BY DATE(CONVERT_TZ($tsCol, '+00:00', '+07:00'))
      ORDER BY d_wib ASC
    ";
    $sumParams = [$startDT, $endEx];
  }
  $st = $pdo->prepare($sumSql);
  $st->execute($sumParams);
  $summary = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $summary[] = [
      'date'  => $r['d_wib'],
      'count' => (int)$r['count_tx'],
      'total' => (float)$r['amount'],
    ];
  }

  // ------ TOP PRODUCTS ------
  if ($isDateOnly) {
    $topSql = "
      SELECT p.name,
             SUM(oi.$qtyCol) AS qty,
             SUM(oi.$qtyCol * oi.$priceCol) AS revenue
      FROM $tblItems oi
      JOIN $tblOrders o ON o.id = oi.order_id
      LEFT JOIN $tblProd p ON p.id = oi.product_id
      WHERE o.$tsCol >= ? AND o.$tsCol <= ?
      GROUP BY oi.product_id, p.name
      HAVING qty > 0
      ORDER BY qty DESC, revenue DESC
      LIMIT 100
    ";
    $topParams = [$start, $end];
  } else {
    $topSql = "
      SELECT p.name,
             SUM(oi.$qtyCol) AS qty,
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
    $topParams = [$startDT, $endEx];
  }
  $st = $pdo->prepare($topSql);
  $st->execute($topParams);
  $top = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $top[] = [
      'name'    => $r['name'] ?? '(Tanpa Nama)',
      'qty'     => (int)$r['qty'],
      'revenue' => (float)$r['revenue'],
    ];
  }

  echo json_encode(['ok'=>true, 'ts_col'=>$tsCol, 'summary'=>$summary, 'top_products'=>$top], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
