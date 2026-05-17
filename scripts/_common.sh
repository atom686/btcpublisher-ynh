#!/bin/bash
# Shared helpers for btcpublisher install/upgrade/remove/restore.

# Local SOCKS port for our dedicated Tor instance. Chosen far from the
# standard 9050 (system tor) and 9150/9151 (Tor Browser) so a YunoHost box
# running any of those can coexist with us.
#
# Lowercase shell var on purpose: ynh_config_add substitutes __TOR_PORT__ in
# templates with the value of $tor_port (must be lowercase per YunoHost convention).
tor_port="19050"

# rsync the bundled PHP source folder into $install_dir, deleting stale
# upstream files but never touching the data dir.
btcpub_sync_source() {
    local dest="$1"
    local src
    src="$(cd "$(dirname "${BASH_SOURCE[0]}")/../sources" && pwd)"

    if [ ! -d "$src" ]; then
        ynh_die --message="Bundled source not found at $src"
    fi
    mkdir -p "$dest"
    rsync -a --delete "$src"/ "$dest"/
}

# Seed an empty settings.json if it doesn't exist; never overwrite user values.
# Provision (or refresh) a per-app Tor instance bound to TOR_PORT on
# loopback. Idempotent.
btcpub_setup_tor_instance() {
    local app="$1"
    if ! command -v tor-instance-create >/dev/null; then
        ynh_die --message="'tor-instance-create' missing. The 'tor' apt package didn't install correctly."
    fi
    if [ ! -d "/etc/tor/instances/$app" ]; then
        tor-instance-create "$app" >/dev/null
    fi
    ynh_config_add --template="torrc" --destination="/etc/tor/instances/$app/torrc"
    # Ownership: Debian's tor-instance-create makes a _tor-<instance> user.
    local tor_user="_tor-$app"
    id "$tor_user" >/dev/null 2>&1 || tor_user="debian-tor"
    chown "$tor_user:$tor_user" "/etc/tor/instances/$app/torrc"
    chmod 644 "/etc/tor/instances/$app/torrc"

    systemctl enable "tor@$app" >/dev/null 2>&1 || true
    systemctl restart "tor@$app"

    # Wait for the SOCKS port to come up.
    local i=0
    while [ $i -lt 20 ]; do
        if ss -tln 2>/dev/null | grep -q "127.0.0.1:$tor_port"; then
            return 0
        fi
        sleep 1
        i=$((i + 1))
    done
    ynh_die --message="tor@$app did not open SOCKS port $tor_port after 20s. Check 'systemctl status tor@$app' and 'journalctl -u tor@$app'."
}

# Disable + remove the per-app Tor instance (called from `remove`).
btcpub_teardown_tor_instance() {
    local app="$1"
    systemctl disable --now "tor@$app" >/dev/null 2>&1 || true
    ynh_safe_rm "/etc/tor/instances/$app"
    ynh_safe_rm "/var/lib/tor-instances/$app"
    # _tor-<app> system user is left alone — adduser/deluser is risky to
    # automate here, and it costs nothing to leave it.
}

btcpub_seed_settings() {
    local data_dir="$1"
    local app_user="$2"
    local settings="$data_dir/settings.json"
    if [ ! -f "$settings" ]; then
        cat > "$settings" <<'JSON'
{
  "telegram_enabled": false,
  "telegram_bot_token": "",
  "telegram_chat_id": ""
}
JSON
        chown "$app_user:$app_user" "$settings"
        chmod 600 "$settings"
    fi
}

# Normalize manifest boolean → PHP literal ("true"/"false").
btcpub_bool_to_php() {
    case "$1" in
        1|true|True|yes|on) echo "true" ;;
        *)                  echo "false" ;;
    esac
}
