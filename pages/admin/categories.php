<?php
// pages/admin/categories.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'admin';
$pageTitle  = 'Categories';

$categories = $pdo->query("
    SELECT c.id, c.name, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
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
          <h1 style="font-size:1.25rem;font-weight:700">Categories</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= count($categories) ?> categor<?= count($categories)!=1?'ies':'y' ?></p>
        </div>
        <button onclick="openAddModal()" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Category
        </button>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Products</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($categories)): ?>
            <tr><td colspan="3" style="text-align:center;padding:32px;color:var(--text-muted)">No categories yet</td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $c): ?>
            <tr>
              <td style="font-weight:600;font-size:.88rem"><?= e($c['name']) ?></td>
              <td class="text-muted"><?= $c['product_count'] ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button onclick="openEditModal(<?= $c['id'] ?>,'<?= e($c['name']) ?>')" class="btn btn-outline btn-xs">Edit</button>
                  <button onclick="confirmDelete(<?= $c['id'] ?>,'<?= e($c['name']) ?>',<?= $c['product_count'] ?>)" class="btn btn-xs btn-danger">Delete</button>
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
<div id="categoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:340px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px" id="categoryModalTitle">Add Category</div>
    <div class="form-group">
      <label class="form-label">Category Name</label>
      <input type="text" id="categoryNameInput" class="form-control" placeholder="e.g. Electronics">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('categoryModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" id="categorySaveBtn" onclick="saveCategory()">Save</button>
    </div>
  </div>
</div>

<!-- Confirm delete modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px">Delete Category</div>
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
  document.getElementById('categoryModalTitle').textContent = 'Add Category';
  document.getElementById('categoryNameInput').value = '';
  document.getElementById('categoryModal').style.display = 'flex';
}

function openEditModal(id, name) {
  editingId = id;
  document.getElementById('categoryModalTitle').textContent = 'Edit Category';
  document.getElementById('categoryNameInput').value = name;
  document.getElementById('categoryModal').style.display = 'flex';
}

async function saveCategory() {
  const name = document.getElementById('categoryNameInput').value.trim();
  if (!name) { showToast('Category name is required', 'error'); return; }

  const action  = editingId ? 'edit' : 'add';
  const payload = editingId ? { id: editingId, name } : { name };

  const r = await fetch(`${APP_URL}/api/categories.php?action=${action}`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    document.getElementById('categoryModal').style.display = 'none';
    showToast(editingId ? 'Category updated' : 'Category added', 'success');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(d.message || 'Failed', 'error');
  }
}

function confirmDelete(id, name, productCount) {
  document.getElementById('confirmMsg').textContent = productCount > 0
    ? `"${name}" is assigned to ${productCount} product(s) and cannot be deleted.`
    : `Permanently delete "${name}"? This cannot be undone.`;
  const btn = document.getElementById('confirmBtn');
  btn.style.display = productCount > 0 ? 'none' : '';
  document.getElementById('confirmModal').style.display = 'flex';
  btn.onclick = async () => {
    const r = await fetch(`${APP_URL}/api/categories.php?action=delete`, {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id })
    });
    const d = await r.json();
    document.getElementById('confirmModal').style.display = 'none';
    if (d.success) { showToast('Category deleted', 'success'); setTimeout(() => location.reload(), 600); }
    else showToast(d.message || 'Failed', 'error');
  };
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
