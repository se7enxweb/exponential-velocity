#define _GNU_SOURCE
/*
 * uwebserver -- a small web server in C beside Exponential Velocity.
 *
 * HTTP/1.1 and HTTP/1.0 over plain TCP and TLS, from one event loop per
 * process (epoll, level-triggered, nothing ever blocks), optionally in
 * several worker processes. Two modes:
 *
 *   uwebserver                   the built-in answers (/, /json, /health),
 *                                a baseline for benchmarks
 *   uwebserver --root=DIR        the files under DIR: GET and HEAD,
 *                                conditional requests, one byte range,
 *                                precompressed .gz files, directory
 *                                listings when asked for
 *
 * Written and kept by hand. Its first version was generated from a U program
 * by a U-to-C translator; of that only the three built-in answers remain,
 * and neither the translator's output nor its runtime (u_runtime.h) is
 * compiled any more.
 *
 * Build (native/uwebserver/Makefile has the release flags):
 *   cc -O2 -o sbin/uwebserver native/uwebserver/uwebserver.c -lssl -lcrypto
 * The documentation is docs/uwebserver.md and the manual page docs/uwebserver.1.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <stdbool.h>
#include <stdint.h>
#include <limits.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>
#include <time.h>
#include <grp.h>
#include <pwd.h>
#include <dirent.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <sys/socket.h>
#include <sys/epoll.h>
#include <sys/file.h>
#include <sys/resource.h>
#include <sys/wait.h>
#include <sys/syscall.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <arpa/inet.h>
#ifdef __linux__
#include <linux/openat2.h>
#include <sys/prctl.h>
#endif

#include "u_options.h"
#include "u_http.h"
#include "u_mime.h"
#include "u_log.h"
#include "u_tls.h"
#include "u_sendfile.h"

/* The release and build, given when it is compiled:
 *   cc -O2 -DUWEB_VERSION="\"$(git describe --tags --abbrev=0)\"" \
 *      -DUWEB_BUILD="\"$(git rev-parse --short HEAD)\"" -o sbin/uwebserver native/uwebserver/uwebserver.c ...
 * so the source never states a version that has gone out of date. */
#ifndef UWEB_VERSION
#define UWEB_VERSION "(version not given at build time)"
#endif
#ifndef UWEB_BUILD
#define UWEB_BUILD "source"
#endif

#define UW_DEFAULT_ADDR    "127.0.0.1"
#define UW_DEFAULT_PORT    8000
#define UW_DEFAULT_TLSPORT 8443
#define UW_MAX_LIST        32
#define UW_MAX_WORKERS     256
#define UW_MAX_LISTENERS   (2 * UW_MAX_LIST + 2)

/* ════════════════════════════════════════════════════════════════════════
 * Configuration
 * ════════════════════════════════════════════════════════════════════════ */

typedef struct { char *v[UW_MAX_LIST]; int n; int from_cli; } UwList;

typedef struct {
    UwList listen, tls_listen, header;
    char *bind; int port, tls_port;
    int bind_given, port_given, tls_port_given;
    char *root, *index, *symlinks, *mime_types, *default_type, *charset, *cache_control, *server_name;
    int dirlist, hidden, etag, gzip_static, server_header, reuse_port;
    int workers, max_conns, backlog, max_requests;
    long long max_header, max_uri, max_body;
    int header_timeout, read_timeout, write_timeout, keepalive_timeout, handshake_timeout;
    char *cert, *key, *chain, *tls_min, *tls_ciphers, *tls_suites;
    char *access_log, *access_format, *error_log;
    char *pid_file, *user, *group, *chroot_dir;
    int daemon, allow_root;
    int quiet, verbose;
    char *config_file;
} UwConfig;

static UwConfig cfg;

static char *uw_strdup(const char *s) {
    char *d = strdup(s);
    if (!d) { fprintf(stderr, "%s: out of memory\n", uw_progname); exit(1); }
    return d;
}

static void uw_config_defaults(UwConfig *c) {
    memset(c, 0, sizeof(*c));
    c->bind = uw_strdup(UW_DEFAULT_ADDR);
    c->port = UW_DEFAULT_PORT;
    c->tls_port = UW_DEFAULT_TLSPORT;
    c->index = uw_strdup("index.html");
    c->symlinks = uw_strdup("inside");
    c->default_type = uw_strdup("application/octet-stream");
    c->charset = uw_strdup("utf-8");
    c->server_name = uw_strdup("uwebserver");
    c->etag = 1;
    c->server_header = 1;
    c->workers = 1;
    c->max_conns = 1024;
    c->backlog = 511;
    c->max_requests = 1000;
    c->max_header = 8192;
    c->max_uri = 4096;
    c->max_body = 1024 * 1024;
    c->header_timeout = 10;
    c->read_timeout = 30;
    c->write_timeout = 30;
    c->keepalive_timeout = 5;
    c->handshake_timeout = 10;
    c->tls_min = uw_strdup("1.2");
    c->access_log = uw_strdup("off");
    c->access_format = uw_strdup("combined");
    c->error_log = uw_strdup("-");
}

enum {
    OPT_HELP = 1, OPT_VERSION, OPT_ABOUT, OPT_CONFIG, OPT_CHECK, OPT_PRINT_CONFIG, OPT_QUIET, OPT_VERBOSE,
    OPT_LISTEN, OPT_BIND, OPT_PORT, OPT_TLS_LISTEN, OPT_TLS_PORT, OPT_REUSE_PORT, OPT_BACKLOG,
    OPT_ROOT, OPT_INDEX, OPT_DIRLIST, OPT_HIDDEN, OPT_SYMLINKS, OPT_MIME, OPT_DEFAULT_TYPE, OPT_CHARSET,
    OPT_CACHE_CONTROL, OPT_ETAG, OPT_GZIP_STATIC, OPT_HEADER, OPT_SERVER_NAME, OPT_SERVER_HEADER,
    OPT_WORKERS, OPT_MAX_CONNS, OPT_MAX_REQUESTS, OPT_MAX_HEADER, OPT_MAX_URI, OPT_MAX_BODY,
    OPT_HEADER_TIMEOUT, OPT_READ_TIMEOUT, OPT_WRITE_TIMEOUT, OPT_KEEPALIVE_TIMEOUT, OPT_HANDSHAKE_TIMEOUT,
    OPT_CERT, OPT_KEY, OPT_CHAIN, OPT_TLS_MIN, OPT_TLS_CIPHERS, OPT_TLS_SUITES,
    OPT_ACCESS_LOG, OPT_ACCESS_FORMAT, OPT_ERROR_LOG,
    OPT_DAEMON, OPT_FOREGROUND, OPT_PID_FILE, OPT_USER, OPT_GROUP, OPT_CHROOT, OPT_ALLOW_ROOT
};

#define V UWO_VALUE
#define S UWO_SWITCH
#define C UWO_CLI_ONLY
#define R UWO_REPEAT
#define H UWO_HIDDEN

/* In --help order; uw_groups says where each group starts. */
static const UwOpt uw_options[] = {
    { "help",           'h', C,     OPT_HELP,          NULL, "print this help and exit" },
    { "version",        'V', C,     OPT_VERSION,       NULL, "print the version and exit" },
    { "about",          0,   C,     OPT_ABOUT,         NULL, "print the version with build details and exit" },
    { "copyright",      0,   C | H, OPT_ABOUT,         NULL, NULL },
    { "",               'v', C | H, OPT_VERSION,       NULL, NULL },
    { "config",         'c', C | V, OPT_CONFIG,        "FILE", "read settings from FILE (the long names, without --)" },
    { "check",          't', C,     OPT_CHECK,         NULL, "check the configuration and exit (also --test-config)" },
    { "test-config",    0,   C | H, OPT_CHECK,         NULL, NULL },
    { "print-config",   0,   C,     OPT_PRINT_CONFIG,  NULL, "print the settings in effect as a config file, and exit" },
    { "quiet",          'q', 0,     OPT_QUIET,         NULL, "log errors only" },
    { "verbose",        0,   0,     OPT_VERBOSE,       NULL, "log connection events too (timeouts, bad requests)" },

    { "listen",         'l', V | R, OPT_LISTEN,        "[ADDR:]PORT", "serve HTTP there; repeatable. ADDR: IPv4, [IPv6], * or localhost" },
    { "bind",           'b', V,     OPT_BIND,          "ADDR", "the address of --port and --tls-port (127.0.0.1)" },
    { "port",           'p', V,     OPT_PORT,          "PORT", "serve HTTP on ADDR:PORT (8000)" },
    { "tls-listen",     0,   V | R, OPT_TLS_LISTEN,    "[ADDR:]PORT", "serve HTTPS there; repeatable (needs --cert and --key)" },
    { "tls-port",       0,   V,     OPT_TLS_PORT,      "PORT", "serve HTTPS on ADDR:PORT (8443, when --cert is given)" },
    { "reuse-port",     0,   S,     OPT_REUSE_PORT,    NULL, "share the ports with other servers (SO_REUSEPORT; off)" },
    { "backlog",        0,   V,     OPT_BACKLOG,       "N", "connections waiting to be accepted (511)" },

    { "root",           'r', V,     OPT_ROOT,          "DIR", "serve the files under DIR (without: the built-in answers)" },
    { "index",          0,   V,     OPT_INDEX,         "NAMES", "the files that answer for a directory, by commas (index.html)" },
    { "directory-listing", 0, S,    OPT_DIRLIST,       NULL, "list a directory that has no index file (off)" },
    { "hidden-files",   0,   S,     OPT_HIDDEN,        NULL, "serve names that start with a dot (off: 404)" },
    { "symlinks",       0,   V,     OPT_SYMLINKS,      "inside|never", "follow links that stay inside DIR, or none (inside)" },
    { "mime-types",     0,   V,     OPT_MIME,          "FILE", "more media types, in the mime.types format" },
    { "default-type",   0,   V,     OPT_DEFAULT_TYPE,  "TYPE", "for unknown extensions (application/octet-stream)" },
    { "charset",        0,   V,     OPT_CHARSET,       "NAME", "added to text types (utf-8; empty: none)" },
    { "cache-control",  0,   V,     OPT_CACHE_CONTROL, "VALUE", "the Cache-Control header of files (none)" },
    { "etag",           0,   S,     OPT_ETAG,          NULL, "send ETag and answer If-None-Match (on)" },
    { "gzip-static",    0,   S,     OPT_GZIP_STATIC,   NULL, "send FILE.gz to clients that accept gzip (off)" },
    { "header",         'H', V | R, OPT_HEADER,        "'NAME: VALUE'", "add a header to every response; repeatable" },
    { "server-name",    0,   V,     OPT_SERVER_NAME,   "NAME", "the value of the Server header (uwebserver)" },
    { "server-header",  0,   S,     OPT_SERVER_HEADER, NULL, "send a Server header (on)" },

    { "workers",        'w', V,     OPT_WORKERS,       "N", "worker processes (1; at most 256)" },
    { "max-connections", 0,  V,     OPT_MAX_CONNS,     "N", "open connections per worker (1024)" },
    { "max-requests",   0,   V,     OPT_MAX_REQUESTS,  "N", "requests per connection; 0: no limit (1000)" },
    { "max-header-size", 0,  V,     OPT_MAX_HEADER,    "SIZE", "request line and headers together (8k; 1k to 1m)" },
    { "max-uri-length", 0,   V,     OPT_MAX_URI,       "SIZE", "the request target (4k)" },
    { "max-body-size",  0,   V,     OPT_MAX_BODY,      "SIZE", "a request body, which is read and discarded (1m)" },
    { "header-timeout", 0,   V,     OPT_HEADER_TIMEOUT, "SECONDS", "to receive a whole request head (10)" },
    { "read-timeout",   0,   V,     OPT_READ_TIMEOUT,  "SECONDS", "between reads of a request body (30)" },
    { "write-timeout",  0,   V,     OPT_WRITE_TIMEOUT, "SECONDS", "without progress sending a response (30)" },
    { "keepalive-timeout", 0, V,    OPT_KEEPALIVE_TIMEOUT, "SECONDS", "idle between requests; 0: no keep-alive (5)" },
    { "tls-handshake-timeout", 0, V, OPT_HANDSHAKE_TIMEOUT, "SECONDS", "to complete a TLS handshake (10)" },

    { "cert",           0,   V,     OPT_CERT,          "FILE", "the certificate for HTTPS (PEM; may hold the chain)" },
    { "key",            0,   V,     OPT_KEY,           "FILE", "its private key (PEM; must not be readable by others)" },
    { "chain",          0,   V,     OPT_CHAIN,         "FILE", "more chain certificates (PEM)" },
    { "tls-min-version", 0,  V,     OPT_TLS_MIN,       "1.2|1.3", "the oldest TLS version accepted (1.2)" },
    { "tls-ciphers",    0,   V,     OPT_TLS_CIPHERS,   "LIST", "TLS 1.2 ciphers, an OpenSSL list (ECDHE with AEAD)" },
    { "tls-ciphersuites", 0, V,     OPT_TLS_SUITES,    "LIST", "TLS 1.3 suites (OpenSSL's default)" },

    { "access-log",     0,   V,     OPT_ACCESS_LOG,    "FILE|-|off", "log each request to FILE, - for standard output (off)" },
    { "access-log-format", 0, V,    OPT_ACCESS_FORMAT, "FORMAT", "common, combined or json (combined)" },
    { "error-log",      0,   V,     OPT_ERROR_LOG,     "FILE|-", "messages to FILE, - for standard error (-)" },

    { "daemon",         'd', S,     OPT_DAEMON,        NULL, "run in the background (off)" },
    { "foreground",     'f', 0,     OPT_FOREGROUND,    NULL, "run in the foreground (the default)" },
    { "pid-file",       0,   V,     OPT_PID_FILE,      "FILE", "write the process id to FILE, locked while running" },
    { "user",           'u', V,     OPT_USER,          "USER", "run as USER once the ports are open (needs root)" },
    { "group",          'g', V,     OPT_GROUP,         "GROUP", "run as GROUP (default: the group of USER)" },
    { "chroot",         0,   V,     OPT_CHROOT,        "DIR", "change the root directory to DIR (needs root)" },
    { "allow-root",     0,   S,     OPT_ALLOW_ROOT,    NULL, "serve as root without --user (refused otherwise)" },
    { NULL, 0, 0, 0, NULL, NULL }
};
#undef V
#undef S
#undef C
#undef R
#undef H

