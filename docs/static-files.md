# Static Files

Velocity serves files from the document root directly, without running PHP. It handles conditional requests, compresses text on the way out, and hands image URLs with size parameters to the image pipeline.

## Serving

A request for a file with an allowed extension is answered straight from disk. The list covers text and data (`html`, `htm`, `txt`, `md`, `json`, `xml`, `yaml`, `yml`, `csv`, `tsv`), code (`css`, `js`, `mjs`, `map`, `wasm`), images (`png`, `gif`, `webp`, `jpg`, `jpeg`, `svg`, `bmp`, `ico`, `avif`), fonts (`woff`, `woff2`, `ttf`, `otf`, `eot`), media (`mp3`, `wav`, `ogg`, `mp4`, `webm`) and `pdf` and `zip`. `.log` is not on it, so a log file under the document root is not downloadable. `Q.webserver.extensions` replaces the whole list. The response includes:

- **`Content-Type`**, mapped from the extension. Files with any other extension are refused, so a stray `.env` or `.sql` in the document root is not downloadable.
- **`ETag`**, derived from the file's modification time and size. A request with a matching `If-None-Match` gets `304 Not Modified` and no body.
- **`Last-Modified`**, from the file's modification time, for clients that validate with `If-Modified-Since` instead.
- **`Cache-Control: public, max-age=0, must-revalidate`** by default. Browsers keep the file but check with the server before reusing it, and the check costs one `304`. If your asset URLs carry a version or hash, set `Q.web.static.maxAge` to the number of seconds a client may keep a file without asking (`public, max-age=N`). Files that decide how a client behaves later -- `sw.js`, `service-worker.js`, `*.webmanifest`, `manifest.json` -- are always sent with `no-cache, must-revalidate`; `Q.web.static.revalidate` replaces that list of patterns (an empty array turns it off).

Recently served files are held in an in-memory response cache keyed by path and content encoding, and revalidated against the file's modification time at intervals, so a hot file is not re-read from disk on every request. Files up to 1 MB are held (`Q.webserver.fileCache.maxFile`), 64 MB in all (`maxSize`), checked every second (`checkInterval`).

### Which paths are files (`Q.web.static.paths`)

Unset, every file with an allowed extension may be sent as it is. Set to a list of patterns on the request path (`"^/design/[^/]+/stylesheets/"`), only a path one of them matches is served as a file; any other request goes to the front controller as though the file were not there, the way an `.htaccess` that sends everything but its asset directories to `index.php` behaves. This keeps protected uploads in the document root behind the script that checks who is asking. Dot segments are resolved before matching.

### Content types as Apache gives them

`Q.web.mimeTypes` names a `mime.types` file (Apache's `TypesConfig`, `/etc/mime.types` on AlmaLinux and Debian) whose types come first, and `Q.web.defaultCharset` adds `; charset=...` to `text/html` and `text/plain` from it (Apache's `AddDefaultCharset`; unset, none is added, as Apache adds none). An extension the file does not list keeps the server's own type. A host's error document (`errorDocuments`, Apache's `ErrorDocument`) goes out with the type of its file.

### A cluster's shared files (`Q.web.static.roots`)

A cluster keeps what every node makes — packed scripts and stylesheets, text images — on a shared mount rather than in each node's document root, and Apache sends what is not on the local disk to the cluster gateway script. `Q.web.static.roots` lists directories laid out like the document root where a static path is looked for when the document root does not have it:

```json
"Q": { "web": { "static": { "roots": [
    { "dir": "/mnt/e1/dfs/alpha", "match": "^/var/([^/]+/)?cache/(texttoimage|public)/" }
] } } }
```

Only a path `Q.web.static.paths` serves as a file is looked for, never a script (`.php`, `.phar`), and only a file whose real path stays inside the listed directory; `match` limits an entry to its paths. A missing file is not remembered as missing, since another node may write it any moment.

## Compression

Text types (HTML, CSS, JavaScript, JSON, SVG and similar) under 5MB are compressed with gzip when the request's `Accept-Encoding` allows it, and the response carries `Vary: Accept-Encoding`.

By default this happens per request, with the result kept in the in-memory response cache. To compress each file once and keep the results on disk, shared by all workers, enable the precompression cache:

```json
{
  "Q": {
    "webserver": {
      "precompress": { "enabled": true, "maxFiles": 1000, "minSize": 1024, "level": 6 }
    }
  }
}
```

The cache is a directory of `.gz` files (default: `qbixserver-precompress` under the system temp directory, set with `dir`), trimmed to the `maxFiles` most recently served. Because they are real files, a front proxy or CDN can serve them directly.

## Images

Image URLs can carry a size, and a request for a format that does not exist on disk is converted from one that does:

```
/photos/hero.jpg?w=400    resized to 400px wide
/photos/hero.webp         made from hero.png if there is no hero.webp
```

The server also picks AVIF or WebP when the browser accepts them, honors `Save-Data`, and caches every result on disk. How all of this works, and its current limits, is in [images.md](images.md).

## Access-controlled files

To serve a file only after PHP has checked permissions, have the script respond with `X-Accel-Redirect` and the server will stream the file itself. See [headers.md](headers.md).

---
[Image Processing →](images.md) · [← Back to README](../README.md)
