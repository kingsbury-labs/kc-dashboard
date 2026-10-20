// The AI core: a wireframe globe on a 2D canvas, no library. Lat/long lines turn about once every 40 seconds,
// nodes on the sphere twinkle, two tilted orbit rings counter-rotate, a ripple floor spreads under it, and each
// agent rides the outer orbit in its colour. Thinking and speaking turn it faster and brighter. Without JS the
// CSS globe in hud.css shows instead; with reduced motion one still frame is drawn.
let level = 0;
let state = 'idle';
let agents = [];
let canvas = null;
let ctx = null;
let frame = 0;
let last = 0;
let spin = 0;
let t = 0;

const STATES = ['idle', 'thinking', 'speaking'];
const COLORS = { session: '#f5b544', loop: '#a78bfa', collector: '#60a5fa', failed: '#f87171' };
const TURN = (Math.PI * 2) / 40;
const TILT = 0.38;

// Fixed nodes on the sphere: a golden-angle spiral, so they spread evenly with no random seed to carry.
const NODES = Array.from({ length: 46 }, (_, i) => {
  const y = 1 - (i + 0.5) * (2 / 46);
  return { lat: Math.asin(y), lon: i * 2.39996, phase: i * 1.7 };
});

export function setLevel(value) {
  const n = Number(value);
  level = Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 0;
}

export function setState(next) {
  if (!STATES.includes(next)) return;
  state = next;
  if (canvas?.parentElement) canvas.parentElement.dataset.state = next;
}

// [{kind, state}], from core.js. A failed agent is drawn red, a busy one larger and pulsing.
export function setAgents(list) {
  agents = Array.isArray(list) ? list.slice(0, 24) : [];
  if (ctx && !frame) draw();
}

function project(lat, lon, r, cx, cy) {
  const x = Math.cos(lat) * Math.sin(lon + spin);
  const y = Math.sin(lat);
  const z = Math.cos(lat) * Math.cos(lon + spin);
  const y2 = y * Math.cos(TILT) - z * Math.sin(TILT);
  const z2 = y * Math.sin(TILT) + z * Math.cos(TILT);
  return { x: cx + x * r, y: cy - y2 * r, z: z2 };
}

function line(points, color, width) {
  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1];
    const b = points[i];
    ctx.globalAlpha = 0.12 + Math.max(0, (a.z + b.z) / 2) * 0.55;
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.stroke();
  }
}

