<?php
// pages/reports/index.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'reports';
$pageTitle  = 'Reports & Analytics';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$currency   = 'Rs';

// Reports page is visible to all roles but revenue data is admin-only.
// The full page (charts, product performance, order counts) is accessible to staff/supervisor too.
// If you want to fully block non-admins, uncomment the two lines below:
// if (!$isAdmin) redirect('/pages/dashboard.php');

// Date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');        // start of this month
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');         // today
$period   = $_GET['period']    ?? 'month';

// Quick period shortcuts
if (isset($_GET['period']) && !isset($_GET['date_from'])) {
    switch ($period) {
        case 'today': $dateFrom = $dateTo = date('Y-m-d'); break;
        case 'week':  $dateFrom = date('Y-m-d', strtotime('-7 days')); break;
        case 'month': $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d'); break;
        case 'year':  $dateFrom = date('Y-01-01'); $dateTo = date('Y-m-d'); break;
    }
}

$dFrom = $dateFrom . ' 00:00:00';
$dTo   = $dateTo   . ' 23:59:59';

// ── Core stats ───────────────────────────────────────────────
$totalOrders = (int)$pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ?")->execute([$dFrom,$dTo]) ?
    $pdo->query("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN '$dFrom' AND '$dTo'")->fetchColumn() : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ?");
$stmt->execute([$dFrom,$dTo]); $totalOrders = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN ('cancelled','returned')");
$stmt->execute([$dFrom,$dTo]); $completedOrders = (int)$stmt->fetchColumn();

$fulfillRate = $totalOrders > 0 ? round(($completedOrders/$totalOrders)*100,1) : 0;

// Revenue (admin only)
$totalRevenue = $todayRevenue = $avgOrder = 0;
if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN ('cancelled','returned')");
    $stmt->execute([$dFrom,$dTo]); $totalRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelled','returned')");
    $stmt->execute(); $todayRevenue = (float)$stmt->fetchColumn();

    $avgOrder = $completedOrders > 0 ? $totalRevenue / $completedOrders : 0;
}

// Total products & units
$stmt = $pdo->query("SELECT COUNT(*), SUM(quantity) FROM products WHERE status='active'");
[$totalProducts, $totalUnits] = $stmt->fetch(PDO::FETCH_NUM);

// ── Sales by day (for chart) ──────────────────────────────────
$salesByDay = $pdo->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS orders,
           COALESCE(SUM(total),0) AS revenue
    FROM orders
    WHERE created_at BETWEEN ? AND ? AND status NOT IN ('cancelled','returned')
    GROUP BY DATE(created_at) ORDER BY day ASC
");
$salesByDay->execute([$dFrom,$dTo]);
$salesByDay = $salesByDay->fetchAll();

