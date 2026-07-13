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

  <!-- Search -->
  <div class="topbar-search" style="position:relative">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input type="text" placeholder="Search anything..." id="globalSearch" autocomplete="off">

    <!-- Search results dropdown -->
    <div id="globalSearchDropdown" style="display:none;position:absolute;top:calc(100% + 6px);left:0;width:360px;max-height:400px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);z-index:600"></div>
  </div>

  <!-- Actions -->
  <div class="topbar-actions">
    <!-- Chat assistant -->
    <button class="topbar-icon-btn" title="Chat Assistant" id="chatBtn">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
    </button>

    <!-- Chat panel (hidden by default) — floats fixed to the bottom-right of the viewport -->
    <div id="chatPanel" style="display:none;position:fixed;bottom:24px;right:24px;width:360px;height:480px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);z-index:8000;overflow:hidden;flex-direction:column">
      <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
        <span style="font-weight:700;font-size:.9rem">Chat Assistant</span>
        <button onclick="document.getElementById('chatPanel').style.display='none'" style="background:none;border:none;cursor:pointer;color:var(--text-muted);display:flex">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div id="chatMessages" style="flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px">
        <div class="chat-bubble chat-bubble-bot">Hi! How can I help you?</div>
      </div>
      <div style="padding:10px;border-top:1px solid var(--border);display:flex;gap:8px;flex-shrink:0">
        <input type="text" id="chatInput" class="form-control" placeholder="Type a message..." style="font-size:.84rem" onkeydown="if(event.key==='Enter')sendChatMessage()">
        <button onclick="sendChatMessage()" class="btn btn-primary btn-sm" id="chatSendBtn">Send</button>
      </div>
    </div>

    <style>
      .chat-bubble { max-width: 85%; padding: 8px 12px; border-radius: var(--radius-md); font-size: .84rem; overflow-wrap: break-word; word-break: break-word; line-height: 1.45; }
      .chat-bubble-user { align-self: flex-end; background: var(--primary); color: #fff; }
      .chat-bubble-bot { align-self: flex-start; background: var(--bg); color: var(--text-secondary); }
      .chat-bubble p { margin: 4px 0; }
      .chat-bubble p:first-child { margin-top: 0; }
      .chat-bubble p:last-child { margin-bottom: 0; }
      .chat-bubble ul { margin: 4px 0; padding-left: 18px; }
      .chat-bubble li { margin: 2px 0; }
      .chat-bubble strong { font-weight: 700; }
      @media (max-width: 480px) {
        #chatPanel { width: calc(100vw - 24px) !important; right: 12px !important; bottom: 12px !important; height: 70vh !important; }
      }
    </style>

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

// Chat panel
document.getElementById('chatBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  const c = document.getElementById('chatPanel');
  const n = document.getElementById('notifDropdown');
  const a = document.getElementById('avatarDropdown');
  if (n) n.style.display = 'none';
  if (a) a.style.display = 'none';
  if (c) {
    c.style.display = c.style.display === 'none' ? 'flex' : 'none';
    if (c.style.display === 'flex') document.getElementById('chatInput')?.focus();
  }
});
document.getElementById('chatPanel')?.addEventListener('click', e => e.stopPropagation());

// Close dropdowns on outside click
document.addEventListener('click', () => {
  const d = document.getElementById('notifDropdown');
  const a = document.getElementById('avatarDropdown');
  const c = document.getElementById('chatPanel');
  const g = document.getElementById('globalSearchDropdown');
  if (d) d.style.display = 'none';
  if (a) a.style.display = 'none';
  if (c) c.style.display = 'none';
  if (g) g.style.display = 'none';
});

// ── Chat assistant ───────────────────────────────────────────
const CHAT_APP_URL = '<?= APP_URL ?>';

function chatSessionId() {
  let id = localStorage.getItem('chatSessionId');
  if (!id) {
    id = 'sess-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    localStorage.setItem('chatSessionId', id);
  }
  return id;
}

function appendChatMessage(text, from) {
  const wrap = document.getElementById('chatMessages');
  const bubble = document.createElement('div');
  bubble.className = 'chat-bubble ' + (from === 'user' ? 'chat-bubble-user' : 'chat-bubble-bot');
  bubble.textContent = text;
  wrap.appendChild(bubble);
  wrap.scrollTop = wrap.scrollHeight;
  return bubble;
}

