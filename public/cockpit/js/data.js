// The cockpit's data: api/cockpit.php every 10 seconds (sessions, agents, talk, files, loops), and live session state
// from api/wall-stream.php's `event: now`. EventSource cannot send X-KC-Token, so each stream starts with a one-use
// ticket fetched with the token, as wall.js does. Both run only while the tab is visible.

const SNAPSHOT = '/api/cockpit.php';
const STREAM = '/api/wall-stream.php';
const POLL_MS = 10000;
const RETRY_MS = 3000;

const token = document.querySelector('meta[name="kc-token"]')?.content ?? '';
const listeners = new Set();
const SKELETON_MS = 4000;
export const state = { snap: null, now: null, error: null, at: 0, late: false };

// Skeletons hold only for the first 4 seconds; after that, or on an error, the panels say so in words.
export function waiting() {
  if (state.snap) return '';
  return state.error ? 'Could not load, retrying' : state.late ? 'Still loading' : 'Loading.';
}

export function skeletonOn() {
  return !state.snap && !state.late && !state.error;
}

export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function emit() {
  listeners.forEach((fn) => {
    try { fn(state); } catch { /* one bad panel leaves the others drawn */ }
  });
}

async function poll() {
  try {
    const res = await fetch(SNAPSHOT, { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) throw new Error(`cockpit ${res.status}`);
    state.snap = await res.json();
    state.error = null;
    state.at = Date.now();
  } catch (e) {
    state.error = String(e.message || e);
  }
  emit();
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

let source = null;
let opening = false;
let retry = null;
let timer = null;

function later() {
  clearTimeout(retry);
  retry = setTimeout(connect, RETRY_MS);
}

// The server ends each stream after 55 seconds and a ticket works once: on any error, a fresh ticket.
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
  if (document.visibilityState !== 'visible') {
    fresh.close();
    return;
  }
  source = fresh;
  fresh.addEventListener('now', (event) => {
    try {
      state.now = JSON.parse(event.data);
      emit();
    } catch { /* a bad event leaves the last state */ }
  });
  fresh.addEventListener('error', () => {
    fresh.close();
    if (source === fresh) source = null;
    later();
  });
}

function start() {
  connect();
  poll();
  clearInterval(timer);
  timer = setInterval(poll, POLL_MS);
}

function stop() {
  clearInterval(timer);
  clearTimeout(retry);
  source?.close();
  source = null;
}

export function begin() {
  setTimeout(() => {
    state.late = true;
    if (!state.snap) emit();
  }, SKELETON_MS);
  document.addEventListener('visibilitychange', () => (document.visibilityState === 'visible' ? start() : stop()));
  if (document.visibilityState === 'visible') start();
}

// Live status from the stream over the snapshot's own state. The registry names a project, not a session, so it
// applies to that project's newest interactive session, as the API does.
export function liveState(s) {
  if (!s.newest) return s.state;
  const live = (state.now?.sessions ?? []).filter((x) => x.project === s.project);
  if (live.some((x) => x.status === 'waiting')) return 'waiting';
  if (live.some((x) => x.status === 'busy')) return 'working';
  return s.state;
}

// Everything Find searches: projects, sessions, agents and recently touched files.
export function findItems() {
  const s = state.snap;
  if (!s) return [];
  const out = [];
  for (const p of s.projects ?? []) out.push({ kind: 'project', label: p.name, detail: 'project', path: `project/${encodeURIComponent(p.name)}` });
  for (const x of s.sessions ?? []) {
    out.push({ kind: 'session', label: x.title || `${x.project} ${x.session.slice(0, 8)}`, detail: `${x.project} · ${x.kind} · ${x.session.slice(0, 8)}`, path: `session/${encodeURIComponent(x.session)}` });
  }
  for (const a of s.agents ?? []) {
    out.push({ kind: 'agent', label: `${a.type} ${a.key.split(':')[1]?.slice(0, 8) ?? ''}`, detail: `${a.project} · ${a.description}`, path: `session/${encodeURIComponent(a.session)}` });
  }
  for (const f of s.files ?? []) {
    out.push({ kind: 'file', label: f.path, detail: f.op, path: `session/${encodeURIComponent(f.session.split(':')[0])}` });
  }
  return out;
}
