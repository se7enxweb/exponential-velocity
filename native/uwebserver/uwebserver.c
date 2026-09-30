#define _GNU_SOURCE
/*
 * uwebserver -- a small web server in C beside Exponential Velocity.
 *
 * Written and kept by hand. Its first version was generated from a U
 * program by a U-to-C translator; of that only the three built-in answers
 * below remain (the paths /, /json and /health), and neither the
 * translator's output nor its runtime (u_runtime.h) is compiled any more.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdbool.h>
#include <stdint.h>

static char* u_handle_path__S(char* path) {
    if ((strcmp(path, "/") == 0)) {
        return "Hello from U!";
    }
    if ((strcmp(path, "/json") == 0)) {
        return "{\"status\":\"ok\",\"server\":\"U WebServer\"}";
    }
    if ((strcmp(path, "/health") == 0)) {
        return "ok";
    }
    return "";
}

static int32_t u_get_status__S(char* path) {
    if ((strcmp(path, "/") == 0)) {
        return 200;
    }
    if ((strcmp(path, "/json") == 0)) {
        return 200;
    }
    if ((strcmp(path, "/health") == 0)) {
        return 200;
    }
    return 404;
}

static char* u_get_content_type__S(char* path) {
    if ((strcmp(path, "/json") == 0)) {
        return "application/json";
    }
    return "text/plain";
}

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>
#include <sys/socket.h>
#include <sys/epoll.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <time.h>
#include <getopt.h>
#include "u_tls.h"
#include "u_sendfile.h"
#include "u_merkle_cache.h"

#define MAX_EVENTS 256
#define MAX_CONNS 8192
#define BUF_SIZE 8192
#define CACHE_SIZE 256

static volatile int running = 1;
void sighandler(int s) { running = 0; }

/* ── Per-connection state ─────────────────────────────── */
typedef struct {
    int fd;
    char buf[BUF_SIZE];
    int buf_len;
    int keep_alive;
    UTlsConn* tls;  /* NULL for plain HTTP */
} Conn;

static Conn conns[MAX_CONNS];
static int epfd;

/* ── Response cache ───────────────────────────────────── */
typedef struct {
    char path[128];
    char response[4096];
    int resp_len;
    time_t expires;
} CacheSlot;

static CacheSlot cache[CACHE_SIZE];
static long cache_hits = 0, cache_misses = 0;

static CacheSlot* cache_lookup(const char* path) {
    unsigned h = 5381;
    for (const char* p = path; *p; p++) h = h * 33 + *p;
    CacheSlot* s = &cache[h % CACHE_SIZE];
    if (s->resp_len > 0 && strcmp(s->path, path) == 0 &&
        (s->expires == 0 || s->expires > time(NULL))) {
        cache_hits++;
        return s;
    }
    return NULL;
}

static void cache_store(const char* path, const char* resp, int rlen, int ttl) {
    unsigned h = 5381;
    for (const char* p = path; *p; p++) h = h * 33 + *p;
    CacheSlot* s = &cache[h % CACHE_SIZE];
    strncpy(s->path, path, 127); s->path[127] = 0;
    if (rlen > 4095) rlen = 4095;
    memcpy(s->response, resp, rlen);
    s->resp_len = rlen;
    s->expires = ttl > 0 ? time(NULL) + ttl : 0;
    cache_misses++;
}

/* ── Date header (cached per second) ──────────────────── */
static char date_hdr[80];
static time_t last_date = 0;
static void update_date(void) {
    time_t now = time(NULL);
    if (now != last_date) {
        struct tm tm; gmtime_r(&now, &tm);
        strftime(date_hdr, sizeof(date_hdr),
                 "Date: %a, %d %b %Y %H:%M:%S GMT", &tm);
        last_date = now;
    }
}

/* ── Stats ────────────────────────────────────────────── */
static long total_requests = 0;
static MerkleCache* merkle = NULL;
static long total_bytes = 0;
static time_t start_time;

