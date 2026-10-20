// The AI core: a wireframe globe on a 2D canvas, no library. A dense lat/long mesh turns about once every 40
// seconds over a bloom of light, bright nodes twinkle (the brightest with small lens flares), segmented HUD rings
// turn at different speeds, two wide tilted orbit rings counter-rotate, and a ripple platform pulses outward under
// it. Each agent rides the outer orbit in its colour. Thinking and speaking turn it faster and brighter. Without JS
// the CSS globe in hud.css shows instead; with reduced motion one still frame is drawn.
let level = 0;
let state = 'idle';
let agents = [];
let canvas = null;
let ctx = null;
let frame = 0;
let last = 0;
let spin = 0;
let t = 0;
let px = 1;

const STATES = ['idle', 'thinking', 'speaking'];
const COLORS = { session: '#f5b544', loop: '#a78bfa', collector: '#60a5fa', failed: '#f87171' };
const TURN = (Math.PI * 2) / 40;
const TILT = 0.38;
const CYAN = '#22d3ee';

// Fixed nodes on the sphere: a golden-angle spiral, so they spread evenly with no random seed to carry.
const NODES = Array.from({ length: 72 }, (_, i) => {
  const y = 1 - (i + 0.5) * (2 / 72);
  return { lat: Math.asin(y), lon: i * 2.39996, phase: i * 1.7, flare: i % 9 === 4 };
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

// One mesh line, each segment as bright as it is near the front of the sphere.
function line(points, color, width) {
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1];
    const b = points[i];
    const z = (a.z + b.z) / 2;
    ctx.globalAlpha = z > 0 ? 0.22 + z * 0.6 : 0.07 + (1 + z) * 0.1;
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.stroke();
  }
}

function ellipse(x, y, rx, ry, rot, color, alpha, width) {
  ctx.globalAlpha = alpha;
  ctx.strokeStyle = color;
  ctx.lineWidth = width;
  ctx.beginPath();
  ctx.ellipse(x, y, rx, ry, rot, 0, Math.PI * 2);
  ctx.stroke();
}

// A segmented HUD ring: arcs with gaps and fine ticks, turned by `turn`.
function hudRing(cx, cy, radius, segments, gap, turn, alpha, width, ticks) {
  ctx.strokeStyle = CYAN;
  ctx.lineWidth = width;
  ctx.globalAlpha = alpha;
  const step = (Math.PI * 2) / segments;
  for (let i = 0; i < segments; i++) {
    const a = turn + i * step;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, a, a + step * (1 - gap));
    ctx.stroke();
  }
  if (!ticks) return;
  ctx.lineWidth = px;
  ctx.globalAlpha = alpha * 0.8;
  for (let i = 0; i < ticks; i++) {
    const a = -turn * 0.5 + (i / ticks) * Math.PI * 2;
    const long = i % 6 === 0 ? 7 : 3;
    ctx.beginPath();
    ctx.moveTo(cx + Math.cos(a) * (radius + 3 * px), cy + Math.sin(a) * (radius + 3 * px));
    ctx.lineTo(cx + Math.cos(a) * (radius + (3 + long) * px), cy + Math.sin(a) * (radius + (3 + long) * px));
    ctx.stroke();
  }
}

