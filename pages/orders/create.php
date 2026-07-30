<?php
// pages/orders/create.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'order';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$currency   = 'Rs';

// Only orders that haven't been dispatched yet (no stock movement to reconcile) can be edited
$editableStatuses = ['new', 'pending', 'confirmed'];

$editOrderId = trim($_GET['edit'] ?? '');
$isEditMode  = $editOrderId !== '';
$order       = null;
$orderItems  = [];

if ($isEditMode) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id=?");
    $stmt->execute([$editOrderId]);
    $order = $stmt->fetch();
    if (!$order) redirect('/pages/orders/index.php');
    if (!in_array($order['status'], $editableStatuses)) redirect('/pages/orders/view.php?id=' . urlencode($order['order_id']));

    $itemsStmt = $pdo->prepare("
        SELECT oi.*, p.product_id AS product_code
        FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id=?
    ");
    $itemsStmt->execute([$order['id']]);
    $orderItems = $itemsStmt->fetchAll();
}

// Exchange: this page also handles the "create the replacement order" step —
// same form, just prefilled with the original order's customer details plus
// a card listing what's being given up.
$exchangeFromOrderId = trim($_GET['exchange_from'] ?? '');
$isExchangeMode      = $exchangeFromOrderId !== '' && !$isEditMode;
$exchangeFromOrder   = null;
$returnCandidates    = [];

if ($isExchangeMode) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id=?");
    $stmt->execute([$exchangeFromOrderId]);
    $exchangeFromOrder = $stmt->fetch();
    if (!$exchangeFromOrder || !$exchangeFromOrder['stock_deducted']) redirect('/pages/orders/index.php');

    $candStmt = $pdo->prepare("
        SELECT oi.id AS item_id, oi.product_name, oi.variant_info, oi.qty, oi.returned_qty, oi.sell_price,
               COALESCE((SELECT SUM(qty) FROM order_returns WHERE order_item_id=oi.id AND is_exchange=1 AND received_at IS NULL), 0) AS pending_qty
        FROM order_items oi
        WHERE oi.order_id=? AND oi.product_id IS NOT NULL
    ");
    $candStmt->execute([$exchangeFromOrder['id']]);
    foreach ($candStmt->fetchAll() as $c) {
        $remaining = (int)$c['qty'] - (int)$c['returned_qty'] - (int)$c['pending_qty'];
        if ($remaining > 0) {
            $c['remaining'] = $remaining;
            $returnCandidates[] = $c;
        }
    }
}

$pageTitle = $isEditMode ? 'Edit Order' : ($isExchangeMode ? 'Exchange/Return Order' : 'New Order');