static const struct { int first_id; const char *title; } uw_groups[] = {
    { OPT_HELP,       "General" },
    { OPT_LISTEN,     "Listening" },
    { OPT_ROOT,       "Content" },
    { OPT_WORKERS,    "Limits and timeouts" },
    { OPT_CERT,       "TLS" },
    { OPT_ACCESS_LOG, "Logging" },
    { OPT_DAEMON,     "Process" },
};

static void uw_print_help(void) {
    printf("Usage: %s [OPTION]...\n"
           "A small web server: the files under --root, or built-in answers for benchmarks,\n"
           "over HTTP/1.1 and HTTPS. It listens on 127.0.0.1:8000 unless told otherwise.\n\n"
           "Mandatory arguments to long options are mandatory for short options too.\n"
           "Every long option also works with one dash (-root=DIR), and every switch has\n"
           "a --no- form (--no-etag). A SIZE may end in k, m or g; SECONDS 0 is no limit.\n",
           uw_progname);
    size_t g = 0;
    for (const UwOpt *o = uw_options; o->name; o++) {
        if (g < sizeof(uw_groups) / sizeof(uw_groups[0]) && o->id == uw_groups[g].first_id && !(o->flags & UWO_HIDDEN)) {
            printf("\n%s:\n", uw_groups[g].title);
            g++;
        }
        if (o->flags & UWO_HIDDEN) continue;
        char left[80];
        const char *arg = o->argname ? o->argname : "";
        const char *eq = o->argname ? "=" : "";
        if (o->shortc) snprintf(left, sizeof left, "-%c, --%s%s%s", o->shortc, o->name, eq, arg);
        else snprintf(left, sizeof left, "    --%s%s%s", o->name, eq, arg);
        if (strlen(left) > 28) printf("  %s\n  %-28s %s\n", left, "", o->help);
        else printf("  %-28s %s\n", left, o->help);
    }
    printf("\nWith --config, the file's settings come first and the command line overrides\n"
           "them; a repeatable option given on the command line replaces the file's values.\n"
           "UWEBSERVER_CONFIG names a configuration file when --config is not given.\n\n"
           "Exit status:\n"
           "  0  success (with --check: the configuration is valid)\n"
           "  1  the server could not start or stopped on an error, or --check failed\n"
           "  2  a usage error: an unknown option, a bad value, a bad configuration file\n\n"
           "Examples:\n"
           "  %s --root=/srv/www                    files on http://127.0.0.1:8000/\n"
           "  %s -r /srv/www -l '*:80' -u www       every IPv4 address, as user www\n"
           "  %s --config=/etc/uwebserver.conf --check\n\n"
           "Full documentation: docs/uwebserver.md, and man uwebserver.\n"
           "Report bugs to: https://github.com/se7enxweb/exponential-velocity/issues\n"
           "Home page: <https://github.com/se7enxweb/exponential-velocity>\n",
           uw_progname, uw_progname, uw_progname);
}

/* --version, -V, -v, --about, --copyright (and -version, -about, -copyright):
 * what this program is, the way the PHP programs of the server say it
 * (Q_WebServer_About), GNU style. */
static void uw_print_about(void) {
    printf("uwebserver (Exponential Velocity) %s\n"
           "A small web server in C beside Exponential Velocity: static files, or\n"
           "built-in answers for benchmarks, over HTTP/1.1 and HTTPS.\n\n"
           "  Program:      uwebserver -- a small web server, compiled\n"
           "  Version:      %s+%s\n"
           "  Built:        %s %s, %s, %s\n"
           "  Options:      uwebserver --help\n"
           "  Home page:    https://github.com/se7enxweb/exponential-velocity\n\n"
           "Copyright (C) 2026 7x (se7enx.com) -- Exponential Velocity\n"
           "Copyright (C) 2024-2026 Qbix, Inc.\n"
           "License MIT: <https://opensource.org/license/mit>; the full text is in\n"
           "the LICENSE file that comes with the program.\n"
           "This is free software: you are free to change and redistribute it.\n"
           "There is NO WARRANTY, to the extent permitted by law.\n\n"
           "Written by 7x (se7enx.com), on the Qbix Server by Qbix, Inc. and contributors.\n",
           UWEB_VERSION, UWEB_VERSION, UWEB_BUILD, __DATE__, __TIME__,
#ifdef __VERSION__
           "compiler " __VERSION__,
#else
           "unknown compiler",
#endif
           OPENSSL_VERSION_TEXT);
}

static void uw_set_str(char **slot, const char *v) {
    char *d = uw_strdup(v);
    free(*slot);
    *slot = d;
}

static int uw_list_add(UwList *l, const char *v, int from_cli, char *err, size_t errlen, const char *name) {
    if (from_cli && !l->from_cli) {          /* the command line replaces the file's values */
        for (int i = 0; i < l->n; i++) free(l->v[i]);
        l->n = 0;
        l->from_cli = 1;
    }
    if (l->n >= UW_MAX_LIST) { snprintf(err, errlen, "at most %d values for '%s'", UW_MAX_LIST, name); return -1; }
    l->v[l->n++] = uw_strdup(v);
    return 0;
}

static int uw_no_ctl(const char *s) {
    for (; *s; s++) if ((unsigned char)*s < 0x20 || *s == 0x7f) return 0;
    return 1;
}

static int uw_valid_header_line(const char *h) {
    const char *colon = strchr(h, ':');
    if (!colon || colon == h) return 0;
    for (const char *c = h; c < colon; c++) if (!uw_is_tchar((unsigned char)*c)) return 0;
    for (const char *c = colon + 1; *c; c++) {
        unsigned char u = (unsigned char)*c;
        if ((u < 0x20 && u != '\t') || u == 0x7f) return 0;
    }
    /* Headers the server sets itself cannot be given a second time. */
    static const char *own[] = { "content-length", "transfer-encoding", "connection", "date", "content-range", "content-encoding" };
    for (size_t i = 0; i < sizeof own / sizeof own[0]; i++)
        if ((size_t)(colon - h) == strlen(own[i]) && strncasecmp(h, own[i], strlen(own[i])) == 0) return 0;
    return 1;
}

/*
 * Applies one setting (from the command line or the configuration file).
 * Returns 0, or -1 with the complaint in err, naming `spelled`.
 */
static int uw_apply(UwConfig *c, const UwOpt *o, const char *val, int negated, int from_cli, const char *spelled, char *err, size_t errlen) {
    long long n;
    int on = !negated;
#define BAD(...) do { snprintf(err, errlen, __VA_ARGS__); return -1; } while (0)
#define NUM(min, max, what) do { if (uw_parse_long(val, min, max, &n) != 0) BAD("invalid %s '%s' for '%s' (%lld to %lld)", what, val, spelled, (long long)(min), (long long)(max)); } while (0)
#define SIZE(min, max) do { if (uw_parse_size(val, min, max, &n) != 0) BAD("invalid size '%s' for '%s' (%lld to %lld bytes; k, m or g may follow)", val, spelled, (long long)(min), (long long)(max)); } while (0)
#define SECS() NUM(0, 86400, "number of seconds")
    switch (o->id) {
    case OPT_QUIET:   c->quiet = on; if (on) c->verbose = 0; break;
    case OPT_VERBOSE: c->verbose = on; if (on) c->quiet = 0; break;
    case OPT_LISTEN:     if (!*val) BAD("empty address for '%s'", spelled); return uw_list_add(&c->listen, val, from_cli, err, errlen, spelled);
    case OPT_TLS_LISTEN: if (!*val) BAD("empty address for '%s'", spelled); return uw_list_add(&c->tls_listen, val, from_cli, err, errlen, spelled);
    case OPT_HEADER:
        if (!uw_valid_header_line(val)) BAD("invalid header '%s' for '%s' (NAME: VALUE, and not one the server sets itself)", val, spelled);
        return uw_list_add(&c->header, val, from_cli, err, errlen, spelled);
    case OPT_BIND:     if (!*val) BAD("empty address for '%s'", spelled); uw_set_str(&c->bind, val); c->bind_given = 1; break;
    case OPT_PORT:     NUM(1, 65535, "port"); c->port = (int)n; c->port_given = 1; break;
    case OPT_TLS_PORT: NUM(1, 65535, "port"); c->tls_port = (int)n; c->tls_port_given = 1; break;
    case OPT_REUSE_PORT: c->reuse_port = on; break;
    case OPT_BACKLOG:  NUM(1, 65535, "backlog"); c->backlog = (int)n; break;
    case OPT_ROOT:     if (!*val) BAD("empty directory for '%s'", spelled); uw_set_str(&c->root, val); break;
    case OPT_INDEX:
        if (!*val) BAD("empty index list for '%s'", spelled);
        for (const char *p = val; *p; p++) if (*p == '/' || (unsigned char)*p < 0x20 || *p == 0x7f) BAD("invalid index names '%s' for '%s' (file names, no '/')", val, spelled);
        uw_set_str(&c->index, val); break;
    case OPT_DIRLIST:  c->dirlist = on; break;
    case OPT_HIDDEN:   c->hidden = on; break;
    case OPT_SYMLINKS:
        if (strcmp(val, "inside") != 0 && strcmp(val, "never") != 0) BAD("invalid value '%s' for '%s' (inside or never)", val, spelled);
        uw_set_str(&c->symlinks, val); break;
    case OPT_MIME:     if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->mime_types, val); break;
    case OPT_DEFAULT_TYPE:
        if (!*val || !strchr(val, '/') || !uw_no_ctl(val)) BAD("invalid media type '%s' for '%s'", val, spelled);
        uw_set_str(&c->default_type, val); break;
    case OPT_CHARSET:
        for (const char *p = val; *p; p++) if (!uw_is_tchar((unsigned char)*p)) BAD("invalid charset '%s' for '%s'", val, spelled);
        uw_set_str(&c->charset, val); break;
    case OPT_CACHE_CONTROL: if (!uw_no_ctl(val)) BAD("invalid value for '%s' (no control characters)", spelled); uw_set_str(&c->cache_control, val); break;
    case OPT_ETAG:        c->etag = on; break;
    case OPT_GZIP_STATIC: c->gzip_static = on; break;
    case OPT_SERVER_NAME:
        if (!*val || !uw_no_ctl(val)) BAD("invalid server name '%s' for '%s'", val, spelled);
        uw_set_str(&c->server_name, val); break;
    case OPT_SERVER_HEADER: c->server_header = on; break;
    case OPT_WORKERS:      NUM(1, UW_MAX_WORKERS, "number of workers"); c->workers = (int)n; break;
    case OPT_MAX_CONNS:    NUM(1, 1000000, "number of connections"); c->max_conns = (int)n; break;
    case OPT_MAX_REQUESTS: NUM(0, 100000000, "number of requests"); c->max_requests = (int)n; break;
    case OPT_MAX_HEADER:   SIZE(1024, 1024 * 1024); c->max_header = n; break;
    case OPT_MAX_URI:      SIZE(16, 1024 * 1024); c->max_uri = n; break;
    case OPT_MAX_BODY:     SIZE(0, 1024LL * 1024 * 1024 * 16); c->max_body = n; break;
    case OPT_HEADER_TIMEOUT:    SECS(); c->header_timeout = (int)n; break;
    case OPT_READ_TIMEOUT:      SECS(); c->read_timeout = (int)n; break;
    case OPT_WRITE_TIMEOUT:     SECS(); c->write_timeout = (int)n; break;
    case OPT_KEEPALIVE_TIMEOUT: SECS(); c->keepalive_timeout = (int)n; break;
    case OPT_HANDSHAKE_TIMEOUT: SECS(); c->handshake_timeout = (int)n; break;
    case OPT_CERT:  if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->cert, val); break;
    case OPT_KEY:   if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->key, val); break;
    case OPT_CHAIN: if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->chain, val); break;
    case OPT_TLS_MIN:
        if (strcmp(val, "1.2") != 0 && strcmp(val, "1.3") != 0) BAD("invalid TLS version '%s' for '%s' (1.2 or 1.3)", val, spelled);
        uw_set_str(&c->tls_min, val); break;
    case OPT_TLS_CIPHERS: uw_set_str(&c->tls_ciphers, val); break;
    case OPT_TLS_SUITES:  uw_set_str(&c->tls_suites, val); break;
    case OPT_ACCESS_LOG:  if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->access_log, val); break;
    case OPT_ACCESS_FORMAT:
        if (strcmp(val, "common") != 0 && strcmp(val, "combined") != 0 && strcmp(val, "json") != 0)
            BAD("invalid format '%s' for '%s' (common, combined or json)", val, spelled);
        uw_set_str(&c->access_format, val); break;
    case OPT_ERROR_LOG:  if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->error_log, val); break;
    case OPT_DAEMON:     c->daemon = on; break;
    case OPT_FOREGROUND: c->daemon = !on; break;
    case OPT_PID_FILE:   if (!*val) BAD("empty file name for '%s'", spelled); uw_set_str(&c->pid_file, val); break;
    case OPT_USER:       if (!*val) BAD("empty user for '%s'", spelled); uw_set_str(&c->user, val); break;
    case OPT_GROUP:      if (!*val) BAD("empty group for '%s'", spelled); uw_set_str(&c->group, val); break;
    case OPT_CHROOT:     if (!*val) BAD("empty directory for '%s'", spelled); uw_set_str(&c->chroot_dir, val); break;
    case OPT_ALLOW_ROOT: c->allow_root = on; break;
    default: break;
    }
    return 0;