/* ── I/O wrappers (plain or TLS) ──────────────────────── */
static int conn_read(Conn* c, void* buf, int len) {
    return c->tls ? u_tls_read(c->tls, buf, len) : read(c->fd, buf, len);
}
static int conn_write(Conn* c, const void* buf, int len) {
    return c->tls ? u_tls_write(c->tls, buf, len) : write(c->fd, buf, len);
}
static void conn_close(Conn* c) {
    if (c->tls) { u_tls_close(c->tls); c->tls = NULL; }
    if (c->fd > 0) { close(c->fd); }
    c->fd = 0; c->buf_len = 0;
}

/* ── Handle one request ──────────────────────────────── */
static void handle_request(Conn* c) {
    char* buf = c->buf; buf[c->buf_len] = 0;
    char path[256] = "/";
    char* sp1 = memchr(buf, ' ', c->buf_len);
    if (sp1) {
        char* sp2 = memchr(sp1+1, ' ', c->buf_len - (sp1+1-buf));
        if (sp2) {
            int plen = sp2 - sp1 - 1;
            if (plen > 0 && plen < 255) { memcpy(path, sp1+1, plen); path[plen] = 0; }
        }
    }
    c->keep_alive = 1;
    if (memmem(buf, c->buf_len, "Connection: close", 17)) c->keep_alive = 0;

    /* Health endpoint with stats */
    if (strcmp(path, "/health") == 0) {
        update_date();
        char body[512];
        int blen = snprintf(body, sizeof(body),
            "{\"status\":\"ok\",\"requests\":%ld,\"cache_hits\":%ld,"
            "\"cache_misses\":%ld,\"uptime\":%ld,"
            "\"merkle_trees\":%d,\"merkle_deps\":%d,\"merkle_invalidations\":%ld}",
            total_requests, cache_hits, cache_misses,
            (long)(time(NULL) - start_time),
            merkle->tree_count, merkle->dep_count, merkle->invalidations);
        char resp[1024];
        int rlen = snprintf(resp, sizeof(resp),
            "HTTP/1.1 200 OK\r\n%s\r\n"
            "Content-Type: application/json\r\n"
            "Content-Length: %d\r\n"
            "Connection: %s\r\nServer: U/1.0\r\n\r\n%s",
            date_hdr, blen, c->keep_alive ? "keep-alive" : "close", body);
        conn_write(c, resp, rlen);
        c->buf_len = 0; total_requests++; total_bytes += rlen;
        return;
    }

    /* Cache check */
    CacheSlot* cached = cache_lookup(path);
    if (cached) {
        conn_write(c, cached->response, cached->resp_len);
        c->buf_len = 0; total_requests++; total_bytes += cached->resp_len;
        return;
    }

    /* Call U handler */
    char* body = u_handle_path__S(path);
    int status = u_get_status__S(path);
    char* ctype = u_get_content_type__S(path);
    int blen = body ? strlen(body) : 0;
    update_date();

    char resp[4096];
    int rlen = snprintf(resp, sizeof(resp),
        "HTTP/1.1 %d %s\r\n%s\r\n"
        "Content-Type: %s\r\nContent-Length: %d\r\n"
        "Connection: %s\r\nServer: U/1.0\r\n\r\n%s",
        status, status == 200 ? "OK" : "Not Found",
        date_hdr, ctype, blen,
        c->keep_alive ? "keep-alive" : "close",
        body ? body : "");

    if (status == 200 && rlen < 4096)
        cache_store(path, resp, rlen, 60);

    conn_write(c, resp, rlen);
    c->buf_len = 0; total_requests++; total_bytes += rlen;
}

/* ── Accept ──────────────────────────────────────────── */
static UTlsCtx* tls_ctx = NULL;

static void do_accept(int listen_fd, int is_tls) {
    while (1) {
        int fd = accept4(listen_fd, NULL, NULL, SOCK_NONBLOCK);
        if (fd < 0) break;
        if (fd >= MAX_CONNS) { close(fd); continue; }
        int one = 1;
        setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));

        Conn* c = &conns[fd];
        c->fd = fd; c->buf_len = 0; c->keep_alive = 1; c->tls = NULL;

        if (is_tls && tls_ctx) {
            /* Blocking TLS handshake (could be async with more work) */
            int flags = fcntl(fd, F_GETFL); fcntl(fd, F_SETFL, flags & ~O_NONBLOCK);
            c->tls = u_tls_accept(tls_ctx, fd);
            fcntl(fd, F_SETFL, flags);
            if (!c->tls) { close(fd); c->fd = 0; continue; }
        }

        struct epoll_event ev = { .events = EPOLLIN | EPOLLET, .data.fd = fd };
        epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &ev);
    }
}

