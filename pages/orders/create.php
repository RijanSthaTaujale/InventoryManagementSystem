<?php
// pages/orders/create.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'order';
$pageTitle  = 'New Order';
$user       = currentUser();
$currency   = 'Rs';

// Generate next order ID preview
$last    = $pdo->query("SELECT order_id FROM orders ORDER BY id DESC LIMIT 1")->fetchColumn();
$num     = $last ? (int)substr($last, strrpos($last,'-')+1) + 1 : 1;
$nextId  = 'ORD-' . date('Y') . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);

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
            <a href="<?= APP_URL ?>/pages/orders/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Orders
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem">New Order</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Add New Order</h1>
        </div>
        <div style="display:flex;gap:8px">
          <a href="<?= APP_URL ?>/pages/orders/index.php" class="btn btn-outline btn-sm">Cancel</a>
          <button class="btn btn-primary" onclick="submitOrder()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>
            Place Order
          </button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

        <!-- LEFT: Order Items -->
        <div style="display:flex;flex-direction:column;gap:16px">

          <!-- Product search -->
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Order Items</div>

            <!-- Search bar -->
            <div style="position:relative;margin-bottom:12px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="productSearch" class="form-control" style="padding-left:34px" placeholder="Search product by name, ID or SKU..." autocomplete="off">
              <!-- Dropdown -->
              <div id="searchDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-md);z-index:200;max-height:260px;overflow-y:auto;margin-top:4px"></div>
            </div>

            <!-- Items table -->
            <div id="itemsWrap">
              <table class="data-table" id="itemsTable">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th style="width:80px">Qty</th>
                    <th style="width:120px">Price (Rs)</th>
                    <th style="width:110px">Total</th>
                    <th style="width:36px"></th>
                  </tr>
                </thead>
                <tbody id="itemsBody">
                  <tr id="emptyRow">
                    <td colspan="5" style="text-align:center;color:var(--text-muted);padding:28px;font-size:.85rem">Search and add products above</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Discount -->
            <div style="display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
              <label style="font-size:.82rem;font-weight:600;color:var(--text-secondary);white-space:nowrap">Discount:</label>
              <input type="number" id="discountAmt" min="0" step="0.01" value="0" class="form-control" style="max-width:110px" oninput="recalc()">
              <select id="discountType" class="form-control" style="max-width:100px" onchange="recalc()">
                <option value="fixed">Rs (Fixed)</option>
                <option value="percent">% (Percent)</option>
              </select>
            </div>
          </div>

          <!-- Customer & Shipping -->
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Customer & Shipping Details</div>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="grid-2" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">Customer Name *</label>
                  <input type="text" id="custName" class="form-control" placeholder="Full name" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Phone Number *</label>
                  <input type="text" id="custPhone" class="form-control" placeholder="98XXXXXXXX">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" id="custEmail" class="form-control" placeholder="customer@example.com">
              </div>
              <div class="form-group">
                <label class="form-label">Delivery Address *</label>
                <textarea id="custAddress" class="form-control" rows="2" placeholder="Street, City, District"></textarea>
              </div>
              <div class="grid-2" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">Shipping Method</label>
                  <select id="shippingMethod" class="form-control" onchange="recalc()">
                    <option value="">No shipping</option>
                    <option value="Standard Shipping (Rs 100)" data-cost="100">Standard — Rs 100</option>
                    <option value="Express Shipping (Rs 250)" data-cost="250">Express — Rs 250</option>
                    <option value="Regional Logistics" data-cost="150">Regional — Rs 150</option>
                    <option value="Pickup" data-cost="0">Pickup — Free</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Payment Method</label>
                  <select id="paymentMethod" class="form-control">
                    <option value="Cash on Delivery">Cash on Delivery</option>
                    <option value="eSewa">eSewa</option>
                    <option value="Khalti">Khalti</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Card">Card</option>
                  </select>
                </div>
              </div>
              <div class="grid-2" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">Payment Status</label>
                  <select id="paymentStatus" class="form-control">
                    <option value="unpaid">Unpaid</option>
                    <option value="paid">Paid</option>
                    <option value="partial">Partial</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Order Status</label>
                  <select id="orderStatus" class="form-control">
                    <option value="new">New</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="pending">Pending</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Order Information -->
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Order Information</div>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group">
                <label class="form-label">Remarks / Staff Notes</label>
                <textarea id="remarks" class="form-control" rows="3" placeholder="Internal notes about this order..."></textarea>
              </div>
            </div>
          </div>

        </div>

        <!-- RIGHT: Summary -->
        <div style="position:sticky;top:calc(var(--topbar-h) + 24px);display:flex;flex-direction:column;gap:14px">

          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Order Summary</div>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:.88rem">
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Order ID</span>
                <span style="font-weight:600;color:var(--text)"><?= $nextId ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Items</span>
                <span id="sumItems" style="font-weight:600;color:var(--text)">0</span>
              </div>
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Subtotal</span>
                <span id="sumSubtotal" style="font-weight:600;color:var(--text)"><?= $currency ?> 0</span>
              </div>
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Discount</span>
                <span id="sumDiscount" style="font-weight:600;color:#ef4444">- <?= $currency ?> 0</span>
              </div>
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Shipping</span>
                <span id="sumShipping" style="font-weight:600;color:var(--text)"><?= $currency ?> 0</span>
              </div>
              <div style="height:1px;background:var(--border);margin:4px 0"></div>
              <div style="display:flex;justify-content:space-between">
                <span style="font-weight:700;font-size:1rem">Total</span>
                <span id="sumTotal" style="font-weight:700;font-size:1.1rem;color:var(--primary)"><?= $currency ?> 0</span>
              </div>
            </div>
          </div>

          <!-- Payment wallet display -->
          <div class="card" id="walletCard" style="display:none">
            <div style="font-size:.82rem;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Payment Method</div>
            <div id="walletDisplay" style="display:flex;align-items:center;gap:8px;font-size:.88rem;font-weight:600"></div>
          </div>

          <button class="btn btn-primary" style="width:100%" onclick="submitOrder()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Place Order
          </button>
          <div id="orderError" style="display:none;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;font-size:.83rem"></div>
        </div>

      </div>
    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL  = '<?= APP_URL ?>';
