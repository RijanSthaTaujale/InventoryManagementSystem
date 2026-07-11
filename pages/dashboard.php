<?php
// pages/dashboard.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';
$user = currentUser();
$role = $user['role'];
$isAdmin = $role === 'admin';
$isSuper = $role === 'supervisor';

// ── Stat Cards ──────────────────────────────────────────────
// Total orders
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

// New orders (today)
$newOrdersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Pending orders
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('new','pending')")->fetchColumn();

// Total products
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();

// Low / critical stock
$lowStock = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_status IN ('lowstock','critical') AND status='active'")->fetchColumn();

// Revenue (admin only)
$totalRevenue = 0;
$todayRevenue = 0;
if ($isAdmin) {
  $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled','returned')")->fetchColumn();
  $todayRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelled','returned')")->fetchColumn();
}

// ── Product Performance (units sold per product, date-range filterable) ──
$perfFrom = $_GET['perf_from'] ?? date('Y-m-d', strtotime('-6 days'));
$perfTo   = $_GET['perf_to']   ?? date('Y-m-d');

$perfStmt = $pdo->prepare("
  SELECT p.id, p.name, p.product_id, COALESCE(SUM(oi.qty),0) AS units_sold
  FROM products p
  LEFT JOIN order_items oi ON oi.product_id = p.id
  LEFT JOIN orders o ON o.id = oi.order_id AND DATE(o.created_at) BETWEEN ? AND ? AND o.status <> 'cancelled'
  WHERE p.status='active'
  GROUP BY p.id
  ORDER BY units_sold DESC, p.name ASC
  LIMIT 10
");
$perfStmt->execute([$perfFrom, $perfTo]);
$productPerformance = $perfStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Low Stock Table ──────────────────────────────────────────
$lowStockStmt = $pdo->query("
  SELECT p.product_id, p.name, p.quantity, p.min_stock_level, p.sell_price, p.stock_status, c.name AS category
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.stock_status IN ('lowstock','critical','outofstock') AND p.status='active'
  ORDER BY p.quantity ASC
  LIMIT 5
");
$lowStockItems = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

$currency = 'Rs';

include __DIR__ . '/../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>

  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../components/topbar.php'; ?>

    <main class="main-content">

      <!-- Page header -->
      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700;color:var(--text)">Dashboard</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            Welcome back, <?= htmlspecialchars($user['name']) ?> &nbsp;·&nbsp;
            <?= date('l, d M Y') ?>
          </p>
        </div>
        <a href="<?= APP_URL ?>/pages/orders/create.php" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          New Order
        </a>
      </div>

      <!-- ── Stat Cards ── -->
      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px">

        <!-- Total Revenue (admin only) -->
        <?php if ($isAdmin): ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value amount"><?= $currency ?> <?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-sub">Today: <?= $currency ?> <?= number_format($todayRevenue, 2) ?></div>
          </div>
          <div class="stat-icon green">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
        </div>
        <?php endif; ?>

        <!-- Total Orders -->
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?= number_format($totalOrders) ?></div>
            <div class="stat-sub"><?= $newOrdersToday ?> new today</div>
          </div>
          <div class="stat-icon blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          </div>
        </div>

        <!-- Today's Orders -->
        <div class="stat-card">
          <div>
            <div class="stat-label">Today's Orders</div>
            <div class="stat-value"><?= number_format($newOrdersToday) ?></div>
            <div class="stat-sub"><?= date('d M Y') ?></div>
          </div>
          <div class="stat-icon blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </div>
        </div>

        <!-- Pending Orders -->
        <div class="stat-card">
          <div>
            <div class="stat-label">Pending Orders</div>
            <div class="stat-value"><?= number_format($pendingOrders) ?></div>
            <div class="stat-sub">Needs attention</div>
          </div>
          <div class="stat-icon orange">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
        </div>

        <!-- Total Products -->
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Products</div>
            <div class="stat-value"><?= number_format($totalProducts) ?></div>
            <div class="stat-sub"><?= $lowStock ?> low stock</div>
          </div>
          <div class="stat-icon purple">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          </div>
        </div>

        <?php if (!$isAdmin): ?>
        <!-- Placeholder 4th card for non-admin -->
        <div class="stat-card">
          <div>
            <div class="stat-label">Low Stock Items</div>
            <div class="stat-value"><?= number_format($lowStock) ?></div>
            <div class="stat-sub">Needs restocking</div>
          </div>
          <div class="stat-icon red">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Product Performance ── -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-header">
          <div>
            <div class="card-title">Product Performance</div>
            <div class="card-sub">Units sold per product in the selected range</div>
          </div>
          <form method="GET" style="display:flex;gap:8px;align-items:center">
            <input type="date" name="perf_from" value="<?= e($perfFrom) ?>" class="form-control" style="width:auto" title="From date">
            <input type="date" name="perf_to" value="<?= e($perfTo) ?>" class="form-control" style="width:auto" title="To date">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
          </form>
        </div>
        <div class="data-table-wrap" style="border:none">
          <table class="data-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Units Sold</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($productPerformance)): ?>
                <tr><td colspan="2" style="text-align:center;color:var(--text-muted);padding:24px">No products yet</td></tr>
              <?php endif; ?>
              <?php foreach ($productPerformance as $pp): ?>
              <tr>
                <td>
                  <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $pp['id'] ?>" style="font-weight:600;font-size:.85rem;color:var(--text);text-decoration:none">
                    <?= e($pp['name']) ?>
                  </a>
                  <div style="font-size:.74rem;color:var(--text-muted)"><?= e($pp['product_id']) ?></div>
                </td>
                <td style="font-weight:700;color:var(--primary)"><?= number_format($pp['units_sold']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── Low Stock Alert ── -->
      <?php if (!empty($lowStockItems)): ?>
      <div class="card" style="margin-bottom:20px;border-left:3px solid var(--accent-red)">
        <div class="card-header">
          <div style="display:flex;align-items:center;gap:8px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="card-title" style="color:#ef4444">Low Stock Alert</div>
          </div>
          <a href="<?= APP_URL ?>/pages/inventory/index.php" class="btn btn-outline btn-sm">View Inventory</a>
        </div>
        <div class="data-table-wrap" style="border:none">
          <table class="data-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowStockItems as $item): ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($item['name']) ?></div>
                  <div style="font-size:.74rem;color:var(--text-muted)"><?= htmlspecialchars($item['product_id']) ?></div>
                </td>
                <td class="text-muted"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
                <td style="font-weight:600"><?= $currency ?> <?= number_format($item['sell_price'], 0) ?></td>
                <td>
                  <span style="font-weight:700;color:<?= $item['quantity'] == 0 ? '#ef4444' : ($item['quantity'] <= 3 ? '#f97316' : '#f59e0b') ?>">
                    <?= $item['quantity'] ?>
                  </span>
                  <span style="color:var(--text-muted);font-size:.75rem"> / <?= $item['min_stock_level'] ?> min</span>
                </td>
                <td>
                  <?php
                  $badgeMap = [
                    'outofstock' => 'badge-critical',
                    'critical'   => 'badge-critical',
                    'lowstock'   => 'badge-lowstock',
                  ];
                  $badgeClass = $badgeMap[$item['stock_status']] ?? 'badge-pending';
                  $label = $item['stock_status'] === 'outofstock' ? 'Out of Stock' : ucfirst($item['stock_status']);
                  ?>
                  <span class="badge <?= $badgeClass ?>"><?= $label ?></span>
                </td>
                <td>
                  <a href="<?= APP_URL ?>/pages/inventory/index.php?search=<?= urlencode($item['product_id']) ?>" class="btn btn-outline btn-xs">Restock</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<?php include __DIR__ . '/../components/foot.php'; ?>