## 📦 Three Ways to Run

### 1. From source (needs PHP 8.1+)

```bash
php sbin/qbixserver.php
```

```bash
# Listen on a Unix domain socket (for nginx proxy)
php sbin/qbixserver.php --socket=/run/qbix/app.sock

# Both TCP and UDS simultaneously
php sbin/qbixserver.php --port=8080 --socket=/run/qbix/app.sock
```

### 2. PHAR — single ~280KB file (needs PHP)

```bash
php sbin/qbixserver.phar --port=80

# Or make it executable
chmod +x sbin/qbixserver.phar ./sbin/qbixserver.phar --port=80
```

### 3. Static binary — no PHP needed

Download the binary for your platform — no PHP installation required:

| Platform | Download |
|---|---|
| **Linux x86_64** | [qbixserver-linux-x86_64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64) |
| **Linux ARM64** | [qbixserver-linux-aarch64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-aarch64) |
| **macOS ARM64** | [qbixserver-macos-arm64](https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-macos-arm64) |

```bash
curl -L https://github.com/se7enxweb/exponential-velocity/releases/latest/download/qbixserver-linux-x86_64 -o qbixserver chmod +x qbixserver ./qbixserver --port=80
```

The binary bundles PHP 8.3 + SQLite + OpenSSL + curl into a single ~15MB executable. Copy it to any Linux or macOS machine and run. No dependencies.

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

# Output: bin/qbixserver (~15MB)
```

The binary is built using [static-php-cli](https://github.com/crazywhalecc/static-php-cli), which compiles PHP + extensions into a statically linked binary.

GitHub Actions automatically builds binaries for **Linux x86_64**, **Linux ARM64**, **macOS x86_64**, and **macOS Apple Silicon** on every tagged release.

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

- PHP 8.1 or later
- Extensions: `sockets`, `pcntl` (for signals + workers), `openssl` (for HTTPS)

```bash
# Check
php -m | grep -E 'sockets|pcntl|openssl'

# Install on Ubuntu/Debian
sudo apt install php-cli php-sockets
```

**For the static binary:**

- Nothing. The PHP runtime is included.

**Windows:** The server works without `pcntl`. Static files, PHP scripts, WebSocket, caching, compression, access control — everything works. PHP scripts run in isolated subprocesses via `proc_open`, so `exit()` and crashes won't bring down the server. You lose the preload speed benefit (each subprocess starts fresh) and signal-based graceful shutdown. For the full 100–300× concurrent capacity (measured) advantage, use Linux or macOS (or WSL).

---

---
[← Back to README](../README.md)

