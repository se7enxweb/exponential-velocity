#define _GNU_SOURCE
/*
 * fuzz_request.c -- the fuzz harness of uwebserver's request parsing: the
 * head parser, the path normaliser, the header value readers (Range,
 * If-None-Match, Accept-Encoding, HTTP dates), the number and size readers
 * of the command line, and the log escaping. Every input is checked against
 * what the server relies on, and a broken promise aborts.
 *
 * Two ways to run it:
 *
 *   clang -g -O1 -fsanitize=fuzzer,address,undefined fuzz_request.c -o fuzz
 *   ./fuzz corpus/                     libFuzzer, coverage-guided
 *
 *   cc -g -O1 -fsanitize=address,undefined -DUW_FUZZ_MAIN fuzz_request.c -o fuzz
 *   ./fuzz [seconds] [seed]            a built-in deterministic fuzzer: seeds
 *                                      from real requests, mutated by a
 *                                      xorshift generator, for the given time
 *
 * make -C native/uwebserver fuzz builds the second (with GCC as well).
 */
#include <assert.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include "u_http.h"
#include "u_options.h"
#include "u_log.h"

#define CHECK(cond) do { if (!(cond)) { fprintf(stderr, "fuzz: broken: %s (%s:%d)\n", #cond, __FILE__, __LINE__); abort(); } } while (0)

static void check_str_inside(UwStr s, const char *buf, size_t len) {
    if (s.n == 0) return;
    CHECK(s.p >= buf && s.p + s.n <= buf + len);
}

static void check_normalized(const char *out, int allow_hidden) {
    size_t n = strlen(out);
    CHECK(n == 0 || out[0] != '/');
    CHECK(n == 0 || out[n - 1] != '/');
    CHECK(strstr(out, "//") == NULL);
    for (size_t i = 0; i < n; i++) CHECK((unsigned char)out[i] >= 0x20 && out[i] != 0x7f);
    /* No segment is "." or ".."; none starts with a dot unless allowed (or /.well-known). */
    const char *seg = out;
    while (*seg) {
        const char *e = strchr(seg, '/');
        size_t sl = e ? (size_t)(e - seg) : strlen(seg);
        CHECK(sl > 0);
        CHECK(!(sl == 1 && seg[0] == '.'));
        CHECK(!(sl == 2 && seg[0] == '.' && seg[1] == '.'));
        if (!allow_hidden && seg[0] == '.') CHECK(seg == out && sl == 11 && memcmp(seg, ".well-known", 11) == 0);
        if (!e) break;
        seg = e + 1;
    }
}

static void fuzz_path(const char *p, size_t n, int allow_hidden) {
    char out[4096];
    int slash = 0;
    int r = uw_normalize_path(p, n, out, sizeof out, allow_hidden, &slash);
    CHECK(r == UW_PATH_OK || r == UW_PATH_BAD || r == UW_PATH_HIDDEN || r == UW_PATH_LONG);
    if (r != UW_PATH_OK) return;
    check_normalized(out, allow_hidden);
    /* Normalising again what came out, as a URL path (a literal "%" encoded as
     * %25: a file may be named "%2e%2e"), gives the same. */
    char again[3 * 4096 + 2], out2[4096];
    int s2 = 0;
    size_t a = 0;
    again[a++] = '/';
    for (const char *q = out; *q; q++) {
        if (*q == '%') { memcpy(again + a, "%25", 3); a += 3; } else again[a++] = *q;
    }
    again[a] = '\0';
    CHECK(uw_normalize_path(again, strlen(again), out2, sizeof out2, allow_hidden, &s2) == UW_PATH_OK);
    CHECK(strcmp(out, out2) == 0);
}

static void fuzz_escape(const char *s, size_t n) {
    char t[1100], j[2300];
    size_t tl = uw_log_escape(t, sizeof t, s, n);
    CHECK(tl < sizeof t && t[tl] == '\0');
    for (size_t i = 0; i < tl; i++) CHECK(t[i] >= 0x20 && t[i] < 0x7f && t[i] != '"');
    size_t jl = uw_json_escape(j, sizeof j, t, tl);
    CHECK(jl < sizeof j && j[jl] == '\0');
    for (size_t i = 0; i < jl; i++) {
        CHECK(j[i] >= 0x20 && j[i] < 0x7f);
        if (j[i] == '"') CHECK(i > 0 && j[i - 1] == '\\');
    }
}

