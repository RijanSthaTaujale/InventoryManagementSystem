<?php
// pages/products/add.php  (admin only)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

$activePage = 'product';
$editId     = (int)($_GET['edit'] ?? 0);
$isEdit     = $editId > 0;
$pageTitle  = $isEdit ? 'Edit Product' : 'Add Product';

$product = [];
$photos  = [];
$variants = [];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$editId]);
    $product = $stmt->fetch();
    if (!$product) redirect('/pages/products/index.php');

    $photos   = $pdo->prepare("SELECT * FROM product_photos WHERE product_id=? ORDER BY sort_order");
    $photos->execute([$editId]);
    $photos   = $photos->fetchAll();

    $variants = $pdo->prepare("SELECT * FROM product_variants WHERE product_id=? ORDER BY id");
    $variants->execute([$editId]);
    $variants = $variants->fetchAll();
}

$categories = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    // Required fields
    $name     = trim($d['name'] ?? '');
    $sell_price = (float)($d['sell_price'] ?? 0);
    $buy_price  = (float)($d['buy_price']  ?? 0);
    $quantity   = (int)($d['quantity']     ?? 0);

    if (!$name)        { $error = 'Product name is required.'; }
    elseif (!$sell_price) { $error = 'Sell price is required.'; }
    else {
        // Auto product_id
        if (!$isEdit) {
            $last = $pdo->query("SELECT product_id FROM products ORDER BY id DESC LIMIT 1")->fetchColumn();
            $num  = $last ? (int)substr($last, 4) + 1 : 1;
            $product_id = 'PRD-' . str_pad($num, 4, '0', STR_PAD_LEFT);
        } else {
            $product_id = $product['product_id'];
        }

        // Slug
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if ($isEdit && $product['slug'] !== $slug) {
            $exists = $pdo->prepare("SELECT id FROM products WHERE slug=? AND id!=?");
            $exists->execute([$slug, $editId]);
            if ($exists->fetch()) $slug .= '-' . time();
        }

        // Stock status
        $min_stock = (int)($d['min_stock_level'] ?? 5);
        $stock_status = $quantity <= 0 ? 'outofstock'
            : ($quantity <= 2 ? 'critical'
            : ($quantity <= $min_stock ? 'lowstock' : 'instock'));

        $fields = [
            'name'            => $name,
            'slug'            => $slug,
            'category_id'     => ($d['category_id'] ?? null) ?: null,
            'brand'           => trim($d['brand'] ?? '') ?: null,
            'sku'             => trim($d['sku']   ?? '') ?: null,
            'description'     => trim($d['description'] ?? '') ?: null,
            'buy_price'       => $buy_price,
            'sell_price'      => $sell_price,
            'image_url'       => trim($d['image_url'] ?? '') ?: null,
            'quantity'        => $quantity,
            'min_stock_level' => $min_stock,
            'max_stock_level' => (int)($d['max_stock_level'] ?? 1000),
            'location'        => trim($d['location'] ?? '') ?: null,
            'weight'          => ($d['weight'] ?? '') !== '' ? (float)$d['weight'] : null,
            'stock_status'    => $stock_status,
            'status'          => $d['status'] ?? 'active',
            'features'        => trim($d['features'] ?? '') ?: null,
            'additional_info' => trim($d['additional_info'] ?? '') ?: null,
            'video_links'     => trim($d['video_links'] ?? '') ?: null,
        ];

        if ($isEdit) {
            $fields['updated_by'] = $user['id'];
            $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE products SET $sets WHERE id=?");
            $stmt->execute([...array_values($fields), $editId]);
            $savedId = $editId;

            // Log stock adjustment if qty changed
            if ((int)($product['quantity']) !== $quantity) {
                $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$savedId,'adjustment',$product['quantity'],$quantity-$product['quantity'],$quantity,'Manual edit',$user['id']]);
            }
        } else {
            $fields['product_id'] = $product_id;
            $fields['created_by'] = $user['id'];
            $cols = implode(',', array_keys($fields));
            $vals = implode(',', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO products ($cols) VALUES ($vals)");
            $stmt->execute(array_values($fields));
            $savedId = $pdo->lastInsertId();

            // Initial stock adjustment
            if ($quantity > 0) {
                $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$savedId,'initial',0,$quantity,$quantity,'Initial stock',$user['id']]);
            }
        }

        // Photos (simple URL list)
        if (!empty($d['photo_urls'])) {
            if ($isEdit) $pdo->prepare("DELETE FROM product_photos WHERE product_id=?")->execute([$savedId]);
            $urls = array_filter(array_map('trim', explode("\n", $d['photo_urls'])));
            foreach ($urls as $i => $url) {
                $pdo->prepare("INSERT INTO product_photos (product_id,url,sort_order,is_primary) VALUES (?,?,?,?)")
                    ->execute([$savedId, $url, $i, $i===0?1:0]);
            }
        }

        // Variants
        if ($isEdit) $pdo->prepare("DELETE FROM product_variants WHERE product_id=?")->execute([$savedId]);
        if (!empty($d['var_label'])) {
            foreach ($d['var_label'] as $i => $vLabel) {
                $vLabel = trim($vLabel);
                $vValue = trim($d['var_value'][$i] ?? '');
                if ($vLabel && $vValue) {
                    $pdo->prepare("INSERT INTO product_variants (product_id,label,value,price_adj) VALUES (?,?,?,?)")
                        ->execute([$savedId, $vLabel, $vValue, (float)($d['var_price'][$i] ?? 0)]);
                }
            }
        }

        $success = $isEdit ? 'Product updated successfully.' : 'Product added successfully.';
        if (!$isEdit) redirect('/pages/products/view.php?id=' . $savedId);

        // Refresh data for edit
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?"); $stmt->execute([$savedId]);
        $product = $stmt->fetch();
    }
}

