## 🏭 Workers and the Pool

The server runs PHP in a pool of persistent workers: processes forked from a parent
that has already loaded your application, each answering one request after another.
Between requests a worker is put back into the state it was forked in, so a request
never sees what the one before it left behind. This page covers how the pool is
sized, how requests reach a worker, and when a worker is replaced.
[architecture.md](architecture.md) explains the model; [reset.md](reset.md) lists
exactly what is reset.

- [How many workers](#how-many-workers)
- [What a worker costs](#what-a-worker-costs)
- [Static and dynamic pools](#static-and-dynamic-pools)
- [Forking from a zygote](#forking-from-a-zygote)
- [How a request reaches a worker](#how-a-request-reaches-a-worker)
- [When a worker is replaced](#when-a-worker-is-replaced)
- [Reload and stop](#reload-and-stop)
- [Settings reference](#settings-reference)
- [Watching the pool](#watching-the-pool)

---

### How many workers

`--workers=N` sets the pool size. Without it, the server sizes the pool from the
machine and from what a worker costs:

- a worker is assumed to hold at least what the parent holds after loading the
  application (never less than 8 MB);
- the count is what fits in the memory left after 1 GB is set aside, no more than
  eight per core, and no more than 64;
- it is never fewer than 4.

The server then checks the count against the file descriptor and process limits
and lowers it when they are too tight; on a terminal it asks before starting.
Anything beyond the automatic ceiling is a deliberate choice, made with
`--workers`.

```sh
php sbin/qbixserver.php --root=web --workers=32
```

---

### What a worker costs

Measured 2026-09-26 on a 12-core Linux host with PHP 8.5, from
`/proc/<pid>/smaps_rollup` -- proportional (PSS) and private memory, never RSS,
which counts every page shared after the fork once per worker and so overstates a
pool many times over.

| | Private memory per worker | PSS per worker | Workers per GB (PSS) |
|---|---|---|---|
| The server alone, serving a one-line PHP page | **1.3–1.9 MB** | 1.4–1.9 MB | about 530 |
| A full CMS (Exponential, ~600 classes), serving real pages | **~10 MB** | ~11 MB | about 90 |

What is shared is the code and data the parent loaded before forking -- about 20
MB for the server alone, 47 MB for the CMS -- and that is paid once. What a worker
costs is its private pages: the ones it writes after the fork (the allocator's
arenas, each request's objects, the application's own caches). That is why a
bigger application costs more per worker even though its code is shared, and why a
worker that has served requests costs more than one that is freshly forked.

Measured totals: 1,000 idle-to-light workers of the bare server came to 1,881 MB
PSS; 49 CMS workers to 617 MB.

**The pool has a ceiling below about 1,000 workers** with the default event loop
(`stream_select`), which cannot watch file descriptors past 1,024. Each worker
holds one, and so does every client connection: 900 workers served 20,000
requests without a failure, 1,000 reset every connection. Going further needs an
event loop without that limit (see [architecture.md](architecture.md#event-loop-backends)).

**The reset between requests** -- statics, globals, the shimmed functions' state,
a cycle collection -- measured 0.55 ms median for the one-line page (0.28 ms of it
restoring statics across 424 classes) and 4.6 ms median for the CMS (0.91 ms of
it for 586 classes). For the CMS that is about 1.5% of a 300 ms page render.

To measure your own:

```sh
# every descendant of the listener: PSS, private and shared
for p in $(pgrep -P $(pgrep -f qbixserver | head -1)); do
  awk '/^Pss:/{p=$2} /^Private_(Clean|Dirty):/{u+=$2} END{print p, u}' /proc/$p/smaps_rollup
done
```

The dashboard's Worker Memory card and `/Q/health` `workerStats` report the same
proportional figure.

---

### Static and dynamic pools

By default the pool is static: every worker is forked at start and stays.

Set `spareWorkers` and the pool is dynamic. Only the spare workers are forked at
start; when every worker is busy, one more is forked, up to the pool size; and a
worker beyond the spare count that has been idle for `idleWorkerTimeout` seconds is
retired, longest idle first. A busy worker is never retired, and the pool never
falls below the spare count.

```json
{ "Q": { "webserver": { "spareWorkers": 8, "idleWorkerTimeout": 60 } } }
```

A dynamic pool costs less memory on a quiet machine; a static one never forks
while it serves.

---

### Forking from a zygote

```json
{ "Q": { "webserver": { "zygote": true } } }
```

**The problem it solves.** `fork()` hands a new worker every descriptor the
server holds at that instant, including every visitor's connection. A newly
forked worker closes the plain ones at once. It cannot close a TLS connection:
PHP closes TLS with `SSL_shutdown()`, which writes a close_notify alert onto the
socket the server is still using, and the visitor's connection would end (see
[lessons.md](lessons.md#tls-in-a-forked-child)). So the worker parks it and keeps
the descriptor until it exits. While any worker holds a copy, the kernel cannot
free the connection: when the visitor and the server have both finished with it,
it sits in `CLOSE-WAIT`, held by a worker that will never use it.

A static pool forks only at start, before any visitor has connected, so it never
sees this. A **dynamic pool** forks whenever its workers become busy -- exactly
when connections are open -- and every worker it forks keeps all of them. On an
Exponential installation serving HTTPS with 48 spare workers, a 20-second burst
of 355 rendered pages left up to **50** connections in `CLOSE-WAIT` held by
workers alone, released only when those workers were retired.

**How it works.** With `zygote` on, the pool forks one extra process -- the
zygote -- at the end of starting up: after the warm-up and the first workers,
before the server accepts its first connection. The zygote closes what a worker
closes (the listeners, the server's end of every worker's socket pair, every
other socket) and then only waits. Every worker the pool needs after that is
forked by the zygote instead of the server:

1. the server creates the new worker's socket pair, as it always has;
2. it hands the worker's end to the zygote over a Unix control socket
   (`SCM_RIGHTS`), with a deadline of five seconds for the whole hand-off;
3. the zygote forks, the new worker takes that socket and runs the ordinary
   worker loop, and the zygote answers with the worker's pid.

The zygote never held a client connection, so its workers inherit none. They are
otherwise the same workers: forked from the same warmed-up state, served the same
way, replaced for the same reasons. The zygote is their parent and reaps them the
moment they exit. Because they are not the server's children, the pool checks
that one is alive by sending it signal `0` and reading its state in `/proc` (a
zombie does not count), rather than with `waitpid()`, and leaves the reaping to
the zygote. On stop, the pool asks every worker to exit, waits for them, and then
stops the zygote.

**Where it cannot run.** The hand-off depends on PHP passing a socket to
another process with `SCM_RIGHTS`. Before **PHP 8.4**, a `Socket` object put in
that message arrives as a different socket, so a worker forked from the zygote
would talk into a dead end and every request after the first would be a 502. A
stream resource arrives intact, on PHP 8.1 and later, so the server hands the
worker's connection over as the stream it is, and the zygote runs on PHP 8.2,
8.3, 8.4 and 8.5 alike. It still tries a hand-off to itself at start and uses
the zygote only when the socket arrives intact; where it does not, workers are
forked from the server, as with `zygote: false`, and the log says so:

```
  zygote off: PHP 8.x does not pass sockets between processes intact (SCM_RIGHTS); workers are forked from the server
```

v0.0.4.29 turned the zygote on by default without this check, so on PHP 8.2 and
8.3 its pool answered only the first request of each worker; v0.0.4.30 ran
without the zygote there, and later versions run with it.

**When the zygote fails.** If a hand-off fails -- the zygote was killed, a send
or the reply fails, or five seconds pass -- the zygote is stopped and the console
log says so:

```
[ERROR] zygote: the zygote did not fork a worker; forking workers from the server from now on
```

From then on workers are forked from the server exactly as they are without the
setting. No request fails on the way: workers the zygote had forked keep serving
until their socket pairs close, and the request that needed a new worker gets
one. A signal arriving at the server during a hand-off (a busy server takes
`SIGCHLD` constantly) is not a failure: an interrupted call is retried within the
same deadline.

**Requirements.** PHP's `sockets` extension with `SCM_RIGHTS`, plus `pcntl` and
`posix`, all present in the Linux builds. Without them the setting is ignored and
workers are forked from the server. It is on by default; set it `false` to fork every worker from the server as before. In Exponential it is
set from `velocity.ini`:

```ini
[ServerSettings]
Zygote=enabled
```

**Checking it.** The zygote is a child of the server whose own children are
workers:

```bash
P=<server pid>
for c in $(ps -o pid= --ppid $P); do n=$(ps -o pid= --ppid $c | wc -l); [ $n -gt 0 ] && echo "zygote $c: $n workers"; done
```

(It has no children until the pool forks its first worker after start.) To see
connections held by workers only -- what the zygote removes -- list the
connections on the HTTPS port and the processes holding each; any held by a pid
other than the server's is one:

```bash
ss -Htanp '( sport = :443 )' | grep -v LISTEN | grep -v "pid=$P,"
```

With the zygote on, the installation above showed none during the same burst,
while the zygote forked 48 workers and every request was answered.
`tests/unit-pool-zygote.php` asserts it over real TLS, together with a killed
worker being reaped at once, a killed zygote costing only itself, signals during
hand-offs, and a stop leaving no process behind.

---

### How a request reaches a worker

The parent accepts every connection and reads every request. Static files, cache
hits and the server's own pages are answered there, and only a request that runs
PHP goes to a worker: the first idle one, checked to be alive before it is used.

When every worker is busy, the request waits in the parent until one is free; it is
never refused for lack of a worker. The limit on load is `maxConnections`, checked
when a connection is accepted, beyond which the server answers `503`.

If a request cannot be handed to a worker, it goes back to the front of the queue,
up to three times, before the client is answered `502`.

---

### When a worker is replaced

A worker answers the request it has, and is then replaced by a fresh fork, when:

| Reason | Setting |
|---|---|
| It has served `maxRequests` requests. | `maxRequests` (`1000`; `0` for no limit) |
| Its heap has grown past the memory ceiling. | `workerMemoryCeiling` (see [reset.md](reset.md#a-worker-that-grows-is-replaced)) |
| Its output buffers were left unbalanced. | — |
| The application asked for it with `Q_WebServer_Pool::retireAfterResponse($reason)`. | — (see [reset.md](reset.md#code-that-can-run-only-once-per-process)) |

Each replacement is logged with its reason.

A worker that dies while serving is replaced as well. When it died before writing
anything and the request was a `GET`, `HEAD` or `OPTIONS`, the request is tried
once more on another worker; otherwise the client is answered `502`. An idle worker
that exits is noticed within two seconds, removed and replaced. Every exited worker
is reaped, so none is left behind as a zombie.

A worker still on one request after `requestTimeout` seconds (default `30`, `0` for
no limit) is stopped: the client gets `504`, the stop is logged with the request,
and a fresh worker takes its place. The request is not run again elsewhere, where
it would hang the same way.

The control panel's Workers tab (API `POST /Q/api/workers/resize` with
`{"workers": N}`) changes the pool's size while it runs: a fixed pool forks up to
`N` at once, and above it retires idle workers now and busy ones as each finishes;
a dynamic pool takes `N` as its new maximum.

With `forkPerRequest`, each worker serves one request and exits, and the parent
forks its replacement: the isolation of a fresh process, at the cost of a fork per
request.

---

### Reload and stop

`qbixconsole server:reload` (`qbixctl graceful`) re-executes the server. The
listening sockets are closed first, open connections are given up to five seconds
to finish, and the workers are asked to stop and given three seconds before they are
ended. The new server forks a new pool from the new code and configuration.

The control panel's Workers tab can recycle one worker or all of them without a
reload: an idle worker is replaced at once, a busy one after its current request.

SIGTERM or SIGINT stops the server the same way and then ends the process. The
stop is bounded by `shutdownTimeout` (default `15` seconds, `0` for no bound):
at the signal a small process is forked to keep the time. It holds nothing of the
server's, ignores the signals a supervisor sends to stop the server, and waits.
If the server has not exited when the time is up, the server and every process it
started are killed with SIGKILL, and the console log says so. Either way, once the
server is gone, whatever it left in its own process group (a worker stuck where it
is, a program a script ran) is killed too, when the server leads its group -- as it
does when started with `setsid`, the way `qbixctl` and supervisors start it. A
server that shares its group with a shell does not take the shell down.

The bound matters because a server can stop where no PHP code runs again: blocked
in the kernel on a lock in memory it shares with its workers. A signal handler of
PHP's, a timer of the event loop or an alarm are all deferred there; only SIGKILL
from another process ends it.

Neither the zygote nor any worker holds a listening socket, so a server that is
killed outright leaves nothing that accepts a connection on its ports; its workers
end when their socket pair closes.

#### How a worker is stopped

A worker acts on SIGTERM, SIGINT and SIGALRM (`set_time_limit()`) in PHP code,
never inside an extension's C function: the signal interrupts a blocking call,
and the worker ends at the next point where PHP code runs, without the
application's shutdown functions. Inside the callback of `apcu_entry()`, the one
place PHP code runs while APCu holds its lock, it exits through the callback
instead, which releases the lock first.

This is what keeps APCu's lock from being orphaned. The server and its workers
share one APCu segment, and its read-write lock is not robust: a process killed
while it holds the lock leaves it held for ever, and every other process that
touches APCu -- the server at its next cache lookup -- blocks in `futex_do_wait`.
The server then accepts no connection, keeps its ports, and does not act on
SIGTERM. A worker past `requestTimeout` is therefore sent SIGTERM, and SIGKILL
only `requestTimeoutGrace` seconds later (default `5`) if it is still there: a
worker that has not reached PHP code in that time is blocked elsewhere and holds
no APCu lock. The pool's shutdown does the same with its three seconds.

| Setting | Default | Meaning |
|---|---|---|
| `shutdownTimeout` | `15` | Seconds a graceful stop (SIGTERM, SIGINT) may take before the server and every process it started are killed. `0` means no bound. |
| `requestTimeoutGrace` | `5` | Seconds between the SIGTERM a worker past `requestTimeout` is sent and the SIGKILL that follows if it is still there. `0` kills at once. |

---

### The user the workers run as

A server started as root -- to bind ports below 1024, or to read a certificate only
root may read -- keeps root in the server process only. Every process that runs
application code gives it up right after it is forked and before it runs anything:
each worker, the zygote (so the workers it forks never held root at all), a
fork-per-request child and a scheduled task. The order is `setgid`, `initgroups`,
`setuid`; the process then checks that it is no longer root and that root cannot be
regained, and is ended if either is not so. It never serves as root because a
switch failed. The TLS handshake stays in the server process, so the workers never
need the certificate files.

Who, first match wins -- like Apache's `User`/`Group` and Debian's
`APACHE_RUN_USER`/`APACHE_RUN_GROUP` in `envvars`:

| Where | User | Group |
|---|---|---|
| command line | `--user=NAME` | `--group=NAME` |
| configuration | `Q.webserver.user` | `Q.webserver.group` |
| environment, then the `envvars` file of the configuration tree | `QBIX_RUN_USER` | `QBIX_RUN_GROUP` |
| default | owner of the document root | group of the document root |

A distribution may add its own names ahead of `QBIX_` (Exponential Velocity reads
`VC_RUN_USER`/`VC_RUN_GROUP` first). Names or numbers (`#1000`) are accepted; with
a user and no group, the user's primary group is used.

- A user or group that does not exist stops the server at start, with the reason.
- `root` is refused unless `Q.webserver.allowRootWorkers` is `true`
  (`--allow-root-workers`, `QBIX_RUN_ALLOW_ROOT=1`).
- With nothing configured and a document root that belongs to root, the workers stay
  root as before, and the start-up says so.
- The warm-up (`Q.webserver.warmup`) runs with the worker user's effective ids, so the
  caches it writes are the workers' to replace; `Q.webserver.warmupAsUser: false`
  runs it as root.
- The server's own cache directories (`Q.web.cache.dir`, `Q.web.appCache.dir`,
  `Q.webserver.precompress.dir`, plus any listed in `Q.webserver.writable`) are handed
  to the worker user at start, with whatever root owned in them, and what the server
  writes there later goes to that user too. The logs and the pid file stay root's.
- The worker user must be able to read the engine's own source tree: classes are
  loaded lazily by the workers.

The start-up prints the choice: `Workers as: alpha:psaserv (default: owner of /srv/site)`.

---

### Settings reference

Every setting, with its default. All are under `Q.webserver`.

| Setting | Default | Meaning |
|---|---|---|
| `spareWorkers` | `0` | Workers kept when idle; above `0` makes the pool dynamic. |
| `idleWorkerTimeout` | `60` | Seconds a worker beyond the spare count may stay idle before it is retired. |
| `maxRequests` | `1000` | Requests a worker serves before it is replaced. `0` means no limit. |
| `requestTimeout` | `30` | Seconds a request may run before the client gets `504` and the worker is replaced. `0` means no limit. |
| `workerMemoryCeiling` | `256`, or ¾ of `memory_limit` if lower | Heap size in MB past which a worker is replaced. `0` turns it off. |
| `forkPerRequest` | `false` | One request per worker, then a fresh fork. |
| `zygote` | `true` | Fork workers started after the pool from a zygote, so they inherit no visitor's connection. `false` forks them from the server as before. See [Forking from a zygote](#forking-from-a-zygote). |
| `warmup` | — | A script run once in the parent before the workers are forked. See [reset.md](reset.md#warming-the-pool-in-the-parent-and-the-one-trap-in-it). |
| `keepGlobals` | `[]` | Globals a worker keeps between requests. `--keep-globals` sets it too. |
| `maxConnections` | `1024` | Connections open at once; beyond it the server answers `503`. |
| `user`, `group` | owner and group of the document root | Who the workers run as when the server is root. See [The user the workers run as](#the-user-the-workers-run-as). |
| `allowRootWorkers` | `false` | Permit `user` root. |
| `warmupAsUser` | `true` | Run the warm-up with the worker user's effective ids. |
| `writable` | `[]` | More directories to hand to the worker user at start. |

The pool size itself is given with `--workers`.

---

### Watching the pool

| Where | What it shows |
|---|---|
| `/Q/dashboard` | The Workers card (idle and busy, and the maximum of a dynamic pool) and the Worker Memory card, which reports proportional memory so shared pages are counted once. See [dashboard.md](dashboard.md). |
| `/Q/health` | The same figures as JSON for an admin: `workers`, `workersMax`, `workersSpare`, `workerStats`. |
| `/Q/panel`, Workers tab | Every worker with its pid, state and requests served, and the queue length. |
| The console log | One line for each worker replaced, retried or found dead, with the reason, and a `zygote:` line if the pool gives up on its zygote. |
| `ps` and `ss` | Which process forked each worker, and connections held by a worker rather than the server. See [Checking it](#forking-from-a-zygote). |

A worker also remembers what it has found out about files -- whether a path
exists, a file's mtime and size -- for the rest of a request, and with
`Q.compat.statTtl` for a little longer. What makes it forget, and why that
includes running another program: [compatibility.md](compatibility.md#remembered-file-facts).

What carries over between requests and what a forked worker inherits: [lessons.md](lessons.md); problems that took longest to diagnose: [time-consuming-lessons.md](time-consuming-lessons.md).

---
[← Back to README](../README.md)
