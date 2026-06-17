<?php
// pages/products/index.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'product';
$pageTitle  = 'Products';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$currency   = 'Rs';

$search      = trim($_GET['search']   ?? '');
$category_id = (int)($_GET['category'] ?? 0);
$stockFilter = $_GET['stock']          ?? '';
$statusFilter= $_GET['status']         ?? 'active';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 20;

$categories = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll();

// Build WHERE
$where  = ['1=1'];
$params = [];

if ($search) {
    $like    = "%$search%";
    $where[] = "(p.name LIKE ? OR p.product_id LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
if ($category_id) { $where[] = "p.category_id=?"; $params[] = $category_id; }
if ($stockFilter) { $where[] = "p.stock_status=?"; $params[] = $stockFilter; }
if ($statusFilter) { $where[] = "p.status=?"; $params[] = $statusFilter; }
else { $where[] = "p.status='active'"; }

$whereSQL   = 'WHERE ' . implode(' AND ', $where);
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    $whereSQL
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Quick stats
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN stock_status='lowstock'   THEN 1 ELSE 0 END) AS low,
        SUM(CASE WHEN stock_status='critical'   THEN 1 ELSE 0 END) AS critical,
        SUM(CASE WHEN stock_status='outofstock' THEN 1 ELSE 0 END) AS out
    FROM products WHERE status='active'
")->fetch();

