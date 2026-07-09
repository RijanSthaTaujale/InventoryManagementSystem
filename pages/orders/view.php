<?php
// pages/orders/view.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'order';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$isSuper    = $user['role'] === 'supervisor';
$isStaff    = $user['role'] === 'staff';
$currency   = 'Rs';

$orderId = trim($_GET['id'] ?? '');
if (!$orderId) redirect('/pages/orders/index.php');

$stmt = $pdo->prepare("
    SELECT o.*, u1.name AS assigned_name, u2.name AS dispatched_name, u3.name AS created_name
    FROM orders o
    LEFT JOIN users u1 ON u1.id = o.assigned_to
    LEFT JOIN users u2 ON u2.id = o.dispatched_by
    LEFT JOIN users u3 ON u3.id = o.created_by
    WHERE o.order_id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) redirect('/pages/orders/index.php');

$pageTitle = 'Order ' . $order['order_id'];

// Order items
$itemsStmt = $pdo->prepare("
    SELECT oi.*, p.image_url, p.product_id AS pid
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();

// Status log
$logStmt = $pdo->prepare("
    SELECT osl.*, u.name AS changed_by_name
    FROM order_status_log osl
    LEFT JOIN users u ON u.id = osl.changed_by
    WHERE osl.order_id = ?
    ORDER BY osl.created_at DESC
");
$logStmt->execute([$order['id']]);
$statusLog = $logStmt->fetchAll();

// Badge map
$badgeMap = [
    'new'        => 'badge-new',
    'confirmed'  => 'badge-confirmed',
    'pending'    => 'badge-pending',
    'cancelled'  => 'badge-cancelled',
    'dispatched' => 'badge-dispatched',
    'delivered'  => 'badge-delivered',
    'returned'   => 'badge-returned',
    'in_courier' => 'badge-in_courier',
];
$badge    = $badgeMap[$order['status']] ?? 'badge-pending';
$payBadge = $order['payment_status'] === 'paid' ? 'badge-confirmed' : ($order['payment_status'] === 'partial' ? 'badge-pending' : 'badge-cancelled');

// What status changes are allowed per role?
// Admin: everything
// Supervisor: everything
// Staff: new, confirmed, pending, cancelled, dispatched
$currentStatus = $order['status'];

$allStatusLabels = [
    'new'        => 'New',
    'confirmed'  => 'Confirmed',
    'pending'    => 'Pending',
    'cancelled'  => 'Cancelled',
    'dispatched' => 'Dispatched',
    'in_courier' => 'In Courier',
    'delivered'  => 'Delivered',
    'returned'   => 'Returned',
];

if ($isAdmin) {
    $statusOptions = $allStatusLabels;
} elseif ($isSuper) {
    $statusOptions = $allStatusLabels;
} else { // staff
    $statusOptions = [
        'new'        => 'New',
        'confirmed'  => 'Confirmed',
        'pending'    => 'Pending',
        'cancelled'  => 'Cancelled',
        'dispatched' => 'Dispatched',
    ];
}
// Always make sure the current status is present, even if outside the role's normal range
if (!isset($statusOptions[$currentStatus])) {
    $statusOptions = [$currentStatus => $allStatusLabels[$currentStatus] ?? ucfirst($currentStatus)] + $statusOptions;
}

$statusColors = [
    'new'        => '#3B5BDB',
    'confirmed'  => '#22c55e',
    'pending'    => '#f59e0b',
    'cancelled'  => '#ef4444',
    'dispatched' => '#8b5cf6',
    'delivered'  => '#10b981',
    'returned'   => '#f97316',
    'in_courier' => '#06b6d4',
];

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <!-- Breadcrumb + header -->
      <div class="flex-between mb-4">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <a href="<?= APP_URL ?>/pages/orders/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Orders
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem"><?= e($order['order_id']) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <h1 style="font-size:1.25rem;font-weight:700"><?= e($order['order_id']) ?></h1>
            <span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_', ' ', $order['status'])) ?></span>
            <span class="badge <?= $payBadge ?>"><?= ucfirst($order['payment_status']) ?></span>
          </div>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            Placed <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
            <?= $order['created_name'] ? ' by ' . e($order['created_name']) : '' ?>
          </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <select id="statusDropdown" class="form-control"
                  style="width:auto;min-width:150px;font-weight:600"
                  onchange="changeStatus(this.value)">
            <?php foreach ($statusOptions as $val => $label): ?>
            <option value="<?= $val ?>" <?= $val === $currentStatus ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <a href="<?= APP_URL ?>/pages/orders/bill.php?id=<?= urlencode($order['order_id']) ?>" target="_blank" class="btn btn-outline btn-sm">Print Bill</a>
          <?php if (in_array($order['status'], ['new', 'pending', 'confirmed'])): ?>
          <a href="<?= APP_URL ?>/pages/orders/create.php?edit=<?= urlencode($order['order_id']) ?>" class="btn btn-outline btn-sm">Edit Order</a>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
          <a href="<?= APP_URL ?>/pages/orders/create.php" class="btn btn-outline btn-sm">New Order</a>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">

        <!-- LEFT -->
        <div style="display:flex;flex-direction:column;gap:16px">

          <!-- Order items -->
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Order Items</div>
            <div class="data-table-wrap" style="border:none">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th style="width:60px">Qty</th>
                    <th style="width:110px">Unit Price</th>
                    <th style="width:110px">Total</th>
                    <?php if ($isAdmin): ?><th style="width:100px">Buy Price</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $item): ?>
                  <tr>
                    <td>
                      <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:36px;height:36px;background:var(--bg);border-radius:var(--radius-sm);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center">
                          <?php if ($item['image_url']): ?>
                            <img src="<?= e(productImageUrl($item['image_url'])) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none'">
                          <?php else: ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                          <?php endif; ?>
                        </div>
                        <div>
                          <div style="font-weight:600;font-size:.85rem"><?= e($item['product_name']) ?></div>
                          <?php if ($item['variant_info']): ?>
                          <div style="font-size:.74rem;color:var(--text-muted)"><?= e($item['variant_info']) ?></div>
                          <?php endif; ?>
                          <?php if ($item['pid']): ?>
                          <div style="font-size:.72rem;color:var(--text-muted)"><?= e($item['pid']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td style="font-weight:700;text-align:center"><?= $item['qty'] ?></td>
                    <td><?= $currency ?> <?= number_format($item['sell_price'], 0) ?></td>
                    <td style="font-weight:600"><?= $currency ?> <?= number_format($item['total'], 0) ?></td>
                    <?php if ($isAdmin): ?>
                    <td class="text-muted"><?= $currency ?> <?= number_format($item['buy_price'], 0) ?></td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Totals -->
            <div style="border-top:1px solid var(--border);margin-top:12px;padding-top:14px;display:flex;flex-direction:column;gap:8px;max-width:300px;margin-left:auto">
              <div style="display:flex;justify-content:space-between;font-size:.87rem;color:var(--text-secondary)">
                <span>Subtotal</span>
                <span style="font-weight:600;color:var(--text)"><?= $currency ?> <?= number_format($order['subtotal'], 0) ?></span>
              </div>
              <?php if ($order['discount'] > 0): ?>
              <div style="display:flex;justify-content:space-between;font-size:.87rem;color:var(--text-secondary)">
                <span>Discount<?= $order['discount_type'] === 'percent' ? ' (%)' : '' ?></span>
                <span style="font-weight:600;color:#ef4444">- <?= $currency ?> <?= number_format($order['discount'], 0) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($order['shipping_cost'] > 0): ?>
              <div style="display:flex;justify-content:space-between;font-size:.87rem;color:var(--text-secondary)">
                <span>Shipping<?= $order['shipping_method'] ? ' (' . e($order['shipping_method']) . ')' : '' ?></span>
                <span style="font-weight:600;color:var(--text)"><?= $currency ?> <?= number_format($order['shipping_cost'], 0) ?></span>
              </div>
              <?php endif; ?>
              <div style="display:flex;justify-content:space-between;font-size:1rem;font-weight:700;padding-top:8px;border-top:1px solid var(--border)">
                <span>Total</span>
                <span style="color:var(--primary)"><?= $currency ?> <?= number_format($order['total'], 0) ?></span>
              </div>
              <?php if ($isAdmin): ?>
              <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--text-muted);margin-top:2px">
                <span>Profit (est.)</span>
                <?php
                $cost   = array_sum(array_map(fn($i) => $i['buy_price'] * $i['qty'], $items));
                $profit = $order['total'] - $cost - $order['shipping_cost'];
                ?>
                <span style="color:<?= $profit >= 0 ? '#22c55e' : '#ef4444' ?>;font-weight:600"><?= $currency ?> <?= number_format($profit, 0) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Status timeline -->
          <?php if (!empty($statusLog)): ?>
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Status History</div>
            <div style="display:flex;flex-direction:column;gap:0">
              <?php foreach ($statusLog as $log):
                $color = $statusColors[$log['to_status']] ?? '#64748b';
                $diff  = time() - strtotime($log['created_at']);
                $ago   = $diff < 60 ? 'just now' : ($diff < 3600 ? floor($diff/60).'m ago' : ($diff < 86400 ? floor($diff/3600).'h ago' : date('d M Y', strtotime($log['created_at']))));
              ?>
              <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;margin-top:5px;flex-shrink:0"></div>
                <div style="flex:1">
                  <div style="font-size:.84rem">
                    <?php if ($log['from_status']): ?>
                    <span style="color:var(--text-muted)"><?= ucfirst(str_replace('_',' ',$log['from_status'])) ?></span>
                    <span style="color:var(--text-muted)"> → </span>
                    <?php endif; ?>
                    <span style="font-weight:700;color:<?= $color ?>"><?= ucfirst(str_replace('_',' ',$log['to_status'])) ?></span>
                  </div>
                  <div style="font-size:.76rem;color:var(--text-muted);margin-top:2px">
                    <?= $log['changed_by_name'] ? e($log['changed_by_name']) . ' · ' : '' ?><?= $ago ?>
                  </div>
                  <?php if ($log['note']): ?>
                  <div style="font-size:.78rem;color:var(--text-secondary);margin-top:3px"><?= e($log['note']) ?></div>
                  <?php endif; ?>
                </div>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= date('d M, h:i A', strtotime($log['created_at'])) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <!-- RIGHT -->
        <div style="display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--topbar-h)+24px)">

          <!-- Customer -->
          <div class="card">
            <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Customer</div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
              <div style="width:38px;height:38px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;font-weight:700;flex-shrink:0">
                <?= strtoupper(substr($order['customer_name'], 0, 1)) ?>
              </div>
              <div>
                <div style="font-weight:700;font-size:.9rem"><?= e($order['customer_name']) ?></div>
                <?php if ($order['customer_phone']): ?>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= e($order['customer_phone']) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($order['customer_email']): ?>
            <div style="display:flex;align-items:center;gap:7px;font-size:.82rem;color:var(--text-secondary);margin-bottom:8px">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
              <?= e($order['customer_email']) ?>
            </div>
            <?php endif; ?>
            <?php if ($order['customer_address']): ?>
            <div style="display:flex;align-items:flex-start;gap:7px;font-size:.82rem;color:var(--text-secondary)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?= nl2br(e($order['customer_address'])) ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Payment & shipping -->
          <div class="card">
            <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Payment & Shipping</div>
            <div style="display:flex;flex-direction:column;gap:9px;font-size:.84rem">
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-secondary)">Payment</span>
                <span style="font-weight:600"><?= e($order['payment_method'] ?? '—') ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="color:var(--text-secondary)">Pay Status</span>
                <span class="badge <?= $payBadge ?>"><?= ucfirst($order['payment_status']) ?></span>
              </div>
              <?php if ($order['shipping_method']): ?>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-secondary)">Shipping</span>
                <span style="font-weight:600"><?= e($order['shipping_method']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($order['dispatched_at']): ?>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-secondary)">Dispatched</span>
                <span style="font-weight:600"><?= date('d M Y', strtotime($order['dispatched_at'])) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($order['delivered_at']): ?>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-secondary)">Delivered</span>
                <span style="font-weight:600"><?= date('d M Y', strtotime($order['delivered_at'])) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($order['dispatched_name']): ?>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-secondary)">Dispatched by</span>
                <span style="font-weight:600"><?= e($order['dispatched_name']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Remarks -->
          <?php if ($order['remarks']): ?>
          <div class="card">
            <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Staff Notes</div>
            <p style="font-size:.84rem;color:var(--text-secondary);line-height:1.6"><?= nl2br(e($order['remarks'])) ?></p>
          </div>
          <?php endif; ?>

          <!-- Admin: quick payment update -->
          <?php if ($isAdmin): ?>
          <div class="card">
            <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Update Payment</div>
            <div style="display:flex;gap:6px">
              <?php foreach (['unpaid'=>'Unpaid','paid'=>'Paid','partial'=>'Partial','refunded'=>'Refunded'] as $ps=>$pl): ?>
              <button onclick="updatePayment('<?= $ps ?>')"
                      class="btn btn-xs <?= $order['payment_status']===$ps ? 'btn-primary' : 'btn-outline' ?>">
                <?= $pl ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>

    </main>
  </div>
</div>

<!-- Status change confirmation modal -->
<div id="statusModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px" id="modalTitle"></div>
    <p style="font-size:.86rem;color:var(--text-secondary);margin-bottom:20px" id="modalMsg"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('statusModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" id="modalConfirmBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL   = '<?= APP_URL ?>';
const ORDER_ID  = '<?= e($order['order_id']) ?>';

function changeStatus(newStatus) {
  const dropdown = document.getElementById('statusDropdown');
  const prevStatus = '<?= $currentStatus ?>';
  if (newStatus === prevStatus) return;

  const label = dropdown.options[dropdown.selectedIndex].text;
  document.getElementById('modalTitle').textContent = label;
  document.getElementById('modalMsg').textContent   = `Change order ${ORDER_ID} status to "${label}"?`;
  document.getElementById('statusModal').style.display = 'flex';

  document.getElementById('modalConfirmBtn').onclick = async () => {
    document.getElementById('statusModal').style.display = 'none';
    dropdown.disabled = true;
    const r = await fetch(`${APP_URL}/api/orders.php?action=status`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ order_id: ORDER_ID, status: newStatus })
    });
    const d = await r.json();
    dropdown.disabled = false;
    if (d.success) { showToast('Status updated', 'success'); setTimeout(() => location.reload(), 700); }
    else { dropdown.value = prevStatus; showToast(d.message || 'Failed', 'error'); }
  };

  // If the modal is dismissed via Cancel button, revert the dropdown
  const cancelBtns = document.querySelectorAll('#statusModal .btn-outline');
  cancelBtns.forEach(btn => {
    btn.onclick = () => {
      document.getElementById('statusModal').style.display = 'none';
      dropdown.value = prevStatus;
    };
  });
}

<?php if ($isAdmin): ?>
async function updatePayment(status) {
  const r = await fetch(`${APP_URL}/api/orders.php?action=payment`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ order_id: ORDER_ID, payment_status: status })
  });
  const d = await r.json();
  if (d.success) { showToast('Payment updated', 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message || 'Failed', 'error');
}
<?php endif; ?>
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>