// Generate next order ID preview (only relevant when creating)
if (!$isEditMode) {
    $last   = $pdo->query("SELECT order_id FROM orders ORDER BY id DESC LIMIT 1")->fetchColumn();
    $num    = $last ? (int)substr($last, strrpos($last,'-')+1) + 1 : 1;
    $nextId = 'ORD-' . date('Y') . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

$fbPages         = $pdo->query("SELECT id, name FROM fb_pages WHERE status='active' ORDER BY name")->fetchAll();
$couriers        = $pdo->query("SELECT id, name FROM couriers WHERE status='active' ORDER BY name")->fetchAll();
$shippingMethods = $pdo->query("SELECT id, name, cost FROM shipping_methods WHERE status='active' ORDER BY name")->fetchAll();

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
            <span style="font-size:.82rem"><?= $isEditMode ? 'Edit Order' : ($isExchangeMode ? 'Exchange/Return' : 'New Order') ?></span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700"><?= $isEditMode ? 'Edit Order — ' . e($order['order_id']) : ($isExchangeMode ? 'Exchange/Return — for ' . e($exchangeFromOrder['order_id']) : 'Add New Order') ?></h1>
        </div>
        <div style="display:flex;gap:8px">
          <a href="<?= APP_URL ?>/pages/orders/<?= $isEditMode ? 'view.php?id=' . urlencode($order['order_id']) : ($isExchangeMode ? 'view.php?id=' . urlencode($exchangeFromOrder['order_id']) : 'index.php') ?>" class="btn btn-outline btn-sm">Cancel</a>
          <button class="btn btn-primary" onclick="submitOrder()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>
            <?= $isEditMode ? 'Update Order' : ($isExchangeMode ? 'Submit Exchange/Return' : 'Place Order') ?>
          </button>
        </div>
      </div>

      <div class="grid-sidebar" style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

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

            <!-- Discount / Extra Charge -->
            <div style="display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);flex-wrap:wrap">
              <label style="font-size:.82rem;font-weight:600;color:var(--text-secondary);white-space:nowrap">Discount:</label>
              <input type="number" id="discountAmt" min="0" step="0.01" value="0" class="form-control" style="max-width:110px" oninput="recalc()">
              <select id="discountType" class="form-control" style="max-width:100px" onchange="recalc()">
                <option value="fixed">Rs (Fixed)</option>
                <option value="percent">% (Percent)</option>
              </select>
              <label style="font-size:.82rem;font-weight:600;color:var(--text-secondary);white-space:nowrap;margin-left:8px">Extra Charge:</label>
              <input type="number" id="extraChargeAmt" min="0" step="0.01" value="0" class="form-control" style="max-width:110px" oninput="recalc()">
            </div>
          </div>

          <?php if ($isExchangeMode): ?>
          <!-- Items Being Returned -->
          <div class="card" style="border:1px solid #fde68a;background:#fffbeb">
            <div class="card-title" style="margin-bottom:2px">Items Being Returned</div>
            <p style="font-size:.8rem;color:var(--text-secondary);margin-bottom:14px">From order <?= e($exchangeFromOrder['order_id']) ?> — select what the customer is giving back. Add a replacement product below for an exchange, or leave it empty for a plain return — either way, stock won't change until it's confirmed received on the Returns page.</p>
            <div style="display:flex;flex-direction:column;gap:8px">
              <?php foreach ($returnCandidates as $rc): ?>
              <label style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:var(--radius-md);cursor:pointer;background:#fff">
                <input type="checkbox" class="return-item-check" data-item-id="<?= (int)$rc['item_id'] ?>" onchange="toggleReturnQty(this)" style="width:16px;height:16px;flex-shrink:0">
                <div style="flex:1">
                  <div style="font-weight:600;font-size:.85rem"><?= e($rc['product_name']) ?></div>
                  <?php if ($rc['variant_info']): ?>
                  <div style="font-size:.74rem;color:var(--text-muted)"><?= e($rc['variant_info']) ?></div>
                  <?php endif; ?>
                </div>
                <span class="return-item-value" data-price="<?= (float)$rc['sell_price'] ?>" style="font-size:.8rem;font-weight:600;color:var(--text-secondary);white-space:nowrap">
                  <?= $currency ?> <?= number_format($rc['sell_price'], 0) ?> &times; <?= (int)$rc['remaining'] ?> = <?= $currency ?> <?= number_format($rc['sell_price'] * $rc['remaining'], 0) ?>
                </span>
                <input type="number" class="return-item-qty form-control" data-item-id="<?= (int)$rc['item_id'] ?>"
                       min="1" max="<?= (int)$rc['remaining'] ?>" value="<?= (int)$rc['remaining'] ?>" disabled
                       oninput="updateReturnItemValue(this)"
                       style="width:70px;text-align:center">
                <span style="font-size:.74rem;color:var(--text-muted);white-space:nowrap">of <?= (int)$rc['remaining'] ?></span>
              </label>
              <?php endforeach; ?>
              <?php if (empty($returnCandidates)): ?>
              <div style="text-align:center;color:var(--text-muted);padding:16px;font-size:.85rem">Nothing left to exchange on this order</div>
              <?php endif; ?>
            </div>
            <div class="form-group" style="margin-top:14px">
              <label class="form-label">Exchange Reason</label>
              <input type="text" id="returnReason" class="form-control" placeholder="e.g. Wrong size, customer wants a different color">
            </div>
          </div>
          <?php endif; ?>

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
                  <input type="text" id="custPhone" class="form-control" placeholder="98XXXXXXXX" maxlength="10" inputmode="numeric" onblur="checkDuplicatePhone(); checkBlacklist()">
                  <div id="blacklistWarning" style="display:none;margin-top:6px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-sm);color:#b91c1c;font-size:.78rem;font-weight:600"></div>
                  <div id="duplicateWarning" style="display:none;margin-top:6px;padding:8px 10px;background:#fefce8;border:1px solid #fde68a;border-radius:var(--radius-sm);color:#92400e;font-size:.78rem"></div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">Delivery Address</label>
                  <input type="text" id="custAddress" class="form-control" placeholder="Street, City, District">
                </div>
                <div class="form-group">
                  <label class="form-label">Courier Name</label>
                  <div style="display:flex;gap:6px;align-items:center">
                    <select id="courierName" class="form-control">
                      <option value="">— None —</option>
                      <?php foreach ($couriers as $c): ?>
                      <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-outline btn-sm" style="white-space:nowrap;padding:8px 10px" onclick="document.getElementById('addCourierModal').style.display='flex'">+</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="grid-3" style="gap:12px">
                <div class="form-group">
                  <label class="form-label">Page</label>
                  <div style="display:flex;gap:6px;align-items:center">
                    <select id="fbPage" class="form-control">
                      <option value="">— None —</option>
                      <?php foreach ($fbPages as $fp): ?>
                      <option value="<?= $fp['id'] ?>"><?= e($fp['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-outline btn-sm" style="white-space:nowrap;padding:8px 10px" onclick="document.getElementById('addPageModal').style.display='flex'">+</button>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Shipping Method</label>
                  <select id="shippingMethod" class="form-control" onchange="recalc()">
                    <option value="">No shipping</option>
                    <?php foreach ($shippingMethods as $sm): ?>
                    <option value="<?= e($sm['name']) ?>" data-cost="<?= $sm['cost'] ?>"><?= e($sm['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">&nbsp;</label>
                  <div style="display:flex;align-items:center;gap:14px;height:38px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:6px">
                      <input type="checkbox" id="prepaidCheckbox" style="width:16px;height:16px;flex-shrink:0" onchange="togglePrepaid()">
                      <label for="prepaidCheckbox" style="font-size:.88rem;font-weight:600;color:var(--text);margin:0;cursor:pointer;white-space:nowrap">Prepaid</label>
                      <input type="number" id="amountPaidInput" class="form-control" min="0" step="0.01" value="0" disabled
                             style="display:none;width:100px;flex-shrink:0" oninput="amountPaidTouched=true; recalc();">
                    </div>
                  </div>
                </div>
              </div>
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
                <span style="font-weight:600;color:var(--text)"><?= $isEditMode ? e($order['order_id']) : $nextId ?></span>
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
                <span>Extra Charge</span>
                <span id="sumExtraCharge" style="font-weight:600;color:#22c55e">+ <?= $currency ?> 0</span>
              </div>
              <div style="display:flex;justify-content:space-between;color:var(--text-secondary)">
                <span>Shipping</span>
                <span id="sumShipping" style="font-weight:600;color:var(--text)"><?= $currency ?> 0</span>
              </div>
              <div style="height:1px;background:var(--border);margin:4px 0"></div>
              <div style="display:flex;justify-content:space-between">
                <span style="font-weight:700;font-size:1rem">Amount <span style="font-weight:500;color:var(--text-muted);font-size:.72rem">(to collect)</span></span>
                <span id="sumAmountDue" style="font-weight:700;font-size:1.1rem;color:var(--text)"><?= $currency ?> 0</span>
              </div>
              <div style="display:flex;justify-content:space-between">
                <span style="font-weight:700;font-size:.9rem">Total <span style="font-weight:500;color:var(--text-muted);font-size:.72rem">(Order Value)</span></span>
                <span id="sumTotal" style="font-weight:700;font-size:.95rem;color:var(--primary)"><?= $currency ?> 0</span>
              </div>
              <div id="sumAlreadyPaidNote" style="display:none;font-size:.72rem;font-weight:700;color:#22c55e;margin-top:-2px"></div>
            </div>
          </div>

          <!-- Payment wallet display -->
          <div class="card" id="walletCard" style="display:none">
            <div style="font-size:.82rem;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Payment Method</div>
            <div id="walletDisplay" style="display:flex;align-items:center;gap:8px;font-size:.88rem;font-weight:600"></div>
          </div>

          <button class="btn btn-primary" style="width:100%" onclick="submitOrder()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= $isEditMode ? 'Update Order' : ($isExchangeMode ? 'Submit Exchange/Return' : 'Place Order') ?>
          </button>
          <div id="orderError" style="display:none;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;font-size:.83rem"></div>
        </div>

      </div>
    </main>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- Add FB Page modal -->
<div id="addPageModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:340px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px">Add Page</div>
    <div class="form-group">
      <label class="form-label">Page Name</label>
      <input type="text" id="newPageName" class="form-control" placeholder="Page name">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('addPageModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="addFbPage()">Add</button>
    </div>
  </div>
</div>

<!-- Add Courier modal -->
<div id="addCourierModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:24px;max-width:340px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1rem;font-weight:700;margin-bottom:14px">Add Courier</div>
    <div class="form-group">
      <label class="form-label">Courier Name</label>
      <input type="text" id="newCourierName" class="form-control" placeholder="e.g. Pathao, NCM">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('addCourierModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="addCourier()">Add</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast-container" id="toastContainer"></div>

<script>
const APP_URL     = '<?= APP_URL ?>';
const CURRENCY    = '<?= $currency ?>';
const IS_EDIT     = <?= $isEditMode ? 'true' : 'false' ?>;
const IS_EXCHANGE = <?= $isExchangeMode ? 'true' : 'false' ?>;
<?php if ($isExchangeMode): ?>
const PREFILL_ORDER = <?= json_encode([
    'original_order_id' => $exchangeFromOrder['order_id'],
    'customer_name'      => $exchangeFromOrder['customer_name'],
    'customer_phone'     => $exchangeFromOrder['customer_phone'],
    'customer_address'   => $exchangeFromOrder['customer_address'],
    'fb_page_id'         => $exchangeFromOrder['fb_page_id'],
    'courier_name'       => $exchangeFromOrder['courier_name'],
]) ?>;
<?php endif; ?>
<?php if ($isEditMode): ?>
const EDIT_ORDER = <?= json_encode([
    'order_id'        => $order['order_id'],
    'customer_name'   => $order['customer_name'],
    'customer_phone'  => $order['customer_phone'],
    'customer_address'=> $order['customer_address'],
    'fb_page_id'      => $order['fb_page_id'],
    'shipping_method' => $order['shipping_method'],
    'payment_method'  => $order['payment_method'],
    'payment_status'  => $order['payment_status'],
    'amount_paid'     => $order['amount_paid'],
    'courier_name'    => $order['courier_name'],
    'discount'        => $order['discount'],
    'discount_type'   => $order['discount_type'],
    'extra_charge'    => $order['extra_charge'],
    'remarks'         => $order['remarks'],
    'items'           => array_map(fn($it) => [
        'id'         => (int)$it['product_id'],
        'name'       => $it['product_name'],
        'product_id' => $it['product_code'] ?? $it['product_name'],
        'sell_price' => (float)$it['sell_price'],
        'buy_price'  => (float)$it['buy_price'],
        'qty'        => (int)$it['qty'],
        'variant_id'   => $it['variant_id'] !== null ? (int)$it['variant_id'] : null,
        'variant_info' => $it['variant_info'],
    ], $orderItems),
]) ?>;
<?php endif; ?>
let items = IS_EDIT ? EDIT_ORDER.items : [];
// Amount Paid defaults to the running total (assume fully prepaid) until the
// user manually edits it — then it stops auto-following the total.
let amountPaidTouched = false;

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
    lastSearchProducts = d.products;
    dropdown.innerHTML = d.products.map((p, pi) => `
      <div onclick="event.stopPropagation(); pickProduct(${pi})"
           style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
        <div style="width:36px;height:36px;background:var(--bg);border-radius:var(--radius-sm);flex-shrink:0;overflow:hidden">
          ${p.image_url ? `<img src="${p.image_url}" style="width:100%;height:100%;object-fit:cover">` : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg></div>'}
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${p.name}</div>
          <div style="font-size:.74rem;color:var(--text-muted)">${p.product_id} &nbsp;·&nbsp; Stock: ${p.quantity} &nbsp;·&nbsp; ${CURRENCY} ${Number(p.sell_price).toLocaleString()}${p.variants?.length ? ` &nbsp;·&nbsp; ${p.variants.length} variant(s)` : ''}</div>
        </div>
        ${p.quantity <= 0 ? '<span style="font-size:.7rem;font-weight:700;color:#ef4444">OUT</span>' : ''}
      </div>`).join('');
    dropdown.style.display = 'block';
  }, 280);
});

document.addEventListener('click', e => { if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) dropdown.style.display='none'; });

let lastSearchProducts = [];

function pickProduct(pi) {
  const p = lastSearchProducts[pi];
  if (!p.variants || !p.variants.length) { addItem(p); return; }
  // Show a variant picker in place of the product list
  dropdown.innerHTML = `
    <div onclick="event.stopPropagation(); renderSearchResults()" style="padding:9px 14px;cursor:pointer;font-size:.8rem;color:var(--primary);font-weight:600;border-bottom:1px solid var(--border)">&larr; Back to results</div>
    <div style="padding:8px 14px;font-size:.78rem;color:var(--text-muted)">${p.name} — choose a variant</div>
  ` + p.variants.map((v, vi) => `
    <div onclick="event.stopPropagation(); addItem(lastSearchProducts[${pi}], lastSearchProducts[${pi}].variants[${vi}])"
         style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--border)"
         onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
      <div style="font-size:.84rem;font-weight:600">${v.label}: ${v.value} &nbsp;·&nbsp; ${CURRENCY} ${Number(v.sell_price).toLocaleString()}</div>
      <div style="font-size:.74rem;color:${v.qty_adj <= 0 ? '#ef4444' : 'var(--text-muted)'}">${v.qty_adj <= 0 ? 'Out of stock' : `Stock: ${v.qty_adj}`}</div>
    </div>`).join('');
}

function renderSearchResults() {
  searchInput.dispatchEvent(new Event('input'));
}

function addItem(p, variant = null) {
  dropdown.style.display = 'none';
  searchInput.value = '';
  const variantId = variant ? variant.id : null;
  // Check if already in list (same product + same variant)
  const existing = items.find(i => i.id === p.id && (i.variant_id ?? null) === variantId);
  if (existing) { existing.qty++; renderItems(); recalc(); return; }
  items.push({
    id: p.id,
    name: p.name,
    product_id: p.product_id,
    sell_price: variant ? parseFloat(variant.sell_price) : parseFloat(p.sell_price),
    buy_price: variant ? parseFloat(variant.buy_price) : parseFloat(p.buy_price),
    qty: 1,
    variant_id: variantId,
    variant_info: variant ? `${variant.label}: ${variant.value}` : null,
  });
  renderItems();
  recalc();

  // Auto-select this product's usual Facebook Page, if it has one — still
  // freely changeable afterward, this just saves picking it manually.
  if (p.fb_page_id) {
    const pageSel = document.getElementById('fbPage');
    if (pageSel && [...pageSel.options].some(o => o.value == p.fb_page_id)) {
      pageSel.value = p.fb_page_id;
    }
  }
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
        <div style="font-size:.74rem;color:var(--text-muted)">${item.product_id}${item.variant_info ? ` &nbsp;·&nbsp; ${item.variant_info}` : ''}</div>
      </td>
      <td>
        <input type="number" value="${item.qty}" min="1"
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

// Exchange mode: the qty input for a "being returned" row only accepts a
// value once its checkbox is ticked.
function toggleReturnQty(cb) {
  const qtyInput = document.querySelector(`.return-item-qty[data-item-id="${cb.dataset.itemId}"]`);
  qtyInput.disabled = !cb.checked;
}

// Keeps the "Rs X × qty = Rs total" line in sync as the return qty is edited.
function updateReturnItemValue(qtyInput) {
  const row   = qtyInput.closest('label');
  const valEl = row.querySelector('.return-item-value');
  const price = parseFloat(valEl.dataset.price) || 0;
  const qty   = Math.max(0, parseInt(qtyInput.value) || 0);
  valEl.textContent = `${CURRENCY} ${price.toLocaleString()} × ${qty} = ${CURRENCY} ${(price * qty).toLocaleString()}`;
}

function recalc() {
  const subtotal = items.reduce((s, i) => s + i.qty * i.sell_price, 0);
  const discAmt  = parseFloat(document.getElementById('discountAmt').value) || 0;
  const discType = document.getElementById('discountType').value;
  const discount = discType === 'percent' ? subtotal * discAmt / 100 : discAmt;
  const extraCharge = parseFloat(document.getElementById('extraChargeAmt').value) || 0;

  const sel      = document.getElementById('shippingMethod');
  const shipping = parseFloat(sel.selectedOptions[0]?.dataset.cost || 0);
  const total    = Math.max(0, subtotal - discount + extraCharge + shipping);

  document.getElementById('sumItems').textContent    = items.reduce((s,i)=>s+i.qty,0);
  document.getElementById('sumSubtotal').textContent = `${CURRENCY} ${subtotal.toLocaleString()}`;
  document.getElementById('sumDiscount').textContent  = `- ${CURRENCY} ${discount.toLocaleString()}`;
  document.getElementById('sumExtraCharge').textContent = `+ ${CURRENCY} ${extraCharge.toLocaleString()}`;
  document.getElementById('sumShipping').textContent  = `${CURRENCY} ${shipping.toLocaleString()}`;
  document.getElementById('sumTotal').textContent     = `${CURRENCY} ${total.toLocaleString()}`;

  const prepaidChecked = document.getElementById('prepaidCheckbox').checked;
  if (prepaidChecked && !amountPaidTouched) {
    document.getElementById('amountPaidInput').value = total.toFixed(2);
  }

  const amountPaid = prepaidChecked ? (parseFloat(document.getElementById('amountPaidInput').value) || 0) : 0;
  const amountDue  = Math.max(0, total - amountPaid);
  const dueEl  = document.getElementById('sumAmountDue');
  const noteEl = document.getElementById('sumAlreadyPaidNote');
  dueEl.textContent = `${CURRENCY} ${amountDue.toLocaleString()}`;
  dueEl.style.color = amountDue > 0 ? 'var(--text)' : '#22c55e';
  if (amountPaid > 0) {
    noteEl.textContent = `✓ ${CURRENCY} ${amountPaid.toLocaleString()} already paid`;
    noteEl.style.display = '';
  } else {
    noteEl.style.display = 'none';
  }
}

// The Amount Paid field is only usable once Prepaid is checked — checking it
// reveals the field pre-filled with the current total (overridable down to
// any partial figure); unchecking hides it and resets to unpaid.
function togglePrepaid() {
  const checked = document.getElementById('prepaidCheckbox').checked;
  const input   = document.getElementById('amountPaidInput');
  input.disabled = !checked;
  input.style.display = checked ? '' : 'none';
  amountPaidTouched = false;
  if (!checked) input.value = 0;
  recalc();
}

// ── Blacklist check ──────────────────────────────────────────
let phoneBlacklisted = false;
let blacklistReason   = '';

async function checkBlacklist() {
  const warnDiv = document.getElementById('blacklistWarning');
  warnDiv.style.display = 'none';
  phoneBlacklisted = false;
  blacklistReason  = '';
  const phone = document.getElementById('custPhone').value.trim();
  if (!/^\d{10}$/.test(phone)) return;

  const r = await fetch(`${APP_URL}/api/orders.php?action=check_blacklist&phone=${encodeURIComponent(phone)}`);
  const d = await r.json();
  if (d.success && d.blacklisted) {
    phoneBlacklisted = true;
    blacklistReason  = d.reason || '';
    warnDiv.textContent = `⚠ This customer is blacklisted${d.reason ? ' — ' + d.reason : ''}.`;
    warnDiv.style.display = 'block';
  }
}

// ── Duplicate phone check ───────────────────────────────────
async function checkDuplicatePhone() {
  const warnDiv = document.getElementById('duplicateWarning');
  warnDiv.style.display = 'none';
  const phone = document.getElementById('custPhone').value.trim();
  if (!/^\d{10}$/.test(phone)) return;

  const excludeParam = IS_EDIT ? `&exclude_order_id=${encodeURIComponent(EDIT_ORDER.order_id)}` : '';
  const r = await fetch(`${APP_URL}/api/orders.php?action=check_duplicate&phone=${encodeURIComponent(phone)}${excludeParam}`);
  const d = await r.json();
  if (d.success && d.duplicate) {
    warnDiv.textContent = `⚠ This phone number already has an order today (${d.order_id}).`;
    warnDiv.style.display = 'block';
  }
}

// ── Add FB Page ──────────────────────────────────────────────
async function addFbPage() {
  const name = document.getElementById('newPageName').value.trim();
  if (!name) return;
  const r = await fetch(`${APP_URL}/api/admin.php?action=add_fb_page`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name })
  });
  const d = await r.json();
  if (d.success) {
    const sel = document.getElementById('fbPage');
    const opt = document.createElement('option');
    opt.value = d.id; opt.textContent = d.name; opt.selected = true;
    sel.appendChild(opt);
    document.getElementById('newPageName').value = '';
    document.getElementById('addPageModal').style.display = 'none';
    showToast('Page added', 'success');
  } else {
    showToast(d.message || 'Failed to add page', 'error');
  }
}

