# Running on Windows

**Status: written for Windows, tested on Linux only.** Nothing on this page has
been run on a real Windows 11 or Windows Server machine yet. The portable worker
pool described below is exercised on Linux by `tests/unit-portable-pool.php`,
which forces it on; the Windows-only code paths (tasklist, taskkill, `NUL`,
`sapi_windows_set_ctrl_handler()`, `bypass_shell`) are covered by unit checks of
their logic, not by a run on Windows. `tests/windows/smoke.ps1` is the test to
run once a Windows machine is available; until it has passed there, treat
Windows support as untested.

A Windows Server VPS (for example on Contabo, with the licence add-on) is planned for testing on a
real machine; Windows 11 desktop is not yet tested.

## Why Windows needs its own pool

On Linux, the BSDs and macOS, Velocity forks its PHP workers from the server
(`pcntl_fork()`): each worker inherits the loaded application copy-on-write.
Windows has no `fork()` and PHP for Windows has neither `pcntl` nor `posix`.
Before this change the server fell back to one `php-cgi` process per request
(a full application boot on every request) and several commands failed outright
(`--stop` and `--reload` called `posix_kill()`, which does not exist there).

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
local process can pose as a worker. Its standard output is a control pipe with
one ASCII line per event:

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
leaves and is replaced. On Windows workers are started without `cmd.exe`
(`bypass_shell`) and in their own process group, so a CTRL+C in the server's
console reaches only the server, which then stops them through their sockets.

Settings:

| Key | Default | |
|---|---|---|
| `Q.webserver.poolMode` | `auto` | see above |
| `Q.webserver.workers` | 1 per core, 2 to 8, on Windows | `--workers=N` |
| `Q.webserver.maxRequests` | 1000 | requests before a worker is replaced |
| `Q.webserver.portable.startTimeout` | 60 | seconds a worker may take to boot |
| `Q.webserver.portable.phpArgs` | `[]` | extra PHP options for workers, e.g. `["-d", "memory_limit=512M"]` |
| `Q.webserver.portable.command` | none | replaces the whole worker command line |
| `Q.webserver.portable.host` | `127.0.0.1` | address of the workers' listener |

Each worker holds its own copy of the application (no copy-on-write), so keep
the worker count modest. `stream_select()` on the official Windows builds
watches at most 256 sockets; the server warns when the worker count comes near
that.

## Stop, reload, CTRL+C

| | Linux, BSD, macOS | Windows |
|---|---|---|
| CTRL+C in the console | SIGINT: graceful stop | `sapi_windows_set_ctrl_handler()`: the same graceful stop |
| `qbixserver --stop --pid=FILE` | SIGTERM | writes `FILE.stop`; the server stops gracefully within a second |
| `qbixserver --reload --pid=FILE` | SIGHUP: re-exec | writes `FILE.reload`; every worker is replaced (rolling), which loads changed code |
| `qbixctl stop` | as above | as above; after the wait, `taskkill /T /F` as a last resort |
| liveness (`qbixctl status`) | `/proc`, `posix_kill(pid, 0)` | `tasklist /FI "PID eq n"` |

The request files work on every system wherever the server was started with
`--pid`; they are how the server is told to stop when no signal can be sent.

## How to run it on Windows

Requirements: PHP 8.0 or later for Windows (x64 or x86, NTS or TS) from
windows.php.net, with `php.ini` enabling at least `extension=sockets`,
`extension=mbstring`, `extension=openssl` (and what the application needs:
`intl`, `sqlite3`/`pdo_sqlite`, `curl`, `gd`, `fileinfo`).

```bat
cd C:\exp\velocity
C:\php\php.exe sbin\qbixserver.php --root=C:\exp\site\web --port=8080 --pid=C:\exp\run\vc.pid
rem in another console:
C:\php\php.exe sbin\qbixserver.php --stop --pid=C:\exp\run\vc.pid
```

Smoke test (PowerShell, from the repository root; it starts a server on a free
port with two portable workers, checks requests, worker reuse, recovery after
a worker is killed, a stop through the request file, and that the port is free
afterwards):

```powershell
powershell -ExecutionPolicy Bypass -File tests\windows\smoke.ps1 -Php C:\php\php.exe
```

or `tests\windows\smoke.bat C:\php\php.exe`. It prints PASS/FAIL per check and
exits non-zero on failure. The same test on Linux is `php tests/unit-portable-pool.php`.

## Known gaps (not done yet)

- Not run on Windows 11, Windows Server, or 32-bit Windows at all.
- Whether `stream_select()` reports readiness on `proc_open()` pipes on
  Windows is unproven. Supervision does not depend on it (liveness comes from
  `proc_get_status()` and the worker's socket), but the `DONE`/`EXIT` lines may
  arrive late or not at all there.
- Whether a worker inherits the server's listening socket on Windows (handle
  inheritance) is unproven; the smoke test checks that the port is free after
  a stop. On POSIX every inherited descriptor is replaced with `/dev/null`.
- `qbixctl status` details that come from `/proc` (command line, environment,
  open ports) are empty on Windows; start, stop, reload and liveness work.
- No Windows service definition (`sc.exe` / NSSM); run it from a console or the
  Task Scheduler for now.
- The watchdog (`Q.webserver.watchdog`), hot reload re-exec, shared instances,
  scheduled tasks and WebSocket rooms still need `pcntl` and are off or run
  in-process on Windows.
- Configuration layout: `/etc/vc` and `/etc/qbix` resolve to `C:\etc\...` on
  Windows; pass `--config` / `--conf-dir` explicitly until a Windows layout
  (`%ProgramData%`) exists.
- The Windows static binary does not build yet; use `php.exe` with the source
  tree or the phar.
