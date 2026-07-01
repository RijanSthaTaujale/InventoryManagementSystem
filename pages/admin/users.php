<?php
// pages/admin/users.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'users';
$pageTitle  = 'User Management';

$search     = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role']        ?? '';
$status     = $_GET['status']      ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;

$where  = ['1=1'];
$params = [];

if ($search) {
    $like   = "%$search%";
    $where[]= "(name LIKE ? OR email LIKE ?)";
    $params = array_merge($params, [$like, $like]);
}
if ($roleFilter) { $where[] = "role=?";   $params[] = $roleFilter; }
if ($status)     { $where[] = "status=?"; $params[] = $status; }

$whereSQL   = 'WHERE ' . implode(' AND ', $where);
$countStmt  = $pdo->prepare("SELECT COUNT(*) FROM users $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM users $whereSQL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Counts
$roleCounts = $pdo->query("SELECT role, COUNT(*) FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalUsers = array_sum($roleCounts);

$baseUrl = APP_URL . '/pages/admin/users.php?' . http_build_query(array_filter([
    'search' => $search, 'role' => $roleFilter, 'status' => $status
]));

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700">User Management</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px"><?= number_format($totalUsers) ?> total accounts</p>
        </div>
        <a href="<?= APP_URL ?>/pages/admin/add_user.php" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add User
        </a>
      </div>

      <!-- Stat cards -->
      <div class="grid-4" style="gap:12px;margin-bottom:20px">
        <?php foreach ([
          ['Total','all','blue',null],
          ['Admin','admin','purple',null],
          ['Staff','staff','green',null],
          ['Supervisor','supervisor','orange',null],
        ] as [$label,$key,$color,$_]): $cnt = $key==='all'?$totalUsers:($roleCounts[$key]??0); ?>
        <a href="<?= APP_URL ?>/pages/admin/users.php?role=<?= $key==='all'?'':$key ?>" style="text-decoration:none">
          <div class="stat-card" style="padding:14px 16px;<?= $roleFilter===($key==='all'?'':$key)?'border-color:var(--primary);box-shadow:0 0 0 2px rgba(59,91,219,.12)':'' ?>">
            <div>
              <div class="stat-label"><?= $label ?></div>
              <div class="stat-value" style="font-size:1.3rem"><?= $cnt ?></div>
            </div>
            <div class="stat-icon <?= $color ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filters -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
        <div style="position:relative;flex:1;min-width:200px;max-width:300px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name or email..." class="form-control" style="padding-left:32px">
        </div>
        <select name="role" class="form-control" style="width:auto">
          <option value="">All Roles</option>
          <option value="admin"      <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
          <option value="staff"      <?= $roleFilter==='staff'?'selected':'' ?>>Staff</option>
          <option value="supervisor" <?= $roleFilter==='supervisor'?'selected':'' ?>>Supervisor</option>
        </select>
        <select name="status" class="form-control" style="width:auto">
          <option value="">All Status</option>
          <option value="active"      <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive"    <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
          <option value="deactivated" <?= $status==='deactivated'?'selected':'' ?>>Deactivated</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($search||$roleFilter||$status): ?>
        <a href="<?= APP_URL ?>/pages/admin/users.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Status</th>
              <th>Last Login</th>
              <th>Joined</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">No users found</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u):
              $roleBadge   = ['admin'=>'badge-dispatched','staff'=>'badge-confirmed','supervisor'=>'badge-in_courier'][$u['role']] ?? 'badge-pending';
              $statusBadge = ['active'=>'badge-confirmed','inactive'=>'badge-pending','deactivated'=>'badge-cancelled'][$u['status']] ?? 'badge-pending';
              $isSelf      = $u['id'] === $user['id'];
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:34px;height:34px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;font-weight:700;flex-shrink:0">
                    <?= strtoupper(substr($u['name'],0,1)) ?>
                  </div>
                  <div>
                    <div style="font-weight:600;font-size:.88rem"><?= e($u['name']) ?> <?= $isSelf ? '<span style="font-size:.7rem;color:var(--text-muted)">(you)</span>' : '' ?></div>
                    <div style="font-size:.76rem;color:var(--text-muted)"><?= e($u['email']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge <?= $roleBadge ?>"><?= ucfirst($u['role']) ?></span></td>
              <td><span class="badge <?= $statusBadge ?>"><?= ucfirst($u['status']) ?></span></td>
              <td style="font-size:.8rem;color:var(--text-muted)">
                <?= $u['last_login'] ? date('d M Y, h:i A', strtotime($u['last_login'])) : 'Never' ?>
              </td>
              <td style="font-size:.8rem;color:var(--text-muted)"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <a href="<?= APP_URL ?>/pages/admin/add_user.php?edit=<?= $u['id'] ?>" class="btn btn-outline btn-xs">Edit</a>
                  <?php if (!$isSelf): ?>
                    <?php if ($u['status'] === 'active'): ?>
                    <button onclick="setStatus(<?= $u['id'] ?>,'inactive','<?= e($u['name']) ?>')" class="btn btn-xs btn-amber">Deactivate</button>
                    <?php else: ?>
                    <button onclick="setStatus(<?= $u['id'] ?>,'active','<?= e($u['name']) ?>')" class="btn btn-xs btn-success">Activate</button>
                    <?php endif; ?>
                    <button onclick="confirmDelete(<?= $u['id'] ?>,'<?= e($u['name']) ?>')" class="btn btn-xs btn-danger">Delete</button>
                  <?php endif; ?>
                </div>
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

<!-- Confirm modal -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:360px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:6px" id="confirmTitle"></div>
    <p style="font-size:.86rem;color:var(--text-secondary);margin-bottom:20px" id="confirmMsg"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-outline btn-sm" onclick="closeConfirm()">Cancel</button>
      <button class="btn btn-sm" id="confirmBtn">Confirm</button>
    </div>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
function closeConfirm() { document.getElementById('confirmModal').style.display='none'; }

function setStatus(id, status, name) {
  document.getElementById('confirmTitle').textContent = status==='active' ? 'Activate Account' : 'Deactivate Account';
  document.getElementById('confirmMsg').textContent   = `${status==='active'?'Activate':'Deactivate'} account for "${name}"?`;
  const btn = document.getElementById('confirmBtn');
  btn.className = 'btn btn-sm ' + (status==='active'?'btn-success':'btn-amber');
  btn.textContent = status==='active' ? 'Activate' : 'Deactivate';
  document.getElementById('confirmModal').style.display = 'flex';
  btn.onclick = async () => {
    const r = await fetch('<?= APP_URL ?>/api/admin.php?action=set_status', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id, status})
    });
    const d = await r.json();
    closeConfirm();
    if (d.success) { showToast('User updated','success'); setTimeout(()=>location.reload(),700); }
    else showToast(d.message||'Failed','error');
  };
}

function confirmDelete(id, name) {
  document.getElementById('confirmTitle').textContent = 'Delete User';
  document.getElementById('confirmMsg').textContent   = `Permanently delete "${name}"? This cannot be undone.`;
  const btn = document.getElementById('confirmBtn');
  btn.className = 'btn btn-sm btn-danger';
  btn.textContent = 'Delete';
  document.getElementById('confirmModal').style.display = 'flex';
  btn.onclick = async () => {
    const r = await fetch('<?= APP_URL ?>/api/admin.php?action=delete_user', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    closeConfirm();
    if (d.success) { showToast('User deleted','success'); setTimeout(()=>location.reload(),700); }
    else showToast(d.message||'Failed','error');
  };
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>