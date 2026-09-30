## uwebserver

`uwebserver` is the small web server in C that comes with Exponential Velocity.
It is not the application server: Velocity itself is `sbin/qbixserver.php` (and
the phar), which runs PHP. uwebserver answers HTTP/1.1 and HTTPS from one event
loop per process, with no PHP at all, and is there for two needs: a baseline to
measure the PHP server against (its built-in answers), and a plain static file
server (`--root`) for tests and small jobs.

```bash
make -C native/uwebserver                  # builds sbin/uwebserver
sbin/uwebserver --root=./web               # http://127.0.0.1:8000/
sbin/uwebserver --help                     # every option
man docs/uwebserver.1                      # the manual page
```

- [Where it is and who uses it](#where-it-is-and-who-uses-it)
- [Building](#building)
- [The command line](#the-command-line)
- [Listening](#listening)
- [Content](#content)
- [Limits and timeouts](#limits-and-timeouts)
- [TLS](#tls)
- [Logging](#logging)
- [Processes and privileges](#processes-and-privileges)
- [The configuration file](#the-configuration-file)
- [Environment, signals and exit status](#environment-signals-and-exit-status)
- [Security](#security)
- [Changes from 0.0.4.41](#changes-from-00441)
- [What it was in 0.0.4.41](#what-it-was-in-00441)

---

### Where it is and who uses it

| Path | What |
|---|---|
| `native/uwebserver/uwebserver.c` | the program: configuration, listeners, the event loop, files, processes |
| `native/uwebserver/u_options.h` | the command line parser (GNU and BSD spellings) |
| `native/uwebserver/u_http.h` | the request head parser and the path normaliser: pure functions, fuzzed on their own |
| `native/uwebserver/u_mime.h` | media types: a built-in table and the `mime.types` reader |
| `native/uwebserver/u_log.h` | the error and access logs, and the escaping of what a client sent |
| `native/uwebserver/u_tls.h` | TLS through OpenSSL 3, without blocking |
| `native/uwebserver/u_sendfile.h` | one non-blocking step of `sendfile(2)` |
| `native/uwebserver/Makefile` | the build, with the release and hardening flags; `install` |
| `native/uwebserver/uwebserver.bash-completion` | bash completion |
| `docs/uwebserver.1` | the manual page |
| `native/uwebserver/u_runtime.h`, `u_merkle_cache.h` | no longer compiled (see [What it was](#what-it-was-in-00441)) |
| `sbin/uwebserver` | the built program. Not committed (`.gitignore`): it is built on the machine that runs it |
| `bin/uwebserver` | a forwarder at the former path that `exec`s `sbin/uwebserver` ([layout.md](layout.md#programs-bin-and-sbin)) |

Nothing in the engine starts it. `src/` never names it, the packages and the
container image leave the program out on purpose (`tests/unit-nfpm-render.php`
checks that), the release workflows do not build it, and the Exponential
installation (`kernel/classes/expvelocity.php`, `settings/velocity.ini`) does
not know it. Its callers are people and tests: `tests/pipelining.php`, the
`tests/unit-uwebserver-*.php` tests, and `tests/unit-moved-programs.php`, which
compares `bin/uwebserver` with `sbin/uwebserver`.

### Building

```bash
make -C native/uwebserver                         # sbin/uwebserver, the release flags
make -C native/uwebserver install PREFIX=/usr/local
make -C native/uwebserver UWEB_VERSION=v0.0.4.42  # a tree without git
```

It is one translation unit and needs a C compiler and OpenSSL 3 (`libssl-dev`
or `openssl-devel`). The one-line build still works for a quick try:
`cc -O2 -o uweb native/uwebserver/uwebserver.c -lssl -lcrypto`. The release
and build it reports in `--version` come from `git describe` and
`git rev-parse` through `UWEB_VERSION` and `UWEB_BUILD`. The flags the
Makefile adds for safety are listed under [Security](#security).

### The command line

GNU style (the GNU Coding Standards, "Command-Line Interfaces"), with the
one-dash spellings every program of the server accepts:

| Form | Example |
|---|---|
| `--name=VALUE`, `--name VALUE` | `--root=/srv/www`, `--root /srv/www` |
| `-name=VALUE`, `-name VALUE` | `-root=/srv/www`, `-port 8081` |
| a short option, bundled | `-p8081`, `-p 8081`, `-qd` |
| a switch, and its `--no-` form | `--etag`, `--no-etag` |
| `--` ends the options | `uwebserver -- ...` (anything after is an argument) |

The whole command line is read before anything is done. An unknown option, a
missing or malformed value, contradicting options or a word it does not expect
is a usage error: the message on standard error, named `uwebserver:`, then
`Try 'uwebserver --help' for more information.`, and exit status 2. Nothing is
started. Long names match exactly, never as abbreviations, so adding an option
can never change what an existing command line means; and a value is never
taken from a following word that is itself an option (`--root --help` is an
error, not a root named `--help`).

`--help` (`-h`) prints the usage, grouped as below, and `--version` (`-V`; also
`-v`, `-version`, `--copyright`, `-copyright`) the version, both on standard
output with exit 0. `--about` prints the version with the build details.

The one former spelling that is deprecated: a port given as a bare number
(`uwebserver 8081`) still works and says on standard error to use `--port`.

### Listening

| Option | Default | |
|---|---|---|
| `-l`, `--listen=[ADDR:]PORT` | | HTTP there; repeatable |
| `-b`, `--bind=ADDR` | `127.0.0.1` | the address of `--port` and `--tls-port` |
| `-p`, `--port=PORT` | `8000` | HTTP on ADDR:PORT |
| `--tls-listen=[ADDR:]PORT` | | HTTPS there; repeatable; needs `--cert` and `--key` |
| `--tls-port=PORT` | `8443` | HTTPS on ADDR:PORT |
| `--reuse-port` | off | let other servers share the ports (`SO_REUSEPORT`) |
| `--backlog=N` | `511` | connections waiting to be accepted |

`ADDR` is a numeric IPv4 address, an IPv6 address in brackets (`[::1]`, `[::]`;
an IPv6 listener takes IPv6 only), `*` for every IPv4 address, or `localhost`
(127.0.0.1). Host names are not looked up: what a server listens on must not
depend on a resolver.

Which listeners there are: every `--listen` and `--tls-listen`; HTTP on
`--bind`:`--port` when either of them is given or when no `--listen` and no
`--tls-listen` is; HTTPS on `--bind`:`--tls-port` when `--tls-port` is given, or
when `--cert` is and no `--tls-listen`. So `uwebserver` alone listens on
`127.0.0.1:8000`, `--listen='*:80'` on every IPv4 address at port 80 only, and
`--cert=... --key=...` adds `127.0.0.1:8443`. `--check` prints the list without
binding anything.

**Why 127.0.0.1:8000.** A server that is started to try something, or by
mistake, should not be reachable from the network, and should not take a port
another server of this machine is known to use: 8080 is the application
server's (the live Velocity listens there), and until 0.0.4.42 uwebserver took
`0.0.0.0:8080` for any argument it did not know. Every interface is one
explicit `--listen='*:PORT'` away.

**Why no `SO_REUSEPORT` by default.** With it, a second server of the same user
binds a port that is in use and the kernel shares the connections between the
two; a mistaken start would silently take half the traffic of a running
server. Without it, the second start fails with "Address already in use", which
is what one wants. `--reuse-port` is there for running several uwebservers on
one port on purpose; `--workers` does not need it.

### Content

Without `--root`, the built-in answers of the benchmark: `/` is
`Hello from U!`, `/json` a small JSON object, `/health` the counters
(`requests`, `uptime`, `connections`, and `cache_hits`, `cache_misses` and the
`merkle_*` counters, which are 0 since the response cache went, kept so a
reader of the former JSON still finds its keys), anything else 404.

With `--root=DIR`, the files under DIR:

| Option | Default | |
|---|---|---|
| `-r`, `--root=DIR` | | the document root |
| `--index=NAMES` | `index.html` | the files that answer for a directory, by commas |
| `--directory-listing` | off | list a directory that has no index file (else 403) |
| `--hidden-files` | off | serve names that start with a dot (else 404) |
| `--symlinks=inside\|never` | `inside` | follow links that stay inside DIR, or none |
| `--mime-types=FILE` | | more types, `mime.types` format; they win |
| `--default-type=TYPE` | `application/octet-stream` | for unknown extensions |
| `--charset=NAME` | `utf-8` | added to text types; empty: none |
| `--cache-control=VALUE` | none | the `Cache-Control` of files |
| `--etag` | on | `ETag`, `If-None-Match`, `If-Range` |
| `--gzip-static` | off | `FILE.gz` to clients that accept gzip, `Vary: Accept-Encoding` |
| `-H`, `--header='NAME: VALUE'` | | on every response; repeatable |
| `--server-name=NAME` | `uwebserver` | the `Server` header, never with a version |
| `--server-header` | on | send a `Server` header at all |

What a request gets: GET and HEAD; `Last-Modified` and, with `--etag`, an
`ETag` from the modification time and size; `304` for a matching
`If-None-Match` (weak comparison, lists and `*`) or, without one, an
`If-Modified-Since` at or after the file's time; one byte range (`bytes=a-b`,
`a-`, `-n`) as `206` with `Content-Range`, `416` when it cannot be satisfied,
the whole file for several ranges or another unit; `If-Range` with the ETag or
the date. A directory without its trailing slash is redirected to it (`301`,
the query kept, always a path on this server); with its slash, the first index
file that exists, else the listing or `403`. `X-Content-Type-Options: nosniff`
on every file and error. Other methods: `405` with `Allow: GET, HEAD` (`501`
for a method it does not know); a body with `Transfer-Encoding` is not read
(`501`).

What a path may not do, whatever the encoding: contain a `..` segment
(`400`), an encoded `/` (`%2F`) or NUL, or a control character (`400`); name a
file whose name starts with a dot (`404`, unless `--hidden-files`); reach
anything outside the root. Files are opened with `openat2(2)` and
`RESOLVE_BENEATH` relative to the root, so the kernel itself refuses a path, a
link or a race that leads out; a link with an absolute target is resolved from
`/` and therefore refused too, so links inside the tree must be relative.
Without `openat2` (a kernel before 5.6) every component is opened with
`O_NOFOLLOW`, following no link at all. Only regular files are sent: a FIFO, a
device or a socket in the tree is `403`, and opening one never waits.

### Limits and timeouts

| Option | Default | When it is reached |
|---|---|---|
| `-w`, `--workers=N` | `1` (at most 256) | worker processes; one that ends is replaced |
| `--max-connections=N` | `1024` | per worker; more are closed at once |
| `--max-requests=N` | `1000` (0: no limit) | the last response says `Connection: close` |
| `--max-header-size=SIZE` | `8k` (1k to 1m) | `431` |
| `--max-uri-length=SIZE` | `4k` | `414` |
| `--max-body-size=SIZE` | `1m` | `413`; a smaller body is read and discarded |
| `--header-timeout=SECONDS` | `10` | the whole head must arrive in this time from its first byte |
| `--read-timeout=SECONDS` | `30` | between reads of a body |
| `--write-timeout=SECONDS` | `30` | without progress sending |
| `--keepalive-timeout=SECONDS` | `5` (0: no keep-alive) | idle between requests |
| `--tls-handshake-timeout=SECONDS` | `10` | a TLS handshake |

A SIZE may end in `k`, `m` or `g` (powers of 1024). A connection that reaches a
timeout is closed. The server raises its descriptor limit to what
`--max-connections` needs, as far as the hard limit allows, and says so when it
cannot.

### TLS

| Option | Default | |
|---|---|---|
| `--cert=FILE` | | the certificate, PEM; it may hold the chain after it |
| `--key=FILE` | | its private key, PEM |
| `--chain=FILE` | | more chain certificates, PEM |
| `--tls-min-version=1.2\|1.3` | `1.2` | the oldest version accepted |
| `--tls-ciphers=LIST` | ECDHE with AES-GCM or ChaCha20-Poly1305 | TLS 1.2, an OpenSSL list |
| `--tls-ciphersuites=LIST` | OpenSSL's | TLS 1.3 |

The key is refused when a user other than its owner may change it, or anyone
but its owner and group may read it; a group that may read it gets a warning.
The key must be the certificate's. Compression and renegotiation are off; ALPN
selects `http/1.1`, the only protocol uwebserver speaks. Handshakes, reads and
writes never block the loop, and a handshake that does not finish in
`--tls-handshake-timeout` is dropped.

### Logging

| Option | Default | |
|---|---|---|
| `--access-log=FILE\|-\|off` | `off` | one line per request; `-` is standard output |
| `--access-log-format=FORMAT` | `combined` | `common`, `combined` or `json` |
| `--error-log=FILE\|-` | `-` (standard error) | start, stop, warnings, errors |
| `-q`, `--quiet` | | errors only |
| `--verbose` | | also timeouts, malformed requests, refused connections |

The `common` and `combined` lines are Apache's formats (`%h - - [%t] "%r" %s %b`,
and `"%{Referer}i" "%{User-Agent}i"`); `json` has `time`, `remote`, `method`,
`target`, `protocol`, `status`, `bytes`, `referer`, `user_agent`, `duration_ms`
and `tls`. Every byte a client sent that is not printable ASCII, and `"` and
`\`, is written `\xHH` (and those again escaped for JSON), so a request can
never add a line or a field to a log. Each line is one `write(2)` to a file
opened with `O_APPEND`, so lines of several workers never mix. Log files are
created with mode 0640, never through a symbolic link, and must be regular
files. An error log line reads
`2026-09-30T12:00:00Z uwebserver[1234]: notice: serving /srv/www on http://127.0.0.1:8000`.

### Processes and privileges

| Option | |
|---|---|
| `-d`, `--daemon` / `-f`, `--foreground` | the background, or the foreground (the default) |
| `--pid-file=FILE` | the process id, with a lock held while running |
| `-u`, `--user=USER` | the user to run as once the ports are open (root only) |
| `-g`, `--group=GROUP` | the group (default: the user's); supplementary groups are dropped |
| `--chroot=DIR` | the root directory to change to before dropping to the user (root only) |
| `--allow-root` | serve as root without `--user` |

**As root it serves only with `--user` or `--allow-root`.** The start, in
order: read and check the whole configuration; open the error log; raise the
descriptor limit; resolve the user and group; open the document root, the
media types, the certificate and key (checking the key's mode) and the access
log; bind the ports (a port below 1024 needs root); detach (`--daemon`); write
and lock the pid file; change the root directory (`--chroot`); drop the
supplementary groups, then the group, then the user (`setresgid`,
`setresuid`), and check that root cannot be had back; then serve. After the
drop the process is not dumpable, so no core file holds the key. `--user=root`
is refused. `--daemon` returns only once the server serves, with 0, or with 1
and the reason in the error log when it could not start. A second server with
the same `--pid-file` does not start: the file is locked, and a stale file
left by a crash holds no lock.

`--workers=N` forks N processes that share the ports (`EPOLLEXCLUSIVE`, one of
them woken per connection). The parent replaces a worker that ends, and stops
the server when workers keep ending (more than 2N+5 in ten seconds), so a
worker that crashes at once cannot become a fork loop. Workers end with the
parent (`PR_SET_PDEATHSIG`).

### The configuration file

`--config=FILE` (or `-c FILE`, or `UWEBSERVER_CONFIG`) reads one setting per
line: the long name of an option without its dashes, then its value, as
`name = value` or `name value`; a switch alone, as `name = yes|no` (also
`true/false`, `on/off`, `1/0`), or as `no-name`. A line whose first non-blank
character is `#` is a comment, and a value may be quoted to keep blanks at its
ends. The repeatable settings (`listen`, `tls-listen`, `header`) are given once
per value.

```
# /etc/uwebserver.conf
root = /srv/www
listen = 127.0.0.1:8000
listen = [::1]:8000
user = www
access-log = /var/log/uwebserver/access.log
gzip-static
header = X-Frame-Options: DENY
```

The file is read first and the command line second, so an option overrides the
file; a repeatable option given on the command line replaces the file's values.
`help`, `version`, `config`, `check` and `print-config` are not settings. An
unknown setting or a bad value stops it with exit status 2, naming the file and
the line (`uwebserver: /etc/uwebserver.conf:4: invalid port 'x' for 'port'`).

`--print-config` prints every setting in effect in this format, and what it
prints reads back to the same settings (`bind`, `port` and `tls-port` are
printed as comments unless they were given, because giving them adds a
listener). `--check` (`-t`, `--test-config`, like `nginx -t`) checks the
configuration and everything the start needs (the root, the media types, the
certificate and key, the logs, the pid file's directory, the user and group),
prints the addresses it would serve on, and binds nothing.

### Environment, signals and exit status

`UWEBSERVER_CONFIG` names the configuration file when `--config` is not given.
No other variable changes what the server does: an inherited environment must
not change what a server exposes.

`SIGTERM`, `SIGINT` and `SIGHUP` stop it: the ports close, idle connections
close, those in the middle of a request finish it (for at most ten seconds),
and it exits 0. With `--workers` the parent stops the workers so.

| Exit status | |
|---|---|
| 0 | success; with `--check`, the configuration is valid |
| 1 | the server could not start (a port in use, a file it cannot open, a key that does not match, root without `--user`), stopped on an error, or `--check` found a problem |
| 2 | a usage error: an unknown option, a missing or bad value, contradicting options, a bad configuration file. Nothing was started |

### Security

uwebserver was reviewed for 0.0.4.42 in four waves, each a review, a list of
findings with a severity, the fixes, and a test that proves each fix:

1. [memory safety and parsing](#wave-1-memory-safety-and-parsing)
2. [resource exhaustion and denial of service](#wave-2-resource-exhaustion-and-denial-of-service)
3. [privileges and TLS](#wave-3-privileges-and-tls)
4. [hardening and fuzzing](#wave-4-hardening-and-fuzzing)

Severity: **high**, a remote client can make the server do what it must not
(serve what it should not, read a stream wrongly, stop serving others);
**medium**, it takes an unusual set-up or only degrades the service;
**low**, a defect with no effect on others' data or availability; **info**, a
change of default or of hygiene. "0.0.4.41" marks what the released program
did; "new" marks what the review found in the code written for 0.0.4.42 before
it was released.

The sanitizer run: `make -C native/uwebserver sanitize` builds with
AddressSanitizer and UndefinedBehaviorSanitizer (`-fsanitize=address,undefined
-fno-sanitize-recover=all`), and every `tests/unit-uwebserver-*.php` test runs
against such a build when `UWEB_TEST_CFLAGS` carries those flags (the
sanitizers need their runtimes, `libasan` and `libubsan`). Any report stops the
process and fails the test.

#### Wave 1: memory safety and parsing

| # | Severity | Finding | Fix | Test |
|---|---|---|---|---|
| 1.1 | high, 0.0.4.41 | Any argument other than the about flags started the server, `--help` and typing errors included, on every interface at port 8080 | the whole command line is read first; errors exit 2 and start nothing | `unit-uwebserver-cli.php` |
| 1.2 | high, 0.0.4.41 | A request head that filled the 8 KiB buffer without its end was dropped and reading went on, so the rest of it was read as the start of new requests | 431 and the connection closed | `unit-uwebserver-parser.php`, "a head that fills the buffer" |
| 1.3 | medium, 0.0.4.41 | The request was barely parsed: the path was whatever lay between the first two spaces, nothing was validated, and `Connection: close` was found by a case-sensitive search anywhere in the head, so a header value holding it closed the connection and `connection: close` did not | a strict head parser (`u_http.h`, RFC 9112): CRLF only, no folding, token names, no control characters, one Host, Content-Length digits that agree, never with Transfer-Encoding, the version checked | `unit-uwebserver-parser.php` (46 malformed and boundary heads) |
| 1.4 | medium, 0.0.4.41 | The connection table took descriptor 0 as "free": a connection accepted on descriptor 0 (standard input closed by a supervisor) was never closed | a table of pointers, NULL for free | "with standard input closed" |
| 1.5 | low, 0.0.4.41 | A certificate's DNS names were read with `strlen` from ASN.1 data, which need not end in NUL | `ASN1_STRING_length`, bounds on every copy | `unit-uwebserver-tls.php`, the start-up line |
| 1.6 | low, 0.0.4.41 | The response cache cut keys at 127 bytes and kept whole responses, `Date` and `Connection` included, so a cached `keep-alive` went to a request that asked for `close` | the cache is gone | `unit-uwebserver-static.php`, HTTP/1.0 and keep-alive; `pipelining.php` |
| 1.7 | high, new | a path is the one thing a client controls that reaches the file system | percent-decoding, then `..`, `%2F`, NUL and control characters refused, dot names hidden, and the file opened beneath the root with `openat2(RESOLVE_BENEATH)`, so no path, link or race leads out | `unit-uwebserver-static.php` (16 paths that must not be served, links out, a FIFO) |
| 1.8 | low, new | The configuration reader used `fgets`, which cannot see a NUL inside a line | `getline`, whose length is the true one | "a NUL byte in the configuration file" |
| 1.9 | low, new | A CR that ended a write, before the request line, was answered 400 instead of waiting for its LF | wait for it | "a request a byte at a time" |
| 1.10 | low, new | A JSON access log line with long escaped fields was cut at 4 KiB into invalid JSON | a buffer sized for the longest line | "every JSON log line parses" |
| 1.11 | low, new | The `Location` of a directory redirect carried query bytes above 0x7e as they came | encoded | "a directory redirect keeps the query" |
| 1.12 | info, new | `/.well-known/` counted as hidden, which breaks ACME and `security.txt` (RFC 8615) | served at the top of the root; dot names inside it are still hidden | "/.well-known/ is served" |
| 1.13 | info, 0.0.4.41 | 58 warnings with `-Wall -Wextra -Wformat=2`, from the generated code and unused functions | none, with a stricter set (`-Wformat=2 -Wformat-signedness -Wshadow -Wnull-dereference -Wstrict-prototypes -Wpointer-arith -Wcast-align -Wwrite-strings -Wundef -Wvla -Wimplicit-fallthrough -Wduplicated-cond -Wlogical-op`) and `-Werror` in the Makefile | `unit-uwebserver-docs.php` builds with the Makefile |
| 1.14 | info, new | GCC 14 saw a possible NULL dereference (`-Wnull-dereference`): the option looked up for a bare port was used unchecked | checked | the Makefile build with GCC 14 under `-Werror` |
| 1.15 | info | ASan, UBSan and LeakSanitizer over every uwebserver test (everything the process holds is freed at a clean exit, so a leak is visible) | no report | the sanitizer run above |

#### Wave 2: resource exhaustion and denial of service

| # | Severity | Finding | Fix | Test |
|---|---|---|---|---|
| 2.1 | high, 0.0.4.41 | The TLS handshake ran blocking inside the accept loop: one client that opened the TLS port and sent nothing stopped the whole server, both ports, for as long as it liked | handshakes without blocking, dropped after `--tls-handshake-timeout` | `unit-uwebserver-tls.php`, "a silent TLS client does not block others"; `unit-uwebserver-limits.php`, "half a TLS ClientHello" |
| 2.2 | high, 0.0.4.41 | No timeout of any kind: an idle connection, a head sent a byte now and then, a body that never came or a response never read held its slot for ever, so a few thousand of them filled the table (slowloris) | `--header-timeout` (from the head's first byte, not renewed by trickling), `--keepalive-timeout`, `--read-timeout`, `--write-timeout` (without progress) | `unit-uwebserver-limits.php`, five timing cases |
| 2.3 | medium, 0.0.4.41 | A response was written once; a short write or EAGAIN was ignored, so a slow reader got a truncated response on a connection still kept alive, out of step | a response buffer and a per-connection state machine that waits until the socket takes more; files by `sendfile(2)` a step at a time | `unit-uwebserver-static.php` (3 MiB), `unit-uwebserver-tls.php` (1 MiB over TLS), "a response never read" |
| 2.4 | medium, 0.0.4.41 | With every descriptor in use, `accept` fails with EMFILE while the listening socket stays readable: the loop spun at full CPU | the listener is paused for a second and a warning logged | "with its descriptors used up, it pauses accepting" |
| 2.5 | medium, 0.0.4.41 | The only bound on connections was the fixed table of 8 192 | `--max-connections` per worker (more are closed at once); the descriptor limit raised to what it needs | "--max-connections=6: a seventh connection is closed at once" |
| 2.6 | medium, new | `--workers`: a worker that dies at once would be forked again without end | at most 2N+5 deaths in ten seconds, then the parent stops, exit status 1 | "workers killed again and again: the parent stops" |
| 2.7 | low, new | The head was parsed again from its start on every read, which is quadratic in its size for a client that sends it in small pieces (measured small at 8 KiB, the default) | parsed only once an empty line has arrived or the buffer is full | "four 57 KiB heads sent 8 bytes at a time ... for little CPU" |
| 2.8 | low, new | The timeout sweep walked the whole descriptor table, up to a million entries, every second | only up to the highest descriptor in use | the limits test's timing |
| 2.9 | info | Memory per connection: the head buffer is `--max-header-size`; a generated answer (a listing) is at most 8 MiB; requests pipelined behind one being answered are not read until it is sent, so a client that never reads cannot make the server buffer its requests | as it is | "pipelined requests never read ... memory grew < 4 MiB" |
| 2.10 | low | A body is skipped up to `--max-body-size`; a larger one is `413`, the connection closed after a bounded linger (2 s, 256 KiB), so the answer arrives before the reset | as it is | "a body of --max-body-size is skipped", "one byte more: 413" |
| 2.11 | low, new | A file cut short while it is sent (`sendfile` returns 0 before the end) | the connection is closed: its Content-Length can no longer be kept, and it never waits for bytes that will not come | "a file cut short while it is sent" |
| 2.12 | info | Files over 4 GiB: lengths and ranges are 64-bit | as it is | "a file of 5 GiB", "a range past 4 GiB" |
| 2.13 | low | A directory listing is bounded: 20 000 names | as it is | "a directory of 20 050 names" |
| 2.14 | info | `--max-requests` per connection (1000) | as it is | "--max-requests=3" |
| 2.15 | info, 0.0.4.41 | `u_serve_file` (never called) spun on EAGAIN inside `sendfile` | replaced by one non-blocking step (`u_sendfile.h`) | the file tests above |

### Changes from 0.0.4.41

Callers of the 0.0.4.41 command line keep working, with these differences:

- **The default address is 127.0.0.1 and the default port 8000**, not
  `0.0.0.0:8080`. `--port=N` binds `127.0.0.1:N`; every interface is
  `--listen='*:N'`.
- **As root it serves only with `--user` or `--allow-root`.**
- **An unknown option, a bad value or `--help` never start a server.**
  `--help` prints the usage.
- `--cert` without `--key` (or the reverse) is an error rather than HTTP only.
- `SO_REUSEPORT` only with `--reuse-port`.
- The `Server` header is `uwebserver`, not `U/1.0`.
- The response cache is gone: it kept whole responses with their `Date` and
  `Connection` headers, so a cached `Connection: close` could be sent on a
  connection kept open.
- The start-up line is on standard error, as a log line, not on standard
  output.

### What it was in 0.0.4.41

This is the state the 0.0.4.42 work started from, kept here so the changes can
be read against it.

**The source.** The first thousand lines of `uwebserver.c` were generated by a
U-to-C translator (`u2c`) whose `.u` source is not in the repository: 14 record
types (`Database_Connection`, `Row`, `CookieJar`, ...) with copy, pack and
unpack functions that nothing calls, and three functions that are the whole
"application": `/` answers `Hello from U!`, `/json` a small JSON object,
`/health` `ok`, everything else 404. The server itself (about 400 lines) was
written by hand and appended. `u_runtime.h`, 6 650 lines, was compiled in for
the generated part only. With `-Wall -Wextra -Wformat=2` the file gave 58
warnings, nearly all unused parameters and functions of the generated code and
the headers.

**It served no files.** The version text said "static files over HTTP and
HTTPS", but the server only ever answered the three built-in paths.
`u_sendfile.h` had a complete file sender (`u_serve_file`) that was never
called.

**Command line.** Four options, `--port`, `--tls-port`, `--cert` and `--key`,
each in the spellings `--name=V`, `--name V`, `-name=V` and `-name V`, plus the
house flags `--version`, `-version`, `-v`, `-V`, `--about`, `-about`,
`--copyright` and `-copyright`, which print the program's about text and exit 0.
Everything else was ignored without a word, and any argument that did not start
with `-` was read as a port number (`atoi`, so `abc` was port 0). Above all,
**any argument that was not one of the about flags started the server** with its
defaults: `uwebserver --help`, `uwebserver -h` or a misspelt option bound
`0.0.0.0:8080` (all interfaces, the port the live Velocity uses) with
`SO_REUSEPORT`. There was no `--help`.

**Defaults.** HTTP on all IPv4 interfaces, port 8080, with `SO_REUSEPORT` (so a
second server of the same user on the same port shares its connections rather
than failing to bind); HTTPS on 8443 when `--cert` and `--key` were both given,
and silently no HTTPS when only one was. No IPv6. Always in the foreground, as
whichever user started it, including root.

**The loop.** One process, one thread, `epoll` in edge-triggered mode, a fixed
table of 8 192 connections indexed by descriptor with an 8 KiB buffer each.
Requests are read until `\r\n\r\n`; pipelined requests are answered in order
(the fix `tests/pipelining.php` guards). The request is barely parsed: the path
is whatever lies between the first two spaces, `Connection: close` is found by a
case-sensitive search anywhere in the head, and nothing else is looked at.
Responses carry `Server: U/1.0`. A response cache of 256 slots kept whole
responses for 60 seconds, including their `Date` and `Connection` headers.

**TLS.** OpenSSL 3, TLS 1.2 minimum, a server session cache. The handshake ran
blocking, inside the accept loop. `SSL_CTX_set_alpn_protos` (a client-side call)
was used on the server context, so no ALPN was ever selected.

**Build.** `gcc -O2 ... -o sbin/uwebserver native/uwebserver/uwebserver.c -lssl -lcrypto -lpthread -lm`,
by hand. The binary found in the checkout was made that way with GCC 11.5 (EL
9) at v0.0.4.30: a position-dependent executable (`EXEC`, not PIE), partial
RELRO, no immediate binding, no stack protector and no `_FORTIFY_SOURCE`.

**Tests.** `tests/pipelining.php` (six cases, against a server started by hand)
and the three about flags in `tests/unit-moved-programs.php`. None ran
uwebserver's own behaviour in the suite.

What 0.0.4.42 changed is in the [CHANGELOG](../CHANGELOG.md); the program as it
is now is described in the sections above.
