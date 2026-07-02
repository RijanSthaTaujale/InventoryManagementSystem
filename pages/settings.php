<?php
// pages/settings.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
 
$activePage = 'settings';
$pageTitle  = 'System Settings';
$user = currentUser();
$role = $user['role'];
 
$success = '';
$error   = '';
 
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
 
  if ($action === 'profile') {
    $name         = trim($_POST['name'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
 
    if (!$name) {
      $error = 'Full name is required.';
    } else {
      // Email changes require admin approval — we store but flag pending
      $emailChanged = ($email !== $user['email']);
      $stmt = $pdo->prepare("UPDATE users SET name=?, display_name=? WHERE id=?");
      $stmt->execute([$name, $display_name ?: null, $user['id']]);
 
      // Update session
      $_SESSION['user']['name']         = $name;
      $_SESSION['user']['display_name'] = $display_name;
 
      $success = 'Profile updated successfully.' . ($emailChanged ? ' Email changes require administrator approval.' : '');
 
      // Refresh user
      $user = currentUser();
    }
 
  } elseif ($action === 'password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
 
    if (!$current || !$new || !$confirm) {
      $error = 'All password fields are required.';
    } elseif ($new !== $confirm) {
      $error = 'New passwords do not match.';
    } elseif (strlen($new) < 12) {
      $error = 'Password must be at least 12 characters.';
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $new)) {
      $error = 'Password must contain at least one special character.';
    } elseif (!preg_match('/[0-9]/', $new)) {
      $error = 'Password must contain at least one number.';
    } else {
      // Verify current password
      $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
      $stmt->execute([$user['id']]);
      $hash = $stmt->fetchColumn();
 
      if (!password_verify($current, $hash)) {
        $error = 'Current password is incorrect.';
      } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$newHash, $user['id']]);
        $success = 'Password updated successfully.';
      }
    }
 
  } elseif ($action === 'deactivate') {
    $pdo->prepare("UPDATE users SET status='deactivated' WHERE id=?")->execute([$user['id']]);
    session_destroy();
    header('Location: ' . APP_URL . '/pages/login.php');
    exit;

  } elseif ($action === 'business_info' && $role === 'admin') {
    $fields = [
      'business_name'    => trim($_POST['business_name']    ?? ''),
      'business_pan'     => trim($_POST['business_pan']     ?? ''),
      'business_address' => trim($_POST['business_address'] ?? ''),
      'business_phone'   => trim($_POST['business_phone']   ?? ''),
      'business_email'   => trim($_POST['business_email']   ?? ''),
      'business_logo'    => trim($_POST['business_logo']    ?? ''),
    ];
    $stmt = $pdo->prepare("
      INSERT INTO settings (`key`, `value`, updated_by) VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)
    ");
    foreach ($fields as $key => $value) {
      $stmt->execute([$key, $value, $user['id']]);
    }
    $success = 'Business info updated successfully.';
  }
}
 
$userInitial    = strtoupper(substr($user['name'], 0, 1));
$displayName    = $user['display_name'] ?? '';
 
