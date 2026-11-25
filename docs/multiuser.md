## Many users behind one server: pools and upstreams

A hosting server runs many sites, each owned by its own system user. Apache
keeps them apart with suEXEC and PHP-FPM with a pool per site. The server does
the same in two ways, and they can be combined:

- [Pools with a user of their own](#pools-with-a-user-of-their-own): one server,
  one port, each host's PHP run by workers of that host's user.
- [Upstreams by host](#upstreams-by-host): a front server passes each listed host
  on to another server (any user, any PHP version) on a socket or loopback port.

---

### Pools with a user of their own

```json
{ "Q": { "webserver": {
    "user": "nobody",
    "hosts": {
        "shop.example":     { "root": "/var/www/vhosts/shop.example/httpdocs" },
        "www.shop.example": { "root": "/var/www/vhosts/shop.example/httpdocs" },
        "blog.example":     { "root": "/var/www/vhosts/blog.example/httpdocs", "pool": "blog" }
    },
    "pools": {
        "shop": { "user": "shopuser", "group": "psacln", "workers": 4,
                  "hosts": ["shop.example", "www.shop.example"],
                  "ini": { "open_basedir": "/var/www/vhosts/shop.example/:/tmp/" } },
        "blog": { "user": "bloguser", "group": "psacln", "workers": 2 }
    }
} } }
```

- Each pool is a worker pool of its own, forked from the same warmed parent, whose
  workers (and zygote) switch to the pool's user and group right after the fork,
  before any application code, as the server's own workers do
  ([workers.md](workers.md#the-user-the-workers-run-as)).
- A host belongs to a pool by the pool's `hosts` list or by `hosts.<host>.pool`. A
  host in no pool uses the server's own workers (`Q.webserver.user`).
- `ini` is applied in each worker of the pool after the switch; a setting PHP
  refuses ends the worker instead of serving without it. `open_basedir` keeps a
  pool's PHP inside its user's tree.
- `workers` is the pool's size; `spareWorkers`, `maxRequests` and the other pool
  settings are shared by every pool.
- **Static files.** The server process reads static files itself. For a host in a
  pool, a file goes out only when the pool's user owns it after symbolic links are
  resolved (Apache's `SymLinksIfOwnerMatch`, nginx's `disable_symlinks
  if_not_owner`), so a link in one user's site cannot hand out another user's or
  the system's files. `pools.<name>.ownerMatch: false` turns that off for a pool.
- **php-cgi** (`webserver.cgi.patterns`) is never used for a host in a pool: it
  would run as the server's user.
- **Start refuses** -- the server exits with the reason -- when a pool names a user
  or group that does not exist, root (unless the pool says `allowRoot: true`), when
  a host names a pool that does not exist, or when the server was not started as
  root and a pool's user is not the server's own. A host is never served by the
  wrong user.

One PHP runs the server, so every pool shares its PHP version and its `php.ini`
(apart from what `ini` sets). Hosts that need another PHP version are served by
another server and reached through an upstream.

The HTTP/2 path routes by the same pools. The dashboard's worker figures show the
server's own pool only.

---

### Upstreams by host

```json
{ "Q": { "webserver": {
    "upstreams": {
        "shop.example":     "unix:/run/app/shop.sock",
        "www.shop.example": "unix:/run/app/shop.sock",
        "legacy.example":   "127.0.0.1:7181"
    },
    "upstreamTimeout": 300
} } }
```

- A request for a listed host is passed on before this server looks at it: no
  cache, no file and no script of its own is involved.
- Sent on: the request line, the headers without the hop-by-hop ones, the body as
  read, `Connection: close`, `X-Forwarded-For` (the incoming chain plus the peer
  address), `X-Forwarded-Proto` and `X-Forwarded-Host`. The upstream should trust
  the front's address for those (`Q.webserver.proxy.trusted` trusts loopback by
  default), so it sees the visitor's address and scheme.
- What comes back is passed to the client byte for byte as it arrives, so a
  streamed answer streams; the client connection is closed after it.
- `502` when the upstream cannot be reached or closes without answering, `504`
  when it sends nothing within `upstreamTimeout` seconds (an answer that has
  started may then take as long as it needs).
- Not passed on: WebSocket and other `Upgrade` requests (`501`), and HTTP/2
  requests for a listed host (`502`, never served locally).

Tests: `tests/multiuser-pools.sh` (as root) and `tests/upstreams.sh`.

---

### Writes to the primary of a replicated database

With a database whose replicas are read-only (LiteFS keeps a `.primary` file in its
mount naming the current primary while the node is a replica, and none while it is
the primary), a host can send every request that may write to the primary's server:

```json
{ "Q": { "webserver": { "hosts": { "shop.example": {
    "root": "/var/www/vhosts/shop.example/httpdocs",
    "writeForward": {
        "primaryFile": "/var/lib/litefs/shop.example/.primary",
        "nodes": { "node-a": "10.0.0.1:7090", "node-b": "10.0.0.2:7090" },
        "methods": ["POST", "PUT", "PATCH", "DELETE"]
    }
} } } } }
```

- No file: this node is the primary and answers everything itself.
- A file: its first line is the primary's node name, looked up in `nodes` (an
  unlisted name is used as `host:port`); requests with a listed method go there
  through the same relay as an upstream; reads stay here.
- A forwarded request carries `X-Qbix-Forwarded-Write: 1` and is never forwarded
  again, so two nodes that briefly both believe the other is primary cannot loop.
- HTTP/1.1 only, as upstreams.

---

### Access control and URL rules per host (what a site's .htaccess does)

The server process evaluates `Q.webserver.hosts.<host>.access`, `.rewrite` and `.index`
for every request of that host, before the cache, a file or a script
(`Q_WebServer_HostRules`). A tool that serves Apache sites writes them from the sites'
`.htaccess` files; pooled workers never read `.htaccess` themselves.

- `access.deny`: `{ "dir": "attachments/" }`, `{ "dir": "", "files": ".user.ini" }`,
  `{ "dir": "", "filesMatch": "\\.log$" }`: 403 for the directory and below, for one file
  name in it and below (Apache's `<Files>`), or names matching a regex (`<FilesMatch>`).
- `access.auth`: `{ "dir": "admin/", "realm": "Staff", "userFile": "/path/.htpasswd" }`:
  HTTP Basic, the deepest block covering the path decides. bcrypt, Apache MD5 (`$apr1$`),
  `{SHA}` and `crypt()` hashes; a user file that cannot be read gives 500, never access.
- `rewrite`: sets of mod_rewrite rules per directory (the deepest set covering the path
  runs, as Apache runs the deepest `.htaccess` with rules): `pattern` (relative to the
  directory), optional `negate`, `conds` (`%{REQUEST_FILENAME}` with `-f`/`-d`/`-s`/`-e`,
  or a regex on `%{HTTP_HOST}`, `%{HTTP_USER_AGENT}`, `%{REQUEST_URI}`, `%{QUERY_STRING}`,
  `%{HTTPS}`, `%{REQUEST_METHOD}`, `%{HTTP_REFERER}`, `%{HTTP_COOKIE}`, `%{HTTP:Name}`, with
  `nocase` and `or`), `target` (`-`, a path, or a URL; `$n` and `%n`), `flags` `F`, `G`,
  `R` (code), `L`/`END`, `NC`, `QSA`. An internal rewrite goes through access and the
  rules again, at most ten times.
- `index`: the directory index names, first existing wins.
- `serverRewrite`: the virtual host's own mod_rewrite rules (Apache's server context, as in
  a Plesk `vhost.conf`): the same rule form as a `rewrite` set, but each `pattern` is
  matched against the whole URL path with its leading slash, and the rules run first,
  before access control and before any directory's rules. A redirect or `F`/`G` answers at
  once; an internal rewrite to a path then meets access control and the directory rules.
- `errorDocuments`: `{ "401": "/var/www/vhosts/example.com/error_docs/unauthorized.html" }`,
  Apache's `ErrorDocument`: the body the server sends for that status of its own (401 from
  access control, 403, 404 ...), status and other headers unchanged. A file that is missing
  leaves the short plain-text answer.
- `followSymlinks`: `true` (Apache's `FollowSymLinks`) or `"ifOwnerMatch"`
  (`SymLinksIfOwnerMatch`: every link on the way has the owner of its target) lets a file
  reached through a link outside the root be sent or run for this host. Unset, such a file
  is refused (403), as for every host.
- A host with an entry here never gets the server's welcome page: a root without an index
  answers 403, as Apache with `Options -Indexes` does.
- Paths are judged decoded, with dot segments resolved; a path that climbs out of the
  root is 400.

Tests: `tests/unit-host-rules.php` (35 checks), `tests/host-rules.sh` (28, live server).

`Q.webserver.forkPerRequest` applies to pools as well: every request then runs in a fresh
worker of its pool's user, as PHP-FPM runs an application that keeps nothing between
requests (`tests/multiuser-pools.sh`, 17 checks).
