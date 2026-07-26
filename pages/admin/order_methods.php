<?php
// pages/admin/order_methods.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'order_methods';
$pageTitle  = 'Shipping & Payment Methods';

$shippingMethods = $pdo->query("
    SELECT sm.id, sm.name, sm.cost, sm.status,
           (SELECT COUNT(*) FROM orders o WHERE o.shipping_method = sm.name) AS order_count
    FROM shipping_methods sm
    ORDER BY sm.name
")->fetchAll();

$paymentMethods = $pdo->query("
    SELECT pm.id, pm.name, pm.status,
           (SELECT COUNT(*) FROM orders o WHERE o.payment_method = pm.name) AS order_count
    FROM payment_methods pm
    ORDER BY pm.name
")->fetchAll();

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <div class="mb-4">
        <h1 style="font-size:1.25rem;font-weight:700">Shipping & Payment Methods</h1>
        <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Manage the options staff can pick from when creating or editing an order</p>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">

        <!-- Shipping Methods -->
        <div>
          <div class="flex-between mb-4">
            <div style="font-size:.95rem;font-weight:700"><?= count($shippingMethods) ?> shipping method<?= count($shippingMethods)!=1?'s':'' ?></div>
            <button onclick="openAddModal('shipping')" class="btn btn-primary btn-sm">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add Shipping Method
            </button>
          </div>
          <div class="data-table-wrap">
            <table class="data-table">
              <thead>
                <tr><th>Name</th><th>Cost</th><th>Status</th><th>Orders</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($shippingMethods)): ?>
                <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No shipping methods yet</td></tr>
                <?php endif; ?>
                <?php foreach ($shippingMethods as $m): ?>
                <tr>
                  <td style="font-weight:600;font-size:.88rem"><?= e($m['name']) ?></td>
                  <td class="text-muted">Rs <?= number_format($m['cost'],0) ?></td>
                  <td><span class="badge <?= $m['status']==='active'?'badge-instock':'badge-pending' ?>"><?= ucfirst($m['status']) ?></span></td>
                  <td class="text-muted"><?= $m['order_count'] ?></td>
                  <td>
                    <div style="display:flex;gap:5px">
                      <button onclick='openEditModal("shipping", <?= $m['id'] ?>, <?= json_encode($m['name']) ?>, <?= $m['cost'] ?>, <?= json_encode($m['status']) ?>)' class="btn btn-outline btn-xs">Edit</button>
                      <button onclick='confirmDelete("shipping", <?= $m['id'] ?>, <?= json_encode($m['name']) ?>, <?= $m['order_count'] ?>)' class="btn btn-xs btn-danger">Delete</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Payment Methods -->
        <div>
          <div class="flex-between mb-4">
            <div style="font-size:.95rem;font-weight:700"><?= count($paymentMethods) ?> payment method<?= count($paymentMethods)!=1?'s':'' ?></div>
            <button onclick="openAddModal('payment')" class="btn btn-primary btn-sm">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add Payment Method
            </button>
          </div>
          <div class="data-table-wrap">
            <table class="data-table">
              <thead>
                <tr><th>Name</th><th>Status</th><th>Orders</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($paymentMethods)): ?>
                <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted)">No payment methods yet</td></tr>
                <?php endif; ?>
                <?php foreach ($paymentMethods as $m): ?>
                <tr>
                  <td style="font-weight:600;font-size:.88rem"><?= e($m['name']) ?></td>
                  <td><span class="badge <?= $m['status']==='active'?'badge-instock':'badge-pending' ?>"><?= ucfirst($m['status']) ?></span></td>
                  <td class="text-muted"><?= $m['order_count'] ?></td>
                  <td>
                    <div style="display:flex;gap:5px">
                      <button onclick='openEditModal("payment", <?= $m['id'] ?>, <?= json_encode($m['name']) ?>, null, <?= json_encode($m['status']) ?>)' class="btn btn-outline btn-xs">Edit</button>
                      <button onclick='confirmDelete("payment", <?= $m['id'] ?>, <?= json_encode($m['name']) ?>, <?= $m['order_count'] ?>)' class="btn btn-xs btn-danger">Delete</button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </main>
  </div>