const CURRENCY = '<?= $currency ?>';
let items = [];

// ── Product Search ───────────────────────────────────────────
const searchInput = document.getElementById('productSearch');
const dropdown    = document.getElementById('searchDropdown');
let searchTimer;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (q.length < 2) { dropdown.style.display = 'none'; return; }
  searchTimer = setTimeout(async () => {
    const r = await fetch(`${APP_URL}/api/products.php?action=search&q=${encodeURIComponent(q)}`);
    const d = await r.json();
    if (!d.products?.length) { dropdown.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:.84rem">No products found</div>'; dropdown.style.display='block'; return; }
    dropdown.innerHTML = d.products.map(p => `
      <div onclick="addItem(${JSON.stringify(p).replace(/"/g,'&quot;')})"
           style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
        <div style="width:36px;height:36px;background:var(--bg);border-radius:var(--radius-sm);flex-shrink:0;overflow:hidden">
          ${p.image_url ? `<img src="${p.image_url}" style="width:100%;height:100%;object-fit:cover">` : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg></div>'}
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${p.name}</div>
          <div style="font-size:.74rem;color:var(--text-muted)">${p.product_id} &nbsp;·&nbsp; Stock: ${p.quantity} &nbsp;·&nbsp; ${CURRENCY} ${Number(p.sell_price).toLocaleString()}</div>
        </div>
        ${p.quantity <= 0 ? '<span style="font-size:.7rem;font-weight:700;color:#ef4444">OUT</span>' : ''}
      </div>`).join('');
    dropdown.style.display = 'block';
  }, 280);
});

document.addEventListener('click', e => { if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display='none'; });

function addItem(p) {
  dropdown.style.display = 'none';
  searchInput.value = '';
  // Check if already in list
  const existing = items.find(i => i.id === p.id);
  if (existing) { existing.qty++; renderItems(); recalc(); return; }
  items.push({ id: p.id, name: p.name, product_id: p.product_id, sell_price: parseFloat(p.sell_price), buy_price: parseFloat(p.buy_price), qty: 1, max_qty: p.quantity });
  renderItems();
  recalc();
}

function renderItems() {
  const tbody = document.getElementById('itemsBody');
  if (!items.length) {
    tbody.innerHTML = '<tr id="emptyRow"><td colspan="5" style="text-align:center;color:var(--text-muted);padding:28px;font-size:.85rem">Search and add products above</td></tr>';
    return;
  }
  tbody.innerHTML = items.map((item, i) => `
    <tr>
      <td>
        <div style="font-weight:600;font-size:.85rem">${item.name}</div>
        <div style="font-size:.74rem;color:var(--text-muted)">${item.product_id}</div>
      </td>
      <td>
        <input type="number" value="${item.qty}" min="1" max="${item.max_qty||999}"
               onchange="updateQty(${i}, this.value)"
               style="width:70px;padding:5px 8px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:.84rem;text-align:center">
      </td>
      <td>
        <input type="number" value="${item.sell_price}" min="0" step="0.01"
               onchange="updatePrice(${i}, this.value)"
               style="width:100px;padding:5px 8px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:.84rem">
      </td>
      <td style="font-weight:600;color:var(--primary)">${CURRENCY} ${(item.qty * item.sell_price).toLocaleString()}</td>
      <td>
        <button onclick="removeItem(${i})" style="background:#fee2e2;border:none;color:#ef4444;border-radius:var(--radius-sm);width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </td>
    </tr>`).join('');
}

