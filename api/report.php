<?php
require_once __DIR__ . '/_init.php';

try {
  $start = trim($_GET['start'] ?? '');
  $end   = trim($_GET['end'] ?? '');

  if ($start === '') $start = gmdate('Y-m-d');
  if ($end   === '') $end   = gmdate('Y-m-d');

  // Normalisasi agar end inklusif (23:59:59 WIB)
  // Semua created_at disimpan UTC → convert ke Asia/Jakarta untuk agregasi harian
  $tzFrom = '+00:00';
  $tzTo   = '+07:00';

  // SUMMARY per hari
  $sqlSummary = "
    SELECT
      DATE(CONVERT_TZ(s.created_at, ?, ?)) AS d,
      COUNT(*) AS trx,
      SUM(s.total) AS revenue
    FROM sales s
    WHERE DATE(CONVERT_TZ(s.created_at, ?, ?)) BETWEEN ? AND ?
    GROUP BY d
    ORDER BY d ASC
  ";
  $st1 = $pdo->prepare($sqlSummary);
  $st1->execute([$tzFrom,$tzTo,$tzFrom,$tzTo,$start,$end]);
  $summary = [];
  while ($r = $st1->fetch()) {
    $summary[] = [
      'date'    => $r['d'],
      'trx'     => (int)$r['trx'],
      'revenue' => (float)$r['revenue']
    ];
  }

  // TOP PRODUCTS (qty & sales) di rentang tanggal
  $sqlTop = "
    SELECT p.name,
           SUM(si.qty) AS qty,
           SUM(si.subtotal) AS sales
    FROM sale_items si
    JOIN sales s   ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE DATE(CONVERT_TZ(s.created_at, ?, ?)) BETWEEN ? AND ?
    GROUP BY si.product_id, p.name
    ORDER BY qty DESC, sales DESC
    LIMIT 100
  ";
  $st2 = $pdo->prepare($sqlTop);
  $st2->execute([$tzFrom,$tzTo,$start,$end]);
  $top = [];
  while ($r = $st2->fetch()) {
    $top[] = [
      'name'  => $r['name'],
      'qty'   => (int)$r['qty'],
      'sales' => (float)$r['sales']
    ];
  }

  json(['ok' => true, 'summary' => $summary, 'top' => $top]);
} catch (Throwable $e) {
  json(['ok' => false, 'error' => $e->getMessage()], 400);
}
