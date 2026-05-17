<?php /** @var string $title; @var string $message; */ ?>
<div class="page-title-row">
  <div>
    <span class="lede">// error</span>
    <h1 class="page-title"><?= h($title) ?></h1>
  </div>
</div>
<p style="color:var(--ink-dim);font-size:14px;margin-bottom:24px"><?= h($message) ?></p>
<p><a href="<?= h(url_for('/')) ?>" class="detail-back">← back to schedule</a></p>
