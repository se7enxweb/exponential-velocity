# Changelog

Every change worth a reader's time, newest first. A published release links
here, so this file is where a release note goes — not into the release note
itself, where it would be written once and never found again.

## How this is kept

**A tag is permanent, so both the tag and the release are deliberate.**

This repository is a Composer package, and Packagist reads every tag. It
currently knows 43 versions — including `v0.0.4.26`, which has no GitHub
release at all. Publishing nothing did not make that version number free; it
made it a version of this package that exists, forever, describing itself with
whatever the tree held at the time.

A tag therefore cannot be withdrawn, moved or re-cut. Packagist caches the
version it found on first read, so a "corrected" tag yields two different
packages wearing one version number, which is worse than the original mistake
and impossible to diagnose from outside. **The only correct response to a bad
version is to publish the next one** and say plainly in its notes what was
wrong with the one before.

So the rule is not "tag freely, release rarely" — it is *accumulate* freely and
tag rarely. Work lands on the branch as ordinary commits and collects under
`## Unreleased`. A tag is made when that accumulation is worth a version
number.

The workflow adds the second half: **it publishes a release only for a tag that
has a section in this file.** Writing the section is the act of deciding to
release, which is why a version and its notes can no longer describe different
things. A tag without a section still builds and tests — useful for proving a
commit before it is released — but it is still a permanent Packagist version,
so it is not free either.

At release time:

1. Check what is already published. Never guess the next number:
   ```bash
   git fetch --tags
   git tag -l 'v*' --sort=version:refname | tail -5   # never plain `tail -1`
   gh release list --limit 10
   ```
   A lexical sort puts `0.0.4.10` *before* `0.0.4.8`, and a tag can exist with
   no release, so check both lists.
2. Confirm the tree is clean, the suite passes, and the committed phar matches
   its sources.
3. Move everything under `## Unreleased` into a new `## vX.Y.Z.N` heading.
4. Write the one-line summary on that heading. It becomes the release title.
5. Rebuild the phar stamped with the version being cut. It is built before
   the tag exists, so without this it names the previous release:
   ```bash
   QBIX_SHIP_VERSION=vX.Y.Z.N php -d phar.readonly=0 build-phar.php
   ```
6. Commit, then tag that commit.

Only the last position increments: `0.0.4.9` → `0.0.4.10` → `0.0.4.11`, never
`0.0.5.0`. The last position is an integer and keeps counting; moving anything
above it is a decision about what the release *means*, not a consequence of
reaching nine.

Entries use the same four prefixes as commit messages — `Added`, `Fixed`,
`Updated`, `Removed` — so a section can be assembled from `git log` and then
edited down to what a reader actually needs.

---

## v0.0.4.47 — a key a script adds to $_SERVER ends with its request

2026-10-09

### Fixed