function draw() {
  const w = canvas.width;
  const h = canvas.height;
  const cx = w / 2;
  const cy = h * 0.45;
  const r = Math.min(w * 0.25, h * 0.35);
  const energy = state === 'thinking' ? 1 : state === 'speaking' ? 0.6 + level * 0.4 : 0.4;
  ctx.clearRect(0, 0, w, h);
  ctx.globalCompositeOperation = 'source-over';

  // Bloom of light behind and under the globe.
  const bloom = ctx.createRadialGradient(cx, cy + r * 0.2, r * 0.05, cx, cy + r * 0.2, r * 2.2);
  bloom.addColorStop(0, `rgba(103, 232, 249, ${0.42 + energy * 0.2})`);
  bloom.addColorStop(0.3, 'rgba(34, 211, 238, 0.18)');
  bloom.addColorStop(0.65, 'rgba(14, 116, 144, 0.07)');
  bloom.addColorStop(1, 'rgba(6, 20, 43, 0)');
  ctx.globalAlpha = 1;
  ctx.fillStyle = bloom;
  ctx.fillRect(0, 0, w, h);

  // Ripple platform: fixed rings with a glow, and pulses spreading outward from the centre.
  const floorY = cy + r * 1.12;
  const floor = ctx.createRadialGradient(cx, floorY, 0, cx, floorY, r * 1.6);
  floor.addColorStop(0, 'rgba(103, 232, 249, 0.55)');
  floor.addColorStop(0.35, 'rgba(34, 211, 238, 0.16)');
  floor.addColorStop(1, 'rgba(34, 211, 238, 0)');
  ctx.save();
  ctx.translate(cx, floorY);
  ctx.scale(1, 0.2);
  ctx.translate(-cx, -floorY);
  ctx.fillStyle = floor;
  ctx.beginPath();
  ctx.arc(cx, floorY, r * 1.6, 0, Math.PI * 2);
  ctx.fill();
  ctx.restore();
  ctx.shadowColor = CYAN;
  ctx.shadowBlur = 8 * px;
  for (let i = 1; i <= 7; i++) {
    ellipse(cx, floorY, r * (0.2 + i * 0.19), r * (0.2 + i * 0.19) * 0.2, 0, CYAN, 0.5 - i * 0.05, px * (i === 3 ? 2 : 1));
  }
  for (let i = 0; i < 3; i++) {
    const p = (t * 0.28 + i / 3) % 1;
    ellipse(cx, floorY, r * (0.3 + p * 1.4), r * (0.3 + p * 1.4) * 0.2, 0, '#a5f3fc', (1 - p) * 0.7, px * 1.5);
  }
  ctx.shadowBlur = 0;

  // HUD rings, arc-reactor style, each turning at its own speed.
  hudRing(cx, cy, r * 1.1, 3, 0.12, t * 0.25, 0.55, px * 1.5, 0);
  hudRing(cx, cy, r * 1.19, 24, 0.35, -t * 0.12, 0.35, px * 3, 72);
  hudRing(cx, cy, r * 1.3, 6, 0.55, t * 0.06, 0.28, px, 0);

  // The sphere's glass: a soft fill so the back lines read as behind it.
  const glass = ctx.createRadialGradient(cx - r * 0.3, cy - r * 0.35, r * 0.1, cx, cy, r);
  glass.addColorStop(0, 'rgba(165, 243, 252, 0.28)');
  glass.addColorStop(0.6, 'rgba(14, 116, 144, 0.16)');
  glass.addColorStop(1, 'rgba(8, 47, 73, 0.32)');
  ctx.globalAlpha = 1;
  ctx.fillStyle = glass;
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.fill();

  // Latitude and longitude lines, dense.
  for (let k = -8; k <= 8; k++) {
    const lat = (k / 9) * (Math.PI / 2);
    const pts = [];
    for (let i = 0; i <= 64; i++) pts.push(project(lat, (i / 64) * Math.PI * 2, r, cx, cy));
    line(pts, '#7dd3fc', px);
  }
  for (let k = 0; k < 24; k++) {
    const lon = (k / 24) * Math.PI * 2;
    const pts = [];
    for (let i = 0; i <= 40; i++) pts.push(project(-Math.PI / 2 + (i / 40) * Math.PI, lon, r, cx, cy));
    line(pts, '#7dd3fc', px);
  }

  // Rim light.
  ctx.globalAlpha = 0.85;
  ctx.shadowColor = CYAN;
  ctx.shadowBlur = 18 * px;
  ctx.strokeStyle = '#67e8f9';
  ctx.lineWidth = 1.5 * px;
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.stroke();

  // Nodes: bright on the front face, twinkling; a few carry a small lens flare.
  for (const n of NODES) {
    const p = project(n.lat, n.lon, r, cx, cy);
    if (p.z < -0.05) continue;
    const tw = 0.55 + 0.45 * Math.sin(t * 2.2 + n.phase);
    const a = (0.4 + p.z * 0.6) * tw;
    const size = (1.4 + p.z * 1.8) * px;
    ctx.globalAlpha = a;
    ctx.fillStyle = '#f0fdff';
    ctx.shadowColor = CYAN;
    ctx.shadowBlur = (10 * energy + 6) * px;
    ctx.beginPath();
    ctx.arc(p.x, p.y, size, 0, Math.PI * 2);
    ctx.fill();
    if (n.flare && p.z > 0.35) {
      const f = size * (5 + 3 * tw);
      ctx.globalAlpha = a * 0.7;
      ctx.strokeStyle = '#cffafe';
      ctx.lineWidth = px;
      ctx.beginPath();
      ctx.moveTo(p.x - f, p.y);
      ctx.lineTo(p.x + f, p.y);
      ctx.moveTo(p.x, p.y - f * 0.6);
      ctx.lineTo(p.x, p.y + f * 0.6);
      ctx.stroke();
    }
  }
  ctx.shadowBlur = 0;

  // Two wide tilted orbit rings turning opposite ways, each with a bright bead.
  const rings = [
    { rx: r * 1.75, ry: r * 0.46, rot: -0.2, dir: 1, color: '#67e8f9' },
    { rx: r * 1.5, ry: r * 0.3, rot: 0.24, dir: -1, color: '#93c5fd' },
  ];
  const at = (ring, a) => ({
    x: cx + ring.rx * Math.cos(a) * Math.cos(ring.rot) - ring.ry * Math.sin(a) * Math.sin(ring.rot),
    y: cy + ring.rx * Math.cos(a) * Math.sin(ring.rot) + ring.ry * Math.sin(a) * Math.cos(ring.rot),
  });
  ctx.shadowColor = CYAN;
  ctx.shadowBlur = 10 * px;
  for (const ring of rings) {
    ellipse(cx, cy, ring.rx, ring.ry, ring.rot, ring.color, 0.6, 1.2 * px);
    const b = at(ring, t * 0.5 * ring.dir * (1 + energy));
    ctx.globalAlpha = 1;
    ctx.fillStyle = '#f0fdff';
    ctx.shadowBlur = 16 * px;
    ctx.beginPath();
    ctx.arc(b.x, b.y, 3 * px, 0, Math.PI * 2);
    ctx.fill();
  }

  // Agents on the outer orbit, spaced evenly, turning with it.
  agents.forEach((ag, i) => {
    const p = at(rings[0], (i / Math.max(1, agents.length)) * Math.PI * 2 + t * 0.12);
    const color = ag.state === 'failed' ? COLORS.failed : COLORS[ag.kind] ?? '#38bdf8';
    const busy = ag.state === 'busy';
    ctx.globalAlpha = busy ? 0.75 + 0.25 * Math.sin(t * 4 + i) : 0.6;
    ctx.fillStyle = color;
    ctx.shadowColor = color;
    ctx.shadowBlur = (busy ? 18 : 6) * px;
    ctx.beginPath();
    ctx.arc(p.x, p.y, (busy ? 5 : 3) * px, 0, Math.PI * 2);
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

// Sharp on high-density screens: the backing store follows the drawn size, and line widths follow the ratio.
function fit() {
  const box = canvas.getBoundingClientRect();
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  if (box.width > 0) {
    canvas.width = Math.round(box.width * dpr);
    canvas.height = Math.round(box.height * dpr);
    px = dpr;
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
  if ('ResizeObserver' in window) {
    new ResizeObserver(() => {
      fit();
      if (!frame) draw();
    }).observe(canvas);
  }
  document.addEventListener('visibilitychange', run);
  still.addEventListener?.('change', run);
  run();
}

if (typeof document !== 'undefined') init();
