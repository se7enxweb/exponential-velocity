/* u_log.h -- the error log and the access log of uwebserver.
 *
 * Every line is written with one write(2) to a descriptor opened with
 * O_APPEND, so lines of several worker processes never interleave. Text
 * that came from a client (the request line, Referer, User-Agent) is
 * escaped before it is written: in the common and combined formats every
 * byte outside printable ASCII, and '"' and '\', become \xHH, the way
 * nginx and Apache escape them; in JSON they are \u00HH. A client can
 * therefore never start a new log line or forge a field.
 */
#ifndef U_LOG_H
#define U_LOG_H

#include <errno.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

enum { UW_LOG_ERROR = 0, UW_LOG_WARN = 1, UW_LOG_NOTICE = 2, UW_LOG_DEBUG = 3 };

static int uw_errlog_fd = 2;
static int uw_log_level = UW_LOG_NOTICE;   /* --quiet: ERROR, --verbose: DEBUG */

/* Escapes n bytes of s for a quoted field of a text log line. Always NUL-terminates. */
static size_t uw_log_escape(char *dst, size_t cap, const char *s, size_t n) {
    static const char hex[] = "0123456789abcdef";
    size_t o = 0;
    if (cap == 0) return 0;
    for (size_t i = 0; i < n; i++) {
        unsigned char c = (unsigned char)s[i];
        if (c >= 0x20 && c < 0x7f && c != '"' && c != '\\') {
            if (o + 1 >= cap) break;
            dst[o++] = (char)c;
        } else {
            if (o + 4 >= cap) break;
            dst[o++] = '\\'; dst[o++] = 'x'; dst[o++] = hex[c >> 4]; dst[o++] = hex[c & 15];
        }
    }
    dst[o] = '\0';
    return o;
}

/* Escapes n bytes of s for a JSON string (without the quotes). Always NUL-terminates. */
static size_t uw_json_escape(char *dst, size_t cap, const char *s, size_t n) {
    static const char hex[] = "0123456789abcdef";
    size_t o = 0;
    if (cap == 0) return 0;
    for (size_t i = 0; i < n; i++) {
        unsigned char c = (unsigned char)s[i];
        if (c >= 0x20 && c < 0x7f && c != '"' && c != '\\') {
            if (o + 1 >= cap) break;
            dst[o++] = (char)c;
        } else if (c == '"' || c == '\\') {
            if (o + 2 >= cap) break;
            dst[o++] = '\\'; dst[o++] = (char)c;
        } else {
            if (o + 6 >= cap) break;
            dst[o++] = '\\'; dst[o++] = 'u'; dst[o++] = '0'; dst[o++] = '0';
            dst[o++] = hex[c >> 4]; dst[o++] = hex[c & 15];
        }
    }
    dst[o] = '\0';
    return o;
}

/* Writes all of buf, retrying short writes and EINTR; the result is ignored on purpose. */
static void uw_write_all(int fd, const char *buf, size_t n) {
    while (n > 0) {
        ssize_t w = write(fd, buf, n);
        if (w < 0) { if (errno == EINTR) continue; return; }
        buf += w; n -= (size_t)w;
    }
}

static void uw_log(int level, const char *fmt, ...) __attribute__((format(printf, 2, 3)));
static void uw_log(int level, const char *fmt, ...) {
    static const char *names[] = { "error", "warning", "notice", "debug" };
    if (level > uw_log_level) return;
    char msg[1024], line[1200];
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(msg, sizeof msg, fmt, ap);
    va_end(ap);
    /* A message may quote a client's bytes: never let it break the line. */
    for (char *c = msg; *c; c++) if ((unsigned char)*c < 0x20 || *c == 0x7f) *c = '?';
    time_t now = time(NULL);
    struct tm tm;
    gmtime_r(&now, &tm);
    char ts[32];
    strftime(ts, sizeof ts, "%Y-%m-%dT%H:%M:%SZ", &tm);
    int n = snprintf(line, sizeof line, "%s uwebserver[%ld]: %s: %s\n", ts, (long)getpid(), names[level], msg);
    if (n < 0) return;
    if ((size_t)n >= sizeof line) { n = (int)sizeof line - 1; line[n - 1] = '\n'; }
    uw_write_all(uw_errlog_fd, line, (size_t)n);
}

#endif /* U_LOG_H */