#undef BAD
#undef NUM
#undef SIZE
#undef SECS
}

/*
 * Reads a configuration file: one setting per line, "name = value",
 * "name value", or "name" alone for a switch (also "name = yes|no" and
 * "no-name"); a line whose first non-blank character is '#' is a comment.
 * A value may be quoted to keep blanks at its ends. Exits 2 on any error,
 * naming the file and the line.
 */
static void uw_read_config(UwConfig *c, const char *file) {
    FILE *f = fopen(file, "re");
    if (!f) { fprintf(stderr, "%s: cannot read the configuration file %s: %s\n", uw_progname, file, strerror(errno)); exit(2); }
    char line[4096];
    int lineno = 0;
    while (fgets(line, sizeof line, f)) {
        lineno++;
        size_t len = strlen(line);
        if (len == sizeof line - 1 && line[len - 1] != '\n') { fprintf(stderr, "%s: %s:%d: line too long\n", uw_progname, file, lineno); exit(2); }
        if (memchr(line, '\0', len) != NULL && strlen(line) != len) { fprintf(stderr, "%s: %s:%d: a NUL byte\n", uw_progname, file, lineno); exit(2); }
        while (len && (line[len - 1] == '\n' || line[len - 1] == '\r' || line[len - 1] == ' ' || line[len - 1] == '\t')) line[--len] = '\0';
        char *p = line;
        while (*p == ' ' || *p == '\t') p++;
        if (!*p || *p == '#') continue;
        char *name = p;
        while (*p && *p != ' ' && *p != '\t' && *p != '=') p++;
        char *name_end = p;
        while (*p == ' ' || *p == '\t') p++;
        if (*p == '=') { p++; while (*p == ' ' || *p == '\t') p++; }
        char *val = p;
        *name_end = '\0';
        size_t vl = strlen(val);
        if (vl >= 2 && ((val[0] == '"' && val[vl - 1] == '"') || (val[0] == '\'' && val[vl - 1] == '\''))) { val[vl - 1] = '\0'; val++; }
        int neg = 0;
        const UwOpt *o = *name ? uw_find_long(uw_options, name, strlen(name), &neg) : NULL;
        if (!o || (o->flags & UWO_CLI_ONLY)) { fprintf(stderr, "%s: %s:%d: unknown setting '%s'\n", uw_progname, file, lineno, name); exit(2); }
        char err[512];
        if (o->flags & UWO_VALUE) {
            if (!*val && o->id != OPT_CHARSET && o->id != OPT_CACHE_CONTROL) { fprintf(stderr, "%s: %s:%d: '%s' needs a value\n", uw_progname, file, lineno, name); exit(2); }
            if (uw_apply(c, o, val, 0, 0, name, err, sizeof err) != 0) { fprintf(stderr, "%s: %s:%d: %s\n", uw_progname, file, lineno, err); exit(2); }
        } else {
            int on = 1;
            if (*val && uw_parse_bool(val, &on) != 0) { fprintf(stderr, "%s: %s:%d: '%s' is yes or no, not '%s'\n", uw_progname, file, lineno, name, val); exit(2); }
            if (neg) on = !on;
            if (uw_apply(c, o, NULL, !on, 0, name, err, sizeof err) != 0) { fprintf(stderr, "%s: %s:%d: %s\n", uw_progname, file, lineno, err); exit(2); }
        }
    }
    fclose(f);
}

static void uw_print_value(const char *name, const char *v) {
    if (!v) { printf("# %s =\n", name); return; }
    size_t n = strlen(v);
    int quote = (n == 0 || v[0] == ' ' || v[n - 1] == ' ' || v[0] == '"' || v[0] == '\'');
    printf(quote ? "%s = \"%s\"\n" : "%s = %s\n", name, v);
}

/* --print-config: every setting, in the configuration file's format. */
static void uw_print_config(const UwConfig *c) {
    char num[32];
#define P_INT(name, v)  do { snprintf(num, sizeof num, "%lld", (long long)(v)); uw_print_value(name, num); } while (0)
#define P_BOOL(name, v) uw_print_value(name, (v) ? "yes" : "no")
#define P_LIST(name, l) do { if ((l).n == 0) printf("# %s =\n", name); for (int i_ = 0; i_ < (l).n; i_++) uw_print_value(name, (l).v[i_]); } while (0)
    printf("# uwebserver %s: the settings in effect\n", UWEB_VERSION);
    P_BOOL("quiet", c->quiet); P_BOOL("verbose", c->verbose);
    P_LIST("listen", c->listen);
    /* bind, port and tls-port add a listener when given: shown commented, with the default, unless they were. */
    if (c->bind_given) uw_print_value("bind", c->bind); else printf("# bind = %s\n", c->bind);
    if (c->port_given) P_INT("port", c->port); else printf("# port = %d\n", c->port);
    P_LIST("tls-listen", c->tls_listen);
    if (c->tls_port_given) P_INT("tls-port", c->tls_port); else printf("# tls-port = %d\n", c->tls_port);
    P_BOOL("reuse-port", c->reuse_port); P_INT("backlog", c->backlog);
    uw_print_value("root", c->root); uw_print_value("index", c->index);
    P_BOOL("directory-listing", c->dirlist); P_BOOL("hidden-files", c->hidden);
    uw_print_value("symlinks", c->symlinks); uw_print_value("mime-types", c->mime_types);
    uw_print_value("default-type", c->default_type); uw_print_value("charset", c->charset);
    uw_print_value("cache-control", c->cache_control); P_BOOL("etag", c->etag); P_BOOL("gzip-static", c->gzip_static);
    P_LIST("header", c->header);
    uw_print_value("server-name", c->server_name); P_BOOL("server-header", c->server_header);
    P_INT("workers", c->workers); P_INT("max-connections", c->max_conns); P_INT("max-requests", c->max_requests);
    P_INT("max-header-size", c->max_header); P_INT("max-uri-length", c->max_uri); P_INT("max-body-size", c->max_body);
    P_INT("header-timeout", c->header_timeout); P_INT("read-timeout", c->read_timeout);
    P_INT("write-timeout", c->write_timeout); P_INT("keepalive-timeout", c->keepalive_timeout);
    P_INT("tls-handshake-timeout", c->handshake_timeout);
    uw_print_value("cert", c->cert); uw_print_value("key", c->key); uw_print_value("chain", c->chain);
    uw_print_value("tls-min-version", c->tls_min); uw_print_value("tls-ciphers", c->tls_ciphers);
    uw_print_value("tls-ciphersuites", c->tls_suites);
    uw_print_value("access-log", c->access_log); uw_print_value("access-log-format", c->access_format);
    uw_print_value("error-log", c->error_log);
    P_BOOL("daemon", c->daemon); uw_print_value("pid-file", c->pid_file);
    uw_print_value("user", c->user); uw_print_value("group", c->group); uw_print_value("chroot", c->chroot_dir);
    P_BOOL("allow-root", c->allow_root);
#undef P_INT
#undef P_BOOL
#undef P_LIST
}

/* ════════════════════════════════════════════════════════════════════════
 * Listeners
 * ════════════════════════════════════════════════════════════════════════ */

typedef struct {
    struct sockaddr_storage ss;
    socklen_t len;
    int tls;
    int fd;
    time_t paused_until;   /* out of descriptors: accept again after this */
    char text[160];
} UwListener;

static UwListener listeners[UW_MAX_LISTENERS];
static int nlisteners = 0;

/* "[ADDR:]PORT", "ADDR", "[V6]:PORT", "*:PORT". Returns 0 or -1 with err. */
static int uw_parse_listen(const char *spec, const char *defaddr, int defport, int tls, UwListener *l, char *err, size_t errlen) {
    char addr[128];
    const char *port_s = NULL;
    memset(l, 0, sizeof(*l));
    l->tls = tls; l->fd = -1;
    if (strlen(spec) >= sizeof addr) { snprintf(err, errlen, "address too long: '%.40s...'", spec); return -1; }
    long long pv;
    if (uw_parse_long(spec, 0, LLONG_MAX, &pv) == 0) {              /* PORT alone */
        snprintf(addr, sizeof addr, "%s", defaddr);
        port_s = spec;
    } else if (spec[0] == '[') {
        const char *close = strchr(spec, ']');
        if (!close) { snprintf(err, errlen, "invalid address '%s' (an IPv6 address is written [ADDR]:PORT)", spec); return -1; }
        snprintf(addr, sizeof addr, "%.*s", (int)(close - spec - 1), spec + 1);
        if (close[1] == ':') port_s = close + 2;
        else if (close[1] != '\0') { snprintf(err, errlen, "invalid address '%s'", spec); return -1; }
    } else {
        const char *colon = strrchr(spec, ':');
        if (colon && strchr(spec, ':') == colon) {                  /* one colon: ADDR:PORT */
            snprintf(addr, sizeof addr, "%.*s", (int)(colon - spec), spec);
            port_s = colon + 1;
        } else snprintf(addr, sizeof addr, "%s", spec);             /* ADDR, or a bare IPv6 address */
    }
    int port = defport;
    if (port_s) {
        if (uw_parse_long(port_s, 1, 65535, &pv) != 0) { snprintf(err, errlen, "invalid port in '%s' (1 to 65535)", spec); return -1; }
        port = (int)pv;
    }
    if (strcmp(addr, "*") == 0) snprintf(addr, sizeof addr, "0.0.0.0");
    if (strcmp(addr, "localhost") == 0) snprintf(addr, sizeof addr, "127.0.0.1");
    struct sockaddr_in *s4 = (struct sockaddr_in *)&l->ss;
    struct sockaddr_in6 *s6 = (struct sockaddr_in6 *)&l->ss;
    if (inet_pton(AF_INET, addr, &s4->sin_addr) == 1) {
        s4->sin_family = AF_INET; s4->sin_port = htons((uint16_t)port); l->len = sizeof(*s4);
        snprintf(l->text, sizeof l->text, "%s://%s:%d", tls ? "https" : "http", addr, port);
    } else if (inet_pton(AF_INET6, addr, &s6->sin6_addr) == 1) {
        s6->sin6_family = AF_INET6; s6->sin6_port = htons((uint16_t)port); l->len = sizeof(*s6);
        snprintf(l->text, sizeof l->text, "%s://[%s]:%d", tls ? "https" : "http", addr, port);
    } else {
        snprintf(err, errlen, "invalid address '%s' in '%s' (a numeric IPv4 address, [IPv6], * or localhost)", addr, spec);
        return -1;
    }
    return 0;
}

/*
 * The listeners the configuration asks for: every --listen and
 * --tls-listen, plus ADDR:PORT for HTTP when --port or --bind is given or
 * nothing else is, and ADDR:TLSPORT for HTTPS when --tls-port is given, or
 * a certificate is and no --tls-listen.
 */
static int uw_resolve_listeners(char *err, size_t errlen) {
    nlisteners = 0;
    int want_http_default = cfg.port_given || cfg.bind_given || (cfg.listen.n == 0 && cfg.tls_listen.n == 0);
    int want_tls_default = cfg.tls_port_given || (cfg.cert && cfg.tls_listen.n == 0);
    for (int i = 0; i < cfg.listen.n; i++)
        if (uw_parse_listen(cfg.listen.v[i], cfg.bind, cfg.port, 0, &listeners[nlisteners++], err, errlen) != 0) return -1;
    if (want_http_default) {
        char p[16]; snprintf(p, sizeof p, "%d", cfg.port);
        if (uw_parse_listen(p, cfg.bind, cfg.port, 0, &listeners[nlisteners++], err, errlen) != 0) return -1;
    }
    for (int i = 0; i < cfg.tls_listen.n; i++)
        if (uw_parse_listen(cfg.tls_listen.v[i], cfg.bind, cfg.tls_port, 1, &listeners[nlisteners++], err, errlen) != 0) return -1;
    if (want_tls_default) {
        char p[16]; snprintf(p, sizeof p, "%d", cfg.tls_port);
        if (uw_parse_listen(p, cfg.bind, cfg.tls_port, 1, &listeners[nlisteners++], err, errlen) != 0) return -1;
    }
    for (int i = 0; i < nlisteners; i++)
        for (int j = 0; j < i; j++) {
            const char *a = strstr(listeners[i].text, "://"), *b = strstr(listeners[j].text, "://");
            if (a && b && strcmp(a, b) == 0) { snprintf(err, errlen, "%s is asked for twice", a + 3); return -1; }
        }
    return 0;
}

static int uw_bind_listeners(void) {
    for (int i = 0; i < nlisteners; i++) {
        UwListener *l = &listeners[i];
        int fd = socket(l->ss.ss_family, SOCK_STREAM | SOCK_NONBLOCK | SOCK_CLOEXEC, 0);
        if (fd < 0) { uw_log(UW_LOG_ERROR, "socket for %s: %s", l->text, strerror(errno)); return -1; }
        int one = 1;
        setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof one);   /* restart through TIME_WAIT */
        if (cfg.reuse_port) setsockopt(fd, SOL_SOCKET, SO_REUSEPORT, &one, sizeof one);
        if (l->ss.ss_family == AF_INET6) setsockopt(fd, IPPROTO_IPV6, IPV6_V6ONLY, &one, sizeof one);
        if (bind(fd, (struct sockaddr *)&l->ss, l->len) != 0) {
            uw_log(UW_LOG_ERROR, "cannot listen on %s: %s", l->text, strerror(errno));
            close(fd); return -1;
        }
        if (listen(fd, cfg.backlog) != 0) { uw_log(UW_LOG_ERROR, "listen on %s: %s", l->text, strerror(errno)); close(fd); return -1; }
        l->fd = fd;
    }
    return 0;
}

/* ════════════════════════════════════════════════════════════════════════
 * Connections
 * ════════════════════════════════════════════════════════════════════════ */

enum { ST_HANDSHAKE, ST_READ, ST_WRITE, ST_LINGER };

