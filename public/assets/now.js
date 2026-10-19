// The running strip. Polls api/now.php every 5 seconds while the tab is visible and stops while it is hidden.
// The page already renders the strip without JS; every value from the server is written with textContent.
import { el, token } from './app.js';

const list = document.getElementById('running');
const POLL_MS = 5000;
let timer = null;

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

async function refresh() {
  try {
    const res = await fetch('/api/now.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) return;
    list.replaceChildren(...items(await res.json()));
  } catch {
    // Keep what is showing; the next poll tries again.
  }
}

function start() {
  if (timer !== null) return;
  refresh();
  timer = setInterval(() => refresh(), POLL_MS);
}

function stop() {
  clearInterval(timer);
  timer = null;
}

if (list) {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') start();
    else stop();
  });
  if (document.visibilityState === 'visible') start();
}
