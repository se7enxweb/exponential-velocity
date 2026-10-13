// Runs the dashboard's own script (rendered, argv[2]) under node with a fake
// browser: a DOM that absorbs everything, a scripted WebSocket and fetch, and
// a virtual clock. Plays one scenario (argv[3]) and prints, as JSON, the
// header status seen after every step: [{t, state, cls, text, url}].
// Driven by tests/unit-dashboard-live-status-client.php.
'use strict';
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');
const scenario = process.argv[3];
const proto = process.argv[4] || 'https:';

let now = 1e12;
let timers = [];
let nextId = 1;
function addTimer(fn, ms, repeat) {
  const id = nextId++;
  timers.push({ id, at: now + Math.max(ms || 0, 0), fn, ms: Math.max(ms || 0, 1), repeat });
  return id;
}
function clearTimer(id) { timers = timers.filter((t) => t.id !== id); }
function advance(ms) {
  const end = now + ms;
  for (;;) {
    timers.sort((a, b) => a.at - b.at || a.id - b.id);
    const t = timers[0];
    if (!t || t.at > end) break;
    now = t.at;
    if (t.repeat) t.at = now + t.ms; else timers.shift();
    t.fn();
  }
  now = end;
}

// A DOM that takes any property or call and gives back more of itself.
const elements = {};
function stub(name) {
  const o = { innerHTML: '', textContent: '', value: '', style: {}, dataset: {}, children: [], childNodes: [] };
  return new Proxy(o, {
    get(target, p) {
      if (p in target) return target[p];
      if (p === 'classList') return { add() {}, remove() {}, toggle() {}, contains() { return false; } };
      if (p === 'getAttribute') return () => '';
      if (p === 'querySelectorAll' || p === 'getElementsByTagName' || p === 'getElementsByClassName') return () => [];
      if (p === 'querySelector') return () => stub(name + '>q');
      if (p === 'getContext') return () => stub('ctx');
      if (p === 'getBoundingClientRect') return () => ({ width: 100, height: 20, top: 0, left: 0 });
      if (p === 'firstChild' || p === 'lastChild' || p === 'parentNode' || p === 'nextSibling') return null;
      if (typeof p === 'symbol') return undefined;
      return function () { return stub(name + '.' + String(p)); };
    },
    set(target, p, v) { target[p] = v; return true; },
  });
}
const docListeners = {};
const winListeners = {};
const document = {
  hidden: false,
  getElementById(id) { return elements[id] || (elements[id] = stub(id)); },
  querySelector() { return stub('q'); },
  querySelectorAll() { return []; },
  createElement(t) { return stub(t); },
  createTextNode() { return stub('text'); },
  addEventListener(ev, fn) { (docListeners[ev] = docListeners[ev] || []).push(fn); },
  body: stub('body'),
  documentElement: stub('html'),
};

// The scripted server side.
const sockets = [];
const server = { accept: true, statusCode: 403, down: false };
class FakeWebSocket {
  constructor(url) {
    this.url = url;
    sockets.push(this);
    const ok = server.accept && !server.down;
    addTimer(() => {
      if (ok) { if (this.onopen) this.onopen({}); this.beat(); }
      else if (this.onclose) this.onclose({ code: 1006 });
    }, 30, false);
  }
  beat() {
    // A heartbeat every 2 s while open and the server is not silent.
    this.hb = addTimer(() => {
      if (!this.closed && !server.silent && this.onmessage) {
        this.onmessage({ data: JSON.stringify({ type: 'heartbeat', stats: vm.runInContext('S', ctx) }) });
      }
    }, 2000, true);
  }
  serverClose(code) {
    if (this.closed) return;
    this.closed = true; clearTimer(this.hb);
    if (this.onclose) this.onclose({ code });
  }
  close() { this.closed = true; clearTimer(this.hb); }
}
function fetchStub() {
  return new Promise((resolve, reject) => {
    if (server.down) { reject(new Error('down')); return; }
    const st = server.accept ? 200 : server.statusCode;
    resolve({
      status: st,
      json: () => Promise.resolve(st === 200 ? { ok: true } : { ok: false, reason: 'signed-out', signIn: '/Q/panel/(next)/L1EvZGFzaGJvYXJk' }),
    });
  });
}