$photoUrls = implode("\n", array_column($photos, 'url'));

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
            <a href="<?= APP_URL ?>/pages/products/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Products
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem;color:var(--text)"><?= $isEdit ? e($product['name']) : 'Add Product' ?></span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700"><?= $isEdit ? 'Edit Product' : 'Add Product' ?></h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Fill in the details below to <?= $isEdit ? 'update the' : 'add a new' ?> product</p>
        </div>
        <div style="display:flex;gap:8px">
          <?php if ($isEdit): ?>
          <a href="<?= APP_URL ?>/pages/products/view.php?id=<?= $editId ?>" class="btn btn-outline btn-sm">View</a>
          <?php endif; ?>
          <button type="submit" form="productForm" class="btn btn-primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
            <?= $isEdit ? 'Update Product' : 'Save Product' ?>
          </button>
        </div>
      </div>

      <?php if ($error): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;margin-bottom:16px;font-size:.88rem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= e($error) ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#ecfdf5;border:1px solid #86efac;border-radius:var(--radius-md);color:#065f46;margin-bottom:16px;font-size:.88rem">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?= e($success) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="productForm">
        <div style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">

          <!-- LEFT COLUMN -->
          <div style="display:flex;flex-direction:column;gap:16px">

            <!-- 1. Basic Info -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">1</div>
                <div class="card-title">Basic Information</div>
              </div>
              <div style="display:flex;flex-direction:column;gap:14px">
                <div class="grid-2" style="gap:12px">
                  <div class="form-group">
                    <label class="form-label">Product Name *</label>
                    <input type="text" name="name" class="form-control" value="<?= e($product['name'] ?? '') ?>" required placeholder="e.g. Wireless Headphones">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-control" value="<?= e($product['brand'] ?? '') ?>" placeholder="e.g. Sony">
                  </div>
                </div>
                <div class="grid-2" style="gap:12px">
                  <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-control">
                      <option value="">Select category</option>
                      <?php foreach ($categories as $c): ?>
                      <option value="<?= $c['id'] ?>" <?= ($product['category_id']??'')==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label">SKU</label>
                    <input type="text" name="sku" class="form-control" value="<?= e($product['sku'] ?? '') ?>" placeholder="e.g. SKU-001">
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Description</label>
                  <textarea name="description" class="form-control" rows="4" placeholder="Product description..."><?= e($product['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                  <label class="form-label">Image URL</label>
                  <input type="url" name="image_url" class="form-control" value="<?= e($product['image_url'] ?? '') ?>" placeholder="https://...">
                  <div class="form-hint">Main product image URL</div>
                </div>
              </div>
            </div>

            <!-- 3. Variants -->
            <div class="card">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">3</div>
                  <div class="card-title">Variants <span style="font-size:.78rem;font-weight:400;color:var(--text-muted)">(Optional)</span></div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="addVariant()">+ Add Variant</button>
              </div>
              <div id="variantRows" style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($variants as $v): ?>
                <div class="variant-row" style="display:grid;grid-template-columns:1fr 1fr 100px 32px;gap:8px;align-items:center">
                  <input type="text" name="var_label[]" class="form-control" value="<?= e($v['label']) ?>" placeholder="Label (e.g. Color)">
                  <input type="text" name="var_value[]" class="form-control" value="<?= e($v['value']) ?>" placeholder="Value (e.g. Red)">
                  <input type="number" name="var_price[]" class="form-control" value="<?= $v['price_adj'] ?>" placeholder="±Price" step="0.01">
                  <button type="button" onclick="this.closest('.variant-row').remove()" style="background:#fee2e2;border:none;color:#ef4444;border-radius:var(--radius-sm);width:32px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
                <?php endforeach; ?>
              </div>
              <?php if (empty($variants)): ?>
              <div id="noVariants" style="text-align:center;padding:20px;color:var(--text-muted);font-size:.83rem">No variants added</div>
              <?php endif; ?>
            </div>

            <!-- 4. Features -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">4</div>
                <div class="card-title">Features</div>
              </div>
              <div class="form-group">
                <textarea name="features" class="form-control" rows="4" placeholder="One feature per line&#10;e.g. Wireless Bluetooth 5.0&#10;30-hour battery life"><?= e($product['features'] ?? '') ?></textarea>
                <div class="form-hint">One feature per line</div>
              </div>
            </div>

            <!-- 5. Video Links -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">5</div>
                <div class="card-title">Video Links <span style="font-size:.78rem;font-weight:400;color:var(--text-muted)">(Optional)</span></div>
              </div>
              <div class="form-group">
                <textarea name="video_links" class="form-control" rows="3" placeholder="One URL per line&#10;https://youtube.com/..."><?= e($product['video_links'] ?? '') ?></textarea>
              </div>
            </div>

            <!-- 6. Additional Info -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">6</div>
                <div class="card-title">Additional Information</div>
              </div>
              <div class="form-group">
                <textarea name="additional_info" class="form-control" rows="3" placeholder="Delivery & Return info, warranty, etc."><?= e($product['additional_info'] ?? '') ?></textarea>
              </div>
            </div>

          </div>

          <!-- RIGHT COLUMN -->
          <div style="display:flex;flex-direction:column;gap:16px">

            <!-- 2. Inventory -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">2</div>
                <div class="card-title">Inventory Information</div>
              </div>
              <div style="display:flex;flex-direction:column;gap:12px">
                <?php if ($isEdit): ?>
                <div class="form-group">
                  <label class="form-label">Product ID</label>
                  <input type="text" class="form-control" value="<?= e($product['product_id']) ?>" disabled style="background:var(--bg)">
                </div>
                <?php endif; ?>
                <div class="grid-2" style="gap:10px">
                  <div class="form-group">
                    <label class="form-label">Quantity *</label>
                    <input type="number" name="quantity" class="form-control" value="<?= $product['quantity'] ?? 0 ?>" min="0" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Min Stock Level</label>
                    <input type="number" name="min_stock_level" class="form-control" value="<?= $product['min_stock_level'] ?? 5 ?>" min="0">
                  </div>
                </div>
                <div class="grid-2" style="gap:10px">
                  <div class="form-group">
                    <label class="form-label">Buy Price (Rs) *</label>
                    <input type="number" name="buy_price" class="form-control" value="<?= $product['buy_price'] ?? '' ?>" min="0" step="0.01" placeholder="0.00">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Sell Price (Rs) *</label>
                    <input type="number" name="sell_price" class="form-control" value="<?= $product['sell_price'] ?? '' ?>" min="0" step="0.01" placeholder="0.00" required>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Location</label>
                  <input type="text" name="location" class="form-control" value="<?= e($product['location'] ?? '') ?>" placeholder="e.g. Shelf A1">
                </div>
                <div class="form-group">
                  <label class="form-label">Weight (kg)</label>
                  <input type="number" name="weight" class="form-control" value="<?= $product['weight'] ?? '' ?>" min="0" step="0.001" placeholder="0.000">
                </div>
                <div class="form-group">
                  <label class="form-label">Status</label>
                  <select name="status" class="form-control">
                    <option value="active"       <?= ($product['status']??'active')==='active'?'selected':'' ?>>Active</option>
                    <option value="inactive"     <?= ($product['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
                    <option value="discontinued" <?= ($product['status']??'')==='discontinued'?'selected':'' ?>>Discontinued</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Product Photos -->
            <div class="card">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
                <div style="width:24px;height:24px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700">7</div>
                <div class="card-title">Product Photos</div>
              </div>
              <div class="form-group">
                <label class="form-label">Photo URLs (one per line)</label>
                <textarea name="photo_urls" class="form-control" rows="5" placeholder="https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg"><?= e($photoUrls) ?></textarea>
                <div class="form-hint">First URL = primary photo</div>
              </div>
              <?php if (!empty($photos)): ?>
              <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
                <?php foreach ($photos as $ph): ?>
                <div style="width:56px;height:56px;border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);background:var(--bg)">
                  <img src="<?= e($ph['url']) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none'">
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>

          </div>
        </div>
      </form>

    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
function addVariant() {
  document.getElementById('noVariants')?.remove();
  const row = document.createElement('div');
  row.className = 'variant-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 100px 32px;gap:8px;align-items:center';
  row.innerHTML = `
    <input type="text" name="var_label[]" class="form-control" placeholder="Label (e.g. Color)">
    <input type="text" name="var_value[]" class="form-control" placeholder="Value (e.g. Red)">
    <input type="number" name="var_price[]" class="form-control" placeholder="±Price" step="0.01" value="0">
    <button type="button" onclick="this.closest('.variant-row').remove()" style="background:#fee2e2;border:none;color:#ef4444;border-radius:var(--radius-sm);width:32px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>`;
  document.getElementById('variantRows').appendChild(row);
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>