// ── Top products ──────────────────────────────────────────────
$topProducts = $pdo->prepare("
    SELECT p.name, p.product_id, p.image_url, p.sell_price, p.buy_price,
           SUM(oi.qty) AS sold_qty,
           SUM(oi.total) AS revenue,
           SUM((oi.sell_price - oi.buy_price) * oi.qty) AS profit
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled','returned')
    GROUP BY oi.product_id
    ORDER BY sold_qty DESC LIMIT 10
");
$topProducts->execute([$dFrom,$dTo]);
$topProducts = $topProducts->fetchAll();

// ── Orders by status ─────────────────────────────────────────
$byStatus = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM orders WHERE created_at BETWEEN ? AND ?
    GROUP BY status
");
$byStatus->execute([$dFrom,$dTo]);
$byStatus = $byStatus->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Sales by category ─────────────────────────────────────────
$byCat = $pdo->prepare("
    SELECT c.name AS category, SUM(oi.qty) AS qty, SUM(oi.total) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    JOIN products p ON p.id=oi.product_id
    LEFT JOIN categories c ON c.id=p.category_id
    WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled','returned')
    GROUP BY p.category_id ORDER BY revenue DESC LIMIT 6
");
$byCat->execute([$dFrom,$dTo]);
$byCat = $byCat->fetchAll();

// Chart data JSON
$chartLabels  = array_column($salesByDay, 'day');
$chartOrders  = array_column($salesByDay, 'orders');
$chartRevenue = array_column($salesByDay, 'revenue');

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
          <h1 style="font-size:1.25rem;font-weight:700">Reports & Analytics</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            Comprehensive analytics for the Store Commerce platform
          </p>
        </div>
        <div style="display:flex;gap:8px">
          <?php if ($isAdmin): ?>
          <button class="btn btn-outline btn-sm" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Export
          </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Period filter -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:20px">
        <?php foreach (['today'=>'Today','week'=>'Last 7 Days','month'=>'This Month','year'=>'This Year'] as $p=>$l): ?>
        <a href="?period=<?= $p ?>" class="btn btn-sm <?= $period===$p&&!isset($_GET['date_from'])?'btn-primary':'btn-outline' ?>"><?= $l ?></a>
        <?php endforeach; ?>
        <form method="GET" style="display:flex;gap:6px;align-items:center;margin-left:4px">
          <input type="date" name="date_from" value="<?= $dateFrom ?>" class="form-control" style="width:auto">
          <span style="color:var(--text-muted);font-size:.85rem">to</span>
          <input type="date" name="date_to"   value="<?= $dateTo   ?>" class="form-control" style="width:auto">
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
      </div>

      <!-- Stat cards -->
      <div class="grid-4" style="gap:12px;margin-bottom:20px">
        <?php if ($isAdmin): ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value amount"><?= $currency ?> <?= number_format($totalRevenue,0) ?></div>
            <div class="stat-sub">Today: <?= $currency ?> <?= number_format($todayRevenue,0) ?></div>
          </div>
          <div class="stat-icon green">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
        </div>
        <?php endif; ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?= number_format($totalOrders) ?></div>
            <div class="stat-sub"><?= $completedOrders ?> completed</div>
          </div>
          <div class="stat-icon blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
          </div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label">Fulfillment Rate</div>
            <div class="stat-value"><?= $fulfillRate ?>%</div>
            <div class="stat-sub">Orders fulfilled</div>
          </div>
          <div class="stat-icon <?= $fulfillRate>=80?'green':($fulfillRate>=60?'orange':'red') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Avg Order Value</div>
            <div class="stat-value amount"><?= $currency ?> <?= number_format($avgOrder,0) ?></div>
            <div class="stat-sub">Per completed order</div>
          </div>
          <div class="stat-icon purple">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          </div>
        </div>
        <?php else: ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Products</div>
            <div class="stat-value"><?= number_format($totalProducts) ?></div>
            <div class="stat-sub"><?= number_format($totalUnits) ?> units</div>
          </div>
          <div class="stat-icon purple">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="grid-2" style="gap:16px;margin-bottom:16px">

        <!-- Sales chart -->
        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Sales Performance</div>
              <div class="card-sub"><?= date('d M Y',strtotime($dateFrom)) ?> – <?= date('d M Y',strtotime($dateTo)) ?></div>
            </div>
          </div>
          <?php if (empty($salesByDay)): ?>
          <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:.85rem">No sales data for this period</div>
          <?php else: ?>
          <canvas id="salesChart" height="180"></canvas>
          <?php endif; ?>
        </div>

        <!-- Orders by status -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Orders by Status</div>
          </div>
          <?php
          $statusColors = ['new'=>'#3B5BDB','confirmed'=>'#22c55e','pending'=>'#f59e0b','cancelled'=>'#ef4444','dispatched'=>'#8b5cf6','delivered'=>'#10b981','returned'=>'#f97316','in_courier'=>'#06b6d4'];
          $statusTotal  = array_sum($byStatus) ?: 1;
          foreach ($statusColors as $s=>$color):
            $cnt = $byStatus[$s] ?? 0;
            $pct = round(($cnt/$statusTotal)*100);
          ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:100px;font-size:.78rem;color:var(--text-secondary);flex-shrink:0"><?= ucfirst(str_replace('_',' ',$s)) ?></div>
            <div style="flex:1;height:8px;background:var(--bg);border-radius:9999px;overflow:hidden">
              <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:9999px"></div>
            </div>
            <div style="font-size:.78rem;font-weight:600;width:28px;text-align:right"><?= $cnt ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);width:32px"><?= $pct ?>%</div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>

      <div class="grid-2" style="gap:16px;margin-bottom:16px">

        <!-- Sales by category -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Regional Sales Destination</div>
            <div class="card-sub">By category</div>
          </div>
          <?php if (empty($byCat)): ?>
          <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:.85rem">No data</div>
          <?php else:
            $maxCatRev = max(array_column($byCat,'revenue')) ?: 1;
            foreach ($byCat as $cat):
              $pct = round(($cat['revenue']/$maxCatRev)*100);
          ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:110px;font-size:.78rem;font-weight:600;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($cat['category']??'Uncategorized') ?></div>
            <div style="flex:1;height:8px;background:var(--bg);border-radius:9999px;overflow:hidden">
              <div style="width:<?= $pct ?>%;height:100%;background:var(--primary);border-radius:9999px"></div>
            </div>
            <div style="font-size:.76rem;font-weight:600;white-space:nowrap"><?= $cat['qty'] ?> sold</div>
            <?php if ($isAdmin): ?>
            <div style="font-size:.74rem;color:var(--text-muted);white-space:nowrap;width:80px;text-align:right"><?= $currency ?> <?= number_format($cat['revenue'],0) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Product performance -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Product Performance</div>
            <div class="card-sub">Top selling items</div>
          </div>
        </div>

      </div>

      <!-- Product Performance Table -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title">Product Performance List</div>
            <div class="card-sub">Showing top <?= count($topProducts) ?> products</div>
          </div>
        </div>
        <?php if (empty($topProducts)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:.85rem">No sales data for this period</div>
        <?php else: ?>
        <div class="data-table-wrap" style="border:none">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Product</th>
                <th>Sold Qty</th>
                <?php if ($isAdmin): ?>
                <th>Revenue</th>
                <th>Profit</th>
                <th>Margin</th>
                <?php endif; ?>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topProducts as $i => $p):
                $margin = $p['revenue'] > 0 ? round(($p['profit']/$p['revenue'])*100,1) : 0;
                $marginColor = $margin >= 30 ? '#22c55e' : ($margin >= 15 ? '#f59e0b' : '#ef4444');
              ?>
              <tr>
                <td style="color:var(--text-muted);font-weight:700"><?= $i+1 ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:34px;height:34px;background:var(--bg);border-radius:var(--radius-sm);overflow:hidden;flex-shrink:0">
                      <?php if ($p['image_url']): ?>
                        <img src="<?= e(productImageUrl($p['image_url'])) ?>" style="width:100%;height:100%;object-fit:cover">
                      <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div style="font-weight:600;font-size:.85rem"><?= e($p['name']) ?></div>
                      <div style="font-size:.74rem;color:var(--text-muted)"><?= e($p['product_id']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:700;color:var(--primary)"><?= number_format($p['sold_qty']) ?></td>
                <?php if ($isAdmin): ?>
                <td style="font-weight:600"><?= $currency ?> <?= number_format($p['revenue'],0) ?></td>
                <td style="font-weight:600;color:#22c55e"><?= $currency ?> <?= number_format($p['profit'],0) ?></td>
                <td>
                  <span style="font-weight:700;color:<?= $marginColor ?>"><?= $margin ?>%</span>
                </td>
                <?php endif; ?>
                <td>
                  <?php $bar = min(100, ($p['sold_qty'] / (max(array_column($topProducts,'sold_qty'))?:1)) * 100); ?>
                  <div style="width:80px;height:6px;background:var(--bg);border-radius:9999px;overflow:hidden">
                    <div style="width:<?= $bar ?>%;height:100%;background:var(--primary);border-radius:9999px"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<?php if (!empty($salesByDay)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const ctx    = document.getElementById('salesChart').getContext('2d');
const labels = <?= json_encode($chartLabels) ?>;
const orders = <?= json_encode($chartOrders) ?>;
<?php if ($isAdmin): ?>
const revenue = <?= json_encode($chartRevenue) ?>;
<?php endif; ?>

new Chart(ctx, {
  type: 'bar',
  data: {
    labels: labels.map(d => new Date(d).toLocaleDateString('en-NP',{month:'short',day:'numeric'})),
    datasets: [
      <?php if ($isAdmin): ?>
      {
        label: 'Revenue (Rs)',
        data: revenue,
        backgroundColor: 'rgba(59,91,219,.15)',
        borderColor: '#3B5BDB',
        borderWidth: 2,
        borderRadius: 4,
        yAxisID: 'y1',
        type: 'line',
        fill: true,
        tension: 0.4,
        pointRadius: 3,
      },
      <?php endif; ?>
      {
        label: 'Orders',
        data: orders,
        backgroundColor: 'rgba(59,91,219,.7)',
        borderRadius: 4,
        yAxisID: 'y',
      }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'top', labels: { font:{size:11} } } },
    scales: {
      y:  { beginAtZero:true, ticks:{stepSize:1}, grid:{color:'rgba(0,0,0,.04)'} },
      <?php if ($isAdmin): ?>
      y1: { beginAtZero:true, position:'right', grid:{display:false}, ticks:{callback:v=>'Rs '+v.toLocaleString()} },
      <?php endif; ?>
      x:  { grid:{display:false} }
    }
  }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../components/foot.php'; ?>