function draw() {
  const w = canvas.width;
  const h = canvas.height;
  const cx = w / 2;
  const cy = h * 0.47;
  const r = Math.min(w, h) * 0.3;
  const energy = state === 'thinking' ? 1 : state === 'speaking' ? 0.6 + level * 0.4 : 0.35;
  const cyan = '#22d3ee';
  ctx.clearRect(0, 0, w, h);

  // Ripple floor: rings spreading out under the globe.
  const floorY = cy + r * 1.22;
  for (let i = 0; i < 4; i++) {
    const p = ((t * 0.25 + i / 4) % 1);
    ctx.globalAlpha = (1 - p) * 0.45;
    ctx.strokeStyle = cyan;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.ellipse(cx, floorY, r * (0.5 + p * 1.1), r * (0.1 + p * 0.22), 0, 0, Math.PI * 2);
    ctx.stroke();
  }

  // Glow behind the sphere.
  const glow = ctx.createRadialGradient(cx, cy, r * 0.1, cx, cy, r * 1.6);
  glow.addColorStop(0, `rgba(56, 189, 248, ${0.28 + energy * 0.2})`);
  glow.addColorStop(0.55, 'rgba(14, 116, 144, 0.12)');
  glow.addColorStop(1, 'rgba(3, 7, 18, 0)');
  ctx.globalAlpha = 1;
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, w, h);

  // Latitude and longitude lines.
  for (let k = -5; k <= 5; k++) {
    const lat = (k / 6) * (Math.PI / 2);
    const pts = [];
    for (let i = 0; i <= 48; i++) pts.push(project(lat, (i / 48) * Math.PI * 2, r, cx, cy));
    line(pts, '#38bdf8', 1);
  }
  for (let k = 0; k < 12; k++) {
    const lon = (k / 12) * Math.PI * 2;
    const pts = [];
    for (let i = 0; i <= 32; i++) pts.push(project(-Math.PI / 2 + (i / 32) * Math.PI, lon, r, cx, cy));
    line(pts, '#38bdf8', 1);
  }

  // Nodes: glow on the front face, twinkling.
  for (const n of NODES) {
    const p = project(n.lat, n.lon, r, cx, cy);
    if (p.z < -0.1) continue;
    const tw = 0.55 + 0.45 * Math.sin(t * 2 + n.phase);
    ctx.globalAlpha = (0.35 + p.z * 0.65) * tw;
    ctx.fillStyle = '#e0f7ff';
    ctx.shadowColor = cyan;
    ctx.shadowBlur = 10 * energy + 4;
    ctx.beginPath();
    ctx.arc(p.x, p.y, 1.6 + p.z * 1.8, 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.shadowBlur = 0;

  // Two tilted orbit rings turning opposite ways, each with a bright bead.
  const rings = [
    { rx: r * 1.55, ry: r * 0.42, rot: -0.22, dir: 1, color: '#38bdf8' },
    { rx: r * 1.32, ry: r * 0.3, rot: 0.32, dir: -1, color: '#a78bfa' },
  ];
  for (const ring of rings) {
    ctx.globalAlpha = 0.55;
    ctx.strokeStyle = ring.color;
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    ctx.ellipse(cx, cy, ring.rx, ring.ry, ring.rot, 0, Math.PI * 2);
    ctx.stroke();
    const a = t * 0.5 * ring.dir * (1 + energy);
    const bx = cx + ring.rx * Math.cos(a) * Math.cos(ring.rot) - ring.ry * Math.sin(a) * Math.sin(ring.rot);
    const by = cy + ring.rx * Math.cos(a) * Math.sin(ring.rot) + ring.ry * Math.sin(a) * Math.cos(ring.rot);
    ctx.globalAlpha = 1;
    ctx.fillStyle = ring.color;
    ctx.shadowColor = ring.color;
    ctx.shadowBlur = 14;
    ctx.beginPath();
    ctx.arc(bx, by, 3, 0, Math.PI * 2);
    ctx.fill();
  }

  // Agents on the outer orbit, spaced evenly, turning with it.
  const outer = rings[0];
  agents.forEach((ag, i) => {
    const a = (i / Math.max(1, agents.length)) * Math.PI * 2 + t * 0.12;
    const x = cx + outer.rx * Math.cos(a) * Math.cos(outer.rot) - outer.ry * Math.sin(a) * Math.sin(outer.rot);
    const y = cy + outer.rx * Math.cos(a) * Math.sin(outer.rot) + outer.ry * Math.sin(a) * Math.cos(outer.rot);
    const color = ag.state === 'failed' ? COLORS.failed : COLORS[ag.kind] ?? '#38bdf8';
    const busy = ag.state === 'busy';
    ctx.globalAlpha = busy ? 0.75 + 0.25 * Math.sin(t * 4 + i) : 0.6;
    ctx.fillStyle = color;
    ctx.shadowColor = color;
    ctx.shadowBlur = busy ? 16 : 6;
    ctx.beginPath();
    ctx.arc(x, y, busy ? 4.5 : 3, 0, Math.PI * 2);
    ctx.fill();
  });
  ctx.shadowBlur = 0;
  ctx.globalAlpha = 1;
}

function loop(now) {
  const dt = last ? Math.min(0.1, (now - last) / 1000) : 0;
  last = now;
  const speed = state === 'thinking' ? 3 : state === 'speaking' ? 1.6 + level : 1;
  spin += TURN * speed * dt;
  t += dt;
  draw();
  frame = requestAnimationFrame(loop);
}

function start() {
  if (!frame) {
    last = 0;
    frame = requestAnimationFrame(loop);
  }
}

function stop() {
  cancelAnimationFrame(frame);
  frame = 0;
}

// Sharp on high-density screens: the backing store follows the drawn size.
function fit() {
  const box = canvas.getBoundingClientRect();
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  if (box.width > 0) {
    canvas.width = Math.round(box.width * dpr);
    canvas.height = Math.round(box.height * dpr);
  }
}

// A pending answer in the list means Claude is working on it.
function watchAnswers() {
  const list = document.getElementById('answers');
  if (!list) return;
  const sync = () => {
    const busy = list.querySelector('[data-status="queued"], [data-status="running"]') !== null;
    if (busy) setState('thinking');
    else if (state === 'thinking') setState('idle');
  };
  new MutationObserver(sync).observe(list, { childList: true, subtree: true, attributes: true });
  sync();
}

function init() {
  canvas = document.getElementById('orb');
  ctx = canvas?.getContext?.('2d') ?? null;
  if (!ctx) return;
  canvas.hidden = false;
  canvas.parentElement.dataset.live = 'true';
  canvas.parentElement.dataset.state = state;
  fit();
  watchAnswers();
  const still = window.matchMedia('(prefers-reduced-motion: reduce)');
  const run = () => {
    if (still.matches || document.hidden) {
      stop();
      draw();
    } else {
      start();
    }
  };
  window.addEventListener('resize', () => {
    fit();
    if (!frame) draw();
  });
  document.addEventListener('visibilitychange', run);
  still.addEventListener?.('change', run);
  run();
}

if (typeof document !== 'undefined') init();