typedef struct {
    int fd;
    int state;
    SSL *ssl;
    int ssl_want;            /* what the last TLS call waited for */
    char *in; size_t in_len, in_cap;
    char *out; size_t out_len, out_off, out_cap;
    int file_fd; off_t file_off, file_end;
    char *tbuf; size_t tbuf_len, tbuf_off;   /* TLS: file data read and not yet written */
    long long discard;       /* request body bytes still to skip */
    int keep_alive, requests, head_started;
    time_t deadline;
    uint32_t events;
    size_t lingered;
    /* for the access log */
    char peer[64];
    char l_method[40], l_target[1100], l_referer[520], l_agent[520];
    int l_minor, l_status, l_logged;
    long long l_bytes;
    struct timespec l_start;
} Conn;

static SSL_CTX *ssl_ctx = NULL;
static Conn **conns = NULL;
static int conns_cap = 0;
static int nconns = 0;
static int epfd = -1;
static int rootfd = -1;
static int access_fd = -1;
static volatile sig_atomic_t stopping = 0;
static time_t now_s;
static char date_hdr[64];
static time_t date_at = 0;
static long long total_requests = 0, total_bytes = 0;
static time_t start_time;

static void uw_update_date(void) {
    now_s = time(NULL);
    if (now_s != date_at) {
        struct tm tm; gmtime_r(&now_s, &tm);
        strftime(date_hdr, sizeof date_hdr, "%a, %d %b %Y %H:%M:%S GMT", &tm);
        date_at = now_s;
    }
}

static void uw_set_events(Conn *c, uint32_t ev) {
    if (c->events == ev) return;
    struct epoll_event e = { .events = ev, .data.fd = c->fd };
    epoll_ctl(epfd, EPOLL_CTL_MOD, c->fd, &e);
    c->events = ev;
}

static void uw_deadline(Conn *c, int secs) { c->deadline = secs > 0 ? now_s + secs : 0; }

static void uw_access_log(Conn *c);

static void uw_conn_close(Conn *c) {
    if (!c) return;
    if (c->l_status && !c->l_logged) uw_access_log(c);
    epoll_ctl(epfd, EPOLL_CTL_DEL, c->fd, NULL);
    if (c->ssl) { SSL_set_quiet_shutdown(c->ssl, 1); SSL_free(c->ssl); }
    if (c->file_fd >= 0) close(c->file_fd);
    close(c->fd);
    conns[c->fd] = NULL;
    free(c->in); free(c->out); free(c->tbuf);
    free(c);
    nconns--;
}

/* ── The output buffer ──────────────────────────────────────────────────── */

#define UW_OUT_LIMIT (8u * 1024 * 1024)   /* a generated response (a listing) at most */

static int uw_out_reserve(Conn *c, size_t more) {
    if (more > UW_OUT_LIMIT || c->out_len + more + 1 > UW_OUT_LIMIT) return -1;
    if (c->out_len + more + 1 <= c->out_cap) return 0;
    size_t cap = c->out_cap ? c->out_cap : 1024;
    while (cap < c->out_len + more + 1) cap *= 2;
    char *n = (char *)realloc(c->out, cap);
    if (!n) return -1;
    c->out = n; c->out_cap = cap;
    return 0;
}

static int uw_out_add(Conn *c, const char *s, size_t n) {
    if (uw_out_reserve(c, n) != 0) return -1;
    memcpy(c->out + c->out_len, s, n);
    c->out_len += n;
    c->out[c->out_len] = '\0';
    return 0;
}