/* ── Read ────────────────────────────────────────────── */
static void do_read(int fd) {
    Conn* c = &conns[fd];
    while (1) {
        int space = BUF_SIZE - c->buf_len - 1;
        if (space <= 0) { c->buf_len = 0; break; }
        int n = conn_read(c, c->buf + c->buf_len, space);
        if (n <= 0) {
            if (n == 0 || (errno != EAGAIN && errno != EWOULDBLOCK)) {
                epoll_ctl(epfd, EPOLL_CTL_DEL, fd, NULL);
                conn_close(c);
            }
            return;
        }
        c->buf_len += n;

        /* Drain EVERY complete request already in the buffer.
         *
         * A client may pipeline several requests in one write. The old code
         * handled the first and returned; handle_request() sets buf_len = 0,
         * so the rest were discarded unread. And because the socket is
         * EPOLLET, those bytes never produce another readiness edge -- epoll
         * never reports the fd again and the client blocks forever waiting
         * for responses that will never come. Symptom: 1 of 3 responses.
         */
        char* hend;
        while ((hend = memmem(c->buf, c->buf_len, "\r\n\r\n", 4)) != NULL) {
            int consumed = (int)(hend - c->buf) + 4;
            /* Defensive: never trust this arithmetic enough to memmove on it. */
            if (consumed <= 0 || consumed > c->buf_len) { c->buf_len = 0; break; }
            int leftover = c->buf_len - consumed;

            /* Scope handle_request to THIS request only. It scans the whole
             * buffer -- e.g. memmem(buf, buf_len, "Connection: close") -- so
             * with pipelining a later request's "Connection: close" would tear
             * down the connection while an earlier one was still being served.
             */
            char next_byte = c->buf[consumed];  /* handle_request NUL-terminates here */
            c->buf_len = consumed;

            handle_request(c);                  /* resets buf_len to 0 */

            /* handle_request only writes that one NUL and clears the length --
             * it never touches the bytes behind the request. So the pipelined
             * data is still intact and a memmove is enough. No scratch buffer,
             * hence no stack-smash risk from a mis-computed length.
             */
            c->buf[consumed] = next_byte;
            if (leftover > 0) memmove(c->buf, c->buf + consumed, leftover);
            c->buf_len = leftover;

            if (!c->keep_alive) {
                epoll_ctl(epfd, EPOLL_CTL_DEL, fd, NULL);
                conn_close(c);
                return;
            }
            if (leftover == 0) break;
        }
        /* Do NOT return here. Under EPOLLET the fd must be read until EAGAIN,
         * or anything that arrived after the last read stays stuck. The loop
         * exits through the n <= 0 branch above.
         */
    }
}

/* The release and build, given when it is compiled:
 *   gcc -O2 -DUWEB_VERSION="\"$(git describe --tags --abbrev=0)\"" \
 *       -DUWEB_BUILD="\"$(git rev-parse --short HEAD)\"" -o sbin/uwebserver native/uwebserver/uwebserver.c ...
 * so the source never states a version that has gone out of date. */
#ifndef UWEB_VERSION
#define UWEB_VERSION "(version not given at build time)"
#endif
#ifndef UWEB_BUILD
#define UWEB_BUILD "source"
#endif

#include "u_options.h"

enum { OPT_HELP = 1, OPT_VERSION, OPT_ABOUT, OPT_PORT, OPT_TLS_PORT, OPT_CERT, OPT_KEY };

