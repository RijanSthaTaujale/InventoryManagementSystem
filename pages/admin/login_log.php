<?php
// pages/admin/login_log.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'users';
$pageTitle  = 'Login Log';

$userFilter = (int)($_GET['user_id'] ?? 0);
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to']   ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;

$where  = ['1=1'];
$params = [];
if ($userFilter)  { $where[] = 's.user_id = ?'; $params[] = $userFilter; }
if ($dateFrom)    { $where[] = 'DATE(s.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo)      { $where[] = 'DATE(s.created_at) <= ?'; $params[] = $dateTo; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_sessions s $whereSQL");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.email
    FROM user_sessions s
    JOIN users u ON u.id = s.user_id
    $whereSQL
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$allUsers = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

// Formats a whole number of seconds as e.g. "2h 15m", "45m", "<1m".
function formatDuration(int $seconds): string {
    if ($seconds < 60) return '<1m';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

$baseUrl = APP_URL . '/pages/admin/login_log.php?' . http_build_query(array_filter([
    'user_id' => $userFilter ?: '', 'date_from' => $dateFrom, 'date_to' => $dateTo
]));

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <a href="<?= APP_URL ?>/pages/admin/users.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Users
        </a>
        <span style="color:var(--text-muted);font-size:.82rem">/</span>
        <span style="font-size:.82rem">Login Log</span>
      </div>
      <div class="flex-between mb-4">
        <div>
          <h1 style="font-size:1.25rem;font-weight:700">Login Log</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">
            <?= number_format($total) ?> session<?= $total!=1?'s':'' ?> recorded — this tracks online/offline activity, separate from a user's account Status on the Users page
          </p>
        </div>
        <button class="btn btn-outline btn-sm" onclick="clearLog()">Clear Log</button>
      </div>

      <!-- Filters -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
        <select name="user_id" class="form-control" style="width:auto">
          <option value="">All Users</option>
          <?php foreach ($allUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="form-control" style="width:auto" title="From date">
        <input type="date" name="date_to"   value="<?= e($dateTo)   ?>" class="form-control" style="width:auto" title="To date">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($userFilter || $dateFrom || $dateTo): ?>
        <a href="<?= APP_URL ?>/pages/admin/login_log.php" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </form>

      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Login At</th>
              <th>Logged Out At</th>
              <th>Duration</th>
              <th>IP</th>
              <th style="width:70px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sessions)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">No sessions recorded yet</td></tr>
            <?php endif; ?>
            <?php foreach ($sessions as $s):
              $loginTs    = strtotime($s['created_at']);
              $isStillOn  = $s['logout_at'] === null && strtotime($s['last_activity_at']) >= time() - 60;
              $isAbandoned = $s['logout_at'] === null && !$isStillOn;
            ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:.85rem"><?= e($s['name']) ?></div>
                <div style="font-size:.74rem;color:var(--text-muted)"><?= e($s['email']) ?></div>
              </td>
              <td style="font-size:.82rem"><?= date('d M Y, h:i A', $loginTs) ?></td>
              <td style="font-size:.82rem">
                <?php if ($s['logout_at']): ?>
                  <?= date('d M Y, h:i A', strtotime($s['logout_at'])) ?>
                <?php elseif ($isStillOn): ?>
                  <span style="color:#16a34a;font-weight:700">&#9679; Online now</span>
                <?php else: ?>
                  <span style="color:var(--text-muted)">— (not logged out)</span>
                <?php endif; ?>
              </td>
              <td style="font-size:.82rem;font-weight:600">
                <?php if ($s['logout_at']): ?>
                  <?= formatDuration(strtotime($s['logout_at']) - $loginTs) ?>
                <?php elseif ($isStillOn): ?>
                  <?= formatDuration(time() - $loginTs) ?> so far
                <?php else: ?>
                  <?= formatDuration(strtotime($s['last_activity_at']) - $loginTs) ?> <span style="font-weight:400;color:var(--text-muted)">(last seen)</span>
                <?php endif; ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-muted)"><?= e($s['ip'] ?? '—') ?></td>
              <td>
                <button class="btn btn-outline btn-xs" style="color:#ef4444;border-color:#fca5a5" onclick="deleteLogEntry(<?= (int)$s['id'] ?>)" title="Delete this entry">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
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

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL = '<?= APP_URL ?>';

async function deleteLogEntry(id) {
  if (!confirm('Delete this log entry?')) return;
  const r = await fetch(`${APP_URL}/api/admin.php?action=delete_login_log`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ id })
  });
  const d = await r.json();
  if (d.success) { showToast('Entry deleted', 'success'); setTimeout(() => location.reload(), 500); }
  else showToast(d.message || 'Failed', 'error');
}

async function clearLog() {
  if (!confirm('Clear the login log? Sessions that are currently online are kept so their status keeps showing correctly.')) return;
  const r = await fetch(`${APP_URL}/api/admin.php?action=clear_login_log`, {
    method: 'POST', headers: {'Content-Type':'application/json'}
  });
  const d = await r.json();
  if (d.success) { showToast(`Cleared ${d.deleted} entr${d.deleted!=1?'ies':'y'}`, 'success'); setTimeout(() => location.reload(), 500); }
  else showToast(d.message || 'Failed', 'error');
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>
