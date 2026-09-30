/* u_options.h -- the command line of uwebserver, GNU style.
 *
 * A table of options (UwOpt) and one parser for it. The parser only reads:
 * it turns argv into a list of (option, value) pairs and positional words,
 * or reports the first error. Nothing is acted on until the whole command
 * line has been read without error, so an unknown or malformed argument
 * never starts anything.
 *
 * Spellings (GNU Coding Standards, "Command-Line Interfaces", plus the BSD
 * spellings every program of the server accepts):
 *   --name=VALUE  --name VALUE      a long option with a value
 *   -name=VALUE   -name VALUE       the same, with one dash
 *   --name  -name  --no-name        a flag; --no-name turns a switch off
 *   -x VALUE  -xVALUE  -abc         short options; flags bundle
 *   --                              ends the options
 * A value is never taken from a following word that is itself an option
 * (starts with '-' and is longer than "-"), so "--root --help" is an error
 * and not a root named "--help". Long names are matched exactly; an
 * abbreviation is an unknown option, so adding an option later can never
 * change what an existing command line means.
 */
#ifndef U_OPTIONS_H
#define U_OPTIONS_H

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <stdarg.h>

#define UWO_VALUE    1u   /* takes a value */
#define UWO_SWITCH   2u   /* a flag with a --no- form */
#define UWO_CLI_ONLY 4u   /* not a configuration file setting */
#define UWO_REPEAT   8u   /* may be given more than once; the values collect */
#define UWO_HIDDEN  16u   /* accepted, not listed in --help (aliases) */

typedef struct {
    const char *name;     /* long name, without dashes */
    int         shortc;   /* one-letter name, or 0 */
    unsigned    flags;
    int         id;
    const char *argname;  /* what the value is, for --help: PORT, FILE ... */
    const char *help;     /* one line for --help; NULL for a hidden alias */
} UwOpt;

typedef struct {
    const UwOpt *opt;
    const char  *value;   /* NULL for a flag */
    int          negated; /* --no-name */
    char         spelled[64]; /* as the user wrote it, for messages */
} UwArg;

typedef struct {
    UwArg       *args;  int nargs;
    const char **words; int nwords;   /* positional arguments */
} UwParsed;

static const char *uw_progname = "uwebserver";

/* A usage error: the message, the pointer to --help, exit status 2. */
static void uw_usage_error(const char *fmt, ...) __attribute__((format(printf, 1, 2), noreturn));
static void uw_usage_error(const char *fmt, ...) {
    va_list ap;
    fprintf(stderr, "%s: ", uw_progname);
    va_start(ap, fmt); vfprintf(stderr, fmt, ap); va_end(ap);
    fprintf(stderr, "\nTry '%s --help' for more information.\n", uw_progname);
    exit(2);
}

static const UwOpt *uw_find_long(const UwOpt *tab, const char *name, size_t len, int *negated) {
    *negated = 0;
    for (const UwOpt *o = tab; o->name; o++)
        if (strlen(o->name) == len && strncmp(o->name, name, len) == 0) return o;
    if (len > 3 && strncmp(name, "no-", 3) == 0) {
        for (const UwOpt *o = tab; o->name; o++)
            if ((o->flags & UWO_SWITCH) && strlen(o->name) == len - 3 && strncmp(o->name, name + 3, len - 3) == 0) {
                *negated = 1; return o;
            }
    }
    return NULL;
}

static const UwOpt *uw_find_short(const UwOpt *tab, int c) {
    for (const UwOpt *o = tab; o->name; o++) if (o->shortc && o->shortc == c) return o;
    return NULL;
}

/* A following word that is an option is never taken as a value. */
static int uw_is_option_word(const char *w) { return w[0] == '-' && w[1] != '\0'; }

static void uw_push_arg(UwParsed *p, const UwOpt *o, const char *value, int negated, const char *spelled, size_t slen) {
    UwArg *a = &p->args[p->nargs++];
    a->opt = o; a->value = value; a->negated = negated;
    if (slen >= sizeof(a->spelled)) slen = sizeof(a->spelled) - 1;
    memcpy(a->spelled, spelled, slen); a->spelled[slen] = '\0';
}

