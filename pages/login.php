<?php require_once __DIR__ . '/../config/app.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Sign In — Inventory Pro</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/global.css"/>
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f1f5f9;padding:24px 16px;margin:0}
.auth-card{background:#fff;border-radius:20px;padding:40px 36px 32px;box-shadow:0 8px 40px rgba(0,0,0,.10);width:100%;max-width:400px}
.auth-logo{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;transition:background .25s}
.auth-logo.staff{background:#3B5BDB}
.auth-logo.admin{background:linear-gradient(135deg,#7c3aed,#db2777)}
.auth-heading{text-align:center;margin-bottom:22px}
.auth-heading h1{font-size:1.6rem;font-weight:700;color:#0f172a;letter-spacing:-.3px}
.auth-heading p{font-size:.9rem;color:#64748b;margin-top:4px}
.role-tabs{display:flex;background:#f1f5f9;border-radius:10px;padding:4px;margin-bottom:20px;gap:4px}
.role-tab{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:8px;border-radius:7px;font-size:.83rem;font-weight:600;cursor:pointer;border:none;background:none;color:#64748b;transition:all .2s}
.role-tab.active-staff{background:#fff;color:#3B5BDB;box-shadow:0 1px 4px rgba(0,0,0,.1)}
.role-tab.active-admin{background:#fff;color:#7c3aed;box-shadow:0 1px 4px rgba(0,0,0,.1)}
.error-banner{display:none;align-items:center;gap:8px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#b91c1c;font-size:.85rem;margin-bottom:14px}
.form-stack{display:flex;flex-direction:column;gap:15px}
.field-label{font-size:.8rem;font-weight:600;color:#475569;margin-bottom:5px;display:block}
.input-wrap{position:relative;display:flex;align-items:center}
.input-wrap .i-icon{position:absolute;left:12px;color:#94a3b8;pointer-events:none;display:flex}
.f-input{width:100%;padding:10px 12px 10px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;background:#fff;font-family:inherit}
.f-input:focus{border-color:#3B5BDB;box-shadow:0 0 0 3px rgba(59,91,219,.1)}
.f-input.admin-mode:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.f-input::placeholder{color:#94a3b8}
.eye-btn{position:absolute;right:12px;background:none;border:none;cursor:pointer;color:#94a3b8;padding:2px;display:flex;align-items:center}
.btn-signin{width:100%;padding:12px;border:none;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;font-family:inherit}
.btn-signin.staff-mode{background:#3B5BDB;color:#fff}
.btn-signin.staff-mode:hover{background:#2f4ec4;box-shadow:0 4px 14px rgba(59,91,219,.35)}
.btn-signin.admin-mode{background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff}
.btn-signin.admin-mode:hover{box-shadow:0 4px 14px rgba(124,58,237,.35)}
.btn-signin:disabled{opacity:.6;cursor:not-allowed}
.auth-hint{text-align:center;font-size:.82rem;color:#94a3b8;margin-top:4px}
@keyframes spin{to{transform:rotate(360deg)}}
.mini-spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-logo staff" id="authLogo">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" id="logoIcon">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
    </svg>
  </div>
  <div class="auth-heading">
    <h1>Welcome Back!</h1>
    <p>Sign in to continue to Inventory Pro</p>
  </div>

  <!-- Role tabs -->
  <div class="role-tabs">
    <button class="role-tab active-staff" id="tabStaff">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Staff / Supervisor
    </button>
    <button class="role-tab" id="tabAdmin">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Admin
    </button>
  </div>

  <!-- Error banner -->
  <div class="error-banner" id="errBanner">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span id="errMsg"></span>
  </div>

  <div class="form-stack">
    <div>
      <label class="field-label">Email</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
        <input id="email" type="email" class="f-input" placeholder="you@example.com" autocomplete="email" autofocus/>
      </div>
    </div>
    <div>
      <label class="field-label">Password</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
        <input id="password" type="password" class="f-input" placeholder="••••••••" autocomplete="current-password"/>
        <button class="eye-btn" id="eyeBtn" type="button">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <button class="btn-signin staff-mode" id="loginBtn" type="button">Sign In →</button>
    <p class="auth-hint">Contact your administrator if you need an account.</p>
  </div>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
let role = 'staff';
const logo    = document.getElementById('authLogo');
const tabS    = document.getElementById('tabStaff');
const tabA    = document.getElementById('tabAdmin');
const loginBtn= document.getElementById('loginBtn');
const emailEl = document.getElementById('email');
const passEl  = document.getElementById('password');
const errBanner = document.getElementById('errBanner');
const errMsg    = document.getElementById('errMsg');

function setRole(r) {
  role = r;
  tabS.className = 'role-tab' + (r === 'staff' ? ' active-staff' : '');
  tabA.className = 'role-tab' + (r === 'admin' ? ' active-admin' : '');
  logo.className = 'auth-logo ' + (r === 'admin' ? 'admin' : 'staff');
  document.getElementById('logoIcon').innerHTML = r === 'admin'
    ? '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'
    : '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>';
  loginBtn.className = 'btn-signin ' + (r === 'admin' ? 'admin-mode' : 'staff-mode');
  emailEl.className  = 'f-input' + (r === 'admin' ? ' admin-mode' : '');
  passEl.className   = 'f-input' + (r === 'admin' ? ' admin-mode' : '');
  hideErr();
}

tabS.onclick = () => setRole('staff');
tabA.onclick = () => setRole('admin');

document.getElementById('eyeBtn').onclick = () => {
  passEl.type = passEl.type === 'text' ? 'password' : 'text';
};

[emailEl, passEl].forEach(el => el.addEventListener('keydown', e => {
  if (e.key === 'Enter') doLogin();
}));
loginBtn.onclick = doLogin;

function showErr(m) { errMsg.textContent = m; errBanner.style.display = 'flex'; }
function hideErr()  { errBanner.style.display = 'none'; }

async function doLogin() {
  const email    = emailEl.value.trim();
  const password = passEl.value;
  if (!email || !password) { showErr('Please enter email and password.'); return; }

  loginBtn.disabled = true;
  loginBtn.innerHTML = '<span class="mini-spin"></span>';
  hideErr();

  try {
    const res  = await fetch(`${APP_URL}/api/auth.php?action=login`, {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({email, password})
    });
    const data = await res.json();

    if (data.success) {
      // Tab mismatch checks
      if (role === 'admin' && data.role !== 'admin') {
        showErr('These credentials do not have admin access.');
        loginBtn.disabled = false; loginBtn.innerHTML = 'Sign In →'; return;
      }
      if (role === 'staff' && data.role === 'admin') {
        showErr('Admin accounts must use the Admin tab.');
        loginBtn.disabled = false; loginBtn.innerHTML = 'Sign In →'; return;
      }
      window.location.href = data.redirect;
    } else {
      showErr(data.message || 'Invalid credentials.');
      loginBtn.disabled = false; loginBtn.innerHTML = 'Sign In →';
    }
  } catch (e) {
    showErr('Network error. Please try again.');
    loginBtn.disabled = false; loginBtn.innerHTML = 'Sign In →';
  }
}
</script>
</body>
</html>