static int uw_out_printf(Conn *c, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
static int uw_out_printf(Conn *c, const char *fmt, ...) {
    va_list ap;
    char tmp[2048];
    va_start(ap, fmt);
    int n = vsnprintf(tmp, sizeof tmp, fmt, ap);
    va_end(ap);
    if (n < 0) return -1;
    if ((size_t)n < sizeof tmp) return uw_out_add(c, tmp, (size_t)n);
    /* Longer than the scratch buffer: format again, straight into the output. */
    if (uw_out_reserve(c, (size_t)n) != 0) return -1;
    va_start(ap, fmt);
    vsnprintf(c->out + c->out_len, (size_t)n + 1, fmt, ap);
    va_end(ap);
    c->out_len += (size_t)n;
    return 0;
}

/* ── Responses ──────────────────────────────────────────────────────────── */

static const char *uw_reason(int s) {
    switch (s) {
    case 200: return "OK"; case 206: return "Partial Content";
    case 301: return "Moved Permanently"; case 304: return "Not Modified";
    case 400: return "Bad Request"; case 403: return "Forbidden"; case 404: return "Not Found";
    case 405: return "Method Not Allowed"; case 413: return "Content Too Large"; case 414: return "URI Too Long";
    case 416: return "Range Not Satisfiable"; case 431: return "Request Header Fields Too Large";
    case 500: return "Internal Server Error"; case 501: return "Not Implemented";
    case 505: return "HTTP Version Not Supported";
    default: return "Error";
    }
}

/* The status line and the headers every response carries. */
static int uw_head_begin(Conn *c, int status) {
    uw_update_date();
    c->l_status = status;
    c->out_len = c->out_off = 0;
    if (uw_out_printf(c, "HTTP/1.1 %d %s\r\nDate: %s\r\n", status, uw_reason(status), date_hdr) != 0) return -1;
    if (cfg.server_header && uw_out_printf(c, "Server: %s\r\n", cfg.server_name) != 0) return -1;
    for (int i = 0; i < cfg.header.n; i++)
        if (uw_out_printf(c, "%s\r\n", cfg.header.v[i]) != 0) return -1;
    return 0;
}

static int uw_head_end(Conn *c) {
    return uw_out_printf(c, "Connection: %s\r\n\r\n", c->keep_alive ? "keep-alive" : "close");
}

/* A small answer: errors, redirects, the built-in paths. */
static void uw_simple(Conn *c, int status, const char *ctype, const char *body, int head_only, const char *extra) {
    size_t blen = body ? strlen(body) : 0;
    if (status >= 400 && status != 403 && status != 404 && status != 416) c->keep_alive = 0;
    if (uw_head_begin(c, status) != 0 ||
        (extra && uw_out_add(c, extra, strlen(extra)) != 0) ||
        uw_out_printf(c, "Content-Type: %s\r\nContent-Length: %zu\r\n", ctype, blen) != 0 ||
        (status >= 300 && uw_out_add(c, "X-Content-Type-Options: nosniff\r\n", 33) != 0) ||
        uw_head_end(c) != 0 ||
        (!head_only && blen && uw_out_add(c, body, blen) != 0)) {
        c->keep_alive = 0;
        c->out_len = 0;
    }
    c->l_bytes = head_only ? 0 : (long long)blen;
    c->state = ST_WRITE;
}

static void uw_error(Conn *c, int status, int head_only) {
    char body[96];
    snprintf(body, sizeof body, "%d %s\n", status, uw_reason(status));
    uw_simple(c, status, "text/plain; charset=utf-8", body, head_only, status == 405 ? "Allow: GET, HEAD\r\n" : NULL);
}

/* ── The built-in answers (no --root): the baseline for benchmarks ─────── */

static void uw_builtin(Conn *c, const char *path, size_t plen, int head_only) {
    if (plen == 1 && path[0] == '/') { uw_simple(c, 200, "text/plain", "Hello from U!", head_only, NULL); return; }
    if (plen == 5 && memcmp(path, "/json", 5) == 0) {
        uw_simple(c, 200, "application/json", "{\"status\":\"ok\",\"server\":\"U WebServer\"}", head_only, NULL);
        return;
    }
    if (plen == 7 && memcmp(path, "/health", 7) == 0) {
        char body[320];
        snprintf(body, sizeof body,
                 "{\"status\":\"ok\",\"requests\":%lld,\"cache_hits\":0,\"cache_misses\":0,\"uptime\":%ld,"
                 "\"connections\":%d,\"merkle_trees\":0,\"merkle_deps\":0,\"merkle_invalidations\":0}",
                 total_requests, (long)(now_s - start_time), nconns);
        uw_simple(c, 200, "application/json", body, head_only, NULL);
        return;
    }
    uw_simple(c, 404, "text/plain", "", head_only, NULL);
}

/* ── Files ──────────────────────────────────────────────────────────────── */

static int uw_have_openat2 = 1;

/*
 * Opens rel (a normalised relative path, "" for the root) under the
 * document root without ever leaving it: openat2 with RESOLVE_BENEATH (and
 * no symbolic links at all with --symlinks=never). Where the kernel has no
 * openat2, each component is opened in turn with O_NOFOLLOW, so no link is
 * followed at all. O_NONBLOCK: a FIFO in the tree must not stop the server.
 */
static int uw_open_beneath(const char *rel) {
    const char *p = *rel ? rel : ".";
    int flags = O_RDONLY | O_CLOEXEC | O_NOCTTY | O_NONBLOCK;
#if defined(__linux__) && defined(SYS_openat2)
    if (uw_have_openat2) {
        struct open_how how;
        memset(&how, 0, sizeof how);
        how.flags = (uint64_t)flags;
        how.resolve = RESOLVE_BENEATH | RESOLVE_NO_MAGICLINKS;
        if (strcmp(cfg.symlinks, "never") == 0) how.resolve |= RESOLVE_NO_SYMLINKS;
        long fd = syscall(SYS_openat2, rootfd, p, &how, sizeof how);
        if (fd >= 0 || errno != ENOSYS) return (int)fd;
        uw_have_openat2 = 0;
    }
#endif
    if (!*rel) return openat(rootfd, ".", flags);
    char buf[PATH_MAX];
    snprintf(buf, sizeof buf, "%s", rel);
    int dir = dup(rootfd);
    if (dir < 0) return -1;
    char *save = NULL;
    char *seg = strtok_r(buf, "/", &save);
    while (seg) {
        char *next = strtok_r(NULL, "/", &save);
        int fd = openat(dir, seg, (next ? (O_RDONLY | O_DIRECTORY | O_CLOEXEC) : flags) | O_NOFOLLOW);
        int e = errno;
        close(dir);
        if (fd < 0) { errno = e; return -1; }
        if (!next) return fd;
        dir = fd;
        seg = next;
    }
    close(dir);
    errno = ENOENT;
    return -1;
}

static int uw_errno_status(int e) {
    if (e == ENOENT || e == ENOTDIR || e == ELOOP || e == EXDEV || e == ENAMETOOLONG) return 404;
    if (e == EACCES || e == EPERM) return 403;
    return 500;
}

/* Percent-encodes a path for a URL: unreserved characters and '/' stay. */
static int uw_url_encode(Conn *c, const char *s, size_t n) {
    static const char hex[] = "0123456789ABCDEF";
    for (size_t i = 0; i < n; i++) {
        unsigned char ch = (unsigned char)s[i];
        if ((ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || (ch >= '0' && ch <= '9') || (ch && strchr("-._~/!$&()*+,;=:@", ch))) {
            if (uw_out_add(c, &s[i], 1) != 0) return -1;
        } else {
            char e[3] = { '%', hex[ch >> 4], hex[ch & 15] };
            if (uw_out_add(c, e, 3) != 0) return -1;
        }
    }
    return 0;
}

static int uw_html_escape(Conn *c, const char *s) {
    for (; *s; s++) {
        const char *r = NULL;
        switch (*s) { case '&': r = "&amp;"; break; case '<': r = "&lt;"; break; case '>': r = "&gt;"; break;
                      case '"': r = "&quot;"; break; case '\'': r = "&#39;"; break; default: break; }
        if (r ? uw_out_add(c, r, strlen(r)) : uw_out_add(c, s, 1)) return -1;
    }
    return 0;
}

static int uw_cmp_names(const void *a, const void *b) { return strcmp(*(char *const *)a, *(char *const *)b); }

#define UW_LIST_MAX 20000

/* A directory listing: names HTML-escaped, links percent-encoded. */
static void uw_listing(Conn *c, int dfd, const char *rel, int head_only) {
    int d2 = dup(dfd);
    DIR *d = d2 >= 0 ? fdopendir(d2) : NULL;
    if (!d) { if (d2 >= 0) close(d2); uw_error(c, 500, head_only); return; }
    char **names = NULL; size_t n = 0, cap = 0;
    int oom = 0;
    struct dirent *e;
    while ((e = readdir(d)) != NULL && n < UW_LIST_MAX) {
        if (!strcmp(e->d_name, ".") || !strcmp(e->d_name, "..")) continue;
        if (e->d_name[0] == '.' && !cfg.hidden) continue;
        struct stat st;
        int isdir = fstatat(dirfd(d), e->d_name, &st, AT_SYMLINK_NOFOLLOW) == 0 && S_ISDIR(st.st_mode);
        if (n == cap) {
            size_t nc = cap ? cap * 2 : 64;
            char **nn = (char **)realloc(names, nc * sizeof(char *));
            if (!nn) { oom = 1; break; }
            names = nn; cap = nc;
        }
        size_t l = strlen(e->d_name);
        names[n] = (char *)malloc(l + 2);
        if (!names[n]) { oom = 1; break; }
        memcpy(names[n], e->d_name, l);
        names[n][l] = isdir ? '/' : '\0';
        names[n][l + 1] = '\0';
        n++;
    }
    closedir(d);
    if (n) qsort(names, n, sizeof(char *), uw_cmp_names);
    /* The body is built apart: the head, written first, needs its length. */
    Conn body; memset(&body, 0, sizeof body);
    int bad = oom;
    if (!bad) bad = uw_out_add(&body, "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Index of /", 67)
                 || uw_html_escape(&body, rel) || uw_out_add(&body, "</title></head>\n<body><h1>Index of /", 36)
                 || uw_html_escape(&body, rel) || uw_out_add(&body, "</h1>\n<ul>\n", 11);
    if (*rel && !bad) bad = uw_out_add(&body, "<li><a href=\"../\">../</a></li>\n", 31);
    for (size_t i = 0; i < n && !bad; i++)
        bad = uw_out_add(&body, "<li><a href=\"./", 15) || uw_url_encode(&body, names[i], strlen(names[i]))
           || uw_out_add(&body, "\">", 2) || uw_html_escape(&body, names[i]) || uw_out_add(&body, "</a></li>\n", 10);
    if (!bad) bad = uw_out_add(&body, "</ul></body></html>\n", 20);
    for (size_t i = 0; i < n; i++) free(names[i]);
    free(names);
    if (bad) { free(body.out); uw_error(c, 500, head_only); return; }
    if (uw_head_begin(c, 200) != 0
        || uw_out_printf(c, "Content-Type: text/html; charset=utf-8\r\nContent-Length: %zu\r\nX-Content-Type-Options: nosniff\r\n", body.out_len) != 0
        || uw_head_end(c) != 0 || (!head_only && uw_out_add(c, body.out, body.out_len) != 0)) {
        free(body.out); c->keep_alive = 0; uw_error(c, 500, head_only); return;
    }
    c->l_bytes = head_only ? 0 : (long long)body.out_len;
    free(body.out);
    c->state = ST_WRITE;
}

/* Does an If-None-Match list hold this entity tag (weak comparison)? */
static int uw_etag_match(UwStr inm, const char *etag) {
    size_t el = strlen(etag), i = 0;
    while (i < inm.n) {
        while (i < inm.n && (inm.p[i] == ' ' || inm.p[i] == '\t' || inm.p[i] == ',')) i++;
        size_t s = i;
        while (i < inm.n && inm.p[i] != ',') i++;
        size_t e = i;
        while (e > s && (inm.p[e - 1] == ' ' || inm.p[e - 1] == '\t')) e--;
        if (e - s == 1 && inm.p[s] == '*') return 1;
        if (e - s > 2 && inm.p[s] == 'W' && inm.p[s + 1] == '/') s += 2;
        if (e - s == el && memcmp(inm.p + s, etag, el) == 0) return 1;
    }
    return 0;
}

static int uw_parse_http_date(UwStr s, time_t *out) {
    char buf[64];
    if (s.n == 0 || s.n >= sizeof buf) return -1;
    memcpy(buf, s.p, s.n); buf[s.n] = '\0';
    struct tm tm; memset(&tm, 0, sizeof tm);
    const char *end = strptime(buf, "%a, %d %b %Y %H:%M:%S GMT", &tm);
    if (!end || *end) return -1;
    time_t t = timegm(&tm);
    if (t == (time_t)-1) return -1;
    *out = t;
    return 0;
}

/*
 * A single byte range "bytes=a-b", "bytes=a-" or "bytes=-n" of a file of
 * `size` bytes. Returns 1 with [*from, *to] (inclusive), 0 to ignore the
 * header (several ranges, another unit, malformed), -1 unsatisfiable.
 */
static int uw_parse_range(UwStr r, long long size, long long *from, long long *to) {
    if (r.n < 7 || strncasecmp(r.p, "bytes=", 6) != 0) return 0;
    const char *p = r.p + 6, *e = r.p + r.n;
    while (p < e && (*p == ' ' || *p == '\t')) p++;
    if (memchr(p, ',', (size_t)(e - p))) return 0;
    long long a = -1, b = -1;
    int digits = 0;
    if (p < e && *p != '-') {
        a = 0;
        while (p < e && *p >= '0' && *p <= '9') { if (a > (LLONG_MAX - 9) / 10) return 0; a = a * 10 + (*p++ - '0'); digits++; }
        if (!digits) return 0;
    }
    if (p >= e || *p != '-') return 0;
    p++;
    digits = 0;
    if (p < e && *p >= '0' && *p <= '9') {
        b = 0;
        while (p < e && *p >= '0' && *p <= '9') { if (b > (LLONG_MAX - 9) / 10) return 0; b = b * 10 + (*p++ - '0'); digits++; }
    }
    while (p < e && (*p == ' ' || *p == '\t')) p++;
    if (p != e) return 0;
    if (a < 0 && b < 0) return 0;
    if (a < 0) {                      /* the last b bytes */
        if (b == 0 || size == 0) return -1;
        *from = b >= size ? 0 : size - b;
        *to = size - 1;
        return 1;
    }
    if (b >= 0 && b < a) return 0;
    if (a >= size) return -1;
    *from = a;
    *to = (b < 0 || b >= size) ? size - 1 : b;
    return 1;
}

/* Does Accept-Encoding allow gzip (and not with q=0)? */
static int uw_accepts_gzip(UwStr ae) {
    size_t i = 0;
    while (i < ae.n) {
        while (i < ae.n && (ae.p[i] == ' ' || ae.p[i] == '\t' || ae.p[i] == ',')) i++;
        size_t s = i;
        while (i < ae.n && ae.p[i] != ',') i++;
        size_t e = i, t = s;
        while (t < e && ae.p[t] != ';' && ae.p[t] != ' ' && ae.p[t] != '\t') t++;
        int is_gzip = (t - s == 4 && strncasecmp(ae.p + s, "gzip", 4) == 0);
        if (!is_gzip) continue;
        /* q=0, q=0.0, q=0.00 or q=0.000 refuses it */
        for (size_t k = t; k + 1 < e; k++) {
            if ((ae.p[k] == 'q' || ae.p[k] == 'Q') && ae.p[k + 1] == '=') {
                size_t z = k + 2;
                if (z >= e || ae.p[z] != '0') return 1;
                z++;
                if (z < e && ae.p[z] == '.') { z++; while (z < e && ae.p[z] == '0') z++; }
                while (z < e && (ae.p[z] == ' ' || ae.p[z] == '\t')) z++;
                return z < e && ae.p[z] != ';' ? 1 : 0;
            }
        }
        return 1;
    }
    return 0;
}

static void uw_serve_file(Conn *c, const UwRequest *r, int fd, const struct stat *st, const char *name, int gz, int head_only) {
    const char *type = uw_mime_lookup(name);
    if (!type) type = cfg.default_type;
    char ctype[320];
    int textual = strncmp(type, "text/", 5) == 0 || !strcmp(type, "application/javascript") || !strcmp(type, "application/json")
                  || !strcmp(type, "image/svg+xml") || !strcmp(type, "application/xml") || !strcmp(type, "application/manifest+json");
    if (textual && cfg.charset && *cfg.charset && !strchr(type, ';')) snprintf(ctype, sizeof ctype, "%s; charset=%s", type, cfg.charset);
    else snprintf(ctype, sizeof ctype, "%s", type);
    char etag[80] = "";
    if (cfg.etag)
        snprintf(etag, sizeof etag, "\"%llx-%llx%s\"",
                 (unsigned long long)st->st_mtim.tv_sec * 1000000000ULL + (unsigned long long)st->st_mtim.tv_nsec,
                 (unsigned long long)st->st_size, gz ? "-gz" : "");
    char lastmod[64];
    struct tm tm; gmtime_r(&st->st_mtim.tv_sec, &tm);
    strftime(lastmod, sizeof lastmod, "%a, %d %b %Y %H:%M:%S GMT", &tm);
    long long size = (long long)st->st_size;

    int not_modified = 0;
    if (r->inm.n) { if (cfg.etag && uw_etag_match(r->inm, etag)) not_modified = 1; }
    else if (r->ims.n) { time_t ims; if (uw_parse_http_date(r->ims, &ims) == 0 && st->st_mtim.tv_sec <= ims) not_modified = 1; }

    long long from = 0, to = size - 1;
    int partial = 0;
    if (!not_modified && r->range.n) {
        int use = 1;
        if (r->if_range.n)
            use = (cfg.etag && r->if_range.n == strlen(etag) && memcmp(r->if_range.p, etag, r->if_range.n) == 0)
               || (r->if_range.n == strlen(lastmod) && memcmp(r->if_range.p, lastmod, r->if_range.n) == 0);
        if (use) {
            int pr = uw_parse_range(r->range, size, &from, &to);
            if (pr < 0) {
                close(fd);
                char extra[96];
                snprintf(extra, sizeof extra, "Content-Range: bytes */%lld\r\n", size);
                uw_simple(c, 416, "text/plain; charset=utf-8", "416 Range Not Satisfiable\n", head_only, extra);
                return;
            }
            partial = pr == 1;
            if (!partial) { from = 0; to = size - 1; }
        }
    }
    int status = not_modified ? 304 : partial ? 206 : 200;
    int bad = uw_head_begin(c, status) != 0;
    if (!bad && etag[0]) bad = uw_out_printf(c, "ETag: %s\r\n", etag) != 0;
    if (!bad) bad = uw_out_printf(c, "Last-Modified: %s\r\n", lastmod) != 0;
    if (!bad && cfg.cache_control && *cfg.cache_control) bad = uw_out_printf(c, "Cache-Control: %s\r\n", cfg.cache_control) != 0;
    if (!bad && cfg.gzip_static) bad = uw_out_add(c, "Vary: Accept-Encoding\r\n", 23) != 0;
    c->l_bytes = 0;
    if (!not_modified && !bad) {
        long long len = size > 0 ? to - from + 1 : 0;
        bad = uw_out_printf(c, "Content-Type: %s\r\nContent-Length: %lld\r\nAccept-Ranges: bytes\r\nX-Content-Type-Options: nosniff\r\n", ctype, len) != 0;
        if (!bad && gz) bad = uw_out_add(c, "Content-Encoding: gzip\r\n", 24) != 0;
        if (!bad && partial) bad = uw_out_printf(c, "Content-Range: bytes %lld-%lld/%lld\r\n", from, to, size) != 0;
        if (!bad && !head_only && len > 0) { c->file_fd = fd; c->file_off = (off_t)from; c->file_end = (off_t)to + 1; fd = -1; c->l_bytes = len; }
    }
    if (!bad) bad = uw_head_end(c) != 0;
    if (fd >= 0) close(fd);
    if (bad) {
        if (c->file_fd >= 0) { close(c->file_fd); c->file_fd = -1; }
        c->keep_alive = 0;
        uw_error(c, 500, head_only);
        return;
    }
    c->state = ST_WRITE;
}

static void uw_serve_static(Conn *c, const UwRequest *r, int head_only) {
    char rel[PATH_MAX];
    int slash = 0;
    int nr = uw_normalize_path(r->path.p, r->path.n, rel, sizeof rel - 256, cfg.hidden, &slash);
    if (nr == UW_PATH_BAD) { uw_error(c, 400, head_only); return; }
    if (nr == UW_PATH_HIDDEN) { uw_error(c, 404, head_only); return; }
    if (nr == UW_PATH_LONG) { uw_error(c, 414, head_only); return; }
    int fd = uw_open_beneath(rel);
    if (fd < 0) { uw_error(c, uw_errno_status(errno), head_only); return; }
    struct stat st;
    if (fstat(fd, &st) != 0) { close(fd); uw_error(c, 500, head_only); return; }
    if (S_ISDIR(st.st_mode)) {
        if (!slash && *rel) {
            /* A directory is answered at its name with a slash: redirect there. */
            close(fd);
            Conn loc; memset(&loc, 0, sizeof loc);
            int bad = uw_out_add(&loc, "Location: /", 11) || uw_url_encode(&loc, rel, strlen(rel)) || uw_out_add(&loc, "/", 1);
            if (!bad && r->query.n) bad = uw_out_add(&loc, "?", 1) || uw_out_add(&loc, r->query.p, r->query.n);
            if (!bad) bad = uw_out_add(&loc, "\r\n", 2);
            if (bad) { free(loc.out); uw_error(c, 414, head_only); return; }
            uw_simple(c, 301, "text/plain; charset=utf-8", "301 Moved Permanently\n", head_only, loc.out);
            free(loc.out);
            return;
        }
        char names[1024];
        snprintf(names, sizeof names, "%s", cfg.index);
        char *save = NULL;
        for (char *nm = strtok_r(names, ",", &save); nm; nm = strtok_r(NULL, ",", &save)) {
            while (*nm == ' ') nm++;
            if (!*nm) continue;
            char path[PATH_MAX];
            int pl = snprintf(path, sizeof path, "%s%s%s", rel, *rel ? "/" : "", nm);
            if (pl < 0 || (size_t)pl >= sizeof path) continue;
            int ifd = uw_open_beneath(path);
            if (ifd < 0) continue;
            struct stat ist;
            if (fstat(ifd, &ist) == 0 && S_ISREG(ist.st_mode)) {
                close(fd);
                uw_serve_file(c, r, ifd, &ist, path, 0, head_only);
                return;
            }
            close(ifd);
        }
        if (cfg.dirlist) { uw_listing(c, fd, rel, head_only); close(fd); return; }
        close(fd);
        uw_error(c, 403, head_only);
        return;
    }
    if (!S_ISREG(st.st_mode)) { close(fd); uw_error(c, 403, head_only); return; }   /* FIFOs, devices, sockets */
    if (slash) { close(fd); uw_error(c, 404, head_only); return; }                  /* "file/" names no file */
    if (cfg.gzip_static && uw_accepts_gzip(r->accept_encoding)) {
        char gzp[PATH_MAX];
        int gl = snprintf(gzp, sizeof gzp, "%s.gz", rel);
        if (gl > 0 && (size_t)gl < sizeof gzp) {
            int gfd = uw_open_beneath(gzp);
            struct stat gst;
            if (gfd >= 0 && fstat(gfd, &gst) == 0 && S_ISREG(gst.st_mode)) {
                close(fd);
                uw_serve_file(c, r, gfd, &gst, rel, 1, head_only);
                return;
            }
            if (gfd >= 0) close(gfd);
        }
    }
    uw_serve_file(c, r, fd, &st, rel, 0, head_only);
}

/* ── One request ────────────────────────────────────────────────────────── */

static int uw_known_method(UwStr m) {
    static const char *known[] = { "POST", "PUT", "DELETE", "PATCH", "OPTIONS", "TRACE", "CONNECT" };
    for (size_t i = 0; i < sizeof known / sizeof known[0]; i++)
        if (m.n == strlen(known[i]) && memcmp(m.p, known[i], m.n) == 0) return 1;
    return 0;
}

static void uw_log_begin(Conn *c, const UwRequest *r) {
    clock_gettime(CLOCK_MONOTONIC, &c->l_start);
    c->l_logged = 0; c->l_bytes = 0; c->l_status = 0;
    if (access_fd < 0) return;
    if (r) {
        uw_log_escape(c->l_method, sizeof c->l_method, r->method.p, r->method.n);
        uw_log_escape(c->l_target, sizeof c->l_target, r->target.p, r->target.n);
        uw_log_escape(c->l_referer, sizeof c->l_referer, r->referer.p, r->referer.n);
        uw_log_escape(c->l_agent, sizeof c->l_agent, r->user_agent.p, r->user_agent.n);
        c->l_minor = r->minor;
    } else {
        snprintf(c->l_method, sizeof c->l_method, "-");
        c->l_target[0] = c->l_referer[0] = c->l_agent[0] = '\0';
        c->l_minor = 1;
    }
}

static void uw_handle(Conn *c, const UwRequest *r) {
    uw_log_begin(c, r);
    total_requests++;
    c->requests++;
    c->keep_alive = r->minor >= 1 ? !r->conn_close : (r->conn_keepalive && !r->conn_close);
    if (cfg.keepalive_timeout == 0 || stopping) c->keep_alive = 0;
    if (cfg.max_requests && c->requests >= cfg.max_requests) c->keep_alive = 0;
    int head_only = r->method.n == 4 && memcmp(r->method.p, "HEAD", 4) == 0;
    int get = r->method.n == 3 && memcmp(r->method.p, "GET", 3) == 0;

    if (r->te.n) { c->keep_alive = 0; uw_error(c, 501, head_only); return; }     /* chunked bodies are not read */
    if (r->has_cl && r->content_length > cfg.max_body) { c->keep_alive = 0; uw_error(c, 413, head_only); return; }
    if (r->has_cl) c->discard = r->content_length;
    if (!get && !head_only) { c->keep_alive = 0; uw_error(c, uw_known_method(r->method) ? 405 : 501, 0); return; }
    if (r->path.n == 1 && r->path.p[0] == '*') { uw_error(c, 400, head_only); return; }
    if (!cfg.root) uw_builtin(c, r->path.p, r->path.n, head_only);
    else uw_serve_static(c, r, head_only);
}

static void uw_access_log(Conn *c) {
    c->l_logged = 1;
    if (access_fd < 0 || !c->l_status) return;
    char line[4096], ts[64];
    struct timespec t1; clock_gettime(CLOCK_MONOTONIC, &t1);
    long ms = (long)((t1.tv_sec - c->l_start.tv_sec) * 1000 + (t1.tv_nsec - c->l_start.tv_nsec) / 1000000);
    time_t t = time(NULL);
    struct tm tm; gmtime_r(&t, &tm);
    int n;
    if (strcmp(cfg.access_format, "json") == 0) {
        /* The fields hold only printable ASCII (escaped as \xHH); JSON escapes '"' and '\'. */
        char m[100], tg[2300], rf[1100], ua[1100];
        uw_json_escape(m, sizeof m, c->l_method, strlen(c->l_method));
        uw_json_escape(tg, sizeof tg, c->l_target, strlen(c->l_target));
        uw_json_escape(rf, sizeof rf, c->l_referer, strlen(c->l_referer));
        uw_json_escape(ua, sizeof ua, c->l_agent, strlen(c->l_agent));
        strftime(ts, sizeof ts, "%Y-%m-%dT%H:%M:%SZ", &tm);
        n = snprintf(line, sizeof line,
                     "{\"time\":\"%s\",\"remote\":\"%s\",\"method\":\"%s\",\"target\":\"%s\",\"protocol\":\"HTTP/1.%d\","
                     "\"status\":%d,\"bytes\":%lld,\"referer\":\"%s\",\"user_agent\":\"%s\",\"duration_ms\":%ld,\"tls\":%s}\n",
                     ts, c->peer, m, tg, c->l_minor, c->l_status, c->l_bytes, rf, ua, ms, c->ssl ? "true" : "false");
    } else {
        strftime(ts, sizeof ts, "%d/%b/%Y:%H:%M:%S +0000", &tm);
        char bytes[32];
        if (c->l_bytes > 0) snprintf(bytes, sizeof bytes, "%lld", c->l_bytes); else snprintf(bytes, sizeof bytes, "-");
        if (strcmp(cfg.access_format, "common") == 0)
            n = snprintf(line, sizeof line, "%s - - [%s] \"%s %s HTTP/1.%d\" %d %s\n",
                         c->peer, ts, c->l_method, c->l_target, c->l_minor, c->l_status, bytes);
        else
            n = snprintf(line, sizeof line, "%s - - [%s] \"%s %s HTTP/1.%d\" %d %s \"%s\" \"%s\"\n",
                         c->peer, ts, c->l_method, c->l_target, c->l_minor, c->l_status, bytes,
                         c->l_referer[0] ? c->l_referer : "-", c->l_agent[0] ? c->l_agent : "-");
    }
    if (n <= 0) return;
    if ((size_t)n >= sizeof line) { n = (int)sizeof line - 1; line[n - 1] = '\n'; }
    uw_write_all(access_fd, line, (size_t)n);
}

/* ── The connection state machine ───────────────────────────────────────── */

#define UW_READ_CLOSED  (-1)
#define UW_READ_WAIT      0
#define UW_READ_FULL      1

/* Reads what the socket has into the input buffer, until it would block or the buffer is full. */
static int uw_read_some(Conn *c) {
    while (c->in_len < c->in_cap) {
        size_t room = c->in_cap - c->in_len;
        ssize_t n;
        if (c->ssl) {
            n = uw_tls_read(c->ssl, c->in + c->in_len, room > INT_MAX ? INT_MAX : (int)room);
            if (n == UW_TLS_WANT_READ || n == UW_TLS_WANT_WRITE) { c->ssl_want = (int)n; return UW_READ_WAIT; }
            if (n <= 0) return UW_READ_CLOSED;
        } else {
            n = read(c->fd, c->in + c->in_len, room);
            if (n < 0) {
                if (errno == EAGAIN || errno == EWOULDBLOCK) return UW_READ_WAIT;
                if (errno == EINTR) continue;
                return UW_READ_CLOSED;
            }
            if (n == 0) return UW_READ_CLOSED;
        }
        c->in_len += (size_t)n;
    }
    return UW_READ_FULL;
}

static void uw_consume(Conn *c, size_t n) {
    if (n >= c->in_len) { c->in_len = 0; return; }
    memmove(c->in, c->in + n, c->in_len - n);
    c->in_len -= n;
}

/*
 * Handles what is in the input buffer: skips a body still due, then reads
 * at most one request head. Leaves the connection in ST_WRITE with an
 * answer, or in ST_READ waiting for more. Returns -1 to close.
 */
static int uw_process_input(Conn *c) {
    if (c->discard > 0) {
        size_t take = c->in_len < (unsigned long long)c->discard ? c->in_len : (size_t)c->discard;
        uw_consume(c, take);
        c->discard -= (long long)take;
        if (c->discard > 0) { uw_deadline(c, cfg.read_timeout); return 0; }
    }
    if (c->in_len == 0) {
        c->head_started = 0;
        if (stopping) return -1;
        uw_deadline(c, c->requests ? cfg.keepalive_timeout : cfg.header_timeout);
        return 0;
    }
    if (!c->head_started) { c->head_started = 1; uw_deadline(c, cfg.header_timeout); }
    UwRequest r;
    int n = uw_parse_request(c->in, c->in_len, (size_t)cfg.max_header, (size_t)cfg.max_uri, &r);
    if (n == UW_NEED_MORE && c->in_len >= c->in_cap) n = -431;
    if (n == UW_NEED_MORE) return 0;
    if (n < 0) {
        uw_log_begin(c, NULL);
        uw_log(UW_LOG_DEBUG, "%s: a malformed request, answered %d", c->peer, -n);
        c->keep_alive = 0;
        c->in_len = 0;
        c->discard = 0;
        uw_error(c, -n, 0);
        return 0;
    }
    c->head_started = 0;
    uw_handle(c, &r);
    uw_consume(c, (size_t)n);         /* only now: r points into the buffer */
    if (!c->keep_alive) c->discard = 0;
    return 0;
}

/* Sends what is due. 1 done, 0 waiting for the socket, -1 close. */
static int uw_write_some(Conn *c) {
    while (c->out_off < c->out_len) {
        size_t left = c->out_len - c->out_off;
        ssize_t w;
        if (c->ssl) {
            w = uw_tls_write(c->ssl, c->out + c->out_off, left > INT_MAX ? INT_MAX : (int)left);
            if (w == UW_TLS_WANT_READ || w == UW_TLS_WANT_WRITE) { c->ssl_want = (int)w; return 0; }
            if (w <= 0) return -1;
        } else {
            w = write(c->fd, c->out + c->out_off, left);
            if (w < 0) { if (errno == EAGAIN || errno == EWOULDBLOCK) return 0; if (errno == EINTR) continue; return -1; }
        }
        c->out_off += (size_t)w;
        total_bytes += w;
        uw_deadline(c, cfg.write_timeout);
    }
    while (c->file_fd >= 0 && c->file_off < c->file_end) {
        size_t left = (size_t)(c->file_end - c->file_off);
        if (c->ssl) {
            if (c->tbuf_off >= c->tbuf_len) {
                if (!c->tbuf) { c->tbuf = (char *)malloc(16384); if (!c->tbuf) return -1; }
                size_t want = left < 16384 ? left : 16384;
                ssize_t r = pread(c->file_fd, c->tbuf, want, c->file_off);
                if (r < 0 && errno == EINTR) continue;
                if (r <= 0) return -1;                /* the file shrank: the length sent is wrong now; close */
                c->tbuf_len = (size_t)r; c->tbuf_off = 0;
            }
            int w = uw_tls_write(c->ssl, c->tbuf + c->tbuf_off, (int)(c->tbuf_len - c->tbuf_off));
            if (w == UW_TLS_WANT_READ || w == UW_TLS_WANT_WRITE) { c->ssl_want = w; return 0; }
            if (w <= 0) return -1;
            c->tbuf_off += (size_t)w;
            c->file_off += w;
            total_bytes += w;
        } else {
            ssize_t w = uw_sendfile_step(c->fd, c->file_fd, &c->file_off, left > (1u << 20) ? (1u << 20) : left);
            if (w < 0) { if (errno == EAGAIN || errno == EWOULDBLOCK) return 0; if (errno == EINTR) continue; return -1; }
            if (w == 0) return -1;                    /* the file shrank while it was sent */
            total_bytes += w;
        }
        uw_deadline(c, cfg.write_timeout);
    }
    if (c->file_fd >= 0) { close(c->file_fd); c->file_fd = -1; }
    c->tbuf_len = c->tbuf_off = 0;
    return 1;
}

static void uw_start_linger(Conn *c) {
    /* Stop sending, then read what the client still sends for a moment, so
     * the kernel does not reset the connection before the answer arrived. */
    shutdown(c->fd, SHUT_WR);
    c->state = ST_LINGER;
    c->lingered = 0;
    c->deadline = now_s + 2;
    uw_set_events(c, EPOLLIN | EPOLLRDHUP);
}

/* Runs the connection until it has to wait for its socket. */
static void uw_conn_run(Conn *c, uint32_t ev) {
    if (ev & EPOLLERR) { uw_conn_close(c); return; }
    for (int guard = 0; guard < 1000000; guard++) {
        switch (c->state) {
        case ST_HANDSHAKE: {
            int h = uw_tls_handshake(c->ssl);
            if (h == 1) { c->state = ST_READ; c->ssl_want = 0; uw_deadline(c, cfg.header_timeout); continue; }
            if (h == UW_TLS_WANT_READ) { uw_set_events(c, EPOLLIN); return; }
            if (h == UW_TLS_WANT_WRITE) { uw_set_events(c, EPOLLOUT); return; }
            uw_log(UW_LOG_DEBUG, "%s: the TLS handshake failed", c->peer);
            uw_conn_close(c);
            return;
        }
        case ST_READ: {
            c->ssl_want = 0;
            int rr = uw_read_some(c);
            if (rr == UW_READ_CLOSED && c->in_len == 0) { uw_conn_close(c); return; }
            if (uw_process_input(c) < 0) { uw_conn_close(c); return; }
            if (c->state != ST_READ) continue;                  /* an answer to send */
            if (rr == UW_READ_CLOSED) { uw_conn_close(c); return; }   /* gone with half a request */
            if (rr == UW_READ_FULL) continue;                    /* room again after a body was skipped */
            uw_set_events(c, c->ssl_want == UW_TLS_WANT_WRITE ? EPOLLOUT : EPOLLIN);
            return;
        }
        case ST_WRITE: {
            c->ssl_want = 0;
            int w = uw_write_some(c);
            if (w < 0) { uw_conn_close(c); return; }
            if (w == 0) { uw_set_events(c, c->ssl_want == UW_TLS_WANT_READ ? EPOLLIN : EPOLLOUT); return; }
            uw_access_log(c);
            c->l_status = 0;
            c->out_len = c->out_off = 0;
            if (c->out_cap > 65536) { free(c->out); c->out = NULL; c->out_cap = 0; }
            if (!c->keep_alive) {
                if (c->ssl) { SSL_shutdown(c->ssl); uw_conn_close(c); return; }
                uw_start_linger(c);
                return;
            }
            c->state = ST_READ;
            continue;       /* a pipelined request may be in the buffer already */
        }
        case ST_LINGER: {
            char junk[4096];
            for (;;) {
                ssize_t n = read(c->fd, junk, sizeof junk);
                if (n > 0) { c->lingered += (size_t)n; if (c->lingered > 256 * 1024) { uw_conn_close(c); return; } continue; }
                if (n < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) return;
                if (n < 0 && errno == EINTR) continue;
                uw_conn_close(c);
                return;
            }
        }
        default:
            uw_conn_close(c);
            return;
        }
    }
    uw_conn_close(c);   /* not reached: the guard against a loop that makes no progress */
}

static void uw_accept(UwListener *l) {
    for (int k = 0; k < 256; k++) {
        struct sockaddr_storage ss;
        socklen_t sl = sizeof ss;
        int fd = accept4(l->fd, (struct sockaddr *)&ss, &sl, SOCK_NONBLOCK | SOCK_CLOEXEC);
        if (fd < 0) {
            if (errno == EMFILE || errno == ENFILE || errno == ENOBUFS || errno == ENOMEM) {
                /* No descriptor for it: stop asking for a second rather than spin on it. */
                uw_log(UW_LOG_WARN, "cannot accept on %s: %s; pausing for a second", l->text, strerror(errno));
                epoll_ctl(epfd, EPOLL_CTL_DEL, l->fd, NULL);
                l->paused_until = now_s + 1;
            }
            return;
        }
        if (fd >= conns_cap || nconns >= cfg.max_conns || stopping) {
            uw_log(UW_LOG_DEBUG, "a connection on %s turned away: %d open, at most %d", l->text, nconns, cfg.max_conns);
            close(fd);
            continue;
        }
        Conn *c = (Conn *)calloc(1, sizeof(Conn));
        char *in = (char *)malloc((size_t)cfg.max_header);
        if (!c || !in) { free(c); free(in); close(fd); continue; }
        c->fd = fd; c->in = in; c->in_cap = (size_t)cfg.max_header; c->file_fd = -1;
        char host[INET6_ADDRSTRLEN] = "?";
        if (ss.ss_family == AF_INET) inet_ntop(AF_INET, &((struct sockaddr_in *)&ss)->sin_addr, host, sizeof host);
        else if (ss.ss_family == AF_INET6) inet_ntop(AF_INET6, &((struct sockaddr_in6 *)&ss)->sin6_addr, host, sizeof host);
        snprintf(c->peer, sizeof c->peer, "%s", host);
        int one = 1;
        setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof one);
        if (l->tls) {
            c->ssl = SSL_new(ssl_ctx);
            if (!c->ssl || SSL_set_fd(c->ssl, fd) != 1) { if (c->ssl) SSL_free(c->ssl); free(in); free(c); close(fd); continue; }
            SSL_set_accept_state(c->ssl);
            c->state = ST_HANDSHAKE;
            uw_deadline(c, cfg.handshake_timeout);
        } else {
            c->state = ST_READ;
            uw_deadline(c, cfg.header_timeout);
        }
        struct epoll_event e = { .events = EPOLLIN, .data.fd = fd };
        c->events = EPOLLIN;
        if (epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &e) != 0) { if (c->ssl) SSL_free(c->ssl); free(in); free(c); close(fd); continue; }
        conns[fd] = c;
        nconns++;
    }
}

