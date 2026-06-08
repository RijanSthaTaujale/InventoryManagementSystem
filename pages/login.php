<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Sign In — Inventory Pro</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/InventoryManagement/assets/css/global.css"/>
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
.forgot-row{text-align:right;margin-top:-6px}
.forgot-link{font-size:.82rem;font-weight:500;color:#3B5BDB}
.forgot-link.admin-cl{color:#7c3aed}
.btn-signin{width:100%;padding:12px;border:none;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;font-family:inherit}
.btn-signin.staff-mode{background:#3B5BDB;color:#fff}
.btn-signin.staff-mode:hover{background:#2f4ec4;box-shadow:0 4px 14px rgba(59,91,219,.35)}
.btn-signin.admin-mode{background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff}
.btn-signin.admin-mode:hover{box-shadow:0 4px 14px rgba(124,58,237,.35)}
.btn-signin:disabled{opacity:.6;cursor:not-allowed}
.auth-switch{text-align:center;font-size:.88rem;color:#64748b;margin-top:2px}
.auth-switch a{font-weight:600;color:#3B5BDB}
.divider{display:flex;align-items:center;gap:10px;color:#94a3b8;font-size:.8rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e2e8f0}
.social-row{display:flex;gap:10px}
.social-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;background:#fff;font-size:.84rem;font-weight:500;color:#0f172a;cursor:pointer;transition:border-color .15s;font-family:inherit}
.social-btn:hover{border-color:#94a3b8}
@keyframes spin{to{transform:rotate(360deg)}}
.mini-spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-logo staff" id="authLogo">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" id="logoIcon"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
  </div>
  <div class="auth-heading"><h1>Welcome Back!</h1><p>Sign in to continue</p></div>

  <div class="role-tabs">
    <button class="role-tab active-staff" id="tabStaff">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Staff
    </button>
    <button class="role-tab" id="tabAdmin">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Admin
    </button>
  </div>

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
    <div class="forgot-row"><a href="/InventoryManagement/pages/forgot-password.php" class="forgot-link" id="forgotLink">Forgot password?</a></div>
    <button class="btn-signin staff-mode" id="loginBtn" type="button">Sign In →</button>
    <p class="auth-switch">Don't have an account? <a href="/InventoryManagement/pages/register.php">Sign up</a></p>
    <div class="divider">Or continue with</div>
    <div class="social-row">
      <button class="social-btn" id="googleBtn" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        Google
      </button>
      <button class="social-btn" id="githubBtn" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
        Github
      </button>
    </div>
  </div>
</div>
<script>
let role='staff';
const logo=document.getElementById('authLogo'),tabS=document.getElementById('tabStaff'),tabA=document.getElementById('tabAdmin');
const loginBtn=document.getElementById('loginBtn'),forgotLink=document.getElementById('forgotLink');
const emailEl=document.getElementById('email'),passEl=document.getElementById('password');
const errBanner=document.getElementById('errBanner'),errMsg=document.getElementById('errMsg');

function setRole(r){
  role=r;
  tabS.className='role-tab'+(r==='staff'?' active-staff':'');
  tabA.className='role-tab'+(r==='admin'?' active-admin':'');
  logo.className='auth-logo '+(r==='admin'?'admin':'staff');
  document.getElementById('logoIcon').innerHTML=r==='admin'
    ?'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'
    :'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>';
  loginBtn.className='btn-signin '+(r==='admin'?'admin-mode':'staff-mode');
  emailEl.className='f-input'+(r==='admin'?' admin-mode':'');
  passEl.className='f-input'+(r==='admin'?' admin-mode':'');
  forgotLink.className='forgot-link'+(r==='admin'?' admin-cl':'');
  hideErr();
}
tabS.onclick=()=>setRole('staff');
tabA.onclick=()=>setRole('admin');

document.getElementById('eyeBtn').onclick=()=>{
  passEl.type=passEl.type==='text'?'password':'text';
};
[emailEl,passEl].forEach(el=>el.addEventListener('keydown',e=>{if(e.key==='Enter')doLogin();}));
loginBtn.onclick=doLogin;

function showErr(m){errMsg.textContent=m;errBanner.style.display='flex';}
function hideErr(){errBanner.style.display='none';}

async function doLogin(){
  const email=emailEl.value.trim(),password=passEl.value;
  if(!email||!password){showErr('Please enter email and password.');return;}
  loginBtn.disabled=true;loginBtn.innerHTML='<span class="mini-spin"></span>';
  hideErr();
  try{
    const res=await fetch('/InventoryManagement/api/auth.php?action=login',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password})});
    const data=await res.json();
    if(data.success){
      if(role==='admin'&&data.role!=='admin'){showErr('These credentials do not have admin access.');loginBtn.disabled=false;loginBtn.innerHTML='Sign In →';return;}
      if(role==='staff'&&data.role==='admin'){showErr('Admin must use the Admin tab.');loginBtn.disabled=false;loginBtn.innerHTML='Sign In →';return;}
      window.location.href=data.redirect;
    }else{showErr(data.message||'Invalid credentials.');loginBtn.disabled=false;loginBtn.innerHTML='Sign In →';}
  }catch(e){showErr('Network error.');loginBtn.disabled=false;loginBtn.innerHTML='Sign In →';}
}
document.getElementById('googleBtn').onclick=()=>alert('Google OAuth coming soon.');
document.getElementById('githubBtn').onclick=()=>alert('GitHub OAuth coming soon.');
</script>
</body>
</html>

