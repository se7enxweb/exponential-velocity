/* u_http.h -- the HTTP/1.x request head parser and path normaliser of
 * uwebserver. Pure functions: no I/O, no allocation, no global state, so
 * they are fuzzed on their own (native/uwebserver/fuzz_request.c).
 *
 * uw_parse_request() reads one request head from a buffer that need not be
 * NUL-terminated and may hold more than one request (pipelining). It is
 * strict where leniency lets two parsers disagree about where a request
 * ends (RFC 9112): lines end in CRLF, a bare CR or LF is an error, header
 * names are tokens with nothing between the name and the colon, obsolete
 * line folding is refused, Content-Length is digits only and must agree
 * with itself, and Transfer-Encoding is reported so the caller can refuse
 * it. Every string it returns points into the buffer, with a length.
 *
 * uw_normalize_path() turns the path of a request target into a relative
 * path under the document root, or refuses it: percent-decoding, then no
 * NUL, no control characters, no encoded '/', no "." or ".." segment
 * surviving, no empty segments, and (unless allowed) no segment that
 * starts with a dot.
 */
#ifndef U_HTTP_H
#define U_HTTP_H

#include <stddef.h>
#include <string.h>
#include <strings.h>

typedef struct { const char *p; size_t n; } UwStr;

typedef struct {
    UwStr method, target, path, query;
    int   minor;               /* HTTP/1.<minor>: 0 or 1 */
    UwStr host, connection, te, inm, ims, range, if_range, accept_encoding, referer, user_agent;
    int   host_count;
    int   has_cl;              /* a Content-Length was given */
    long long content_length;
    int   conn_close, conn_keepalive;
    size_t head_len;           /* bytes of the head, the empty line included */
} UwRequest;

/* Results of uw_parse_request below 0: the status to answer with. */
#define UW_NEED_MORE 0

static int uw_is_tchar(unsigned char c) {
    if (c >= 'a' && c <= 'z') return 1;
    if (c >= 'A' && c <= 'Z') return 1;
    if (c >= '0' && c <= '9') return 1;
    return c && strchr("!#$%&'*+-.^_`|~", c) != NULL;
}

static int uw_streq_ci(UwStr s, const char *lit) {
    size_t n = strlen(lit);
    return s.n == n && strncasecmp(s.p, lit, n) == 0;
}

/* Does a comma-separated header value hold this token (case-insensitive)? */
static int uw_has_token(UwStr v, const char *tok) {
    size_t tn = strlen(tok), i = 0;
    while (i < v.n) {
        while (i < v.n && (v.p[i] == ' ' || v.p[i] == '\t' || v.p[i] == ',')) i++;
        size_t s = i;
        while (i < v.n && v.p[i] != ',') i++;
        size_t e = i;
        while (e > s && (v.p[e - 1] == ' ' || v.p[e - 1] == '\t')) e--;
        if (e - s == tn && strncasecmp(v.p + s, tok, tn) == 0) return 1;
    }
    return 0;
}

/*
 * Returns the length of the head (> 0) when a complete, valid head is at the
 * start of buf; UW_NEED_MORE when more bytes are needed; or minus the status
 * to answer with: -400 malformed, -414 target too long, -431 head too
 * large, -501 a method token too long to be one we know, -505 not HTTP/1.x.
 */
