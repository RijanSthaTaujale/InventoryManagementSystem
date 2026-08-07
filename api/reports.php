<?php
// api/reports.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';

$user    = currentUser();
$isAdmin = $user['role'] === 'admin';
$action  = $_GET['action'] ?? '';

// ── EXPORT PRODUCT PERFORMANCE AS CSV ─────────────────────────
// Same query/date-range logic as the Reports page's Product Performance
// table, just without the pagination limit — Revenue/Profit/Margin columns
// are admin-only, matching what the page itself shows non-admin roles.
if ($action === 'export_product_performance' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
    $dFrom    = $dateFrom . ' 00:00:00';
    $dTo      = $dateTo   . ' 23:59:59';

    $stmt = $pdo->prepare("
        SELECT p.name, p.product_id,
               SUM(oi.qty) AS sold_qty,
               SUM(oi.total) AS revenue,
               SUM((oi.sell_price - oi.buy_price) * oi.qty) AS profit
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE o.dispatched_at BETWEEN ? AND ?
        GROUP BY oi.product_id
        ORDER BY sold_qty DESC
    ");
    $stmt->execute([$dFrom, $dTo]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product_performance_' . $dateFrom . '_to_' . $dateTo . '.csv"');

    $out = fopen('php://output', 'w');
    $header = ['Product Code', 'Product Name', 'Sold Qty'];
    if ($isAdmin) { $header = array_merge($header, ['Revenue', 'Profit', 'Margin %']); }
    fputcsv($out, $header);

    foreach ($rows as $r) {
        $row = [$r['product_id'], $r['name'], $r['sold_qty']];
        if ($isAdmin) {
            $margin = $r['revenue'] > 0 ? round(($r['profit'] / $r['revenue']) * 100, 1) : 0;
            $row = array_merge($row, [$r['revenue'], $r['profit'], $margin]);
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
