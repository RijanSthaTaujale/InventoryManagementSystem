<?php
// pages/admin/add_user.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser(); // must be assigned before the role check below

if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'admin';
$editId     = (int)($_GET['edit'] ?? 0);
$isEdit     = $editId > 0;
$pageTitle  = $isEdit ? 'Edit User' : 'Add User';

$editUser = [];
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
    if (!$editUser) redirect('/pages/admin/users.php');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $role     = trim($_POST['role']     ?? 'staff');
    $status   = trim($_POST['status']   ?? 'active');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$name)  { $error = 'Name is required.'; }
    elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Valid email is required.'; }
    elseif (!in_array($role,   ['admin','staff','supervisor']))     { $error = 'Invalid role.'; }
    elseif (!in_array($status, ['active','inactive']))              { $error = 'Invalid status.'; }
    elseif (!$isEdit && !$password)                                 { $error = 'Password is required.'; }
    elseif ($password && $password !== $confirm)                    { $error = 'Passwords do not match.'; }
    elseif ($password && strlen($password) < 6)                    { $error = 'Password must be at least 6 characters.'; }
    else {
        // Check email unique
        $dup = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $dup->execute([$email, $editId ?: 0]);
        if ($dup->fetch()) {
            $error = 'Email already exists.';
        } else {
            if ($isEdit) {
                if ($password) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET name=?,email=?,role=?,status=?,password=? WHERE id=?")
                        ->execute([$name, $email, $role, $status, $hash, $editId]);
                } else {
                    $pdo->prepare("UPDATE users SET name=?,email=?,role=?,status=? WHERE id=?")
                        ->execute([$name, $email, $role, $status, $editId]);
                }
                $success = 'User updated successfully.';
                // Refresh
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $stmt->execute([$editId]);
                $editUser = $stmt->fetch();
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,?,?)")
                    ->execute([$name, $email, $hash, $role, $status]);
                redirect('/pages/admin/users.php');
            }
        }
    }
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
            <a href="<?= APP_URL ?>/pages/admin/users.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Users
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem"><?= $isEdit ? e($editUser['name']) : 'Add User' ?></span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700"><?= $isEdit ? 'Edit User' : 'Add New User' ?></h1>
        </div>
        <a href="<?= APP_URL ?>/pages/admin/users.php" class="btn btn-outline btn-sm">← Back</a>
      </div>

      <?php if ($error): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;margin-bottom:16px;font-size:.88rem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= e($error) ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#ecfdf5;border:1px solid #86efac;border-radius:var(--radius-md);color:#065f46;margin-bottom:16px;font-size:.88rem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?= e($success) ?>
      </div>
      <?php endif; ?>

      <div style="max-width:600px">
        <form method="POST">
          <div class="card" style="display:flex;flex-direction:column;gap:16px">

            <!-- Avatar preview -->
            <div style="display:flex;align-items:center;gap:14px;padding-bottom:16px;border-bottom:1px solid var(--border)">
              <div style="width:56px;height:56px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;font-weight:700" id="avatarPreview">
                <?= $isEdit ? strtoupper(substr($editUser['name'],0,1)) : '?' ?>
              </div>
              <div>
                <div style="font-weight:600" id="namePreview"><?= $isEdit ? e($editUser['name']) : 'New User' ?></div>
                <div style="font-size:.8rem;color:var(--text-muted)" id="rolePreview"><?= $isEdit ? ucfirst($editUser['role']) : 'Staff' ?></div>
              </div>
            </div>

            <!-- Name + Email -->
            <div class="grid-2" style="gap:14px">
              <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="name" class="form-control" value="<?= e($editUser['name'] ?? '') ?>"
                       required placeholder="John Doe"
                       oninput="document.getElementById('namePreview').textContent=this.value||'New User';document.getElementById('avatarPreview').textContent=(this.value||'?')[0].toUpperCase()">
              </div>
              <div class="form-group">
                <label class="form-label">Email Address *</label>
                <input type="email" name="email" class="form-control" value="<?= e($editUser['email'] ?? '') ?>"
                       required placeholder="user@example.com">
              </div>
            </div>

            <!-- Role + Status -->
            <div class="grid-2" style="gap:14px">
              <div class="form-group">
                <label class="form-label">Role *</label>
                <select name="role" class="form-control"
                        onchange="document.getElementById('rolePreview').textContent=this.options[this.selectedIndex].text">
                  <?php foreach (['staff'=>'Staff','supervisor'=>'Supervisor','admin'=>'Admin'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= ($editUser['role']??'staff')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-hint">
                  Staff: orders &amp; products. Supervisor: + courier/delivery statuses. Admin: full access + revenue.
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Account Status *</label>
                <select name="status" class="form-control">
                  <option value="active"   <?= ($editUser['status']??'active')==='active'?'selected':'' ?>>Active</option>
                  <option value="inactive" <?= ($editUser['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
              </div>
            </div>

            <div style="height:1px;background:var(--border)"></div>

            <!-- Password -->
            <div>
              <div style="font-size:.88rem;font-weight:700;margin-bottom:12px;color:var(--text)">
                <?= $isEdit ? 'Change Password' : 'Set Password' ?>
                <?php if ($isEdit): ?>
                <span style="font-size:.76rem;font-weight:400;color:var(--text-muted)"> (leave blank to keep current)</span>
                <?php endif; ?>
              </div>
              <div class="grid-2" style="gap:14px">
                <div class="form-group">
                  <label class="form-label"><?= $isEdit ? 'New Password' : 'Password *' ?></label>
                  <div style="position:relative">
                    <input type="password" name="password" id="pwd" class="form-control"
                           placeholder="••••••••" <?= $isEdit?'':'required' ?>>
                    <button type="button" onclick="togglePwd('pwd')"
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted)">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Confirm Password</label>
                  <input type="password" name="confirm" class="form-control" placeholder="••••••••">
                </div>
              </div>
            </div>

            <!-- Submit -->
            <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:8px;border-top:1px solid var(--border)">
              <a href="<?= APP_URL ?>/pages/admin/users.php" class="btn btn-outline btn-sm">Cancel</a>
              <button type="submit" class="btn btn-primary btn-sm">
                <?= $isEdit ? 'Update User' : 'Create User' ?>
              </button>
            </div>

          </div>
        </form>

        <?php if ($isEdit && $editUser['id'] !== $user['id']): ?>
        <!-- Danger zone -->
        <div class="card" style="margin-top:16px;border-color:#fecaca">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
              <div style="font-weight:700;color:#ef4444;margin-bottom:3px">Danger Zone</div>
              <div style="font-size:.82rem;color:var(--text-secondary)">Permanently delete this user account. This cannot be undone.</div>
            </div>
            <button onclick="deleteUser()" class="btn btn-danger btn-sm">Delete User</button>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
function togglePwd(id) {
  const el = document.getElementById(id);
  el.type = el.type === 'text' ? 'password' : 'text';
}

<?php if ($isEdit && $editUser['id'] !== $user['id']): ?>
async function deleteUser() {
  if (!confirm('Delete "<?= e($editUser['name']) ?>"? This cannot be undone.')) return;
  const r = await fetch('<?= APP_URL ?>/api/admin.php?action=delete_user', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: <?= $editId ?>})
  });
  const d = await r.json();
  if (d.success) {
    showToast('User deleted', 'success');
    setTimeout(() => window.location.href = '<?= APP_URL ?>/pages/admin/users.php', 700);
  } else {
    showToast(d.message || 'Failed', 'error');
  }
}
<?php endif; ?>
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>