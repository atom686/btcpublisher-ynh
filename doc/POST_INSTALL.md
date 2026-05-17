btcpublisher is installed at **__DOMAIN____PATH__**.

**Next steps:**

1. Open the URL in a browser (you'll be prompted to log in via the YunoHost portal).
2. If you skipped the `.onion` step at install time, edit `__INSTALL_DIR__/config.php` and set `peer_host` to your Bitcoin node's hidden-service address; then `systemctl reload cron`. Until then, only the public-fallback route can broadcast.
3. (Optional) Enable Telegram notifications from the **settings** page.
4. Upload signed tx hexes via the **+ new** button. Pick exact time or random-within-window.

**Logs:**
- Web: standard nginx access log + `/var/log/__APP__/poll.log` for the cron.
- `journalctl -u php8.3-fpm` for app-level errors.

**State DB:** `__DATA_DIR__/state.db` (SQLite). **Telegram creds:** `__DATA_DIR__/settings.json`. Both backed up automatically by `yunohost backup`.

**Tor:** must be reachable on `127.0.0.1:9050`. The install script enables `tor@default`.
