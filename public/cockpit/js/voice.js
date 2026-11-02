// Card 110: the Cas bar's voice line to the local cas-voice engine (D:\Dev\cas-voice\proto\RESULT-110.md is the protocol).
// Three modes: Always on (cas-voice listens for "Hey Cas" or "Time to work"), Click to talk (the mic button opens a turn)
// and Off. The page never starts cas-voice; with no token in the page, or no socket, the bar says "Voice engine is off".
import { $, el, reduced } from './dom.js';

const URL_WS = 'ws://127.0.0.1:8766/ws';
const KEY = 'kc-voice-mode';
export const MODES = { always: 'Always on', click: 'Click to talk', off: 'Voice off' };

let ws = null;
let mode = 'off';
let hooks = { state() {}, turn() {}, mic() {} };
let ctx = null;
let stream = null;
let node = null;
let talking = false;
let minTurn = 0;
let playAt = 0;
let retry = null;
const sources = new Set();
// The mic level meter (card 120): 12 bars fed by an AnalyserNode on the mic stream, about 30 draws a second.
const BARS = 12;
const FLOOR = 0.02;
const SILENT_MS = 5000;
let analyser = null;
let meter = null;
let raf = 0;
let quietSince = 0;
let drawn = 0;

const token = () => $('meta[name="kc-voice-token"]')?.content ?? '';

export function available() {
  return token() !== '';
}

function readMode() {
  try {
    const m = localStorage.getItem(KEY);
    return m in MODES ? m : 'off';
  } catch {
    return 'off';
  }
}

function writeMode(m) {
  try { localStorage.setItem(KEY, m); } catch { /* storage off: the mode lasts this page only */ }
}

export function currentMode() {
  return mode;
}

// What the socket should hear for the mode: wake listens for the phrases, talk is an open turn.
function wire() {
  if (mode === 'always') return 'wake';
  return mode === 'click' && talking ? 'talk' : 'off';
}

function connect() {
  if (ws || !available() || mode === 'off') return;
  const s = new WebSocket(URL_WS);
  ws = s;
  s.binaryType = 'arraybuffer';
  s.onopen = () => {
    s.send(JSON.stringify({ type: 'hello', token: token() }));
    s.send(JSON.stringify({ type: 'mode', mode: wire() }));
  };
  s.onclose = () => {
    if (ws === s) ws = null;
    flush();
    hooks.state('off');
    clearTimeout(retry);
    if (mode !== 'off') retry = setTimeout(connect, 5000);
  };
  s.onmessage = (ev) => {
    if (typeof ev.data !== 'string') return play(ev.data);
    let o;
    try { o = JSON.parse(ev.data); } catch { return; }
    if (o.type === 'state') hooks.state(o.state === 'loading' ? 'thinking' : o.state);
    else if (o.type === 'transcript') hooks.turn(o.who === 'cas' ? 'cas' : 'rob', String(o.text ?? ''));
    else if (o.type === 'stop_playback') { minTurn = o.turn; flush(); }
    else if (o.type === 'sleep') hooks.state('idle');
    else if (o.type === 'error') hooks.turn('cas', String(o.text ?? ''));
  };
}

function send(o) {
  if (ws && ws.readyState === 1) ws.send(JSON.stringify(o));
}

function flush() {
  for (const s of sources) { try { s.onended = null; s.stop(); } catch { /* already stopped */ } }
  sources.clear();
  playAt = 0;
}

function play(buf) {
  if (!ctx || buf.byteLength < 6) return;
  const turn = new DataView(buf).getUint32(1, true);
  if (turn < minTurn) return;
  const i16 = new Int16Array(buf.slice(5, 5 + ((buf.byteLength - 5) & ~1)));
  const f = new Float32Array(i16.length);
  for (let i = 0; i < i16.length; i++) f[i] = i16[i] / 32768;
  const ab = ctx.createBuffer(1, f.length, 24000);
  ab.copyToChannel(f, 0);
  const src = ctx.createBufferSource();
  src.buffer = ab;
  src.connect(ctx.destination);
  const now = ctx.currentTime;
  if (playAt < now + 0.02) playAt = now + 0.04;
  src.start(playAt);
  playAt += ab.duration;
  sources.add(src);
  src.onended = () => sources.delete(src);
}

