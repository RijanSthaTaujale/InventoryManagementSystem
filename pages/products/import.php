<?php
// pages/products/import.php  — admin only
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';
require_once __DIR__ . '/../../config/uploads.php';

$importStagingDir = __DIR__ . '/../../assets/uploads/products/_import_staging/' . session_id();

// Removes any staged (not-yet-confirmed) bulk-import images for this session
function clearImportStaging(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') as $f) { @unlink($f); }
    @rmdir($dir);
}

$user = currentUser();
if ($user['role'] !== 'admin') redirect('/pages/dashboard.php');

if (($_GET['action'] ?? '') === 'reupload') {
    clearImportStaging($importStagingDir);
    unset($_SESSION['import_rows'], $_SESSION['import_images']);
    redirect('/pages/products/import.php');
}

$activePage = 'product';
$pageTitle  = 'Import Products';
$currency   = 'Rs';

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$catMap     = array_column($categories, 'id', 'name'); // name → id

$error   = '';
$success = '';
$preview = [];
$csvRows = [];

// ── Handle CSV upload for preview ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    if (empty($_FILES['csv_file']['tmp_name'])) {
        $error = 'Please select a CSV file.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $ext  = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only CSV files are supported. Save your spreadsheet as CSV first.';
        } else {
            $handle = fopen($file, 'r');
            $header = fgetcsv($handle);
            // Normalize headers
            $header = array_map(fn($h) => strtolower(trim(str_replace([' ','-'], '_', $h))), $header);

            $required = ['name', 'sell_price'];
            $missing  = array_diff($required, $header);
            if (!empty($missing)) {
                $error = 'Missing required columns: ' . implode(', ', $missing) . '. See the template below.';
                fclose($handle);
            } else {
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 2) continue;
                    $data = array_combine($header, array_pad($row, count($header), ''));
                    $csvRows[] = $data;
                }
                fclose($handle);

                if (empty($csvRows)) {
                    $error = 'The CSV file has no data rows.';
                } else {
                    // Stage any bulk-uploaded images, keyed by their (sanitized) original
                    // filename, so the confirm step can match them to CSV rows via image_filename.
                    clearImportStaging($importStagingDir);
                    $stagedImages = [];
                    if (!empty($_FILES['images']['tmp_name'])) {
                        $count = count($_FILES['images']['tmp_name']);
                        for ($i = 0; $i < $count; $i++) {
                            $f = [
                                'name'     => $_FILES['images']['name'][$i],
                                'type'     => $_FILES['images']['type'][$i],
                                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                                'error'    => $_FILES['images']['error'][$i],
                                'size'     => $_FILES['images']['size'][$i],
                            ];
                            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                            $check = validateImageUpload($f);
                            if (!$check['ok']) continue;
                            if (!is_dir($importStagingDir)) mkdir($importStagingDir, 0755, true);
                            $safeName = sanitizeFilename($f['name']);
                            if (move_uploaded_file($f['tmp_name'], $importStagingDir . '/' . $safeName)) {
                                $stagedImages[$safeName] = true;
                            }
                        }
                    }
                    $_SESSION['import_images'] = $stagedImages;

                    // Store in session for confirm step
                    $_SESSION['import_rows'] = $csvRows;
                    $preview = $csvRows;
                }
            }
        }
    }
}

