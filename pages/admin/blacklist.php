<?php
// pages/admin/blacklist.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'blacklist';
$pageTitle  = 'Customer Blacklist';

$entries = $pdo->query("
    SELECT b.id, b.phone, b.reason, b.created_at, u.name AS by_name
    FROM customer_blacklist b
    LEFT JOIN users u ON u.id = b.blacklisted_by
    ORDER BY b.created_at DESC
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
          <h1 style="font-size:1.25rem;font-weight:700">Customer Blacklist</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= count($entries) ?> blacklisted number<?= count($entries)!=1?'s':'' ?> · a warning is shown when placing an order for these numbers</p>
        </div>
        <button onclick="openAddModal()" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add to Blacklist
        </button>
      </div>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Phone</th>
              <th>Reason</th>
              <th>Added By</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($entries)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">No blacklisted numbers yet</td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $b): ?>
            <tr>
              <td style="font-weight:700;color:#b91c1c"><?= e($b['phone']) ?></td>
              <td style="font-size:.84rem;color:var(--text-secondary)"><?= e($b['reason'] ?? '—') ?></td>
              <td class="text-muted"><?= e($b['by_name'] ?? '—') ?></td>
              <td style="font-size:.8rem;color:var(--text-muted)"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
              <td>
                <button onclick="removeEntry(<?= $b['id'] ?>,'<?= e($b['phone']) ?>')" class="btn btn-xs btn-danger">Remove</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </main>
  </div>
</div>

<!-- Add modal -->
<div id="blacklistModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px">Add to Blacklist</div>
    <div class="form-group">
      <label class="form-label">Phone Number</label>
      <input type="text" id="blPhone" class="form-control" placeholder="98XXXXXXXX" maxlength="10">
    </div>
    <div class="form-group" style="margin-top:12px">
      <label class="form-label">Reason</label>
      <input type="text" id="blReason" class="form-control" placeholder="e.g. Repeated fake orders, non-payment...">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('blacklistModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveBlacklist()">Add</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';

function openAddModal() {
  document.getElementById('blPhone').value = '';
  document.getElementById('blReason').value = '';
  document.getElementById('blacklistModal').style.display = 'flex';
}

async function saveBlacklist() {
  const phone  = document.getElementById('blPhone').value.trim();
  const reason = document.getElementById('blReason').value.trim();
  if (!/^\d{10}$/.test(phone)) { showToast('Phone number must be exactly 10 digits', 'error'); return; }

  const r = await fetch(`${APP_URL}/api/admin.php?action=add_blacklist`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ phone, reason })
  });
  const d = await r.json();
  if (d.success) {
    document.getElementById('blacklistModal').style.display = 'none';
    showToast('Added to blacklist', 'success');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(d.message || 'Failed', 'error');
  }
}

async function removeEntry(id, phone) {
  if (!confirm(`Remove ${phone} from the blacklist?`)) return;
  const r = await fetch(`${APP_URL}/api/admin.php?action=remove_blacklist`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id })
  });
  const d = await r.json();
  if (d.success) { showToast('Removed from blacklist', 'success'); setTimeout(() => location.reload(), 600); }
  else showToast(d.message || 'Failed', 'error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
