## 🗂️ Configuration Layout

The server can keep its configuration in a directory laid out like Debian's
`/etc/apache2`, because that is the structure most administrators already know.
The standard place is `/etc/qbix`. Sites, shared snippets and engine modules each
have an `-available` directory holding the files and an `-enabled` directory
holding symlinks to the ones in use; enabling or disabling one is a symlink,
made or removed by the console.

- [The tree](#the-tree)
- [How the directory is chosen](#how-the-directory-is-chosen)
- [Load order](#load-order)
- [Enabling and disabling](#enabling-and-disabling)
- [Overlays and distributions](#overlays-and-distributions)
- [Checking what is used](#checking-what-is-used)
- [Programs: bin/ and sbin/](#programs-bin-and-sbin)

---

### The tree

```
/etc/qbix/
  qbix.conf               base settings                  (apache2.conf)
  ports.conf              listen ports                   (ports.conf)
  envvars                 environment for the process    (envvars)
  mods-available/*.conf   engine modules                 (mods-available)
  mods-enabled/           symlinks to the modules in use
  conf-available/*.conf   shared snippets                (conf-available)
  conf-enabled/           symlinks to the snippets in use
  sites-available/*.conf  one file per installation      (sites-available)
  sites-enabled/          symlinks to the sites in use
  designs/                the server's own page designs  (see designs.md)
  ssl/                    certificates                   (see https.md)
  acl/                    the control panel's credentials (see dashboard.md)

/var/lib/qbix/            state kept between runs        (/var/lib, beside /etc)
  sessions/               the control panel's sessions, one file each
  shell/                  the Q shell's per-user history and settings
```

Every file holds a JSON object in the engine's usual configuration format, under
Apache's file names. `envvars` holds `export NAME=value` lines, as Apache's does;
the server reads it, and never runs it as a script. `QBIX_RUN_USER` and
`QBIX_RUN_GROUP` there set the workers' user and group, as `APACHE_RUN_USER` and
`APACHE_RUN_GROUP` do (see [workers.md](workers.md#the-user-the-workers-run-as)).

Only `*.conf` and `*.json` files in an `-enabled` directory are loaded, as Apache
includes only `*.conf`, so an editor's backup file is never read as a setting. A
symlink whose target has gone is treated as disabled, not as an error.

The base file is named after its directory — `qbix.conf` in `/etc/qbix` — and
`qbix.conf` is accepted in any tree, so a tree moved elsewhere keeps working.

---

### How the directory is chosen

The directory is used only when something asks for it. The server never picks up
`/etc/qbix` just because it exists, so a machine's configuration cannot change how
an unrelated server, or a test suite, behaves.

| Asked for by | Meaning |
|---|---|
| `--conf-dir=DIR` | Use `DIR`. |
| `--conf-dir=auto` | Use the first standard place that holds a configuration. |
| `--conf-dir=none` | Use no configuration directory. |
| `QBIX_CONF_DIR` | The same, from the environment, when `--conf-dir` is not given. |
| `--config=DIR/sites-enabled/SITE.conf` | A site file inside a `sites-enabled` or `sites-available` directory names its tree. |

A directory counts as a configuration directory when it has a base file or any of
the `-available` and `-enabled` directories.

---

### Load order

Files are merged in the order `apache2.conf` includes them, and a later file wins:

1. the base file (`qbix.conf`),
2. `ports.conf`,
3. `mods-enabled/`, in name order,
4. `conf-enabled/`, in name order,
5. the `--config` file, which is normally one of `sites-enabled/`.

The site file is given with `--config`: the server loads the site it is started
for, not every enabled site at once.

```sh
php sbin/qbixserver.php --config=/etc/qbix/sites-enabled/example.com.conf
```

---

### Enabling and disabling

The console does what `a2ensite` and its family do: it makes or removes a relative
symlink in the `-enabled` directory, pointing at the file of the same name in
`-available`.

```sh
qbixconsole site:enable example.com      # a2ensite example.com
qbixconsole site:disable example.com     # a2dissite example.com
qbixconsole conf:enable logging          # a2enconf logging
qbixconsole mod:disable http2            # a2dismod http2
qbixconsole server:reload                # apply it
```

`qbixctl ensite`, `dissite`, `enconf`, `disconf`, `enmod` and `dismod` are the same
commands. A file in an `-enabled` directory that is not a symlink is left alone.
The change takes effect on the next reload. See [console.md](console.md).

---

### Overlays and distributions

Other trees laid out the same way can be stacked on top of `/etc/qbix` as
overlays. The base is loaded first and each overlay after it, so an overlay holds
only what it changes, and the files in `/etc/qbix` keep working unchanged beneath
it. The console's enable and disable commands change the top tree of the stack.

A distribution of the engine that keeps its own tree registers it as an overlay.
The distribution is named by `--distribution=NAME`, then `QBIX_DISTRIBUTION`, then
a `DISTRIBUTION` file in the engine's source directory; the server loads the class
`Q_WebServer_Distribution_<Name>` from `src/Q/WebServer/Distribution/` and asks it
to register what it adds. With no distribution, `none`, or no such class, nothing
changes. An overlay may have its own environment variable that moves it, as
`QBIX_CONF_DIR` moves the base.

Only the base and its registered overlays stack. A directory that is neither is
used on its own, so a server pointed at a tree of its own does not also pick up
the machine's.

Designs and certificates are looked up the same way: the top overlay's
`designs/` and `ssl/` first, then the base's.

### The state directory

What the server writes and keeps between runs lives in the state directory that
goes with the tree in use, as `/var/lib` goes with `/etc`: `/var/lib/qbix` for the
base tree, and for an overlay the state directory it registers along with its tree
(a distribution passes it to `addOverlay()`). `Q.webserver.stateDir` or
`QBIX_STATE_DIR` moves it. With no configuration tree in use there is no state
directory, and the server keeps its state beside the application, as before.

The control panel keeps its credentials in the tree (`acl/`) and its sessions in
the state directory (`sessions/`). Both must pass a strict ownership rule, checked
up to `/`, or the panel refuses every sign-in: see
[dashboard.md](dashboard.md#where-the-panel-keeps-its-credentials).

---

### Checking what is used

```sh
php sbin/qbixserver.php --layout --conf-dir=auto  # the files that would be loaded, as JSON, then exit
qbixconsole layout:show                          # the stack, and what is available and enabled
qbixconsole server:configtest                    # every file parses (qbixctl -t)
```

`layout:show` marks each enabled file with `*` and prints the stack in load order,
later winning. `server:configtest` prints `OK` or `BAD` for every file of every
tree and exits non-zero when one does not parse; a file that does not parse is
skipped by the server with a line on the console, never half-read.

---

### Programs: bin/ and sbin/

The engine's own tree is laid out the way the Filesystem Hierarchy Standard lays
out a system: the daemon and the commands that administer it in `sbin/`, the
commands any user runs in `bin/`, sources with the sources. The installed
packages follow the same split, `/usr/sbin` and `/usr/bin`. It came in with
0.0.4.41; before, the programs sat at the top of the tree, and `bin/` held the
phar, a helper and C sources side by side.

```
sbin/qbixserver.php      the server
sbin/qbixctl.php         control, the apachectl way
sbin/qbixconsole.php     every console command
sbin/qbixserver.phar     the server as one archive (committed; what the packages run)
sbin/uwebserver          the small C web server for benchmarks and tests (built)
bin/qshell.php           the shell at a terminal, and the server's shell runner
bin/qbix-appinfo.php     what a Qbix application is made of, for the panel
native/uwebserver/       uwebserver.c and its headers: source, not a program
```

Each program reads the engine's files from the directory above its own
(`dirname(__DIR__)`), the same way from a checkout, a Composer vendor copy and the
phar, which carries this layout inside it and runs `sbin/qbixserver.php` from its
stub.

**Every former path keeps working.** Systemd units, init scripts, cron jobs,
Composer's `vendor/bin`, programs that drive the server (a CMS's own control
script) and the start records of running servers (`qbixctl restart` starts a
server again the way it was started) name the old paths, so each old path is
still there and behaves exactly like the new one:

- the old `.php` files are forwarders: they `require` the new file in the same
  process, so the command line (`$argv`), the process id, the process title that
  `ps` and `pkill -f` see, standard input and output and the exit status are the
  new file's. A server started as `php qbixserver.php ...` still shows as that,
  and is found, reloaded and restarted as before;
- `bin/qbixserver.phar` is the same file as `sbin/qbixserver.phar`, byte for
  byte, written by `build-phar.php` and checked by `tests/phar-is-current.php`. A
  copy rather than a symbolic link, so it is there however the tree was unpacked
  (a Composer zip extracted without links, a file system without them). In the
  packages it is a link;
- `bin/uwebserver` is a shell forwarder that `exec`s `sbin/uwebserver`;
- the one difference is a line on standard error, written only when standard
  error is a terminal, saying where the program moved. Nothing a script reads
  changes. `QBIX_MOVED_QUIET=1` silences it at a terminal too.

`qbixctl` starts `sbin/qbixserver.php` and treats a server started by either path
as this engine's (`Q_WebServer_Ctl::serverScripts()`), so it stops, reloads and
restarts one started by the old path as well; the pattern
`qbixserver.php.*--port=N` matches both.

| Former path | Path now | Why |
|---|---|---|
| `qbixserver.php` | `sbin/qbixserver.php` | a daemon |
| `qbixctl.php` | `sbin/qbixctl.php` | starts, stops and configures the daemon: administration |
| `qbixconsole.php` | `sbin/qbixconsole.php` | its commands administer the server (sites, certificates, the panel's password, the cache) |
| `qshell.php` | `bin/qshell.php` | the shell a user opens; it runs with that user's rights (the server's runner drops to `Q.shell.user`) |
| `bin/qbixserver.phar` | `sbin/qbixserver.phar` | the daemon as one archive; the former path is the same file |
| `bin/uwebserver` | `sbin/uwebserver` | a small daemon, for benchmarks; a forwarder stays at the former path |
| `bin/uwebserver.c`, `bin/u_*.h` | `native/uwebserver/` | source code, not a program: kept as sources, in neither program directory |
| `bin/qbix-appinfo.php` | unchanged | reads an application and reports; any user may run it |
| `packaging/bin/qbixserver`, `qbixctl`, `qbixconsole` | `packaging/sbin/` | the wrappers the packages install in `/usr/sbin`; the former names are links to them |
| `packaging/bin/qbix-ext` | unchanged | a build helper run from the checkout, never installed |
| `/usr/bin/qbixserver`, `qbixctl`, `qbixconsole` (packages) | `/usr/sbin/...` | where a system keeps daemons and their administration commands; `/usr/bin/...` stay, as links, because `/usr/sbin` is not on every user's `PATH` |
| `/usr/local/bin/...` (container image) | `/usr/local/sbin/...` | as in the packages; `/usr/local/bin/...` are links |
| (not installed before 0.0.4.42) | `/usr/bin/vc-qshell` (packages), `/usr/local/bin/vc-qshell` (image) | the shell, a user command: a link to `bin/qshell.php` in the installed tree. Not `qshell`, which is the name of Qiniu's command-line tool; the name is the packaging's, the tree itself keeps `bin/qshell.php` |
| `/usr/share/exponential-velocity/bin/qbixserver.phar` | `/usr/share/exponential-velocity/sbin/qbixserver.phar` | the tree's own layout; the former path is a link |
| `build-phar.php`, `build-app.php`, `build-binary.sh` | unchanged | build tools run in a checkout, like a `configure` script; never installed |
| `src/Q/WebServer/Distribution/*/*info.php` | unchanged | helpers a distribution's panel runs from beside its class, not commands |
| `qbix-build.php` (the build stamp) | unchanged | data the programs read, not a program |

Composer's `bin` lists `sbin/qbixserver.php`, `sbin/qbixctl.php`,
`sbin/qbixconsole.php` and `bin/qshell.php`, so `vendor/bin/` has all four under
their own names.

The forwarders stay for the 0.0.4 line at least; a release that removes them, if
one ever does, is announced in the changelog before it happens.

---
[← Back to README](../README.md)