static int uw_listener_of(int fd) {
    for (int i = 0; i < nlisteners; i++) if (listeners[i].fd == fd) return i;
    return -1;
}

static void uw_on_signal(int s) { (void)s; stopping = 1; }

/* The event loop of one process. */
static int uw_serve(void) {
    epfd = epoll_create1(EPOLL_CLOEXEC);
    if (epfd < 0) { uw_log(UW_LOG_ERROR, "epoll: %s", strerror(errno)); return 1; }
    for (int i = 0; i < nlisteners; i++) {
        struct epoll_event e = { .events = EPOLLIN | EPOLLEXCLUSIVE, .data.fd = listeners[i].fd };
        if (epoll_ctl(epfd, EPOLL_CTL_ADD, listeners[i].fd, &e) != 0) { uw_log(UW_LOG_ERROR, "epoll: %s", strerror(errno)); return 1; }
    }
    struct epoll_event events[256];
    time_t last_sweep = 0, stop_at = 0;
    for (;;) {
        uw_update_date();
        if (stopping && !stop_at) {
            stop_at = now_s + 10;
            for (int i = 0; i < nlisteners; i++) {
                if (listeners[i].fd < 0) continue;
                epoll_ctl(epfd, EPOLL_CTL_DEL, listeners[i].fd, NULL);
                close(listeners[i].fd);
                listeners[i].fd = -1;
            }
            /* Idle connections go now; one in the middle of a request finishes it. */
            for (int fd = 0; fd < conns_cap; fd++) {
                Conn *c = conns[fd];
                if (c && c->state == ST_READ && c->in_len == 0 && c->discard == 0) uw_conn_close(c);
            }
        }
        if (stopping && (nconns == 0 || now_s >= stop_at)) break;
        int n = epoll_wait(epfd, events, 256, 1000);
        uw_update_date();
        for (int i = 0; i < n; i++) {
            int fd = events[i].data.fd;
            int li = uw_listener_of(fd);
            if (li >= 0) { uw_accept(&listeners[li]); continue; }
            if (fd >= 0 && fd < conns_cap && conns[fd]) uw_conn_run(conns[fd], events[i].events);
        }
        if (now_s != last_sweep) {
            last_sweep = now_s;
            for (int fd = 0; fd < conns_cap; fd++) {
                Conn *c = conns[fd];
                if (c && c->deadline && now_s >= c->deadline) {
                    uw_log(UW_LOG_DEBUG, "%s: timed out (%s)", c->peer,
                           c->state == ST_HANDSHAKE ? "TLS handshake" : c->state == ST_WRITE ? "sending" :
                           c->state == ST_LINGER ? "closing" : c->head_started ? "request head" :
                           c->discard ? "request body" : "idle");
                    uw_conn_close(c);
                }
            }
            for (int i = 0; i < nlisteners; i++) {
                if (listeners[i].paused_until && now_s >= listeners[i].paused_until && listeners[i].fd >= 0) {
                    struct epoll_event e = { .events = EPOLLIN | EPOLLEXCLUSIVE, .data.fd = listeners[i].fd };
                    epoll_ctl(epfd, EPOLL_CTL_ADD, listeners[i].fd, &e);
                    listeners[i].paused_until = 0;
                }
            }
        }
    }
    for (int fd = 0; fd < conns_cap; fd++) if (conns[fd]) uw_conn_close(conns[fd]);
    close(epfd);
    return 0;
}

