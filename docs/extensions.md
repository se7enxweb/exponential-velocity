## 🧩 Extensions: Checking, Planning, Building, Installing

How to see what a PHP has against the standard set, what a build would carry, how to
build one, and how to install what is missing. What the standard set *is* (tiers,
variants, every extension and why) is in [requirements.md](requirements.md).

- [Check this machine](#check-this-machine)
- [Install what is missing](#install-what-is-missing)
- [Database add-ons](#database-add-ons)
- [Plan a build](#plan-a-build)
- [Build a binary](#build-a-binary)
- [The source kit](#the-source-kit)
- [In scripts and CI](#in-scripts-and-ci)
- [The manifest](#the-manifest)

### Check this machine

```bash
qbixctl ext:check
```

```
PHP 8.3 on linux-x86_64: provides the 'lite' set; checked against 'standard'
  ok  ctype            server       Character-class checks used when parsing requests and configuration
  ...
  --  redis            recommended  Redis / Valkey cache backend
  ~   gd               required     Image aliases: ... (without webp)

missing: redis
to install (apt):
  sudo apt-get install -y php8.3-redis
```

`ok` is present, `--` is missing, `~` is present but built without a feature the
standard expects (gd without WebP or FreeType). The first line names the largest
variant this PHP fully provides.

| Option | |
|---|---|
| `--variant=full` | Check against another variant (default: standard). |
| `--tier=required` | Show one tier. |
| `--format=json` | Machine-readable, with the exit code in `exit`. |

Exit code: **0** nothing missing, **1** a required extension is missing, **2** only
recommended ones are (or one is incomplete).

The same report is on the dashboard (the Extensions card) and in `/Q/health`
(`extensions`), and the server prints it once at start when anything is missing.
A static binary is checked against its own platform's exceptions; any other PHP
against what its OS can have at all.

### Install what is missing

```bash
qbixctl ext:install-hint              # everything ext:check finds missing
qbixctl ext:install-hint intl redis   # just these
```

It detects the package manager and names the packages for this PHP version:

| System | Detected by | Example |
|---|---|---|
| Debian, Ubuntu | `/etc/os-release` | `sudo apt-get install -y php8.3-intl php8.3-redis` |
| RHEL, Rocky, Alma, Fedora | `/etc/os-release` | `sudo dnf install -y php-intl php-pecl-redis6` |
| Remi collections | PHP under `/opt/remi/php83` | `sudo dnf install -y php83-php-intl php83-php-pecl-redis6` |
| Plesk | PHP under `/opt/plesk/php/8.3` | `/opt/plesk/php/8.3/bin/pecl install redis`; bundled ones are switched on under Tools & Settings > PHP Settings |
| Alpine | `/etc/os-release` | `apk add php83-intl php83-pecl-redis` |
| FreeBSD | the OS | `pkg install -y php83-intl php83-pecl-redis` |
| macOS | the OS | `brew install shivammathur/extensions/redis@8.3` |
| Windows | the OS | `extension=intl` in php.ini; PECL DLLs from pecl.php.net |

Force another system with `--manager=apt` (or dnf, dnf-scl, plesk, apk, pkg, brew,
windows) and another PHP with `--php=8.4`. Several extensions share a package on some
systems (`dom`, `xml`, `xsl` are `php8.3-xml` on Debian), and the command lists each
package once. Restart PHP-FPM, or the server, afterwards.

Package names vary between repositories; if one is not found, search your
distribution for the extension name.

### Database add-ons

Oracle, Firebird, SQL Server and Access need client libraries that cannot be built
into a static binary. Ask for the steps by name:

```bash
qbixctl ext:install-hint oci8           # Oracle Instant Client + pecl install oci8
qbixctl ext:install-hint pdo_oci        # the PDO driver for Oracle
qbixctl ext:install-hint pdo_firebird   # Firebird
qbixctl ext:install-hint sqlsrv pdo_sqlsrv   # SQL Server (Microsoft's ODBC driver)
qbixctl ext:install-hint msaccess       # Access through ODBC
```

The Docker image already carries the Oracle and Firebird add-ons and ODBC drivers
([docker.md](docker.md)).

### Plan a build

```bash
qbixctl ext:plan --variant=standard --platform=windows-x64 --php=8.4
```

Lists what the build includes, tier by tier, what it leaves out with the reason for
each, the extra libraries (gd's JPEG, PNG, WebP and FreeType), and the two
static-php-cli commands it would run. `ext:list` prints the same selection in other
shapes:

```bash
qbixctl ext:list --variant=standard --format=table   # name, tier, purpose
qbixctl ext:list --variant=standard --format=spc     # ctype,filter,...  (for spc build)
qbixctl ext:list --variant=standard --format=libs    # libjpeg,libpng,libwebp,freetype
qbixctl ext:list --variant=standard --format=json    # include, exclude, libs, purposes
```

`--with=xdebug,imagick` adds extensions to any variant, subject to the platform's
exceptions. Platforms: `linux-x86_64`, `linux-aarch64`, `macos-arm64`, `windows-x64`
(default: this machine). PHP: 8.2, 8.3, 8.4, 8.5 (default 8.3).

### Build a binary

```bash
qbixctl ext:build --variant=standard --php=8.4 --dry-run   # see the commands
qbixctl ext:build --variant=standard --php=8.4 --spc=./spc
```

It runs static-php-cli (`--spc`, else `spc` on the PATH, else `./spc`) to download the
sources and build PHP (CLI and micro) with the variant's extensions and libraries,
rebuilds the phar (in a full checkout; the source kit uses the phar it ships), and
combines the two into one file:
`dist/qbixserver-<platform>-php<version>-<variant>` (`.exe` on Windows; `--out` to
change the directory). static-php-cli builds for the machine it runs on, so build
each platform on that platform.

### The source kit

```bash
qbixctl ext:build --variant=source
```

Writes `dist/qbixserver-source-kit.tar.gz`: the phar, the manifest and its schema,
the console with the sources it needs, [requirements.md](requirements.md), this page,
and a `BUILD.md` with the recipe for every variant. Unpack it on the target machine
and run `php sbin/qbixctl.php ext:build --variant=...` there.

### In scripts and CI

```bash
# Fail a deploy when the host PHP lacks a required extension:
qbixctl ext:check --format=json > ext.json || [ $? -eq 2 ]

# Build the extension list for static-php-cli from the manifest:
spc build "$(qbixctl ext:list --variant=full --platform=linux-x86_64 --php=8.3 --format=spc)" \
  --build-cli --build-micro --with-libs="$(qbixctl ext:list --variant=full --format=libs)"
```

### The manifest

`build/extensions.json` (schema: `build/extensions.schema.json`) holds, for every
extension: its tier, what it is for, the static-php-cli name and libraries, the
platforms whose static build leaves it out with the reason, the operating systems
it cannot exist on, and package names where they differ from the defaults. It also
holds the variants, the PHP versions, the add-on and documented drivers with their
recipes, and the default package-name patterns. It ships inside the phar.
`QBIX_EXTENSIONS_MANIFEST=/path/to/file.json` (or `--manifest=`) points the commands
at another one. `tests/unit-extensions-manifest.php` validates it.