static const UwOpt uw_options[] = {
    { "port",      'p', UWO_VALUE, OPT_PORT,     "PORT", "serve HTTP on PORT (default 8080)" },
    { "tls-port",  0,   UWO_VALUE, OPT_TLS_PORT, "PORT", "serve HTTPS on PORT (default 8443 with --cert)" },
    { "cert",      0,   UWO_VALUE, OPT_CERT,     "FILE", "the certificate (PEM, with its chain) for HTTPS" },
    { "key",       0,   UWO_VALUE, OPT_KEY,      "FILE", "the private key (PEM) of --cert" },
    { "help",      'h', UWO_CLI_ONLY, OPT_HELP,    NULL, "print this help and exit" },
    { "version",   'V', UWO_CLI_ONLY, OPT_VERSION, NULL, "print the version and exit" },
    { "about",     0,   UWO_CLI_ONLY, OPT_ABOUT,   NULL, "print the version with build details and exit" },
    { "copyright", 0,   UWO_CLI_ONLY | UWO_HIDDEN, OPT_ABOUT, NULL, NULL },
    { "",          'v', UWO_CLI_ONLY | UWO_HIDDEN, OPT_VERSION, NULL, NULL },
    { NULL, 0, 0, 0, NULL, NULL }
};

static void uw_print_help(void) {
    printf("Usage: %s [OPTION]...\n"
           "A small web server in C for benchmarks and tests beside Exponential Velocity.\n\n",
           uw_progname);
    for (const UwOpt *o = uw_options; o->name; o++) {
        if (o->flags & UWO_HIDDEN) continue;
        char left[64];
        if (o->shortc) snprintf(left, sizeof left, "-%c, --%s%s%s", o->shortc, o->name, o->argname ? "=" : "", o->argname ? o->argname : "");
        else snprintf(left, sizeof left, "    --%s%s%s", o->name, o->argname ? "=" : "", o->argname ? o->argname : "");
        printf("  %-26s %s\n", left, o->help);
    }
    printf("\nExit status: 0 on success, 1 when the server cannot start, 2 on a usage error.\n\n"
           "Report bugs to: https://github.com/se7enxweb/exponential-velocity/issues\n"
           "Home page: <https://github.com/se7enxweb/exponential-velocity>\n");
}

/* --version, -V, -v, --about, --copyright (and -version, -about, -copyright):
 * what this program is, the way the PHP programs of the server say it
 * (Q_WebServer_About), GNU style. */
static void uw_print_about(void) {
    printf("uwebserver (Exponential Velocity) %s\n"
           "A small web server in C for benchmarks and tests beside Exponential Velocity:\n"
           "HTTP and HTTPS with keep-alive and pipelining.\n\n"
           "  Program:      uwebserver -- a minimal web server, compiled\n"
           "  Version:      %s+%s\n"
           "  Built:        %s %s, %s\n"
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
           "compiler " __VERSION__
#else
           "unknown compiler"
#endif
           );
}

static int uw_port_value(const UwArg *a) {
    long long v;
    if (uw_parse_long(a->value, 1, 65535, &v) != 0)
        uw_usage_error("invalid port '%s' for '%s' (1 to 65535)", a->value, a->spelled);
    return (int)v;
}