int LLVMFuzzerTestOneInput(const uint8_t *data, size_t size);
int LLVMFuzzerTestOneInput(const uint8_t *data, size_t size) {
    const char *buf = (const char *)data;
    size_t limits[][2] = { { 8192, 4096 }, { 1024, 16 }, { 64, 32 } };
    for (size_t k = 0; k < sizeof limits / sizeof limits[0]; k++) {
        UwRequest r;
        int n = uw_parse_request(buf, size, limits[k][0], limits[k][1], &r);
        CHECK(n == UW_NEED_MORE || n == -400 || n == -414 || n == -431 || n == -501 || n == -505 || n > 0);
        if (n <= 0) continue;
        CHECK((size_t)n <= size && (size_t)n <= limits[k][0] && r.head_len == (size_t)n);
        CHECK(r.method.n > 0 && r.target.n > 0 && r.target.n <= limits[k][1]);
        CHECK(r.minor == 0 || r.minor == 1);
        UwStr all[] = { r.method, r.target, r.host, r.connection, r.te, r.inm, r.ims, r.range, r.if_range, r.accept_encoding, r.referer, r.user_agent, r.query };
        for (size_t i = 0; i < sizeof all / sizeof all[0]; i++) check_str_inside(all[i], buf, size);
        CHECK(!(r.has_te && r.has_cl));
        CHECK(r.content_length >= 0);
        if (r.minor == 1) CHECK(r.host_count == 1);
        CHECK(r.path.n > 0 && (r.path.p[0] == '/' || (r.path.n == 1 && r.path.p[0] == '*')));
        /* An absolute-form path may be the literal "/" the parser supplies. */
        if (r.path.p != (const char *)"/" && !(r.path.n == 1 && r.path.p[0] == '/')) check_str_inside(r.path, buf, size);
        for (size_t i = 0; i < r.target.n; i++) CHECK((unsigned char)r.target.p[i] > 0x20 && r.target.p[i] != 0x7f);
        if (r.path.p[0] == '/') { fuzz_path(r.path.p, r.path.n, 0); fuzz_path(r.path.p, r.path.n, 1); }
        long long from = -1, to = -1;
        long long sizes[] = { 0, 1, 10, 5368709120LL };
        for (size_t s = 0; s < 4; s++) {
            int rr = uw_parse_range(r.range, sizes[s], &from, &to);
            CHECK(rr == -1 || rr == 0 || rr == 1);
            if (rr == 1) CHECK(from >= 0 && from <= to && to < sizes[s]);
        }
        (void)uw_etag_match(r.inm, "\"1a-2b\"");
        (void)uw_accepts_gzip(r.accept_encoding);
        time_t t;
        (void)uw_parse_http_date(r.ims, &t);
    }
    /* The same bytes as a path, a header value and a command-line value. */
    if (size > 0 && buf[0] == '/') { fuzz_path(buf, size, 0); fuzz_path(buf, size, 1); }
    UwStr v = { buf, size };
    long long from, to;
    int rr = uw_parse_range(v, 1000, &from, &to);
    if (rr == 1) CHECK(from >= 0 && from <= to && to < 1000);
    (void)uw_etag_match(v, "\"x\"");
    (void)uw_accepts_gzip(v);
    char z[64];
    size_t zn = size < sizeof z - 1 ? size : sizeof z - 1;
    memcpy(z, buf, zn); z[zn] = '\0';
    long long num;
    if (uw_parse_long(z, 1, 65535, &num) == 0) CHECK(num >= 1 && num <= 65535);
    if (uw_parse_size(z, 1024, 1024 * 1024, &num) == 0) CHECK(num >= 1024 && num <= 1024 * 1024);
    fuzz_escape(buf, size < 1000 ? size : 1000);
    return 0;
}

#ifdef UW_FUZZ_MAIN
/* ── The built-in deterministic fuzzer ─────────────────────────────────── */

static uint64_t rng_state;
static uint64_t rng(void) { rng_state ^= rng_state << 13; rng_state ^= rng_state >> 7; rng_state ^= rng_state << 17; return rng_state; }

