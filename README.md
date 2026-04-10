# Cloudflare Tunnel for Local Development

A WordPress plugin that exposes your local development site via Cloudflare Tunnel, rewriting URLs on the fly **only** when requests arrive through the tunnel. Accessing `localhost` directly is never affected.

---

## How it works

When a request arrives from the Cloudflare Tunnel host, the plugin:

- Rewrites `site_url`, `home_url`, `content_url`, `plugins_url`, theme URIs and upload URLs to the tunnel URL
- Sets `$_SERVER['HTTPS'] = 'on'` and `SERVER_PORT = 443` — no need to touch `wp-config.php`
- Runs an output buffer as a safety net, rewriting any hardcoded URLs left in the final HTML (including JSON-encoded URLs inside Gutenberg blocks)
- Prevents redirect loops by removing `redirect_canonical` and intercepting `wp_redirect`

When a request arrives from `localhost`, **nothing changes** — WordPress behaves exactly as normal.

---

## Requirements

- WordPress 6.0+ installed and working on http://localhost or http://localhost:port. See: [https://github.com/alfiosalanitri/wordpress-with-docker](https://github.com/alfiosalanitri/wordpress-with-docker)
- PHP 8.0+
- [Cloudflare Tunnel](https://developers.cloudflare.com/tunnel/downloads/) installed on your development machine

---

## Installation

1. Download the latest `cloudflare-tunnel-for-local-development.zip` from the [Releases](../../releases/latest) page
2. In WordPress go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate

---

## Usage

**1. Start the tunnel on your machine:**

```bash
cloudflared tunnel --url http://localhost:8001
```

Cloudflare will output a URL like `https://xxxx.trycloudflare.com`.

**2. Configure the plugin:**

Go to **Settings → CF Tunnel / Local Dev** and:
- Paste the tunnel URL (must start with `https://`)
- Check **"Attiva la riscrittura degli URL per le richieste dal tunnel"**
- Save

**3. Share the tunnel URL** — visitors hitting `https://xxxx.trycloudflare.com` will see your local site with all assets loading correctly.

You can leave the plugin enabled permanently. From `localhost` everything works as before; the rewriting only activates for tunnel requests.

---

## Uninstall

Deactivating or uninstalling the plugin removes all stored options (`cflt_tunnel_url`, `cflt_tunnel_enabled`) from the database automatically.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)
