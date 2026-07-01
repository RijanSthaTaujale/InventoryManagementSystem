<?php
// pages/products/view.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$activePage = 'product';
$user       = currentUser();
$isAdmin    = $user['role'] === 'admin';
$currency   = 'Rs';

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('/pages/products/index.php');

$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) redirect('/pages/products/index.php');

$pageTitle = $p['name'];

$photos   = $pdo->prepare("SELECT * FROM product_photos WHERE product_id=? ORDER BY sort_order");
$photos->execute([$id]); $photos = $photos->fetchAll();

$variants = $pdo->prepare("SELECT * FROM product_variants WHERE product_id=? ORDER BY label,value");
$variants->execute([$id]); $variants = $variants->fetchAll();

// Group variants by label
$varGroups = [];
foreach ($variants as $v) $varGroups[$v['label']][] = $v;

// Stock adjustments log
$adjLog = $pdo->prepare("SELECT sa.*, u.name AS by_name FROM stock_adjustments sa LEFT JOIN users u ON u.id=sa.adjusted_by WHERE sa.product_id=? ORDER BY sa.created_at DESC LIMIT 10");
$adjLog->execute([$id]); $adjLog = $adjLog->fetchAll();

// Recent orders containing this product
$recentOrders = $pdo->prepare("
    SELECT o.order_id, o.customer_name, o.status, o.created_at, oi.qty, oi.sell_price
    FROM order_items oi JOIN orders o ON o.id=oi.order_id
    WHERE oi.product_id=? ORDER BY o.created_at DESC LIMIT 5
");
$recentOrders->execute([$id]); $recentOrders = $recentOrders->fetchAll();

$badgeMap = ['instock'=>'badge-instock','lowstock'=>'badge-lowstock','critical'=>'badge-critical','outofstock'=>'badge-critical'];
$badge    = $badgeMap[$p['stock_status']] ?? 'badge-pending';
$badgeLbl = $p['stock_status']==='outofstock'?'Out of Stock':ucfirst($p['stock_status']);

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <!-- Breadcrumb + actions -->
      <div class="flex-between mb-4">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <a href="<?= APP_URL ?>/pages/products/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Products
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem"><?= e($p['name']) ?></span>
          </div>
          <h1 style="font-size:1.2rem;font-weight:700"><?= e($p['name']) ?></h1>
          <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <span style="font-size:.78rem;color:var(--text-muted)"><?= e($p['product_id']) ?></span>
            <span class="badge <?= $badge ?>"><?= $badgeLbl ?></span>
          </div>
        </div>
        <?php if ($isAdmin): ?>
        <div style="display:flex;gap:8px">
          <a href="<?= APP_URL ?>/pages/products/add.php?edit=<?= $p['id'] ?>" class="btn btn-outline btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </a>
          <button onclick="openAdjModal()" class="btn btn-primary btn-sm">Adjust Stock</button>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">

        <!-- LEFT -->
        <div style="display:flex;flex-direction:column;gap:16px">

          <!-- Main product card -->
          <div class="card">
            <div style="display:grid;grid-template-columns:200px 1fr;gap:20px">
              <!-- Image gallery -->
              <div>
                <div style="width:200px;height:200px;background:var(--bg);border-radius:var(--radius-md);overflow:hidden;margin-bottom:8px" id="mainImg">
                  <?php $mainImg = $p['image_url'] ?? ($photos[0]['url'] ?? ''); ?>
                  <?php if ($mainImg): ?>
                    <img src="<?= e($mainImg) ?>" style="width:100%;height:100%;object-fit:cover" id="mainImgTag">
                  <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">
                      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                  <?php endif; ?>
                </div>
                <?php if (!empty($photos)): ?>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <?php foreach ($photos as $ph): ?>
                  <div onclick="document.getElementById('mainImgTag').src='<?= e($ph['url']) ?>'"
                       style="width:44px;height:44px;background:var(--bg);border-radius:var(--radius-sm);overflow:hidden;cursor:pointer;border:2px solid transparent"
                       onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='transparent'">
                    <img src="<?= e($ph['url']) ?>" style="width:100%;height:100%;object-fit:cover">
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>

              <!-- Details -->
              <div>
                <div style="font-size:1.5rem;font-weight:700;color:var(--primary);margin-bottom:4px"><?= $currency ?> <?= number_format($p['sell_price'],0) ?></div>
                <?php if ($isAdmin): ?>
                <div style="font-size:.82rem;color:var(--text-muted);margin-bottom:12px">Buy price: <?= $currency ?> <?= number_format($p['buy_price'],0) ?></div>
                <?php endif; ?>

                <?php if ($p['description']): ?>
                <p style="font-size:.88rem;color:var(--text-secondary);line-height:1.6;margin-bottom:14px"><?= nl2br(e($p['description'])) ?></p>
                <?php endif; ?>

                <!-- Variants -->
                <?php foreach ($varGroups as $label => $vals): ?>
                <div style="margin-bottom:10px">
                  <div style="font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:5px"><?= e($label) ?></div>
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php foreach ($vals as $v): ?>
                    <span style="padding:4px 12px;border:1.5px solid var(--border);border-radius:9999px;font-size:.8rem;font-weight:500"><?= e($v['value']) ?><?= $v['price_adj']!=0?' ('.($v['price_adj']>0?'+':'').number_format($v['price_adj'],0).')':'' ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endforeach; ?>

                <!-- Meta grid -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px">
                  <?php $meta = [
                    'Brand'    => $p['brand']         ?? '—',
                    'SKU'      => $p['sku']            ?? '—',
                    'Category' => $p['category_name']  ?? '—',
                    'Location' => $p['location']       ?? '—',
                    'Weight'   => $p['weight'] ? $p['weight'].'kg' : '—',
                    'Status'   => ucfirst($p['status']),
                  ];
                  foreach ($meta as $k=>$v): ?>
                  <div style="font-size:.82rem">
                    <span style="color:var(--text-muted)"><?= $k ?>: </span>
                    <span style="font-weight:600"><?= e($v) ?></span>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <?php if ($p['features']): ?>
            <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
              <div style="font-size:.82rem;font-weight:700;margin-bottom:8px">Features</div>
              <ul style="list-style:none;display:flex;flex-direction:column;gap:5px">
                <?php foreach (explode("\n", trim($p['features'])) as $feat): if (!trim($feat)) continue; ?>
                <li style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;color:var(--text-secondary)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" style="flex-shrink:0;margin-top:2px"><polyline points="20 6 9 17 4 12"/></svg>
                  <?= e(trim($feat)) ?>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>

            <?php if ($p['additional_info']): ?>
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
              <div style="font-size:.82rem;font-weight:700;margin-bottom:6px">Additional Information</div>
              <p style="font-size:.84rem;color:var(--text-secondary)"><?= nl2br(e($p['additional_info'])) ?></p>
            </div>
            <?php endif; ?>
          </div>

          <!-- Recent orders -->
          <?php if (!empty($recentOrders)): ?>
          <div class="card">
            <div class="card-title" style="margin-bottom:14px">Recent Orders</div>
            <div class="data-table-wrap" style="border:none">
              <table class="data-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Qty</th><th>Price</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                  <?php foreach ($recentOrders as $o):
                    $bm = ['new'=>'badge-new','confirmed'=>'badge-confirmed','pending'=>'badge-pending','cancelled'=>'badge-cancelled','dispatched'=>'badge-dispatched','delivered'=>'badge-delivered','returned'=>'badge-returned','in_courier'=>'badge-in_courier'];
                  ?>
                  <tr>
                    <td><a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($o['order_id']) ?>" style="font-weight:600;color:var(--primary);font-size:.83rem"><?= e($o['order_id']) ?></a></td>
                    <td style="font-size:.84rem"><?= e($o['customer_name']) ?></td>
                    <td style="font-weight:600"><?= $o['qty'] ?></td>
                    <td><?= $currency ?> <?= number_format($o['sell_price'],0) ?></td>
                    <td><span class="badge <?= $bm[$o['status']]??'badge-pending' ?>"><?= ucfirst(str_replace('_',' ',$o['status'])) ?></span></td>
                    <td style="font-size:.76rem;color:var(--text-muted)"><?= date('d M Y',strtotime($o['created_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <!-- RIGHT: Stock info + log -->
        <div style="display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--topbar-h)+24px)">

          <!-- Stock card -->
          <div class="card">
            <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Stock Status</div>
            <div style="font-size:2.5rem;font-weight:800;color:<?= $p['quantity']<=0?'#ef4444':($p['quantity']<=$p['min_stock_level']?'#f97316':'var(--primary)') ?>;line-height:1"><?= $p['quantity'] ?></div>
            <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px">units in stock</div>
            <div style="margin-top:12px">
              <span class="badge <?= $badge ?>"><?= $badgeLbl ?></span>
            </div>
            <div style="margin-top:12px;font-size:.8rem;color:var(--text-secondary)">
              Min level: <strong><?= $p['min_stock_level'] ?></strong> units
            </div>
            <!-- Stock bar -->
            <div style="margin-top:10px;height:6px;background:var(--bg);border-radius:9999px;overflow:hidden">
              <?php $pct = $p['min_stock_level']>0 ? min(100,($p['quantity']/$p['min_stock_level'])*50) : 100; ?>
              <div style="width:<?= $pct ?>%;height:100%;background:<?= $p['quantity']<=0?'#ef4444':($p['quantity']<=$p['min_stock_level']?'#f97316':'#22c55e') ?>;border-radius:9999px"></div>
            </div>
          </div>

          <!-- Stock adjustment log -->
          <?php if (!empty($adjLog)): ?>
          <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
              <div style="font-size:.76rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Stock History</div>
              <a href="<?= APP_URL ?>/pages/inventory/log.php?product_id=<?= $p['id'] ?>" style="font-size:.76rem;font-weight:600;color:var(--primary)">View Full Log →</a>
            </div>
            <div style="display:flex;flex-direction:column;gap:0">
              <?php foreach ($adjLog as $adj):
                $isPos = $adj['qty_change'] >= 0;
              ?>
              <div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)">
                <div style="width:22px;height:22px;border-radius:50%;background:<?= $isPos?'#dcfce7':'#fee2e2' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="<?= $isPos?'#16a34a':'#dc2626' ?>" stroke-width="2.5">
                    <?= $isPos?'<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>':'<line x1="5" y1="12" x2="19" y2="12"/>' ?>
                  </svg>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:.78rem;font-weight:600;color:<?= $isPos?'#16a34a':'#dc2626' ?>"><?= $isPos?'+':'' ?><?= $adj['qty_change'] ?> units</div>
                  <div style="font-size:.72rem;color:var(--text-muted)"><?= ucfirst($adj['type']) ?> · <?= $adj['by_name']??'System' ?></div>
                  <?php if ($adj['reason']): ?><div style="font-size:.72rem;color:var(--text-muted)"><?= e($adj['reason']) ?></div><?php endif; ?>
                  <div style="font-size:.7rem;color:var(--text-muted)"><?= date('d M, h:i A',strtotime($adj['created_at'])) ?></div>
                </div>
                <div style="font-size:.76rem;font-weight:700;color:var(--text-secondary)"><?= $adj['qty_after'] ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>

    </main>
  </div>
</div>

<!-- Stock Adjust Modal -->
<?php if ($isAdmin): ?>
<div id="adjModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--radius-xl);padding:28px;max-width:380px;width:90%;box-shadow:var(--shadow-md)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:4px">Adjust Stock</div>
    <div style="font-size:.84rem;color:var(--text-secondary);margin-bottom:18px"><?= e($p['name']) ?> — Current: <?= $p['quantity'] ?> units</div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="form-group">
        <label class="form-label">Type</label>
        <select id="adjType" class="form-control" onchange="updateLabel()">
          <option value="add">Add Stock</option>
          <option value="remove">Remove Stock</option>
          <option value="adjustment">Set Exact Quantity</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" id="adjLabel">Quantity to Add</label>
        <input type="number" id="adjQty" class="form-control" min="0" value="0">
      </div>
      <div class="form-group">
        <label class="form-label">Reason</label>
        <input type="text" id="adjReason" class="form-control" placeholder="e.g. Supplier restock">
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('adjModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="submitAdj()">Confirm</button>
    </div>
  </div>
</div>

<script>
function openAdjModal() { document.getElementById('adjModal').style.display='flex'; }
function updateLabel() {
  const labels = {add:'Quantity to Add',remove:'Quantity to Remove',adjustment:'Set Exact Quantity'};
  document.getElementById('adjLabel').textContent = labels[document.getElementById('adjType').value];
}
async function submitAdj() {
  const r = await fetch('<?= APP_URL ?>/api/inventory.php?action=adjust', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      product_id: <?= $p['id'] ?>,
      type:   document.getElementById('adjType').value,
      qty:    parseInt(document.getElementById('adjQty').value)||0,
      reason: document.getElementById('adjReason').value
    })
  });
  const d = await r.json();
  document.getElementById('adjModal').style.display='none';
  if (d.success) { showToast('Stock updated','success'); setTimeout(()=>location.reload(),700); }
  else showToast(d.message||'Failed','error');
}
</script>
<?php endif; ?>

<div class="toast-container" id="toastContainer"></div>
<?php include __DIR__ . '/../../components/foot.php'; ?>