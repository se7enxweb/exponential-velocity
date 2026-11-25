# Host fallback

When Velocity cannot serve a host — its PHP answers 5xx in a storm, or a tool has
flagged it (a start that failed, a rule Velocity cannot do) — that host goes back to
the web server that served it before, visitors who still reach Velocity get a page
that says why, and the server reports it. Other hosts are not affected.

```json
"Q": { "webserver": { "hostFallback": {
    "upstream": "127.0.0.1:7080",
    "errors": 20, "window": 60, "hold": 300,
    "stateDir": "/var/lib/vc/fallback",
    "report": "/usr/local/sbin/vcc fallback report"
},
"hosts": { "shop.example": { "hostFallback": { "upstream": "unix:/run/apache-shop.sock" } } } } }
```

| Key | Meaning |
|---|---|
| `upstream` | the previous server (Apache behind Plesk's nginx: `127.0.0.1:7080`; a Unix socket: `unix:/path`). Empty: no relay, the explaining page. |
| `errors`, `window` | a 5xx storm: this many 5xx answers of one host within this many seconds |
| `hold` | how long a storm keeps the host away (then it is served again and counted afresh) |
| `stateDir` | a file `<host>` there sends the host back until it is removed; its text is the cause shown. Reports are written there as `<host>.json`. |
| `report` | run once per episode, detached, with `VC_FALLBACK_HOST`, `VC_FALLBACK_CAUSE`, `VC_FALLBACK_REPORT` (the JSON: host, cause, time, node, until, the last lines of the error log) |

Per host, `Q.webserver.hosts.<host>.hostFallback` overrides any key.
(`Q.webserver.fallback` is something else: the page for a path nothing matched.)

## What a visitor gets

- **HTTP/1.1** (nginx or Plesk's nginx in front, or a client speaking 1.1): the
  request is relayed to `upstream` exactly as a `Q.webserver.upstreams` host is
  ([multiuser.md](multiuser.md)): the answer streams back byte for byte.
- **HTTP/2 directly to Velocity**, or no `upstream`: 503 with `Retry-After: 120`,
  `Cache-Control: no-store`, `X-Vc-Fallback: unavailable`, and a short page naming
  the host, the cause, and what an administrator does.

The page answered in fallback is not counted as a new error, so a host comes back
when its hold time ends or its flag is removed.

## Behind nginx

`vcc nginx site ... --fallback=127.0.0.1:7080` writes the previous server as a
`backup` server of the site's upstream: when Velocity is down (connection refused,
502/503/504), nginx itself asks the previous server. That covers a Velocity that is
not running at all, which no setting inside Velocity can.

## Reports

`vcc fallback report` (the `report` command above) posts the report to gnubus as
an event, labels `kind=vc-fallback` and `host=<host>`. Only root signs for a node
on gnubus: a server running as an unprivileged user leaves the report in
`stateDir`, and `vcc fallback report --pending` (run by `vcc-check.timer` every
30 seconds) sends it. `vcc fallback status` lists every flagged and reported host;
`vcc fallback set <host> <cause>` and `vcc fallback clear <host>` flag and clear by
hand.

## Tests

`php tests/unit-host-fallback.php` (storm, window, flag, page, report command,
per-host settings). Live: vcc's `tests/reference.php fallback` sends a host back to
Apache and returns it, on a loopback reference target.
