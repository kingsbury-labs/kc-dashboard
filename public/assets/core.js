// The command center's live parts: the clock, the timeline countdowns, Ctrl+K to the Ask box, and applyNow, which
// rebuilds the agents, gauges and local model tile from an api/now.php snapshot (now.js polls, wall.js streams).
// Busy agents get a pulsing light line to the core. Every value from the server is written with textContent.
import { el } from './app.js';
import { setAgents } from './orb.js';

const still = window.matchMedia('(prefers-reduced-motion: reduce)');
const clock = document.getElementById('clock');
const date = document.getElementById('clock-date');

function tick() {
  if (!clock) return;
  const now = new Date();
  clock.textContent = now.toLocaleTimeString('en-CA', { hour12: false });
  clock.dateTime = now.toISOString();
  if (date) date.textContent = now.toLocaleDateString('en-CA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

// Same wording as View::eta.
function eta(at) {
  const d = at - Math.floor(Date.now() / 1000);
  const a = Math.abs(d);
  let text;
  if (a < 60) return 'now';
  if (a < 3600) text = `${Math.floor(a / 60)} min`;
  else if (a < 86400) text = `${Math.floor(a / 3600)} h${Math.floor((a % 3600) / 60) ? ` ${Math.floor((a % 3600) / 60)} min` : ''}`;
  else text = `${Math.floor(a / 86400)} d`;
  return d > 0 ? `in ${text}` : `${text} ago`;
}

// Same sums as View::nextRun, in the browser's own time zone: a repeating job's next slot since midnight, or a
// daily job's time today or tomorrow, and how far through its period it is.
function nextRun(li, nowMs) {
  const midnight = new Date(nowMs);
  midnight.setHours(0, 0, 0, 0);
  const every = Number(li.dataset.every);
  if (every > 0) {
    const period = every * 60000;
    const next = midnight.getTime() + (Math.floor((nowMs - midnight.getTime()) / period) + 1) * period;
    return [next, 1 - (next - nowMs) / period];
  }
  const hm = /^(\d{2}):(\d{2})$/.exec(li.dataset.daily ?? '');
  if (!hm) return null;
  let next = midnight.getTime() + (Number(hm[1]) * 60 + Number(hm[2])) * 60000;
  if (next <= nowMs) next += 86400000;
  return [next, 1 - (next - nowMs) / 86400000];
}

function countdowns() {
  const nowMs = Date.now();
  for (const li of document.querySelectorAll('.mission[data-every], .mission[data-daily]')) {
    const run = nextRun(li, nowMs);
    const p = li.querySelector('.eta');
    const time = li.querySelector('time');
    if (!run || !p || !time) continue;
    const at = new Date(run[0]);
    p.dataset.at = String(Math.floor(run[0] / 1000));
    time.dateTime = at.toISOString();
    time.textContent = at.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit', hour12: false });
    li.querySelector('.m-fill')?.setAttribute('width', String(Math.max(2, Math.min(100, Math.round(run[1] * 100)))));
  }
  for (const p of document.querySelectorAll('.mission .eta[data-at]')) {
    const time = p.parentElement.querySelector('time');
    if (time && time.dateTime.includes('T')) p.textContent = eta(Number(p.dataset.at));
  }
}

const ICONS = { session: 'M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14', loop: 'M20 12a8 8 0 1 1-3-6.2M20 4v5h-5', collector: 'M12 3v12M7 10l5 5 5-5M4 20h16' };
const SVG = 'http://www.w3.org/2000/svg';

function icon(kind) {
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('class', 'ico');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', ICONS[kind] ?? ICONS.session);
  svg.append(path);
  return svg;
}

const unregistered = [...document.querySelectorAll('.agent[data-state="off"] .a-name')].map((n) => n.textContent);

// Same list as View::agents.
function agentList(data) {
  const out = [];
  const stale = data.stale === true;
  for (const s of data.sessions ?? []) {
    const status = ['busy', 'waiting', 'idle'].includes(s.status) ? s.status : 'idle';
    const task = status === 'waiting' && s.waitingFor ? `waiting: ${s.waitingFor}` : s.name ?? '';
    out.push({ kind: 'session', name: s.project ?? s.name ?? 'session', state: stale ? 'stale' : status, task });
  }
  for (const l of data.loops ?? []) {
    const state = { running: 'busy', stopped: 'idle', stale: 'stale' }[l.state];
    if (!state) continue;
    out.push({ kind: 'loop', name: l.project ?? '', state, task: `${l.card != null ? `card ${l.card}` : 'no card'}${l.note != null ? `: ${l.note}` : ''}` });
  }
  for (const t of data.tasks ?? []) {
    const failed = t.failed === true;
    const state = failed ? 'failed' : String(t.status ?? '').toLowerCase() === 'running' ? 'busy' : 'idle';
    out.push({ kind: 'collector', name: t.name ?? '', state, task: failed ? `failed ${t.lastResult ?? ''}` : t.lastRun ? `last run ${t.lastRun}` : 'not run yet' });
  }
  // The scheduled jobs the server found unregistered stay as off collectors until Task Scheduler has them.
  const registered = (data.tasks ?? []).map((t) => String(t.name ?? '').toLowerCase());
  for (const name of unregistered) {
    if (!registered.includes(name.toLowerCase())) out.push({ kind: 'collector', name, state: 'off', task: 'not registered' });
  }
  return out;
}

// Same path as View::sinePath: one sine across 360 units, the view shows 120 of them.
function sinePath(period, amp) {
  let d = 'M0 12';
  for (let x = 2; x <= 360; x += 2) d += `L${x} ${(12 - amp * Math.sin((x * 2 * Math.PI) / period)).toFixed(1)}`;
  return d;
}
const SINES = [['w1', sinePath(40, 7)], ['w2', sinePath(60, 4)]];

function sine() {
  const wave = el('span', 'wave');
  wave.setAttribute('aria-hidden', 'true');
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', '0 0 120 24');
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('focusable', 'false');
  for (const [cls, d] of SINES) {
    const path = document.createElementNS(SVG, 'path');
    path.setAttribute('class', cls);
    path.setAttribute('d', d);
    svg.append(path);
  }
  wave.append(svg);
  return wave;
}

function tile(a, tone) {
  const li = el('li', 'agent');
  li.dataset.kind = a.kind;
  li.dataset.state = a.state;
  li.dataset.tone = String(tone % 4);
  const disc = el('span', 'disc');
  disc.append(icon(a.kind));
  const st = el('p', 'a-state');
  st.append(el('span', 'dot'), a.state);
  li.append(disc, el('p', 'a-name', a.name), st, el('p', 'a-task', a.task), sine());
  return li;
}

// The feed's live, warn and tip rows, as View::feedLive builds them; the headline rows are left as they are.
const FEED_ICONS = { LIVE: ICONS.loop, WARN: 'M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4', TIP: 'M7 7h10v10H7zM10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4' };
const SYSTEM_NOTES = [['disk', 90, 'Disk %d% full', 'System drive is nearly out of space'], ['ram', 85, 'RAM at %d%', 'Memory pressure is high'],
  ['cpu', 90, 'CPU at %d%', 'Something is working the processor hard']];

function feedRow(tag, title, line) {
  const li = el('li', 'fi');
  li.dataset.src = 'live';
  li.dataset.tag = tag;
  const disc = el('span', 'disc');
  const svg = icon('session');
  svg.querySelector('path')?.setAttribute('d', FEED_ICONS[tag]);
  disc.append(svg);
  const t = el('span', 'feed-tag', tag);
  t.dataset.tag = tag;
  li.append(disc, el('p', 'fi-title', title), el('p', 'fi-line', line), t);
  return li;
}

function feedLive(data) {
  const box = document.getElementById('feed-more');
  if (!box) return;
  const rows = [];
  for (const l of data.loops ?? []) {
    if (l.state === 'running') rows.push(feedRow('LIVE', `${l.project ?? ''} loop running`, `${l.card != null ? `card ${l.card}` : 'between cards'}${l.note != null ? `: ${l.note}` : ''}`));
  }
  for (const s of data.sessions ?? []) {
    if (s.status !== 'busy' && s.status !== 'waiting') continue;
    rows.push(feedRow('LIVE', `${s.project ?? s.name ?? 'session'} session ${s.status}`, s.status === 'waiting' && s.waitingFor ? `waiting: ${s.waitingFor}` : s.name ?? 'Claude Code'));
  }
  for (const t of data.tasks ?? []) {
    if (t.failed === true) rows.push(feedRow('WARN', `${t.name ?? ''} failed`, `last result ${t.lastResult ?? '?'}`));
  }
  const sys = data.system && data.system.connected === true ? data.system : {};
  for (const [key, limit, title, line] of SYSTEM_NOTES) {
    if (Number.isInteger(sys[key]) && sys[key] >= limit) rows.push(feedRow('TIP', title.replace('%d', String(sys[key])), line));
  }
  for (const old of box.querySelectorAll('[data-src="live"]')) old.remove();
  box.prepend(...rows);
}

// Numbers count up to a new value unless motion is reduced.
function countTo(node, to, suffix) {
  const from = Number(node.dataset.value || 0);
  node.dataset.value = String(to);
  if (still.matches || from === to) {
    node.textContent = `${to}${suffix}`;
    return;
  }
  const t0 = performance.now();
  const step = (now) => {
    const p = Math.min(1, (now - t0) / 600);
    node.textContent = `${Math.round(from + (to - from) * p)}${suffix}`;
    if (p < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

function gauge(id, pct) {
  const li = document.getElementById(`gauge-${id}`);
  if (!li) return;
  const on = Number.isInteger(pct);
  li.dataset.on = on ? '1' : '0';
  li.querySelector('.g-fill')?.setAttribute('stroke-dasharray', `${on ? Math.max(0, Math.min(100, pct)) : 0} 100`);
  const value = li.querySelector('.g-value');
  if (!value) return;
  if (on) countTo(value, pct, '%');
  else value.textContent = '--';
}

export function applyNow(data) {
  if (!data || typeof data !== 'object') return;
  const list = document.getElementById('agent-list');
  const agents = agentList(data);
  const tones = {};
  const tiles = agents.map((a) => {
    tones[a.kind] = (tones[a.kind] ?? -1) + 1;
    return tile(a, tones[a.kind]);
  });
  if (list) list.replaceChildren(...(tiles.length ? tiles : [el('li', 'quiet', 'No agent running.')]));
  feedLive(data);
  const busy = agents.filter((a) => a.state === 'busy').length;
  const meta = document.getElementById('agents-meta');
  if (meta) meta.textContent = `${busy} busy of ${agents.length}`;
  setAgents(agents);
  const sys = data.system && data.system.connected === true ? data.system : null;
  const monitor = document.getElementById('monitor');
  if (monitor) {
    monitor.dataset.on = sys ? '1' : '0';
    const nc = monitor.querySelector('.nc');
    if (sys && nc) nc.remove();
    if (!sys && !nc) monitor.append(el('p', 'nc', 'not connected'));
  }
  for (const id of ['cpu', 'ram', 'disk']) gauge(id, sys ? sys[id] : null);
  const local = document.getElementById('llm-local');
  if (local) {
    local.dataset.on = sys?.llama === true ? '1' : '0';
    const p = local.querySelector('.p-state');
    if (p) p.textContent = sys ? (sys.llama ? 'Running' : 'Off') : 'not connected';
  }
  const llm = document.getElementById('llm-meta');
  if (llm) llm.textContent = `${Number(llm.dataset.models || 0) + (sys?.llama === true ? 1 : 0)} connected`;
  const net = document.getElementById('f-net');
  const ports = Array.isArray(data.ports) ? data.ports.length : 0;
  if (net) net.textContent = `${ports} dev ${ports === 1 ? 'server' : 'servers'}`;
}

// Light lines from each busy agent tile to the core, pulsing outward. Wide layouts only; one still frame when
// motion is reduced; nothing while the tab is hidden.
let layer = null;
let lctx = null;
let lframe = 0;
const wide = window.matchMedia('(min-width: 64rem)');

function drawLinks(t) {
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  if (layer.width !== Math.round(innerWidth * dpr)) {
    layer.width = Math.round(innerWidth * dpr);
    layer.height = Math.round(innerHeight * dpr);
  }
  lctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  lctx.clearRect(0, 0, innerWidth, innerHeight);
  const orb = document.getElementById('orb');
  if (!orb || !wide.matches) return;
  const o = orb.getBoundingClientRect();
  const cx = o.left + o.width / 2;
  const cy = o.top + o.height * 0.45;
  const colors = { session: '245, 181, 68', loop: '167, 139, 250', collector: '96, 165, 250' };
  let i = 0;
  for (const tileNode of document.querySelectorAll('.agent[data-state="busy"]')) {
    const r = tileNode.getBoundingClientRect();
    const x = r.left + r.width / 2;
    const y = r.top + r.height / 2;
    const rgb = colors[tileNode.dataset.kind] ?? '56, 189, 248';
    // A soft S-curve: out of the tile sideways, then up into the core from below.
    const c1 = [x + (cx - x) * 0.6, y];
    const c2 = [cx, cy + (y - cy) * 0.55];
    const grad = lctx.createLinearGradient(x, y, cx, cy);
    grad.addColorStop(0, `rgba(${rgb}, 0.55)`);
    grad.addColorStop(1, `rgba(${rgb}, 0.12)`);
    lctx.strokeStyle = grad;
    lctx.lineWidth = 1.5;
    lctx.shadowColor = `rgba(${rgb}, 0.9)`;
    lctx.shadowBlur = 6;
    lctx.beginPath();
    lctx.moveTo(x, y);
    lctx.bezierCurveTo(c1[0], c1[1], c2[0], c2[1], cx, cy);
    lctx.stroke();
    for (const lag of [0, 0.5]) {
      const p = (t / 2200 + lag + i * 0.17) % 1;
      const q = 1 - p;
      const bx = q ** 3 * x + 3 * q * q * p * c1[0] + 3 * q * p * p * c2[0] + p ** 3 * cx;
      const by = q ** 3 * y + 3 * q * q * p * c1[1] + 3 * q * p * p * c2[1] + p ** 3 * cy;
      lctx.fillStyle = `rgba(${rgb}, 1)`;
      lctx.shadowBlur = 12;
      lctx.beginPath();
      lctx.arc(bx, by, 2.5, 0, Math.PI * 2);
      lctx.fill();
    }
    lctx.shadowBlur = 0;
    i += 1;
  }
}

function linkLoop(t) {
  drawLinks(t);
  lframe = requestAnimationFrame(linkLoop);
}

function links() {
  if (!document.getElementById('orb') || !document.getElementById('agent-list')) return;
  layer = document.createElement('canvas');
  layer.className = 'links-layer';
  layer.setAttribute('aria-hidden', 'true');
  document.body.append(layer);
  lctx = layer.getContext('2d');
  if (!lctx) return;
  const run = () => {
    cancelAnimationFrame(lframe);
    lframe = 0;
    if (still.matches || document.hidden) drawLinks(0);
    else lframe = requestAnimationFrame(linkLoop);
  };
  document.addEventListener('visibilitychange', run);
  still.addEventListener?.('change', run);
  window.addEventListener('resize', () => drawLinks(0));
  run();
}

document.addEventListener('keydown', (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    const ask = document.getElementById('ask-question');
    if (ask) {
      event.preventDefault();
      ask.focus();
    }
  }
});

// The network tile: the browser's own online flag.
function online() {
  const node = document.getElementById('f-online');
  if (node) node.textContent = navigator.onLine ? 'Online' : 'Offline';
}
window.addEventListener('online', online);
window.addEventListener('offline', online);
online();

const initial = [...document.querySelectorAll('.agent')].map((a) => ({ kind: a.dataset.kind, state: a.dataset.state }));
setAgents(initial);
tick();
countdowns();
setInterval(() => {
  if (!document.hidden) tick();
}, 1000);
setInterval(() => countdowns(), 30000);
links();
