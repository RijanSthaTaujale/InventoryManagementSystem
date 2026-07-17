<?php
// pages/admin/couriers.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'couriers';
$pageTitle  = 'Couriers';

$couriers = $pdo->query("
    SELECT c.id, c.name, c.status,
           (SELECT COUNT(*) FROM orders o WHERE o.courier_name = c.name) AS order_count
    FROM couriers c
    ORDER BY c.name
")->fetchAll();

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700">Couriers</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= count($couriers) ?> courier<?= count($couriers)!=1?'s':'' ?></p>
        </div>
        <button onclick="openAddModal()" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Courier
        </button>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Status</th>
              <th>Orders</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($couriers)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted)">No couriers yet</td></tr>
            <?php endif; ?>
            <?php foreach ($couriers as $c): ?>
            <tr>
              <td style="font-weight:600;font-size:.88rem"><?= e($c['name']) ?></td>
              <td><span class="badge <?= $c['status']==='active'?'badge-instock':'badge-pending' ?>"><?= ucfirst($c['status']) ?></span></td>
              <td class="text-muted"><?= $c['order_count'] ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button onclick="openEditModal(<?= $c['id'] ?>,'<?= e($c['name']) ?>','<?= $c['status'] ?>')" class="btn btn-outline btn-xs">Edit</button>
                  <button onclick="confirmDelete(<?= $c['id'] ?>,'<?= e($c['name']) ?>',<?= $c['order_count'] ?>)" class="btn btn-xs btn-danger">Delete</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </main>
  </div>
</div>

<!-- Add/Edit modal -->
<div id="courierModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:340px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px" id="courierModalTitle">Add Courier</div>
    <div class="form-group">
      <label class="form-label">Courier Name</label>
      <input type="text" id="courierNameInput" class="form-control" placeholder="e.g. Pathao, NCM">
    </div>
    <div class="form-group" id="courierStatusGroup" style="margin-top:12px;display:none">
      <label class="form-label">Status</label>
      <select id="courierStatusInput" class="form-control">
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('courierModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveCourier()">Save</button>
    </div>
  </div>
</div>

<!-- Confirm delete modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Delete Courier</div>
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

function openAddModal() {
  editingId = null;
  document.getElementById('courierModalTitle').textContent = 'Add Courier';
  document.getElementById('courierNameInput').value = '';
  document.getElementById('courierStatusGroup').style.display = 'none';
  document.getElementById('courierModal').style.display = 'flex';
}

function openEditModal(id, name, status) {
  editingId = id;
  document.getElementById('courierModalTitle').textContent = 'Edit Courier';
  document.getElementById('courierNameInput').value = name;
  document.getElementById('courierStatusInput').value = status;
  document.getElementById('courierStatusGroup').style.display = '';
  document.getElementById('courierModal').style.display = 'flex';
}

async function saveCourier() {
  const name = document.getElementById('courierNameInput').value.trim();
  if (!name) { showToast('Courier name is required', 'error'); return; }

  const action  = editingId ? 'edit_courier' : 'add_courier';
  const payload = editingId
    ? { id: editingId, name, status: document.getElementById('courierStatusInput').value }
    : { name };

  const r = await fetch(`${APP_URL}/api/admin.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    document.getElementById('courierModal').style.display = 'none';
    showToast(editingId ? 'Courier updated' : 'Courier added', 'success');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(d.message || 'Failed', 'error');
  }
}

function confirmDelete(id, name, orderCount) {
  document.getElementById('confirmMsg').textContent = orderCount > 0
    ? `"${name}" has been used on ${orderCount} order(s). Those orders keep their record; only the courier entry itself will be removed. Delete anyway?`
    : `Permanently delete "${name}"? This cannot be undone.`;
  document.getElementById('confirmModal').style.display = 'flex';
  document.getElementById('confirmBtn').onclick = async () => {
    const r = await fetch(`${APP_URL}/api/admin.php?action=delete_courier`, {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id })
    });
    const d = await r.json();
    document.getElementById('confirmModal').style.display = 'none';
    if (d.success) { showToast('Courier deleted', 'success'); setTimeout(() => location.reload(), 600); }
    else showToast(d.message || 'Failed', 'error');
  };
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
