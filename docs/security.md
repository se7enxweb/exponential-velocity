# Security

How this server decides what it is willing to serve and run, what it refuses,
and what it deliberately still permits. Written to be read by anyone
maintaining this code or a fork of it.

Each section states the rule, the reason, and how to check the rule is still in
force — because most of these exist to prevent something that leaves no trace
when it goes wrong.

---

## Nothing outside the document root is served or run

### The rule

A request resolves to a file. If that file, after every symbolic link is
followed, does not lie under the document root, the request is refused with
403. This applies to static files and, more importantly, to PHP scripts.

`Q.webserver.followSymlinks` (default `false`) restores the older behaviour of
following links wherever they lead, for installations that deliberately serve
through links that leave the root.

### Why a request-string check is not enough

`Q_WebServer::pathEscapesRoot()` judges the decoded request path before
anything is opened. It refuses any `..`, any null byte, and their percent
encodings. That is the right check to make first, and it is deliberately
blunt — it refuses `notes..txt` too, because reasoning about how many ways a
segment can be spelled is how these are got wrong.

It cannot see a symbolic link, and no check on the request string can.
`/report.php` is an ordinary path whether that name leads to a file or to
somewhere else entirely on the disk. The request carries no evidence either
way.

Before this was fixed:

- a link inside the root with any served extension returned whatever it pointed
  at, and
- **a link named `*.php` was executed.**

The second is the one that matters. It converts *the ability to create one file
inside the document root* into *the ability to run code from anywhere the
server user can read*. Upload directories, shared asset trees and dependency
installers all create links in the ordinary course of their work, so this does
not require anybody to have been careless in an unusual way.

### Where the check lives

`Q_WebServer::insideRoot()` resolves both sides with `realpath()` and requires
a directory separator after the root, so `/srv/www-old` beside `/srv/www` is
outside rather than a prefix match away from inside.

It is called at the four places that act on a resolved path:

| Where | What it protects |
|---|---|
| `serveStaticFile()` | HTTP/1 static files |
| `http2Route()` | HTTP/2 static files |
| `resolveScript()` | the HTTP/2 script route |
| `handlePhp()` | **every HTTP/1 route that ends in running a file** |

The last one is the important entry in that table, and it is worth saying why.
Fixing the first three was not enough: with a worker pool configured, the
dispatch reaches `handlePhp()` by a different road, and a linked `*.php` was
still executed after the other three were closed. A fix verified by reading the
code would have been reported as complete at that point. That is why
`tests/unit-symlink-containment.php` starts a real server and asks it over a
socket rather than calling a function.

### Checking it

```bash
php tests/unit-symlink-containment.php
```

Thirteen cases. It builds a document root containing a link that stays inside
it and two that leave it — one `.txt`, one `.php` — and requires that the first
still works and the other two are refused, in both content and status.

A link that stays inside the root must keep working. Serving a theme directory
or a shared asset tree through a link is ordinary, and a fix that forbade every
link would break real installations for no gain.

---

## Contradictory request framing is refused, not resolved

A request carrying both `Content-Length` and `Transfer-Encoding`, or two
different `Content-Length` values, is answered `400`.

RFC 9112 §6.1 requires this, and the reason is request smuggling. A front-end
and a back-end that break the tie differently can be made to see different
request boundaries in the same byte stream, so one visitor's request is read as
the tail of another's. *Choosing a winner is what makes that possible.*
Refusing is what prevents it.

Bare LF line endings are refused for the same reason: a proxy that accepts only
CRLF and a server that accepts both do not agree about where a header stops.

Refusing them first required being able to see them. `requestComplete()` and
the read loop recognised only `\r\n\r\n`, so a request using bare LF never
completed at all — the connection held its slot until the read timeout, from
one short write that needs no browser or proxy to produce.

```bash
php tests/unit-malformed-requests.php
```

Twenty-one cases over a socket, because curl will not send most of them.

---

## HTTP/2 frames the RFC fixes exactly

HTTP/2 gives a peer more to work with than HTTP/1: every frame carries a length
and a stream id it chose, and the connection holds state across all of them.
These are the checks RFC 9113 words as MUST, all enforced in `handle()` before
the type switch:

| §  | Frame | Answer |
|---|---|---|
| 4.2 | larger than the advertised `SETTINGS_MAX_FRAME_SIZE` | `FRAME_SIZE_ERROR` |
| 6.1 | `DATA` on stream 0 | `PROTOCOL_ERROR` |
| 6.2 | `HEADERS` on stream 0 | `PROTOCOL_ERROR` |
| 5.1.1 | an even stream id from a client | `PROTOCOL_ERROR` |
| 6.5 | `SETTINGS` length not a multiple of 6 | `FRAME_SIZE_ERROR` |
| 6.5 | `SETTINGS` with `ACK` and a payload | `FRAME_SIZE_ERROR` |
| 6.7 | `PING` that is not 8 octets | `FRAME_SIZE_ERROR` |
| 6.9 | `WINDOW_UPDATE` of 0 | `PROTOCOL_ERROR` |
| 6.4 | `RST_STREAM` that is not 4 octets | `FRAME_SIZE_ERROR` |
| 6.8 | `GOAWAY` shorter than 8 octets | `FRAME_SIZE_ERROR` |

The first has the most direct consequence: accepting a frame past the size we
advertised means buffering whatever a peer felt like sending, on a connection
it opened, before any of it has been authorised.

