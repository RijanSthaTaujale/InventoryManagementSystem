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

// ── Trending Products (top sold by order_items qty) ─────────
$trendingStmt = $pdo->query("
  SELECT p.id, p.name, p.sell_price, p.image_url, p.stock_status, p.quantity,
         COALESCE(SUM(oi.qty),0) AS total_sold
  FROM products p
  LEFT JOIN order_items oi ON oi.product_id = p.id
  WHERE p.status='active'
  GROUP BY p.id
  ORDER BY total_sold DESC, p.created_at DESC
  LIMIT 6
");
$trending = $trendingStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Recent Activity (order status log) ──────────────────────
$activityStmt = $pdo->query("
  SELECT osl.to_status, osl.created_at, o.order_id, o.customer_name,
         u.name AS changed_by_name
  FROM order_status_log osl
  JOIN orders o ON o.id = osl.order_id
  LEFT JOIN users u ON u.id = osl.changed_by
  ORDER BY osl.created_at DESC
  LIMIT 8
");
$recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

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

// ── Recent Orders ────────────────────────────────────────────
$recentOrdersStmt = $pdo->query("
  SELECT o.order_id, o.customer_name, o.total, o.status, o.created_at,
         COUNT(oi.id) AS item_count
  FROM orders o
  LEFT JOIN order_items oi ON oi.order_id = o.id
  GROUP BY o.id
  ORDER BY o.created_at DESC
  LIMIT 5
");
$recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

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
      <div class="grid-4" style="gap:14px;margin-bottom:20px">

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

      <!-- ── Trending Products + Recent Activity ── -->
      <div class="grid-2" style="gap:16px;margin-bottom:20px">

        <!-- Trending Products -->
        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Trending Products</div>
              <div class="card-sub">Top performing items</div>
            </div>
            <a href="<?= APP_URL ?>/pages/products/index.php" class="btn btn-outline btn-sm">View All</a>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?php foreach (array_slice($trending, 0, 4) as $p): ?>
            <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $p['id'] ?>"
               style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:var(--radius-md);text-decoration:none;color:var(--text);transition:border-color var(--transition)"
               onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
              <div style="width:52px;height:52px;background:var(--bg);border-radius:var(--radius-md);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center">
                <?php if ($p['image_url']): ?>
                  <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <?php endif; ?>
              </div>
              <div style="min-width:0;flex:1">
                <div style="font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['name']) ?></div>
                <div style="font-size:.78rem;color:var(--primary);font-weight:700;margin-top:2px"><?= $currency ?> <?= number_format($p['sell_price'], 0) ?></div>
                <div style="font-size:.7rem;color:var(--text-muted);margin-top:1px">Sold: <?= number_format($p['total_sold']) ?></div>
              </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($trending)): ?>
              <div style="grid-column:span 2;text-align:center;padding:24px;color:var(--text-muted);font-size:.85rem">No products yet</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Recent Activity</div>
              <div class="card-sub">Latest order updates</div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:0">
            <?php if (empty($recentActivity)): ?>
              <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:.85rem">No activity yet</div>
            <?php endif; ?>
            <?php
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
            foreach ($recentActivity as $act):
              $color = $statusColors[$act['to_status']] ?? '#64748b';
              $timeAgo = '';
              $diff = time() - strtotime($act['created_at']);
              if ($diff < 60) $timeAgo = 'just now';
              elseif ($diff < 3600) $timeAgo = floor($diff/60).'m ago';
              elseif ($diff < 86400) $timeAgo = floor($diff/3600).'h ago';
              else $timeAgo = date('d M', strtotime($act['created_at']));
            ?>
            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
              <div style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;margin-top:5px;flex-shrink:0"></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:.82rem">
                  <span style="font-weight:600"><?= htmlspecialchars($act['order_id']) ?></span>
                  marked as <span style="font-weight:600;color:<?= $color ?>"><?= ucfirst(str_replace('_',' ',$act['to_status'])) ?></span>
                </div>
                <div style="font-size:.75rem;color:var(--text-muted);margin-top:1px">
                  <?= htmlspecialchars($act['customer_name']) ?> · <?= $timeAgo ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
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
                  <a href="<?= APP_URL ?>/pages/inventory/adjust.php?product_id=<?= $item['product_id'] ?>" class="btn btn-outline btn-xs">Restock</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Recent Orders ── -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">Recent Orders</div>
            <div class="card-sub">Latest 5 orders</div>
          </div>
          <a href="<?= APP_URL ?>/pages/orders/index.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="data-table-wrap" style="border:none">
          <table class="data-table">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Items</th>
                <?php if ($isAdmin): ?><th>Total</th><?php endif; ?>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentOrders)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:28px">No orders yet</td></tr>
              <?php endif; ?>
              <?php foreach ($recentOrders as $ord): ?>
              <?php
              $badgeMap = [
                'new'       => 'badge-new',
                'confirmed' => 'badge-confirmed',
                'pending'   => 'badge-pending',
                'cancelled' => 'badge-cancelled',
                'dispatched'=> 'badge-dispatched',
                'delivered' => 'badge-delivered',
                'returned'  => 'badge-returned',
                'in_courier'=> 'badge-in_courier',
              ];
              $badge = $badgeMap[$ord['status']] ?? 'badge-pending';
              ?>
              <tr>
                <td>
                  <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($ord['order_id']) ?>"
                     style="font-weight:600;font-size:.85rem;color:var(--primary)"><?= htmlspecialchars($ord['order_id']) ?></a>
                </td>
                <td><?= htmlspecialchars($ord['customer_name']) ?></td>
                <td class="text-muted"><?= $ord['item_count'] ?> item<?= $ord['item_count'] != 1 ? 's' : '' ?></td>
                <?php if ($isAdmin): ?>
                <td style="font-weight:600"><?= $currency ?> <?= number_format($ord['total'], 0) ?></td>
                <?php endif; ?>
                <td><span class="badge <?= $badge ?>"><?= ucfirst(str_replace('_',' ',$ord['status'])) ?></span></td>
                <td class="text-muted" style="font-size:.78rem"><?= date('d M Y', strtotime($ord['created_at'])) ?></td>
                <td>
                  <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($ord['order_id']) ?>" class="btn btn-outline btn-xs">View</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<?php include __DIR__ . '/../components/foot.php'; ?>