# Running without root

Velocity needs root for exactly three things: binding a port below 1024, reading a
certificate only root may read, and starting worker pools that belong to *other*
users. Everything else — the warm-up, every worker, the scheduled tasks, the logs —
runs as an ordinary user. This page is the security model, and how to run with as
little root as each setup allows.

## The three ways to run

| Setup | Server process | Workers | What root is left |
|---|---|---|---|
| Started as the user (systemd `User=`) | the user | the user | none |
| Started as root with `--drop-master` | root until the ports are bound and certificates read, then the user, for good | the user | none after start |
| Started as root, pools of several users (`Q.webserver.pools`) | root (only to switch each pool to its user) | each pool's own user | the master; bound it with `CapabilityBoundingSet` |

Workers never run as root unless asked twice (`--user=root` and
`--allow-root-workers`): every process that runs application code switches user
right after it is forked and before it runs anything, and checks that root cannot
be had back (`Q_WebServer_RunAs`, [multiuser.md](multiuser.md)).

### 1. Started as the user — the default to aim for

Ports above 1024 need nothing. For 80 and 443 give the process the one capability
it needs and nothing else:

```ini
[Service]
User=vc
Group=vc
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
ExecStart=/usr/bin/php /opt/exponential-velocity/sbin/qbixserver.php --conf-dir=/etc/vc --port=80 --https-port=443
```

The certificate and key must be readable by the user (group `vc`, mode 0640).
Behind nginx (Velocity on a Unix socket or 127.0.0.1) not even the capability is
needed: `CapabilityBoundingSet=` empty. `vcc profile apply standalone|nginx`
writes these units.

### 2. `--drop-master`: root only for the start

```
qbixserver.php ... --user=alpha --group=psacln --drop-master
```

also `Q.webserver.dropMaster: true` or `QBIX_RUN_DROP_MASTER=1` (or the
distribution's prefix, `VC_RUN_DROP_MASTER=1`, in the configuration tree's
`envvars`). Order at start:

1. the listeners are bound (HTTP, HTTPS, the Unix socket) and the certificates
   read into memory, as root;
2. the log directory and the pid file are handed to the user (a directory less
   than three levels deep — `/run`, `/var/log`, `/tmp` — is never touched);
3. the server process calls `setgid`, `initgroups`, `setuid` and checks that real,
   effective and saved ids are the user's and that `setuid(0)` fails;
4. only then: the warm-up, the workers, the scheduled tasks.

A start that cannot drop stops with an error; it never goes on as root. With
`Q.webserver.pools` configured the start is refused (pools of other users need a
master that can switch to them).

What the server can no longer do after the drop: bind a port below 1024 again (a
listener added later, such as the HTTPS port when a first certificate arrives;
use a capability or start as the user instead) and re-read a certificate only
root may read (a renewed certificate is picked up by a restart, or make it
group-readable).

### 3. Pools of several users

The master stays root because it forks each pool's workers and switches them to
the pool's user. Bound what that root can do:

```ini
CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_SETUID CAP_SETGID CAP_CHOWN CAP_FOWNER CAP_KILL CAP_DAC_READ_SEARCH
```

No module loading, no raw sockets, no mounts, no ptrace. The application code of
each site still only ever runs as that site's user.

## The warm-up and access checks

The warm-up runs the application's bootstrap once in the server process so every
worker inherits it. With a root server it runs with the worker user's *effective*
ids (`asUser()`), so what it writes is the user's. `access(2)` — what PHP's
`is_writable()` asks — answers for the *real* user, which was still root: on an
NFS export that squashes root, a directory the worker user may write read as not
writable ("Local DFS mount point ... is not writeable" from Exponential's cluster
file handler). While effective and real user differ, the access check is now
asked as the effective user, by trying: read opens the file or directory, write on
a directory creates and removes a probe file, write on a file opens it for writing
without changing it. With `--drop-master` the real user is the worker user anyway.

## Checking it

```
vcc topology show          # every Velocity master, its user, its workers' users
grep Uid /proc/<pid>/status
php tests/unit-nonroot-and-static-roots.php    # as root: drop, access checks, refusals
```