static int uw_parse_request(const char *buf, size_t len, size_t max_head, size_t max_uri, UwRequest *r) {
    memset(r, 0, sizeof(*r));
    size_t pos = 0;
    /* RFC 9112 2.2: at least one empty line before the request line may be ignored. */
    for (int skipped = 0; skipped < 4 && pos + 1 < len && buf[pos] == '\r' && buf[pos + 1] == '\n'; skipped++) pos += 2;
    if (pos < len && (buf[pos] == '\r' || buf[pos] == '\n')) {
        if (buf[pos] == '\n' || (pos + 1 < len && buf[pos + 1] != '\n')) return -400;
    }

    /* Request line: METHOD SP TARGET SP HTTP/1.x CRLF */
    size_t i = pos;
    while (i < len && uw_is_tchar((unsigned char)buf[i])) i++;
    if (i == len) return (i - pos > 32) ? -501 : (len >= max_head ? -431 : UW_NEED_MORE);
    if (buf[i] != ' ' || i == pos) return -400;
    if (i - pos > 32) return -501;
    r->method.p = buf + pos; r->method.n = i - pos;
    size_t t = ++i;
    while (i < len && buf[i] != ' ' && buf[i] != '\r' && buf[i] != '\n') {
        unsigned char c = (unsigned char)buf[i];
        if (c <= 0x20 || c == 0x7f) return -400;   /* CTL, NUL, TAB in the target */
        if (i - t >= max_uri) return -414;
        i++;
    }
    if (i == len) return (i - t >= max_uri) ? -414 : (len >= max_head ? -431 : UW_NEED_MORE);
    if (buf[i] != ' ' || i == t) return -400;
    r->target.p = buf + t; r->target.n = i - t;
    size_t v = ++i;
    while (i < len && buf[i] != '\r' && buf[i] != '\n') {
        if (i - v > 16) return -400;
        i++;
    }
    if (i + 1 >= len) return (len >= max_head) ? -431 : UW_NEED_MORE;
    if (buf[i] != '\r' || buf[i + 1] != '\n') return -400;
    size_t vn = i - v;
    if (vn != 8 || memcmp(buf + v, "HTTP/", 5) != 0 || buf[v + 6] != '.' ||
        buf[v + 5] < '0' || buf[v + 5] > '9' || buf[v + 7] < '0' || buf[v + 7] > '9') return -400;
    if (buf[v + 5] != '1') return -505;
    r->minor = buf[v + 7] - '0';
    if (r->minor > 1) r->minor = 1;       /* HTTP/1.2+ is answered as 1.1 */
    i += 2;

    /* Header fields, until the empty line. */
    int cl_seen = 0;
    for (;;) {
        if (i >= max_head) return -431;
        if (i + 1 >= len) return (len >= max_head) ? -431 : UW_NEED_MORE;
        if (buf[i] == '\r') {
            if (buf[i + 1] != '\n') return -400;
            i += 2;
            break;                       /* the empty line: the head is complete */
        }
        if (buf[i] == ' ' || buf[i] == '\t') return -400;   /* obsolete line folding */
        size_t ns = i;
        while (i < len && uw_is_tchar((unsigned char)buf[i])) i++;
        if (i == len) return (len >= max_head) ? -431 : UW_NEED_MORE;
        if (buf[i] != ':' || i == ns) return -400;         /* no space before the colon */
        UwStr name = { buf + ns, i - ns };
        i++;
        while (i < len && (buf[i] == ' ' || buf[i] == '\t')) i++;
        size_t vs = i;
        while (i < len && buf[i] != '\r' && buf[i] != '\n') {
            unsigned char c = (unsigned char)buf[i];
            if ((c < 0x20 && c != '\t') || c == 0x7f) return -400;   /* NUL and other CTLs */
            i++;
        }
        if (i + 1 >= len) return (len >= max_head) ? -431 : UW_NEED_MORE;
        if (buf[i] != '\r' || buf[i + 1] != '\n') return -400;
        size_t ve = i;
        while (ve > vs && (buf[ve - 1] == ' ' || buf[ve - 1] == '\t')) ve--;
        UwStr val = { buf + vs, ve - vs };
        i += 2;

        if (uw_streq_ci(name, "host")) { r->host = val; r->host_count++; }
        else if (uw_streq_ci(name, "connection")) {
            if (uw_has_token(val, "close")) r->conn_close = 1;
            if (uw_has_token(val, "keep-alive")) r->conn_keepalive = 1;
            r->connection = val;
        }
        else if (uw_streq_ci(name, "content-length")) {
            /* Digits only; a repeated value must be the same number. */
            long long n = 0;
            if (val.n == 0 || val.n > 18) return -400;
            for (size_t k = 0; k < val.n; k++) {
                if (val.p[k] < '0' || val.p[k] > '9') return -400;
                n = n * 10 + (val.p[k] - '0');
            }
            if (cl_seen && n != r->content_length) return -400;
            cl_seen = 1; r->has_cl = 1; r->content_length = n;
        }
        else if (uw_streq_ci(name, "transfer-encoding")) { r->te = val; if (!val.n) r->te.n = 1; }
        else if (uw_streq_ci(name, "if-none-match")) r->inm = val;
        else if (uw_streq_ci(name, "if-modified-since")) r->ims = val;
        else if (uw_streq_ci(name, "range")) r->range = val;
        else if (uw_streq_ci(name, "if-range")) r->if_range = val;
        else if (uw_streq_ci(name, "accept-encoding")) r->accept_encoding = val;
        else if (uw_streq_ci(name, "referer")) r->referer = val;
        else if (uw_streq_ci(name, "user-agent")) r->user_agent = val;
    }
    if (i > max_head) return -431;
    if (r->te.n && r->has_cl) return -400;            /* both: a smuggling shape */
    if (r->minor == 1 && r->host_count != 1) return -400;
    if (r->host_count > 1) return -400;

    /* The target: origin-form, or absolute-form (whose path is used). */
    const char *tp = r->target.p; size_t tn = r->target.n;
    if (tn >= 7 && (strncasecmp(tp, "http://", 7) == 0 || (tn >= 8 && strncasecmp(tp, "https://", 8) == 0))) {
        size_t k = (tp[4] == ':') ? 7 : 8;
        while (k < tn && tp[k] != '/' && tp[k] != '?') k++;
        if (k == tn || tp[k] != '/') { tp = "/"; tn = 1; }   /* http://host or http://host?q */
        else { tp += k; tn -= k; }
    } else if (tn == 1 && tp[0] == '*') {
        r->path.p = tp; r->path.n = 1;
        r->head_len = i;
        return (int)i;
    } else if (tp[0] != '/') return -400;
    const char *q = memchr(tp, '?', tn);
    if (memchr(tp, '#', tn)) return -400;
    r->path.p = tp; r->path.n = q ? (size_t)(q - tp) : tn;
    if (q) { r->query.p = q + 1; r->query.n = tn - (size_t)(q - tp) - 1; }
    r->head_len = i;
    return (int)i;
}

