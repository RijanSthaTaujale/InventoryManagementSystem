<?php
// pages/orders/exchange.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if (!in_array($user['role'], ['admin', 'supervisor'], true)) redirect('/pages/dashboard.php');

$activePage = 'exchanges';
$pageTitle  = 'Returns';
$currency   = 'Rs';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$search   = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';

// Status is derived (received_at/received_damaged), not a stored enum, so
// the filter translates to a condition rather than a plain column match.
$statusFilter = $_GET['status'] ?? '';
$statusWhere  = '';
if ($statusFilter === 'pending')       $statusWhere = 'AND r.received_at IS NULL';
elseif ($statusFilter === 'received')  $statusWhere = 'AND r.received_at IS NOT NULL AND r.received_damaged = 0';
elseif ($statusFilter === 'damaged')   $statusWhere = 'AND r.received_at IS NOT NULL AND r.received_damaged = 1';
else $statusFilter = '';

// Counts for the tab bar (always unfiltered by search/date/status, so the
// tabs show stable totals no matter what's currently searched/filtered —
// same convention as the status tabs on the Orders list).
$statusCounts = [
    ''         => (int)$pdo->query("SELECT COUNT(*) FROM order_returns WHERE is_exchange=1")->fetchColumn(),
    'pending'  => (int)$pdo->query("SELECT COUNT(*) FROM order_returns WHERE is_exchange=1 AND received_at IS NULL")->fetchColumn(),
    'received' => (int)$pdo->query("SELECT COUNT(*) FROM order_returns WHERE is_exchange=1 AND received_at IS NOT NULL AND received_damaged=0")->fetchColumn(),
    'damaged'  => (int)$pdo->query("SELECT COUNT(*) FROM order_returns WHERE is_exchange=1 AND received_at IS NOT NULL AND received_damaged=1")->fetchColumn(),
];

$searchWhere = '';
$searchParams = [];
if ($search) {
    $searchWhere = "AND (o.order_id LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR oi.product_name LIKE ? OR n.order_id LIKE ?)";
    $like = "%$search%";
    $searchParams = [$like, $like, $like, $like, $like];
}
$dateWhere = '';
if ($dateFrom) { $dateWhere .= " AND DATE(r.created_at) >= ?"; $searchParams[] = $dateFrom; }
if ($dateTo)   { $dateWhere .= " AND DATE(r.created_at) <= ?"; $searchParams[] = $dateTo; }

// The list shows full history, not just what's pending — settled rows stay
// visible (marked Received/Damaged) rather than disappearing once actioned.
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM order_returns r
    JOIN order_items oi ON oi.id = r.order_item_id
    JOIN orders o ON o.id = r.order_id
    LEFT JOIN orders n ON n.id = r.new_order_id
    WHERE r.is_exchange = 1 $statusWhere $searchWhere $dateWhere
");
$countStmt->execute($searchParams);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT r.*, oi.product_name, oi.variant_info,
           o.order_id AS original_order_id, o.customer_name, o.customer_phone,
           n.order_id AS new_order_id_str,
           u.name AS requested_by_name
    FROM order_returns r
    JOIN order_items oi ON oi.id = r.order_item_id
    JOIN orders o ON o.id = r.order_id
    LEFT JOIN orders n ON n.id = r.new_order_id
    LEFT JOIN users u ON u.id = r.returned_by
    WHERE r.is_exchange = 1 $statusWhere $searchWhere $dateWhere
    ORDER BY (r.received_at IS NULL) DESC, r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($searchParams);
$rows = $stmt->fetchAll();

$pendingCount = $statusCounts['pending'];
$pendingUnits = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM order_returns WHERE is_exchange=1 AND received_at IS NULL")->fetchColumn();

