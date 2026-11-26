## 📦 Three Ways to Run

### 1. From source (needs PHP 8.0+)

```bash
php sbin/qbixserver.php
```

```bash
# Listen on a Unix domain socket (for nginx proxy)
php sbin/qbixserver.php --socket=/run/qbix/app.sock

# Both TCP and UDS simultaneously
php sbin/qbixserver.php --port=8080 --socket=/run/qbix/app.sock
```

### 2. PHAR — a single file of about 6.4MB (needs PHP)

```bash
php sbin/qbixserver.phar --port=80

# Or make it executable
chmod +x sbin/qbixserver.phar
./sbin/qbixserver.phar --port=80
```

### 3. Static binary — no PHP needed

Download the binary for your platform — no PHP installation required:

| Platform | Download |
|---|---|
| **Linux x86_64** | [qbixserver-linux-x86_64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64) |
| **Linux ARM64** | [qbixserver-linux-aarch64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-aarch64) |
| **macOS ARM64** | [qbixserver-macos-arm64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-macos-arm64) |
| **Windows x64** | [qbixserver-windows-x64.exe](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-windows-x64.exe) |

```bash
curl -L https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64 -o qbixserver
chmod +x qbixserver
./qbixserver --port=80
```

These names are the `standard` extension set on PHP 8.3, about 75MB on Linux
and macOS and 37MB on Windows (0.0.4.66). Every release also carries each
platform on PHP 8.2, 8.3, 8.4 and 8.5 in four extension sets,
`qbixserver-<platform>-php<version>-<mini|lite|standard|full>`, Windows
`-gui.exe` builds that open no console, the bare PHP interpreters (`php-*`),
`qbixserver.phar`, `.deb` and `.rpm` packages ([packages.md](packages.md)), a
source kit and `SHA256SUMS`. What each set holds: [extensions.md](extensions.md).
Copy the binary to a machine of its platform and run it; nothing else is needed.

### Watchdog

`--watchdog`, or `Q.webserver.watchdog` set to `true` or to an object, forks a
watchdog at start-up (`src/Q/WebServer/Watchdog.php`). It detaches into its own
session, waits on the server and starts it again with the same command line
after a crash, waiting `backoff` seconds (2) before the first restart and twice
as long after each further one, up to `maxBackoff` (60). After `maxRestarts`
crashes (10) within an hour it gives up. A clean stop (`--stop`, SIGTERM) ends
it too. It writes its pid and log to `local/watchdog.pid` and
`local/watchdog.log` (`watchdog.pid` and `watchdog.log` move them). It needs
`pcntl` to fork; under systemd, or as a Windows service, the service manager
restarts the server instead.

---

### Option styles

Every command line here — `sbin/qbixserver.php`, `qbixconsole`, `qbixctl` and the small C
server — takes GNU and BSD spellings alike:

| Form | Meaning |
|---|---|
| `--name=V`, `--name V`, `-name=V`, `-name V` | an option that takes a value |
| `--name`, `-name` | a flag |
| `--no-name` | a flag turned off (the last spelling wins) |
| `-abc` | one-letter flags bundled (`qbixconsole`) |
| `--` | ends the options; the rest is passed on as plain arguments |

A single-dash word is read as a long option only when it is a known option name, so
one-letter options (`-t`, `-h`, `-v`) keep their meaning. A value option takes the next
argument only when that does not start with a dash, so `--open` (whose value is
optional) and `--root --debug` mean what they always did: give an optional value with
`=`, as in `--open=/admin`.

Every option and command of the three is listed in [console.md](console.md).

## 🔨 Building

### Build the PHAR

```bash
php -d phar.readonly=0 build-phar.php
# Output: sbin/qbixserver.phar (and the same file at bin/qbixserver.phar)
```

### Build the static binary

```bash
# With Docker (easiest):
./build-binary.sh --docker

# With static-php-cli installed locally:
./build-binary.sh

# Options: --os=linux|macos --arch=x86_64|aarch64 --php=8.2|8.3|8.4|8.5
#          --variant=mini|lite|standard|full   (defaults: linux, this machine's architecture, 8.3, standard)
# Output: sbin/qbixserver, beside the phar it is made from
```

The binary is built using [static-php-cli](https://github.com/crazywhalecc/static-php-cli), which compiles PHP + extensions into a statically linked binary.

GitHub Actions (`.github/workflows/release.yml`) builds binaries for **Linux x86_64**, **Linux ARM64**, **macOS Apple Silicon** and **Windows x64**, each on PHP 8.2 to 8.5 in every extension set, on every tagged release.

---

## 🔌 With Qbix Platform

Velocity's engine was extracted from the [Qbix Platform](https://github.com/Qbix/Platform) — a full-stack framework for building social apps with real-time streams, user management, and plugin architecture.

When you have a Qbix app, the server uses the full framework:

```bash
php sbin/qbixserver.php --app=/path/to/myapp --port=80
```

In this mode:

- Requests route through `Q_Dispatcher` — the full Qbix event pipeline
- Plugins load automatically (Users, Streams, Assets, etc.)
- Clean URLs work (`/community/123` → module routing)
- Static files still use the fast path (no framework overhead)
- The dashboard shows Qbix-specific stats

The standalone mode (without `--app`) runs as a plain web server — no framework, no plugins. PHP files execute directly, static files serve from memory. Use this for simple sites, APIs, or any project that doesn't need the full Qbix stack.

### Qbix Platform scripts

The full Platform includes additional server scripts like `static.php` for CDN-style static file serving with versioned URLs. See the [Platform repository](https://github.com/Qbix/Platform) for details.

---

## 📋 Requirements

**Linux / macOS (recommended):**

- PHP 8.0 or later (8.0 to 8.5; on Red Hat Enterprise Linux see [rhel.md](rhel.md))
- Extensions (the `server` tier, [requirements.md](requirements.md)): `ctype`,
  `filter`, `mbstring`, `openssl` (for HTTPS), `pcntl` and `posix` (for signals and
  the forked pool), `phar`, `session`, `sockets`, `sqlite3`, `tokenizer`

```bash
# Check, with the install command for this system
php sbin/qbixctl.php ext:check --variant=mini

# Install on Ubuntu/Debian
sudo apt install php-cli php-common php-mbstring php-sqlite3

# Install on RHEL, AlmaLinux, Rocky 9 (PHP 8.0 from AppStream)
sudo dnf install php-cli php-common php-process php-mbstring php-pdo php-xml
```

**For the static binary:**

- Nothing. The PHP runtime is included.

**Windows:** The server runs on the official PHP for Windows without `pcntl`. PHP requests go to a portable pool of persistent workers, each a process of its own started with `proc_open` (`--pool-mode=portable`, chosen automatically where there is no fork), so `exit()` and crashes do not bring down the server. Each worker boots the application itself rather than sharing the parent's memory copy-on-write, so keep the worker count modest. Stop and reload work through files beside the pid file instead of signals, and `packaging/windows/vcc.ps1` installs it as a Windows service. CI runs it on Windows Server 2022 and 2025 (x64 and x86). See [windows.md](windows.md).

---

---
[← Back to README](../README.md)