- **A `REQUEST_*` key a script adds to `$_SERVER` ends with its request.** Between two requests a persistent worker keeps the `$_SERVER` keys the server provides and removes the rest, but it kept every key starting with `REQUEST_`, so a key an application recorded under that prefix (a request filter's verdict, for instance) was still there on the next request the same worker served. Only the `REQUEST_*` keys the server sets for every request survive now: `REQUEST_METHOD`, `REQUEST_URI`, `REQUEST_SCHEME`, `REQUEST_TIME` and `REQUEST_TIME_FLOAT`. `tests/request-keys-reset.sh` covers it.
- **`sbin/qbixserver.phar` is rebuilt from these sources**, stamped v0.0.4.47.

## v0.0.4.46 — a stop is bounded and leaves nothing behind, and a file read through the compat wrapper is read whole

2026-10-07

### Fixed

- **A stop or restart can no longer hang.** A worker killed while it held the shared APCu lock left that lock held for ever; the parent's next cache lookup then waited on it, stopped accepting connections and never acted on SIGTERM. A worker now ends on SIGTERM, SIGINT or SIGALRM only at a point where it holds no APCu lock (inside an `apcu_entry` callback it exits, which releases the lock first), and the request timeout sends SIGTERM first and SIGKILL only after `Q.webserver.requestTimeoutGrace` (default 5 s). Workers forked by the server itself now honour SIGTERM too.
- **A graceful stop is bounded.** On SIGTERM or SIGINT a short-lived process keeps the deadline `Q.webserver.shutdownTimeout` (default 15 s); at the deadline it kills the server and everything it started, including what is left in its process group, so no process can keep serving the ports after a stop. See [workers.md](docs/workers.md) and [configuration.md](docs/configuration.md). `tests/unit-shutdown-bounded.php` covers it.
- **Files read through the compat file wrapper are read whole.** Every local file opened through `Q_WebServer_CompatFileWrapper` is a user-space stream, and PHP returns at most 8192 bytes per `fread()` on one; an application loop that requested 16384 bytes and counted 16384 sent each file half-sized with status 200, a matching Content-Length and a cached copy of the short body (downloads were cut to half their size, range requests too). `fread()` is now replaced for such streams with a read that returns the length asked for or the rest of the file; sockets and pipes keep their normal short reads. `tests/unit-compat-fread-full-length.php` covers file sizes, ranges, empty files and sockets.
- **`sbin/qbixserver.phar` is rebuilt from these sources**, stamped v0.0.4.46.

## v0.0.4.45 — Q.webserver.fallback serves the file it names

2026-10-05

### Fixed

- **`Q.webserver.fallback` works.** A string fallback and `{"file": ...}` called a method that did not exist, so any configured fallback ended the request in a fatal error instead of the page it named. As [routing.md](docs/routing.md#fallback--spa-routing-custom-404-catch-all) describes, a string now names a file answered with 200 through the static file path (the single-page application catch-all; a `.php` file is run when `Q.webserver.scripts` allows it), and `{"file": ...}` is answered with 404 and the file as the page. A file outside the root or with an extension the server does not serve is not sent; the request gets the server's own 404. `tests/unit-fallback.php` covers each form.
- **`sbin/qbixserver.phar` is rebuilt from these sources**, stamped v0.0.4.45.

## v0.0.4.44 — forwarded protocol headers are honoured only from trusted proxies

2026-10-05

### Updated

- **`HTTPS` and `REQUEST_SCHEME` follow a forwarded protocol only from a trusted proxy.** `X-Forwarded-Proto` (or the header named in `Q.webserver.proxy.headers.proto`), `CloudFront-Forwarded-Proto` and Cloudflare's `CF-Visitor` are read only when the connection comes from an address in `Q.webserver.proxy.trusted`, the same list that decides `REMOTE_ADDR`. Of a comma-separated `X-Forwarded-Proto` the first entry counts. A TLS connection is HTTPS from any client. The pool, the php-cgi and subprocess paths and `--app` all ask the same helper, `Q_WebServer_Proxy::clientProto()` (and the new `isHttps()`), and take TLS from the request's own connection rather than from whether a TLS listener exists. A proxy that sets the protocol header has to be listed in `Q.webserver.proxy.trusted`, which is loopback only (`127.0.0.1`, `::1`) unless configured.
- **The trusted proxy settings are documented.** [configuration.md](docs/configuration.md) lists `webserver.proxy.trusted` and the `ip` and `proto` headers, and the `Q_WebServer_Proxy` class comment no longer says that Cloudflare's addresses are trusted by default: the default is loopback only.
- **`sbin/qbixserver.phar` is rebuilt from these sources**, stamped v0.0.4.44.

## v0.0.4.43 — Logs panel tail now uses an after= byte cursor, appends without re-rendering, handles rotation, and shows live/paused state visibly.

2026-10-05

### Updated

- **Logs panel tail now polls with a byte cursor.** The `logs` API accepts an `after=` parameter and returns only the lines appended since that byte offset, plus `rotated=true` when the log file was rotated or truncated. The JavaScript `loadLogs()` appends new rows instead of re-rendering the whole view, stops on errors, and restarts cleanly after rotation.
- **Logs controls and status are more visible.** The Tail button now shows an on/off dot and a "Live" badge, the stats line is clearer, and the output has an empty-state hint and better responsive styling.
- **Panel view parameters are preserved across tabs.** `window.Q_VIEW_PARAMS` and `history.replaceState` keep filters and the active tab in the URL.

### Fixed

- **Tail button no longer fails silently.** API or network errors now update the stats line and stop the tail loop.

## v0.0.4.42 — persistent workers report each request's own start time and read sessions without a warning; uwebserver becomes a small static file server with a GNU command line, safe defaults and four security reviews; the packages install the shell as vc-qshell

2026-09-30

### Fixed

- **A pool worker reports each request's own start time.** `$_SERVER['REQUEST_TIME_FLOAT']` and
  `REQUEST_TIME` were inherited from the process that forked the worker and never set again, so in a
  persistent worker every request claimed to have started when the server did. An application that keys a
  per-request cache on `REQUEST_TIME_FLOAT` -- Exponential does -- kept that cache for the worker's whole
  life and served one request's data to the next. `executeScript()` now sets both when the request starts,
  as every SAPI does.
- **The compat session handler reads each value from its own bytes.** It decoded every value from the whole
  rest of the session string, which PHP 8.3 and later reports as `unserialize(): Extra data` for every key
  but the last -- a warning per key on every request a persistent worker served -- and found the next key by
  serializing the value again and skipping that many bytes, so wherever that is not the stored text (a
  float, an object with `__serialize` or `__sleep`) the keys after it were misread. The end of each value is
  now found by scanning the serialize format; anything else is decoded as before, without the warning.
- **`uwebserver` never starts on an argument it does not know.** It took any
  argument other than its about flags as leave to serve, on every interface at
  port 8080, which is the port the application server uses: `uwebserver --help`,
  `-h` or a misspelt option started a server. The whole command line is now read
  first, the GNU way (`--name=VALUE`, `--name VALUE`, the one-dash spellings,
  bundled short options, `--`); an unknown option, a missing or malformed value
  or a stray word is reported on standard error with
  `Try 'uwebserver --help' for more information.` and exit status 2, and nothing
  is started. `--help` prints the usage and exits 0.
- **Its defaults are safe.** It listens on `127.0.0.1:8000` unless told
  otherwise; every interface only with `--listen='*:PORT'`. `SO_REUSEPORT` only
  with `--reuse-port`, so a second start on a port in use fails instead of taking
  a share of a running server's connections. As root it serves only with `--user`
  (dropped once the ports are open) or `--allow-root`.
- **Four security reviews** (memory safety and parsing; resource exhaustion;
  privileges and TLS; hardening and fuzzing), every finding with its severity,
  fix and test in `docs/uwebserver.md`, "Security". Among them: a request head
  that filled the buffer was dropped and its rest read as new requests (now
  `431` and closed); the TLS handshake blocked the whole server; there was no
  timeout of any kind; responses ignored a full socket; running out of
  descriptors made the loop spin; a connection on descriptor 0 was never closed;
  the request was barely parsed (now strictly, RFC 9112); a path could not leave
  the root only because nothing served files (now `openat2` with
  `RESOLVE_BENEATH`, `..`, `%2F` and NUL refused, dot names hidden); logs and the
  pid file are never a second name of another file; root does not read a
  configuration file others may change; TLS 1.2 offers only forward-secret AEAD
  ciphers, no session tickets, no compression, no renegotiation.

### Added

- **`uwebserver --root=DIR` serves files:** GET and HEAD, `ETag` and
  `Last-Modified` with conditional requests, one byte range, index files and the
  redirect to a directory's slash, `--directory-listing`, `--gzip-static`,
  `--mime-types`, `--cache-control`, `--header`, `--hidden-files`,
  `--symlinks=inside|never`. Without `--root` it gives the built-in answers of
  the benchmark, as before.
- **Options for every common need:** `--listen` and `--tls-listen` (repeatable,
  IPv4 and IPv6), `--bind`, `--port`, `--tls-port`, `--backlog`; `--cert`,
  `--key`, `--chain`, `--tls-min-version`, `--tls-ciphers`,
  `--tls-ciphersuites`; `--workers`, `--max-connections`, `--max-requests`,
  `--max-header-size`, `--max-uri-length`, `--max-body-size`, and five timeouts;
  `--access-log` (common, combined or json), `--error-log`, `--quiet`,
  `--verbose`; `--daemon`, `--pid-file` (locked), `--user`, `--group`,
  `--chroot`; `--config=FILE` with the same names (or `UWEBSERVER_CONFIG`),
  `--print-config`, and `--check` (`--test-config`), which checks everything the
  start needs and serves nothing.
- **A manual page, `docs/uwebserver.1`, bash completion, and a Makefile:**
  `make -C native/uwebserver` builds `sbin/uwebserver` hardened (PIE, full
  RELRO, stack protector, `_FORTIFY_SOURCE`) and with `-Werror`; `make sanitize`
  builds it with AddressSanitizer and UndefinedBehaviorSanitizer; `make fuzz`
  builds the fuzz harness of the request parsing
  (`native/uwebserver/fuzz_request.c`, also a libFuzzer entry point); `make
  install` installs the program, the manual page and the completion.
  `docs/uwebserver.md` is the full reference.
- **The packages install the shell as `/usr/bin/vc-qshell`**, a link to
  `bin/qshell.php` in the installed tree, and the container image as
  `/usr/local/bin/vc-qshell`. Not `qshell`: `/usr/bin/qshell` is Qiniu's
  command-line tool. 0.0.4.41 did not install the shell as a command at all.

### Removed

- The first 960 lines of `uwebserver.c`, generated by a U-to-C translator and
  never called, and with them the compiling of `u_runtime.h` (6 650 lines); the
  response cache, which kept whole responses with their `Date` and `Connection`
  headers. `u_runtime.h` and `u_merkle_cache.h` are still in the tree, compiled
  by nothing.

### Compatibility

- Every option and spelling of 0.0.4.41 still works: `--port`, `--tls-port`,
  `--cert`, `--key`, in both spellings, and the about flags. A port given as a
  bare number works and says `--port` is the spelling to use.
- What a caller may notice: the default address and port (`127.0.0.1:8000`
  rather than `0.0.0.0:8080`; `--port=N` binds `127.0.0.1:N`); as root, `--user`
  or `--allow-root` is needed; `--cert` without `--key` is an error rather than
  plain HTTP; the `Server` header is `uwebserver`, not `U/1.0`; the start-up line
  is a log line on standard error. `/health` keeps its keys.
- `sbin/uwebserver`, the committed binary, is built from these sources with the
  Makefile (GCC 11.5 on EL 9, x86-64, linked against the system's OpenSSL 3).
  The one committed until now had been built at v0.0.4.30 (its `--version` said
  so), without any hardening, and started on any argument. On another system,
  build it with `make -C native/uwebserver`.

### Tests

- `tests/unit-pool-request-time-per-request.php`: two requests from one persistent worker each report their
  own `REQUEST_TIME_FLOAT` (fails without the fix: both carried the same time).
- `tests/unit-compat-session-decode.php`: sessions written by PHP's own encoder from awkward values (floats,
  `|` and `;` in strings, nested arrays, objects with `__serialize` and `__sleep`, an enum) come back as
  `session_decode()` reads them, with no warning.
- Ten new tests, each building uwebserver from its sources and starting it only
  on 127.0.0.1 and a free high port: `unit-uwebserver-cli.php` (144 cases),
  `-static.php` (98), `-config.php` (98), `-tls.php` (23), `-process.php` (46),
  `-docs.php` (234), `-parser.php` (54), `-limits.php` (30), `-privileges.php`
  (22), `-hardening.php` (23). They pass under AddressSanitizer,
  UndefinedBehaviorSanitizer and LeakSanitizer (`UWEB_TEST_CFLAGS`) with no
  report. The request-parser fuzzer ran 13 393 920 inputs in ten minutes under
  both sanitizers and found nothing.
- `tests/unit-nfpm-render.php` checks `/usr/bin/vc-qshell` and that nothing
  named `qshell` is installed; `tests/unit-moved-programs.php` compares `--help`
  and a usage error of both uwebserver paths when the binary has a `--help`.
- `native/uwebserver/u_runtime.h` and `u_merkle_cache.h`, no longer compiled since the generated
  code was removed, are removed too. `docs/uwebserver.md` gains "Upgrading from 0.0.4.41
  or earlier": the new default address, root and bad options, and that nothing in
  a site starts uwebserver.

## v0.0.4.41 — the programs move to sbin/ and bin/, and every former path keeps working

2026-09-30

### Renamed

- **The daemon and its administration commands are in `sbin/`, the user
  commands in `bin/`,** the way the Filesystem Hierarchy Standard lays out a
  system: `qbixserver.php`, `qbixctl.php` and `qbixconsole.php` are
  `sbin/qbixserver.php`, `sbin/qbixctl.php` and `sbin/qbixconsole.php`;
  `qshell.php` is `bin/qshell.php`; the committed phar is
  `sbin/qbixserver.phar`; the uwebserver binary is `sbin/uwebserver` and its
  C sources (`uwebserver.c`, `u_*.h`) are in `native/uwebserver/`, with the
  sources rather than in either program directory. `bin/qbix-appinfo.php` stays
  where it is. Each program reads the engine's files from the directory above
  its own, from a checkout, a Composer vendor copy and the phar alike; the phar
  carries the same layout and its stub runs `sbin/qbixserver.php`. The table
  of what moved, and why, is in `docs/layout.md`, "Programs".
- **The packages install `qbixserver`, `qbixctl` and `qbixconsole` in
  `/usr/sbin`**, and the systemd unit starts `/usr/sbin/qbixserver`. The
  wrappers are `packaging/sbin/`; the container image puts them in
  `/usr/local/sbin`.

### Compatibility

- **Every former path still works, with nothing to change.** `qbixserver.php`,
  `qbixctl.php`, `qbixconsole.php` and `qshell.php` at the top of the tree are
  forwarders that run the new file in the same process: the command line, the
  process id and title (`ps`, `pkill -f 'qbixserver.php.*--port=N'`), standard
  input and output and the exit status are the new program's. A server
  started by an old path shows the same command line as before and is found,
  reloaded and restarted by `qbixctl` exactly as it was started; `qbixctl`
  itself starts `sbin/qbixserver.php` and counts a server started by either
  path as this engine's. `bin/qbixserver.phar` is the same file as
  `sbin/qbixserver.phar`, byte for byte (a copy, so it is there however the
  tree was unpacked); `bin/uwebserver` execs `sbin/uwebserver`;
  `packaging/bin/qbixserver`, `qbixctl` and `qbixconsole` are links to
  `packaging/sbin/`. The packages keep `/usr/bin/qbixserver`, `qbixctl` and
  `qbixconsole` as links, for units, scripts and users without `/usr/sbin` on
  their `PATH`, and `/usr/share/exponential-velocity/bin/qbixserver.phar` as a
  link to the phar.
- The one visible difference: a forwarder writes a line on standard error
  saying where its program moved, only when standard error is a terminal.
  `QBIX_MOVED_QUIET=1` silences it there too.
- Composer's `bin` lists `sbin/qbixserver.php`, `sbin/qbixctl.php`,
  `sbin/qbixconsole.php` and `bin/qshell.php`, so `vendor/bin/` carries all four.
- A program that drives the server from its own code finds everything through
  `Q_WebServer_Ctl` as before: `serverScript()` is `sbin/qbixserver.php` (or
  `qbixserver.php` in a tree from before this release), the new
  `serverScripts()` lists both paths, `engineDir()` reads a `$sourceDir` that
  names `sbin/` as the directory above it, and `Q_WebServer_Shell_Entry` finds
  `bin/qshell.php` and `sbin/qbixconsole.php`, or the former files in an older
  tree.

### Updated

- `build-phar.php` writes `sbin/qbixserver.phar` and its copy at
  `bin/qbixserver.phar`; `build-app.php`, `build-binary.sh` (its binary is
  `sbin/qbixserver`), the release recipes, the source kit and the workflows use
  the new paths. `tests/phar-is-current.php` also checks that the stub runs
  `sbin/qbixserver.php` and that the two phar paths are the same file.
- The service examples in `service/`, the README and every document name the
  new paths.

### Tests

- `tests/unit-moved-programs.php`: every former path and its new path answer
  `--help`, `--version`, `-V`, `--about` and usage errors with the same
  standard output, standard error and exit status (qbixconsole `list` and
  `help`, qbixctl without a command, qshell `-c` with a failing command, both
  phar paths, both uwebserver paths); standard input reaches the program
  through a forwarder (`qshell --exec`); the note appears only at a terminal
  and not with `QBIX_MOVED_QUIET=1`; real servers started by the former path
  and by `qbixctl` serve and are restarted with their exact command line by the
  new and the former `qbixctl`, and one started without a pid file is restarted
  from its process table entry; both phar paths serve and
  `phar://.../qbixserver.php` still runs the server; the phar carries the new
  layout and the forwarders; `Q_WebServer_Shell_Entry` and `Q_WebServer_Ctl`
  find the programs from the engine's directory, from `sbin/` and in a tree of
  the former layout. 147 cases.
- `tests/unit-nfpm-render.php` checks that the packages put the programs in
  `/usr/sbin`, links in `/usr/bin` and nothing else there, the tree under
  `/usr/share/exponential-velocity` with the phar link, the unit's
  `/usr/sbin/qbixserver`, and that every link points into the package.
  `packaging/ci/test-package.sh` checks the same on an installed package.
- `tests/unit-about.php` covers the programs at both paths. Every other test
  runs the programs at their new paths; `tests/phar-serves.sh` defaults to
  `sbin/qbixserver.phar` and is still run against `bin/qbixserver.phar`.
- `tests/unit-worker-pool-dynamic.php` waits for a fixed pool's workers
  instead of counting them the moment its port answers: the pool forks them
  after it listens, so a loaded machine failed a pool that was starting
  normally (got 0, want 3).

## v0.0.4.40 — qbixctl restart brings a server back as it was started, and its default log is the server's own

2026-09-30

### Fixed

- **`qbixctl restart` restarts with the options the server was started
  with.** It stopped the server and then started one with only the options
  given to `restart` itself -- the pid file, the configuration -- so a
  server started with `--root`, `--port`, `--https-port`, `--host`,
  `--workers`, `--keep-globals` or any other option came back with the
  default root and ports, and the start timed out waiting for it. A server
  started with a pid file now writes how it was started beside it
  (`<pid file>.json`): the whole command line, the PHP interpreter's own
  `-d` settings, the directory it was started in, the `QBIX_*`,
  `*_CONF_DIR`, `*_STATE_DIR`, `*_DISTRIBUTION`, `*_RUN_USER` and
  `*_RUN_GROUP` variables of its environment, and the file its output goes
  to. `restart` reads that record -- or, for a server that wrote none, the
  process table -- stops the server, starts it again the same way from
  whatever directory and environment `restart` runs in, and waits until
  every port it listened on listens again. Server options given to
  `restart` replace the recorded ones (`qbixctl restart --workers=16`);
  anything after `--` is added.
- **The default server log is never the temporary directory.** Without
  `--log`, `qbixctl start` wrote every server's output to
  `/tmp/qbixserver.log`: one file for all servers on the machine,
  overwritten by each start. It now goes to `Q.webserver.log.dir`; else
  `var/log`, `files/log` or `logs` of the document root or the directory
  above it; else the configuration tree's log directory (`/var/log/qbix`
  for `/etc/qbix`, `/var/log/vc` for Velocity's `/etc/vc`, `QBIX_LOG_DIR`
  when set); else `var/log` beside the document root. A
  `/tmp/qbixserver.log` left by an earlier version is not touched; it can
  be removed by hand.

### Updated

- `qbixctl start` passes on every server option it is given: `--app`,
  `--socket`, `--socket-mode`, `--preset`, `--keep-globals`, `--user`,
  `--group`, `--debug`, `--quiet`, `--verbose`, `--hotreload`,
  `--watchdog` and `--allow-root-workers` as well as the ones it took
  before.
- The test harness for server tests (`tests/fixtures/race-harness.php`) no
  longer turns the response cache off, or sets the source transform, when
  a test says nothing about them: every test now runs with the server's own
  defaults, which is what an installation gets. This is how the wrong
  cache default fixed in v0.0.4.39 stayed hidden.

### Tests

- `tests/unit-ctl-restart-keeps-options.php` starts real servers: one with
  `qbixctl start` and a full set of options (HTTP and HTTPS ports, bind
  address, workers, pid file, site file, configuration tree,
  `--keep-globals`, a distribution, an option after `--`), restarted with
  `restart --pid`, with a bare `restart` from another directory and
  environment, and with `restart --workers=3`; and one started by hand
  with a relative root, an interpreter `-d` setting and no pid file,
  restarted from its process table entry. Each comes back with the same
  command line, ports, directory, environment and log. 32 cases.
- `tests/unit-ctl-default-log.php` checks the order of the default log
  places and that a real `qbixctl start` without `--log` writes to the
  installation's `var/log` and nothing to the temporary directory.
  17 cases.

## v0.0.4.39 — the response cache is off unless a setting turns it on

2026-09-30

### Fixed

- **No cache setting means no cache.** The server's built-in defaults set
  `Q.web.cache.enabled` to `true`, although `docs/cache.md`, the README and
  `Q_WebServer_Cache::init()` all said `false`. A server that configured
  nothing about the cache therefore kept every public page it served, in
  `files/cache/reverse` below the application directory; and one that
  enabled the cache through its cache module and later disabled the module
  (`qbixctl dismod cache`, `mod:disable cache`) went on caching after a
  restart, because the module was the only file that said anything about
  the cache. The default is now `false`.

### Behaviour change

- **Enabling the cache is an explicit `enabled: true`**, in a module such as
  `mods-available/cache.conf` or in the site's own file. An installation
  whose cache module or configuration states `enabled: true` caches exactly
  as before; the site file (`--config`) still wins over a module, and a
  setting saved in the control panel over both. An installation that relied
  on the old default, with no `enabled` anywhere, stops caching: add
  `"Q": { "web": { "cache": { "enabled": true } } }` to keep it. A module
  that sets only `dir`, `defaultTtl` or other cache settings no longer turns
  the cache on by itself.

### Tests

- `tests/unit-cache-off-unless-enabled.php` starts real servers on a page
  sending `Cache-Control: public, max-age=300`: with no cache setting (no
  hit, nothing stored anywhere), with the cache module enabled (hits, stored
  in its directory), with the module disabled by the engine's own tool and
  the server restarted (no hit, nothing stored), and with `enabled` true and
  false in the site file. 4 of its 15 cases fail against v0.0.4.38.

## v0.0.4.38 — large uploads get a clean 413 or arrive whole, and compressed files open through the file layer

2026-09-29

### Fixed

- **A body over `post_max_size` gets a 413 the client can read.** Over
  HTTP/1.1 the 413 was written and the connection closed at once, with the
  body still arriving; closing a socket that has unread data makes the
  kernel reset the connection, and depending on timing the client saw the
  reset instead of the 413. The refusal now carries `Connection: close`,
  and what the client still sends is read and discarded until it stops
  (`Q.webserver.timeout.linger`, 5 s of silence, and
  `Q.webserver.timeout.lingerTotal`, 30 s in all) before the connection is
  closed. The check is made from the request head, before any body is kept.
- **HTTP/2 applies `post_max_size` too.** It never did: a body over the
  limit was held whole and handed to a worker, and came back as a 502 for
  every upload from 8 MB up. A `content-length` over the limit is now
  answered 413 on its own stream before any DATA is kept, a body without one
  is refused on the DATA frame that takes it over, the stream is then reset
  with `NO_ERROR` so the client stops sending, and the connection and its
  other streams carry on. Discarded DATA is credited back to the connection
  window.
- **Chunked request bodies.** A chunked body had no size check at all (it
  reached a worker and came back 502), was taken as complete wherever the
  text `\r\n0\r\n` first appeared inside its data, and on a kept-alive
  connection its bytes were left in the buffer as if they were the next
  request. Chunked bodies are now parsed chunk by chunk as they arrive:
  refused with 413 on the chunk that passes the limit, complete exactly
  where the last chunk and its trailers end, 400 for a malformed chunk
  size.
- **Binary uploads just under the limit.** A request travels to its worker
  as JSON, with a binary body base64-encoded, and the worker refused any
  request frame over a fixed 10 MB. An upload of about 7.5 MB or more --
  inside the default `post_max_size` of 8 MB -- therefore made the worker
  exit without a word, and the visitor got a 502. The frame limit now
  follows `post_max_size`, and the parent's deadline for handing a large
  frame to a worker grows with its size instead of a flat two seconds.
- **Slow uploads are not cut off.** The read timeout ran from the
  connection's accept, and a kept-alive connection's idle timer kept
  running while its next request arrived, so an upload taking longer than
  the timeout was closed part-way through. The head of a request has
  `Q.webserver.timeout.read` seconds from its first byte; a body may take as
  long as it keeps arriving, the timeout counting from its last read.
- **Framing from the head only.** `Content-Length` and
  `Transfer-Encoding` were looked for anywhere in the buffer, so a body
  containing that text -- an uploaded log file -- could be read as framing.
- **`post_max_size = 0`** means no limit, as in PHP; it used to refuse
  every request with a body.
- **Compressed files through the file layer.** The Compat file wrapper had
  no `stream_cast()`, so PHP could not put a zlib stream on a file opened
  through it: `gzopen()`, `copy()` into or out of a `.gz`, and every
  `compress.zlib://` path -- an Exponential package is a `.tar.gz` -- failed
  with "can not be opened for reading" under the server and worked under
  PHP-FPM. `stream_select()` on such a file threw.
- **Stream options on files.** The wrapper answered every stream option
  with false, so `stream_set_blocking()` and `stream_set_timeout()` failed
  and `stream_set_write_buffer()` returned -1 on every file. They now reach
  the file.
- **No stray warning from a quiet open.** A file opened with error
  reporting off (PharData creating an archive, `@fopen()`) printed a
  warning the plain file layer does not give.

### Tests

- `tests/unit-request-body-limit.php`: bodies at, under and over the limit,
  sent whole before reading the answer; chunked bodies over the limit, at
  it, with the terminator text inside the data, malformed, and two on one
  connection; bodies trickled in over three times the read timeout, on a
  new and on a kept-alive connection; framing text inside a body. 22 of its
  39 cases fail against v0.0.4.37.
- `tests/http2-request-body-limit.php`: 413 and `RST_STREAM(NO_ERROR)` for
  an oversized `content-length` and for a body without one, discarded DATA
  credited to the window, other streams served, a body at the limit whole.
  5 of its 16 cases fail against v0.0.4.37.
- `tests/unit-compat-wrapper-streams.php`: the same stream calls with and
  without the wrapper, including a `.tar.gz` built, read and extracted with
  PharData. 13 of its 31 cases fail against v0.0.4.37.

## v0.0.4.37 — multipart form fields with nested names reach $_POST as PHP builds them

2026-09-29

### Fixed

- **Nested field names in a multipart body.** The multipart parser
  understood one level of brackets: `tags[]` and `a[b]` worked, but
  `Attributes[0][id]` was stored under that literal string, so the
  application found no `Attributes` at all. The same fields sent urlencoded
  go through `parse_str()` and arrived intact, so the fault showed only in
  forms posted as multipart -- which includes every `fetch()` with a
  `FormData` body. Both multipart parsers, `Q_WebServer::parseMultipart()`
  and `Q_WebServer_Compat::parseMultipart()`, now build `$_POST` and
  `$_FILES` through the new `Q_WebServer_FormData`, which registers the
  names with `parse_str()` -- the code PHP registers every request variable
  with -- and puts the values in afterwards, so binary values never pass
  through the query-string encoding. Nested brackets, `[]` appends at any
  depth, mixed numeric and string keys, repeated names, spaces and dots in
  top-level names, unmatched brackets and `max_input_nesting_level` behave
  as in PHP.
- **`$_FILES` in PHP's layout.** A file field named `f[a][b]` gives
  `$_FILES['f']['name']['a']['b']`, and the same for `full_path`, `type`,
  `tmp_name`, `error` and `size`. As in PHP, an empty file field reports
  `UPLOAD_ERR_NO_FILE` with no type and does not count towards
  `max_file_uploads`; the file name loses any path the client sent
  (`full_path` keeps it); a file that was not kept has no temporary name and
  a size of 0; and uploads disabled, one file too many or a file field with
  broken brackets leave out that file and every later one, while text
  fields are still read.
- `tests/unit-multipart-form-fields.php` posts each case to `php -S` and to
  both parsers and requires identical results: keys, order, types and the
  uploaded files' contents.

## v0.0.4.36 — a warm-up that fails halfway no longer answers every request with its page

2026-09-28

### Fixed

- **A failed warm-up leaves nothing of its request in the workers.** The pool
  snapshots statics and globals after `Q.webserver.warmup`, and every worker
  restores that snapshot before each request. A warm-up that threw in the
  middle of a render stopped before its own clean-up, so the snapshot held
  that render's request -- address, route, visitor, open output buffers --
  and every worker began every request as that one. On an installation whose
  warm-up failed while its template cache was being cleared, half of the
  signed-in requests for the admin dashboard came back as the public front
  page, rendered for an anonymous visitor and marked publicly cacheable. The
  new `Q_WebServer_WarmupGuard` captures the parent's state before the
  warm-up and, when it throws, puts it back before the snapshot and the fork:
  output buffers the warm-up opened are discarded, superglobals and globals
  get their earlier values back (globals it added are removed), and the
  statics of classes declared during the warm-up return to their declared
  defaults, closures excepted (Composer's ClassLoader keeps its include
  helper in one). The classes stay loaded, so workers still warm lazily, now
  from a clean parent. The log line says what was undone. Tested by making
  the warm-up fail after a full render and requesting four addresses thirty
  times each, signed in and anonymously: every answer was that address's own
  page.
- **No deprecation for implicitly nullable parameters on PHP 8.4 and later.**
  Eleven parameters with a `null` default and a type are now typed `?Type`.

## v0.0.4.35 — security headers on every response, pages found behind a TLS-ending load balancer, and 401 challenges answered as 401

2026-09-28

### Added

- **`Q.webserver.headers`, `headersOnScripts` and `hsts`.** A static file
  never reaches the application, so served by this server alone a stylesheet
  or an image carried none of the security headers a front end sends, and
  HSTS went out only for a domain with a record of its own in the panel
  store. `Q.webserver.headers` (name => value) is now added to every response
  the server builds itself: static files over HTTP/1.1 and HTTP/2, the
  in-memory copy, 304s, image variants, its own error pages and redirects.
  `Q.webserver.headersOnScripts` adds them to a script's response as well,
  each only where the script did not send that header. `Q.webserver.hsts` (a
  max-age, an object with `maxAge`, `includeSubDomains` and `preload`, or a
  string) sends Strict-Transport-Security on every response over TLS and
  never over plain HTTP; a domain's own HSTS record still wins. A header
  already present, in any letter case, is never replaced or sent twice;
  names that are not HTTP tokens, values with CR, LF or NUL, and the framing
  headers are ignored. Nothing changes without the settings.
  `tests/unit-response-headers.php` holds it.
- Embedded OpenType fonts (`.eot`) are served with their media type.
- GitHub funding metadata, the same as the other se7enxweb packages.

### Fixed

- **The application's page cache finds pages behind a load balancer that
  ends TLS.** `Q.web.appCache` got scheme and host from the connection only.
  Behind a load balancer forwarding to `exp:8080`, the worker saw
  `X-Forwarded-Proto` and `X-Forwarded-Host` and stored the page for
  `https://www.example.com`, while the server process asked for `http` and
  `exp:8080`, so the page was never served from there. `serve()` now also
  gets `headers` (every request header, lower-case names) and `port` (the
  listener's), so the application can work scheme and host out as its own
  code does. Applications that ignore the new keys are unaffected. Exponential
  uses it from its HTTP cache (se7enxweb/exponential#103).
- **`header('WWW-Authenticate: ...')` answers 401** unless the call names a
  code, as PHP does, so an authentication challenge no longer goes out as
  500. `getallheaders()` and `apache_request_headers()` fall back to the
  request's headers in pool workers, where they returned nothing.
- **A static file is served whole to a client that takes no gzip when
  precompression is on.** When precompression had nothing to offer, its empty
  result was sent as the body: a 200 with `Content-Length: 0`. A HEAD for a
  large file on plain HTTP now describes what the GET sends.

### Updated

- **The exponential preset brings its own static, script and front
  controller lists** when the configuration names none. Started with the
  preset and no lists, an application served every file below its root and
  ran any PHP file. The lists are the ones the application ships and are
  only supplied where the configuration sets none, so narrower lists are
  never widened.
- **The tests rebuild the phar first and run against it.** The phar used to
  be rebuilt by its own workflow at the same time as the tests ran, so a push
  that changed the sources failed `phar-is-current` and the commit carrying
  the rebuilt phar was never tested. The tests workflow now rebuilds it in
  its first job, commits it back to main on a push there, and hands it to
  every suite.

---

## v0.0.4.34 — workers run as the site's user, not as root, and the response caches pause for a maintenance window

2026-09-27

### Added

- **Workers give up root.** Started as root, the server kept root in every
  worker, so each file a worker wrote under the site (image variations,
  caches, logs of the application) belonged to root while the site's own web
  server runs as the site's user: the site could not change or remove them,
  and the two servers raced on the same files. The master keeps root for what
  needs it — the ports, the certificates, reloads — and the zygote, every
  worker, fork-per-request children and scheduled tasks give it up right after
  they are forked, before any application code runs. Like Apache's `User` and
  `Group`: `--user` / `--group`, `Q.webserver.user` / `Q.webserver.group`, or
  `QBIX_RUN_USER` / `QBIX_RUN_GROUP` (a distribution adds its own prefix, vc
  `VC_RUN_*`) from the environment or the `envvars` file; left out, the owner
  and group of the document root. Root is refused unless
  `--allow-root-workers` / `Q.webserver.allowRootWorkers`; a user or group that
  does not exist stops the start before anything is bound, and a worker whose
  switch fails is ended instead of serving as root. The directories the
  workers write (`Q.web.cache.dir`, `Q.web.appCache.dir`,
  `Q.webserver.precompress.dir`, `Q.webserver.writable`) are handed to that
  user at start. `docs/workers.md` has the details; `tests/unit-runas.php`
  holds it.
- **`Q.web.cache.pauseFile`.** While the file named there exists, neither the
  response cache nor the application's cache answers: every request reaches
  the application. An application puts it there while it must answer
  everything itself — a maintenance window, an installation rebuilding its
  database — so visitors see its maintenance page and not pages stored before.
  Looked at no more than twice a second per worker.

---

## v0.0.4.33 — an application reading its own PHP files gets the files, not the transformed source

2026-09-27

### Fixed

- **Reading a PHP file gives its own bytes.** The compat file wrapper
  transformed every `.php` file opened for reading, not only those opened by
  `include` and `require`, so `file_get_contents()`, `md5_file()` and `fopen()`
  on application source returned the rewritten code. An application that
  checks its own files saw each such file as changed: Exponential's upgrade
  check (Setup › System Upgrade › File consistency) listed about 330 kernel and
  library files as modified under Velocity and none under Apache. Only includes
  are transformed now; `tests/unit-compat-read-is-the-file.php` holds it.

---

## v0.0.4.32 — several servers on one port, browsers answered from the response cache, purges seen at once, and the zygote on PHP 8.2 and 8.3

2026-09-27

### Fixed

- **The zygote runs on PHP 8.2 and 8.3.** v0.0.4.30 turned it off there, because
  a socket handed to it with `SCM_RIGHTS` arrived as another one. The fault is
  in how PHP puts a `Socket` object into the message; sent as a stream
  resource, the same socket arrives intact on PHP 8.1 and later. Each worker's
  connection is now handed over as the stream it is, the start-up self-test
  checks that same path, and the zygote runs on 8.2, 8.3, 8.4 and 8.5. See
  [docs/workers.md](docs/workers.md#forking-from-a-zygote).
- **One refused download no longer holds a release back.** v0.0.4.30 waited on
  a package job whose Docker Hub token request was refused and on a PHP 8.3
  lite build whose doctor had not fetched the musl toolchain; both now retry.
- **Browsers are answered from the response cache when the application's
  cache holds the page.** Its answers were kept in the response cache only
  when uncompressed, and a browser always asks for gzip, so no browser request
  was answered from there: one front page served 724 pages a second at 1.38 ms
  of CPU each. A gzip answer is now kept when gzip is the coding the response
  cache stores for that request (br and zstd are still left to the
  application's cache): 2,637 pages a second at 0.38 ms, and 2,965 at 64
  concurrent (was 741).
- **A purge reaches the response cache within the second.** The server process
  read the generation marker's mtime through the compat file wrapper, which
  remembered it: after a publish, purged pages were served for up to 8 s more.
  The marker's remembered stat is now forgotten before each once-a-second check.
- **Every server holds the same copy of a page.** A compressed answer from the
  application's cache was kept as it came, while a rendered page is minified
  first, so the same page existed in two forms with two ETags, and a browser
  revalidating against another server sharing the port got the whole page
  again. Such an answer is now decompressed, minified and compressed once.
- **PHP 8.5 full binaries build.** The `memcache` extension does not compile
  against PHP 8.5, so every 8.5 full build failed and none has shipped. An
  extension can now be marked unavailable for a PHP version
  (`unavailablePhp` in `build/extensions.json`, static builds only);
  `memcache` is, for 8.5.

### Added

- **`Q.webserver.reusePort`**: several servers can listen on the same port and
  the kernel spreads connections across them, so cached pages use more than
  one core: one server about 3,500 cached pages a second, two 6,600-6,900,
  four 12,200-13,100 (measured on 12 cores, TLS, gzip). See
  [architecture.md](docs/architecture.md#several-servers-on-one-port).

### Updated

- **Requests without a session cookie are answered from the response cache
  before the application's cache** (`Q.web.appCache`), and the application's
  answer to such a request is kept in the response cache (under its own
  rules), so the next one does not ask it again. Requests with a session
  cookie or credentials still go to the application's cache alone. An
  application whose purges should reach the response cache points
  `Q.web.cache.generationFile` at a file each purge rewrites. See
  [docs/cache.md](docs/cache.md#an-applications-own-cache).
- **The Revolt event loop is documented**: which extension each of its drivers
  needs (`ev`, `event`, `uv`), installing one system-wide, configuring and
  forcing the backend, and a measurement: up to 2,000 connections Revolt with
  `ev` was 2-12 % slower than `stream_select`, so `select` stays the
  recommendation. See [architecture.md](docs/architecture.md#revolt).

---

## v0.0.4.31 — pages over HTTP/2 known as secure, sessions where PHP is told to keep them, an application's own page cache asked first, and --version in every program

2026-09-27

### Fixed

- **Pages served over HTTP/2 were rendered as plain HTTP.** HTTP/2 is only
  served over TLS, but its requests carried no HTTPS flag, so the worker set no
  `HTTPS` variable: an application's absolute URLs and its own answer to "is
  this request secure" were wrong, and a page cache keyed by scheme stored
  HTTP/2 pages under `http://`.
- **Sessions went to the temporary directory.** The compatibility layer runs
  sessions itself and read `session.save_path` as a plain directory, so PHP's
  `N;MODE;/path` form (the usual way to make session files readable for the
  site's group) named no directory; set with `-d`, where `;` starts a comment,
  it arrived as `0` and every session went to `/tmp`. Files were created with
  the umask (0644) instead of PHP's 0600. The path is now read as PHP's files
  handler reads it: subdirectory levels, the mode for new files, and no
  collection with levels, which PHP leaves to a cron job.
- **A checkout reported the release and build of its last phar build.**
  `qbix-build.php`, written beside the sources by every phar build, outlived the
  build it described in a checkout of its own. It is now read only where the
  directory has no `.git` of its own.

### Added

- **An application's own page cache is asked before a worker**
  (`Q.web.appCache`: `file`, `class`, `dir`). The response cache skips every
  request with a session cookie; an application that keeps rendered pages per
  visitor or permission set can now answer those in the server process, so a
  hit never wakes a worker. See [docs/cache.md](docs/cache.md#an-applications-own-cache).
- **`--version`, `-version`, `-v`, `-V`, `--about`, `--copyright` in every
  program** — qbixserver, qbixctl, qbixconsole, qshell, qbix-appinfo, build-phar,
  build-app, the phar and static binaries, and `bin/uwebserver`: one GNU-style
  text with the program, release and build, where it runs from, engine, PHP,
  system and extensions, the copyright, the MIT licence and the warranty
  disclaimer.

---

## v0.0.4.30 — v0.0.4.29's worker pool fixed for PHP 8.2 and 8.3

2026-09-27

### What was wrong with v0.0.4.29

**On PHP 8.2 and 8.3, v0.0.4.29's worker pool answered only the first request
of each worker it forked after start; the rest were 502.** v0.0.4.29 turned the
zygote on by default (`Q.webserver.zygote`), and the zygote hands each new
worker its connection with `SCM_RIGHTS`, which is broken in PHP before 8.4:
`socket_recvmsg()` gives back a different socket from the one sent. On PHP 8.4
and 8.5 v0.0.4.29 works as released. If you run v0.0.4.29 on PHP 8.2 or 8.3 and
cannot upgrade yet, set `Q.webserver.zygote` to `false`.

### Fixed

- **The zygote is used only where PHP passes sockets intact.** The server hands
  a socket to itself at start and starts the zygote only when it arrives
  unchanged; otherwise workers are forked from the server as before, and the
  log says why (`zygote off: PHP 8.3.x does not pass sockets between processes
  intact`). The check can never stop the server from starting. PHP 8.2 and 8.3
  run without the zygote, 8.4 and 8.5 with it; a pool that forks under load
  answers every request on all four. See [docs/workers.md](docs/workers.md#forking-from-a-zygote).

---

## v0.0.4.29 — a response cache that is faster and says what it is doing, a control panel to run it, and error pages a visitor can use

2026-09-26

### Upgrading from v0.0.4.28

- **Pool workers are forked from the zygote by default.** `Q.webserver.zygote`
  now defaults to `true`: workers started after the pool come from a process
  forked before the first connection was accepted, so a worker forked while the
  server is busy holds no visitor's connection. Set it to `false` to fork from
  the server as before; without ext-sockets the pool falls back on its own.
- **An uncaught exception's message is no longer sent to the client.** Without
  `Q.webserver.debug` the response is the application's own exception handler,
  as in PHP (`set_exception_handler()`), or else the designed 500 page. The
  message, class, file, line and trace go to the error log as before, and to the
  response only with `Q.webserver.debug`. Anything that parsed the message out
  of a 500 body needs debug on.
- **APCu is used only when it can hold entries.** Under the CLI that means
  `apc.enable_cli=1`; with it off (PHP's default) the cache now says so at
  startup instead of running from disk while its settings said memory.
- **Cached pages are filed under the coding they are stored in.** Brotli and
  gzip clients now share one entry, so the first request for each page after the
  upgrade renders it again.

### Added

- **Optional TOTP two-factor authentication for the control panel, off by
  default.** With `Q.panel.twofactor` on and a device enrolled, panel sign-in
  asks for a time-based code (RFC 6238, HMAC-SHA1, 6 digits, 30-second step,
  ±1-step skew) after the password, with one-time recovery codes as a fallback.
  The engine has no new dependency: the codes are computed with `hash_hmac`.
  The secret and the recovery-code hashes live in the locked panel store beside
  the password hash and never leave the machine except the one-time enrollment
  views. A correct password gives only a pending session until a valid code
  promotes it; a wrong or replayed code is refused and feeds the same lockout as
  a wrong password. Enroll and manage it in the panel's Security tab or with
  `qbixctl panel:2fa` (status/enroll/confirm/disable/recovery); the CLI's
  `disable` is the local recovery path for a lost authenticator. When the flag
  is off, sign-in is byte-for-byte as before, even with a device enrolled. See
  [docs/2fa.md](docs/2fa.md).
- **A Cache tab in the control panel** to run the response cache from the
  browser: live hit rate, where hits come from, APCu memory, the stale and
  refused counts; settings with presets that are kept by the panel and survive a
  restart; clear, purge by URL or pattern, warm a page; a browser of stored
  pages. Its API is under `/Q/api/cache`. See [docs/dashboard.md](docs/dashboard.md).
- **An optional in-process memory layer in front of APCu** for cached pages
  (`Q.web.cache.memory.maxEntries`, off by default): about 12% less CPU per hit
  for large pages, no change for small ones.
- **`/Q/health` reports where cache hits come from** (the validator index,
  memory, APCu, disk), the APCu segment's size, use, entries and expunges, and
  refused stores.
- **`Q.dashboard.hidePanelRequests`** keeps the server's own `/Q/` requests out of
  the dashboard's counts, top paths and live log (off by default).
- **`Q.webserver.zygote`** and **`Q.compat.statTtl`** (how long a worker keeps
  what it knows about files across requests; 1 second measured 13% less CPU per
  rendered page).
- **Per-domain SNI certificate selection**, and client IP and user agent on 5xx
  metrics (never cookies).
- **`docs/panel.md`**: signing in to the control panel, starting with its default
  password; **`docs/cache-audit.md`**: the response cache audit and its measurements;
  and a README section on how Exponential Velocity differs from Qbix.

### Fixed

- **Keep-alive:** the last response before `keepAlive.max`, and a 5xx, promised
  `Connection: keep-alive` and then closed the connection; about one request in
  a thousand failed. Both now say `Connection: close`.
- **Error pages:** every error the server answers itself is the designed page,
  in plain words, on HTTP/2 as well as HTTP/1.1 -- a blocked path over HTTP/2 was
  the bare word "Forbidden", and a worker that stopped or timed out sent
  "Worker died" or "Request timed out" as text.
- **An open dashboard doubled the cost of every request:** its stats were rebuilt
  for each request; they now go out at most once a second.
- **The static file cache stopped taking files once full;** it now evicts the
  least recently used.
- **`q=0` in Accept-Encoding was ignored** by the response cache, static files and
  precompressed files.
- **A zygote hand-off interrupted by a signal** was taken for a dead zygote.
- **`tests/bench-load.php` reported one CPU too few.**

### Updated

- **Response cache:** a HEAD for a cached page is answered from the cache; the
  APCu copy is kept through the stale-while-revalidate window; `purge()` says how
  many entries it removed.
- **Pool workers** remember which paths exist for the rest of a request, and
  forget file facts after another program runs.
- **The dashboard's swap figure** reads used / total and is coloured by how fast
  pages come back from swap, not by how much is parked there.
- **Documentation:** worker memory, reset cost and the shim count, from
  measurements: a worker holds 1.3–1.9 MB of private memory with nothing loaded
  and about 10 MB for a full CMS; the reset takes 0.55 ms and 4.6 ms; the default
  event loop stops a pool below about 1,000 workers; 44 functions are shimmed.
  Earlier pages said 120–200 KB, 5,000 workers per GB and 0.03 ms.
- The old `ghcr.io/se7enxweb/qbix-webserver` image is marked deprecated in favour
  of the exponential-velocity image; the illumos platform check runs on demand.

---

## v0.0.4.28 — domains, certificates and a shell in the control panel, and scripts that run only when listed

2026-09-25

### Upgrading from v0.0.4.27

- **The control panel's credentials and sessions moved, and are trusted only
  when no one else can change them.** With a configuration tree they live in
  `<conf>/acl/panel.json` and `<state>/sessions/`; without one, in `local/`
  beside the application as before. An existing password is carried over on the
  first start (the old file is kept, renamed). Every directory from the store up
  to `/` must belong to root or the server's user and not be writable by group
  or others; otherwise the panel is locked. The common case after an upgrade is
  an application directory left `0775` by a umask of `002`: the refusal, the
  start-up log and `qbixctl panel:check` name that directory and the fix
  (`chmod g-w,o-w <dir>`), or set `Q.panel.aclDir` / `Q.panel.sessionsDir` (or
  start with `--conf-dir`) to keep the panel's files elsewhere. See
  `docs/dashboard.md`.
- **The access log's default format is now `vhost`:** the `qbix` format plus the
  host name, in quotes, at the end of each line. A log reader anchored at the end
  of the line (fail2ban, a GoAccess custom format) needs updating, or set
  `"Q": {"web": {"log": {"format": "qbix"}}}` to keep the old lines.
- **Listed scripts, front controllers and static paths are opt-in.** Nothing
  changes until `Q.webserver.scripts`, `Q.webserver.frontControllers` or
  `Q.web.static.paths` is set; see Added below and `docs/configuration.md`.
- **The `iopoll` event loop is opt-in.** `auto` chooses Revolt when it is
  installed, else `stream_select`, as v0.0.4.27 did; `iopoll` runs only when
  asked for (`QBIX_EVENT_LOOP` or `Q.webserver.eventLoop`), until it has been
  tested against the real `Io\Poll`.
- **The control panel starts with a default key that must be changed** at the
  first sign-in, and passwords are now checked against rules and stored with
  bcrypt. An existing password keeps working. See `docs/passwords.md`.
- **The operating-system package was renamed to `exponential-velocity`** (deb
  and rpm), with the service unit `exponential-velocity.service` and the
  `/usr/share/exponential-velocity`, `/var/lib/exponential-velocity` and
  `/etc/default/exponential-velocity` paths. Installing it over an installed
  `qbix-webserver` package upgrades in place: it declares Replaces/Obsoletes,
  carries the settings and state across, re-enables and restarts the service if
  it was running, and leaves `/usr/share/qbix-webserver` as a symlink so old
  references keep working. See `docs/packages.md`.
- **The project's default branch is now `main`** (was `maintain`), and the
  package it publishes is `se7enxweb/exponential-velocity`.

### Added

- **Only listed scripts run by name, and only listed files are served as they
  are** (contributed by @fwoldt). `Q.webserver.scripts` names the scripts a
  request may run under their own name; `Q.webserver.frontControllers` maps
  path patterns to scripts (`{"^/api/": "index_rest.php"}`); and
  `Q.web.static.paths` lists the patterns a file must match to be sent as it
  is. Anything else goes to the front controller, as an application's
  `.htaccess` would send it, so a bundled tool or a command-line script is not
  run by being asked for, and protected uploads stay behind the application's
  download view. The response cache starts a new generation when the lists
  change, so nothing stored before is answered after.
- **Domains in the control panel.** The domains in use (listeners, certificate
  names, Host headers seen, site files), a record per domain with a status
  (active, suspended with 503, disabled), aliases, subdomains and a document
  root per domain with host routing on HTTP/1.1 and HTTP/2, redirects (HTTP to
  HTTPS, preferred `www` or bare host, custom path and host forwarding), HSTS,
  custom error documents, the certificate covering each domain with issuing or
  renewing it for one domain, and per-domain traffic linked to the Logs tab.
- **An SSL tab in the control panel**: the served certificate, every
  certificate by expiry, safe settings, renew and reload, and a bounded
  certificate history.
- **The Q shell**, a drop-down console on every server view (`` ` `` or the
  toolbar): zsh-style line editing, commands for the server (`server`, `ssl`,
  `conf`, `site`, `mod`, `cache`, `logs`, `workers`, `ext`), pipes and
  scripting, tiers with a password step for the commands that change the
  server, jobs, tabs and splits, window controls, a REST API, and a history of
  a hundred thousand entries paged to the console and searched on the server.
  Server commands run as the server, with its configuration; everything else
  runs as the configured shell user. An application the server recognises can
  add its own commands. See `docs/shell.md`.
- **One registry of the PHP applications the server recognises**, used by the
  panel's Apps and Frameworks tabs and the autohost, so an installation served
  from its own directory is no longer invisible.
- **Bookmarkable control panel tabs**, and a Logs tab that filters by text,
  method, status and host.
- **A metrics view for browsers at `/Q/metrics`**; scrapers keep the Prometheus
  text format.
- **A PHP extension baseline**: one manifest of the extensions the server
  provides, the `ext:*` commands, a check at start, in `/Q/health` and on the
  dashboard, and `docs/requirements.md` and `docs/extensions.md`.
- **Release builds for every platform, PHP version and variant**, computed from
  the extension baseline, with a source kit and checksums; deb and rpm packages
  for Debian 12 and 13, Ubuntu 22.04 and 24.04, and EL 9 and 10; and Docker
  images for every PHP version and variant on amd64 and arm64. See
  `docs/binaries.md`, `docs/packages.md` and `docs/docker.md`.
- `qbixctl panel:password` to set the control panel password from the command
  line, and `qbixctl status`, `stop` and `graceful` finding a running server
  without its pid file.
- The previous exceptions of an uncaught error in the log, each with its file
  and line; with `--debug`, the place, trace and causes in the response too.
- Documentation pages of lessons, general and hard-won.

### Fixed

- **A second server on the same certificate directory and port took HTTPS
  away from the first.** Each server now keeps its own copy of its certificate
  and removes only its own and those of stopped servers; the shared
  self-signed pair is replaced only when it lacks a name that is asked for,
  and keeps the names it had. A stopping server no longer removes another
  server's pid file or stops its watchdog.
- **An include of a file being rewritten in place could run the head of one
  version joined to the tail of the next.**
- **The shell works in every form the server ships in** -- the phar, the
  packages, the container image and the static binaries -- where it answered
  that its runner was missing (as a `429`, shown as "too many jobs"). A
  missing runner answers `503` with the reason.
- **Shell commands** no longer print nothing when the console's WebSocket is
  refused, the WebSocket is no longer refused on every TLS page, jobs no
  longer report exit code 1 when the server's child reaper reaches them first,
  server commands get the server's configuration, `-f` works anywhere on the
  line, and commands no longer receive the server's sockets or environment.
- **The server stopped minutes after the shell was used** with a dashboard
  open.
- **The `iopoll` event loop failed to load**; every backend now behaves the
  same and is tested.
- The control panel flashed its sign-in form on reload, and a signed-in panel
  session was not recognised by the dashboard and the other `/Q/` views.
- The panel answered the application's 404 over HTTP/2, the component cache
  could not be switched on, and settings the worker pool ignored now apply.
- The server died at start with a document root directly under `/`, such as a
  container's `/app`.
- `ext:build` in the source kit rebuilt the phar from a tree without its
  designs.
- The OpenBSD and NetBSD platform jobs, which had failed on every run.
- **The `full` static binaries build again on every platform.** What
  static-php-cli cannot build, or builds without registering, is left out per
  platform with its reason: `rar` everywhere (its upstream branch is gone),
  `gmssl` everywhere (built but never registered), `mysqlnd_ed25519` and
  `mysqlnd_parsec` (shared only), `protobuf` (conflicts with `grpc`), `yac` on
  arm64 (no atomic compare-and-swap it recognises) and on Windows (breaks
  `redis`' igbinary detection), and on Windows also `xlswriter`, `ds` and `xz`.
  `ext:plan --variant=full --platform=<platform>` lists them.

### Updated

- **The server's own pages are compressed on HTTP/1.1** (gzip, or brotli when
  available), share one stylesheet, and meet contrast and heading-order
  checks; the dashboard, PHP Info and metrics views use the control panel's
  header, navigation and colours.
- **Every control panel settings write goes through the panel store's lock**,
  and the shell's old files move into the state directory.
- The compat file wrapper tests paths with string comparisons instead of
  regular expressions.
- Panel passwords may repeat digits, symbols and separators; only the whole
  host name is forbidden.
- Every command-line script starts with `#!/usr/bin/env` and is executable.
- A newer push cancels the Docker and platform runs still queued for an older
  commit on the same branch.
- A failed static PHP build on Linux or macOS shows static-php-cli's own
  compiler errors in the job, as the Windows build already did.
- The documentation and help text match what the code does.

---

## v0.0.4.27 — HTTPS that looks after itself, a worker pool that sizes itself, and workers that stay the size they started

2026-09-24

`v0.0.4.26` was tagged on 2026-09-23 but never released: it had no section
here, so the workflow built it and stopped, as designed. It is still a
version on Packagist. Everything it contained is described below, so a
reader moving from `v0.0.4.25` needs only this section.

### Added

- **HTTPS that needs no setup and no attention.** With no certificate
  configured the server makes its own self-signed one, through a chain of
  providers tried in order (ECDSA, then SHA-256 RSA, then the `openssl`
  command, then the system's snakeoil pair), so it works where one of them is
  missing or refused. It follows the host names it is reached by, renews
  before expiry, and swaps a new certificate into the running server without
  a restart or a dropped connection. HTTPS comes up before HTTP. Every step is
  reported through events, so the log says what was tried and why it failed.
- **Certificates from wherever you keep them, and Let's Encrypt built in.**
  PEM or DER files with their chain, a directory, a `.zip`, `.tar.gz`,
  `.tar.bz2` or `.rar` archive, or a PKCS#12 bundle; and an RFC 8555 ACME
  client for Let's Encrypt or any other ACME CA, run as a background job with
  backoff so a CA outage never touches serving. See `docs/https.md`.
- **A worker pool that runs only the workers it needs.** It grows with load
  up to `Q.webserver.workers` and retires idle ones down to
  `Q.webserver.spareWorkers` (measured on an Exponential install: 7,094 MB held
  by a fixed pool of 590, 626 MB dynamic). A worker's
  death never costs a request that could still be served: a GET, HEAD or
  OPTIONS it had not started answering runs once more on another worker; a
  POST is never run twice and gets a 502.
- **A configuration directory laid out like Debian's `/etc/apache2`**:
  `qbix.conf`, `ports.conf`, `envvars`, and `conf-`, `mods-` and
  `sites-available` with `-enabled` symlinks. Overlay trees can be stacked on
  `/etc/qbix`, and a distribution option lets an engine built on this one add
  its own. See `docs/layout.md`.
- **`qbixconsole` and `qbixctl`**: a console with commands, aliases,
  abbreviations and help that needs no library, and apache2ctl-style control
  (`start`, `stop`, `restart`, `status`, `configtest`) for the server.
- **Every command line accepts GNU and BSD option spellings**: `--name=value`,
  `--name value`, `-name=value`, `-name value`, bundled one-letter flags and
  `--no-flag`.
- **Designs on disk for the server's own pages**, so the dashboard, panel,
  documentation, directory listing and error pages can be restyled without
  editing the engine. See `docs/designs.md`.
- **A toolbar linking the server's own views**, and documentation pages on
  the layout, the console, designs, the response cache and workers.
- **A generation marker for the response cache**: a deploy invalidates every
  cached page by touching one file.
- The exception class, file, line and a short trace in the log when a
  script's exception reaches the worker.
- Icons, a web app manifest and link previews for the server's own pages.
- A pooled request is told the port it arrived on and the address it came
  from.
- `QBIX_SHIP_VERSION=vX.Y.Z.N` for `build-phar.php`, so a release's phar
  shows the version being released. It is built before its tag exists, and
  every release phar until now named the release before it.

### Fixed

- **A file rewritten after start could keep running its old code.** Three
  separate paths, all closed: files the parent warm-up had included were
  served as the warm-up saw them until a restart; a regenerated script ran its
  previous compile when the opcode cache does not check timestamps on every
  include; and a file rewritten while a worker sat idle was served once more
  from the old compile. File stats and included files no longer go stale
  within a request or within the same second either.
- **Under PHP 8.2 and 8.3's function JIT (`opcache.jit=1235`) the source
  transform wrote a script's code twice.** Once the transform loop turned hot
  the JIT compiled it mid-call and the compiled loop restarted from the first
  token while keeping its output. PHP 8.4 and later, tracing mode, and no JIT
  were unaffected. The loop is now written so the JIT compiles it correctly,
  and the test suite runs under 1235 again.
- **The admin surface and cluster join were open to anyone who could reach
  the port**, and a set of lower-severity issues from an audit are closed:
  request framing (bare LF and obs-fold), log injection, response-cache
  personalisation, dashboard injection through the Host header, and HPACK
  decoded-size amplification. The HPACK check first applied to every header
  block and broke every browser's HTTP/2 connection; it now applies only to
  table-size updates.
- **HTTP/2:** browsers that cancel streams across many reloads were cut off as
  a rapid-reset attack (and every GOAWAY now logs why it was sent); requests
  could be left unread in the TLS buffer; a pooled response cancelled the
  reader of its whole connection; in fork-per-request mode a script's answer
  closed the connection, leaving signed-in pages without their header and
  styles; and one request for an unknown `/Q/` path hung the whole server.
- **A newly forked worker ended every TLS connection open at that moment.**
- **Request bodies of 0–47 and 58–255 bytes were refused** as
  "Content-Length is not a number".
- **An application's session name and cookie lifetime were ignored**, and
  cached rewrites outlived a change to the rewrite rules.
- A pooled script answered `HEAD` with its body and saw no `PATH_INFO`.
- `php://input` in a worker printed a PHP 8.2+ deprecation for a dynamic
  `$context` property into the response where `display_errors` is on.
- The server's own certificate, and the test certificates, were refused where
  the system will not sign with SHA-1.
- `--verify-binary` printed PHP warnings for an unsigned file instead of
  saying so, and `--sign-binary` exited 0 when it could not sign.
- The dashboard: Top paths ran the count and the average time together,
  System RAM is coloured by severity, the Live requests memory column was
  empty with the newest entries hidden at the bottom, and TLS visitors were
  recorded as `0.0.0.0`.
- One hanging test hung the whole unit run and left the servers it had
  started behind; the runner now times each test out.

### Fixed

- **Every request left an output buffer behind, so persistent workers grew
  without limit.** The response was captured in a buffer opened per request
  with `ob_start(null, 0, 0)`; flags `0` make a buffer impossible to remove
  *and* impossible to clean, so each request's buffer -- with its whole page in
  it -- stayed on the stack for the life of the worker. Measured on an
  Exponential install at ~2 MB a request: one worker at 1.1 GB after 600
  requests. The same happened in the in-process server. Responses looked
  right, because the body was read from whichever buffer was on top -- except
  when a script left a buffer of its own open, when only that buffer's content
  was sent. There is now one capture buffer per process, reused and emptied
  each request (`Q_WebServer_Capture`), and buffers a script leaves open are
  part of its response.
- **Every request left its error handler behind.** PHP keeps each handler
  that `set_error_handler()` replaces on an internal stack no PHP code can
  see, and the between-request reset "restored" the boot handler by setting
  it -- one more push. Exponential installs a method of its eZDebug instance,
  which holds everything the request logged, so every request's debug log
  stayed in the worker: ~1 MB a request, 4 MB for a search page. The reset
  now pops handlers until the boot one is current, compared by identity.
- **The file wrapper leaked a resource on every filesystem call.** To reach
  the disk, the compat `file://` wrapper unregistered and re-registered
  itself, and each registration is a resource PHP frees only when a request
  ends -- never, in a worker. Exponential makes up to 14 000 such calls a
  request. Missing paths and directory listings are now answered with
  `glob()`, which bypasses the wrappers; `fstat()` on an open file no longer
  unwraps; the transform sends `file_exists()`, `is_dir()` and `is_file()`
  to shims that ask the OS directly; and repeated stats of a path within a
  request are remembered (forgotten on any write through the wrapper and at
  the end of the request). Per-request calls went from thousands to
  hundreds. A transformed file's stat now carries its mtime, without which
  the opcode cache would not store it, so transformed scripts are no longer
  recompiled on every include.
- `is_link()` was false for every link under the compat wrapper, which
  answered link queries with `stat()` instead of `lstat()`.
- **Includes no longer cost a leaked resource each.** A template engine
  includes the same compiled templates over and over (~1 900 includes of a
  few dozen files on one search page), and each was a real open through the
  wrapper. Included files are now read once and their bytes kept (at most
  4 MB per process), served while a real stat taken in the same request
  still matches their mtime and size -- so what runs is always the file as
  it is on disk. Mixed traffic now grows a worker ~0.1 MB a request.
- **`$db or die(...)`, `else exit;` and `$ok || exit(1)` ended the worker.**
  The source transform's member test was written `$isMember = is_array($prev)
  and (...)`; `=` binds tighter than `and`, so it assigned `is_array($prev)`
  alone, and any `exit` or `die` after a keyword or operator counted as a
  method call and stayed a real exit. Every such exit in an application killed
  the worker serving it.
- **A worker that exited deleted the server's pid file and killed its
  watchdog.** The cleanup is a shutdown function registered in the parent,
  and every forked worker inherited it -- so a worker replaced at its memory
  ceiling, retired at the application's request, or crashed, ran it on the
  way out. The server kept serving, but `status` and `stop` could no longer
  find it. It now acts only in the process that registered it.
- **Cyclic garbage piled up in workers.** PHP runs its cycle collector only
  when the root buffer fills, and raises that threshold whenever a run finds
  little, so in a process that never ends a request, one request's
  self-referencing objects outlived it: ~47 KB a request on an Exponential
  install. The between-request reset now collects cycles; the buffer only
  ever holds one request's candidates, so it is cheap.
- **Fewer wrapper registrations still.** With an engine archive in use the
  wrapper also swapped out `phar://` -- one more registration -- around every
  operation, even on plain files; it now does so only for phar paths. And
  `filemtime()` and `filesize()`, which return one number each, are sent by
  the transform to shims that ask libcurl's `file://` (which bypasses PHP's
  stream wrappers) and never put a partial stat in PHP's stat cache; includes
  use the same. A worker now makes ~12 registrations a request where it made
  12 600.
- **Edited and regenerated files are picked up without a restart.** The
  opcode cache reads its clock only at request startup, which a persistent
  worker never repeats, so it never revalidated a cached script: a
  regenerated template or an edited class ran its old compile until the
  server restarted. The wrapper now invalidates a script's compile when it
  sees the file's mtime move. The transform cache had the same fault -- an
  edited file that needs the transform kept its old transformed source --
  and now re-transforms on a changed mtime.

Together: a worker that grew ~2 MB a request (1.1 GB after 600 on an
Exponential install) now holds a flat heap -- ~2 KB a request, the few
registrations left -- bounded in any case by the worker memory ceiling below.

- **The "Worker Memory (COW)" card reported several times the real memory.** It
  summed each worker's RSS, and RSS counts a shared copy-on-write page in full
  against every process mapping it -- so the warmed baseline shared across 380
  workers showed as ~15 GB, the opposite of what copy-on-write does, and it did
  not fall when the server restarted because every worker inherits that shared
  baseline at birth. The card now reports PSS (proportional set size) summed
  over the parent and workers, which is the actual physical memory: ~2.9 GB
  where RSS claimed ~15 GB.
- The live request log printed raw millisecond floats
  (`502.26688385009766ms`); durations are rounded to one decimal, at the source
  and in the render.
- The status-code filter offered only codes seen live after the page loaded;
  it now lists every code the server has recorded, from the stats it already
  sends.
- **Reading the worker-memory card wedged the server at scale.** It read
  `/proc/<pid>/smaps_rollup` for every worker to sum PSS, inside the single
  event loop, every couple of seconds while a dashboard was open. That read
  walks all of a process's mappings, so at hundreds of workers it stalled the
  loop long enough that no connection could be accepted -- the front page timed
  out, not just the dashboard. It now samples a bounded set of workers and
  scales the average, at a fixed cost regardless of pool size.

### Added

- `Q_WebServer_Pool::retireAfterResponse($reason)`, for application code that
  can run only once per process -- one that defines constants from the
  request, say. The request is answered normally; the parent then replaces
  the worker and logs the reason. Outside a pool worker it does nothing.
- **A per-request health check that replaces a worker instead of letting it
  grow.** After each request a worker checks that its output stack is back to
  the one empty capture buffer and that its heap is under
  `Q.webserver.workerMemoryCeiling` (MB; default 256, or three quarters of
  `memory_limit` if lower). A worker that fails still answers, and the parent
  replaces it before its next request and logs the reason, so a leak anywhere
  costs a re-fork and a named log line rather than the machine's memory.
- **A parent warm-up, `Q.webserver.warmup`.** A script the pool runs once in the
  parent, after the source-code transform is installed and before it forks --
  typically one rendering a representative page. The arena that render grows is
  inherited copy-on-write, so a warm worker holds ~21 MB private against ~209
  MB without it on an Exponential install. It has its own key because the
  existing `Q.webserver.preload` is required before the transform exists: a
  script run there compiled the whole application untransformed, and every
  worker inherited a real `exit` (answering `502 Worker died` wherever the app
  finishes a request with `exit`) and a `header()` that does nothing under the
  CLI SAPI. The server now warns at startup when `preload` is set with the
  transform on.
- Column headings on the live request log (Time, Sts, Verb, Path, ms, Mem).
- The dashboard's Workers card says what its numbers are. It showed
  "590/590" (idle of total, unlabelled) over "reqs: 5 PHP / 7 static", which
  counts requests served and was read as "only 5 PHP workers". It now shows
  the worker count, then idle and busy workers, then PHP requests and static
  files served, each on its own labelled row.
- The dashboard reads at a glance: the status-code and worker-memory cards
  list one item per line, the header reads "Linux · PHP x · Live · Up 15s",
  and "Documentation · Powered by the Qbix engine" has its own centred line
  in the footer.
- The dashboard's own heading links to the dashboard; the footer's product name
  keeps its link to the repository.
- Swap usage on the System RAM card. A box can read a comfortable RAM
  percentage while it has pushed gigabytes to disk under earlier pressure; the
  card now shows swap when any is in use and tints red then, so the reading is
  not falsely reassuring.
- An `exponential` framework preset (`--preset=exponential`), for the eZ
  Publish 4 legacy line. Alongside the front controller and ini limits it keeps
  the source-code transform on (the kernel calls `header()`/`setcookie()` the
  SAPI-coupled way) and preserves the type registries across requests -- both
  settings a modern framework does not need and would otherwise be found the
  hard way. A preset can now carry a `_webserver` block that merges under
  `Q.webserver`, which is how the preset reaches `keepGlobals`.
- The product name across the served views is a parameter (`Q.webserver.brand`),
  with optional links for it and a maintainer credit, and the shown version is
  the fork's own release from `git describe`, stamped at build time, with the
  upstream number kept as the engine it is built on.


### Added

- The brand and maintainer labels can now carry links. `Q.webserver.brandUrl`
  links the product name (to its repository), and `Q.webserver.maintainer` /
  `maintainerUrl` add a "Maintained by <name>" credit that links where you say.
  All are empty by default, so upstream shows plain text and links nothing it
  was not given. A url key that is not http/https is dropped rather than
  linked, so a malformed setting cannot inject a `javascript:` link.
- The version shown is now the fork's own -- `v0.0.4.26`, the nearest release
  tag on this branch -- not upstream's `1.5.0`, which stays defined as the
  engine this is built on and is named in the footer ("powered by the Qbix
  engine"). The ship version is stamped from `git describe` at build time, so
  reading it costs nothing at runtime. The dashboard footer also carries a
  "Maintained by 7x" line.
- The served views now carry a footer, and the version display carries the
  build: the short commit and the datetime the phar was built, e.g.
  `Exponential Velocity v1.5.0+a34150c (2026-09-23 17:16 UTC)`. `build-phar.php`
  stamps both into the phar and a file on disk beside `qbixserver.php`, because
  the server is often run as a plain vendored file rather than through the phar
  stub — and a vendor directory is frequently its own checkout on an unrelated
  commit, so asking git at runtime reported the wrong thing. The stamp is
  semver build metadata after a `+`, which every version comparator ignores.
- The product name shown across the served `/Q/` views is now a parameter,
  `Q.webserver.brand`, default `Qbix Server`. `Q_WebServer::brand()` is the one
  accessor; the dashboard heading and title, the docs chrome, the panel title
  and the manifest's human-readable fields all ask it rather than hardcoding a
  name. A fork can name itself without editing a dozen views or diverging from
  upstream at every occurrence. The wire identifiers — the `.well-known/qbix`
  path, `qbix.json`, the `qbix` framework key, `isQbixApp`, the provider name —
  are protocol, not brand, and are deliberately left as they are: renaming them
  would break federation and app detection for a cosmetic gain.

### Fixed

- **The dashboard's live WebSocket never connected over HTTPS.** It hardcoded
  `ws://`, and a browser blocks an insecure socket opened from an `https://`
  page as mixed content — so the status sat on a red "connecting" that never
  resolved, and the page only updated on reload. The scheme and host are now
  taken from `location` in the browser, which is authoritative for both, so it
  is `wss://` on a secure page and the port is always right.
- The HTTP/2 route did not answer the server's own URLs. `/Q/dashboard`,
  `/Q/health` and `/Q/metrics` were handled on HTTP/1.1 only, so every browser
  — which negotiates HTTP/2 — got the application's 404 from the dashboard
  while `curl --http1.1` got 200. The delegation now sits *behind* every
  refusal the HTTP/1.1 path makes, because `route()` can fall through to
  serving a static file and in front of the guards it would serve files around
  them.
- HTTP/2 served files that HTTP/1.1 refused, including `/settings/site.ini`
  — 74KB of configuration with database credentials in it — and `/.git/config`.
  The blocked list and the extension allow-list were consulted on one path and
  not the other, so everything they protected was protected only from clients
  old enough to ask in the older protocol.
- **Durations were printed to thirteen decimal places.** The dashboard card
  read `Avg response 258.4ms` above `slowest: 1541.8879985809326ms` — two
  numbers side by side disagreeing about how precisely this server measures
  anything, with the second long enough to break the width of the card holding
  it. The same raw value went out in `/Q/health`, and the console access log
  had it too (`GET /slow.php (125.39982795715ms)`) while the *file* access log
  had always used one decimal. Rounded where the numbers are produced rather
  than where they are shown, so every consumer benefits.
- **The log filled with `ReflectionProperty::setAccessible() is deprecated`.**
  Once per static property, every time a snapshot was taken. The call has had
  no effect since PHP 8.1 — this package's own minimum — and PHP 8.5 deprecates
  it, so the only thing it still did was write the notice.
- **The dashboard showed `\u00B7` and `\u2014` as text.** Thirteen JavaScript
  escapes were written directly into the dashboard's HTML, where nothing
  interprets them — PHP reads only `\u{00B7}`, with braces, and JavaScript
  never saw these. So the status line read `546 ok \u00B7 28 redir` instead of
  `546 ok · 28 redir`; the Workers, System RAM and Worker Memory cards showed
  `\u2014` where a value belongs; and the pause and close buttons were
  labelled `\u23F8` and `\u2715`. Nothing failed, and nothing could have: the
  page rendered perfectly, reading wrongly. It was found by somebody looking at
  it. They are now HTML entities, and the identical escapes inside `<script>`
  — where they *are* interpreted — were left alone.
- **The dashboard could not see most of HTTP/2.** A script is handed to the
  worker pool, which records it when it answers, so PHP requests were counted
  on both protocols and the numbers looked right. Everything the HTTP/2 route
  answered itself — static files, and *every refusal* — was counted nowhere.
  Measured: five requests for `/.git/config` over HTTP/1.1 moved the 4xx
  counter from 2 to 7; five identical requests over HTTP/2 moved it from 7 to
  7. A browser negotiates HTTP/2, so anyone probing the server with a modern
  client produced a dashboard showing that nothing had happened.
- A signed-in visitor's pages were cached and served to everybody else. The
  cache skips a request carrying a session cookie, but the match required the
  configured name followed immediately by `=`. Exponential's cookie is
  `eZSESSID<digest>`, so the skip never fired.
- Two tests left a server running every time they ran — `proc_open` with shell
  redirection reports the shell's pid, so terminating it orphaned the server.
  Thirty-nine had accumulated on one machine.
- The Windows build had no archiver available to extract php-src with, and
  before that failed on a line continuation written for the wrong shell and
  asked spc for a library it cannot build on that platform.
- OpenBSD installed nothing at all; NetBSD was missing a dependency by name.
- The Windows build, properly this time. `windows-latest` had migrated to the
  `windows-2025-vs2026` image, which installs Visual Studio 2026 at
  `...\Microsoft Visual Studio\18\Enterprise` — VS 2026 is version *18*, not a
  `2022` directory. spc finds Visual Studio by testing six hardcoded paths for
  2022 and 2019, so it found none, returned `false`, and the caller read
  `['version']` off it. The build therefore died twenty minutes in with
  `Current VS version  is not supported yet!` — an empty version, and no
  mention of the one thing that was wrong. **Nothing in this repository
  changed; the runner did.** The job is now pinned to `windows-2022`, the
  toolchain spc actually targets, and a two-second precondition step reports
  the real cause by name if an image ever moves again.

### Updated

- The platform documentation now advertises what this actually runs on, in
  three honest tiers — proven, expected, and not today — rather than implying
  uniform support.
- The Amiga answer is now a map for someone who might attempt the port, naming
  the PHP 5 to PHP 8 library gaps that stand in the way, rather than a refusal.
- A release title carries its summary instead of repeating the tag.

### Added

- This changelog, and a release process built on it. A published release is now
  a deliberate batch rather than a side effect of tagging: the workflow
  publishes a release **only** for a tag with a section here, so tags stay
  cheap and releases stay meaningful. Eight releases went out in one day before
  this, three with no title at all. Each release links back to the full
  changelog and to every commit since the release before it.
- `tests/unit-http2-route-order.php`, asserting that every refusal in
  `http2Route()` is present *and* in an order where it can do its job. Each
  assertion was checked against a deliberately broken copy of the source, so
  the test fails when the guards move rather than passing regardless.

---

## v0.0.4.25 — three security fixes, and macOS ships for the first time

2026-09-23

### Fixed

- **A symbolic link inside the document root served, and executed, files
  outside it.** A link named `*.php` turned the ability to create one file into
  the ability to run code from anywhere the server user can read. Containment
  is now checked on the resolved path at all four dispatch points; closing
  three of them was not enough, because with a worker pool configured the
  dispatch reaches `handlePhp()` by a different road.
- **Contradictory request framing was resolved rather than refused**, which is
  request smuggling (RFC 9112 §6.1). Bare LF line endings were also accepted,
  and a request using them never completed at all — it held a connection slot
  until the read timeout, from one short write.
- **Six HTTP/2 frames the RFC says must be refused were accepted instead**,
  including frames larger than the size the server itself advertised.
- A worker answered from its own stat cache when deciding whether a
  revalidation claim had been abandoned.
- The last writes that could go out short without anyone noticing, on the IPC
  pipe and the session file.
- Workers exited after one request, from a write that handed the socket back
  non-blocking.
- `php-cgi` mode answered every request with "Class Q_WebServer not found".
- The server died on FreeBSD after printing its banner.
- riscv64 segfaulted under emulation until PCRE's JIT was turned off.

### Added

- The macOS binary. **It was never broken** — the test that condemned it never
  started the server, and the claim has been withdrawn from the README.
- `docs/security.md`, recording what the server refuses and why, for anyone
  maintaining this or a fork of it.

## v0.0.4.24 — the phar is published as a release asset for the first time

2026-09-23

### Added

- A platform matrix that watches the phar serve a page on systems we ship no
  binary for, covering musl, DragonFly, illumos, RISC-V and ARMv5.

### Fixed

- `--stop` and `--reload` exited 0 without ever sending the signal.
- Every platform job was failing, on two unrelated causes.
- The README offered Windows and macOS downloads that did not exist.
- The BSD install commands were mangled into one line, and the BSD jobs died at
  startup on a missing tokenizer.

## v0.0.4.23 — the control commands work, and the release pipeline produces a release

2026-09-23

### Fixed

- **The server ignored SIGTERM**, so `stop` and `restart` timed out instead of
  working.
- Header values could write headers of their own. Every response now goes
  through one serialiser; the guard had existed in one of seven places that
  wrote headers.
- WebSocket frames and worker packets were written without checking they went
  out whole, and the worker pool counted a short write of a request as success.
- A hostname ending in a newline or a hyphen passed validation before reaching
  certbot, a resolver and the log.
- Every release carried one frozen name, and a broken macOS binary blocked the
  other three platforms.

### Added

- A pre-warm cache that survives a restart, taking startup from 4.7s to 0.6s.

## v0.0.4.20 — the cache-filling request is served what was stored

2026-09-23

### Fixed

- The request that filled the cache was served different bytes from every
  request after it. The caller now gets the response back as it was stored.

## v0.0.4.19 and earlier

See the [release history](https://github.com/se7enxweb/exponential-velocity/releases)
and `git log`. Entries before this file existed were not written up.

**`v0.0.4.21`, `v0.0.4.22` and `v0.0.4.26` are tags with no release.** Their
builds did not produce a usable artifact, and the gaps are left in place rather
than backfilled — a version number that never shipped anything is more honest
as a hole than as a release with nothing behind it.

They are not, however, absent. Packagist read each of those tags and they are
installable versions of this package; there is simply no release page and no
binary. If you have pinned one, move to the next version above it. They are
left alone rather than deleted because withdrawing a published version breaks
anything that already resolved it, and because re-cutting a tag is the one
repair that makes things worse.