</div>

<!-- Add/Edit modal -->
<div id="methodModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:340px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px" id="methodModalTitle">Add Shipping Method</div>
    <div class="form-group">
      <label class="form-label">Name</label>
      <input type="text" id="methodNameInput" class="form-control" placeholder="e.g. Standard Shipping (Rs 100)">
    </div>
    <div class="form-group" id="methodCostGroup" style="margin-top:12px">
      <label class="form-label">Cost (Rs)</label>
      <input type="number" id="methodCostInput" class="form-control" min="0" step="0.01" value="0">
    </div>
    <div class="form-group" id="methodStatusGroup" style="margin-top:12px;display:none">
      <label class="form-label">Status</label>
      <select id="methodStatusInput" class="form-control">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('methodModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveMethod()">Save</button>
    </div>
  </div>
</div>

<!-- Confirm delete modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Delete Method</div>
    <p style="font-size:.86rem;color:var(--text-secondary);margin-bottom:20px" id="confirmMsg"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('confirmModal').style.display='none'">Cancel</button>
      <button class="btn btn-sm btn-danger" id="confirmBtn">Delete</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';
let editingId = null;
let editingType = null; // 'shipping' | 'payment'

function openAddModal(type) {
  editingId = null;
  editingType = type;
  document.getElementById('methodModalTitle').textContent = type === 'shipping' ? 'Add Shipping Method' : 'Add Payment Method';
  document.getElementById('methodNameInput').value = '';
  document.getElementById('methodCostInput').value = 0;
  document.getElementById('methodCostGroup').style.display = type === 'shipping' ? '' : 'none';
  document.getElementById('methodStatusGroup').style.display = 'none';
  document.getElementById('methodModal').style.display = 'flex';
}

function openEditModal(type, id, name, cost, status) {
  editingId = id;
  editingType = type;
  document.getElementById('methodModalTitle').textContent = type === 'shipping' ? 'Edit Shipping Method' : 'Edit Payment Method';
  document.getElementById('methodNameInput').value = name;
  document.getElementById('methodCostInput').value = cost || 0;
  document.getElementById('methodCostGroup').style.display = type === 'shipping' ? '' : 'none';
  document.getElementById('methodStatusInput').value = status;
  document.getElementById('methodStatusGroup').style.display = '';
  document.getElementById('methodModal').style.display = 'flex';
}

async function saveMethod() {
  const name = document.getElementById('methodNameInput').value.trim();
  if (!name) { showToast('Name is required', 'error'); return; }

  const prefix  = editingType === 'shipping' ? 'shipping_method' : 'payment_method';
  const action  = editingId ? `edit_${prefix}` : `add_${prefix}`;
  const payload = editingId
    ? { id: editingId, name, cost: parseFloat(document.getElementById('methodCostInput').value) || 0, status: document.getElementById('methodStatusInput').value }
    : { name, cost: parseFloat(document.getElementById('methodCostInput').value) || 0 };

  const r = await fetch(`${APP_URL}/api/admin.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    document.getElementById('methodModal').style.display = 'none';
    showToast(editingId ? 'Method updated' : 'Method added', 'success');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(d.message || 'Failed', 'error');
  }
}

function confirmDelete(type, id, name, orderCount) {
  document.getElementById('confirmMsg').textContent = orderCount > 0
    ? `"${name}" has been used on ${orderCount} order(s). Those orders keep their record; only this option itself will be removed. Delete anyway?`
    : `Permanently delete "${name}"? This cannot be undone.`;
  document.getElementById('confirmModal').style.display = 'flex';
  document.getElementById('confirmBtn').onclick = async () => {
    const prefix = type === 'shipping' ? 'shipping_method' : 'payment_method';
    const r = await fetch(`${APP_URL}/api/admin.php?action=delete_${prefix}`, {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id })
    });
    const d = await r.json();
    document.getElementById('confirmModal').style.display = 'none';
    if (d.success) { showToast('Method deleted', 'success'); setTimeout(() => location.reload(), 600); }
    else showToast(d.message || 'Failed', 'error');
  };
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
