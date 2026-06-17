
<script>
function showToast(msg, type = 'info') {
  const container = document.getElementById('toastContainer');
  if (!container) return;
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  const icons = {
    success: '<polyline points="20 6 9 17 4 12"/>',
    error:   '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
    info:    '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'
  };
  t.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icons[type]||icons.info}</svg><span>${msg}</span>`;
  container.appendChild(t);
  setTimeout(() => { t.classList.add('hiding'); setTimeout(() => t.remove(), 250); }, 3200);
}
</script>
</body>
</html>
