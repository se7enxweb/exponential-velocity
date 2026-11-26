# Migrating from nginx

If you have an existing nginx + php-fpm setup, Velocity replaces both nginx and php-fpm with a single binary. Here's how to migrate your configuration.

## Quick start

```bash
# Stop nginx + php-fpm
sudo systemctl stop nginx php-fpm

# Start Velocity on the same port
./qbixserver --root=/var/www/myapp/public --port=80   # the worker count is sized automatically
```

## Configuration mapping

### server block → command line or config

nginx `server { listen 80; root /var/www/myapp/public; }` becomes:

```bash
./qbixserver --root=/var/www/myapp/public --port=80
```

The port can also come from config (`{ "Q": { "webserver": { "port": 80 } } }`);
the document root is given with `--root` (or per host, under `domains`, below).

### try_files → automatic

nginx `try_files $uri $uri/ /index.php?$query_string;` is the default behavior. The server checks for a static file first, then routes to your front controller. No configuration needed.

### location blocks → presets, .htaccess rewrites or host rules

The server applies the mod_rewrite directives of an application's `.htaccess`
(`RewriteEngine`, `RewriteBase`, `RewriteCond`, `RewriteRule`) when it routes a
request to PHP, so the rules Laravel, WordPress and Symfony ship with work. Other
`location` logic -- denied paths, HTTP Basic areas, redirects, index names, error
pages -- goes into `Q.webserver.hosts.<host>` (`access`, `rewrite`,
`serverRewrite`, `index`, `errorDocuments`), described in
[multiuser.md](multiuser.md#access-control-and-url-rules-per-host-what-a-sites-htaccess-does).

For framework-specific routing:

```bash
./qbixserver --root=public --preset=laravel
./qbixserver --root=. --preset=wordpress
./qbixserver --root=public --preset=symfony
```

### proxy_pass → upstreams by host

The server can hand a whole host to another HTTP server (another Velocity, or
anything else) on a Unix socket or a loopback port:

```json
{ "Q": { "webserver": { "upstreams": { "api.example.com": "127.0.0.1:3000" } } } }
```

It proxies by host, not by path: an nginx `location /api/ { proxy_pass ... }`
inside one site has no equivalent; keep a proxy in front for that. WebSocket
upgrades are not passed on (501). See [multiuser.md](multiuser.md).
`Q.webserver.proxy` is something else: the proxies in front of this server whose
`X-Forwarded-*` headers are trusted (`Q.webserver.proxy.trusted`, loopback by
default).

### SSL/TLS

nginx cert paths become config:

```json
{
  "Q": {
    "web": {
      "https": {
        "mode": "files",
        "cert": "/etc/letsencrypt/live/example.com/fullchain.pem",
        "key": "/etc/letsencrypt/live/example.com/privkey.pem"
      }
    }
  }
}
```

Or let the server get the certificate itself: `"mode": "letsencrypt"` with
`"acme": { "email": "admin@example.com", "domains": ["example.com"] }`. Every
mode is in [https.md](https.md).

### X-Accel-Redirect

Supported natively. Your PHP code can return `X-Accel-Redirect: /path/to/file` headers and the server will serve the file directly, just like nginx.

### gzip

The server compresses responses automatically for clients that accept it. No `gzip on;` configuration needed.

### Virtual hosts

```json
{
  "Q": {
    "webserver": {
      "domains": {
        "app1.example.com": { "root": "/var/www/app1/public" },
        "app2.example.com": { "root": "/var/www/app2/web" }
      }
    }
  }
}
```

Multiple domains can point to the same app — the app uses `$_SERVER['HTTP_HOST']` to differentiate.

### What you lose

- **HTTP/2 server push** — not implemented (browsers are removing support anyway); HTTP/2 itself is built in, over TLS, with `Q.web.http2.enabled`
- **Proxying by path** — upstreams are chosen by host only
- **Load balancing** — use the clustering feature or an external LB
- **Rate limiting by IP** — implement in your PHP app or use a firewall

### What you gain

- **COW memory** — workers are forked from a preloaded parent and share its memory copy-on-write; the pool is sized from free memory and cores (at most 64 unless `--workers` says otherwise)
- **Single binary** — no nginx config, no php-fpm pool config, no socket permissions
- **Built-in control panel** — manage domains, workers, certs from a browser
- **Auto-provisioning** — new domains get certs automatically with autohost