// ── Add Courier ──────────────────────────────────────────────
async function addCourier() {
  const name = document.getElementById('newCourierName').value.trim();
  if (!name) return;
  const r = await fetch(`${APP_URL}/api/admin.php?action=add_courier`, {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name })
  });
  const d = await r.json();
  if (d.success) {
    const sel = document.getElementById('courierName');
    const opt = document.createElement('option');
    opt.value = d.name; opt.textContent = d.name; opt.selected = true;
    sel.appendChild(opt);
    document.getElementById('newCourierName').value = '';
    document.getElementById('addCourierModal').style.display = 'none';
    showToast('Courier added', 'success');
  } else {
    showToast(d.message || 'Failed to add courier', 'error');
  }
}

// ── Submit ───────────────────────────────────────────────────
async function submitOrder() {
  const errDiv = document.getElementById('orderError');
  errDiv.style.display = 'none';

  const custName    = document.getElementById('custName').value.trim();
  const custPhone   = document.getElementById('custPhone').value.trim();
  const custAddress = document.getElementById('custAddress').value.trim();

  if (!custName)              { errDiv.textContent='Customer name is required.'; errDiv.style.display='block'; return; }
  if (!/^\d{10}$/.test(custPhone)) { errDiv.textContent='Phone number must be exactly 10 digits.'; errDiv.style.display='block'; return; }

  let returnItems = [];
  if (IS_EXCHANGE) {
    returnItems = [...document.querySelectorAll('.return-item-check:checked')].map(cb => ({
      item_id: parseInt(cb.dataset.itemId),
      qty: parseInt(document.querySelector(`.return-item-qty[data-item-id="${cb.dataset.itemId}"]`).value) || 0,
    })).filter(ri => ri.qty > 0);
    if (!returnItems.length) { errDiv.textContent='Select at least one item the customer is returning.'; errDiv.style.display='block'; return; }
  }
  // A new product is only required when there's actually a replacement being
  // given — picking return item(s) with nothing new added is just a Return,
  // handled without creating a second order (see action=create server-side).
  const isPureReturn = IS_EXCHANGE && returnItems.length > 0 && !items.length;
  if (!items.length && !isPureReturn) { errDiv.textContent='Add at least one product.'; errDiv.style.display='block'; return; }

  // Re-check the blacklist right here (not just relying on the onblur check) so
  // the warning always fires at the moment of placing the order.
  const blCheck = await fetch(`${APP_URL}/api/orders.php?action=check_blacklist&phone=${encodeURIComponent(custPhone)}`);
  const blData  = await blCheck.json();
  if (blData.success && blData.blacklisted) {
    const proceed = confirm(`This customer's phone number is blacklisted${blData.reason ? ' — ' + blData.reason : ''}.\n\nPlace the order anyway?`);
    if (!proceed) return;
  }

  const discAmt  = parseFloat(document.getElementById('discountAmt').value) || 0;
  const discType = document.getElementById('discountType').value;
  const subtotal = items.reduce((s,i)=>s+i.qty*i.sell_price,0);
  const discount = discType==='percent' ? subtotal*discAmt/100 : discAmt;
  const extraCharge = parseFloat(document.getElementById('extraChargeAmt').value) || 0;
  const sel      = document.getElementById('shippingMethod');
  const shipping = parseFloat(sel.selectedOptions[0]?.dataset.cost||0);
  const total    = Math.max(0, subtotal - discount + extraCharge + shipping);

  const payload = {
    customer_name:    custName,
    customer_phone:   custPhone,
    customer_address: custAddress,
    fb_page_id:       document.getElementById('fbPage').value || null,
    amount_paid:      document.getElementById('prepaidCheckbox').checked
                        ? Math.max(0, parseFloat(document.getElementById('amountPaidInput').value) || 0)
                        : 0,
    extra_charge:     extraCharge,
    shipping_method:  sel.value,
    shipping_cost:    shipping,
    courier_name:     document.getElementById('courierName').value.trim(),
    discount:         discount,
    discount_type:    discType,
    subtotal,
    total,
    remarks:          document.getElementById('remarks').value.trim(),
    ...(IS_EXCHANGE ? {
      exchange_from_order_id: PREFILL_ORDER.original_order_id,
      return_items:            returnItems,
      return_reason:           document.getElementById('returnReason').value.trim(),
    } : {}),
    items: items.map(i => ({ product_id: i.id, product_name: i.name, qty: i.qty, sell_price: i.sell_price, buy_price: i.buy_price, total: i.qty * i.sell_price, variant_id: i.variant_id ?? null, variant_info: i.variant_info ?? null }))
  };
  if (IS_EDIT) payload.order_id = EDIT_ORDER.order_id;

  const btns = document.querySelectorAll('button.btn-primary');
  const busyLabel = IS_EDIT ? 'Updating...' : (IS_EXCHANGE ? 'Submitting...' : 'Placing...');
  const idleLabel = IS_EDIT ? 'Update Order' : (IS_EXCHANGE ? 'Submit Exchange/Return' : 'Place Order');
  btns.forEach(b => { b.disabled = true; b.innerHTML = `<span class="spinner"></span> ${busyLabel}`; });

  try {
    const r = await fetch(`${APP_URL}/api/orders.php?action=${IS_EDIT ? 'update' : 'create'}`, {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.success) {
      showToast(IS_EDIT ? 'Order updated!' : (IS_EXCHANGE ? 'Exchange/Return submitted!' : 'Order created!'),'success');
      setTimeout(() => window.location.href = `${APP_URL}/pages/orders/view.php?id=${d.order_id}`, 700);
    } else {
      errDiv.textContent = d.message || `Failed to ${IS_EDIT ? 'update' : 'create'} order.`;
      errDiv.style.display = 'block';
      btns.forEach(b => { b.disabled = false; b.textContent = idleLabel; });
    }
  } catch(e) {
    errDiv.textContent = 'Network error. Please try again.';
    errDiv.style.display = 'block';
    btns.forEach(b => { b.disabled = false; b.textContent = idleLabel; });
  }
}

// ── Prefill customer details when creating an Exchange order ──
if (IS_EXCHANGE) {
  document.getElementById('custName').value    = PREFILL_ORDER.customer_name || '';
  document.getElementById('custPhone').value   = PREFILL_ORDER.customer_phone || '';
  document.getElementById('custAddress').value = PREFILL_ORDER.customer_address || '';
  if (PREFILL_ORDER.fb_page_id) document.getElementById('fbPage').value = PREFILL_ORDER.fb_page_id;
  if (PREFILL_ORDER.courier_name) {
    const courierSel = document.getElementById('courierName');
    if (![...courierSel.options].some(o => o.value === PREFILL_ORDER.courier_name)) {
      const opt = document.createElement('option');
      opt.value = PREFILL_ORDER.courier_name; opt.textContent = PREFILL_ORDER.courier_name;
      courierSel.appendChild(opt);
    }
    courierSel.value = PREFILL_ORDER.courier_name;
  }
}

// ── Prefill form when editing an existing order ───────────────
if (IS_EDIT) {
  document.getElementById('custName').value    = EDIT_ORDER.customer_name || '';
  document.getElementById('custPhone').value   = EDIT_ORDER.customer_phone || '';
  document.getElementById('custAddress').value = EDIT_ORDER.customer_address || '';
  if (EDIT_ORDER.fb_page_id) document.getElementById('fbPage').value = EDIT_ORDER.fb_page_id;
  if (EDIT_ORDER.shipping_method) document.getElementById('shippingMethod').value = EDIT_ORDER.shipping_method;
  if ((EDIT_ORDER.amount_paid || 0) > 0) {
    document.getElementById('prepaidCheckbox').checked = true;
    document.getElementById('amountPaidInput').disabled = false;
    document.getElementById('amountPaidInput').style.display = '';
    document.getElementById('amountPaidInput').value = EDIT_ORDER.amount_paid;
    amountPaidTouched = true; // it's a real saved value — don't let recalc() below overwrite it
  }
  if (EDIT_ORDER.courier_name) {
    const courierSel = document.getElementById('courierName');
    if (![...courierSel.options].some(o => o.value === EDIT_ORDER.courier_name)) {
      const opt = document.createElement('option');
      opt.value = EDIT_ORDER.courier_name; opt.textContent = EDIT_ORDER.courier_name;
      courierSel.appendChild(opt);
    }
    courierSel.value = EDIT_ORDER.courier_name;
  }
  document.getElementById('remarks').value       = EDIT_ORDER.remarks || '';

  const discAmt = parseFloat(EDIT_ORDER.discount) || 0;
  document.getElementById('discountType').value = EDIT_ORDER.discount_type || 'fixed';
  // discount is stored as a resolved amount; if it was a percent discount, back-convert to the % for display
  const subtotalAtSave = items.reduce((s,i) => s + i.qty * i.sell_price, 0);
  document.getElementById('discountAmt').value = (EDIT_ORDER.discount_type === 'percent' && subtotalAtSave > 0)
    ? +(discAmt / subtotalAtSave * 100).toFixed(2)
    : discAmt;
  document.getElementById('extraChargeAmt').value = parseFloat(EDIT_ORDER.extra_charge) || 0;

  renderItems();
  recalc();
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>