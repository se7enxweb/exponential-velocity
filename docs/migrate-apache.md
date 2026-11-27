# Migrating from Apache

If you have an existing Apache + mod_php or Apache + php-fpm setup, Velocity replaces both. The URL rewriting in your `.htaccess` files works without changes; the rest of what an `.htaccess` does is written into the server's configuration (below).

## Quick start

```bash
# Stop Apache
sudo systemctl stop apache2  # or httpd

# Start Velocity
./qbixserver --root=/var/www/html --port=80
```

## .htaccess compatibility

When it routes a request to PHP, the server reads the application's `.htaccess`
files for their mod_rewrite directives:

- `RewriteEngine On/Off`
- `RewriteBase`
- `RewriteRule` with regex patterns, flags `[L]`, `[R]`/`[R=301]`, `[F]`, `[QSA]`, `[NC]`, `[E=VAR:value]`
- `RewriteCond` with `%{REQUEST_FILENAME}` (`-f`, `-d`), `%{REQUEST_URI}`, `%{HTTP_HOST}`

That covers the front-controller rules of WordPress, Laravel, Drupal and Joomla.
Other directives in an `.htaccess` are not read from the file. Their equivalents
are per-host settings, `Q.webserver.hosts.<host>`, which a tool that serves
Apache sites writes from the sites' `.htaccess` files and virtual hosts
([multiuser.md](multiuser.md#access-control-and-url-rules-per-host-what-a-sites-htaccess-does)):

| Apache | Velocity (`Q.webserver.hosts.<host>`) |
|---|---|
| `Require all denied`, `<Files>`, `<FilesMatch>` | `access.deny` |
| `AuthType Basic` + `AuthUserFile` | `access.auth` (bcrypt, `$apr1$`, `{SHA}`, `crypt()`) |
| `DirectoryIndex` | `index` |
| `ErrorDocument` | `errorDocuments` |
| `RewriteRule` in the virtual host | `serverRewrite` |
| `RewriteMap` (`txt:`, `int:`) | `maps`, looked up as `${name:key\|default}` |
| `RewriteRule ... [E=NAME:value]`, `%{ENV:NAME}` | `flags.E`, for the rules after it (not handed to PHP) |
| `Options FollowSymLinks` / `SymLinksIfOwnerMatch` | `followSymlinks` |
| `Options -Indexes` | the default for a host listed there: a root without an index answers 403 |

`Header always set` in the server context becomes `Q.webserver.headers` (added to
every response the server builds, and to scripts' responses too with
`Q.webserver.headersOnScripts: true`); HSTS is `Q.webserver.hsts`. There is no
per-directory `Header` equivalent.

## Configuration mapping

### VirtualHost → domains config

```apache
<VirtualHost *:80>
    ServerName app.example.com
    DocumentRoot /var/www/app/public
</VirtualHost>
```

Becomes:

```json
{
  "Q": {
    "webserver": {
      "domains": {
        "app.example.com": { "root": "/var/www/app/public" }
      }
    }
  }
}
```

### mod_rewrite → already supported

Your `.htaccess` RewriteRules work as-is (the directives listed above). The server also has built-in front-controller routing that handles the common case (try static file → fall through to index.php) without any rewrite rules.

### mod_ssl → https config

```json
{ "Q": { "web": { "https": { "mode": "files",
  "cert": "/etc/ssl/example.com/fullchain.pem", "key": "/etc/ssl/example.com/privkey.pem" } } } }
```

Or `"mode": "letsencrypt"` with `"acme": { "email": ..., "domains": [...] }` to have
the server get and renew the certificate. Every mode is in [https.md](https.md).

### ProxyPass → upstreams by host

```json
{ "Q": { "webserver": { "upstreams": { "api.example.com": "127.0.0.1:3000" } } } }
```

A whole host is handed to another server; proxying one path of a site is not
supported. See [multiuser.md](multiuser.md).

### What you keep

- `.htaccess` rewrite rules work unchanged
- `$_SERVER` superglobals populated identically
- `php.ini` settings: the server runs on the PHP that starts it, so give PHP the ini file as usual (`php -c /path/php.ini sbin/qbixserver.php ...`, or `PHPRC`)
- File upload handling works the same way

### Running beside Apache, or without root

Velocity can take Apache's ports without running as root, and hand a host it
cannot serve back to the server that served it before: see
[running-without-root.md](running-without-root.md) and
[host-fallback.md](host-fallback.md).

### What you gain

- Workers forked from a preloaded parent, sharing its memory copy-on-write
- Single binary deployment — no Apache modules to install
- Built-in cert provisioning and auto-renewal
- Control panel with framework-aware package management
