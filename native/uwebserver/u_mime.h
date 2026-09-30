/* u_mime.h -- media types by file name extension, for uwebserver.
 *
 * A built-in table of the common types, and a loader for files in the
 * mime.types format (a type, then its extensions, '#' starts a comment),
 * whose entries are added to the table and win over the built-in ones.
 * Lookups are case-insensitive on the extension.
 */
#ifndef U_MIME_H
#define U_MIME_H

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <errno.h>

typedef struct { char *ext; char *type; } UwMime;

static const char *const uw_mime_builtin[][2] = {
    { "html", "text/html" }, { "htm", "text/html" }, { "css", "text/css" },
    { "js", "text/javascript" }, { "mjs", "text/javascript" }, { "json", "application/json" },
    { "map", "application/json" }, { "txt", "text/plain" }, { "text", "text/plain" },
    { "md", "text/markdown" }, { "csv", "text/csv" }, { "xml", "application/xml" },
    { "svg", "image/svg+xml" }, { "png", "image/png" }, { "jpg", "image/jpeg" },
    { "jpeg", "image/jpeg" }, { "gif", "image/gif" }, { "webp", "image/webp" },
    { "avif", "image/avif" }, { "ico", "image/vnd.microsoft.icon" }, { "bmp", "image/bmp" },
    { "woff", "font/woff" }, { "woff2", "font/woff2" }, { "ttf", "font/ttf" }, { "otf", "font/otf" },
    { "pdf", "application/pdf" }, { "zip", "application/zip" }, { "gz", "application/gzip" },
    { "tar", "application/x-tar" }, { "wasm", "application/wasm" }, { "mp4", "video/mp4" },
    { "webm", "video/webm" }, { "mp3", "audio/mpeg" }, { "ogg", "audio/ogg" }, { "wav", "audio/wav" },
    { "webmanifest", "application/manifest+json" }, { "rss", "application/rss+xml" },
    { "atom", "application/atom+xml" }, { "yaml", "application/yaml" }, { "yml", "application/yaml" },
};

static UwMime *uw_mime_extra = NULL;
static size_t uw_mime_extra_n = 0;

/* Loads a mime.types file. Returns 0, or -1 with a message in err. */
static int uw_mime_load(const char *file, char *err, size_t errlen) {
    FILE *f = fopen(file, "re");
    if (!f) { snprintf(err, errlen, "cannot read %s: %s", file, strerror(errno)); return -1; }
    char line[1024];
    int lineno = 0;
    while (fgets(line, sizeof line, f)) {
        lineno++;
        size_t len = strlen(line);
        if (len == sizeof line - 1 && line[len - 1] != '\n') {
            snprintf(err, errlen, "%s:%d: line too long", file, lineno); fclose(f); return -1;
        }
        char *hash = strchr(line, '#');
        if (hash) *hash = '\0';
        char *save = NULL;
        char *type = strtok_r(line, " \t\r\n", &save);
        if (!type) continue;
        if (!strchr(type, '/') || strlen(type) > 127) {
            snprintf(err, errlen, "%s:%d: '%.40s' is not a media type", file, lineno, type); fclose(f); return -1;
        }
        for (const char *c = type; *c; c++) {
            if ((unsigned char)*c <= 0x20 || *c == 0x7f || *c == ';' || *c == '"') {
                snprintf(err, errlen, "%s:%d: '%.40s' is not a media type", file, lineno, type); fclose(f); return -1;
            }
        }
        char *ext;
        while ((ext = strtok_r(NULL, " \t\r\n", &save)) != NULL) {
            if (uw_mime_extra_n >= 100000) { snprintf(err, errlen, "%s: too many entries", file); fclose(f); return -1; }
            UwMime *m = (UwMime *)realloc(uw_mime_extra, (uw_mime_extra_n + 1) * sizeof(UwMime));
            if (!m) { snprintf(err, errlen, "out of memory"); fclose(f); return -1; }
            uw_mime_extra = m;
            m[uw_mime_extra_n].ext = strdup(ext);
            m[uw_mime_extra_n].type = strdup(type);
            if (!m[uw_mime_extra_n].ext || !m[uw_mime_extra_n].type) { snprintf(err, errlen, "out of memory"); fclose(f); return -1; }
            uw_mime_extra_n++;
        }
    }
    fclose(f);
    return 0;
}

/* The type of a file name, or NULL when its extension is not known. */
static const char *uw_mime_lookup(const char *name) {
    const char *slash = strrchr(name, '/');
    const char *base = slash ? slash + 1 : name;
    const char *dot = strrchr(base, '.');
    if (!dot || dot == base || !dot[1]) return NULL;
    const char *ext = dot + 1;
    for (size_t i = uw_mime_extra_n; i > 0; i--)          /* the last one given wins */
        if (strcasecmp(uw_mime_extra[i - 1].ext, ext) == 0) return uw_mime_extra[i - 1].type;
    for (size_t i = 0; i < sizeof(uw_mime_builtin) / sizeof(uw_mime_builtin[0]); i++)
        if (strcasecmp(uw_mime_builtin[i][0], ext) == 0) return uw_mime_builtin[i][1];
    return NULL;
}

#endif /* U_MIME_H */
