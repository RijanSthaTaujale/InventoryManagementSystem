<?php
// pages/orders/exchange.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if (!in_array($user['role'], ['admin', 'supervisor'], true)) redirect('/pages/dashboard.php');

$activePage = 'exchanges';
$pageTitle  = 'Exchanges';
$currency   = 'Rs';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$total = (int)$pdo->query("SELECT COUNT(*) FROM order_returns WHERE is_exchange=1 AND received_at IS NULL")->fetchColumn();
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
    WHERE r.is_exchange = 1 AND r.received_at IS NULL
    ORDER BY r.created_at ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute();
$rows = $stmt->fetchAll();

$totalUnits = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM order_returns WHERE is_exchange=1 AND received_at IS NULL")->fetchColumn();

$baseUrl = APP_URL . '/pages/orders/exchange.php';

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
            <span style="font-size:.82rem">Exchanges</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Exchanges Awaiting Receipt</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($total) ?> pending · <?= number_format($totalUnits) ?> units
          </p>
        </div>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Original Order</th>
              <th>Product</th>
              <th>Qty &amp; Amount</th>
              <th>Requested By</th>
              <th>Replacement Order</th>
              <th style="width:260px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">No exchanges awaiting receipt</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
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
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn btn-primary btn-xs" onclick="confirmReceived(<?= (int)$r['id'] ?>, false)">Confirm Received</button>
                  <button class="btn btn-outline btn-xs" style="color:#dc2626;border-color:#fca5a5" onclick="confirmReceived(<?= (int)$r['id'] ?>, true)">Mark Damaged</button>
                  <button class="btn btn-outline btn-xs" onclick="cancelExchange(<?= (int)$r['id'] ?>)">Cancel</button>
                </div>
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

async function cancelExchange(returnId) {
  if (!confirm('Cancel this pending exchange? The amount will be added back to the original order.')) return;

  const r = await fetch(`${APP_URL}/api/orders.php?action=exchange_cancel`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ return_id: returnId })
  });
  const d = await r.json();
  if (d.success) { showToast('Exchange cancelled', 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message || 'Failed', 'error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
