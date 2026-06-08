<?php
// components/pagination.php
// Usage: $page, $totalPages, $baseUrl (with ? params already appended) required
$page       = $page ?? 1;
$totalPages = $totalPages ?? 1;
$baseUrl    = $baseUrl ?? '?';
if ($totalPages <= 1) return;
$sep = strpos($baseUrl, '?') !== false ? '&' : '?';
?>
<div class="pagination">
  <a href="<?= $baseUrl . $sep ?>page=<?= max(1, $page-1) ?>"
     class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
  </a>

  <?php
  $range = 2;
  $start = max(1, $page - $range);
  $end   = min($totalPages, $page + $range);
  if ($start > 1): ?>
    <a href="<?= $baseUrl . $sep ?>page=1" class="page-btn">1</a>
    <?php if ($start > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= $baseUrl . $sep ?>page=<?= $i ?>"
       class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $totalPages): ?>
    <?php if ($end < $totalPages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
    <a href="<?= $baseUrl . $sep ?>page=<?= $totalPages ?>" class="page-btn"><?= $totalPages ?></a>
  <?php endif; ?>

  <a href="<?= $baseUrl . $sep ?>page=<?= min($totalPages, $page+1) ?>"
     class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
  </a>
</div>