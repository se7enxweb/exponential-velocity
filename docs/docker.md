## 🐳 Docker Images

The server is published as container images built on the official PHP images
(Debian bookworm), one per PHP version and variant, for `amd64` and `arm64`.
Each image carries its variant of the extension baseline
([requirements.md](requirements.md)), is checked against it when it is built, and
answers a health check.

- [Tags](#tags)
- [Running it](#running-it)
- [What each variant carries](#what-each-variant-carries)
- [Database add-ons: Oracle, Firebird, ODBC](#database-add-ons-oracle-firebird-odbc)
- [Configuration](#configuration)
- [User and configuration trees](#user-and-configuration-trees)
- [Health check](#health-check)
- [Compose](#compose)
- [Building your own](#building-your-own)

---

### Tags

```
ghcr.io/se7enxweb/exponential-velocity:<release>-php<version>-<variant>   fixed
ghcr.io/se7enxweb/exponential-velocity:php<version>-<variant>             moves with each release
ghcr.io/se7enxweb/exponential-velocity:<release>                          newest PHP, standard variant, fixed
ghcr.io/se7enxweb/exponential-velocity:latest                             newest PHP, standard variant
ghcr.io/se7enxweb/exponential-velocity:edge-php<version>-<variant>        the main branch, moves with each push
ghcr.io/se7enxweb/exponential-velocity:edge                               the main branch, newest PHP, standard variant
```

`<release>` is the version without its `v` (`0.0.4.69`). A fixed tag is never
moved once it is published: the workflow stops before it would overwrite one, and
an image found wrong after its release is replaced by the next release, with the
reason in its [changelog](../CHANGELOG.md) entry. `edge` tags are for trying what
is coming; production pins a fixed tag. Each tag also has a
single-architecture form with `-amd64` or `-arm64` appended to the fixed tag.

> DEPRECATED: `ghcr.io/se7enxweb/qbix-webserver` is no longer updated. Use
> `ghcr.io/se7enxweb/exponential-velocity` instead.

The images were published as `ghcr.io/se7enxweb/qbix-webserver` until the
repository was renamed. Those tags stay where they are but get no new ones:
change the image name to `ghcr.io/se7enxweb/exponential-velocity` and keep the
same tag. Inside the image the server now lives in `/usr/share/exponential-velocity`,
and `/usr/share/qbix-webserver` is a link to it.

`<version>` is 8.2, 8.3, 8.4 or 8.5; `<variant>` is `mini`, `lite`, `standard` or
`full` ([binaries.md](binaries.md#variants-which-binary-to-download)). Every tag is
multi-architecture: Docker pulls the `amd64` or `arm64` image to match the host.
Pin a fixed tag in production, so an image only changes when you change the tag.

---

### Running it

```bash
docker run -d --name web -p 8080:8080 \
  -v "$PWD/public:/app" \
  ghcr.io/se7enxweb/exponential-velocity:php8.3-standard
```

The document root is `/app`; the image ships the welcome page there, which a
mounted directory replaces. The server listens on 8080 inside the container. Any
server option can be passed by replacing the command:

```bash
docker run -d -p 8080:8080 -v "$PWD:/srv/site" \
  ghcr.io/se7enxweb/exponential-velocity:php8.3-standard \
  qbixserver --root=/srv/site/public --port=8080 --workers=16
```

`qbixserver`, `qbixctl` and `qbixconsole` are on the PATH inside the image, in
`/usr/local/sbin` with links at their former paths in `/usr/local/bin`
([layout.md, Programs](layout.md#programs-bin-and-sbin)), and the shell as
`vc-qshell`, a link at `/usr/local/bin/vc-qshell` to `bin/qshell.php`
([shell.md](shell.md)):

```bash
docker exec web qbixctl ext:check          # this image against its baseline
```

---

### What each variant carries

The same variants as the binaries, built for a dynamic PHP instead of a static
one. Two things follow from that:

- **ODBC is in** (`odbc`, `pdo_odbc`), with drivers ([below](#database-add-ons-oracle-firebird-odbc)):
  a static binary cannot load ODBC drivers at run time, an image can.
- **The `full` image is best effort for the extra tier.** Extensions outside the
  server, required and recommended tiers are built from PHP's sources or PECL, and
  one that does not build on a given PHP version is left out of that image rather
  than failing it. The image records exactly what it carries, and why anything was
  left out:

```bash
docker run --rm ghcr.io/se7enxweb/exponential-velocity:php8.3-full \
  cat /usr/share/exponential-velocity/image-extensions.txt
```

An extension counts as installed only when PHP then starts without a warning that
it could not load it. One that links against another extension is enabled after
it: `event` uses the symbols of `sockets`, so its ini file is
`$PHP_INI_DIR/conf.d/zz-docker-php-ext-event.ini`, read after every
`docker-php-ext-*.ini` (`loadAfter` in `packaging/docker/debian-build-deps.json`).

The server, required and recommended tiers are never best effort: an image that
misses any of them fails its build.

opcache is enabled for the command line, with the tracing JIT, since the server is
a long-lived CLI process (`$PHP_INI_DIR/conf.d/zz-qbix-opcache.ini`).

---

### Database add-ons: Oracle, Firebird, ODBC

The `standard` and `full` images also carry the drivers a static binary cannot:

| Add-on | Extensions | What it brings |
|---|---|---|
| Oracle | `oci8`, `pdo_oci` | Oracle Instant Client (basic + sdk) in `/opt/oracle/instantclient` |
| Firebird | `pdo_firebird` | The Firebird client library |
| ODBC drivers | for `odbc` and `pdo_odbc` | PostgreSQL, MariaDB/MySQL, SQLite, and FreeTDS for Microsoft SQL Server and Sybase |

On PHP 8.4 and later `oci8` and `pdo_oci` are no longer bundled with PHP and come
from PECL; an add-on that does not build on a PHP version is recorded in
`image-extensions.txt` like any other exception.

**Oracle Instant Client licensing.** Oracle distributes Instant Client under its own
license (at the time of writing the Oracle Free Use Terms and Conditions, which
permit redistribution). Read Oracle's current terms before you redistribute an
image that contains it. To build without it, set `WITH_ADDONS=0`
([Building your own](#building-your-own)).

Microsoft SQL Server through `sqlsrv`/`pdo_sqlsrv` needs Microsoft's ODBC driver,
which is not redistributable, so it is not in any image; FreeTDS over ODBC is.
[requirements.md](requirements.md) describes how to add `sqlsrv` yourself.

---

### Configuration

The image's default command, `qbixserver --root=/app --port=8080`, reads no
configuration tree. To use one, mount it at `/etc/vc` (Velocity's overlay) or
`/etc/qbix` (the engine's base tree; the image creates it empty), both laid out as
described in [layout.md](layout.md), and ask for it: `--conf-dir=auto`, a
`--config=/etc/vc/sites-enabled/<site>.conf`, or the environment variable
`QBIX_CONF_DIR` (or `VC_CONF_DIR`). Settings can also be passed on the command
line. Certificates and HTTPS: [https.md](https.md). A mounted directory needs to be
readable by the container's user, `www-data` ([below](#user-and-configuration-trees)).

---

### User and configuration trees

The server runs as `www-data` (uid 33), not as root. Docker and Podman let a
container's user bind any port, so `--port=80` still works inside the container.
Files the server writes to a mounted directory belong to uid 33; give the
directory to it (`chown 33:33`) or run the image with `--user` set to the owner
you want. Images up to 0.0.4.70 ran as root; to keep that, run with `--user root`.

The configuration trees follow Debian's `/etc/apache2` layout ([layout.md](layout.md)),
both owned by `www-data`:

```
/etc/qbix/qbix.conf, ports.conf, envvars      the engine's base tree, as the packages install it
/etc/qbix/sites-available/default.conf        enabled by the link in sites-enabled/
/etc/qbix/{conf,mods}-{available,enabled}/
/etc/vc/{sites,conf,mods}-{available,enabled}/   Velocity's overlay, empty, for your own files
/etc/vc/designs/
/var/lib/vc  /var/log/vc  /var/cache/vc  /run/vc  state, logs, cache, run files
```

Mount a site file at `/etc/vc/sites-available/<site>.conf`, enable it with
`qbixctl ensite <site>` (or mount `/etc/vc/sites-enabled/` too) and start the server
with `--conf-dir=auto` to use it.

---

### Health check

Each image declares a health check that asks the server's `/Q/health` on port 8080
and expects `"status":"ok"`; `/Q/health` is always open to the machine itself.

```bash
docker inspect -f '{{.State.Health.Status}}' web
```

If you run the server on another port, set `QBIX_HEALTH_URL`
(e.g. `http://127.0.0.1:9000/Q/health`).

---

### Compose

```yaml
services:
  web:
    image: ghcr.io/se7enxweb/exponential-velocity:php8.3-standard
    ports:
      - "8080:8080"
    volumes:
      - ./public:/app:ro
    restart: unless-stopped
  db:
    image: mariadb:11
    environment:
      MARIADB_ROOT_PASSWORD_FILE: /run/secrets/db_root_password
    secrets: [db_root_password]
secrets:
  db_root_password:
    file: ./db_root_password.txt
```

For a complete Exponential site in containers (Apache with PHP-FPM or Velocity,
SQLite, MariaDB or PostgreSQL), use Exponential's own images,
`ghcr.io/se7enxweb/exponential`, documented in the Exponential repository
(`doc/eng-us/help/hosting/models/containers-docker-and-podman.md`) and on
[doc.se7enx.com](https://doc.se7enx.com/).

---

### Building your own

The images are built from `packaging/docker/Dockerfile` in the repository, from the
repository's root:

```bash
docker build -f packaging/docker/Dockerfile \
  --build-arg PHP_VERSION=8.3 --build-arg VARIANT=standard \
  -t my/exponential-velocity:php8.3-standard .
```

| Build argument | Default | Meaning |
|---|---|---|
| `PHP_VERSION` | `8.3` | The official `php:<version>-cli-bookworm` image to build on |
| `VARIANT` | `standard` | `mini`, `lite`, `standard` or `full` |
| `WITH_ADDONS` | `auto` | `1` or `0`: the Oracle, Firebird and ODBC add-ons. `auto` is 1 for standard and full |

The extension list is not in the Dockerfile: `packaging/docker/plan.php` asks the
baseline which extensions the variant carries on this PHP, and the build ends with
`qbixctl ext:check`, so an image never ships without something its variant
promises.
