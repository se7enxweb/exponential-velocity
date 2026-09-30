## ⏳ Time-Consuming Lessons

The problems below each cost hours or days to find, because the symptom pointed
somewhere else. Each is written the same way: what it looked like, what it
seemed to be, what it actually was, how to recognise it next time, and the fix.
If you are chasing a symptom that matches one of these, start from the
"Recognise it" line. The general rules behind them are in
[lessons.md](lessons.md).

- [The JIT that wrote the script twice](#the-jit-that-wrote-the-script-twice)
- [An old compile after an idle rewrite](#an-old-compile-after-an-idle-rewrite)
- [The warm-up that outlived start](#the-warm-up-that-outlived-start)
- [One database socket for every worker](#one-database-socket-for-every-worker)
- [Forking traps](#forking-traps)
- [The pool that "used 15 GB"](#the-pool-that-used-15-gb)
- [Workers that grew by a page a request](#workers-that-grew-by-a-page-a-request)
- [HTTP/2 defences that broke browsers](#http2-defences-that-broke-browsers)
- [Tests that passed here and failed there](#tests-that-passed-here-and-failed-there)
- [The release phar that named the release before it](#the-release-phar-that-named-the-release-before-it)
- [A tag nobody released](#a-tag-nobody-released)
- [The upload that never finished](#the-upload-that-never-finished)
- [The reload that changed nothing](#the-reload-that-changed-nothing)

---

### The JIT that wrote the script twice

- **Symptom:** in CI only, a test that regenerates and includes a PHP file failed
  with `syntax error, unexpected token "<"`. It passed on every developer machine.
- **Looked like:** a stale opcode cache serving a half-written file.
- **Actually:** PHP 8.2 and 8.3's *function* JIT (`opcache.jit=1235`) compiled the
  source transform in the middle of a call once its token loop turned hot. The
  compiled loop started again from the first token while keeping what it had
  already written, so about the fourth call in a process returned every token
  twice -- two `<?php` tags. PHP 8.4 and later, the tracing JIT and no JIT were
  never affected. `setup-php` turns 1235 on by default, which is why only CI saw it.
- **Recognise it:** a failure that appears only where `opcache.jit=1235`
  (`php -i | grep opcache.jit`), and output that contains its input twice.
  Reproduce with the function alone in a loop in a `php:8.3` container.
- **Fix:** the loop is written as a `foreach`, which that JIT compiles correctly
  (v0.0.4.27). `tests/unit-compat-transform-under-jit.php` runs the transform hot
  under 1235 and compares every result. Do not work around it by switching CI to
  tracing: that hides the fault from the one place that can see it.

---

### An old compile after an idle rewrite

- **Symptom:** after changing a PHP file, the first request to a worker showed
  the old version; the next one showed the new version.
- **Looked like:** a race in the server's own file cache.
- **Actually:** with `opcache.revalidate_freq` above 0 (the default is 2) the
  opcode cache does not look at a file it holds for that many seconds. The server
  re-checked the files it had served only when a request *ended*, so a file
  rewritten while the worker sat idle was served once more from the old compile.
- **Recognise it:** it happens once per worker, only within a couple of seconds
  of the rewrite, and goes away with `opcache.revalidate_freq=0`.
- **Fix:** the server now also re-checks served files as each request starts
  (v0.0.4.27). See [the opcode cache](lessons.md#the-opcode-cache).

---

### The warm-up that outlived start

- **Symptom:** every signed-in admin page failed with
  `array_unique(): Argument #1 ($array) must be of type array, string given` under
  this server, while the same pages worked under Apache. Earlier, template edits
  reached Apache at once and this server not at all, and a restart did not help.
- **Looked like:** a broken template or a bad deploy.
- **Actually:** two things the parent warm-up left behind. First, the file
  wrapper's stat memo from the warm-up was inherited by every worker, which
  compared rewritten files against the warm-up's mtime and ran the old code.
  Second, the warm-up rendered a public page, and a template engine's *static*
  override map kept the public design. A worker compiling an admin template
  baked the public paths into the compiled file on disk -- and that file then
  broke every admin page, surviving restarts.
- **Recognise it:** works under FPM, fails only under this server, and only
  with a warm-up. Grep compiled templates for paths from the wrong design.
- **Fix:** the server forgets the warm-up's stats at the end of the warm-up and
  in each worker after the fork. The application's warm-up must reset statics
  as well as globals. After fixing such a leak, move the compiled or cached files
  it wrote aside, or they keep the mistake.

---

### One database socket for every worker

- **Symptom:** intermittent `MySQL server has gone away` and
  `Commands out of sync`, surfacing as unrelated errors such as
  `Call to a member function attribute() on array`. Pages cached during the
  errors stayed wrong -- including under the other web server sharing the cache.
- **Looked like:** an overloaded or restarting database.
- **Actually:** the warm-up only dropped its reference to the database
  connection. The socket stayed open in the parent and every forked worker
  inherited the same one. In fork-per-request mode each exiting worker also sent
  QUIT over it. An application cache that stored "nothing found" during those
  errors then served empty pages to everyone.
- **Recognise it:** `ss -xpn | grep "pid=<parent pid>"` (or `ss -tpn`) shows a
  database socket held by the parent after start.
- **Fix:** close the connection explicitly at the end of the warm-up. Then clear
  any application cache that may have stored a failed lookup. Better still,
  never let an application cache store a negative result from a failed query.

---

### Forking traps

Three separate defects, all "tests pass, production drops requests":

- **`fclose()` of an inherited TLS stream in a child** sends close_notify on the
  parent's live connection. A burst of 360 requests over TLS lost 239 of them;
  every unit test over plain TCP passed. See
  [TLS in a forked child](lessons.md#tls-in-a-forked-child).
- **The same from the parent:** in fork-per-request mode the parent closed the
  worker's client, which for HTTP/2 is the whole shared connection. Signed-in
  pages then lost their header and styles on a hard reload, because only the
  dynamic requests went to workers.
- **`SIGCHLD` reaps every child.** The server's handler calls `waitpid(-1)`, pool
  workers included, so a later `waitpid($pid)` for a dead worker returns -1, and
  `posix_kill($pid, 0)` is true for a zombie. A dead worker was counted as alive.
  Ask the pool whether a worker has exited instead.
- **Recognise them:** anything that changes forking, worker lifecycle or
  descriptors must be tested with concurrent TLS requests while workers are
  forked and retired, not only with plain HTTP. `tests/unit-tls-survives-worker-forks.php`
  does this.

---

### The pool that "used 15 GB"

- **Symptom:** the dashboard and `top` showed the pool at several gigabytes, and
  the figure did not fall after a restart.
- **Looked like:** a memory leak, or too many workers.
- **Actually:** RSS summed over processes. Every worker maps the parent's warmed
  pages copy-on-write, and RSS counts each of those shared pages once per worker:
  380 workers at ~40 MB RSS read as 15 GB, where the real total was about 2.9 GB.
- **Recognise it:** RSS per worker is high from the moment it forks.
- **Fix:** measure PSS (`/proc/<pid>/smaps_rollup`); the dashboard does. See
  [measuring memory](lessons.md#measuring-memory).

---

### Workers that grew by a page a request

- **Symptom:** a worker grew about 2 MB per request, 1.1 GB after 600, with
  nothing any PHP code could reach holding the memory. Responses looked right.
- **Looked like:** a leak in the application.
- **Actually:** three things the server accumulated per request: an output
  buffer opened with flags that make it impossible to remove or clean, each with
  its page in it; error handlers pushed onto PHP's hidden handler stack and never
  popped, one of them holding the whole request log; and a stream wrapper
  registration per filesystem call, each a resource freed only at process end.
- **Recognise it:** memory per worker rises linearly with requests served, even
  for a trivial page. `ob_get_level()` above 1 at the start of a request is the
  first thing to check.
- **Fix:** one capture buffer per process, handlers unwound to the boot handler,
  far fewer wrapper registrations. `Q.webserver.workerMemoryCeiling` replaces a
  worker that grows anyway and logs why.

---

### HTTP/2 defences that broke browsers

- **Symptom:** browsers were disconnected after many reloads; later, after a
  hardening change, every browser's HTTP/2 connection hung while `curl` worked.
- **Looked like:** client bugs, or a network problem.
- **Actually:** the rapid-reset limit counted every stream the browser had ever
  cancelled, and a browser cancels the previous page's requests on each reload,
  so long-lived connections crossed it on ordinary use. The HPACK table-size
  check was applied where the encoder also learns the peer's
  `SETTINGS_HEADER_TABLE_SIZE`: browsers send 65536, curl sends nothing, so only
  browsers were refused.
- **Recognise it:** works with `curl --http2`, fails in a browser. Every GOAWAY
  the server sends now logs its reason -- read the log first.
- **Fix:** resets must also be more than half the streams opened to count as an
  attack; the table-size check applies only to size updates inside a header
  block. Test protocol hardening with a real browser, not only curl.

---

### Tests that passed here and failed there

- **Symptom:** memory-ceiling tests failed only in a container; an include test
  failed with a body that was right apart from a line of text at the top.
- **Looked like:** opcache breaking the server.
- **Actually:** the tests assumed things about the environment. A fresh worker
  was assumed to be over 1 MB of heap, so a 1 MB ceiling would always trip; on a
  lean build it is about 0.85 MB and nothing was replaced. And the stock `php`
  Docker image has no php.ini, so `display_errors` is on and a harmless warning
  from `header()` landed in the body being compared.
- **Recognise it:** run the suite in a clean container
  (`docker run --rm -v $PWD:/q -w /q php:8.3-cli php tests/run-unit.php`) and
  compare `php -i` between the two machines before suspecting the code.
- **Fix:** tests now make their own conditions (a request that holds 2 MB) rather
  than assuming them. Write tests that way.

---

### The release phar that named the release before it

- **Symptom:** the dashboard of a freshly released build showed the previous
  version number.
- **Looked like:** a stale deployment.
- **Actually:** the shipped version is stamped at build time from the nearest
  tag. A release commit is built before its tag exists, so every release phar
  named the release before it.
- **Recognise it:** `php sbin/qbixserver.phar --version` on the tagged commit
  shows the previous tag.
- **Fix:** build the release phar with the version being cut:
  `QBIX_SHIP_VERSION=vX.Y.Z.N php -d phar.readonly=0 build-phar.php`
  (the release steps in `CHANGELOG.md` include it).

---

### A tag nobody released

- **Symptom:** a version exists on Packagist with no GitHub release and no notes.
- **Looked like:** a failed release that could be redone.
- **Actually:** the release workflow publishes only a tag that has a section in
  `CHANGELOG.md`. A tag without one builds, tests and stops -- but Packagist has
  already read it, and a tag once read is a permanent version. It cannot be
  moved or re-cut: anything that resolved it keeps what it found.
- **Recognise it:** `git tag -l 'v*' --sort=version:refname` and
  `gh release list` disagree. Never use plain `tail -1` on tags: `0.0.4.10`
  sorts before `0.0.4.8` by name.
- **Fix:** write the changelog section before tagging. For a version already
  published without notes, describe it in the next release's notes.

---

### The upload that never finished

- **Symptom:** a file upload's progress dialog hung forever. No error anywhere.
- **Looked like:** a front-end problem in the application.
- **Actually:** two transport faults in the server. `json_encode()` returns
  `false` for a request body that is not UTF-8, and `strlen(false)` is 0, so the
  worker received an empty frame; and `is_uploaded_file()` was checked against a
  registry the pool's multipart parser never wrote to, so files that had arrived
  whole were refused.
- **Recognise it:** a hang in a progress dialog is a transport fault until proven
  otherwise. Compare the same upload against another web server byte for byte
  before reading application code.
- **Fix:** request bodies are framed safely for any bytes, and the parser
  registers the files it accepts.

---

### The reload that changed nothing

- **Symptom:** a PHP fix was deployed and PHP-FPM reloaded, and the site behind
  the other web server still ran the old code.
- **Looked like:** a cache that had not been cleared.
- **Actually:** the FPM pool that serves the vhost may belong to a different
  master than the default `php-fpm` unit -- a control panel often runs one FPM
  master per PHP version. The reload went to the wrong master.
- **Recognise it:** `ps -eo pid,etime,cmd | grep "php-fpm: master"` lists more
  than one master, and the one serving the site has been up far longer than your
  reload. Find the pool file that names the vhost under each master's
  `php-fpm.d/`.
- **Fix:** reload the master that owns the pool. Then clear any page cache
  shared between the two servers *after* the reload, or a page rendered by the
  old code in between is cached again.

---
[← Back to README](../README.md)