static int uw_hexval(int c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

/* Results of uw_normalize_path. */
#define UW_PATH_OK      0
#define UW_PATH_BAD    -400   /* malformed or climbs out: 400 */
#define UW_PATH_HIDDEN -404   /* a segment starting with a dot: 404 */
#define UW_PATH_LONG   -414

/*
 * Decodes and normalises the path of a request (which starts with '/') into
 * out: a path relative to the document root, without a leading or trailing
 * slash, "" for the root itself. *slash is set when the request path ended
 * in '/'. Segments "." are dropped; ".." anywhere is refused rather than
 * resolved, so a path can never name anything above the root.
 */
static int uw_normalize_path(const char *p, size_t n, char *out, size_t outcap, int allow_hidden, int *slash) {
    size_t o = 0, seg = 0;
    *slash = 0;
    if (n == 0 || p[0] != '/' || outcap == 0) return UW_PATH_BAD;
    for (size_t i = 0; i <= n; i++) {
        int c;
        int end = (i == n);
        if (!end && p[i] == '%') {
            if (i + 2 >= n) return UW_PATH_BAD;          /* "%" needs two hex digits */
            int h = uw_hexval((unsigned char)p[i + 1]), l = uw_hexval((unsigned char)p[i + 2]);
            if (h < 0 || l < 0) return UW_PATH_BAD;
            c = h * 16 + l;
            i += 2;
            if (c == '/' || c == 0) return UW_PATH_BAD;      /* %2F, %00 */
        } else if (!end) {
            c = (unsigned char)p[i];
        } else c = '/';
        if (c < 0x20 || c == 0x7f) return UW_PATH_BAD;
        if (c == '/') {
            /* End of a segment: out[seg .. o) */
            size_t sl = o - seg;
            if (sl == 0) {                 /* empty segment: leading, "//" or trailing */
                if (end && i > 0 && p[n - 1] == '/') *slash = 1;
                continue;
            }
            if (sl == 1 && out[seg] == '.') { o = seg; if (end) *slash = 1; continue; }
            if (sl == 2 && out[seg] == '.' && out[seg + 1] == '.') return UW_PATH_BAD;
            if (out[seg] == '.' && !allow_hidden) return UW_PATH_HIDDEN;
            if (!end) {
                if (o + 1 >= outcap) return UW_PATH_LONG;
                out[o++] = '/';
                seg = o;
            }
            continue;
        }
        if (o + 1 >= outcap) return UW_PATH_LONG;
        out[o++] = (char)c;
    }
    /* A '/' left at the end by a segment that was then dropped ("a/." -> "a/"). */
    while (o > 0 && out[o - 1] == '/') o--;
    out[o] = '\0';
    return UW_PATH_OK;
}

#endif /* U_HTTP_H */
