<?php
// components/topbar.php
// Usage: include __DIR__ . '/../components/topbar.php';
$user = currentUser();
$userName    = $user['name']  ?? 'User';
$userInitial = strtoupper(substr($userName, 0, 1));
$userRole    = $user['role']  ?? 'staff';

// Count low-stock notifications (simple query, cached via static)
$notifCount = 0;
try {
  $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_status IN ('lowstock','critical','outofstock') AND status='active'");
  $notifCount = (int)$stmt->fetchColumn();
} catch (Exception $e) { $notifCount = 0; }
?>
<header class="topbar" id="topbar">
  <!-- Sidebar toggle -->
  <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- Search -->
  <div class="topbar-search">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input type="text" placeholder="Search anything..." id="globalSearch" autocomplete="off"/>
  </div>

  <!-- Actions -->
  <div class="topbar-actions">
    <!-- Messages -->
    <button class="topbar-icon-btn" title="Messages">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
    </button>

    <!-- Notifications -->
    <button class="topbar-icon-btn" title="Notifications" id="notifBtn">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <?php if ($notifCount > 0): ?>
        <span class="notif-dot"></span>
      <?php endif; ?>
    </button>

    <!-- Notifications dropdown (hidden by default) -->
    <div id="notifDropdown" style="display:none;position:absolute;top:50px;right:60px;width:300px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);z-index:500;overflow:hidden">
      <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <span style="font-weight:700;font-size:.9rem">Notifications</span>
        <?php if ($notifCount > 0): ?>
          <span style="background:#fee2e2;color:#b91c1c;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:9999px"><?= $notifCount ?> alert<?= $notifCount > 1 ? 's' : '' ?></span>
        <?php endif; ?>
      </div>
      <div style="max-height:260px;overflow-y:auto">
        <?php if ($notifCount > 0): ?>
          <a href="<?= APP_URL ?>/pages/inventory/index.php" style="display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);color:var(--text);text-decoration:none" class="notif-item">
            <div style="width:34px;height:34px;background:#fee2e2;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="m10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div>
              <div style="font-size:.84rem;font-weight:600"><?= $notifCount ?> product<?= $notifCount > 1 ? 's' : '' ?> need restocking</div>
              <div style="font-size:.76rem;color:var(--text-muted);margin-top:2px">Low or critical stock levels</div>
            </div>
          </a>
        <?php else: ?>
          <div style="padding:28px 16px;text-align:center;color:var(--text-muted);font-size:.85rem">No new notifications</div>
        <?php endif; ?>
      </div>
      <div style="padding:10px 16px;border-top:1px solid var(--border)">
        <a href="<?= APP_URL ?>/pages/inventory/index.php" style="font-size:.8rem;font-weight:600;color:var(--primary)">View all alerts →</a>
      </div>
    </div>

    <!-- Avatar dropdown -->
    <div style="position:relative" id="avatarWrap">
      <div class="topbar-avatar" id="avatarBtn" title="<?= htmlspecialchars($userName) ?>">
        <?= htmlspecialchars($userInitial) ?>
      </div>
      <div id="avatarDropdown" style="display:none;position:absolute;top:42px;right:0;width:200px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);z-index:500;overflow:hidden">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border)">
          <div style="font-weight:700;font-size:.88rem"><?= htmlspecialchars($userName) ?></div>
          <div style="font-size:.76rem;color:var(--text-muted);text-transform:capitalize;margin-top:2px"><?= htmlspecialchars($userRole) ?></div>
        </div>
        <a href="<?= APP_URL ?>/pages/settings.php" style="display:flex;align-items:center;gap:9px;padding:10px 16px;color:var(--text);font-size:.85rem;border-bottom:1px solid var(--border)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
          Profile & Settings
        </a>
        <a href="<?= APP_URL ?>/api/auth.php?action=logout" style="display:flex;align-items:center;gap:9px;padding:10px 16px;color:#ef4444;font-size:.85rem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Logout
        </a>
      </div>
    </div>
  </div>
</header>

<script>
// Sidebar toggle
document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('sidebar')?.classList.toggle('open');
});

// Notifications dropdown
document.getElementById('notifBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  const d = document.getElementById('notifDropdown');
  const a = document.getElementById('avatarDropdown');
  if (a) a.style.display = 'none';
  if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none';
});

// Avatar dropdown
document.getElementById('avatarBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  const d = document.getElementById('avatarDropdown');
  const n = document.getElementById('notifDropdown');
  if (n) n.style.display = 'none';
  if (d) d.style.display = d.style.display === 'none' ? 'block' : 'none';
});

// Close dropdowns on outside click
document.addEventListener('click', () => {
  const d = document.getElementById('notifDropdown');
  const a = document.getElementById('avatarDropdown');
  if (d) d.style.display = 'none';
  if (a) a.style.display = 'none';
});
</script>