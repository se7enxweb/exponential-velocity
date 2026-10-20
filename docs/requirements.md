## 📋 Requirements: the PHP Extensions This Server Provides

This page is the standard: which PHP versions the server is built for, which
extensions every build carries, which ones a form of distribution cannot carry and
why, and what the server does when the PHP it runs on lacks something. The list
itself lives in one file, `build/extensions.json`; builds, the start-up check, the
dashboard and this page all read it. [extensions.md](extensions.md) shows how to
check a machine, plan a build and install what is missing.

- [PHP versions](#php-versions)
- [Tiers](#tiers)
- [Variants](#variants)
- [Databases](#databases)
- [Caching and images](#caching-and-images)
- [Forms of distribution and their exceptions](#forms-of-distribution-and-their-exceptions)
- [At start-up](#at-start-up)
- [Commands](#commands)
- [Every extension](#every-extension)

### PHP versions

The static binaries are built for **PHP 8.2, 8.3, 8.4 and 8.5**. 8.3 is the default.
The phar, Composer package, Docker image and OS packages run on any PHP from **8.0**
to 8.5 ([PHP 8.0 and Red Hat Enterprise Linux](rhel.md)).

| Version | Note |
|---|---|
| 8.0 | The default PHP of RHEL 9 (AppStream). No static binary is built for it. AVIF images and the dashboard's per-request memory peak need 8.1 and 8.2; see [rhel.md](rhel.md#what-is-different-on-80). |
| 8.4 | `imap`, `oci8` and `pdo_oci` are no longer bundled with PHP; they build from PECL. |
| 8.5 | `opcache` is always compiled in and cannot be left out. |

Always-present parts of PHP are not listed below, since no build can lack them:
Core, `date`, `hash`, `json`, `pcre`, `random` (from PHP 8.2), `Reflection`, `SPL` and `standard`.

### Tiers

Every extension belongs to one tier.

| Tier | What it means |
|---|---|
| **server** | What the server itself needs to run and to isolate requests. |
| **required** | What the standard application platform needs to run. A PHP without one of these is not a supported platform. |
| **recommended** | The default extensions of the platform, every database driver that can be linked statically, and caching. |
| **extra** | Everything else static-php-cli can build on that platform. |
| **other** | Known, but in no variant: development tools (xdebug, pcov, profilers), runtimes that conflict with the server's own workers (swoole, swow), and drivers that need a proprietary library at run time. Add one to a build with `ext:build --with=`. |

### Variants

Variants stack tiers, so each one carries everything the smaller ones do.

| Variant | Tiers | For |
|---|---|---|
| **mini** | server | The smallest binary: the server and plain PHP applications. |
| **lite** | server, required | The standard application platform, with nothing extra. |
| **standard** | server, required, recommended | The default: lite plus every static database driver, caching and full image support. |
| **full** | server, required, recommended, extra | Everything static-php-cli can build on the platform. |
| **source** | as full | Not a binary: a kit (the phar, the manifest, the console and the static-php-cli recipe, with `BUILD.md`) that builds any variant on the machine it is for. |

How many extensions a variant has on each static platform (PHP 8.3):

| Variant | Linux x86-64 | Linux ARM64 | macOS | Windows |
|---|---|---|---|---|
| mini | 11 | 11 | 11 | 9 |
| lite | 30 | 30 | 30 | 26 |
| standard | 49 | 49 | 51 | 40 |
| full | 93 | 93 | 95 | 66 |

### Databases

| Database | Extensions | How it is provided |
|---|---|---|
| MySQL / MariaDB | `mysqli`, `mysqlnd`, `pdo_mysql` | Built in (required tier) |
| PostgreSQL | `pgsql`, `pdo_pgsql` | Built in (recommended) |
| SQLite | `sqlite3`, `pdo_sqlite` | Built in (`sqlite3` is in the server tier) |
| MongoDB | `mongodb` | Built in (recommended); not in the Windows binary |
| ODBC | `odbc`, `pdo_odbc` | Built in (recommended); see the static Linux exception below |
| Oracle | `oci8`, `pdo_oci` | **Loadable add-on**: needs Oracle Instant Client, a proprietary shared library that cannot be linked into a static binary. Included in the Docker image; `ext:install-hint oci8` prints the steps elsewhere. |
| Firebird | `pdo_firebird` | **Loadable add-on**: needs the Firebird client library. Included in the Docker image. (The old `interbase` extension was removed from PHP in 7.4.) |
| SQL Server | `sqlsrv`, `pdo_sqlsrv` | **Documented install**: needs Microsoft's ODBC Driver for SQL Server at run time. `ext:install-hint sqlsrv` prints the steps. |
| Microsoft Access | through `odbc` / `pdo_odbc` | **Documented install**: an Access ODBC driver (mdbtools on Linux, the Access Database Engine on Windows). `ext:install-hint msaccess` prints the steps. |

`qbixctl ext:check` reports an add-on as present when it is loaded; it never counts
a missing add-on or documented driver against the standard set.

### Caching and images

Caching in the standard set: **opcache** (with JIT), **apcu**, **redis** (Redis and
Valkey), **memcached** and **igbinary**. The older `memcache` client is in the extra
tier for libraries that still use it.

Images: **gd**, built with JPEG, PNG, WebP and FreeType, and **exif** for photo
metadata. The check looks inside gd: a gd without WebP or FreeType is reported as
incomplete, not as present. `imagick` is in the extra tier.

### Forms of distribution and their exceptions

Every form meets this standard except where a line below says otherwise.

| Form | What it carries | Exceptions |
|---|---|---|
| **Static binaries** ([binaries.md](binaries.md)) | The variant named in the file, compiled in | Per platform, below. A static binary cannot load extensions or C libraries at run time, so the loadable add-ons and documented drivers are not available in it. |
| **phar / Composer** | Whatever the host PHP has | Nothing can be added by the server; at start and on the dashboard it reports what the host PHP lacks, with the install command for that system. |
| **Docker image** ([docker.md](docker.md)) | The standard set, plus the Oracle and Firebird add-ons and ODBC drivers | On Debian (glibc), so Oracle Instant Client works. |
| **OS packages** ([packages.md](packages.md)) | Depend on the distribution's own PHP extension packages | Whatever that distribution does not package for its PHP version is reported by `ext:check` after install. |

Static-binary exceptions, by platform:

- **Windows**: no `pcntl` or `posix` (there is no `fork()`; isolation comes from
  `qbix_fork.dll`, or php-cgi), no `intl` (the ICU bundle needs C++17, which PHP's
  Windows build does not use for intl) and no `xsl` (static-php-cli cannot build
  libxslt there), plus the extensions static-php-cli does not build for Windows yet
  (`gmp`, `ldap`, `memcached`, `mongodb`, `odbc`, `readline`, `gettext` and others;
  `ext:plan --platform=windows-x64` lists them all). [binaries.md](binaries.md) has
  the detail and what would bring each back.
- **Linux (x86-64 and ARM64)**: the binaries are fully static (musl), which cannot
  load ODBC driver libraries at run time, so `odbc` and `pdo_odbc` are left out. Use
  the Docker image or OS packages for ODBC. `ffi` is left out for the same reason.
- **macOS**: carries the whole standard set, ODBC included.

A distribution PHP is judged by its own rules: the check applies a static build's
exceptions only when it runs inside one, and otherwise only what cannot exist on
the OS at all (such as `pcntl` on Windows).

### At start-up

- **Refuses to start** only without what this configuration cannot run without:
  process isolation (`pcntl`, or a php-cgi binary), `tokenizer` while the source
  transform is on, and `openssl` when HTTPS is configured. It prints the install
  command and exits.
- **Warns once** when extensions from the standard set are missing, listing them by
  tier with the install command for this system. `Q.webserver.extensionsCheck =
  false` silences the warning; it never skips the refusal.
- **Reports** the same on the dashboard's Extensions card and in `/Q/health` under
  `extensions`: the largest variant this PHP provides (`variant_detected`), what is
  missing per tier, incomplete extensions, and the install commands.

### Commands

| Command | Does |
|---|---|
| `qbixctl ext:check` | This PHP against the standard set. Exit 0: nothing missing; 1: a required extension is missing; 2: only recommended ones are. `--format=json` for scripts. |
| `qbixctl ext:list --variant=standard --platform=linux-x86_64 --php=8.3` | What a variant carries. `--format=spc` and `--format=libs` print what a build passes to static-php-cli. |
| `qbixctl ext:plan --variant=full --platform=windows-x64` | What a build includes, what it leaves out and why, and the commands. |
| `qbixctl ext:install-hint intl redis` | The install commands for this system (apt, dnf, Remi collections, Plesk, apk, pkg, Homebrew or Windows). With no names: everything `ext:check` finds missing. Also takes `oci8`, `pdo_oci`, `pdo_firebird`, `sqlsrv`, `pdo_sqlsrv` and `msaccess`. |
| `qbixctl ext:build --variant=standard --php=8.4` | Builds a static PHP and server binary for this machine with static-php-cli; `--variant=source` writes the kit. `--dry-run` prints the commands. |

[extensions.md](extensions.md) walks through each.

### Every extension

Generated from `build/extensions.json`; "not in the static build for" lists the
platforms whose binary leaves the extension out (see the exceptions above).

#### Server

| Extension | What it is for | Not in the static build for |
|---|---|---|
| `ctype` | Character-class checks used when parsing requests and configuration |  |
| `filter` | Validating host names, addresses and URLs in requests |  |
| `mbstring` | Multibyte strings: request parsing, templates and text handling |  |
| `openssl` | TLS for HTTPS, certificates, ACME and secure random bytes |  |
| `pcntl` | Forking a worker per request (the process isolation the pool relies on) | Windows |
| `phar` | Running the server from its single-file archive |  |
| `posix` | Process and user control for workers, pid files and signals | Windows |
| `session` | PHP sessions, kept correct across persistent workers |  |
| `sockets` | TCP options (no-delay, keep-alive) on client connections |  |
| `sqlite3` | The server's own metrics store |  |
| `tokenizer` | The source transform that lets applications run in persistent workers |  |

#### Required

| Extension | What it is for | Not in the static build for |
|---|---|---|
| `curl` | Outgoing HTTP: package downloads, feeds, web services, cache warming |  |
| `dom` | XML documents: rich text, packages, feeds, WebDAV |  |
| `fileinfo` | Detecting the MIME type of uploaded and stored files |  |
| `gd` | Image aliases: resizing, cropping and converting images (with JPEG, PNG, WebP and FreeType) |  |
| `iconv` | Character-set conversion for content, mail and the console |  |
| `intl` | Locale-aware formatting, collation and transliteration | Windows |
| `libxml` | The XML library behind dom, simplexml, xml, xmlreader, xmlwriter and xsl |  |
| `mysqli` | The default MySQL/MariaDB database handler |  |
| `mysqlnd` | The native MySQL driver mysqli and pdo_mysql use |  |
| `opcache` | Opcode cache (and JIT); always compiled in from PHP 8.5 |  |
| `pdo` | Database abstraction layer used by persistent-object libraries and tools |  |
| `pdo_mysql` | PDO driver for MySQL/MariaDB |  |
| `simplexml` | Reading XML settings, metadata and package descriptions |  |
| `xml` | Event-based XML parsing |  |
| `xmlreader` | Streaming XML reading for large imports |  |
| `xmlwriter` | Streaming XML output for AJAX and feeds |  |
| `xsl` | XSLT transforms for document import and export | Windows |
| `zip` | Zip archives: package import and export, document formats |  |
| `zlib` | Compression: gzip responses, compressed cache and archives |  |

#### Recommended

| Extension | What it is for | Not in the static build for |
|---|---|---|
| `apcu` | In-memory user cache for cache handlers |  |
| `bcmath` | Arbitrary-precision arithmetic for prices and authentication maths |  |
| `bz2` | bzip2 archives |  |
| `calendar` | Calendar conversions used by date helpers |  |
| `exif` | Reading photo metadata (orientation, camera data) on upload |  |
| `ftp` | FTP transfers for deployment and import tools |  |
| `gettext` | gettext translations for components that use them | Windows |
| `gmp` | Big-integer maths for authentication libraries | Windows |
| `igbinary` | Compact binary serializer for caches (used by redis and memcached) |  |
| `ldap` | Signing users in against LDAP / Active Directory | Windows |
| `memcached` | Memcached cache backend | Windows |
| `mongodb` | MongoDB database driver | Windows |
| `odbc` | ODBC database access (unixODBC) | Linux x86-64, Linux ARM64, Windows |
| `pdo_odbc` | PDO driver for ODBC data sources | Linux x86-64, Linux ARM64 |
| `pdo_pgsql` | PDO driver for PostgreSQL |  |
| `pdo_sqlite` | PDO driver for SQLite |  |
| `pgsql` | PostgreSQL database handler |  |
| `readline` | Interactive console input for command-line tools | Windows |
| `redis` | Redis / Valkey cache backend |  |
| `soap` | SOAP web-service clients and servers |  |
| `sodium` | Modern cryptography: signing, sealed boxes, Argon2 hashing |  |

#### Extra

| Extension | What it is for | Not in the static build for |
|---|---|---|
| `amqp` | RabbitMQ / AMQP messaging |  |
| `ast` | PHP abstract syntax trees (static analysis) |  |
| `brotli` | Brotli compression |  |
| `com_dotnet` | COM and .NET interop (Windows only) | Linux x86-64, Linux ARM64, macOS |
| `dba` | Berkeley-style key-value databases |  |
| `decimal` | Arbitrary-precision decimals |  |
| `deepclone` | Fast deep object cloning |  |
| `dio` | Direct low-level I/O (serial ports, devices) |  |
| `ds` | Efficient data structures |  |
| `ev` | libev event loop: epoll/kqueue for the Revolt event loop backend (see architecture.md) |  |
| `event` | libevent event loop: epoll/kqueue for the Revolt event loop backend (see architecture.md) | Windows |
| `ffi` | Calling C libraries from PHP | Linux x86-64, Linux ARM64 |
| `gmssl` | Chinese national cryptography (SM2/3/4) |  |
| `grpc` | gRPC clients | Windows |
| `imagick` | ImageMagick image processing | Windows |
| `imap` | IMAP/POP3 mail access (unbundled from PHP 8.4, built from PECL) | Windows |
| `inotify` | Linux file-change notifications | macOS, Windows |
| `lz4` | LZ4 compression | Windows |
| `maxminddb` | MaxMind GeoIP lookups | Windows |
| `mbregex` | Multibyte regular expressions for mbstring |  |
| `memcache` | The older memcache client, for libraries that still use it | Windows |
| `msgpack` | MessagePack serialization |  |
| `mysqlnd_ed25519` | MariaDB ed25519 authentication |  |
| `mysqlnd_parsec` | MariaDB PARSEC authentication |  |
| `opentelemetry` | OpenTelemetry tracing |  |
| `password-argon2` | Argon2 password hashing | Windows |
| `protobuf` | Protocol Buffers | Windows |
| `rar` | RAR archives |  |
| `rdkafka` | Kafka clients | Windows |
| `shmop` | Shared memory segments |  |
| `simdjson` | Very fast JSON decoding |  |
| `snappy` | Snappy compression | Windows |
| `snmp` | SNMP network management | Windows |
| `ssh2` | SSH and SFTP clients |  |
| `sysvmsg` | System V message queues | Windows |
| `sysvsem` | System V semaphores | Windows |
| `sysvshm` | System V shared memory |  |
| `tidy` | HTML clean-up and repair | Windows |
| `trader` | Technical-analysis maths | Windows |
| `uuid` | UUID generation | Windows |
| `uv` | libuv event loop: epoll/kqueue for the Revolt event loop backend (see architecture.md) | Windows |
| `xlswriter` | Writing Excel files |  |
| `xz` | XZ / LZMA compression |  |
| `yac` | Lock-free shared-memory cache |  |
| `yaml` | YAML parsing |  |
| `zstd` | Zstandard compression | Windows |

#### Other (in no variant)

| Extension | What it is for | Not in the static build for |
|---|---|---|
| `enchant` | Not yet buildable by static-php-cli | Linux x86-64, Linux ARM64, macOS, Windows |
| `excimer` | A sampling profiler, for development only | Windows |
| `glfw` | Desktop windowing, not for a server | Linux x86-64, Linux ARM64, Windows |
| `mcrypt` | Removed from PHP; not buildable | Linux x86-64, Linux ARM64, macOS, Windows |
| `oci8` | Needs Oracle Instant Client at run time: provided as a loadable add-on instead | Linux x86-64, Linux ARM64, macOS, Windows |
| `parallel` | Needs a thread-safe (ZTS) PHP; the builds are non-thread-safe |  |
| `pcov` | Code coverage, for development only |  |
| `pdo_sqlsrv` | Needs Microsoft's ODBC driver at run time: documented install instead |  |
| `spx` | A profiler, for development only | Windows |
| `sqlsrv` | Needs Microsoft's ODBC driver at run time: documented install instead |  |
| `swoole` | Its own event loop and server; conflicts with this server's workers | Windows |
| `swoole-hook-mysql` | Swoole add-on | Windows |
| `swoole-hook-odbc` | Swoole add-on | Windows |
| `swoole-hook-pgsql` | Swoole add-on | Windows |
| `swoole-hook-sqlite` | Swoole add-on | Windows |
| `swow` | Its own coroutine runtime; conflicts with this server's workers |  |
| `xdebug` | A debugger: slows every request, for development only | Windows |
| `xhprof` | A profiler, for development only | Windows |