static const char *seeds[] = {
    "GET / HTTP/1.1\r\nHost: l\r\n\r\n",
    "GET /index.html?x=1 HTTP/1.1\r\nHost: example.org\r\nConnection: keep-alive\r\nAccept-Encoding: gzip, br;q=0.5\r\n\r\n",
    "HEAD /a/b/c.txt HTTP/1.0\r\nUser-Agent: x\r\nReferer: http://r/\r\n\r\n",
    "GET /ten.txt HTTP/1.1\r\nHost: l\r\nRange: bytes=2-4\r\nIf-Range: \"1a-2b\"\r\n\r\n",
    "GET /x HTTP/1.1\r\nHost: l\r\nIf-None-Match: W/\"1a-2b\", \"c\"\r\nIf-Modified-Since: Mon, 01 Jan 2001 00:00:00 GMT\r\n\r\n",
    "GET http://host:80/p%20q/%2e%2e/%2F HTTP/1.1\r\nHost: host\r\nContent-Length: 5\r\n\r\nhello",
    "POST /upload HTTP/1.1\r\nHost: l\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
    "\r\n\r\nGET /.well-known/acme-challenge/t HTTP/1.1\r\nHost: l\r\n\r\n",
    "/a/./b//c/../d%00e%zz/.hidden/",
    "bytes=-500",
};
static const char *tokens[] = {
    "\r\n", "\n", "\r", " ", "\t", ":", "/", "..", ".", "%", "%2e", "%2E", "%2f", "%00", "%0d%0a", "?", "#", "*",
    "HTTP/1.1", "HTTP/1.0", "HTTP/2.0", "Host: l\r\n", "Content-Length: ", "Transfer-Encoding: chunked\r\n",
    "Range: bytes=", "-", ",", "999999999999999999999", "\"", "W/", "gzip;q=0", "\xff", "\x80", "\x7f", "\0",
};

int main(int argc, char **argv) {
    double seconds = argc > 1 ? atof(argv[1]) : 10.0;
    rng_state = argc > 2 ? strtoull(argv[2], NULL, 10) : 0x9e3779b97f4a7c15ULL;
    if (!rng_state) rng_state = 1;
    enum { MAX = 12000 };
    static char buf[MAX];
    struct timespec t0, t1;
    clock_gettime(CLOCK_MONOTONIC, &t0);
    unsigned long long iters = 0;
    for (;;) {
        if ((iters & 1023) == 0) {
            clock_gettime(CLOCK_MONOTONIC, &t1);
            if ((double)(t1.tv_sec - t0.tv_sec) + (double)(t1.tv_nsec - t0.tv_nsec) / 1e9 >= seconds) break;
        }
        const char *s = seeds[rng() % (sizeof seeds / sizeof seeds[0])];
        size_t n = strlen(s);
        memcpy(buf, s, n);
        int muts = 1 + (int)(rng() % 12);
        for (int m = 0; m < muts; m++) {
            size_t at = n ? (size_t)(rng() % (n + 1)) : 0;
            switch (rng() % 6) {
            case 0: if (n) buf[rng() % n] = (char)(rng() & 0xff); break;                 /* a byte changed */
            case 1: if (n && at < n) { memmove(buf + at, buf + at + 1, n - at - 1); n--; } break;   /* one removed */
            case 2: {                                                                      /* a token inserted */
                const char *t = tokens[rng() % (sizeof tokens / sizeof tokens[0])];
                size_t tl = t[0] ? strlen(t) : 1;
                if (n + tl < MAX) { memmove(buf + at + tl, buf + at, n - at); memcpy(buf + at, t, tl); n += tl; }
                break;
            }
            case 3: if (n) n = (size_t)(rng() % n); break;                               /* cut short */
            case 4: {                                                                      /* a run repeated */
                size_t len = 1 + (size_t)(rng() % 64), times = 1 + (size_t)(rng() % 128);
                if (at + len <= n) for (size_t k = 0; k < times && n + len < MAX; k++) { memmove(buf + at + len, buf + at, n - at); n += len; }
                break;
            }
            default: {                                                                     /* bytes from elsewhere in it */
                if (n > 2) { size_t a = (size_t)(rng() % n), b = (size_t)(rng() % n); char c = buf[a]; buf[a] = buf[b]; buf[b] = c; }
            }
            }
        }
        /* An exact-size copy, so reading one byte past the input is caught. */
        char *exact = (char *)malloc(n ? n : 1);
        if (!exact) return 1;
        memcpy(exact, buf, n);
        LLVMFuzzerTestOneInput((const uint8_t *)exact, n);
        free(exact);
        iters++;
    }
    printf("fuzz: %llu inputs in %.0f seconds, no broken promise\n", iters, seconds);
    return 0;
}
#endif