function updateQty(i, v)   { items[i].qty = Math.max(1, parseInt(v)||1); renderItems(); recalc(); }
function updatePrice(i, v) { items[i].sell_price = Math.max(0, parseFloat(v)||0); renderItems(); recalc(); }
function removeItem(i)     { items.splice(i,1); renderItems(); recalc(); }

function recalc() {
  const subtotal = items.reduce((s, i) => s + i.qty * i.sell_price, 0);
  const discAmt  = parseFloat(document.getElementById('discountAmt').value) || 0;
  const discType = document.getElementById('discountType').value;
  const discount = discType === 'percent' ? subtotal * discAmt / 100 : discAmt;

  const sel      = document.getElementById('shippingMethod');
  const shipping = parseFloat(sel.selectedOptions[0]?.dataset.cost || 0);
  const total    = Math.max(0, subtotal - discount + shipping);

  document.getElementById('sumItems').textContent    = items.reduce((s,i)=>s+i.qty,0);
  document.getElementById('sumSubtotal').textContent = `${CURRENCY} ${subtotal.toLocaleString()}`;
  document.getElementById('sumDiscount').textContent  = `- ${CURRENCY} ${discount.toLocaleString()}`;
  document.getElementById('sumShipping').textContent  = `${CURRENCY} ${shipping.toLocaleString()}`;
  document.getElementById('sumTotal').textContent     = `${CURRENCY} ${total.toLocaleString()}`;
}

// ── Submit ───────────────────────────────────────────────────
async function submitOrder() {
  const errDiv = document.getElementById('orderError');
  errDiv.style.display = 'none';

  const custName    = document.getElementById('custName').value.trim();
  const custPhone   = document.getElementById('custPhone').value.trim();
  const custAddress = document.getElementById('custAddress').value.trim();

  if (!custName)    { errDiv.textContent='Customer name is required.'; errDiv.style.display='block'; return; }
  if (!custPhone)   { errDiv.textContent='Phone number is required.';  errDiv.style.display='block'; return; }
  if (!custAddress) { errDiv.textContent='Delivery address is required.'; errDiv.style.display='block'; return; }
  if (!items.length){ errDiv.textContent='Add at least one product.'; errDiv.style.display='block'; return; }

  const discAmt  = parseFloat(document.getElementById('discountAmt').value) || 0;
  const discType = document.getElementById('discountType').value;
  const subtotal = items.reduce((s,i)=>s+i.qty*i.sell_price,0);
  const discount = discType==='percent' ? subtotal*discAmt/100 : discAmt;
  const sel      = document.getElementById('shippingMethod');
  const shipping = parseFloat(sel.selectedOptions[0]?.dataset.cost||0);
  const total    = Math.max(0, subtotal - discount + shipping);

  const payload = {
    customer_name:    custName,
    customer_phone:   custPhone,
    customer_email:   document.getElementById('custEmail').value.trim(),
    customer_address: custAddress,
    status:           document.getElementById('orderStatus').value,
    payment_method:   document.getElementById('paymentMethod').value,
    payment_status:   document.getElementById('paymentStatus').value,
    shipping_method:  sel.value,
    shipping_cost:    shipping,
    discount:         discount,
    discount_type:    discType,
    subtotal,
    total,
    remarks:          document.getElementById('remarks').value.trim(),
    items: items.map(i => ({ product_id: i.id, product_name: i.name, qty: i.qty, sell_price: i.sell_price, buy_price: i.buy_price, total: i.qty * i.sell_price }))
  };

  const btn = document.querySelector('button.btn-primary');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Placing...';

  try {
    const r = await fetch(`${APP_URL}/api/orders.php?action=create`, {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
      showToast('Order created!','success');
      setTimeout(() => window.location.href = `${APP_URL}/pages/orders/view.php?id=${d.order_id}`, 700);
    } else {
      errDiv.textContent = d.message || 'Failed to create order.';
      errDiv.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = 'Place Order';
    }
  } catch(e) {
    errDiv.textContent = 'Network error. Please try again.';
    errDiv.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = 'Place Order';
  }
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>