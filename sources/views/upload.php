<div class="page-title-row">
  <div>
    <span class="lede">// new transaction</span>
    <h1 class="page-title">upload</h1>
  </div>
</div>

<div class="tabs">
  <button class="tab active" data-tab="single">single tx</button>
  <button class="tab" data-tab="bulk">bulk (paste many)</button>
</div>

<section id="tab-single" class="tab-panel active">
  <form hx-post="<?= h(url_for('/upload/single')) ?>" hx-target="#preview" hx-swap="innerHTML" class="upload-form">
    <label>raw signed tx hex
      <textarea name="hex_tx" rows="5" placeholder="0200000001..." required autocomplete="off" spellcheck="false"></textarea>
    </label>
    <fieldset>
      <legend>schedule mode</legend>
      <label class="radio-row">
        <input type="radio" name="mode" value="exact">
        <span>exact time:</span>
        <input type="datetime-local" name="fire_at">
      </label>
      <label class="radio-row">
        <input type="radio" name="mode" value="random" checked>
        <span>random within:</span>
        <input type="text" name="window_start" value="now" size="6">
        →
        <input type="text" name="window_end" value="+7d" size="6">
        <span>min-gap</span>
        <input type="text" name="min_gap" value="30m" size="5">
      </label>
    </fieldset>
    <button type="submit" class="btn-primary">submit</button>
  </form>
  <div id="preview"></div>
</section>

<section id="tab-bulk" class="tab-panel">
  <form hx-post="<?= h(url_for('/upload/bulk')) ?>" hx-target="#bulk-result" hx-swap="innerHTML" class="upload-form">
    <label>tx hexes (one per line, <code>#</code> for comments)
      <textarea name="hex_blob" rows="12" placeholder="# 20 signed txs, one hex per line&#10;0200000001..." required autocomplete="off" spellcheck="false"></textarea>
    </label>
    <fieldset>
      <legend>distribution</legend>
      <label class="radio-row"><span>window</span>
        <input type="text" name="window_start" value="now" size="6">
        →
        <input type="text" name="window_end" value="+7d" size="6">
      </label>
      <label class="radio-row"><span>min-gap</span> <input type="text" name="min_gap" value="30m" size="5"></label>
      <label class="radio-row"><span>seed</span> <input type="text" name="seed" value="" size="10" placeholder="optional"></label>
      <label class="radio-row"><input type="checkbox" name="preserve_existing" checked> <span>preserve existing pending txs</span></label>
    </fieldset>
    <button type="submit" class="btn-primary">schedule batch</button>
  </form>
  <div id="bulk-result"></div>
</section>

<script>
document.querySelectorAll('.tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab, .tab-panel').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});
</script>
