<?php
// pages/inventory/damaged.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if (!in_array($user['role'], ['admin', 'supervisor'], true)) redirect('/pages/dashboard.php');

$activePage = 'damaged';
$pageTitle  = 'Damaged Stock';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$total      = (int)$pdo->query("SELECT COUNT(*) FROM damaged_products")->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT d.*, p.name AS product_name, p.product_id AS pid, u.name AS logged_by_name
    FROM damaged_products d
    JOIN products p ON p.id = d.product_id
    LEFT JOIN users u ON u.id = d.logged_by
    ORDER BY d.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute();
$rows = $stmt->fetchAll();

$totalUnits = (int)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM damaged_products")->fetchColumn();

$baseUrl = APP_URL . '/pages/inventory/damaged.php';

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
            <a href="<?= APP_URL ?>/pages/inventory/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Inventory
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem">Damaged Stock</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Damaged Stock</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($total) ?> log entr<?= $total!=1?'ies':'y' ?> · <?= number_format($totalUnits) ?> units total
          </p>
        </div>
        <button onclick="openDamageModal()" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Log Damage
        </button>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Qty</th>
              <th>Reason</th>
              <th>Logged By</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No damaged stock logged yet</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:.85rem"><?= e($r['product_name']) ?></div>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($r['pid']) ?></div>
              </td>
              <td style="font-weight:700;color:#dc2626"><?= $r['qty'] ?></td>
              <td style="font-size:.84rem;color:var(--text-secondary)"><?= e($r['reason'] ?? '—') ?></td>
              <td style="font-size:.8rem;color:var(--text-secondary)"><?= e($r['logged_by_name'] ?? '—') ?></td>
              <td>
                <div style="font-size:.8rem"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= date('h:i A', strtotime($r['created_at'])) ?></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): include __DIR__ . '/../../components/pagination.php'; endif; ?>

    </main>
  </div>
</div>

<!-- Log Damage modal -->
<div id="damageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:400px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:18px">Log Damaged Stock</div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="form-group" style="position:relative">
        <label class="form-label">Product</label>
        <input type="text" id="damageProductSearch" class="form-control" placeholder="Search product..." autocomplete="off">
        <div id="damageSearchDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-md);z-index:200;max-height:220px;overflow-y:auto;margin-top:4px"></div>
        <div id="damageSelectedProduct" style="font-size:.78rem;color:var(--text-muted);margin-top:4px"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Quantity Damaged</label>
        <input type="number" id="damageQty" class="form-control" min="1" value="1">
      </div>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <input type="text" id="damageReason" class="form-control" placeholder="e.g. Water damage, broken in transit...">
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
      <button class="btn btn-outline btn-sm" onclick="closeDamageModal()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="submitDamage()">Log Damage</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
let damageProductId = null;
let damageMaxQty     = 0;

function openDamageModal() {
  damageProductId = null;
  document.getElementById('damageProductSearch').value = '';
  document.getElementById('damageSelectedProduct').textContent = '';
  document.getElementById('damageQty').value = 1;
  document.getElementById('damageReason').value = '';
  document.getElementById('damageModal').style.display = 'flex';
}
function closeDamageModal() { document.getElementById('damageModal').style.display = 'none'; }

const damageSearchInput = document.getElementById('damageProductSearch');
const damageDropdown    = document.getElementById('damageSearchDropdown');
let damageSearchTimer;

damageSearchInput.addEventListener('input', () => {
  clearTimeout(damageSearchTimer);
  const q = damageSearchInput.value.trim();
  damageProductId = null;
  if (q.length < 2) { damageDropdown.style.display = 'none'; return; }
  damageSearchTimer = setTimeout(async () => {
    const r = await fetch(`${APP_URL}/api/products.php?action=search&q=${encodeURIComponent(q)}`);
    const d = await r.json();
    if (!d.products?.length) { damageDropdown.innerHTML = '<div style="padding:12px;text-align:center;color:var(--text-muted);font-size:.82rem">No products found</div>'; damageDropdown.style.display = 'block'; return; }
    damageDropdown.innerHTML = d.products.map(p => `
      <div onclick='selectDamageProduct(${p.id}, ${JSON.stringify(p.name)}, ${p.quantity})'
           style="padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:.84rem"
           onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
        <div style="font-weight:600">${p.name}</div>
        <div style="font-size:.74rem;color:var(--text-muted)">${p.product_id} · Stock: ${p.quantity}</div>
      </div>`).join('');
    damageDropdown.style.display = 'block';
  }, 280);
});
document.addEventListener('click', e => { if (!damageSearchInput.contains(e.target) && !damageDropdown.contains(e.target)) damageDropdown.style.display = 'none'; });

function selectDamageProduct(id, name, qty) {
  damageProductId = id;
  damageMaxQty    = qty;
  damageSearchInput.value = name;
  document.getElementById('damageSelectedProduct').textContent = `Current stock: ${qty} units`;
  damageDropdown.style.display = 'none';
}

async function submitDamage() {
  if (!damageProductId) { showToast('Please select a product', 'error'); return; }
  const qty    = parseInt(document.getElementById('damageQty').value) || 0;
  const reason = document.getElementById('damageReason').value.trim();
  if (qty < 1) { showToast('Enter a valid quantity', 'error'); return; }

  const r = await fetch(`${APP_URL}/api/inventory.php?action=log_damage`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ product_id: damageProductId, qty, reason })
  });
  const d = await r.json();
  closeDamageModal();
  if (d.success) { showToast('Damage logged', 'success'); setTimeout(() => location.reload(), 700); }
  else showToast(d.message || 'Failed', 'error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