**Unknown frame types are still accepted and discarded**, as §4.1 requires.
That is how the protocol is extended, and a peer that rejects what it does not
recognise cannot be extended. The test asserts this too, so the checks above
cannot quietly grow into it.

```bash
php tests/unit-http2-protocol-errors.php
```

---

## A header value cannot write headers of its own

A CR or LF inside a header value ends the line early; what follows is read as a
further header, or after a blank line as a second response. Values reach the
serialiser from application output and from stored cache entries, so this is
not a theoretical source.

Every response goes through `Q_WebServer::headerLines()`, which drops a header
whose name or value contains CR or LF. Dropping is per header, so one poisoned
value does not blank the rest of the response, and the header is dropped
*whole* — truncating would keep an attacker-chosen prefix, and escaping would
invent a value the caller never asked to send.

The guard previously existed in one of seven places that wrote headers; the
other six concatenated by hand. `tests/unit-header-injection.php` therefore
checks the filter **and reads the source tree**, so a newly hand-written header
loop fails the suite rather than quietly reopening the hole.

## Headers on every response

A front end such as Apache (`Header always set`) or nginx (`add_header ...
always`) usually puts a few security headers on every answer. An application
sets them on its own pages, but a static file never reaches the application,
so behind this server alone a stylesheet or an image went out without them,
and there was no Strict-Transport-Security at all unless a domain record in
the panel store asked for it.

```json
{ "Q": { "webserver": {
    "headers": {
        "X-Content-Type-Options": "nosniff",
        "X-Frame-Options": "SAMEORIGIN",
        "Referrer-Policy": "strict-origin-when-cross-origin",
        "Permissions-Policy": "camera=(), microphone=(), payment=(), usb=()"
    },
    "headersOnScripts": true,
    "hsts": { "maxAge": 300 }
} } }
```

- `headers` go on every response the server builds itself: static files over
  HTTP/1.1 and HTTP/2 (the in-memory copy included), 304s, image variants, its
  own error pages and redirects.
- `headersOnScripts` adds them to a script's response too, each only where the
  script did not send that header: an application's own `X-Frame-Options:
  DENY` stays `DENY`.
- `hsts` puts `Strict-Transport-Security` on every response sent over TLS,
  script responses included, and never on plain HTTP (RFC 6797 section 7.2). A
  domain with an HSTS record of its own gets that record's value. Mind the
  max-age: a browser that has seen it refuses plain HTTP to the host name, on
  every port, for that long.

Nothing is ever sent twice or replaced: a header already present, in any
letter case, is left alone. Names that are not HTTP tokens, values with CR, LF
or NUL, and the framing headers the server works out itself (`Content-Length`,
`Content-Type`, `Connection`, `Transfer-Encoding`, `Date` ...) are ignored, so
a typo in the configuration cannot write a header of its own into every
response. `tests/unit-response-headers.php` checks each kind of response over
HTTP/1.1, HTTP/1.1 with TLS and HTTP/2.

### SVG files

An SVG is a document a browser runs script from when it is opened directly
(its URL in the address bar, an `<object>` or an `<iframe>`), from the origin
it was served from. Every `.svg` and `.svgz` file the server sends as a static
file therefore goes out with

```
Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'
X-Content-Type-Options: nosniff
```

so it runs nothing and loads nothing; shown with `<img>` or from a style sheet
it renders as before. The headers are added over HTTP/1.1 and HTTP/2, in the
in-memory copy and for an `X-Accel-Redirect` answer; `Q.webserver.headers`
naming one of them does not send it twice. This is a second line of defence
for applications that store uploaded SVG images (Exponential sanitizes them on
upload). `Q.webserver.svgSandbox: false` turns it off.
`tests/unit-svg-sandbox-headers.php` covers it.

---

## The control panel's password

The panel is the page that changes the server, so its password is held to strict
rules (16+ characters, four character classes, no runs or repeated letters, common passwords or
the server's own names, 80+ bits), stored with bcrypt, rehashed when the cost
changes, and guarded by a lockout that doubles up to an hour. A server with no
password yet signs in only with the default key, and only to change it. Everything
about it is in [passwords.md](passwords.md).

Where the password and the sessions are kept is guarded too. Every directory from
them up to `/` must belong to root or the server's user and be writable by no one
else, the directories and files themselves the server's alone (`0700`/`0600`), and no
symbolic link on the way. A store some other user could rename and replace with a
password of their own is never believed: the panel then refuses every sign-in, the
default key and every session, and says why at start and in `qbixctl panel:check`.
See [dashboard.md](dashboard.md#where-the-panel-keeps-its-credentials).

## Notes for anyone maintaining a fork

Two of the above were found by running the server rather than reading it, and
would not have been found by reading:

- The symlink execution path, because three of the four dispatch points can be
  closed while the fourth still runs the file.
- The framing faults, because `curl` refuses to send the requests that expose
  them.

If you maintain a fork of this server, the containment check is the one to
carry across. It is a single function and four call sites, and its absence is
invisible until somebody creates a link.

Upstream `Qbix/webserver` (`main`, read 2026-09-23) has no equivalent: no
resolved-path comparison is made for the purpose of refusing a request. Its two
`strncmp()` comparisons against the document root exist to compute
`SCRIPT_NAME`, and fall back to the request path when they do not match rather
than refusing. That was read, not run, so it is stated as a structural absence
rather than a demonstrated exploit — but the dispatch has the same shape as the
one proved exploitable here.

---

## Reporting something

If you find a problem of this kind, please report it privately to the
maintainers rather than opening a public issue, and allow time for a fix to be
released before describing it publicly.
