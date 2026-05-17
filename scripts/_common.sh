#!/bin/bash
# Shared helpers for btcpublisher install/upgrade/remove/restore.

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
