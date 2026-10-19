// The wall: a Fullscreen button, a screen wake lock and the running strip fed by api/wall-stream.php.
// EventSource cannot send X-KC-Token, so each stream starts with a one-use ticket fetched with the token.
// The page renders everything without JS; every value from the server is written with textContent.
import { el, token } from './app.js';

const list = document.getElementById('running');
const full = document.getElementById('wall-full');
const note = document.getElementById('wall-note');
const STREAM = '/api/wall-stream.php';
const RETRY_MS = 3000;
let lock = null;

// Same items as now.js.
function item(kind, name, what) {
  const li = el('li');
  li.dataset.kind = kind;
  li.append(el('span', 'name', name), ' ', el('span', 'tag', what));
  return li;
}

function items(data) {
  const out = [];
  for (const s of data.sessions ?? []) {
    if (s.status !== 'busy' && s.status !== 'waiting') continue;
    const what = s.status === 'waiting' && s.waitingFor ? `waiting: ${s.waitingFor}` : s.status;
    out.push(item('session', s.project ?? s.name ?? 'session', what));
  }
  for (const l of data.loops ?? []) {
    if (l.state === 'running') out.push(item('loop', l.project ?? '', `card ${l.card ?? '?'}`));
  }
  for (const t of data.tasks ?? []) {
    if (t.failed === true) out.push(item('task', t.name ?? '', `failed ${t.lastResult ?? ''}`));
  }
  for (const p of data.ports ?? []) {
    out.push(item('port', `:${p.port ?? ''}`, p.program ?? ''));
  }
  if (out.length === 0) out.push(el('li', 'quiet', 'Nothing running.'));
  return out;
}

async function ticket() {
  const res = await fetch(STREAM, {
    method: 'POST',
    cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-KC-Token': token },
    body: '{}',
  });
  if (!res.ok) throw new Error(`ticket ${res.status}`);
  return (await res.json()).ticket;
}

// The server ends each stream after 55 seconds and a ticket works once, so the browser's own reconnect
// would be refused: on any error this closes the stream and starts a new one with a fresh ticket.
// The stream runs only while the tab is visible, like now.js's polling.
let source = null;
let retry = null;
let opening = false;

function later() {
  clearTimeout(retry);
  retry = setTimeout(() => connect(), RETRY_MS);
}

async function connect() {
  if (source || opening || document.visibilityState !== 'visible') return;
  opening = true;
  let fresh;
  try {
    fresh = new EventSource(`${STREAM}?ticket=${encodeURIComponent(await ticket())}`);
  } catch {
    opening = false;
    later();
    return;
  }
  opening = false;
  // Hidden while the ticket was on its way: spend nothing more and wait for the tab to come back.
  if (document.visibilityState !== 'visible') {
    fresh.close();
    return;
  }
  source = fresh;
  fresh.addEventListener('now', (event) => {
    try {
      list.replaceChildren(...items(JSON.parse(event.data)));
    } catch {
      // A bad event leaves the strip as it was.
    }
  });
  fresh.addEventListener('error', () => {
    fresh.close();
    if (source === fresh) source = null;
    later();
  });
}

function disconnect() {
  clearTimeout(retry);
  retry = null;
  source?.close();
  source = null;
}

// The browser drops the lock whenever the tab is hidden, so it is taken again each time it comes back.
async function wake() {
  if (!('wakeLock' in navigator) || document.visibilityState !== 'visible' || (lock && !lock.released)) return;
  try {
    lock = await navigator.wakeLock.request('screen');
  } catch {
    note.textContent = 'The screen may sleep: the browser refused the wake lock.';
  }
}

if (full && document.fullscreenEnabled) {
  full.hidden = false;
  full.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen().catch(() => {
      note.textContent = 'Fullscreen was refused.';
    });
  });
  document.addEventListener('fullscreenchange', () => {
    full.textContent = document.fullscreenElement ? 'Exit fullscreen' : 'Fullscreen';
  });
}

document.addEventListener('visibilitychange', () => {
  wake();
  if (!list) return;
  if (document.visibilityState === 'visible') connect();
  else disconnect();
});
wake();
if (list) connect();
