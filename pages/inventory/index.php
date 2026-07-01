<?php
// pages/inventory/index.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'inventory';
$pageTitle  = 'Inventory';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$currency   = 'Rs';

$search      = trim($_GET['search']   ?? '');
$category_id = (int)($_GET['category'] ?? 0);
$stockFilter = $_GET['stock']          ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 20;

$categories = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll();

// Stats
$stats = $pdo->query("
    SELECT
        SUM(quantity)                                           AS total_units,
        COUNT(*)                                                AS total_products,
        SUM(CASE WHEN stock_status='lowstock'  THEN 1 ELSE 0 END) AS low_count,
        SUM(CASE WHEN stock_status='critical'  THEN 1 ELSE 0 END) AS critical_count,
        SUM(CASE WHEN stock_status='outofstock'THEN 1 ELSE 0 END) AS out_count
    FROM products WHERE status='active'
")->fetch();

// Last adjustment time
$lastAdj = $pdo->query("SELECT created_at FROM stock_adjustments ORDER BY id DESC LIMIT 1")->fetchColumn();

// Build query
$where  = ["p.status='active'"];
$params = [];
if ($search) {
    $like   = "%$search%";
    $where[]= "(p.name LIKE ? OR p.product_id LIKE ? OR p.sku LIKE ?)";
    $params = array_merge($params, [$like,$like,$like]);
}
if ($category_id) { $where[] = "p.category_id=?"; $params[] = $category_id; }
if ($stockFilter) { $where[] = "p.stock_status=?"; $params[] = $stockFilter; }

$whereSQL   = 'WHERE ' . implode(' AND ', $where);
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name,
           (SELECT created_at FROM stock_adjustments WHERE product_id=p.id ORDER BY id DESC LIMIT 1) AS last_adjusted
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    $whereSQL
    ORDER BY p.stock_status='outofstock' DESC, p.stock_status='critical' DESC,
             p.stock_status='lowstock' DESC, p.quantity ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Recent adjustments (admin sidebar)
$recentAdj = [];
if ($isAdmin) {
    $recentAdj = $pdo->query("
        SELECT sa.*, p.name AS product_name, p.product_id AS pid, u.name AS by_name
        FROM stock_adjustments sa
        JOIN products p ON p.id=sa.product_id
        LEFT JOIN users u ON u.id=sa.adjusted_by
        ORDER BY sa.created_at DESC LIMIT 8
    ")->fetchAll();
}

$baseUrl = APP_URL . '/pages/inventory/index.php?' . http_build_query(array_filter([
    'search'=>$search,'category'=>$category_id?:'','stock'=>$stockFilter
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
          <h1 style="font-size:1.25rem;font-weight:700">
            <?= $isAdmin ? 'Stock Management & Updates' : 'Stock Monitoring' ?>
          </h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= $isAdmin ? 'Manage and adjust stock levels across all products' : 'Monitor stock levels and get low stock alerts' ?>
          </p>
        </div>
        <?php if ($isAdmin): ?>
        <button class="btn btn-primary" onclick="openAdjModal()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Stock
        </button>
        <?php endif; ?>
      </div>

      <!-- Stat cards -->
      <div style="display:grid;grid-template-columns:repeat(<?= $isAdmin?5:4 ?>,1fr);gap:12px;margin-bottom:20px">
        <div class="stat-card">
          <div>
            <div class="stat-label">Total Units</div>
            <div class="stat-value"><?= number_format($stats['total_units'] ?? 0) ?></div>
            <div class="stat-sub"><?= $stats['total_products'] ?> products</div>
          </div>
          <div class="stat-icon blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          </div>
        </div>
        <div class="stat-card" style="<?= ($stats['low_count']??0)>0?'border-color:#fed7aa':''; ?>">
          <div>
            <div class="stat-label">Low Stock</div>
            <div class="stat-value" style="color:<?= ($stats['low_count']??0)>0?'#f97316':'var(--text)' ?>"><?= $stats['low_count'] ?? 0 ?></div>
            <div class="stat-sub">Items</div>
          </div>
          <div class="stat-icon orange"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        </div>
        <div class="stat-card" style="<?= ($stats['critical_count']??0)>0?'border-color:#fecaca':''; ?>">
          <div>
            <div class="stat-label">Critical</div>
            <div class="stat-value" style="color:<?= ($stats['critical_count']??0)>0?'#ef4444':'var(--text)' ?>"><?= $stats['critical_count'] ?? 0 ?></div>
            <div class="stat-sub">Items</div>
          </div>
          <div class="stat-icon red"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value" style="color:<?= ($stats['out_count']??0)>0?'#ef4444':'var(--text)' ?>"><?= $stats['out_count'] ?? 0 ?></div>
            <div class="stat-sub">Items</div>
          </div>
          <div class="stat-icon red"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="stat-card">
          <div>
            <div class="stat-label">Last Updated</div>
            <div class="stat-value" style="font-size:.95rem"><?= $lastAdj ? date('d M', strtotime($lastAdj)) : '—' ?></div>
            <div class="stat-sub"><?= $lastAdj ? date('h:i A', strtotime($lastAdj)) : 'No adjustments' ?></div>
          </div>
          <div class="stat-icon green"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:grid;grid-template-columns:<?= $isAdmin?'1fr 280px':'1fr' ?>;gap:16px;align-items:start">

        <!-- Main inventory table -->
        <div>
          <!-- Filters -->
          <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
            <div style="position:relative;flex:1;min-width:200px;max-width:300px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search products..." class="form-control" style="padding-left:32px">
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
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($search||$category_id||$stockFilter): ?>
            <a href="<?= APP_URL ?>/pages/inventory/index.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
          </form>

          <!-- Table -->
          <?php if (empty($products)): ?>
          <div style="text-align:center;padding:60px;color:var(--text-muted);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg)">
            <div style="font-weight:600;margin-bottom:4px">No products found</div>
            <div style="font-size:.82rem">Try adjusting your filters</div>
          </div>
          <?php else: ?>
          <div class="data-table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Category</th>
                  <th>Stock Qty</th>
                  <th>Min Level</th>
                  <th>Buy Price</th>
                  <th>Sell Price</th>
                  <th>Status</th>
                  <th>Last Adjusted</th>
                  <th>Log</th>
                  <?php if ($isAdmin): ?><th>Action</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($products as $p):
                  $badgeMap = ['instock'=>'badge-instock','lowstock'=>'badge-lowstock','critical'=>'badge-critical','outofstock'=>'badge-critical'];
                  $badge    = $badgeMap[$p['stock_status']] ?? 'badge-pending';
                  $label    = $p['stock_status']==='outofstock'?'Out of Stock':ucfirst($p['stock_status']);
                  $qtyColor = $p['quantity']<=0?'#ef4444':($p['quantity']<=$p['min_stock_level']?'#f97316':'var(--text)');
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:.85rem"><?= e($p['name']) ?></div>
                    <div style="font-size:.74rem;color:var(--text-muted)"><?= e($p['product_id']) ?></div>
                  </td>
                  <td class="text-muted"><?= e($p['category_name']??'—') ?></td>
                  <td>
                    <div style="display:flex;align-items:center;gap:8px">
                      <span style="font-weight:700;font-size:1rem;color:<?= $qtyColor ?>"><?= $p['quantity'] ?></span>
                      <!-- Mini progress bar -->
                      <div style="width:50px;height:5px;background:var(--bg);border-radius:9999px;overflow:hidden">
                        <?php $pct = $p['min_stock_level']>0 ? min(100, ($p['quantity']/$p['min_stock_level'])*50) : 100; ?>
                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $p['quantity']<=0?'#ef4444':($p['quantity']<=$p['min_stock_level']?'#f97316':'#22c55e') ?>;border-radius:9999px"></div>
                      </div>
                    </div>
                  </td>
                  <td class="text-muted"><?= $p['min_stock_level'] ?></td>
                  <td><?= $currency ?> <?= number_format($p['buy_price'],0) ?></td>
                  <td style="font-weight:600"><?= $currency ?> <?= number_format($p['sell_price'],0) ?></td>
                  <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                  <td style="font-size:.76rem;color:var(--text-muted)">
                    <?= $p['last_adjusted'] ? date('d M Y', strtotime($p['last_adjusted'])) : '—' ?>
                  </td>
                  <td>
                    <a href="<?= APP_URL ?>/pages/inventory/log.php?product_id=<?= $p['id'] ?>" class="btn btn-outline btn-xs">Log</a>
                  </td>
                  <?php if ($isAdmin): ?>
                  <td>
                    <button onclick="openAdjModal(<?= $p['id'] ?>, <?= json_encode($p['name']) ?>, <?= $p['quantity'] ?>)"
                            class="btn btn-outline btn-xs">Adjust</button>
                  </td>
                  <?php endif; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <!-- Pagination -->
          <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>
        </div>

        <!-- RIGHT: Recent adjustments (admin only) -->
        <?php if ($isAdmin): ?>
        <div style="display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--topbar-h)+24px)">
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Recent Adjustments</div>
            <?php if (empty($recentAdj)): ?>
              <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:.83rem">No adjustments yet</div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0">
              <?php foreach ($recentAdj as $adj):
                $isAdd = $adj['qty_change'] > 0;
              ?>
              <div style="padding:10px 0;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:9px">
                <div style="width:28px;height:28px;border-radius:50%;background:<?= $isAdd?'#dcfce7':'#fee2e2' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="<?= $isAdd?'#16a34a':'#dc2626' ?>" stroke-width="2.5">
                    <?= $isAdd ? '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>' : '<line x1="5" y1="12" x2="19" y2="12"/>' ?>
                  </svg>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($adj['product_name']) ?></div>
                  <div style="font-size:.76rem;color:var(--text-muted)">
                    <span style="color:<?= $isAdd?'#16a34a':'#dc2626' ?>;font-weight:600"><?= $isAdd?'+':'' ?><?= $adj['qty_change'] ?></span>
                    → <?= $adj['qty_after'] ?> units
                    · <?= $adj['by_name'] ?? 'System' ?>
                  </div>
                  <div style="font-size:.72rem;color:var(--text-muted)"><?= date('d M, h:i A', strtotime($adj['created_at'])) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<!-- Stock Adjustment Modal (admin) -->
<?php if ($isAdmin): ?>
<div id="adjModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:420px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:4px">Stock Adjustment</div>
    <div style="font-size:.84rem;color:var(--text-secondary);margin-bottom:18px" id="adjProductName"></div>
    <div style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group">
        <label class="form-label">Adjustment Type</label>
        <select id="adjType" class="form-control" onchange="updateAdjLabel()">
          <option value="add">Add Stock (+)</option>
          <option value="remove">Remove Stock (-)</option>
          <option value="adjustment">Set Exact Quantity</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" id="adjQtyLabel">Quantity to Add</label>
        <input type="number" id="adjQty" class="form-control" min="0" value="0" placeholder="Enter quantity">
        <div class="form-hint" id="adjCurrent"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <input type="text" id="adjReason" class="form-control" placeholder="e.g. Restock from supplier, Damaged goods...">
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
      <button class="btn btn-outline btn-sm" onclick="closeAdjModal()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="submitAdj()">Confirm Adjustment</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
let adjProductId  = null;
let adjCurrentQty = 0;

function openAdjModal(productId = null, name = '', currentQty = 0) {
  adjProductId  = productId;
  adjCurrentQty = currentQty;
  document.getElementById('adjProductName').textContent = productId ? name : 'Select a product below';
  document.getElementById('adjCurrent').textContent     = productId ? `Current stock: ${currentQty} units` : '';
  document.getElementById('adjQty').value    = 0;
  document.getElementById('adjReason').value = '';
  document.getElementById('adjType').value   = 'add';
  updateAdjLabel();

  // If no product pre-selected, show product search
  if (!productId) {
    document.getElementById('adjProductName').innerHTML =
      '<input type="text" id="adjProductSearch" class="form-control" style="margin-top:6px" placeholder="Search product...">';
    setupAdjSearch();
  }
  document.getElementById('adjModal').style.display = 'flex';
}

function setupAdjSearch() {
  const inp = document.getElementById('adjProductSearch');
  if (!inp) return;
  let t;
  inp.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      if (inp.value.trim().length < 2) return;
      const r = await fetch(`${APP_URL}/api/products.php?action=search&q=${encodeURIComponent(inp.value.trim())}`);
      const d = await r.json();
      // simple select
      let sel = document.getElementById('adjProductSel');
      if (!sel) {
        sel = document.createElement('select');
        sel.id = 'adjProductSel';
        sel.className = 'form-control';
        sel.style.marginTop = '6px';
        sel.onchange = function() {
          const opt = this.selectedOptions[0];
          adjProductId  = parseInt(opt.value);
          adjCurrentQty = parseInt(opt.dataset.qty);
          document.getElementById('adjCurrent').textContent = `Current stock: ${adjCurrentQty} units`;
        };
        inp.parentNode.appendChild(sel);
      }
      sel.innerHTML = '<option value="">-- Select product --</option>' +
        d.products.map(p => `<option value="${p.id}" data-qty="${p.quantity}">${p.name} (${p.product_id}) — ${p.quantity} units</option>`).join('');
    }, 300);
  });
}

function closeAdjModal() { document.getElementById('adjModal').style.display = 'none'; }

function updateAdjLabel() {
  const type = document.getElementById('adjType').value;
  const labels = { add:'Quantity to Add', remove:'Quantity to Remove', adjustment:'Set Exact Quantity' };
  document.getElementById('adjQtyLabel').textContent = labels[type] || 'Quantity';
}

async function submitAdj() {
  if (!adjProductId) { showToast('Please select a product','error'); return; }
  const qty    = parseInt(document.getElementById('adjQty').value) || 0;
  const type   = document.getElementById('adjType').value;
  const reason = document.getElementById('adjReason').value.trim();
  if (qty <= 0 && type !== 'adjustment') { showToast('Enter a valid quantity','error'); return; }

  const r = await fetch(`${APP_URL}/api/inventory.php?action=adjust`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ product_id: adjProductId, type, qty, reason })
  });
  const d = await r.json();
  closeAdjModal();
  if (d.success) { showToast('Stock adjusted successfully','success'); setTimeout(()=>location.reload(),700); }
  else showToast(d.message||'Failed','error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>