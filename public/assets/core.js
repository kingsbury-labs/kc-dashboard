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

function countdowns() {
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
  return out;
}

function tile(a) {
  const li = el('li', 'agent');
  li.dataset.kind = a.kind;
  li.dataset.state = a.state;
  const disc = el('span', 'disc');
  disc.append(icon(a.kind));
  const st = el('p', 'a-state');
  st.append(el('span', 'dot'), a.state);
  const wave = el('span', 'wave');
  wave.setAttribute('aria-hidden', 'true');
  for (let i = 0; i < 16; i++) wave.append(el('i'));
  li.append(disc, el('p', 'a-name', a.name), st, el('p', 'a-task', a.task), wave);
  return li;
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
  if (list) list.replaceChildren(...(agents.length ? agents.map(tile) : [el('li', 'quiet', 'No agent running.')]));
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
  const cy = o.top + o.height * 0.47;
  const colors = { session: '245, 181, 68', loop: '167, 139, 250', collector: '96, 165, 250' };
  for (const tileNode of document.querySelectorAll('.agent[data-state="busy"]')) {
    const r = tileNode.getBoundingClientRect();
    const x = r.left + r.width / 2;
    const y = r.top;
    const rgb = colors[tileNode.dataset.kind] ?? '56, 189, 248';
    const grad = lctx.createLinearGradient(x, y, cx, cy);
    grad.addColorStop(0, `rgba(${rgb}, 0.7)`);
    grad.addColorStop(1, `rgba(${rgb}, 0.05)`);
    lctx.strokeStyle = grad;
    lctx.lineWidth = 1.2;
    lctx.beginPath();
    lctx.moveTo(x, y);
    lctx.bezierCurveTo(x, y - 60, cx, cy + 80, cx, cy);
    lctx.stroke();
    const p = (t / 1600) % 1;
    const bx = (1 - p) ** 3 * x + 3 * (1 - p) ** 2 * p * x + 3 * (1 - p) * p * p * cx + p ** 3 * cx;
    const by = (1 - p) ** 3 * y + 3 * (1 - p) ** 2 * p * (y - 60) + 3 * (1 - p) * p * p * (cy + 80) + p ** 3 * cy;
    lctx.fillStyle = `rgba(${rgb}, 0.95)`;
    lctx.beginPath();
    lctx.arc(bx, by, 2.5, 0, Math.PI * 2);
    lctx.fill();
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

const initial = [...document.querySelectorAll('.agent')].map((a) => ({ kind: a.dataset.kind, state: a.dataset.state }));
setAgents(initial);
tick();
countdowns();
setInterval(() => {
  if (!document.hidden) tick();
}, 1000);
setInterval(() => countdowns(), 30000);
links();
