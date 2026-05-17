# btcpublisher (PHP / YunoHost)

Schedule signed Bitcoin transactions for random-time broadcast through your remote node's Tor hidden service, with public-mempool fallback. Packaged for [YunoHost](https://yunohost.org).

PHP 8.3 + HTMX, no framework, no Composer dependencies. Cron runs the firing poll every minute; PHP-FPM serves the web UI. SQLite for state.

## Install

```bash
sudo yunohost app install /path/to/this/repo
```

You'll be prompted for:

- **Domain + path** — where to mount the app (e.g. `example.com/btcpublisher`).
- **Permission** — which YunoHost group can access it. **Restrict to `admins`** in production. Broadcasting is privileged.
- **Node `.onion`** — your Bitcoin node's P2P hidden-service address (port 8333). Leave blank to use only public-mempool fallback.
- **Public fallback** — if enabled, unsent txs ship via mempool.space / blockstream.info when the node is unreachable. Defeats Tor privacy for that broadcast.

## Layout

```
manifest.toml                 # YunoHost packaging v2
conf/
  nginx.conf                  # subpath reverse proxy + PHP-FPM upstream
  cron                        # */1 * * * *  bin/poll.php
  config.php                  # template rendered at install with node/path tokens
  logrotate
scripts/
  _common.sh                  # rsync + seed-settings helpers
  install / upgrade / remove
  backup / restore / change_url
sources/
  public/index.php            # front controller + router
  views/                      # HTML templates (PHP IS the template engine)
  assets/style.css            # broadcast-equipment aesthetic
  lib/
    decode.php                # tx parser + bech32/bech32m (BIP-173/350)
    p2p.php                   # Bitcoin P2P client over SOCKS5 (Tor)
    broadcast.php             # P2P primary + public fallback + retry/backoff
    state.php                 # SQLite via PDO
    scheduler.php             # uniform random + min-gap slot picker
    notify.php                # Telegram sendMessage
    bootstrap.php             # config, identity, view helpers
  bin/poll.php                # cron entry: fires due txs, marks missed
doc/
  DESCRIPTION.md  POST_INSTALL.md
```

## Logs

```bash
tail -F /var/log/btcpublisher/poll.log
journalctl -u php8.3-fpm -f
```

## Manual settings

Telegram bot token + chat id are managed from the **/settings** page in the web UI (no shell access needed). Stored in `/home/yunohost.app/btcpublisher/settings.json` with mode 0600 owned by the app user.

To change broadcast routing (Tor SOCKS port, retry windows, etc.), edit `/var/www/btcpublisher/config.php` and reload cron with `systemctl reload cron` (changes take effect on the next minute).

## License

MIT.