/* ════════════════════════════════════════════════════════════════════════
 * Start-up: files, privileges, processes
 * ════════════════════════════════════════════════════════════════════════ */

/* Opens a log file for appending; "-" is the given standard descriptor. */
static int uw_open_log(const char *spec, int std_fd) {
    if (strcmp(spec, "-") == 0) return std_fd;
    int fd = open(spec, O_WRONLY | O_APPEND | O_CREAT | O_CLOEXEC | O_NOFOLLOW | O_NOCTTY, 0640);
    if (fd < 0) return -1;
    struct stat st;
    if (fstat(fd, &st) != 0 || !S_ISREG(st.st_mode)) { close(fd); errno = EINVAL; return -1; }
    return fd;
}

static uid_t run_uid;
static gid_t run_gid;
static int drop_privileges = 0;

static int uw_lookup_identity(char *err, size_t errlen) {
    drop_privileges = 0;
    if (!cfg.user && !cfg.group) return 0;
    run_uid = geteuid(); run_gid = getegid();
    long long id;
    if (cfg.user) {
        struct passwd *pw = getpwnam(cfg.user);
        if (!pw && uw_parse_long(cfg.user, 0, 0x7fffffff, &id) == 0) pw = getpwuid((uid_t)id);
        if (!pw) { snprintf(err, errlen, "--user: no such user '%s'", cfg.user); return -1; }
        run_uid = pw->pw_uid; run_gid = pw->pw_gid;
        if (run_uid == 0) { snprintf(err, errlen, "--user=%s is root; name an unprivileged user", cfg.user); return -1; }
    }
    if (cfg.group) {
        struct group *gr = getgrnam(cfg.group);
        if (!gr && uw_parse_long(cfg.group, 0, 0x7fffffff, &id) == 0) gr = getgrgid((gid_t)id);
        if (!gr) { snprintf(err, errlen, "--group: no such group '%s'", cfg.group); return -1; }
        run_gid = gr->gr_gid;
    }
    drop_privileges = 1;
    return 0;
}

/* chroot first, then the supplementary groups, the group and the user;
 * then the proof that root cannot be had back. */
static int uw_drop(void) {
    if (cfg.chroot_dir) {
        if (chdir(cfg.chroot_dir) != 0 || chroot(".") != 0 || chdir("/") != 0) {
            uw_log(UW_LOG_ERROR, "cannot change the root directory to %s: %s", cfg.chroot_dir, strerror(errno));
            return -1;
        }
    }
    if (!drop_privileges) return 0;
    if (geteuid() != 0) {
        if (run_uid == geteuid() && run_gid == getegid()) return 0;
        uw_log(UW_LOG_ERROR, "only root can change to another --user or --group");
        return -1;
    }
    if (setgroups(1, &run_gid) != 0) { uw_log(UW_LOG_ERROR, "setgroups: %s", strerror(errno)); return -1; }
    if (setresgid(run_gid, run_gid, run_gid) != 0) { uw_log(UW_LOG_ERROR, "setresgid(%u): %s", (unsigned)run_gid, strerror(errno)); return -1; }
    if (setresuid(run_uid, run_uid, run_uid) != 0) { uw_log(UW_LOG_ERROR, "setresuid(%u): %s", (unsigned)run_uid, strerror(errno)); return -1; }
    if (run_uid != 0 && (setuid(0) == 0 || seteuid(0) == 0)) { uw_log(UW_LOG_ERROR, "root could be had back after dropping it; stopping"); return -1; }
    uid_t ru, eu, su; gid_t rg, eg, sg;
    if (getresuid(&ru, &eu, &su) != 0 || getresgid(&rg, &eg, &sg) != 0 ||
        ru != run_uid || eu != run_uid || su != run_uid || rg != run_gid || eg != run_gid || sg != run_gid) {
        uw_log(UW_LOG_ERROR, "the user and group did not change as asked; stopping");
        return -1;
    }
#ifdef __linux__
    prctl(PR_SET_DUMPABLE, 0, 0, 0, 0);   /* no core file with the TLS key in it */
#endif
    return 0;
}

static int pid_fd = -1;

static int uw_write_pid_file(void) {
    if (!cfg.pid_file) return 0;
    int fd = open(cfg.pid_file, O_RDWR | O_CREAT | O_CLOEXEC | O_NOFOLLOW | O_NOCTTY, 0644);
    if (fd < 0) { uw_log(UW_LOG_ERROR, "cannot open the pid file %s: %s", cfg.pid_file, strerror(errno)); return -1; }
    struct stat st;
    if (fstat(fd, &st) != 0 || !S_ISREG(st.st_mode)) { uw_log(UW_LOG_ERROR, "the pid file %s is not a regular file", cfg.pid_file); close(fd); return -1; }
    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        char old[32] = "";
        ssize_t n = pread(fd, old, sizeof old - 1, 0);
        if (n > 0) { old[n] = '\0'; old[strcspn(old, "\r\n")] = '\0'; }
        uw_log(UW_LOG_ERROR, "the pid file %s is held by another running uwebserver (pid %s)", cfg.pid_file, old[0] ? old : "?");
        close(fd);
        return -1;
    }
    char buf[32];
    int n = snprintf(buf, sizeof buf, "%ld\n", (long)getpid());
    if (ftruncate(fd, 0) != 0 || pwrite(fd, buf, (size_t)n, 0) != n) {
        uw_log(UW_LOG_ERROR, "cannot write the pid file %s: %s", cfg.pid_file, strerror(errno));
        close(fd);
        return -1;
    }
    if (fchmod(fd, 0644) != 0) { /* its mode is only cosmetic: the lock is what counts */ }
    pid_fd = fd;
    return 0;
}

static void uw_remove_pid_file(void) {
    if (pid_fd < 0) return;
    if (ftruncate(pid_fd, 0) != 0) { /* the released lock still tells the next start */ }
    if (unlink(cfg.pid_file) != 0) { /* after dropping privileges the directory may not be ours */ }
    close(pid_fd);
    pid_fd = -1;
}

static int ready_pipe = -1;

/* Detaches from the terminal. The first process waits until the server says
 * it is serving (or that it could not start) and exits with that status. */
static int uw_daemonize(void) {
    int p[2];
    if (pipe2(p, O_CLOEXEC) != 0) { uw_log(UW_LOG_ERROR, "pipe: %s", strerror(errno)); return -1; }
    fflush(NULL);
    pid_t pid = fork();
    if (pid < 0) { uw_log(UW_LOG_ERROR, "fork: %s", strerror(errno)); return -1; }
    if (pid > 0) {
        close(p[1]);
        char status = 1;
        ssize_t n;
        do n = read(p[0], &status, 1); while (n < 0 && errno == EINTR);
        _exit(n == 1 ? (unsigned char)status : 1);
    }
    close(p[0]);
    if (setsid() < 0) { uw_log(UW_LOG_ERROR, "setsid: %s", strerror(errno)); return -1; }
    pid = fork();
    if (pid < 0) { uw_log(UW_LOG_ERROR, "fork: %s", strerror(errno)); return -1; }
    if (pid > 0) _exit(0);
    ready_pipe = p[1];
    if (chdir("/") != 0) { /* not fatal: every path in use was opened already */ }
    umask(022);
    int null = open("/dev/null", O_RDWR | O_CLOEXEC);
    if (null >= 0) {
        dup2(null, 0);
        if (access_fd != 1) dup2(null, 1);
        if (uw_errlog_fd != 2) dup2(null, 2);
        if (null > 2) close(null);
    }
    return 0;
}

