## 🛤️ Clean URL Routing (Optional)

Add `Q.routes` to your config and the server maps clean URLs to handlers — same event pipeline as the [Qbix Platform](https://github.com/Qbix/Platform). No `.php` suffixes, no rewrite rules.

### Config

```json
{
    "Q": {
        "routes": {
            "":                {"module": "app", "action": "welcome"},
            "$module/$action": {}
        }
    }
}
```

Route patterns use `$variable` for dynamic segments. Literal segments match exactly. The matched `module` and `action` determine which handlers fire.

### Handler directory structure

```
handlers/
└── api/
    └── users/
        ├── validate.php    ← runs first (validate input)
        ├── get.php         ← runs on GET requests
        ├── post.php        ← runs on POST requests
        ├── put.php         ← runs on PUT requests
        ├── delete.php      ← runs on DELETE requests
        └── response.php    ← runs last (transform output)
```

### Dispatch pipeline

For `GET /api/users`, the server fires three events in order:

```
1.  api/users/validate   ← validate input, check auth
2.  api/users/get        ← handle the GET method
3.  api/users/response   ← post-process, add headers
```

This is the same pipeline as `Q_Dispatcher` in the full Qbix Platform. Your handlers work identically when you upgrade.

### Example handlers

```php
<?php
// handlers/api/users/validate.php — runs before every method
function api_users_validate(&$params, &$result) {
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit; // safe — forked process
    }
}
```

```php
<?php
// handlers/api/users/get.php — handles GET /api/users
function api_users_get(&$params, &$result) {
    Q_Response::header('Content-Type: application/json');
    echo json_encode(MyApp\Users::list($_GET));
}
```

```php
<?php
// handlers/api/users/post.php — handles POST /api/users
function api_users_post(&$params, &$result) {
    $user = MyApp\Users::create($_POST);
    http_response_code(201);
    Q_Response::header('Content-Type: application/json');
    echo json_encode($user);
}
```

### Priority

```
1. PHP scripts            /legacy.php          → web/legacy.php
                          /legacy.php/extra    → web/legacy.php, PATH_INFO=/extra
2. Static files           /style.css           → web/style.css
3. Routed handlers        /api/users           → handlers/api/users/get.php
4. Front controller       /anything            → web/index.php (if exists)
5. Configurable fallback  /anything            → see below
6. 404
```

Existing files take priority. Routing only activates when `Q.routes` is configured and no file matches. This means you can mix routed handlers with direct PHP scripts — migrate gradually.

Before any of this, a host's own access control and URL rules (`Q.webserver.hosts.<host>.access`, `.rewrite`, `.serverRewrite`, written from its `.htaccess` by the tool that sets the host up) are applied, for every request of that host; see [multiuser.md](multiuser.md) and [migrate-apache.md](migrate-apache.md).

Three settings narrow what counts as a file or script:

- **`Q.webserver.scripts`** lists the scripts that may run when asked for by name (`["/index.php"]`). Unset, every `.php` file inside the document root may. Set, any other script is treated as though it were not there and the request goes to the front controller.
- **`Q.web.static.paths`** does the same for static files; see [static-files.md](static-files.md#which-paths-are-files-qwebstaticpaths).
- **`Q.webserver.frontControllers`** maps patterns on the path to scripts, checked in order (`{"^/api/": "index_rest.php"}`); the first whose script exists wins, otherwise `index.php`. This is decided by the server, because a pooled worker runs the script it is handed and an `.htaccess` rule naming another script is not consulted on that path.

### Fallback — SPA routing, custom 404, catch-all

When nothing matches, the server checks `Q.webserver.fallback` in config. Three options:

**SPA catch-all** — serve `index.html` (answered `200`; a `.php` file named here is run) for all unmatched routes (React, Vue, etc.):

```json
{ "Q": { "webserver": { "fallback": "index.html" } } }
```

**Custom 404 handler** — PHP processes the 404 (logging, custom pages):

```json
{ "Q": { "webserver": { "fallback": {"handler": "app/notfound"} } } }
```

```php
<?php
// handlers/app/notfound/get.php
function app_notfound_get(&$params, &$result) {
    Q_Response::code(404);
    Q_Response::header('Content-Type: text/html');
    echo Q::view('app/404.php', ['path' => $_SERVER['REQUEST_URI']]);
}
```

**Static 404 page** — serve a file without invoking PHP, answered `404` with its file's content type:

```json
{ "Q": { "webserver": { "fallback": {"file": "404.html"} } } }
```

### The full symmetry

```
Static files:    GET /style.css            → web/style.css
PHP scripts:     GET /page.php             → web/page.php (direct execution)
HTTP routed:     GET /api/users            → handlers/api/users/get.php
WebSocket:       {"event":"chat/message"}  → handlers/chat/message.php

The last three use classes/ (preloaded, shared)
The last two use handlers/ (loaded on demand)
```

Drop files. They work. No framework to learn, no boilerplate to write. When you outgrow it, the same handlers run on the full Qbix Platform.

---

---
[← Back to README](../README.md)

