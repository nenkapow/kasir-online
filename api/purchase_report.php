<?php
// /api/purchase_report.php
// Laporan pembelian: ringkasan per hari, rekap per supplier, dan detail item.
// Params (GET):
//   start=YYYY-MM-DD  (required)
//   end=YYYY-MM-DD    (required)  — inclusive
//   supplier=...      (optional, substring match)
//   q=...             (optional, cari SKU/Nama)
// Output:
//   { ok, range: {start,end}, summary:[{date, invoices, total}], suppliers:[{supplier,total,invoices}],
//     items:[{sku,name,qty,total,avg_cost,last_cost}], invoices:[{invoice_code,supplier,total,created_at_wib,items:[...] }] }

require_once __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $start = trim($_GET['start'] ?? '');
  $end   = trim($_GET['end'] ?? '');
  if ($start === '' || $end === '') {
    throw new Exception('Parameter start & end wajib ada (YYYY-MM-DD).');
  }
  // normalisasi end agar inclusive (tambah 1 hari, pakai < nextDay)
  $endObj = new DateTime($end, new DateTimeZone('UTC'));
  $endObj->modify('+1 day');
  $endExclusive = $endObj->format('Y-m-d');

  $supplier = trim($_GET['supplier'] ?? '');
  $q = trim($_GET['q'] ?? '');

  $w = "p.created_at >= ? AND p.created_at < ?";
  $params = [$start, $endExclusive];

  if ($supplier !== '') {
    $w .= " AND p.supplier_name LIKE ?";
    $params[] = '%' . $supplier . '%';
  }

  // ============ RINGKASAN HARIAN ============
  $sqlSummary = "
    SELECT
      DATE(CONVERT_TZ(p.created_at,'+00:00','+07:00')) AS dt,
      COUNT(*) AS invoices,
      SUM(p.total) AS total
    FROM purchases p
    WHERE $w
    GROUP BY dt
    ORDER BY dt ASC
  ";
  $st = $pdo->prepare($sqlSummary);
  $st->execute($params);
  $summary = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $summary[] = [
      'date' => $r['dt'],
      'invoices' => (int)$r['invoices'],
      'total' => (float)$r['total'],
    ];
  }

  // ============ REKAP SUPPLIER ============
  $sqlSup = "
    SELECT COALESCE(p.supplier_name,'') AS supplier,
           COUNT(*) AS invoices,
           SUM(p.total) AS total
    FROM purchases p
    WHERE $w
    GROUP BY supplier
    ORDER BY total DESC
  ";
  $st = $pdo->prepare($sqlSup);
  $st->execute($params);
  $suppliers = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $suppliers[] = [
      'supplier' => $r['supplier'],
      'invoices' => (int)$r['invoices'],
      'total' => (float)$r['total'],
    ];
  }

  // Tambahan filter q untuk detail item
  $wItem = $w;
  $paramsItem = $params;
  if ($q !== '') {
    $wItem .= " AND (pr.sku LIKE ? OR pr.name LIKE ?)";
    $paramsItem[] = '%' . $q . '%';
    $paramsItem[] = '%' . $q . '%';
  }

  // ============ DETAIL ITEM (GROUPED) ============
  $sqlItems = "
    SELECT
      pr.sku, pr.name,
      SUM(pi.qty) AS qty,
      SUM(pi.subtotal) AS total,
      -- avg_cost dihitung total/qty
      CASE WHEN SUM(pi.qty) > 0 THEN SUM(pi.subtotal)/SUM(pi.qty) ELSE 0 END AS avg_cost,
      -- last_cost: harga pembelian terakhir di range
      SUBSTRING_INDEX(
        GROUP_CONCAT(pi.price ORDER BY p.created_at DESC SEPARATOR ','), ',', 1
      ) AS last_cost
    FROM purchase_items pi
    JOIN purchases p ON p.id = pi.purchase_id
    JOIN products pr ON pr.id = pi.product_id
    WHERE $wItem
    GROUP BY pr.sku, pr.name
    ORDER BY total DESC
    LIMIT 2000
  ";
  $st = $pdo->prepare($sqlItems);
  $st->execute($paramsItem);
  $items = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      'sku' => $r['sku'],
      'name' => $r['name'],
      'qty' => (int)$r['qty'],
      'total' => (float)$r['total'],
      'avg_cost' => (float)$r['avg_cost'],
      'last_cost' => (float)$r['last_cost'],
    ];
  }

  // ============ LIST INVOICE + ITEM ============
  $sqlInv = "
    SELECT
      p.id, p.invoice_code, COALESCE(p.supplier_name,'') AS supplier,
      p.total,
      DATE_FORMAT(CONVERT_TZ(p.created_at,'+00:00','+07:00'), '%Y-%m-%d %H:%i:%s') AS created_at_wib
    FROM purchases p
    WHERE $w
    ORDER BY p.created_at DESC
    LIMIT 500
  ";
  $st = $pdo->prepare($sqlInv);
  $st->execute($params);
  $invRows = $st->fetchAll(PDO::FETCH_ASSOC);
  $invoices = [];
  if ($invRows) {
    $ids = array_map(fn($r) => (int)$r['id'], $invRows);
    $inQuery = implode(',', array_fill(0, count($ids), '?'));

    $sqlItemsPerInv = "
      SELECT pi.purchase_id, pr.sku, pr.name, pi.qty, pi.price, pi.subtotal
      FROM purchase_items pi
      JOIN products pr ON pr.id = pi.product_id
      WHERE pi.purchase_id IN ($inQuery)
      ORDER BY pi.purchase_id ASC, pr.name ASC
    ";
    $sti = $pdo->prepare($sqlItemsPerInv);
    $sti->execute($ids);

    $byInv = [];
    while ($ri = $sti->fetch(PDO::FETCH_ASSOC)) {
      $pid = (int)$ri['purchase_id'];
      if (!isset($byInv[$pid])) $byInv[$pid] = [];
      $byInv[$pid][] = [
        'sku' => $ri['sku'],
        'name' => $ri['name'],
        'qty' => (int)$ri['qty'],
        'price' => (float)$ri['price'],
        'subtotal' => (float)$ri['subtotal'],
      ];
    }

    foreach ($invRows as $r) {
      $pid = (int)$r['id'];
      $invoices[] = [
        'invoice_code' => $r['invoice_code'],
        'supplier' => $r['supplier'],
        'total' => (float)$r['total'],
        'created_at_wib' => $r['created_at_wib'],
        'items' => $byInv[$pid] ?? [],
      ];
    }
  }

  echo json_encode([
    'ok' => true,
    'range' => ['start'=>$start, 'end'=>$end],
    'summary' => $summary,
    'suppliers' => $suppliers,
    'items' => $items,
    'invoices' => $invoices
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