// Minimal, safe markdown-ish renderer for bot replies: escapes HTML first
// (so no injected markup can execute), then adds back **bold**, bullet
// lists, and paragraph breaks as real elements so long structured answers
// (like AI-generated product detail lists) render legibly instead of
// showing raw asterisks.
function renderChatMarkdown(text) {
  const escaped = document.createElement('div');
  escaped.textContent = text;
  const lines = escaped.innerHTML.split('\n');

  let html = '';
  let inList = false;
  for (const line of lines) {
    const listMatch = line.match(/^\s*[*-]\s+(.*)/);
    if (listMatch) {
      if (!inList) { html += '<ul>'; inList = true; }
      html += `<li>${listMatch[1]}</li>`;
      continue;
    }
    if (inList) { html += '</ul>'; inList = false; }
    if (line.trim() !== '') html += `<p>${line}</p>`;
  }
  if (inList) html += '</ul>';

  return html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

async function sendChatMessage() {
  const input = document.getElementById('chatInput');
  const msg = input.value.trim();
  if (!msg) return;
  input.value = '';
  appendChatMessage(msg, 'user');

  const btn = document.getElementById('chatSendBtn');
  btn.disabled = true;
  const thinking = appendChatMessage('...', 'bot');

  try {
    const r = await fetch(`${CHAT_APP_URL}/api/chat.php`, {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ message: msg, session_id: chatSessionId() })
    });
    const d = await r.json();
    if (d.success) {
      thinking.innerHTML = renderChatMarkdown(d.reply);
    } else {
      thinking.textContent = d.message || 'Something went wrong.';
    }
  } catch (e) {
    thinking.textContent = 'Network error. Please try again.';
  } finally {
    btn.disabled = false;
    document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
  }
}

// ── Global search ────────────────────────────────────────────
const gSearchInput    = document.getElementById('globalSearch');
const gSearchDropdown = document.getElementById('globalSearchDropdown');
let gSearchTimer;

gSearchInput?.addEventListener('input', () => {
  clearTimeout(gSearchTimer);
  const q = gSearchInput.value.trim();
  if (q.length < 2) { gSearchDropdown.style.display = 'none'; return; }
  gSearchTimer = setTimeout(() => runGlobalSearch(q), 280);
});
gSearchInput?.addEventListener('focus', () => {
  if (gSearchInput.value.trim().length >= 2) gSearchDropdown.style.display = 'block';
});
gSearchDropdown?.addEventListener('click', e => e.stopPropagation());

async function runGlobalSearch(q) {
  gSearchDropdown.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:.83rem">Searching...</div>';
  gSearchDropdown.style.display = 'block';

  const [prodRes, orderRes] = await Promise.all([
    fetch(`${CHAT_APP_URL}/api/products.php?action=search&q=${encodeURIComponent(q)}`).then(r => r.json()).catch(() => ({products: []})),
    fetch(`${CHAT_APP_URL}/api/orders.php?action=search&q=${encodeURIComponent(q)}`).then(r => r.json()).catch(() => ({orders: []})),
  ]);
  const products = (prodRes.products || []).slice(0, 5);
  const orders   = (orderRes.orders   || []).slice(0, 5);

  if (!products.length && !orders.length) {
    gSearchDropdown.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:.83rem">No results found</div>';
    return;
  }

  let html = '';
  if (products.length) {
    html += '<div style="padding:8px 14px 4px;font-size:.7rem;font-weight:700;letter-spacing:.05em;color:var(--text-muted);text-transform:uppercase">Products</div>';
    html += products.map(p => `
      <a href="${CHAT_APP_URL}/pages/products/view.php?id=${p.id}" style="display:flex;align-items:center;gap:10px;padding:8px 14px;text-decoration:none;color:var(--text);border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:0">
          <div style="font-size:.84rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${p.name}</div>
          <div style="font-size:.72rem;color:var(--text-muted)">${p.product_id} · Stock: ${p.quantity}</div>
        </div>
      </a>`).join('');
  }
  if (orders.length) {
    html += '<div style="padding:8px 14px 4px;font-size:.7rem;font-weight:700;letter-spacing:.05em;color:var(--text-muted);text-transform:uppercase">Orders</div>';
    html += orders.map(o => `
      <a href="${CHAT_APP_URL}/pages/orders/view.php?id=${encodeURIComponent(o.order_id)}" style="display:flex;align-items:center;gap:10px;padding:8px 14px;text-decoration:none;color:var(--text);border-bottom:1px solid var(--border)">
        <div style="flex:1;min-width:0">
          <div style="font-size:.84rem;font-weight:600">${o.order_id}</div>
          <div style="font-size:.72rem;color:var(--text-muted)">${o.customer_name} · ${o.status.replace('_',' ')}</div>
        </div>
      </a>`).join('');
  }
  gSearchDropdown.innerHTML = html;
}
</script>