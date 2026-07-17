<?php
// components/sidebar.php
// Usage: $activePage = 'dashboard'; include __DIR__ . '/../components/sidebar.php';
$activePage = $activePage ?? '';
$user = currentUser();
$role = $user['role'] ?? 'staff';
$userName = $user['name'] ?? 'User';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<aside class="sidebar" id="sidebar">
  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5">
        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 0 1-8 0"/>
      </svg>
    </div>
    <div>
      <div class="brand-name">Inventory</div>
      <div class="brand-sub">STORE MANAGER</div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="sidebar-nav">
    <!-- Dashboard -->
    <a href="<?= APP_URL ?>/pages/dashboard.php"
       class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>
      Dashboard
    </a>

    <!-- Products -->
    <a href="<?= APP_URL ?>/pages/products/index.php"
       class="nav-item <?= $activePage === 'product' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
        <line x1="12" y1="22.08" x2="12" y2="12"/>
      </svg>
      Product
    </a>

    <!-- Inventory -->
    <a href="<?= APP_URL ?>/pages/inventory/index.php"
       class="nav-item <?= $activePage === 'inventory' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
        <line x1="8" y1="18" x2="21" y2="18"/>
        <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/>
        <line x1="3" y1="18" x2="3.01" y2="18"/>
      </svg>
      Inventory
    </a>

    <!-- Orders -->
    <a href="<?= APP_URL ?>/pages/orders/index.php"
       class="nav-item <?= $activePage === 'order' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 0 1-8 0"/>
      </svg>
      Order
      <?php if (($activePage === 'order') && false): // badge placeholder ?>
        <span style="margin-left:auto;background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:9999px;">3</span>
      <?php endif; ?>
    </a>

    <!-- Reports -->
    <a href="<?= APP_URL ?>/pages/reports/index.php"
       class="nav-item <?= $activePage === 'reports' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>
        <line x1="6" y1="20" x2="6" y2="14"/>
      </svg>
      Reports
    </a>

    <!-- Settings -->
    <a href="<?= APP_URL ?>/pages/settings.php"
       class="nav-item <?= $activePage === 'settings' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
      </svg>
      Settings
    </a>

    <?php if ($role === 'admin'): ?>
    <div style="margin:12px 0 4px;padding:0 12px;font-size:.68rem;font-weight:700;letter-spacing:.06em;color:var(--sidebar-text);text-transform:uppercase;opacity:.6">Admin</div>

    <!-- Users -->
    <a href="<?= APP_URL ?>/pages/admin/users.php"
       class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Users
    </a>

    <!-- Categories -->
    <a href="<?= APP_URL ?>/pages/admin/categories.php"
       class="nav-item <?= $activePage === 'categories' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
      </svg>
      Categories
    </a>

    <!-- Couriers -->
    <a href="<?= APP_URL ?>/pages/admin/couriers.php"
       class="nav-item <?= $activePage === 'couriers' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
        <circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
      </svg>
      Couriers
    </a>

    <?php endif; ?>

    <?php if ($role === 'admin' || $role === 'supervisor'): ?>
    <!-- Blacklist -->
    <a href="<?= APP_URL ?>/pages/admin/blacklist.php"
       class="nav-item <?= $activePage === 'blacklist' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
      </svg>
      Blacklist
    </a>

    <!-- Damaged Stock -->
    <a href="<?= APP_URL ?>/pages/inventory/damaged.php"
       class="nav-item <?= $activePage === 'damaged' ? 'active' : '' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
      Damaged Stock
    </a>
    <?php endif; ?>
  </nav>

  <!-- User info + logout -->
  <div class="sidebar-footer">
    <div style="display:flex;align-items:center;gap:9px;padding:8px 12px;margin-bottom:4px;border-radius:var(--radius-sm);background:rgba(255,255,255,.04)">
      <div style="width:28px;height:28px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700;flex-shrink:0">
        <?= htmlspecialchars($userInitial) ?>
      </div>
      <div style="overflow:hidden;flex:1;min-width:0">
        <div style="font-size:.8rem;font-weight:600;color:#f8fafc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($userName) ?></div>
        <div style="font-size:.68rem;color:var(--sidebar-text);text-transform:capitalize"><?= htmlspecialchars($role) ?></div>
      </div>
    </div>
    <a href="<?= APP_URL ?>/api/auth.php?action=logout" class="logout-btn" id="logoutBtn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Logout
    </a>
  </div>
</aside>