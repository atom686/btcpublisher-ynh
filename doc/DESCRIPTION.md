**btcpublisher** schedules signed Bitcoin transactions for random-time broadcast.

You hand it a batch of pre-signed transaction hexes (one at a time or in bulk); it spreads them across a configurable window (default 1 week) with a minimum gap between fires, and broadcasts each at its assigned time through your remote Bitcoin node's Tor hidden service (P2P, port 8333). If the node is unreachable, it falls back to public mempool endpoints (mempool.space, blockstream.info).

The web UI lets you:
- Upload single transactions (exact time or random within a window) or paste a bulk batch.
- Inspect each tx's decoded structure (txid, inputs, outputs, segwit / Taproot kind).
- Manage the queue (cancel, reschedule, fire-now, retry failed).
- Get Telegram pings on every fire (configurable from the settings page).

**Architecture:** PHP-FPM serves the web UI; a per-minute cron runs the firing poll. State (queue + Telegram credentials) lives in YunoHost's data directory and survives upgrades.

**Privacy:** All node communication routes over Tor. Public fallback can be disabled at install time if Tor-only privacy outweighs reliability.

**Auth:** YunoHost SSO. Restrict the "main" permission to admins for production — broadcasting transactions is privileged.