const window = {
  addEventListener(ev, fn) { (winListeners[ev] = winListeners[ev] || []).push(fn); },
  fetch: fetchStub,
  IntersectionObserver: undefined,
};
const location = { protocol: proto, host: 'example.test:8080', origin: proto + '//example.test:8080', pathname: '/Q/dashboard', search: '' };
const ctx = {
  window, document, location, WebSocket: FakeWebSocket, fetch: fetchStub,
  setTimeout: (f, ms) => addTimer(f, ms, false), clearTimeout: clearTimer,
  setInterval: (f, ms) => addTimer(f, ms, true), clearInterval: clearTimer,
  requestAnimationFrame: (f) => addTimer(f, 16, false),
  btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
  navigator: { userAgent: 'node' }, console,
  Date: class extends Date { constructor(...a) { if (a.length) super(...a); else super(now); } static now() { return now; } },
  Math, JSON, Array, Object, String, Number, parseInt, parseFloat, isNaN, Promise,
};
ctx.window.document = document;
vm.createContext(ctx);
vm.runInContext(code, ctx);

const seen = [];
function look(label) {
  const sub = (elements.sub && elements.sub.innerHTML) || '';
  const st = /data-state="([a-z]+)"/.exec(sub);
  const cls = /<span class="wd( on| wait)?" id="wd">/.exec(sub);
  const txt = /<span id="wl">([^<]*)<\/span>/.exec(sub);
  const last = sockets[sockets.length - 1];
  seen.push({ label, t: now - 1e12, state: st ? st[1] : null,
    color: cls ? (cls[1] === ' on' ? 'green' : cls[1] === ' wait' ? 'amber' : 'red') : null,
    text: txt ? txt[1] : null, signIn: /id="wsin" href="([^"]+)"/.test(sub),
    sockets: sockets.length, url: last ? last.url : null });
}
async function step(ms, label) {
  // Let promise callbacks (fetch) run between timer steps.
  const slice = 50;
  for (let done = 0; done < ms; done += slice) { advance(Math.min(slice, ms - done)); await new Promise((r) => setImmediate(r)); }
  look(label);
}
const open = () => sockets.filter((s) => !s.closed);

(async () => {
  await step(100, 'loaded');
  if (scenario === 'restart') {
    await step(3000, 'live');
    open().forEach((s) => s.serverClose(1006)); server.down = true;
    await step(100, 'restarting');
    await step(2900, 'still restarting');
    server.down = false;
    await step(10000, 'back');
  } else if (scenario === 'signedout') {
    server.accept = false;
    open().forEach((s) => s.serverClose(1006));
    await step(3000, 'refused');
    await step(30000, 'still refused');
    server.accept = true;
    await step(11000, 'signed in elsewhere');
  } else if (scenario === 'ended') {
    await step(2000, 'live');
    server.accept = false;
    open().forEach((s) => s.serverClose(4401));
    await step(100, 'told');
  } else if (scenario === 'down') {
    server.down = true;
    open().forEach((s) => s.serverClose(1006));
    await step(60000, 'down a minute');
    const before = sockets.length;
    await step(60000, 'down two minutes');
    seen.push({ label: 'attempts in the second minute', n: sockets.length - before });
    server.down = false;
    await step(11000, 'back');
  } else if (scenario === 'silent') {
    await step(3000, 'live');
    server.silent = true;
    await step(13000, 'silent');
    server.silent = false;
    await step(3000, 'after');
  } else if (scenario === 'visible') {
    server.down = true;
    open().forEach((s) => s.serverClose(1006));
    await step(30000, 'hidden, down');
    server.down = false;
    const before = sockets.length;
    document.hidden = false;
    (docListeners.visibilitychange || []).forEach((f) => f());
    await step(200, 'shown');
    seen.push({ label: 'a connect at once when shown', n: sockets.length - before });
  }
  process.stdout.write(JSON.stringify(seen));
})();
