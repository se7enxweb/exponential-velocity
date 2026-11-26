## 🗺️ Roadmap

**Coming next:**

- **HTTP long-polling** — Socket.IO's polling transport (`EIO` with
  `transport=polling`) for environments that block WebSocket. The server
  speaks the WebSocket transport only today; the shell's own HTTP polling
  (`/Q/api/shell/poll`) is separate and does not cover Socket.IO clients.

**Shipped, and no longer on this list:**

- **Clustering** — a worker pool per server ([workers.md](workers.md)); several
  servers on the same ports sharing one opcode cache and APCu
  (`Q.webserver.instances` with `Q.webserver.reusePort`, `Q_WebServer_Instances`);
  room leaders with failover across servers (`Q.cluster`, `Q_WebServer_Cluster`);
  and pools per user with upstreams by host ([multiuser.md](multiuser.md)).
- **Windows** — the portable worker pool, HTTPS, a Windows service run by
  `packaging/windows/vcc.ps1`, tested on Windows Server 2022 and 2025
  ([windows.md](windows.md)).
- **HTTP/2** — built in, over TLS (`Q.web.http2.enabled`).

---

---
[← Back to README](../README.md)
