<?php
// pages/inventory/log.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'inventory';
$user       = currentUser();

$productId = (int)($_GET['product_id'] ?? 0);
if (!$productId) redirect('/pages/inventory/index.php');

$stmt = $pdo->prepare("SELECT id, product_id, name, quantity, min_stock_level FROM products WHERE id=?");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) redirect('/pages/inventory/index.php');

$pageTitle = 'Inventory Log — ' . $product['name'];
$qtyColor = $product['quantity'] <= 0 ? '#ef4444' : ($product['quantity'] <= $product['min_stock_level'] ? '#f97316' : 'var(--text)');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE product_id=?");
$countStmt->execute([$productId]);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Every entry, newest first — qty_after is already the running stock level
// at that point (stored at the time of the adjustment), so no cumulative
// math is needed here.
$stmt = $pdo->prepare("
    SELECT created_at, type, reason, qty_change, qty_after
    FROM stock_adjustments
    WHERE product_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$productId]);
$rows = $stmt->fetchAll();

// Fallback label when no reason was recorded (mainly manual adjustments
// where staff left the note blank) — most rows already carry a specific
// reason (e.g. "Dispatched ORD-...").
$typeLabels = [
    'sale'       => 'Dispatched',
    'return'     => 'Returned',
    'damaged'    => 'Damaged',
    'add'        => 'Stock Added',
    'remove'     => 'Stock Removed',
    'adjustment' => 'Adjustment',
    'initial'    => 'Initial Stock',
];

$baseUrl = APP_URL . '/pages/inventory/log.php?product_id=' . $product['id'];

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
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= e($product['name']) ?> (<?= e($product['product_id']) ?>) · Currently <span style="font-weight:700;font-size:1rem;color:<?= $qtyColor ?>"><?= $product['quantity'] ?> units</span> available</p>
        </div>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Remarks</th>
              <th>Stock In</th>
              <th>Stock Out</th>
              <th>Net Change</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No stock movement recorded yet</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              $change = (int)$r['qty_change'];
              $label  = $r['reason'] ?: ($typeLabels[$r['type']] ?? ucfirst($r['type']));
            ?>
            <tr>
              <td style="font-size:.85rem"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
              <td style="font-size:.85rem;color:var(--text-secondary)"><?= e($label) ?></td>
              <td style="color:#16a34a;font-weight:600"><?= $change > 0 ? $change : '' ?></td>
              <td style="color:#dc2626;font-weight:600"><?= $change < 0 ? abs($change) : '' ?></td>
              <td style="font-weight:700"><?= (int)$r['qty_after'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/foot.php'; ?>
