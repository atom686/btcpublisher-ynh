<?php /** @var string $hex_tx; @var DateTimeImmutable $candidate; @var string $window_start, $window_end, $min_gap; */ ?>
<div class="preview-card">
  <h3>candidate fire-time</h3>
  <p><time datetime="<?= h($candidate->format('c')) ?>"><?= h($candidate->format('Y-m-d H:i:s')) ?> UTC</time></p>

  <div class="preview-actions">
    <form hx-post="<?= h(url_for('/random/commit')) ?>" hx-target="body" hx-swap="innerHTML" class="inline">
      <input type="hidden" name="hex_tx" value="<?= h($hex_tx) ?>">
      <input type="hidden" name="fire_at" value="<?= h($candidate->format('c')) ?>">
      <button class="btn-primary">accept</button>
    </form>

    <form hx-post="<?= h(url_for('/random/preview')) ?>" hx-target="#preview" hx-swap="innerHTML" class="inline">
      <input type="hidden" name="hex_tx" value="<?= h($hex_tx) ?>">
      <input type="hidden" name="window_start" value="<?= h($window_start) ?>">
      <input type="hidden" name="window_end" value="<?= h($window_end) ?>">
      <input type="hidden" name="min_gap" value="<?= h($min_gap) ?>">
      <button class="btn-sm">re-roll</button>
    </form>

    <button class="btn-sm" type="button" onclick="document.getElementById('preview').innerHTML=''">cancel</button>
  </div>
</div>
