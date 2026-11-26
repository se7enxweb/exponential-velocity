# Migrating from Caddy

Caddy's automatic HTTPS and zero-config design is the closest to Velocity's philosophy. The main difference: Velocity runs your PHP directly instead of proxying to php-fpm.

## Quick start

```bash
# Stop Caddy
sudo systemctl stop caddy

# Start Velocity; automatic HTTPS is set in the config file (below)
./qbixserver --root=/var/www/myapp/public --port=80 --https-port=443 --config=/etc/vc/sites-enabled/myapp.conf
```

## Configuration mapping

### Caddyfile → config

```caddyfile
example.com {
    root * /var/www/myapp/public
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

Becomes:

```json
{
  "Q": {
    "webserver": {
      "domains": {
        "example.com": { "root": "/var/www/myapp/public", "tls": "auto" }
      },
      "tls": { "acmeEmail": "admin@example.com" }
    }
  }
}
```

A domain with `"tls": "auto"` gets a Let's Encrypt certificate, in the background,
when `Q.webserver.tls.acmeEmail` is set; HTTPS starts once it is there. The
server-wide certificate is configured under `Q.web.https` instead
(`"mode": "letsencrypt"`, see [https.md](https.md)).

### Automatic HTTPS

Both Caddy and Velocity provision Let's Encrypt certs automatically. The behavior is nearly identical — set a domain, get a cert. Velocity's ACME client also speaks to ZeroSSL, Google Trust Services or any ACME directory, and can serve certificates kept by certbot, a PKCS#12 bundle or a remote source ([https.md](https.md)).

### Autohost (on-demand TLS)

Caddy's `on_demand_tls` maps directly to Velocity's autohost feature:

```json
{
  "Q": {
    "webserver": {
      "autohost": {
        "enabled": true,
        "authorize": "allowlist",
        "allowlist": ["*.example.com"]
      }
    }
  }
}
```

### reverse_proxy → upstreams by host

```json
{ "Q": { "webserver": { "upstreams": { "api.example.com": "127.0.0.1:3000" } } } }
```

Velocity proxies whole hosts, not paths, and does not pass WebSocket upgrades
on ([multiuser.md](multiuser.md)).

### What's different

- **No Caddyfile syntax** — all config is JSON (or CLI flags)
- **No HTTP/3** — Caddy supports QUIC natively; Velocity speaks HTTP/1.1, and HTTP/2 over TLS when `Q.web.http2.enabled` is `true`
- **PHP runs in the server's own workers** — no php-fpm socket, no FastCGI. PHP is part of the server
- **COW workers** — instead of Caddy proxying to a fixed pool of fpm workers, Velocity forks persistent workers from a preloaded parent that share its memory copy-on-write (on Windows, where there is no fork, each worker is a process of its own: [windows.md](windows.md))
