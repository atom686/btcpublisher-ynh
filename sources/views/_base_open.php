<?php $title = $title ?? 'btcpublisher'; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0a0908">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(url_for('/assets/style.css')) ?>">
<script src="https://unpkg.com/htmx.org@2.0.4" integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+" crossorigin="anonymous"></script>
</head>
<body>

<div class="status-strip" id="status-strip"
     hx-get="<?= h(url_for('/api/status.json')) ?>"
     hx-trigger="load, every 15s [document.visibilityState=='visible']"
     hx-swap="none"
     hx-on::after-request="renderStatus(event)">
  <span class="seg"><span class="dot" id="dot-tor"></span><strong>tor</strong></span>
  <span class="seg"><strong>pending</strong> <span class="v" id="s-pending">—</span></span>
  <span class="seg"><strong>fired</strong> <span class="v" id="s-done">—</span></span>
  <span class="seg signal"><strong>next</strong> <span class="v" id="s-next">—</span></span>
  <span class="spacer-flex"></span>
  <span class="seg"><strong>op</strong> <span class="v"><?= h($user ?? '—') ?></span></span>
</div>
<script>
function renderStatus(e) {
  try {
    const d = JSON.parse(e.detail.xhr.responseText);
    document.getElementById('dot-tor').className = 'dot ' + (d.tor ? 'live' : 'dead');
    document.getElementById('s-pending').textContent = d.summary.pending;
    document.getElementById('s-done').textContent    = d.summary.done;
    const nxt = d.summary.next_fire;
    if (nxt) {
      const s = nxt.rel_seconds, abs = Math.abs(s);
      let txt;
      if (abs < 60)        txt = s + 's';
      else if (abs < 3600) txt = Math.round(s/60) + 'm';
      else if (abs < 86400)txt = (s/3600).toFixed(1) + 'h';
      else                 txt = (s/86400).toFixed(1) + 'd';
      document.getElementById('s-next').textContent = (s < 0 ? 'overdue ' : 'in ') + txt;
    } else { document.getElementById('s-next').textContent = '—'; }
  } catch (err) {}
}
</script>

<header class="bar">
  <a href="<?= h(url_for('/')) ?>" class="brand">btcpublisher<span class="sub">// scheduled broadcaster</span></a>
  <span class="spacer"></span>
  <a href="<?= h(url_for('/upload')) ?>" class="nav-link">+ new</a>
  <a href="<?= h(url_for('/settings')) ?>" class="nav-link">settings</a>
</header>

<main>
