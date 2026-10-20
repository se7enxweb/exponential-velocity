## 🎩 PHP 8.0 and Red Hat Enterprise Linux

The server runs on **PHP 8.0 to 8.5**. PHP 8.0 is the PHP that Red Hat
Enterprise Linux 9 ships as its default (AppStream), and the newest PHP many
RHEL 8 installations are allowed to run, so it is supported in full: the
Composer package, the phar and the EL 9 package all install and serve on it,
with nothing extra to install.

- [Why 8.0 works](#why-80-works)
- [What is different on 8.0](#what-is-different-on-80)
- [Installing on RHEL 9](#installing-on-rhel-9)
- [Installing on RHEL 8](#installing-on-rhel-8)
- [The PHP extensions it needs](#the-php-extensions-it-needs)
- [Where things go](#where-things-go)
- [systemd](#systemd)
- [SELinux and the firewall](#selinux-and-the-firewall)
- [Verifying the installation](#verifying-the-installation)

---

### Why 8.0 works

Until v0.0.4.54 the package asked for PHP 8.1. Nothing in the server needed a
language feature 8.1 introduced: every shipped file parses on 8.0, and no
function, class or constant that is newer than 8.0 is called without a check
that it exists first. What 8.1 did change is behaviour, and those places now
handle 8.0 themselves. Nothing new is required: no polyfill package, no
extension, no setting.

| What | On 8.1 and later | On 8.0 |
|---|---|---|
| A worker's statics between requests | Each request starts with the values a class declares; `ReflectionProperty::getDefaultValue()` says what they are. | PHP 8.0 keeps a class's static values in the same table as its declared defaults (unless the opcode cache made the class immutable), so `getDefaultValue()` answers with the value the property holds now. The server reads the declaration from the class's source instead and evaluates it in the class's scope and namespace (`Q_WebServer_Snapshot::declaredDefault()`). |
| Private and protected statics | Reflection reaches them by itself. | Reflection needs `setAccessible(true)`, which the server calls on 8.0 only (8.5 deprecates it). |
| The dashboard's log tail | Reads a log's current size. | PHP 8.0 kept the size from the previous poll in its stat cache; the cache is cleared first. |
| `memory_reset_peak_usage()` (8.2) | Each request's own memory peak is shown in the dashboard's live request list. | Not available: the column stays empty. |
| `imageavif()` (8.1) | Images can be negotiated to AVIF ([images.md](images.md)). | Negotiation stops at WebP. |
| `$_FILES[...]['full_path']` (8.1) | Filled, as PHP's own upload handling does. | Left out, as PHP 8.0's own upload handling does ([FormData](../src/Q/WebServer/FormData.php)). |
| The zygote ([workers.md](workers.md#forking-from-a-zygote)) | A self-test at start-up decides. | The same self-test; sockets are passed intact on 8.0 as well, so the zygote runs. |
| `Io\Poll` (8.6) | Used where PHP has it. | `stream_select()`, as on 8.1 to 8.5. |

Before v0.0.4.54, running the server on PHP 8.0 anyway (with
`--ignore-platform-reqs`, or from the phar) served pages, but a persistent
worker did not put back the statics of a class first loaded during a request:
the private and protected ones were skipped, and the public ones were "reset"
to the first request's values. Exponential keeps its siteaccess in such a
static, so after one request for the public site the administration interface
answered as the public site.

**The one case 8.0 cannot handle the way later versions do** is a class
declared by `eval()` (it has no source to read) that has static properties
with defaults. A worker that has served a request which declared one cannot
put those statics back, so it is replaced after that request, and the next
request goes to a fresh worker. The log names the property:

```
  worker 12345 replaced after 1 requests: PHP 8.0 cannot tell the declared default of Foo::$bar, so this worker could not put it back
```

An application that declares classes this way runs correctly, at the cost of
a fork per such request. On PHP 8.1 and later it never happens.

Reading a declaration costs one pass of PHP's tokenizer over the class's file,
once per class and worker, the first time a class with statics is put back.
Turning the opcode cache on for the CLI (`opcache.enable_cli=1`) is
recommended on every version ([lessons.md](lessons.md)).

---

### What is different on 8.0

For the server, nothing beyond the table above: the same workers, pool, zygote,
HTTP/1.1, HTTP/2, TLS, WebSocket, uploads, sessions, caches and console. The
test suite runs on 8.0 like on 8.1 to 8.5; the cases that need syntax 8.0
cannot parse (enums, first-class callables) are skipped there, with the reason
printed.

For your application, everything PHP 8.0 itself lacks: an application that
uses enums, `readonly`, `never`, first-class callables or `new` in
initializers needs 8.1 whatever server runs it.

PHP 8.0 reached its end of life upstream in November 2023. Red Hat supports it
in RHEL 9 for the life of that release; outside RHEL, prefer a current PHP.

---

### Installing on RHEL 9

RHEL 9 (and AlmaLinux, Rocky Linux, Oracle Linux 9) installs PHP 8.0 from
AppStream unless another stream has been enabled.

**The package.** Each release carries `exponential-velocity-<release>-1.el9.noarch.rpm`,
which depends on `php-cli >= 8.0` and the distribution's PHP packages for
everything the server needs ([packages.md](packages.md)):

```bash
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/download/v<release>/exponential-velocity-<release>-1.el9.noarch.rpm
sudo dnf install ./exponential-velocity-<release>-1.el9.noarch.rpm
```

`dnf` installs PHP 8.0 with it. Every release's EL 9 package is installed in a
clean AlmaLinux 9 container on its default PHP 8.0 and must serve a page before
the release is published. A newer stream works the same way:
`sudo dnf module enable -y php:8.2` before the install.

**Composer** (as a dependency of your application, e.g. Exponential):

```bash
sudo dnf install -y php-cli php-common php-mbstring php-process php-pdo php-xml php-opcache
composer require se7enxweb/exponential-velocity
vendor/bin/qbixserver.php --root=./ --port=8080
```

**The phar** needs only `php-cli` and the extensions below:

```bash
curl -LO https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver.phar
php qbixserver.phar --root=./web --port=8080
```

**Remi's repository** has PHP 8.0 and every later version for EL 8 and 9, and
the PECL extensions AppStream lacks (`apcu`, `igbinary`, `memcached`,
`mongodb`, `redis`):

```bash
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset -y php
sudo dnf module enable -y php:remi-8.0        # or remi-8.1 ... remi-8.5
sudo dnf install -y php-cli php-pecl-apcu
```

---

### Installing on RHEL 8

RHEL 8's default PHP is 7.2. Enable the 8.0 stream (or a later one, where your
minor release has it) first:

```bash
sudo dnf module reset -y php
sudo dnf module enable -y php:8.0
sudo dnf install -y php-cli php-common php-mbstring php-process php-pdo php-xml php-opcache php-gd php-intl
```

Then run the phar, or install with Composer, as on RHEL 9. No `.el8` package
is built: the EL 9 package names EL 9's paths and dependencies, and is not
tested on EL 8.

---

### The PHP extensions it needs

`qbixctl ext:check` lists what this PHP has and lacks, with the command that
installs each missing one on this system ([extensions.md](extensions.md)).
What the server itself needs (the `server` tier, [requirements.md](requirements.md)):

| Extension | EL package |
|---|---|
| `ctype`, `filter`, `openssl`, `phar`, `session`, `sockets`, `tokenizer` | `php-common`, `php-cli` |
| `mbstring` | `php-mbstring` |
| `pcntl`, `posix` | `php-process` |
| `sqlite3` | `php-pdo` |

The EL 9 package also depends on `php-xml`, `php-gd`, `php-intl`,
`php-mysqlnd`, `php-opcache` and `php-pecl-zip` (the `lite` set an application
platform needs), and recommends the rest of the `standard` set.

Without `pcntl` the server still runs, with workers as subprocesses instead of
forks ([architecture.md](architecture.md)); install `php-process` for the pool.

---

### Where things go

The package installs the server under `/usr/share/exponential-velocity`, the
programs in `/usr/sbin` with links in `/usr/bin`, and the configuration tree
in `/etc/qbix`, laid out like Debian's `/etc/apache2`
([layout.md](layout.md)):

```
/etc/qbix/qbix.conf  ports.conf  envvars
/etc/qbix/sites-available/   -> sites-enabled/   (qbixctl ensite / dissite)
/etc/qbix/conf-available/    -> conf-enabled/    (qbixctl enconf / disconf)
/etc/qbix/mods-available/    -> mods-enabled/    (qbixctl enmod / dismod)
/var/lib/exponential-velocity/                   state, owned by qbix
```

A distribution of the engine stacks its own tree on top of `/etc/qbix` as an
overlay; Exponential's is `/etc/vc`, with `/var/lib/vc` and `/var/log/vc`
beside it ([layout.md](layout.md#overlays-and-distributions)).
The same tree is used on RHEL as on Debian: the layout is the server's, not the
distribution's.

---

### systemd

```bash
sudo systemctl enable --now exponential-velocity
systemctl status exponential-velocity
sudo systemctl reload exponential-velocity     # graceful
journalctl -u exponential-velocity
```

The service runs as the `qbix` user, with `/var/lib/exponential-velocity` as
its working directory, and may bind ports below 1024. Its settings are in
`/etc/default/exponential-velocity` (the same file on every distribution; RHEL
has no `/etc/sysconfig` file for it). `QBIX_ROOT` there is the document root;
point it at your application and restart.

A PHP from Remi's parallel packages (`php80-php-cli` and the like, in
`/opt/remi/php80`) is not `php` on the `PATH`: start the server with it
explicitly, e.g. in a drop-in (`systemctl edit exponential-velocity`) whose
`ExecStart=` runs `/opt/remi/php80/root/usr/bin/php /usr/share/exponential-velocity/sbin/qbixserver.phar ...`.

---

### SELinux and the firewall

The unit starts `/usr/sbin/qbixserver`, an ordinary `bin_t` program, so under
RHEL's targeted policy the service runs unconfined (`unconfined_service_t`):
SELinux does not stop it binding its ports or reading the application, and no
boolean or file context is needed for the server itself. Check with:

```bash
ps -eZ | grep qbixserver
```

Two things are confined and may need a change:

- **Apache or nginx in front** (`httpd_t`), forwarding to the server's port:
  ```bash
  sudo setsebool -P httpd_can_network_connect 1
  ```
- **Files the server writes** under a tree labelled for Apache
  (`/var/www/...`, `httpd_sys_content_t`) that Apache must read or write too:
  label the writable directories `httpd_sys_rw_content_t`, as for PHP-FPM:
  ```bash
  sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/example/var(/.*)?'
  sudo restorecon -R /var/www/example/var
  ```

Open the port in `firewalld` when clients connect directly:

```bash
sudo firewall-cmd --permanent --add-port=8080/tcp && sudo firewall-cmd --reload
```

---

### Verifying the installation

```bash
php -v                                   # 8.0 or later
qbixserver --version                     # the release
qbixctl ext:check --variant=lite         # "nothing missing"
sudo systemctl start exponential-velocity
curl -s http://127.0.0.1:8080/Q/health   # {"status":"ok", ...}
```

From a source checkout, the server's own tests run on the PHP in use:

```bash
php tests/run-unit.php
```

What v0.0.4.54 was checked with on PHP 8.0.30: the whole unit suite (and on
8.1 to 8.5); the EL 9 package installed in an AlmaLinux 9 container on
AppStream's PHP 8.0, `ext:check --variant=lite` with nothing missing and a page
served as the service user; and an Exponential site on SQLite served by a
pool of persistent workers, with keep-alive, HTTPS with HTTP/2, a 3 MB upload,
sessions, a burst of concurrent requests and an administrator signing in.

---
[← Back to README](../README.md)
