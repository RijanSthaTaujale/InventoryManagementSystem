<?php
// pages/orders/bill.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/auth_guard.php';

$orderId = trim($_GET['id'] ?? '');
if (!$orderId) redirect('/pages/orders/index.php');

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) redirect('/pages/orders/index.php');

$items = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$items->execute([$order['id']]);
$items = $items->fetchAll();

$currency = 'Rs';

$bizName    = getSetting('business_name', 'Pompoy Apparels');
$bizPan     = getSetting('business_pan', '126106185');
$bizAddress = getSetting('business_address', 'Kalanki, Kathmandu');
$bizPhone   = getSetting('business_phone', '9802377999');
$bizEmail   = getSetting('business_email', 'sochejastai@gmail.com');
$bizLogo    = getSetting('business_logo', '/assets/images/business-logo.png');

// Converts an integer amount to English words, e.g. 1750 -> "One Thousand Seven Hundred Fifty"
function numberToWords(int $num): string {
    if ($num === 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
              'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
              'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $threeDigits = function (int $n) use ($ones, $tens): string {
        $parts = [];
        if ($n >= 100) { $parts[] = $ones[intdiv($n, 100)] . ' Hundred'; $n %= 100; }
        if ($n >= 20)  { $parts[] = $tens[intdiv($n, 10)]; $n %= 10; }
        if ($n > 0)    { $parts[] = $ones[$n]; }
        return implode(' ', $parts);
    };

    $groups = [[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand'], [1, '']];
    $words = [];
    foreach ($groups as [$value, $label]) {
        if ($num >= $value) {
            $chunk = intdiv($num, $value);
            $num  %= $value;
            $words[] = trim($threeDigits($chunk) . ' ' . $label);
        }
    }
    return trim(implode(' ', $words));
}

$subtotal = array_sum(array_column($items, 'total'));
$amountWords = numberToWords((int)round($order['total'])) . ' Only';

$pageTitle = 'Bill — ' . $order['order_id'];
include __DIR__ . '/../../components/head.php';
?>
<style>
  body { background: #e2e8f0; }
  .bill-toolbar { max-width: 760px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; padding: 0 4px; }
  .bill-sheet { max-width: 760px; margin: 0 auto 40px; background: #fff; box-shadow: var(--shadow-md); border-radius: var(--radius-lg); overflow: hidden; }
  .bill-header { background: #002060; color: #fff; padding: 28px 36px; display: flex; justify-content: space-between; align-items: center; gap: 20px; }
  .bill-header img { width: 74px; height: 74px; object-fit: contain; background: #fff; border-radius: 10px; padding: 6px; }
  .bill-header .biz-meta { font-size: .78rem; line-height: 1.7; opacity: .92; }
  .bill-header .biz-contact { text-align: right; font-size: .82rem; line-height: 1.9; white-space: nowrap; }
  .bill-body { padding: 32px 36px; }
  .bill-title { font-size: 1.6rem; font-weight: 800; color: #002060; letter-spacing: .05em; margin-bottom: 6px; }
  .bill-meta-row { display: flex; justify-content: space-between; font-size: .86rem; color: #334155; margin-bottom: 22px; }
  .bill-to-label { font-size: .74rem; font-weight: 700; letter-spacing: .06em; color: #94a3b8; margin-bottom: 4px; }
  .bill-to-name { font-size: 1.02rem; font-weight: 700; color: #0f172a; }
  .bill-table { width: 100%; border-collapse: collapse; margin: 22px 0; font-size: .86rem; }
  .bill-table th { background: #002060; color: #fff; text-align: left; padding: 10px 12px; font-size: .78rem; letter-spacing: .03em; }
  .bill-table th.num, .bill-table td.num { text-align: right; }
  .bill-table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
  .bill-totals { margin-left: auto; width: 260px; font-size: .88rem; }
  .bill-totals div { display: flex; justify-content: space-between; padding: 5px 0; }
  .bill-totals .grand { border-top: 2px solid #002060; margin-top: 6px; padding-top: 10px; font-weight: 800; font-size: 1.05rem; color: #002060; }
  .bill-words { margin-top: 18px; font-size: .84rem; color: #334155; }
  .bill-footer { text-align: center; padding: 20px; font-weight: 700; color: #002060; letter-spacing: .04em; border-top: 1px dashed #cbd5e1; margin-top: 10px; }
  @media print {
    body { background: #fff; }
    .bill-toolbar { display: none; }
    .bill-sheet { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; }
    @page { margin: 12mm; }
  }
</style>

<div class="bill-toolbar">
  <a href="<?= APP_URL ?>/pages/orders/view.php?id=<?= urlencode($order['order_id']) ?>" class="btn btn-outline btn-sm">← Back to Order</a>
  <button class="btn btn-primary btn-sm" onclick="window.print()">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print / Save as PDF
  </button>
</div>

<div class="bill-sheet">
  <div class="bill-header">
    <div style="display:flex;align-items:center;gap:16px">
      <img src="<?= APP_URL . e($bizLogo) ?>" alt="<?= e($bizName) ?>">
      <div class="biz-meta">
        <div style="font-size:1.1rem;font-weight:800;margin-bottom:4px"><?= e($bizName) ?></div>
        <div>PAN No: <?= e($bizPan) ?></div>
        <div><?= e($bizAddress) ?></div>
      </div>
    </div>
    <div class="biz-contact">
      <div>☎ <?= e($bizPhone) ?></div>
      <div>✉ <?= e($bizEmail) ?></div>
    </div>
  </div>

  <div class="bill-body">
    <div class="bill-title">INVOICE</div>
    <div class="bill-meta-row">
      <div>Invoice No: <strong><?= e($order['order_id']) ?></strong></div>
      <div>Invoice Date: <strong><?= date('F j, Y', strtotime($order['created_at'])) ?></strong></div>
    </div>

    <div class="bill-to-label">BILL TO</div>
    <div class="bill-to-name"><?= e($order['customer_name']) ?></div>
    <?php if ($order['customer_phone']): ?><div style="font-size:.84rem;color:#475569"><?= e($order['customer_phone']) ?></div><?php endif; ?>
    <?php if ($order['customer_address']): ?><div style="font-size:.84rem;color:#475569"><?= nl2br(e($order['customer_address'])) ?></div><?php endif; ?>

    <table class="bill-table">
      <thead>
        <tr>
          <th>Details</th>
          <th class="num">Qty</th>
          <th class="num">Price</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?><?= $it['variant_info'] ? ' — ' . e($it['variant_info']) : '' ?></td>
          <td class="num"><?= $it['qty'] ?></td>
          <td class="num"><?= $currency ?> <?= number_format($it['sell_price'], 2) ?></td>
          <td class="num"><?= $currency ?> <?= number_format($it['total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="bill-totals">
      <div><span>Subtotal</span><span><?= $currency ?> <?= number_format($subtotal, 2) ?></span></div>
      <?php if ($order['discount'] > 0): ?>
      <div><span>Discount</span><span>- <?= $currency ?> <?= number_format($order['discount'], 2) ?></span></div>
      <?php endif; ?>
      <?php if ($order['shipping_cost'] > 0): ?>
      <div><span>Shipping</span><span><?= $currency ?> <?= number_format($order['shipping_cost'], 2) ?></span></div>
      <?php endif; ?>
      <div class="grand"><span>Total</span><span><?= $currency ?> <?= number_format($order['total'], 2) ?></span></div>
    </div>

    <div class="bill-words">In words: <?= e($amountWords) ?></div>

    <div class="bill-footer">THANK YOU FOR YOUR BUSINESS!</div>
  </div>
</div>
<?php include __DIR__ . '/../../components/foot.php'; ?>
