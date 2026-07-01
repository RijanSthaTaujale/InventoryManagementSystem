<?php
// pages/inventory/log.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'inventory';
$user       = currentUser();

$productId = (int)($_GET['product_id'] ?? 0);
if (!$productId) redirect('/pages/inventory/index.php');

$stmt = $pdo->prepare("SELECT id, product_id, name, quantity FROM products WHERE id=?");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) redirect('/pages/inventory/index.php');

$pageTitle = 'Inventory Log — ' . $product['name'];

$period = $_GET['period'] ?? 'daily';
if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'])) $period = 'daily';

$groupExpr = match ($period) {
    'weekly'  => 'YEARWEEK(created_at, 3)',
    'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
    'yearly'  => 'YEAR(created_at)',
    default   => 'DATE(created_at)',
};

$stmt = $pdo->prepare("
    SELECT $groupExpr AS period_key, MIN(DATE(created_at)) AS period_date,
           SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) AS total_in,
           SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END) AS total_out,
           SUM(qty_change) AS net_change,
           COUNT(*) AS adjustments
    FROM stock_adjustments
    WHERE product_id = ?
    GROUP BY period_key
    ORDER BY period_date DESC
    LIMIT 60
");
$stmt->execute([$productId]);
$rows = $stmt->fetchAll();

function formatPeriodLabel(string $period, string $date): string {
    return match ($period) {
        'weekly'  => 'Week of ' . date('d M Y', strtotime($date)),
        'monthly' => date('M Y', strtotime($date)),
        'yearly'  => date('Y', strtotime($date)),
        default   => date('d M Y', strtotime($date)),
    };
}

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
            <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $product['id'] ?>" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> <?= e($product['name']) ?>
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem">Inventory Log</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Inventory Log</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= e($product['name']) ?> (<?= e($product['product_id']) ?>) · Currently <?= $product['quantity'] ?> units</p>
        </div>
        <div style="display:flex;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:4px">
          <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $val => $label): ?>
          <a href="<?= APP_URL ?>/pages/inventory/log.php?product_id=<?= $product['id'] ?>&period=<?= $val ?>"
             style="padding:6px 14px;border-radius:var(--radius-sm);font-size:.8rem;font-weight:600;text-decoration:none;<?= $period===$val?'background:var(--primary);color:#fff':'color:var(--text-secondary)' ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th><?= ucfirst($period) ?> Period</th>
              <th>Stock In</th>
              <th>Stock Out</th>
              <th>Net Change</th>
              <th>Adjustments</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No stock movement recorded yet</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $net = (int)$r['net_change']; ?>
            <tr>
              <td style="font-weight:600;font-size:.85rem"><?= e(formatPeriodLabel($period, $r['period_date'])) ?></td>
              <td style="color:#16a34a;font-weight:600">+<?= $r['total_in'] ?></td>
              <td style="color:#dc2626;font-weight:600">-<?= $r['total_out'] ?></td>
              <td style="font-weight:700;color:<?= $net >= 0 ? '#16a34a' : '#dc2626' ?>"><?= $net >= 0 ? '+' : '' ?><?= $net ?></td>
              <td class="text-muted"><?= $r['adjustments'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/foot.php'; ?>
