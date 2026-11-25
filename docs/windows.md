# Running on Windows

Velocity runs on Windows with the official PHP for Windows: persistent PHP
workers (the portable pool), HTTPS, a complete Exponential site, and a Windows
service that runs under a low-privilege virtual account. This page says what
is **proven** (run in CI on real Windows, every push), what is **theory**
(designed and expected to work, not yet run), and what is **missing**.

- [Support matrix](#support-matrix)
- [What the tests prove](#what-the-tests-prove)
- [Quick start](#quick-start)
- [Running it as a Windows service (vcc.ps1)](#running-it-as-a-windows-service-vccps1)
- [The configuration tree on Windows](#the-configuration-tree-on-windows)
- [HTTPS: PEM files and the Windows certificate store](#https-pem-files-and-the-windows-certificate-store)
- [How Velocity maps to Windows APIs](#how-velocity-maps-to-windows-apis)
- [The portable pool](#the-portable-pool)
- [Stop, reload, CTRL+C](#stop-reload-ctrlc)
- [Known gaps](#known-gaps)
- [Testing on a real machine](#testing-on-a-real-machine)

## Support matrix

"Proven" means the `windows` workflow (`.github/workflows/windows.yml`) runs it
on that system on every push and it passes; the results of the last run are in
[Results](#results). Everything else is marked for what it is.

| Windows | Architecture | PHP | Status |
|---|---|---|---|
| Windows Server 2022 | x64 (win64) | 8.1, 8.3, 8.5 | **proven**: unit gate, smoke, HTTPS; with 8.3 also the Exponential site, load and the service |
| Windows Server 2025 | x64 (win64) | 8.1, 8.3, 8.5 | **proven**: unit gate, smoke, HTTPS; with 8.5 also the Exponential site, load and the service |
| Windows Server 2022 | x86 (win32, 32-bit PHP) | 8.3 | **proven**: unit gate, smoke, HTTPS |
| Windows 11 Enterprise 25H2 (build 26200) | ARM64 machine, x64 PHP under emulation | 8.3 | **proven**: unit gate, smoke, HTTPS, Exponential site, load, service |
| Windows 11 on ARM | native ARM64 PHP | | **not possible yet**: windows.php.net publishes no ARM64 build of PHP (x64 and x86 only); a self-built ARM64 PHP is untested |
| Windows 11 / 10 desktop | x64 | 8.x | **theory**: same kernel, APIs and PHP builds as the proven Server 2022/2025 and Windows 11 ARM jobs; GitHub has no x64 Windows client runner, so it needs a real machine or VM |
| Windows 10 22H2 | x64, x86 | 8.x | **theory**, as above; Windows 10 is out of mainstream support since October 2025 |
| Windows Server 2019 | x64 | 8.x | **theory**: GitHub retired its 2019 runner; nothing used here is newer than Server 2016 APIs (curl.exe and `New-SelfSignedCertificate` options need 2019's) |
| Physical machines, long runs, real traffic | any | | **not tried** |

PHP: 8.0 or later (the CI covers 8.1, 8.3 and 8.5), NTS or TS, from
windows.php.net or `setup-php`. Velocity itself needs the `sockets` and
`openssl` extensions, `mbstring`, and `sqlite3` for its metrics; Exponential
adds `intl`, `xsl`, `gd`, `curl`, `fileinfo`, `pdo_sqlite`, `zip` and `exif`.

Two things the zip from windows.php.net does not do by itself, both found by
the CI:

- **No CA bundle.** PHP for Windows verifies HTTPS certificates but ships no
  list of trusted CAs, so every outgoing HTTPS request fails: Exponential's
  installer cannot fetch its site packages ("Retrieving remote site packages
  list failed"), and ACME cannot reach Let's Encrypt. Download
  <https://curl.se/ca/cacert.pem> and set `curl.cainfo` and `openssl.cafile` in
  `php.ini` to it (`tests/windows/install-php.ps1` does).
- **No OpenSSL legacy provider in the 32-bit build.** A `.pfx` encrypted the
  old way (TripleDES/RC2, the default of `Export-PfxCertificate` and of many
  tools) cannot be read there; export with
  `-CryptoAlgorithmOption AES256_SHA256` (`vcc.ps1 cert-import` does).

### Results

The last run of the `windows` workflow on the branch this was written on:
see the run linked from the release notes, or the Actions tab. Each job
uploads its logs (server log, worker trace, the Exponential installation
log with the password masked) as an artifact.

## What the tests prove

| Test | What it checks | Where |
|---|---|---|
| Windows unit gate (`tests/windows/unit-survey.ps1 -Gate`) | the tests listed in `tests/windows/unit-windows.txt`: the portable pool, the `%ProgramData%` layout and enabling sites without symlinks | every job |
| Smoke (`tests/windows/smoke.ps1`) | a real server with two portable workers: pages answered by workers, worker reuse, a killed worker replaced, a fatal error answering 500, a graceful `--stop` that ends every worker and frees the port | every job |
| HTTPS (`tests/windows/https-smoke.ps1`) | a self-signed certificate made by the server; a certificate from `Cert:\CurrentUser\My` exported as `.pfx` and served through `mode: pkcs12`, with the same thumbprint presented; TLS 1.2 or later; plain HTTP beside it; graceful stop | every job |
| Exponential fixture (`tests/windows/exponential-fixture.ps1`) | the public `se7enxweb/exponential` repository, `composer install`, and `exp:install` on SQLite: the documented installation, on Windows | Server 2022, Server 2025, Windows 11 ARM |
| Exponential site (`tests/windows/exponential-site.ps1`) | `--preset=exponential` with persistent portable workers: `/`, `/site/`, `/editor/`, `/admin/user/login` answer 200, a static file is served, an unknown path is 404, the administrator logs in and reaches the dashboard; three rounds of 300 requests at concurrency 8 where every answer is good, no worker dies, worker memory levels off (round 2 to 3 under 16 MB or 15 %), the server's handle count does not grow by 100; the session survives; graceful stop | Server 2022, Server 2025, Windows 11 ARM |
| Service (`tests/windows/service.ps1`) | `vcc.ps1` doctor, install (and `-DryRun`), the `%ProgramData%\vc` tree and its ACL, start, the PHP processes running as `NT SERVICE\vc-ci`, that account denied writing into `C:\Windows` and allowed the one directory granted to it, the event log, `cert-import` from `Cert:\LocalMachine\My` served over HTTPS, recovery by the Service Control Manager after the server is killed, `site-disable`/`site-enable`, graceful stop, uninstall leaving no service, event source or ACL entry; then the Exponential site served by the service under its own virtual account, administrator login included | Server 2022, Server 2025, Windows 11 ARM |
| Unit survey (`tests/windows/unit-survey.ps1`) | every `tests/unit-*.php` on Windows, recorded in the job summary; not a gate, because many exercise fork, signals, `/proc` or POSIX permissions on purpose | Server 2025 |

## Quick start

From a console, with PHP for Windows in `C:\php` and Velocity in `C:\vc`:

```bat
C:\php\php.exe C:\vc\sbin\qbixserver.php --root=C:\sites\example --port=8080 --pid=C:\sites\vc.pid
rem in another console:
C:\php\php.exe C:\vc\sbin\qbixserver.php --stop --pid=C:\sites\vc.pid
```

An Exponential site: add `--preset=exponential` and point `--root` at the
installation. For a site that should run without anybody logged in, install it
as a service (next section).

## Running it as a Windows service (vcc.ps1)

`packaging\windows\vcc.ps1` is the Windows counterpart of `vcc` ("vc control",
which runs Plesk sites on Velocity on Linux). It lays out the configuration
tree, installs one Windows service per site, and gives it a low-privilege
account. Run it from an elevated PowerShell (Windows PowerShell 5.1 or
PowerShell 7):

```powershell
cd C:\vc\packaging\windows
.\vcc.ps1 doctor  -Php C:\php\php.exe
.\vcc.ps1 install -Php C:\php\php.exe -Root C:\sites\example -Port 8080 -Preset exponential -WritableDirs var
.\vcc.ps1 start
.\vcc.ps1 status            # -Json for a program
.\vcc.ps1 logs -Tail 50
.\vcc.ps1 stop
.\vcc.ps1 uninstall         # -Purge also removes %ProgramData%\vc
```

| Command | Does |
|---|---|
| `doctor` | Windows version and architecture, php.exe and its extensions, the C# compiler, the source tree |
| `install` | the tree, the site file `sites-available\<site>.conf` and its enabling, the service host, the service, the account, ACLs, event log source, failure actions; `-OpenFirewall` adds an inbound rule for the site's ports |
| `start`, `stop`, `restart`, `status` | the service; stop is Velocity's graceful stop |
| `site-add`, `site-list`, `site-enable`, `site-disable` | the `sites-available` / `sites-enabled` pair, as `a2ensite` |
| `cert-import -Thumbprint X [-HttpsPort 443]` | serve a certificate from the Windows certificate store |
| `logs` | the server's log; start, stop and failures are also in the Application event log under the service's name |
| `uninstall` | the service, its ACL entries (by the account's SID), the event source and the firewall rule; the tree stays unless `-Purge` |

Every command ends with `PASS`, `FAIL`, `USAGE` or `REFUSED` and exits 0, 1,
2 or 3, as vcc does; `-DryRun` prints what would be done and changes nothing.
Options: `-Name` (service name, default `vc`; one service per site), `-Site`
(site file name, default `default`), `-Php`, `-Root`, `-Port`, `-HttpsPort`,
`-Workers`, `-Preset`, `-WritableDirs`, `-Account`, `-StartupType`, `-Grace`
(seconds a stop may take before the process tree is ended, 25).

### How the service works

PHP cannot answer the Service Control Manager itself: `php.exe` registered as a
service is ended after 30 seconds with error 1053. `vcc.ps1 install` therefore
compiles a small service host, `VcService.exe`, from
`packaging\windows\VcService.cs` with the C# compiler that ships with the .NET
Framework 4.x in every supported Windows (`csc.exe`): nothing is downloaded and
no binary is kept in the repository. It is AnyCPU, so it runs natively on x86,
x64 and ARM64. It:

- starts `php.exe sbin\qbixserver.php --distribution=vc --config=<site file> --root=<root> --pid=<run>\<name>.pid --pool-mode=portable`,
  with `TEMP`/`TMP` pointing at `%ProgramData%\vc\run\tmp-<name>`, and appends the
  server's output to `%ProgramData%\vc\logs\<name>-server.log`;
- turns the SCM's Stop and Shutdown into Velocity's graceful stop (the request
  file `<pidfile>.stop`), and ends the process tree with `taskkill /T /F` only if
  the server is still running after the grace period;
- exits with an error when the server ends on its own, so the failure actions
  `vcc.ps1` sets (restart after 5 s, 5 s, 30 s) bring it back;
- writes start, stop and unexpected exits to the Application event log.

### The account: a virtual account, not SYSTEM

The default account is the service's own virtual account, `NT SERVICE\<name>`:
no password, no logon rights, a SID of its own (`S-1-5-80-...`), and nothing
granted except what `vcc.ps1` grants. This is the Windows equivalent of the
POSIX model, where the server runs as its own unprivileged user:

| | Linux | Windows |
|---|---|---|
| who the server runs as | `vc`/`qbix` user (or per-site pool users) | `NT SERVICE\<name>` (virtual account) |
| configuration | `/etc/vc`, root-owned, world-readable | `%ProgramData%\vc`, inheritance from `%ProgramData%` cut; SYSTEM and Administrators full control, the service account read |
| what it may write | `/var/log/vc`, `/var/lib/vc`, `/run/vc` | `logs`, `state`, `run`, `cache`, `ssl` under `%ProgramData%\vc` |
| the site | read; writable directories by group | read; `-WritableDirs` get Modify (Exponential: `var`) |
| ports below 1024 | `CAP_NET_BIND_SERVICE` | no privilege needed on Windows; only a port reserved by `http.sys` (IIS) conflicts |

`-Account LocalService`, `NetworkService` or a named account (with
`-Password`) are possible; `LocalSystem` only when asked for, with a warning.
Note that `%ProgramData%` lets every user create files in new folders by
default, which is why the tree's inheritance is cut: otherwise any local user
could drop a site file into `sites-enabled`.

## The configuration tree on Windows

Windows has no `/etc`, `/var` or `/run`. The convention is one folder per
program under `%ProgramData%`, so each tree of the Debian Apache layout
([layout.md](layout.md)) becomes one folder holding all of it
(`Q_WebServer_Layout::platformDir()`):

| POSIX | Windows |
|---|---|
| `/etc/vc` (`vc.conf`, `ports.conf`, `envvars`, `*-available`, `*-enabled`, `ssl`, `designs`) | `%ProgramData%\vc` |
| `/var/lib/vc` | `%ProgramData%\vc\state` |
| `/var/log/vc` | `%ProgramData%\vc\logs` |
| `/var/cache/vc` | `%ProgramData%\vc\cache` |
| `/run/vc` | `%ProgramData%\vc\run` |
| `/etc/qbix`, `/var/log/qbix` ... | `%ProgramData%\qbix`, `%ProgramData%\qbix\logs` ... |

`QBIX_CONF_DIR`, `VC_CONF_DIR`, `QBIX_STATE_DIR` and `QBIX_LOG_DIR` still move a
tree. Paths compare without regard to letter case or the kind of slash.

`sites-enabled` holds symbolic links on POSIX. Windows lets only
administrators (or Developer Mode) create symbolic links, so enabling falls
back to a hard link and then to a copy. Either counts as enabled; enabling
again refreshes one that no longer matches its `sites-available` file (an
editor that saves by replacing the file breaks a hard link), and disabling
removes one that still matches. `vcc.ps1` runs elevated and gets real
symbolic links (with full target paths: PHP for Windows resolves a relative
target against the working directory).

## HTTPS: PEM files and the Windows certificate store

Velocity's TLS is PHP's OpenSSL, the same on Windows as elsewhere. Every
certificate source in [https.md](https.md) works from a file:

- **PEM files** (`mode: files`): the usual `cert` and `key`, with Windows paths.
- **Self-signed** (`mode: self-signed`): made by the server; proven in CI.
- **The Windows certificate store**: Windows keeps certificates in its store,
  not in files, and a private key there may be non-exportable (or held by a
  TPM or smart card). `vcc.ps1 cert-import -Thumbprint <X>` exports a
  certificate *with an exportable key* from `Cert:\LocalMachine\My` as a
  PKCS#12 bundle into `%ProgramData%\vc\ssl` (readable only by SYSTEM,
  Administrators and the service account), with a random password in a file
  beside it, and switches the site to `mode: pkcs12`. Proven in CI, including
  that the server presents exactly that certificate.
- **Let's Encrypt** (`mode: letsencrypt`) needs nothing Windows-specific and is
  **theory** on Windows: the ACME client is pure PHP, but it has not been run
  against a CA from a Windows machine.

Not supported: serving straight from the store through Schannel, or a key that
cannot be exported. That would need a TLS stack other than OpenSSL (or
`http.sys` in front); renewing a store certificate means running `cert-import`
again.

## How Velocity maps to Windows APIs

| Area | POSIX | Windows | Status |
|---|---|---|---|
| sockets | `stream_select()` (and an evented backend where the extension exists) | `stream_select()`, i.e. Winsock `select()`; the official builds limit it to 256 sockets (`FD_SETSIZE`), the server warns near it | proven (smoke, load); IOCP is not used: PHP has no IOCP API, and `select()` over a few hundred sockets is enough for the worker counts used on Windows |
| workers | `pcntl_fork()`, copy-on-write | `proc_open()` with `bypass_shell`, a process per worker, in its own process group; each boots the application itself | proven |
| worker connection | Unix socket (0600) | loopback TCP with a per-worker token on standard input; named pipes are not used (PHP's streams cannot listen on one) | proven |
| control lines | the worker's standard output (pipe) | a file per worker in the temporary directory (reading pipes stalled the server on the first runs) | proven |
| stop, reload | SIGTERM, SIGHUP | request files `<pidfile>.stop` / `.reload`; CTRL+C and CTRL+BREAK via `sapi_windows_set_ctrl_handler()` | proven (stop); reload by hand only |
| process liveness, ending | `/proc`, `posix_kill()` | `tasklist`, `taskkill /T` | proven |
| service manager | systemd unit | the SCM, through `VcService.exe` (`ServiceBase`); failure actions for restarts | proven |
| logging | files, journald | files; the Application event log for the service's start, stop and failures | proven |
| file locking | `flock()` (advisory) | `flock()` maps to `LockFileEx()` (mandatory: a locked range cannot be read by others); the cache writes whole files by rename, which Windows refuses while the target is open, so a concurrent reader can make a write fail and be retried | load test proven with the response cache off; the cache under concurrent load on Windows is **theory** |
| paths | `/` | `\` and `/` both accepted by PHP; case-insensitive; the layout normalises both | proven for the layout |
| long paths | none | 260 characters unless `LongPathsEnabled` is set in the registry and the program is long-path aware (PHP 7.1+ is); the Exponential tree fits, `core.longpaths` is set for Git | proven for the fixture |
| users | `setuid()`/`setgid()`, `--user`, per-site pool users | no change of user inside a process: the service's account is the identity; `--user`/`--group` and per-site pools of different users do not apply | proven (virtual account) |
| permissions | modes (`0600` keys) | ACLs, set by `vcc.ps1`; `chmod()` only toggles the read-only flag | proven |
| certificates | PEM files | PEM files, PKCS#12, and the certificate store through `cert-import` | proven |
| firewall | none needed | Windows Defender Firewall: `vcc.ps1 install -OpenFirewall` adds an inbound rule for the site's ports | written, not exercised in CI (the runners' firewall is off) |

## The portable pool

`Q.webserver.poolMode` (command line `--pool-mode`) chooses how workers start:

| Value | Meaning |
|---|---|
| `auto` (default) | `fork` where `pcntl_fork()` exists, `portable` otherwise |
| `fork` | forked from the server, as always (falls back to `portable`, with a warning, where there is no fork) |
| `portable` | every worker is a process of its own, started with `proc_open()` |

On Linux nothing changes unless `portable` is asked for. On Windows `auto`
means `portable`.

A portable worker runs the server's own command line again (the same script,
options and `php.ini`), reads the same configuration, boots the application
itself (compat layer, `Q.webserver.warmup`, statics snapshot) and then serves
requests exactly as a forked worker does, resetting itself between them. It
connects back to the server over loopback TCP (`127.0.0.1`, an ephemeral port)
and proves itself with a token it is given on its standard input, so no other
local process can pose as a worker. Its control channel carries one ASCII line
per event:

```
READY <pid>        booted and connected
DONE <requests>    answered a request; how many so far
EXIT <code>        leaving, with this exit code
```

The server supervises the workers: one that dies is replaced (and its request
retried or answered 502, as for a forked worker); one that dies while booting is
replaced after a delay that doubles with each failure in a row (0.5 s up to
30 s); one that does not connect within `Q.webserver.portable.startTimeout`
seconds (60) is ended; one that has served `Q.webserver.maxRequests` requests
leaves and is replaced.

| Key | Default | |
|---|---|---|
| `Q.webserver.poolMode` | `auto` | see above |
| `Q.webserver.workers` | 1 per core, 2 to 8, on Windows | `--workers=N` |
| `Q.webserver.maxRequests` | 1000 | requests before a worker is replaced |
| `Q.webserver.portable.startTimeout` | 60 | seconds a worker may take to boot |
| `Q.webserver.portable.phpArgs` | `[]` | extra PHP options for workers, e.g. `["-d", "memory_limit=512M"]` |
| `Q.webserver.portable.command` | none | replaces the whole worker command line |
| `Q.webserver.portable.host` | `127.0.0.1` | address of the workers' listener (TCP) |
| `Q.webserver.portable.transport` | `auto` | `unix` (POSIX default: a 0600 Unix socket) or `tcp` (Windows) |
| `Q.webserver.portable.controlChannel` | `auto` | `pipe` (POSIX default) or `file` (Windows default) |
| `QBIX_POOL_MODE` (environment) | none | default for `Q.webserver.poolMode` when the configuration has none |
| `QBIX_POOL_TRACE` (environment) | none | `1` prints what the pool and its workers do |

Each worker holds its own copy of the application (no copy-on-write), so keep
the worker count modest: an Exponential worker needs tens of megabytes.

## Stop, reload, CTRL+C

| | Linux, BSD, macOS | Windows |
|---|---|---|
| CTRL+C in the console | SIGINT: graceful stop | `sapi_windows_set_ctrl_handler()`: the same graceful stop |
| `qbixserver --stop --pid=FILE` | SIGTERM | writes `FILE.stop`; the server stops gracefully within a second |
| `qbixserver --reload --pid=FILE` | SIGHUP: re-exec | writes `FILE.reload`; every worker is replaced (rolling), which loads changed code |
| `vcc.ps1 stop` / `Stop-Service` | `systemctl stop` | the service host writes `FILE.stop`, then waits |
| `qbixctl stop` | as above | as above; after the wait, `taskkill /T /F` as a last resort |
| liveness (`qbixctl status`) | `/proc`, `posix_kill(pid, 0)` | `tasklist /FI "PID eq n"` |

## Known gaps

- **Windows 11 and 10 on x64, and physical machines**: not run. GitHub has no
  x64 Windows client runner; see [Testing on a real machine](#testing-on-a-real-machine).
- **Native ARM64 PHP**: windows.php.net builds only x64 and x86; on Windows 11
  ARM the x64 build runs under emulation (proven), slower than native would be.
- **Real traffic and long runs**: the load test is short (900 requests, 8 at a
  time); hours of traffic, uploads and many sites per machine are untested.
- **The response cache under concurrent load on Windows** (file locking and
  rename semantics, see the API table): untested; the Exponential load test
  runs with the cache off.
- **Unit tests**: most of the suite was written for POSIX (fork, signals,
  `/proc`, file modes). The survey job lists which pass on Windows; only the
  gate list must.
- **No `--user` switching, no per-site pools of different users**: on Windows
  the identity is the service account. One service per site gives one account
  per site.
- The watchdog (`Q.webserver.watchdog`), hot reload re-exec, shared instances,
  scheduled tasks and WebSocket rooms still need `pcntl` and are off or run
  in-process on Windows.
- `qbixctl status` details that come from `/proc` (command line, environment,
  open ports) are empty on Windows; `vcc.ps1 status` shows the service, its
  account, the server and its workers instead.
- Whether a worker inherits the server's listening socket (handle inheritance)
  is unproven; the tests check that the port is free after every stop.
- Let's Encrypt from a Windows machine, the firewall rule, and Windows Server
  Core: written for, not run.
- The Windows static binary does not build yet; use `php.exe` with the source
  tree or the phar.
- Plesk for Windows (IIS) is not a target of the Linux `vcc`: that one reads
  Apache's configuration and drives systemd. `vcc.ps1` is the standalone
  Windows counterpart.

## Testing on a real machine

Everything the CI runs can be run by hand on any Windows machine, from the
repository root in an elevated PowerShell, with PHP in `C:\php`:

```powershell
$php = "C:\php\php.exe"
powershell -ExecutionPolicy Bypass -File tests\windows\unit-survey.ps1 -Php $php -Gate
powershell -ExecutionPolicy Bypass -File tests\windows\smoke.ps1 -Php $php
powershell -ExecutionPolicy Bypass -File tests\windows\https-smoke.ps1 -Php $php
powershell -ExecutionPolicy Bypass -File tests\windows\exponential-fixture.ps1 -Php $php -Dir C:\exp   # needs git and composer (or downloads composer.phar)
powershell -ExecutionPolicy Bypass -File tests\windows\exponential-site.ps1 -Php $php -Dir C:\exp
powershell -ExecutionPolicy Bypass -File tests\windows\service.ps1 -Php $php -ExpDir C:\exp
```

For a Windows 11 x64 desktop the same commands are the whole proof; send the
output with the "Windows report" issue form. A Windows Server VPS (for
example Contabo's Windows Server add-on) or a local Windows 11 VM would turn
the "theory" rows above into "proven" ones; nothing has been bought for it.