include __DIR__ . '/../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>
 
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../components/topbar.php'; ?>
 
    <main class="main-content">
 
      <!-- Page header -->
      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700">System Settings</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Manage your profile, security preferences, and portal views.</p>
        </div>
        <button class="btn btn-dark" id="saveAllBtn" onclick="saveAll()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Changes
        </button>
      </div>
 
      <!-- Alerts -->
      <?php if ($success): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#ecfdf5;border:1px solid #86efac;border-radius:var(--radius-md);color:#065f46;font-size:.88rem;margin-bottom:16px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;font-size:.88rem;margin-bottom:16px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
 
      <!-- ── Profile Settings ── -->
      <form method="POST" id="profileForm" style="margin-bottom:16px">
        <input type="hidden" name="action" value="profile">
        <div class="card">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
            <div style="width:36px;height:36px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            </div>
            <div class="card-title">Profile Settings</div>
          </div>
 
          <div class="grid-2" style="gap:16px;margin-bottom:16px">
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Display Name</label>
              <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($displayName) ?>" placeholder="A. <?= htmlspecialchars(explode(' ',$user['name'])[0]) ?>">
            </div>
          </div>
 
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($user['email']) ?>"
                   style="background:var(--text);color:#fff;border-color:var(--text)">
            <div class="form-hint">Email changes require administrator approval</div>
          </div>
        </div>
      </form>
 
      <!-- ── Security ── -->
      <form method="POST" id="passwordForm" style="margin-bottom:16px">
        <input type="hidden" name="action" value="password">
        <div class="card">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
            <div style="width:36px;height:36px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div class="card-title">Security</div>
          </div>
          <p style="font-size:.84rem;color:var(--text-secondary);margin-bottom:20px">Update your password to keep your account secure. We recommend using a unique password that you don't use elsewhere.</p>
 
          <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:16px;align-items:start">
            <!-- Password requirements box -->
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius-md);padding:14px 16px">
              <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;font-weight:700;font-size:.84rem;color:#92400e">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Password Requirements
              </div>
              <ul style="list-style:none;display:flex;flex-direction:column;gap:6px">
                <li style="font-size:.8rem;color:#78350f;display:flex;align-items:center;gap:6px">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Minimum 12 characters
                </li>
                <li style="font-size:.8rem;color:#78350f;display:flex;align-items:center;gap:6px">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  One special character
                </li>
                <li style="font-size:.8rem;color:#78350f;display:flex;align-items:center;gap:6px">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  One numeric value
                </li>
              </ul>
            </div>
 
            <!-- Password fields -->
            <div style="display:flex;flex-direction:column;gap:14px">
              <div class="form-group">
                <label class="form-label">Current Password</label>
                <div class="input-group" style="position:relative">
                  <input type="password" name="current_password" id="curPwd" class="form-control" placeholder="••••••••••••">
                  <button type="button" onclick="togglePwd('curPwd',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);display:flex">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
              <div class="grid-2" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">New Password</label>
                  <input type="password" name="new_password" class="form-control" placeholder="••••••••••••">
                </div>
                <div class="form-group">
                  <label class="form-label">Confirm Password</label>
                  <input type="password" name="confirm_password" class="form-control" placeholder="••••••••••••">
                </div>
              </div>
              <div>
                <button type="submit" class="btn btn-outline btn-sm">Update Password</button>
              </div>
            </div>
          </div>
        </div>
      </form>
 
      <?php if ($role === 'admin'):
        $bizName    = getSetting('business_name', 'Pompoy Apparels');
        $bizPan     = getSetting('business_pan', '126106185');
        $bizAddress = getSetting('business_address', 'Kalanki, Kathmandu');
        $bizPhone   = getSetting('business_phone', '9802377999');
        $bizEmail   = getSetting('business_email', 'sochejastai@gmail.com');
        $bizLogo    = getSetting('business_logo', '/assets/images/business-logo.png');
      ?>
      <!-- ── Business / Bill Info ── -->
      <form method="POST" id="businessForm" style="margin-bottom:16px">
        <input type="hidden" name="action" value="business_info">
        <div class="card">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
            <div style="width:36px;height:36px;background:#e0e7ff;border-radius:50%;display:flex;align-items:center;justify-content:center">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            </div>
            <div class="card-title">Business / Bill Info</div>
          </div>
          <p style="font-size:.84rem;color:var(--text-secondary);margin-bottom:20px">This information appears on printed order bills.</p>

          <div class="grid-2" style="gap:16px;margin-bottom:16px">
            <div class="form-group">
              <label class="form-label">Business Name</label>
              <input type="text" name="business_name" class="form-control" value="<?= e($bizName) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">PAN No.</label>
              <input type="text" name="business_pan" class="form-control" value="<?= e($bizPan) ?>">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:16px">
            <label class="form-label">Address</label>
            <input type="text" name="business_address" class="form-control" value="<?= e($bizAddress) ?>">
          </div>
          <div class="grid-2" style="gap:16px;margin-bottom:16px">
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="text" name="business_phone" class="form-control" value="<?= e($bizPhone) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="business_email" class="form-control" value="<?= e($bizEmail) ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Logo Path</label>
            <div style="display:flex;align-items:center;gap:12px">
              <img src="<?= APP_URL . e($bizLogo) ?>" alt="Logo preview" style="width:44px;height:44px;object-fit:contain;border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff">
              <input type="text" name="business_logo" class="form-control" value="<?= e($bizLogo) ?>" placeholder="/assets/images/business-logo.png">
            </div>
            <div class="form-hint">Path to a logo image file relative to the app root. Replace assets/images/business-logo.png to change it.</div>
          </div>
          <div style="margin-top:16px">
            <button type="submit" class="btn btn-outline btn-sm">Save Business Info</button>
          </div>
        </div>
      </form>
      <?php endif; ?>

      <!-- ── Staff Deactivation ── -->
      <div class="card" style="border-color:#fecaca">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:12px">
            <div style="width:36px;height:36px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
              <div style="font-weight:700;color:#ef4444;font-size:.95rem">Staff Deactivation</div>
              <div style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Temporarily disable your access to the portal. This will not delete your data.</div>
            </div>
          </div>
          <button class="btn btn-outline btn-sm" style="border-color:#ef4444;color:#ef4444" onclick="confirmDeactivate()">
            Deactivate Account
          </button>
        </div>
      </div>
 
    </main>
  </div>
</div>
 
<!-- Confirm deactivation modal -->
<div id="deactivateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:none;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:380px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px;color:var(--text)">Deactivate Account?</div>
    <p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:20px">Your account will be temporarily disabled. Contact an admin to re-enable access.</p>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn btn-outline btn-sm" onclick="closeModal()">Cancel</button>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="deactivate">
        <button type="submit" class="btn btn-danger btn-sm">Yes, Deactivate</button>
      </form>
    </div>
  </div>
</div>
 
<script>
function togglePwd(id, btn) {
  const el = document.getElementById(id);
  el.type = el.type === 'text' ? 'password' : 'text';
}
 
function saveAll() {
  document.getElementById('profileForm').submit();
}
 
function confirmDeactivate() {
  document.getElementById('deactivateModal').style.display = 'flex';
}
function closeModal() {
  document.getElementById('deactivateModal').style.display = 'none';
}
</script>
 
<div class="toast-container" id="toastContainer"></div>
<?php include __DIR__ . '/../components/foot.php'; ?>
 