static void uw_ready(int status) {
    if (ready_pipe < 0) return;
    char s = (char)status;
    if (write(ready_pipe, &s, 1) != 1) { /* the waiting parent is gone */ }
    close(ready_pipe);
    ready_pipe = -1;
}

/* Several workers: fork them, and start a new one when one ends. */
static pid_t worker_pids[UW_MAX_WORKERS];
static volatile sig_atomic_t child_died = 0;
static void uw_on_child(int s) { (void)s; child_died = 1; }

static int uw_supervise(void) {
    struct sigaction sa; memset(&sa, 0, sizeof sa);
    sa.sa_handler = uw_on_child;
    sigaction(SIGCHLD, &sa, NULL);
    sigset_t block, old;
    sigemptyset(&block);
    sigaddset(&block, SIGCHLD); sigaddset(&block, SIGTERM); sigaddset(&block, SIGINT); sigaddset(&block, SIGHUP);
    sigprocmask(SIG_BLOCK, &block, &old);
    int deaths = 0;
    time_t window = time(NULL);
    for (int i = 0; i < cfg.workers; i++) worker_pids[i] = 0;
    for (;;) {
        for (int i = 0; i < cfg.workers && !stopping; i++) {
            if (worker_pids[i]) continue;
            pid_t pid = fork();
            if (pid < 0) { uw_log(UW_LOG_ERROR, "fork: %s", strerror(errno)); break; }
            if (pid == 0) {
                signal(SIGCHLD, SIG_DFL);
                if (pid_fd >= 0) { close(pid_fd); pid_fd = -1; }
#ifdef __linux__
                prctl(PR_SET_PDEATHSIG, SIGTERM, 0, 0, 0);
#endif
                sigprocmask(SIG_SETMASK, &old, NULL);
                _exit(uw_serve());
            }
            worker_pids[i] = pid;
        }
        if (stopping) break;
        sigsuspend(&old);
        if (child_died) {
            child_died = 0;
            int st; pid_t pid;
            while ((pid = waitpid(-1, &st, WNOHANG)) > 0) {
                for (int i = 0; i < cfg.workers; i++) if (worker_pids[i] == pid) worker_pids[i] = 0;
                if (stopping) continue;
                uw_log(UW_LOG_WARN, "worker %ld ended (%s %d); starting another", (long)pid,
                       WIFSIGNALED(st) ? "signal" : "status", WIFSIGNALED(st) ? WTERMSIG(st) : WEXITSTATUS(st));
                time_t t = time(NULL);
                if (t - window > 10) { window = t; deaths = 0; }
                if (++deaths > 2 * cfg.workers + 5) {     /* not a fork loop: give up */
                    uw_log(UW_LOG_ERROR, "workers keep ending; stopping");
                    stopping = 1;
                }
            }
        }
    }
    for (int i = 0; i < cfg.workers; i++) if (worker_pids[i]) kill(worker_pids[i], SIGTERM);
    time_t until = time(NULL) + 15;
    for (int alive = 1; alive && time(NULL) < until; ) {
        alive = 0;
        for (int i = 0; i < cfg.workers; i++) {
            if (!worker_pids[i]) continue;
            pid_t r = waitpid(worker_pids[i], NULL, WNOHANG);
            if (r == worker_pids[i] || (r < 0 && errno == ECHILD)) worker_pids[i] = 0; else alive = 1;
        }
        if (alive) { struct timespec ts = { 0, 50 * 1000000L }; nanosleep(&ts, NULL); }
    }
    for (int i = 0; i < cfg.workers; i++) if (worker_pids[i]) { kill(worker_pids[i], SIGKILL); waitpid(worker_pids[i], NULL, 0); }
    return 0;
}

/* The descriptors the connection table can hold: as many as the limit allows. */
static int uw_raise_nofile(void) {
    struct rlimit rl;
    if (getrlimit(RLIMIT_NOFILE, &rl) != 0) return 1024;
    rlim_t want = (rlim_t)cfg.max_conns + 64;
    if (rl.rlim_cur < want) {
        rl.rlim_cur = rl.rlim_max < want ? rl.rlim_max : want;
        if (setrlimit(RLIMIT_NOFILE, &rl) != 0) { /* keep what there is */ }
        getrlimit(RLIMIT_NOFILE, &rl);
    }
    if (rl.rlim_cur < want)
        uw_log(UW_LOG_WARN, "the descriptor limit is %llu: fewer than --max-connections=%d can be open",
               (unsigned long long)rl.rlim_cur, cfg.max_conns);
    return rl.rlim_cur > 1048576 ? 1048576 : (int)rl.rlim_cur;
}

/* What --check verifies, and the start needs as well. 0, or -1 (logged). */
static int uw_check_resources(int starting) {
    char err[512];
    if (uw_lookup_identity(err, sizeof err) != 0) { uw_log(UW_LOG_ERROR, "%s", err); return -1; }
    if (cfg.chroot_dir) {
        struct stat st;
        if (stat(cfg.chroot_dir, &st) != 0 || !S_ISDIR(st.st_mode)) { uw_log(UW_LOG_ERROR, "--chroot: %s is not a directory", cfg.chroot_dir); return -1; }
        if (geteuid() != 0) { uw_log(UW_LOG_ERROR, "--chroot needs root"); return -1; }
    }
    if (cfg.root) {
        int fd = open(cfg.root, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
        if (fd < 0) { uw_log(UW_LOG_ERROR, "--root: cannot open the directory %s: %s", cfg.root, strerror(errno)); return -1; }
        if (starting) rootfd = fd; else close(fd);
    }
    if (cfg.mime_types && uw_mime_extra_n == 0) {
        if (uw_mime_load(cfg.mime_types, err, sizeof err) != 0) { uw_log(UW_LOG_ERROR, "--mime-types: %s", err); return -1; }
    }
    int has_tls = 0;
    for (int i = 0; i < nlisteners; i++) has_tls |= listeners[i].tls;
    if (has_tls) {
        int km = uw_tls_key_mode_check(cfg.key, err, sizeof err);
        if (km < 0) { uw_log(UW_LOG_ERROR, "%s", err); return -1; }
        if (km > 0) uw_log(UW_LOG_WARN, "%s", err);
        SSL_CTX *ctx = uw_tls_ctx_new(cfg.cert, cfg.key, cfg.chain, cfg.tls_min, cfg.tls_ciphers, cfg.tls_suites, err, sizeof err);
        if (!ctx) { uw_log(UW_LOG_ERROR, "%s", err); return -1; }
        if (starting) ssl_ctx = ctx; else SSL_CTX_free(ctx);
    }
    if (!starting) {
        const char *logs[2] = { cfg.access_log, cfg.error_log };
        for (int i = 0; i < 2; i++) {
            if (!strcmp(logs[i], "-") || !strcmp(logs[i], "off")) continue;
            int fd = uw_open_log(logs[i], -1);
            if (fd < 0) { uw_log(UW_LOG_ERROR, "cannot open the log %s: %s", logs[i], strerror(errno)); return -1; }
            close(fd);
        }
        if (cfg.pid_file) {
            char dir[PATH_MAX];
            snprintf(dir, sizeof dir, "%s", cfg.pid_file);
            char *slash = strrchr(dir, '/');
            if (!slash) snprintf(dir, sizeof dir, ".");
            else if (slash == dir) slash[1] = '\0';
            else *slash = '\0';
            if (access(dir, W_OK) != 0) { uw_log(UW_LOG_ERROR, "--pid-file: cannot write in %s: %s", dir, strerror(errno)); return -1; }
        }
    }
    return 0;
}

/* Settings that contradict each other are usage errors (exit status 2). */
static void uw_validate(void) {
    char err[512];
    if ((cfg.cert != NULL) != (cfg.key != NULL)) uw_usage_error("%s", cfg.cert ? "--cert needs --key" : "--key needs --cert");
    if ((cfg.tls_port_given || cfg.tls_listen.n) && !cfg.cert) uw_usage_error("%s needs --cert and --key", cfg.tls_listen.n ? "--tls-listen" : "--tls-port");
    if (cfg.chain && !cfg.cert) uw_usage_error("--chain needs --cert and --key");
    if (cfg.max_uri > cfg.max_header) uw_usage_error("--max-uri-length (%lld) is more than --max-header-size (%lld)", cfg.max_uri, cfg.max_header);
    if (!cfg.root && (cfg.dirlist || cfg.gzip_static || cfg.mime_types))
        uw_usage_error("%s needs --root", cfg.dirlist ? "--directory-listing" : cfg.gzip_static ? "--gzip-static" : "--mime-types");
    if (uw_resolve_listeners(err, sizeof err) != 0) uw_usage_error("%s", err);
}

int main(int argc, char **argv) {
    UwParsed parsed;
    uw_parse_argv(uw_options, argc, argv, &parsed);   /* exits 2 on any error: nothing is done yet */
    uw_config_defaults(&cfg);

    int check = 0, print = 0;
    for (int i = 0; i < parsed.nargs; i++) {
        const UwArg *a = &parsed.args[i];
        switch (a->opt->id) {
        case OPT_HELP:    uw_print_help();  return 0;
        case OPT_VERSION:
        case OPT_ABOUT:   uw_print_about(); return 0;
        case OPT_CONFIG:  cfg.config_file = (char *)a->value; break;
        case OPT_CHECK:   check = 1; break;
        case OPT_PRINT_CONFIG: print = 1; break;
        default: break;
        }
    }
    /* A bare number was once read as the port: still accepted, with a note. */
    const char *bare_port = NULL;
    for (int i = 0; i < parsed.nwords; i++) {
        long long v;
        if (uw_parse_long(parsed.words[i], 1, 65535, &v) != 0 || i > 0)
            uw_usage_error("unexpected argument '%s'", parsed.words[i]);
    }
    if (parsed.nwords == 1) {
        bare_port = parsed.words[0];
        fprintf(stderr, "%s: a port given as a bare argument is deprecated; use --port=%s\n", uw_progname, bare_port);
    }
    if (!cfg.config_file) {
        const char *env = getenv("UWEBSERVER_CONFIG");
        if (env && *env) cfg.config_file = (char *)env;
    }
    if (cfg.config_file) uw_read_config(&cfg, cfg.config_file);
    char err[512];
    for (int i = 0; i < parsed.nargs; i++) {
        const UwArg *a = &parsed.args[i];
        if (a->opt->flags & UWO_CLI_ONLY) continue;
        if (uw_apply(&cfg, a->opt, a->value, a->negated, 1, a->spelled, err, sizeof err) != 0) uw_usage_error("%s", err);
    }
    if (bare_port) {
        const UwOpt *po = uw_find_short(uw_options, 'p');
        if (uw_apply(&cfg, po, bare_port, 0, 1, "port", err, sizeof err) != 0) uw_usage_error("%s", err);
    }
    uw_validate();
    uw_log_level = cfg.quiet ? UW_LOG_ERROR : cfg.verbose ? UW_LOG_DEBUG : UW_LOG_NOTICE;

    if (print) { uw_print_config(&cfg); return 0; }

    if (check) {
        if (uw_check_resources(0) != 0) return 1;
        printf("%s: the configuration is valid", uw_progname);
        for (int i = 0; i < nlisteners; i++) printf("%s %s", i ? "," : "; it would serve on", listeners[i].text);
        printf("\n");
        return 0;
    }

    /* Root serves only when asked to. */
    if (geteuid() == 0 && !cfg.user && !cfg.allow_root) {
        fprintf(stderr, "%s: refusing to serve as root: give --user=USER to drop to that user once the ports are open, or --allow-root\n", uw_progname);
        return 1;
    }

    int efd = uw_open_log(cfg.error_log, 2);
    if (efd < 0) { fprintf(stderr, "%s: cannot open the error log %s: %s\n", uw_progname, cfg.error_log, strerror(errno)); return 1; }
    uw_errlog_fd = efd;
    conns_cap = uw_raise_nofile();
    conns = (Conn **)calloc((size_t)conns_cap, sizeof(Conn *));
    if (!conns) { uw_log(UW_LOG_ERROR, "out of memory"); return 1; }
    if (uw_check_resources(1) != 0) return 1;
    if (strcmp(cfg.access_log, "off") != 0) {
        access_fd = uw_open_log(cfg.access_log, 1);
        if (access_fd < 0) { uw_log(UW_LOG_ERROR, "cannot open the access log %s: %s", cfg.access_log, strerror(errno)); return 1; }
    }
    signal(SIGPIPE, SIG_IGN);
    struct sigaction sa; memset(&sa, 0, sizeof sa);
    sa.sa_handler = uw_on_signal;
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT, &sa, NULL);
    sigaction(SIGHUP, &sa, NULL);
    if (uw_bind_listeners() != 0) return 1;
    if (cfg.daemon && uw_daemonize() != 0) return 1;
    if (uw_write_pid_file() != 0) { uw_ready(1); return 1; }
    if (uw_drop() != 0) { uw_remove_pid_file(); uw_ready(1); return 1; }
    start_time = time(NULL);
    uw_update_date();
    for (int i = 0; i < nlisteners; i++)
        uw_log(UW_LOG_NOTICE, "serving %s on %s", cfg.root ? cfg.root : "the built-in answers", listeners[i].text);
    if (ssl_ctx) { char d[512]; uw_tls_describe(cfg.cert, d, sizeof d); uw_log(UW_LOG_NOTICE, "TLS certificate: %s", d); }
    if (drop_privileges) uw_log(UW_LOG_NOTICE, "running as uid %u, gid %u", (unsigned)getuid(), (unsigned)getgid());
    uw_ready(0);
    int rc = cfg.workers > 1 ? uw_supervise() : uw_serve();
    uw_log(UW_LOG_NOTICE, "stopped after %lld requests, %lld bytes sent", total_requests, total_bytes);
    uw_remove_pid_file();
    if (ssl_ctx) SSL_CTX_free(ssl_ctx);
    return rc;
}