$baseUrl = APP_URL . '/pages/orders/exchange.php?' . http_build_query(array_filter(['status'=>$statusFilter,'search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo]));

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <div class="flex-between mb-4">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <a href="<?= APP_URL ?>/pages/orders/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Orders
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem">Returns</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Returns</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($pendingCount) ?> pending · <?= number_format($pendingUnits) ?> units awaiting receipt
          </p>
        </div>
      </div>

      <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
        <?php foreach (['' => 'All', 'pending' => 'Pending', 'received' => 'Received', 'damaged' => 'Damaged'] as $val => $label):
          $tabQ = http_build_query(array_filter(['status'=>$val,'search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo]));
        ?>
        <a href="<?= APP_URL ?>/pages/orders/exchange.php<?= $tabQ ? '?' . $tabQ : '' ?>"
           class="btn btn-sm <?= $statusFilter === $val ? 'btn-primary' : 'btn-outline' ?>">
          <?= $label ?> (<?= number_format($statusCounts[$val]) ?>)
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Search + date filter -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
        <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
        <div style="position:relative;flex:1;min-width:220px;max-width:320px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="<?= e($search) ?>" placeholder="Order ID, customer, phone, product..." class="form-control" style="padding-left:32px">
        </div>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" style="width:auto" title="From date">
        <input type="date" name="date_to"   value="<?= e($dateTo)   ?>" class="form-control" style="width:auto" title="To date">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search || $dateFrom || $dateTo): ?>
        <a href="<?= APP_URL ?>/pages/orders/exchange.php<?= $statusFilter ? '?status=' . urlencode($statusFilter) : '' ?>" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Original Order</th>
              <th>Product</th>
              <th>Qty &amp; Amount</th>
              <th>Requested By</th>
              <th>Replacement Order</th>
              <th style="width:110px">Status</th>
              <th style="width:260px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">No returns yet</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $isPending = $r['received_at'] === null; ?>
            <tr>
              <td>
                <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($r['original_order_id']) ?>" style="font-weight:700;color:var(--primary);font-size:.82rem"><?= e($r['original_order_id']) ?></a>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($r['customer_name']) ?><?= $r['customer_phone'] ? ' · ' . e($r['customer_phone']) : '' ?></div>
              </td>
              <td>
                <div style="font-weight:600;font-size:.85rem"><?= e($r['product_name']) ?></div>
                <?php if ($r['variant_info']): ?>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($r['variant_info']) ?></div>
                <?php endif; ?>
                <?php if ($r['reason']): ?>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">"<?= e($r['reason']) ?>"</div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-weight:700"><?= (int)$r['qty'] ?> unit<?= $r['qty']!=1?'s':'' ?></div>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= $currency ?> <?= number_format($r['amount'],0) ?></div>
              </td>
              <td>
                <div style="font-size:.8rem"><?= e($r['requested_by_name'] ?? '—') ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></div>
              </td>
              <td>
                <?php if ($r['new_order_id_str']): ?>
                <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($r['new_order_id_str']) ?>" style="font-weight:700;color:var(--primary);font-size:.82rem"><?= e($r['new_order_id_str']) ?></a>
                <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isPending): ?>
                <span class="badge" style="background:#fef3c7;color:#92400e">Pending</span>
                <?php elseif ($r['received_damaged']): ?>
                <span class="badge" style="background:#fee2e2;color:#b91c1c">Damaged</span>
                <?php else: ?>
                <span class="badge" style="background:#dcfce7;color:#166534">Received</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isPending): ?>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn btn-primary btn-xs" onclick="confirmReceived(<?= (int)$r['id'] ?>, false)">Confirm Received</button>
                  <button class="btn btn-outline btn-xs" style="color:#dc2626;border-color:#fca5a5" onclick="confirmReceived(<?= (int)$r['id'] ?>, true)">Mark Damaged</button>
                  <button class="btn btn-outline btn-xs" onclick="cancelExchange(<?= (int)$r['id'] ?>, <?= $r['new_order_id_str'] ? 'true' : 'false' ?>)">Cancel</button>
                </div>
                <?php else: ?>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= e($r['received_damaged'] ? 'Damaged' : 'Received') ?> <?= date('d M Y, h:i A', strtotime($r['received_at'])) ?></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>

    </main>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';

async function confirmReceived(returnId, damaged) {
  const msg = damaged
    ? 'Confirm this item was received back damaged? It will be logged to Damaged Stock and NOT restocked.'
    : 'Confirm this item was received back in good condition? Stock will increase.';
  if (!confirm(msg)) return;

  const r = await fetch(`${APP_URL}/api/orders.php?action=exchange_receive`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ return_id: returnId, damaged })
  });
  const d = await r.json();
  if (d.success) { showToast(damaged ? 'Logged to Damaged Stock' : 'Received and restocked', 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message || 'Failed', 'error');
}

async function cancelExchange(returnId, isExchange) {
  const label = isExchange ? 'exchange' : 'return';
  if (!confirm(`Cancel this pending ${label}? The amount will be added back to the original order.`)) return;

  const r = await fetch(`${APP_URL}/api/orders.php?action=exchange_cancel`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ return_id: returnId })
  });
  const d = await r.json();
  if (d.success) { showToast(`${isExchange ? 'Exchange' : 'Return'} cancelled`, 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message || 'Failed', 'error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