/* Reads argv; on any error prints it and exits 2. The result points into argv. */
static void uw_parse_argv(const UwOpt *tab, int argc, char **argv, UwParsed *p) {
    p->args = (UwArg *)calloc((size_t)argc + 1, sizeof(UwArg));
    p->words = (const char **)calloc((size_t)argc + 1, sizeof(char *));
    p->nargs = p->nwords = 0;
    if (!p->args || !p->words) { fprintf(stderr, "%s: out of memory\n", uw_progname); exit(1); }
    int done = 0;
    for (int i = 1; i < argc; i++) {
        const char *a = argv[i];
        if (done || a[0] != '-' || a[1] == '\0') { p->words[p->nwords++] = a; continue; }
        if (strcmp(a, "--") == 0) { done = 1; continue; }
        int dashes = (a[1] == '-') ? 2 : 1;
        const char *name = a + dashes;
        const char *eq = strchr(name, '=');
        size_t nlen = eq ? (size_t)(eq - name) : strlen(name);
        int neg = 0;
        const UwOpt *o = NULL;
        /* One dash: a long name (the BSD spelling) when it is one, else letters. */
        if (dashes == 2 || nlen > 1) o = uw_find_long(tab, name, nlen, &neg);
        if (dashes == 2 && !o) uw_usage_error("unrecognized option '%.*s'", (int)(nlen + 2), a);
        if (o) {
            size_t slen = (size_t)dashes + nlen;
            if (o->flags & UWO_VALUE) {
                const char *v = NULL;
                if (eq) v = eq + 1;
                else if (i + 1 < argc && !uw_is_option_word(argv[i + 1])) v = argv[++i];
                else uw_usage_error("option '%.*s' requires an argument", (int)slen, a);
                uw_push_arg(p, o, v, 0, a, slen);
            } else {
                if (eq) uw_usage_error("option '%.*s' doesn't allow an argument", (int)slen, a);
                uw_push_arg(p, o, NULL, neg, a, slen);
            }
            continue;
        }
        /* Short options, bundled: -qd, -p8080, -p 8080. */
        for (const char *c = a + 1; *c; c++) {
            const UwOpt *s = uw_find_short(tab, (unsigned char)*c);
            if (!s) uw_usage_error("invalid option -- '%c'", *c);
            char sp[3] = { '-', *c, 0 };
            if (s->flags & UWO_VALUE) {
                const char *v = NULL;
                if (c[1]) v = c + 1;
                else if (i + 1 < argc && !uw_is_option_word(argv[i + 1])) v = argv[++i];
                else uw_usage_error("option requires an argument -- '%c'", *c);
                uw_push_arg(p, s, v, 0, sp, 2);
                break;
            }
            uw_push_arg(p, s, NULL, 0, sp, 2);
        }
    }
}

/* Strict numbers: all digits, in range. Returns 0 on success. */
static int uw_parse_long(const char *s, long long min, long long max, long long *out) {
    if (!s || !*s) return -1;
    long long v = 0;
    for (const char *c = s; *c; c++) {
        if (*c < '0' || *c > '9') return -1;
        if (v > (max - (*c - '0')) / 10) return -1;   /* would pass max */
        v = v * 10 + (*c - '0');
    }
    if (v < min || v > max) return -1;
    *out = v;
    return 0;
}

/* A size: digits with an optional k, m or g (powers of 1024). */
static int uw_parse_size(const char *s, long long min, long long max, long long *out) {
    if (!s || !*s) return -1;
    size_t n = strlen(s);
    long long mul = 1;
    char buf[32];
    if (n >= sizeof(buf)) return -1;
    memcpy(buf, s, n + 1);
    char last = buf[n - 1];
    if (last == 'k' || last == 'K') mul = 1024LL;
    else if (last == 'm' || last == 'M') mul = 1024LL * 1024;
    else if (last == 'g' || last == 'G') mul = 1024LL * 1024 * 1024;
    if (mul != 1) buf[--n] = '\0';
    long long v;
    if (uw_parse_long(buf, 0, max / mul, &v) != 0) return -1;
    v *= mul;
    if (v < min || v > max) return -1;
    *out = v;
    return 0;
}

/* yes/no words of a configuration file. */
static int uw_parse_bool(const char *s, int *out) {
    static const char *yes[] = { "yes", "true", "on", "1" }, *no[] = { "no", "false", "off", "0" };
    for (int i = 0; i < 4; i++) {
        if (strcasecmp(s, yes[i]) == 0) { *out = 1; return 0; }
        if (strcasecmp(s, no[i]) == 0) { *out = 0; return 0; }
    }
    return -1;
}

#endif /* U_OPTIONS_H */