int main(int argc, char** argv) {
    UwParsed parsed;
    uw_parse_argv(uw_options, argc, argv, &parsed);   /* exits 2 on any error */

    int port = 8080, tls_port = 0;
    const char *cert = NULL, *key = NULL;

    for (int i = 0; i < parsed.nargs; i++) {
        const UwArg *a = &parsed.args[i];
        switch (a->opt->id) {
        case OPT_HELP:    uw_print_help();  return 0;
        case OPT_VERSION:
        case OPT_ABOUT:   uw_print_about(); return 0;
        default: break;
        }
    }
    /* A bare number was once read as the port: still accepted, with a note. */
    for (int i = 0; i < parsed.nwords; i++) {
        long long v;
        if (uw_parse_long(parsed.words[i], 1, 65535, &v) != 0)
            uw_usage_error("unexpected argument '%s'", parsed.words[i]);
        fprintf(stderr, "%s: a port given as a bare argument is deprecated; use --port=%s\n", uw_progname, parsed.words[i]);
        port = (int)v;
    }
    for (int i = 0; i < parsed.nargs; i++) {
        const UwArg *a = &parsed.args[i];
        switch (a->opt->id) {
        case OPT_PORT:     port = uw_port_value(a); break;
        case OPT_TLS_PORT: tls_port = uw_port_value(a); break;
        case OPT_CERT:     cert = a->value; break;
        case OPT_KEY:      key = a->value; break;
        default: break;
        }
    }
    if ((cert != NULL) != (key != NULL))
        uw_usage_error("%s", cert ? "--cert needs --key" : "--key needs --cert");
    if (tls_port && !cert)
        uw_usage_error("--tls-port needs --cert and --key");

    signal(SIGINT, sighandler);
    signal(SIGTERM, sighandler);
    signal(SIGPIPE, SIG_IGN);

    /* TLS setup */
    if (cert && key) {
        UTlsCertInfo info = u_tls_cert_info(cert);
        tls_ctx = u_tls_ctx_new(cert, key, "1.2");
        if (!tls_ctx) { fprintf(stderr, "TLS init failed: %s\n", u_tls_last_error()); return 1; }
        printf("TLS: %s (expires in %d days, SAN: %s)\n",
               info.subject, info.days_remaining, info.san[0] ? info.san : "none");
        if (tls_port == 0) tls_port = 8443;
    }

    epfd = epoll_create1(0);
    memset(cache, 0, sizeof(cache));
    memset(conns, 0, sizeof(conns));
    start_time = time(NULL);
    merkle = merkle_cache_new();

    /* HTTP listener */
    int sock = socket(AF_INET, SOCK_STREAM | SOCK_NONBLOCK, 0);
    int opt = 1;
    setsockopt(sock, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
    setsockopt(sock, SOL_SOCKET, SO_REUSEPORT, &opt, sizeof(opt));
    struct sockaddr_in addr = {0};
    addr.sin_family = AF_INET; addr.sin_addr.s_addr = INADDR_ANY; addr.sin_port = htons(port);
    if (bind(sock, (struct sockaddr*)&addr, sizeof(addr)) < 0) { perror("bind HTTP"); return 1; }
    listen(sock, 4096);
    struct epoll_event ev = { .events = EPOLLIN, .data.fd = sock };
    epoll_ctl(epfd, EPOLL_CTL_ADD, sock, &ev);
    printf("⚡ U WebServer on :%d", port);

    /* HTTPS listener */
    int tls_sock = -1;
    if (tls_ctx && tls_port > 0) {
        tls_sock = socket(AF_INET, SOCK_STREAM | SOCK_NONBLOCK, 0);
        setsockopt(tls_sock, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
        struct sockaddr_in taddr = {0};
        taddr.sin_family = AF_INET; taddr.sin_addr.s_addr = INADDR_ANY; taddr.sin_port = htons(tls_port);
        if (bind(tls_sock, (struct sockaddr*)&taddr, sizeof(taddr)) < 0) { perror("bind HTTPS"); return 1; }
        listen(tls_sock, 4096);
        struct epoll_event tev = { .events = EPOLLIN, .data.fd = tls_sock };
        epoll_ctl(epfd, EPOLL_CTL_ADD, tls_sock, &tev);
        printf(", HTTPS on :%d", tls_port);
    }
    printf(" (epoll, keep-alive, cached)\n");
    fflush(stdout);

    /* Main loop */
    struct epoll_event events[MAX_EVENTS];
    while (running) {
        int n = epoll_wait(epfd, events, MAX_EVENTS, 1000);
        for (int i = 0; i < n; i++) {
            int fd = events[i].data.fd;
            if (fd == sock) do_accept(sock, 0);
            else if (fd == tls_sock) do_accept(tls_sock, 1);
            else do_read(fd);
        }
    }

    printf("\n%ld requests served, %ld bytes, cache %ld/%ld (%.1f%% hit rate)\n",
           total_requests, total_bytes, cache_hits, cache_hits + cache_misses,
           (cache_hits+cache_misses) > 0 ? 100.0*cache_hits/(cache_hits+cache_misses) : 0.0);

    close(sock);
    if (tls_sock >= 0) close(tls_sock);
    if (tls_ctx) u_tls_ctx_free(tls_ctx);
    close(epfd);
    return 0;
}
