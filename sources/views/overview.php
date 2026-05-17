<?php /** @var array $txs; @var DateTimeImmutable $now; */ ?>
<?php if (!$txs): ?>
  <div class="empty">
    <p>// no scheduled transactions //</p>
    <a href="<?= h(url_for('/upload')) ?>" class="btn-primary">upload first tx</a>
  </div>
<?php else:
  $pending = 0; $done = 0; $other = 0;
  foreach ($txs as $t) {
    if ($t['status'] === 'pending')         $pending++;
    elseif ($t['status'] === 'done')        $done++;
    else                                    $other++;
  }
?>
<div class="page-title-row">
  <div>
    <span class="lede">// schedule</span>
    <h1 class="page-title"><?= $pending ?> pending<span style="color:var(--ink-mute);font-weight:400"> / <?= $done ?> done / <?= $other ?> other</span></h1>
  </div>
  <div class="filters">
    <label><input type="checkbox" id="show-done"> show fired / failed / missed</label>
  </div>
</div>

<table class="schedule"
       hx-get="<?= h(url_for('/')) ?>"
       hx-trigger="every 30s [document.visibilityState=='visible']"
       hx-target="body" hx-swap="innerHTML">
  <thead>
    <tr>
      <th>id</th><th>status</th><th>fire at (utc)</th><th>rel</th><th>route</th><th>txid / error</th><th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($txs as $tx): include __DIR__ . '/partials/tx_row.php'; endforeach; ?>
  </tbody>
</table>

<script>
(() => {
  const cb = document.getElementById('show-done');
  if (!cb) return;
  const KEY = 'btcpub.show-done';
  const apply = (on) => {
    document.querySelectorAll('tr[data-status]').forEach(tr => {
      const s = tr.dataset.status;
      if (s !== 'pending' && s !== 'firing') tr.style.display = on ? '' : 'none';
    });
  };
  cb.checked = localStorage.getItem(KEY) === '1';
  apply(cb.checked);
  cb.addEventListener('change', () => {
    localStorage.setItem(KEY, cb.checked ? '1' : '0');
    apply(cb.checked);
  });
})();
</script>
<?php endif; ?>
