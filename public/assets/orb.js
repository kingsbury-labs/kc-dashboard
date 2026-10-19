// The orb: radial glow and noise-displaced rings on a 2D canvas. Without JS the CSS orb in hud.css shows instead.
let level = 0;
let state = 'idle';
let canvas = null;
let ctx = null;
let frame = 0;
let t = 0;

const STATES = ['idle', 'thinking', 'speaking'];

export function setLevel(value) {
  const n = Number(value);
  level = Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 0;
}

export function setState(next) {
  if (!STATES.includes(next)) return;
  state = next;
  if (canvas?.parentElement) canvas.parentElement.dataset.state = next;
}

// Sums of sines stand in for noise: smooth, cheap, no random seed to carry.
function wobble(angle, ring) {
  return Math.sin(angle * 3 + t * 1.3 + ring) * 0.6 + Math.sin(angle * 5 - t * 0.9 + ring * 2) * 0.4;
}

function draw() {
  const w = canvas.width;
  const h = canvas.height;
  const cx = w / 2;
  const cy = h / 2;
  const accent = getComputedStyle(canvas).getPropertyValue('--accent').trim() || '#35d0e6';
  const energy = state === 'thinking' ? 0.55 : state === 'speaking' ? 0.3 + level * 0.7 : 0.15 + level * 0.4;
  ctx.clearRect(0, 0, w, h);

  const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, w / 2);
  glow.addColorStop(0, accent);
  glow.addColorStop(0.35, accent + '55');
  glow.addColorStop(1, accent + '00');
  ctx.globalAlpha = 0.5 + energy * 0.4;
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, w, h);

  ctx.strokeStyle = accent;
  ctx.lineWidth = 1.5;
  for (let ring = 0; ring < 3; ring++) {
    const base = w * (0.22 + ring * 0.08);
    ctx.globalAlpha = 0.9 - ring * 0.25;
    ctx.beginPath();
    for (let i = 0; i <= 64; i++) {
      const a = (i / 64) * Math.PI * 2;
      const r = base + wobble(a, ring) * w * 0.04 * energy;
      const x = cx + Math.cos(a) * r;
      const y = cy + Math.sin(a) * r;
      if (i === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    }
    ctx.closePath();
    ctx.stroke();
  }
  ctx.globalAlpha = 1;
}

function loop() {
  t += 0.016;
  draw();
  frame = requestAnimationFrame(loop);
}

function start() {
  if (!frame) frame = requestAnimationFrame(loop);
}

function stop() {
  cancelAnimationFrame(frame);
  frame = 0;
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
  document.addEventListener('visibilitychange', run);
  still.addEventListener?.('change', run);
  run();
}

if (typeof document !== 'undefined') init();
