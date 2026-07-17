<?php
// pages/orders/index.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'order';
$pageTitle  = 'Orders';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$isSuper    = $user['role'] === 'supervisor';
$isStaff    = $user['role'] === 'staff';
$currency   = 'Rs';

$search     = trim($_GET['search'] ?? '');
$status     = $_GET['status']      ?? '';
$dateFrom   = $_GET['date_from']   ?? '';
$dateTo     = $_GET['date_to']     ?? '';
$courier    = trim($_GET['courier'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;

$validStatuses = ['new','confirmed','pending','cancelled','dispatched','in_courier','delivered','returned'];

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(o.order_id LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($status && in_array($status, $validStatuses)) {
    $where[]  = "o.status = ?";
    $params[] = $status;
}
if ($dateFrom) { $where[] = "DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "DATE(o.created_at) <= ?"; $params[] = $dateTo; }
if ($courier)  { $where[] = "o.courier_name = ?"; $params[] = $courier; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$couriers = $pdo->query("SELECT DISTINCT courier_name FROM orders WHERE courier_name IS NOT NULL AND courier_name <> '' ORDER BY courier_name")->fetchAll(PDO::FETCH_COLUMN);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT o.*, COUNT(oi.id) AS item_count,
           u.name AS assigned_name,
           uc.name AS created_by_name,
           GROUP_CONCAT(DISTINCT oi.product_name SEPARATOR ', ') AS product_names,
           fp.name AS page_name
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN users u  ON u.id  = o.assigned_to
    LEFT JOIN users uc ON uc.id = o.created_by
    LEFT JOIN fb_pages fp ON fp.id = o.fb_page_id
    $whereSQL
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Blacklist lookup (small table — load whole set once)
$blacklistSet = array_flip($pdo->query("SELECT phone FROM customer_blacklist")->fetchAll(PDO::FETCH_COLUMN));

// Duplicate detection: same phone + same day appearing more than once,
// scoped to phones present on this page for efficiency.
$pagePhones = array_values(array_unique(array_filter(array_column($orders, 'customer_phone'))));
$duplicateKeys = [];
if ($pagePhones) {
    $placeholders = implode(',', array_fill(0, count($pagePhones), '?'));
    $dupStmt = $pdo->prepare("
        SELECT customer_phone, DATE(created_at) AS d, COUNT(*) AS c
        FROM orders WHERE customer_phone IN ($placeholders)
        GROUP BY customer_phone, DATE(created_at) HAVING c > 1
    ");
    $dupStmt->execute($pagePhones);
    foreach ($dupStmt->fetchAll() as $row) {
        $duplicateKeys[$row['customer_phone'] . '|' . $row['d']] = true;
    }
}

// Status counts for tab bar
$statusCounts = [];
foreach ($validStatuses as $s) {
    $r = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status=?");
    $r->execute([$s]);
    $statusCounts[$s] = (int)$r->fetchColumn();
}
$statusCounts['all'] = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

// Revenue total (admin only)
$totalRevenue = 0;
if ($isAdmin) {
    $rev = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders o $whereSQL");
    $rev->execute($params);
    $totalRevenue = (float)$rev->fetchColumn();
}

$baseUrl = APP_URL . '/pages/orders/index.php?' . http_build_query(array_filter([
    'search' => $search, 'status' => $status,
    'date_from' => $dateFrom, 'date_to' => $dateTo, 'courier' => $courier
]));

$badgeMap = [
    'new'=>'badge-new','confirmed'=>'badge-confirmed','pending'=>'badge-pending',
    'cancelled'=>'badge-cancelled','dispatched'=>'badge-dispatched','delivered'=>'badge-delivered',
    'returned'=>'badge-returned','in_courier'=>'badge-in_courier'
];

// ── Role-based allowed status options for the dropdown ────────
// admin: everything
// supervisor: everything
// staff: new, confirmed, pending, cancelled, dispatched
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
    $allowedStatusOptions = $allStatusLabels;
} elseif ($isSuper) {
    $allowedStatusOptions = $allStatusLabels;
} else { // staff
    $allowedStatusOptions = [
        'new'        => 'New',
        'confirmed'  => 'Confirmed',
        'pending'    => 'Pending',
        'cancelled'  => 'Cancelled',
        'dispatched' => 'Dispatched',
    ];
}

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <!-- Header -->
      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700">Orders Management</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($total) ?> order<?= $total!=1?'s':'' ?> found
            <?php if ($isAdmin && $totalRevenue > 0): ?>
            &nbsp;·&nbsp; Total: <strong><?= $currency ?> <?= number_format($totalRevenue,0) ?></strong>
            <?php endif; ?>
          </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
          <a href="<?= APP_URL ?>/api/orders.php?action=export_csv&<?= http_build_query(array_filter(['search'=>$search,'status'=>$status,'date_from'=>$dateFrom,'date_to'=>$dateTo,'courier'=>$courier])) ?>" class="btn btn-outline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
          </a>
          <?php if ($isAdmin || $isSuper): ?>
          <button onclick="dispatchAllConfirmed()" class="btn btn-outline" id="dispatchAllBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            Dispatch All Confirmed
          </button>
          <button onclick="moveAllToCourier()" class="btn btn-outline" id="moveAllCourierBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            Move All to In Courier
          </button>
          <button onclick="document.getElementById('bulkDeliverModal').style.display='flex'" class="btn btn-outline" id="bulkDeliverBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Bulk Deliver by ID
          </button>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/pages/orders/create.php" class="btn btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Order
          </a>
        </div>
      </div>

      <!-- Stat cards -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
        <?php foreach ([
          ['Total','all','blue',null],
          ['New','new','blue','badge-new'],
          ['Pending','pending','orange','badge-pending'],
          ['Dispatched','dispatched','purple','badge-dispatched'],
        ] as [$label,$key,$color,$badge]): ?>
        <a href="<?= APP_URL ?>/pages/orders/index.php?status=<?= $key==='all'?'':$key ?>" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px;<?= $status===($key==='all'?'':$key)?'border-color:var(--primary);box-shadow:0 0 0 2px rgba(59,91,219,.12)':'' ?>">
            <div>
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value" style="font-size:1.3rem"><?= $statusCounts[$key] ?? 0 ?></div>
            </div>
            <div class="stat-icon <?= $color ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Status tab bar -->
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:6px">
        <a href="<?= APP_URL ?>/pages/orders/index.php<?= $search?'?search='.urlencode($search):'' ?>"
           style="display:flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--radius-sm);font-size:.78rem;font-weight:600;text-decoration:none;<?= !$status?'background:var(--primary);color:#fff':'color:var(--text-secondary)' ?>">
          All <span style="background:<?= !$status?'rgba(255,255,255,.25)':'var(--bg)' ?>;padding:1px 6px;border-radius:9999px;font-size:.7rem"><?= $statusCounts['all'] ?></span>
        </a>
        <?php foreach ($validStatuses as $s):
          $active = $status === $s;
          $cnt    = $statusCounts[$s] ?? 0;
          $q      = http_build_query(array_filter(['status'=>$s,'search'=>$search]));
        ?>
        <a href="<?= APP_URL ?>/pages/orders/index.php?<?= $q ?>"
           style="display:flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--radius-sm);font-size:.78rem;font-weight:600;text-decoration:none;<?= $active?'background:var(--primary);color:#fff':'color:var(--text-secondary)' ?>">
          <?= ucfirst(str_replace('_',' ',$s)) ?>
          <span style="background:<?= $active?'rgba(255,255,255,.25)':'var(--bg)' ?>;padding:1px 6px;border-radius:9999px;font-size:.7rem"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Search + date filter -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div style="position:relative;flex:1;min-width:220px;max-width:320px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="<?= e($search) ?>" placeholder="Order ID, customer name, phone..." class="form-control" style="padding-left:32px">
        </div>
        <input type="date" name="date_from" id="dateFromInput" value="<?= e($dateFrom) ?>" class="form-control" style="width:auto" title="From date">
        <input type="date" name="date_to"   id="dateToInput"   value="<?= e($dateTo)   ?>" class="form-control" style="width:auto" title="To date">
        <select name="courier" class="form-control" style="width:auto">
          <option value="">All Couriers</option>
          <?php foreach ($couriers as $c): ?>
          <option value="<?= e($c) ?>" <?= $courier === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search||$dateFrom||$dateTo||$courier): ?>
        <a href="<?= APP_URL ?>/pages/orders/index.php<?= $status?'?status='.urlencode($status):'' ?>" class="btn btn-outline btn-sm" onclick="localStorage.removeItem('ordersDateFrom');localStorage.removeItem('ordersDateTo')">Clear</a>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <?php if (empty($orders)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-muted)">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin:0 auto 12px;opacity:.3"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
        <div style="font-weight:600;margin-bottom:4px">No orders found</div>
        <div style="font-size:.82rem">Try adjusting your search or filters</div>
      </div>
      <?php else: ?>
      <div class="data-table-wrap" style="margin-bottom:20px;overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Product</th>
              <?php if ($isAdmin): ?><th>Total</th><?php endif; ?>
              <th>Status</th>
              <th>Page</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o):
              $badge = $badgeMap[$o['status']] ?? 'badge-pending';

              // Build this row's dropdown options: role-allowed statuses + current status (so it's always selectable/visible)
              $rowOptions = $allowedStatusOptions;
              if (!isset($rowOptions[$o['status']])) {
                  $rowOptions = [$o['status'] => $allStatusLabels[$o['status']] ?? ucfirst($o['status'])] + $rowOptions;
              }

              $isBlacklisted = $o['customer_phone'] && isset($blacklistSet[$o['customer_phone']]);
              $rowDate       = date('Y-m-d', strtotime($o['created_at']));
              $isDuplicate   = $o['customer_phone'] && isset($duplicateKeys[$o['customer_phone'] . '|' . $rowDate]);
              $rowStyle      = $isBlacklisted ? 'background:#fef2f2' : ($isDuplicate ? 'background:#fefce8' : '');
            ?>
            <tr style="<?= $rowStyle ?>">
              <td>
                <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($o['order_id']) ?>"
                   style="font-weight:700;color:var(--primary);font-size:.85rem"><?= e($o['order_id']) ?></a>
              </td>
              <td>
                <div style="font-weight:600;font-size:.85rem"><?= e($o['customer_name']) ?></div>
                <?php if ($o['customer_phone']): ?>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($o['customer_phone']) ?></div>
                <?php endif; ?>
                <?php if ($isBlacklisted): ?>
                <div style="font-size:.68rem;font-weight:700;color:#b91c1c;margin-top:2px">⚠ Blacklisted</div>
                <?php elseif ($isDuplicate): ?>
                <div style="font-size:.68rem;font-weight:700;color:#92400e;margin-top:2px">⚠ Duplicate today</div>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($o['product_names'] ?? '') ?>"><?= e($o['product_names'] ?? '—') ?></td>
              <?php if ($isAdmin): ?>
              <td style="font-weight:600"><?= $currency ?> <?= number_format($o['total'],0) ?></td>
              <?php endif; ?>
              <td>
                <select class="form-control status-select"
                        data-order-id="<?= e($o['order_id']) ?>"
                        data-current="<?= e($o['status']) ?>"
                        style="width:auto;min-width:130px;padding:5px 8px;font-size:.78rem;font-weight:600;border-radius:9999px;<?= 'background:var(--bg)' ?>"
                        onchange="onStatusChange(this)">
                  <?php foreach ($rowOptions as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $val === $o['status'] ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="text-muted"><?= e($o['page_name'] ?? '—') ?></td>
              <td>
                <div style="font-size:.8rem"><?= date('d M Y', strtotime($o['created_at'])) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= date('h:i A', strtotime($o['created_at'])) ?></div>
              </td>
              <td>
                <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($o['order_id']) ?>" class="btn btn-outline btn-xs">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>

    </main>
  </div>
</div>

<?php if ($isAdmin || $isSuper): ?>
<!-- Bulk Deliver by ID modal -->
<div id="bulkDeliverModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:440px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Bulk Deliver by Order ID</div>
    <p style="font-size:.86rem;color:var(--text-secondary);margin-bottom:12px">
      Paste order IDs (one per line, or comma-separated). Only orders currently <strong>In Courier</strong> will be marked <strong>Delivered</strong>.
    </p>
    <textarea id="bulkDeliverIds" class="form-control" rows="6" placeholder="ORD-20260701-0001&#10;ORD-20260701-0002"></textarea>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('bulkDeliverModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="submitBulkDeliver()">Mark Delivered</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';

// ── Date filter persistence (localStorage) ──────────────────
(function persistDateFilter() {
  const params = new URLSearchParams(window.location.search);
  if (params.has('date_from') || params.has('date_to')) {
    localStorage.setItem('ordersDateFrom', params.get('date_from') || '');
    localStorage.setItem('ordersDateTo', params.get('date_to') || '');
    return;
  }
  const savedFrom = localStorage.getItem('ordersDateFrom');
  const savedTo   = localStorage.getItem('ordersDateTo');
  if (savedFrom || savedTo) {
    if (savedFrom) params.set('date_from', savedFrom);
    if (savedTo)   params.set('date_to', savedTo);
    window.location.replace(window.location.pathname + '?' + params.toString());
  }
})();

// In-row status dropdown change
async function onStatusChange(selectEl) {
  const orderId   = selectEl.dataset.orderId;
  const newStatus = selectEl.value;
  const prevStatus= selectEl.dataset.current;

  if (newStatus === prevStatus) return;

  let note = '';
  if (newStatus === 'cancelled' || newStatus === 'pending') {
    note = (prompt(`Reason for marking this order "${newStatus}"?`) || '').trim();
    if (!note) { selectEl.value = prevStatus; return; }
  }

  selectEl.disabled = true;
  const res = await fetch(`${APP_URL}/api/orders.php?action=status`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ order_id: orderId, status: newStatus, note })
  });
  const data = await res.json();
  selectEl.disabled = false;

  if (data.success) {
    selectEl.dataset.current = newStatus;
    showToast('Status updated', 'success');
    setTimeout(() => location.reload(), 600);
  } else {
    selectEl.value = prevStatus; // revert UI on failure
    showToast(data.message || 'Failed to update status', 'error');
  }
}

<?php if ($isAdmin || $isSuper): ?>
async function dispatchAllConfirmed() {
  const btn = document.getElementById('dispatchAllBtn');
  btn.disabled = true;
  const res  = await fetch(`${APP_URL}/api/orders.php?action=dispatch_all_confirmed`, { method: 'POST' });
  const data = await res.json();
  btn.disabled = false;
  if (data.success) {
    showToast(data.count > 0 ? `${data.count} order(s) dispatched` : 'No confirmed orders found', 'success');
    setTimeout(() => location.reload(), 700);
  } else {
    showToast(data.message || 'Failed', 'error');
  }
}

async function moveAllToCourier() {
  const btn = document.getElementById('moveAllCourierBtn');
  btn.disabled = true;
  const res  = await fetch(`${APP_URL}/api/orders.php?action=move_all_to_courier`, { method: 'POST' });
  const data = await res.json();
  btn.disabled = false;
  if (data.success) {
    showToast(data.count > 0 ? `${data.count} order(s) moved to In Courier` : 'No dispatched orders found', 'success');
    setTimeout(() => location.reload(), 700);
  } else {
    showToast(data.message || 'Failed', 'error');
  }
}

async function submitBulkDeliver() {
  const raw = document.getElementById('bulkDeliverIds').value;
  const orderIds = raw.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
  if (!orderIds.length) { showToast('Paste at least one order ID', 'error'); return; }

  document.getElementById('bulkDeliverModal').style.display = 'none';
  const res  = await fetch(`${APP_URL}/api/orders.php?action=bulk_deliver`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ order_ids: orderIds })
  });
  const data = await res.json();
  if (data.success) {
    let msg = `${data.delivered} order(s) marked delivered`;
    if (data.skipped?.length) msg += `, ${data.skipped.length} skipped`;
    showToast(msg, 'success');
    setTimeout(() => location.reload(), 900);
  } else {
    showToast(data.message || 'Failed', 'error');
  }
}
<?php endif; ?>
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>