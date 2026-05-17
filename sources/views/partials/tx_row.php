<?php
/** @var array $tx; @var DateTimeImmutable $now; */
$fire_at = new DateTimeImmutable($tx['fire_at']);
$rel_txt = relative_time($fire_at, $now);
$rel_s   = $fire_at->getTimestamp() - $now->getTimestamp();
?>
<tr id="tx-<?= (int) $tx['id'] ?>" data-status="<?= h($tx['status']) ?>" class="row-<?= h($tx['status']) ?>">
  <td class="id" data-label="id">
    <a href="<?= h(url_for('/tx/' . (int) $tx['id'])) ?>">#<?= str_pad((string) $tx['id'], 3, '0', STR_PAD_LEFT) ?></a>
  </td>
  <td class="status" data-label="status"><span class="badge badge-<?= h($tx['status']) ?>"><?= h($tx['status']) ?></span></td>
  <td class="time" data-label="fire at">
    <time datetime="<?= h($fire_at->format('c')) ?>"><?= h($fire_at->format('Y-m-d H:i')) ?></time>
  </td>
  <td class="rel<?= $rel_s < 0 ? ' past' : '' ?>" data-label="rel"><?= h($rel_txt) ?></td>
  <td class="route" data-label="route"><?= h($tx['route'] ?? '—') ?></td>
  <td class="tail mono" data-label="result">
    <?php if ($tx['status'] === 'done' && $tx['txid']): ?>
      <a href="https://mempool.space/tx/<?= h($tx['txid']) ?>" target="_blank" rel="noopener"><?= h(substr($tx['txid'], 0, 18)) ?>…</a>
    <?php elseif ($tx['last_error']): ?>
      <span class="err" title="<?= h($tx['last_error']) ?>"><?= h(substr($tx['last_error'], 0, 50)) ?><?= strlen($tx['last_error']) > 50 ? '…' : '' ?></span>
    <?php else: ?>
      —
    <?php endif; ?>
  </td>
  <td class="actions" data-label="">
    <?php if ($tx['status'] === 'pending'): ?>
      <form hx-post="<?= h(url_for('/tx/' . (int) $tx['id'] . '/fire-now')) ?>" hx-target="#tx-<?= (int) $tx['id'] ?>" hx-swap="outerHTML" hx-confirm="Fire tx #<?= (int) $tx['id'] ?> immediately?" class="inline"><button class="btn-sm">fire now</button></form>
      <button class="btn-sm" type="button" onclick="document.getElementById('reschedule-form-<?= (int) $tx['id'] ?>').classList.toggle('hidden')">edit</button>
      <form hx-post="<?= h(url_for('/tx/' . (int) $tx['id'] . '/cancel')) ?>" hx-target="#tx-<?= (int) $tx['id'] ?>" hx-swap="outerHTML" hx-confirm="Cancel tx #<?= (int) $tx['id'] ?>?" class="inline"><button class="btn-sm btn-danger">cancel</button></form>
    <?php elseif (in_array($tx['status'], ['failed', 'missed'], true)): ?>
      <form hx-post="<?= h(url_for('/tx/' . (int) $tx['id'] . '/retry')) ?>" hx-target="#tx-<?= (int) $tx['id'] ?>" hx-swap="outerHTML" class="inline"><button class="btn-sm">retry</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php if ($tx['status'] === 'pending'): ?>
<tr id="reschedule-form-<?= (int) $tx['id'] ?>" class="hidden inline-form" data-status="reschedule">
  <td colspan="7">
    <form hx-post="<?= h(url_for('/tx/' . (int) $tx['id'] . '/reschedule')) ?>" hx-target="#tx-<?= (int) $tx['id'] ?>" hx-swap="outerHTML" class="reschedule">
      <label><input type="radio" name="mode" value="exact" checked> exact:
        <input type="datetime-local" name="fire_at">
      </label>
      <label><input type="radio" name="mode" value="random"> random in
        <input type="text" name="window_start" value="now" size="6">
        →
        <input type="text" name="window_end" value="+7d" size="6">
        gap <input type="text" name="min_gap" value="30m" size="5">
      </label>
      <button type="submit" class="btn-sm">save</button>
    </form>
  </td>
</tr>
<?php endif; ?>
