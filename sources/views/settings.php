<?php /** @var array $settings; */ ?>
<div class="page-title-row">
  <div>
    <span class="lede">// preferences</span>
    <h1 class="page-title">settings</h1>
  </div>
</div>

<form method="post" action="<?= h(url_for('/settings')) ?>" class="upload-form">
  <fieldset>
    <legend>telegram notifications</legend>
    <label class="radio-row">
      <input type="checkbox" name="telegram_enabled" <?= !empty($settings['telegram_enabled']) ? 'checked' : '' ?>>
      <span>enabled — ping on each fire (success or failure)</span>
    </label>
    <label>bot token
      <input type="text" name="telegram_bot_token" value="<?= h($settings['telegram_bot_token'] ?? '') ?>" autocomplete="off" spellcheck="false" placeholder="123456:AAH..." />
    </label>
    <label>chat id
      <input type="text" name="telegram_chat_id" value="<?= h($settings['telegram_chat_id'] ?? '') ?>" autocomplete="off" spellcheck="false" placeholder="987654321" />
    </label>
  </fieldset>
  <button type="submit" class="btn-primary">save</button>
</form>

<p style="color:var(--ink-mute);margin-top:1.5em;font-size:12px;">
  Create a bot with <a href="https://t.me/botfather" target="_blank" rel="noopener">@BotFather</a> on Telegram,
  paste the token here. Send any message to your bot, visit
  <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> and copy the <code>chat.id</code>.
</p>
