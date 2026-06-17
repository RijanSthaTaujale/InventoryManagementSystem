<?php
// pages/orders/index.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'order';
$pageTitle  = 'Orders';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$isSuper    = $user['role'] === 'supervisor';
$currency   = 'Rs';

$search     = trim($_GET['search'] ?? '');
$status     = $_GET['status']      ?? '';
$dateFrom   = $_GET['date_from']   ?? '';
$dateTo     = $_GET['date_to']     ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;

$validStatuses = ['new','confirmed','pending','cancelled','dispatched','delivered','returned','in_courier'];

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

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT o.*, COUNT(oi.id) AS item_count,
           u.name AS assigned_name
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN users u ON u.id = o.assigned_to
    $whereSQL
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

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
    'date_from' => $dateFrom, 'date_to' => $dateTo
]));

$badgeMap = [
    'new'=>'badge-new','confirmed'=>'badge-confirmed','pending'=>'badge-pending',
    'cancelled'=>'badge-cancelled','dispatched'=>'badge-dispatched','delivered'=>'badge-delivered',
    'returned'=>'badge-returned','in_courier'=>'badge-in_courier'
];

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
        <a href="<?= APP_URL ?>/pages/orders/create.php" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          New Order
        </a>
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
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" style="width:auto" title="From date">
        <input type="date" name="date_to"   value="<?= e($dateTo)   ?>" class="form-control" style="width:auto" title="To date">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search||$dateFrom||$dateTo): ?>
        <a href="<?= APP_URL ?>/pages/orders/index.php<?= $status?'?status='.urlencode($status):'' ?>" class="btn btn-outline btn-sm">Clear</a>
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
      <div class="data-table-wrap" style="margin-bottom:20px">
        <table class="data-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Items</th>
              <?php if ($isAdmin): ?><th>Total</th><?php endif; ?>
              <th>Status</th>
              <th>Payment</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o):
              $badge = $badgeMap[$o['status']] ?? 'badge-pending';
              $payBadge = $o['payment_status']==='paid' ? 'badge-confirmed' : ($o['payment_status']==='partial'?'badge-pending':'badge-cancelled');
            ?>
            <tr>
              <td>
                <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($o['order_id']) ?>"
                   style="font-weight:700;color:var(--primary);font-size:.85rem"><?= e($o['order_id']) ?></a>
              </td>
              <td>
                <div style="font-weight:600;font-size:.85rem"><?= e($o['customer_name']) ?></div>
                <?php if ($o['customer_phone']): ?>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($o['customer_phone']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $o['item_count'] ?> item<?= $o['item_count']!=1?'s':'' ?></td>
              <?php if ($isAdmin): ?>
              <td style="font-weight:600"><?= $currency ?> <?= number_format($o['total'],0) ?></td>
              <?php endif; ?>
              <td><span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
              <td><span class="badge <?= $payBadge ?>"><?= ucfirst($o['payment_status']) ?></span></td>
              <td>
                <div style="font-size:.8rem"><?= date('d M Y', strtotime($o['created_at'])) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= date('h:i A', strtotime($o['created_at'])) ?></div>
              </td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($o['order_id']) ?>" class="btn btn-outline btn-xs">View</a>
                  <?php if (in_array($o['status'],['new','confirmed','pending']) && ($isAdmin || $user['role']==='staff' || $isSuper)): ?>
                  <button onclick="quickStatus('<?= $o['order_id'] ?>','confirmed')" class="btn btn-xs" style="background:#dcfce7;color:#15803d;border:none;cursor:pointer" title="Confirm">✓</button>
                  <?php endif; ?>
                  <?php if ($isAdmin && $o['status']==='confirmed'): ?>
                  <button onclick="quickStatus('<?= $o['order_id'] ?>','dispatched')" class="btn btn-xs btn-purple" title="Dispatch">→</button>
                  <?php endif; ?>
                </div>
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
<div class="toast-container" id="toastContainer"></div>

<script>
async function quickStatus(orderId, newStatus) {
  const res  = await fetch('<?= APP_URL ?>/api/orders.php?action=status', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({order_id: orderId, status: newStatus})
  });
  const data = await res.json();
  if (data.success) { showToast('Order ' + newStatus,'success'); setTimeout(()=>location.reload(),700); }
  else showToast(data.message||'Failed','error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>