$baseUrl = APP_URL . '/pages/products/index.php?' . http_build_query(array_filter([
    'search'   => $search,
    'category' => $category_id ?: '',
    'stock'    => $stockFilter,
    'status'   => $statusFilter !== 'active' ? $statusFilter : '',
]));

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
          <h1 style="font-size:1.25rem;font-weight:700">Products</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($total) ?> product<?= $total != 1 ? 's' : '' ?> found
          </p>
        </div>
        <?php if ($isAdmin): ?>
        <div style="display:flex;gap:8px">
          <a href="<?= APP_URL ?>/pages/products/import.php" class="btn btn-outline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Import CSV
          </a>
          <a href="<?= APP_URL ?>/pages/products/add.php" class="btn btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Product
          </a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Stat cards -->
      <div class="grid-4" style="gap:12px;margin-bottom:20px">
        <a href="<?= APP_URL ?>/pages/products/index.php" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px">
            <div>
              <div class="stat-label">Total Active</div>
              <div class="stat-value" style="font-size:1.3rem"><?= $stats['total'] ?></div>
            </div>
            <div class="stat-icon blue">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            </div>
          </div>
        </a>
        <a href="<?= APP_URL ?>/pages/products/index.php?stock=lowstock" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px;<?= $stockFilter==='lowstock'?'border-color:var(--primary)':'' ?>">
            <div>
              <div class="stat-label">Low Stock</div>
              <div class="stat-value" style="font-size:1.3rem;color:<?= $stats['low']>0?'#f97316':'var(--text)' ?>"><?= $stats['low'] ?></div>
            </div>
            <div class="stat-icon orange">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
          </div>
        </a>
        <a href="<?= APP_URL ?>/pages/products/index.php?stock=critical" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px;<?= $stockFilter==='critical'?'border-color:var(--primary)':'' ?>">
            <div>
              <div class="stat-label">Critical</div>
              <div class="stat-value" style="font-size:1.3rem;color:<?= $stats['critical']>0?'#ef4444':'var(--text)' ?>"><?= $stats['critical'] ?></div>
            </div>
            <div class="stat-icon red">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
          </div>
        </a>
        <a href="<?= APP_URL ?>/pages/products/index.php?stock=outofstock" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px;<?= $stockFilter==='outofstock'?'border-color:var(--primary)':'' ?>">
            <div>
              <div class="stat-label">Out of Stock</div>
              <div class="stat-value" style="font-size:1.3rem;color:<?= $stats['out']>0?'#ef4444':'var(--text)' ?>"><?= $stats['out'] ?></div>
            </div>
            <div class="stat-icon red">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
          </div>
        </a>
      </div>

      <!-- Filters -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
        <div style="position:relative;flex:1;min-width:200px;max-width:320px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="<?= e($search) ?>" placeholder="Name, ID, SKU, brand..." class="form-control" style="padding-left:32px">
        </div>
        <select name="category" class="form-control" style="width:auto">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $category_id==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="stock" class="form-control" style="width:auto">
          <option value="">All Stock</option>
          <?php foreach (['instock'=>'In Stock','lowstock'=>'Low Stock','critical'=>'Critical','outofstock'=>'Out of Stock'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $stockFilter===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isAdmin): ?>
        <select name="status" class="form-control" style="width:auto">
          <option value="active"       <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
          <option value="inactive"     <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="discontinued" <?= $statusFilter==='discontinued'?'selected':'' ?>>Discontinued</option>
          <option value=""             <?= $statusFilter===''?'selected':'' ?>>All Status</option>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($search || $category_id || $stockFilter || $statusFilter !== 'active'): ?>
        <a href="<?= APP_URL ?>/pages/products/index.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <?php if (empty($products)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-muted);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg)">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin:0 auto 12px;opacity:.3"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        <div style="font-weight:600;margin-bottom:4px">No products found</div>
        <div style="font-size:.82rem">Try adjusting your filters<?= $isAdmin ? ' or <a href="'.APP_URL.'/pages/products/add.php">add a product</a>' : '' ?></div>
      </div>
      <?php else: ?>
      <div class="data-table-wrap" style="margin-bottom:20px">
        <table class="data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Stock</th>
              <?php if ($isAdmin): ?>
              <th>Buy Price</th>
              <th>Sell Price</th>
              <?php else: ?>
              <th>Sell Price</th>
              <?php endif; ?>
              <th>Status</th>
              <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p):
              $stockBadgeMap = ['instock'=>'badge-instock','lowstock'=>'badge-lowstock','critical'=>'badge-critical','outofstock'=>'badge-critical'];
              $stockBadge    = $stockBadgeMap[$p['stock_status']] ?? 'badge-pending';
              $stockLabel    = $p['stock_status'] === 'outofstock' ? 'Out of Stock' : ucfirst($p['stock_status']);
              $qtyColor      = $p['quantity'] <= 0 ? '#ef4444' : ($p['quantity'] <= $p['min_stock_level'] ? '#f97316' : 'var(--text)');
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:40px;height:40px;background:var(--bg);border-radius:var(--radius-sm);flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center">
                    <?php if ($p['image_url']): ?>
                      <img src="<?= e($p['image_url']) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none'">
                    <?php else: ?>
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <?php endif; ?>
                  </div>
                  <div>
                    <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $p['id'] ?>" style="font-weight:600;font-size:.88rem;color:var(--text)"><?= e($p['name']) ?></a>
                    <div style="font-size:.74rem;color:var(--text-muted)"><?= e($p['product_id']) ?><?= $p['brand'] ? ' · '.e($p['brand']) : '' ?></div>
                  </div>
                </div>
              </td>
              <td class="text-muted"><?= e($p['category_name'] ?? '—') ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-weight:700;font-size:.95rem;color:<?= $qtyColor ?>"><?= $p['quantity'] ?></span>
                  <span class="badge <?= $stockBadge ?>"><?= $stockLabel ?></span>
                </div>
              </td>
              <?php if ($isAdmin): ?>
              <td class="text-muted"><?= $currency ?> <?= number_format($p['buy_price'], 0) ?></td>
              <?php endif; ?>
              <td style="font-weight:600"><?= $currency ?> <?= number_format($p['sell_price'], 0) ?></td>
              <td>
                <?php
                $statusColor = ['active'=>'badge-confirmed','inactive'=>'badge-pending','discontinued'=>'badge-cancelled'][$p['status']] ?? 'badge-pending';
                ?>
                <span class="badge <?= $statusColor ?>"><?= ucfirst($p['status']) ?></span>
              </td>
              <?php if ($isAdmin): ?>
              <td>
                <div style="display:flex;gap:5px">
                  <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-xs">View</a>
                  <a href="<?= APP_URL ?>/pages/products/add.php?edit=<?= $p['id'] ?>" class="btn btn-outline btn-xs">Edit</a>
                  <button onclick="deleteProduct(<?= $p['id'] ?>, '<?= e($p['name']) ?>')" class="btn btn-xs btn-danger">Del</button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>

    </main>
  </div>
</div>

<!-- Confirm delete modal -->
<?php if ($isAdmin): ?>
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Delete Product</div>
    <p style="font-size:.86rem;color:var(--text-secondary);margin-bottom:20px" id="confirmMsg"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('confirmModal').style.display='none'">Cancel</button>
      <button class="btn btn-danger btn-sm" id="confirmBtn">Delete</button>
    </div>
  </div>
</div>

<script>
function deleteProduct(id, name) {
  document.getElementById('confirmMsg').textContent = `Mark "${name}" as discontinued? It won't appear in orders.`;
  document.getElementById('confirmModal').style.display = 'flex';
  document.getElementById('confirmBtn').onclick = async () => {
    const r = await fetch('<?= APP_URL ?>/api/products.php?action=delete', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    document.getElementById('confirmModal').style.display = 'none';
    if (d.success) { showToast('Product removed', 'success'); setTimeout(() => location.reload(), 700); }
    else showToast(d.message || 'Failed', 'error');
  };
}
</script>
<?php endif; ?>

<div class="toast-container" id="toastContainer"></div>
<?php include __DIR__ . '/../../components/foot.php'; ?>