// ── Handle confirm import ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    $rows         = $_SESSION['import_rows'] ?? [];
    $stagedImages = $_SESSION['import_images'] ?? [];
    $permDir      = __DIR__ . '/../../assets/uploads/products';
    $ok      = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $name       = trim($row['name'] ?? '');
        $sell_price = (float)($row['sell_price'] ?? 0);
        if (!$name || !$sell_price) { $skipped++; continue; }

        $buy_price  = (float)($row['buy_price']       ?? 0);
        $quantity   = (int)($row['quantity']           ?? 0);
        $brand      = trim($row['brand']               ?? '') ?: null;
        $sku        = trim($row['sku']                 ?? '') ?: null;
        $category   = trim($row['category']            ?? '');
        $location   = trim($row['location']            ?? '') ?: null;
        $min_stock  = (int)($row['min_stock_level']    ?? 5) ?: 5;
        $image_url  = trim($row['image_url']           ?? '') ?: null;
        $desc       = trim($row['description']         ?? '') ?: null;

        // Bulk-uploaded image takes priority over an external image_url
        $imgFilename = sanitizeFilename(trim($row['image_filename'] ?? ''));
        if ($imgFilename !== '' && isset($stagedImages[$imgFilename])) {
            $stagedPath = $importStagingDir . '/' . $imgFilename;
            $ext        = strtolower(pathinfo($imgFilename, PATHINFO_EXTENSION));
            $finalName  = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (is_dir($permDir) || mkdir($permDir, 0755, true)) {
                if (rename($stagedPath, $permDir . '/' . $finalName)) {
                    $image_url = '/assets/uploads/products/' . $finalName;
                }
            }
        }

        // Category lookup
        $cat_id = null;
        if ($category) {
            // case-insensitive match
            foreach ($categories as $c) {
                if (strtolower($c['name']) === strtolower($category)) { $cat_id = $c['id']; break; }
            }
        }

        // Auto product_id
        $last       = $pdo->query("SELECT product_id FROM products ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num        = $last ? (int)substr($last, 4) + 1 : 1;
        $product_id = 'PRD-' . str_pad($num, 4, '0', STR_PAD_LEFT);

        // Slug
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        $exists = $pdo->prepare("SELECT id FROM products WHERE slug=?");
        $exists->execute([$slug]);
        if ($exists->fetch()) $slug .= '-' . time() . rand(0,99);

        // Skip duplicate SKU
        if ($sku) {
            $dupSku = $pdo->prepare("SELECT id FROM products WHERE sku=?");
            $dupSku->execute([$sku]);
            if ($dupSku->fetch()) { $skipped++; continue; }
        }

        $stock_status = $quantity <= 0 ? 'outofstock'
            : ($quantity <= 2 ? 'critical'
            : ($quantity <= $min_stock ? 'lowstock' : 'instock'));

        $stmt = $pdo->prepare("
            INSERT INTO products
                (product_id, name, slug, category_id, brand, sku, description,
                 buy_price, sell_price, image_url, quantity, min_stock_level,
                 location, stock_status, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?)
        ");
        $stmt->execute([
            $product_id, $name, $slug, $cat_id, $brand, $sku, $desc,
            $buy_price, $sell_price, $image_url, $quantity, $min_stock,
            $location, $stock_status, $user['id']
        ]);
        $newId = $pdo->lastInsertId();

        // Log initial stock
        if ($quantity > 0) {
            $pdo->prepare("INSERT INTO stock_adjustments (product_id,type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$newId, 'initial', 0, $quantity, $quantity, 'CSV import', $user['id']]);
        }

        $ok++;
    }

    clearImportStaging($importStagingDir);
    unset($_SESSION['import_rows'], $_SESSION['import_images']);
    $success = "$ok product" . ($ok != 1 ? 's' : '') . " imported successfully." . ($skipped ? " $skipped row(s) skipped (missing name/price or duplicate SKU)." : '');
    $preview = [];
}

// Restore preview from session if available (page reload)
if (empty($preview) && !empty($_SESSION['import_rows']) && empty($success)) {
    $preview = $_SESSION['import_rows'];
}

include __DIR__ . '/../../components/head.php';
?>
<div class="app-shell">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div style="flex:1;display:flex;flex-direction:column">
    <?php include __DIR__ . '/../../components/topbar.php'; ?>
    <main class="main-content">

      <!-- Header -->
      <div class="flex-between mb-4">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <a href="<?= APP_URL ?>/pages/products/index.php" style="color:var(--text-muted);font-size:.82rem;display:flex;align-items:center;gap:4px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Products
            </a>
            <span style="color:var(--text-muted);font-size:.82rem">/</span>
            <span style="font-size:.82rem">Import CSV</span>
          </div>
          <h1 style="font-size:1.25rem;font-weight:700">Bulk Import Products</h1>
          <p style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Upload a CSV file to add multiple products at once</p>
        </div>
        <a href="<?= APP_URL ?>/pages/products/index.php" class="btn btn-outline btn-sm">← Back</a>
      </div>

      <!-- Alerts -->
      <?php if ($error): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-md);color:#b91c1c;font-size:.88rem;margin-bottom:16px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= e($error) ?>
      </div>
      <?php endif; ?>
      <?php if ($success): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#ecfdf5;border:1px solid #86efac;border-radius:var(--radius-md);color:#065f46;font-size:.88rem;margin-bottom:16px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?= e($success) ?>
        <a href="<?= APP_URL ?>/pages/products/index.php" style="margin-left:auto;font-weight:600;color:#065f46">View Products →</a>
      </div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start">

        <!-- LEFT: upload + preview -->
        <div style="display:flex;flex-direction:column;gap:16px">

          <!-- Upload card -->
          <?php if (empty($preview)): ?>
          <div class="card">
            <div class="card-title" style="margin-bottom:4px">Upload CSV File</div>
            <p style="font-size:.83rem;color:var(--text-secondary);margin-bottom:18px">Select your CSV spreadsheet. The first row must be the header.</p>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
              <input type="hidden" name="action" value="preview">
              <div id="dropZone"
                   style="border:2px dashed var(--border);border-radius:var(--radius-lg);padding:40px;text-align:center;cursor:pointer;transition:border-color var(--transition)"
                   onclick="document.getElementById('csvInput').click()"
                   ondragover="event.preventDefault();this.style.borderColor='var(--primary)'"
                   ondragleave="this.style.borderColor='var(--border)'"
                   ondrop="handleDrop(event)">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin:0 auto 10px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div style="font-size:.9rem;font-weight:600;margin-bottom:4px">Click or drag & drop CSV here</div>
                <div style="font-size:.8rem;color:var(--text-muted)" id="fileLabel">Supports .csv files only</div>
              </div>
              <input type="file" id="csvInput" name="csv_file" accept=".csv" style="display:none" onchange="showFileName(this)">

              <div style="margin-top:16px" class="form-group">
                <label class="form-label">Product Images (optional)</label>
                <input type="file" name="images[]" accept=".jpg,.jpeg,.png,.gif,.webp" multiple class="form-control">
                <div class="form-hint">Select all product image files here, then put each file's exact name in the <code>image_filename</code> CSV column to link it to that row.</div>
              </div>

              <div style="margin-top:14px;display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary" id="previewBtn" disabled>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  Preview Import
                </button>
              </div>
            </form>
          </div>

          <?php else: ?>

          <!-- Preview table -->
          <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
              <div>
                <div class="card-title">Preview — <?= count($preview) ?> row<?= count($preview)!=1?'s':'' ?></div>
                <div style="font-size:.82rem;color:var(--text-secondary);margin-top:2px">Review before confirming. Rows with missing name or sell price will be skipped.</div>
              </div>
              <a href="<?= APP_URL ?>/pages/products/import.php?action=reupload" class="btn btn-outline btn-sm">← Re-upload</a>
            </div>

            <div style="overflow-x:auto">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>SKU</th>
                    <th>Qty</th>
                    <th>Buy Price</th>
                    <th>Sell Price</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview as $i => $row):
                    $hasName  = !empty(trim($row['name'] ?? ''));
                    $hasPrice = !empty($row['sell_price'] ?? '');
                    $isValid  = $hasName && $hasPrice;
                  ?>
                  <tr style="<?= !$isValid ? 'opacity:.45' : '' ?>">
                    <td style="color:var(--text-muted)"><?= $i + 1 ?></td>
                    <td style="font-weight:600">
                      <?= e(trim($row['name'] ?? '—')) ?>
                      <?php if (!$isValid): ?>
                      <span style="font-size:.7rem;color:#ef4444;margin-left:4px">will skip</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= e($row['category'] ?? '—') ?></td>
                    <td class="text-muted"><?= e($row['brand']    ?? '—') ?></td>
                    <td class="text-muted"><?= e($row['sku']      ?? '—') ?></td>
                    <td style="font-weight:600"><?= (int)($row['quantity'] ?? 0) ?></td>
                    <td class="text-muted"><?= $currency ?> <?= number_format((float)($row['buy_price'] ?? 0), 0) ?></td>
                    <td style="font-weight:600"><?= $currency ?> <?= number_format((float)($row['sell_price'] ?? 0), 0) ?></td>
                    <td>
                      <?php $qty = (int)($row['quantity'] ?? 0); ?>
                      <span class="badge <?= $qty <= 0 ? 'badge-critical' : ($qty <= 5 ? 'badge-lowstock' : 'badge-instock') ?>">
                        <?= $qty <= 0 ? 'Out of Stock' : ($qty <= 5 ? 'Low' : 'In Stock') ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <form method="POST" style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
              <input type="hidden" name="action" value="import">
              <a href="<?= APP_URL ?>/pages/products/import.php?action=reupload" class="btn btn-outline btn-sm">Cancel</a>
              <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Confirm & Import <?= count($preview) ?> Product<?= count($preview)!=1?'s':'' ?>
              </button>
            </form>
          </div>
          <?php endif; ?>

        </div>

        <!-- RIGHT: instructions + template -->
        <div style="display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--topbar-h)+24px)">

          <!-- CSV template download -->
          <div class="card">
            <div class="card-title" style="margin-bottom:10px">CSV Template</div>
            <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:12px">Download a ready-to-fill template with all supported columns.</p>
            <button onclick="downloadTemplate()" class="btn btn-outline btn-sm" style="width:100%">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Download Template
            </button>
          </div>

          <!-- Column reference -->
          <div class="card">
            <div class="card-title" style="margin-bottom:12px">Column Reference</div>
            <div style="display:flex;flex-direction:column;gap:8px">
              <?php
              $cols = [
                ['name',            'text',   true,  'Product name'],
                ['sell_price',      'number', true,  'Selling price in Rs'],
                ['buy_price',       'number', false, 'Cost / buy price in Rs'],
                ['quantity',        'number', false, 'Initial stock quantity'],
                ['category',        'text',   false, 'Must match an existing category name'],
                ['brand',           'text',   false, 'Brand name'],
                ['sku',             'text',   false, 'Unique SKU code'],
                ['description',     'text',   false, 'Product description'],
                ['image_filename',  'text',   false, 'Exact filename of an image uploaded in the box above (e.g. headphones.jpg)'],
                ['image_url',       'url',    false, 'External image URL — used only if image_filename is empty'],
                ['location',        'text',   false, 'Warehouse location (e.g. Shelf A1)'],
                ['min_stock_level', 'number', false, 'Low stock alert threshold (default 5)'],
              ];
              foreach ($cols as [$col, $type, $req, $desc]):
              ?>
              <div style="font-size:.8rem">
                <div style="display:flex;align-items:center;gap:6px">
                  <code style="background:var(--bg);padding:1px 6px;border-radius:4px;font-size:.75rem"><?= $col ?></code>
                  <?php if ($req): ?><span style="font-size:.68rem;color:#ef4444;font-weight:700">required</span><?php endif; ?>
                  <span style="color:var(--text-muted);font-size:.72rem"><?= $type ?></span>
                </div>
                <div style="color:var(--text-secondary);margin-top:2px;padding-left:2px"><?= $desc ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Available categories -->
          <div class="card">
            <div class="card-title" style="margin-bottom:10px">Available Categories</div>
            <div style="display:flex;flex-wrap:wrap;gap:5px">
              <?php foreach ($categories as $c): ?>
              <span style="background:var(--bg);padding:3px 9px;border-radius:9999px;font-size:.75rem;font-weight:500"><?= e($c['name']) ?></span>
              <?php endforeach; ?>
            </div>
          </div>

        </div>
      </div>

    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
function showFileName(input) {
  const label = document.getElementById('fileLabel');
  if (input.files.length) {
    label.textContent = input.files[0].name;
    label.style.color = 'var(--primary)';
    document.getElementById('previewBtn').disabled = false;
  }
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').style.borderColor = 'var(--border)';
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const input = document.getElementById('csvInput');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  showFileName(input);
}

function downloadTemplate() {
  const headers = ['name','sell_price','buy_price','quantity','category','brand','sku','description','image_filename','image_url','location','min_stock_level'];
  const example = ['Wireless Headphones','3500','1800','10','Electronics','Sony','SON-001','Premium wireless headphones','headphones.jpg','','Shelf A1','5'];
  const csv = [headers.join(','), example.join(',')].join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'products_import_template.csv';
  a.click();
}
</script>
<?php include __DIR__ . '/../../components/foot.php'; ?>