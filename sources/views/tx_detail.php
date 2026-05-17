<?php
/** @var array $tx; @var ?array $decoded; @var ?int $total_out_sat; */
$fire_at = new DateTimeImmutable($tx['fire_at']);
?>
<a href="<?= h(url_for('/')) ?>" class="detail-back">← schedule</a>

<div class="detail-header">
  <h1>#<?= str_pad((string) $tx['id'], 3, '0', STR_PAD_LEFT) ?></h1>
  <span class="badge badge-<?= h($tx['status']) ?>"><?= h($tx['status']) ?></span>
</div>

<dl class="meta">
  <dt>fire at</dt><dd><time datetime="<?= h($fire_at->format('c')) ?>"><?= h($fire_at->format('Y-m-d H:i:s')) ?> UTC</time></dd>
  <?php if (!empty($tx['created_at'])): ?>
  <dt>created</dt><dd><time><?= h((new DateTimeImmutable($tx['created_at']))->format('Y-m-d H:i:s')) ?> UTC</time></dd>
  <?php endif; ?>
  <?php if (!empty($tx['fired_at'])): ?>
  <dt>fired</dt><dd><time><?= h((new DateTimeImmutable($tx['fired_at']))->format('Y-m-d H:i:s')) ?> UTC</time></dd>
  <?php endif; ?>
  <dt>attempts</dt><dd><?= (int) $tx['attempts'] ?></dd>
  <?php if (!empty($tx['route'])): ?><dt>route</dt><dd><?= h($tx['route']) ?></dd><?php endif; ?>
  <?php if (!empty($tx['txid'])): ?>
  <dt>txid</dt><dd><a href="https://mempool.space/tx/<?= h($tx['txid']) ?>" target="_blank" rel="noopener"><?= h($tx['txid']) ?></a></dd>
  <?php endif; ?>
  <?php if (!empty($tx['last_error'])): ?>
  <dt>last error</dt><dd class="mono" style="color:var(--err)"><?= h($tx['last_error']) ?></dd>
  <?php endif; ?>
</dl>

<?php if ($decoded): ?>
<section>
  <h2>decoded</h2>
  <dl class="meta">
    <dt>computed txid</dt><dd><?= h($decoded['txid']) ?></dd>
    <dt>version</dt><dd><?= (int) $decoded['version'] ?></dd>
    <dt>segwit</dt><dd><?= $decoded['is_segwit'] ? 'yes' : 'no' ?></dd>
    <dt>size</dt><dd><?= (int) $decoded['size'] ?> B</dd>
    <dt>locktime</dt><dd><?= (int) $decoded['locktime'] ?></dd>
    <dt>inputs</dt><dd><?= count($decoded['inputs']) ?></dd>
    <dt>outputs</dt><dd><?= count($decoded['outputs']) ?> <span style="color:var(--ink-mute)">/ total <?= number_format($total_out_sat) ?> sat</span></dd>
  </dl>

  <h3>inputs</h3>
  <ol class="ios">
    <?php foreach ($decoded['inputs'] as $i): ?>
    <li><span class="mono"><?= h($i['prevout_txid']) ?></span>:<?= (int) $i['prevout_vout'] ?>
        <span class="seq">seq=0x<?= sprintf('%08x', $i['sequence']) ?></span></li>
    <?php endforeach; ?>
  </ol>

  <h3>outputs</h3>
  <ol class="ios">
    <?php foreach ($decoded['outputs'] as $o): ?>
    <li>
      <strong><?= number_format($o['value_sat']) ?></strong> <span style="color:var(--ink-mute);font-size:11px">sat</span>
      <span class="badge"><?= h($o['kind']) ?></span>
      <?php if (!empty($o['address_or_data'])): ?>
      <span class="mono" style="font-size:11.5px;color:var(--ink-dim);word-break:break-all"><?= h($o['address_or_data']) ?></span>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<section>
  <h2>raw hex</h2>
  <pre class="hex"><?= h($tx['hex']) ?></pre>
</section>
