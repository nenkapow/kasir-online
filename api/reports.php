<?php
require_once __DIR__ . '/config.php';
check_pin();
$pdo = db();

$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d');
// include end day
$end_dt = date('Y-m-d 23:59:59', strtotime($end));

// Summary
$sumStmt = $pdo->prepare("SELECT DATE(created_at) as day, COUNT(*) as tx_count, SUM(total) as revenue
                          FROM sales
                          WHERE created_at BETWEEN :s AND :e
                          GROUP BY DATE(created_at)
                          ORDER BY day ASC");
$sumStmt->execute([':s'=>$start, ':e'=>$end_dt]);
$summary = $sumStmt->fetchAll();

// Top products
$topStmt = $pdo->prepare("SELECT p.id, p.name, SUM(si.qty) as qty_sold, SUM(si.qty*si.price) as gross
                          FROM sale_items si
                          JOIN products p ON p.id = si.product_id
                          JOIN sales s ON s.id = si.sale_id
                          WHERE s.created_at BETWEEN :s AND :e
                          GROUP BY p.id, p.name
                          ORDER BY qty_sold DESC
                          LIMIT 20");
$topStmt->execute([':s'=>$start, ':e'=>$end_dt]);
$top = $topStmt->fetchAll();

ok(['summary'=>$summary, 'top_products'=>$top]);
?>