// Mic permission is asked here, so only once Rob turns voice on.
async function micOn() {
  if (stream) return true;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
  } catch {
    hooks.turn('cas', 'The microphone is blocked. Allow it for this page and try again.');
    return false;
  }
  ctx = new AudioContext();
  await ctx.resume();
  await ctx.audioWorklet.addModule('/cockpit/js/mic-worklet.js');
  node = new AudioWorkletNode(ctx, 'down');
  node.port.onmessage = (e) => { if (ws && ws.readyState === 1 && wire() !== 'off') ws.send(e.data); };
  const mute = ctx.createGain();
  mute.gain.value = 0;
  ctx.createMediaStreamSource(stream).connect(node);
  node.connect(mute);
  mute.connect(ctx.destination);
  analyser = ctx.createAnalyser();
  analyser.fftSize = 512;
  ctx.createMediaStreamSource(stream).connect(analyser);
  meterOn();
  hooks.mic(true);
  return true;
}

function meterEl() {
  if (meter?.isConnected) return meter;
  meter = el('div', 'vu');
  meter.setAttribute('role', 'meter');
  meter.setAttribute('aria-label', 'Mic level');
  meter.append(el('span', 'vu-bars'), el('span', 'vu-text'));
  for (let i = 0; i < BARS; i++) meter.firstChild.append(el('i'));
  $('.command')?.append(meter);
  return meter;
}

// The peak of the latest frame, 0 to 1.
function peak() {
  const buf = new Float32Array(analyser.fftSize);
  analyser.getFloatTimeDomainData(buf);
  let p = 0;
  for (let i = 0; i < buf.length; i++) p = Math.max(p, Math.abs(buf[i]));
  return Math.min(1, p);
}

function drawMeter(t) {
  raf = requestAnimationFrame(drawMeter);
  if (!analyser || t - drawn < 33) return;
  drawn = t;
  const m = meterEl();
  const p = peak();
  if (p >= FLOOR) quietSince = t;
  const silent = t - quietSince >= SILENT_MS;
  m.classList.toggle('silent', silent);
  const lit = Math.round(Math.sqrt(p) * BARS);
  [...m.firstChild.children].forEach((b, i) => b.classList.toggle('on', i < lit));
  m.lastChild.textContent = silent ? 'No mic input' : (reduced ? Math.round(p * 100) + '%' : '');
}

function meterOn() {
  const m = meterEl();
  if (m) m.hidden = false;
  quietSince = performance.now();
  cancelAnimationFrame(raf);
  raf = requestAnimationFrame(drawMeter);
}

function meterOff() {
  cancelAnimationFrame(raf);
  raf = 0;
  analyser = null;
  if (meter) meter.hidden = true;
}

function micOff() {
  flush();
  stream?.getTracks().forEach((t) => t.stop());
  stream = null;
  node = null;
  meterOff();
  if (ctx) { try { ctx.close(); } catch { /* closed */ } ctx = null; }
  hooks.mic(false);
}

export async function setMode(m) {
  mode = m in MODES ? m : 'off';
  writeMode(mode);
  talking = false;
  if (mode === 'off') {
    send({ type: 'stop' });
    send({ type: 'mode', mode: 'off' });
    clearTimeout(retry);
    ws?.close();
    micOff();
    hooks.state('off');
    return;
  }
  if (mode === 'always' && !(await micOn())) return;
  if (mode === 'click') micOff();
  connect();
  send({ type: 'mode', mode: wire() });
  hooks.state(ws && ws.readyState === 1 ? 'idle' : 'off');
}

// The mic button in Click to talk: opens a turn, or ends it.
export async function toggleTalk() {
  if (mode !== 'click') return;
  if (talking) {
    talking = false;
    send({ type: 'mode', mode: 'off' });
    micOff();
    hooks.state('idle');
    return;
  }
  if (!(await micOn())) return;
  talking = true;
  connect();
  send({ type: 'mode', mode: 'talk' });
  hooks.state('listening');
}

// A care incident, read aloud in a local browser voice. Nothing goes to cas-voice's brain.
export function announce(text) {
  if (mode === 'off' || !('speechSynthesis' in window)) return;
  const u = new SpeechSynthesisUtterance(text);
  const local = speechSynthesis.getVoices().find((v) => v.localService && v.lang.startsWith('en'));
  if (local) u.voice = local;
  speechSynthesis.speak(u);
}

export function start(h) {
  hooks = { ...hooks, ...h };
  mode = readMode();
  if (!available()) {
    hooks.state('off');
    return;
  }
  // Always on resumes on load; the browser already granted the mic when Rob first chose it.
  if (mode === 'off') hooks.state('off');
  else setMode(mode);
}
