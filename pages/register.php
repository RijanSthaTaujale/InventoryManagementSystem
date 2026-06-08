<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Create Account — Inventory Pro</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="/InventoryManagement/assets/css/global.css"/>
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f1f5f9;padding:24px 16px;margin:0}
.auth-card{background:#fff;border-radius:20px;padding:40px 36px 32px;box-shadow:0 8px 40px rgba(0,0,0,.10);width:100%;max-width:420px}
.auth-heading{text-align:center;margin-bottom:24px}
.auth-heading h1{font-size:1.5rem;font-weight:700;color:#0f172a;letter-spacing:-.3px}
.auth-heading p{font-size:.88rem;color:#64748b;margin-top:4px}
.error-banner{display:none;align-items:center;gap:8px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#b91c1c;font-size:.85rem;margin-bottom:14px}
.form-stack{display:flex;flex-direction:column;gap:14px}
.field-label{font-size:.8rem;font-weight:600;color:#475569;margin-bottom:5px;display:block}
.input-wrap{position:relative;display:flex;align-items:center}
.input-wrap .i-icon{position:absolute;left:12px;color:#94a3b8;pointer-events:none;display:flex}
.f-input{width:100%;padding:10px 12px 10px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;background:#fff;font-family:inherit}
.f-input:focus{border-color:#3B5BDB;box-shadow:0 0 0 3px rgba(59,91,219,.1)}
.f-input::placeholder{color:#94a3b8}
.eye-btn{position:absolute;right:12px;background:none;border:none;cursor:pointer;color:#94a3b8;padding:2px;display:flex;align-items:center}
.terms-row{display:flex;align-items:flex-start;gap:8px;font-size:.83rem;color:#64748b}
.terms-row input[type=checkbox]{width:15px;height:15px;margin-top:1px;cursor:pointer;accent-color:#3B5BDB}
.terms-row a{color:#3B5BDB;font-weight:500}
.btn-create{width:100%;padding:12px;border:none;border-radius:10px;font-size:.95rem;font-weight:600;cursor:pointer;background:#0f172a;color:#fff;transition:all .2s;font-family:inherit}
.btn-create:hover{background:#1e293b}
.btn-create:disabled{opacity:.6;cursor:not-allowed}
.auth-switch{text-align:center;font-size:.88rem;color:#64748b;margin-top:2px}
.auth-switch a{font-weight:600;color:#3B5BDB}
@keyframes spin{to{transform:rotate(360deg)}}
.mini-spin{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-heading"><h1>Create Account</h1><p>Sign up to start shopping</p></div>

  <div class="error-banner" id="errBanner">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <span id="errMsg"></span>
  </div>

  <div class="form-stack">
    <div>
      <label class="field-label">Full Name</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <input id="name" type="text" class="f-input" placeholder="John Doe" autocomplete="name" autofocus/>
      </div>
    </div>
    <div>
      <label class="field-label">Email</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
        <input id="email" type="email" class="f-input" placeholder="john@example.com" autocomplete="email"/>
      </div>
    </div>
    <div>
      <label class="field-label">Password</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
        <input id="password" type="password" class="f-input" placeholder="••••••••" autocomplete="new-password"/>
        <button class="eye-btn" id="eyeBtn" type="button">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
    <div>
      <label class="field-label">Confirm Password</label>
      <div class="input-wrap">
        <span class="i-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
        <input id="confirm" type="password" class="f-input" placeholder="••••••••" autocomplete="new-password"/>
      </div>
    </div>
    <label class="terms-row">
      <input type="checkbox" id="termsCheck"/>
      I agree to the <a href="#">Terms of Service and Privacy Policy</a>
    </label>
    <button class="btn-create" id="registerBtn" type="button">Create Account</button>
    <p class="auth-switch">Already have an account? <a href="/InventoryManagement/pages/login.php">Sign in</a></p>
  </div>
</div>
<script>
const nameEl=document.getElementById('name'),emailEl=document.getElementById('email');
const passEl=document.getElementById('password'),confirmEl=document.getElementById('confirm');
const termsEl=document.getElementById('termsCheck'),regBtn=document.getElementById('registerBtn');
const errBanner=document.getElementById('errBanner'),errMsg=document.getElementById('errMsg');

document.getElementById('eyeBtn').onclick=()=>{passEl.type=passEl.type==='text'?'password':'text';};
regBtn.onclick=doRegister;
[nameEl,emailEl,passEl,confirmEl].forEach(el=>el.addEventListener('keydown',e=>{if(e.key==='Enter')doRegister();}));

function showErr(m){errMsg.textContent=m;errBanner.style.display='flex';}
function hideErr(){errBanner.style.display='none';}

async function doRegister(){
  const name=nameEl.value.trim(),email=emailEl.value.trim(),password=passEl.value,confirm=confirmEl.value;
  if(!name||!email||!password){showErr('All fields are required.');return;}
  if(password!==confirm){showErr('Passwords do not match.');return;}
  if(password.length<6){showErr('Password must be at least 6 characters.');return;}
  if(!termsEl.checked){showErr('Please agree to the Terms of Service.');return;}
  regBtn.disabled=true;regBtn.innerHTML='<span class="mini-spin"></span>';
  hideErr();
  try{
    const res=await fetch('/InventoryManagement/api/auth.php?action=register',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email,password,confirm_password:confirm})});
    const data=await res.json();
    if(data.success){window.location.href=data.redirect;}
    else{showErr(data.message||'Registration failed.');regBtn.disabled=false;regBtn.innerHTML='Create Account';}
  }catch(e){showErr('Network error.');regBtn.disabled=false;regBtn.innerHTML='Create Account';}
}
</script>
</body>
</html>