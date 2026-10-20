// Voice out: the browser's speechSynthesis reads the briefing after Brief me and an Ask Claude answer on its speak
// button. Only voices with localService true are offered, so no audio or text leaves the PC. There is no network call
// in this file. While speaking the orb is set to speaking and pulsed on each word boundary (speech output cannot be
// routed into Web Audio). With no speechSynthesis, or no JS, none of the controls exist.
import { setState, setLevel } from './orb.js';

const KEY = 'kc-voice';
const synth = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window ? window.speechSynthesis : null;
const state = document.getElementById('voice-state') ?? document.querySelector('#voice .v-state');
const panel = document.getElementById('voice');
const capsule = document.getElementById('talk-button');
let armed = false;
let run = 0;
let speaking = false;
let picker = null;
let stopButton = null;

export const available = synth !== null;

function stored() {
  try {
    return window.localStorage.getItem(KEY) ?? '';
  } catch {
    return '';
  }
}

function remember(name) {
  try {
    window.localStorage.setItem(KEY, name);
  } catch {
    // Storage blocked: the choice lasts until the page closes.
  }
}

// Local voices only; a remote voice would send the text to a server.
export function localVoices() {
  return synth ? synth.getVoices().filter((v) => v.localService === true) : [];
}

function chosen() {
  const all = localVoices();
  const name = picker?.value || stored();
  return all.find((v) => v.name === name) ?? all.find((v) => v.lang.toLowerCase().startsWith('en')) ?? all[0] ?? null;
}

function label(text) {
  if (state) state.textContent = text;
}

function finish(id) {
  if (id !== run) return;
  speaking = false;
  setLevel(0);
  setState('idle');
  label(localVoices().length > 0 ? 'Voice ready' : 'No local voice');
  if (stopButton) stopButton.hidden = true;
}

export function stop() {
  if (!synth) return;
  run += 1;
  synth.cancel();
  finish(run);
}

// Each line is its own utterance: long text in one utterance is cut off by Chrome after about 15 seconds.
export function speak(lines) {
  const voice = chosen();
  const parts = (Array.isArray(lines) ? lines : [lines]).map((s) => String(s ?? '').trim()).filter((s) => s !== '');
  if (!synth || !voice || parts.length === 0) return false;
  synth.cancel();
  run += 1;
  const id = run;
  speaking = true;
  setState('speaking');
  label('Speaking');
  if (stopButton) stopButton.hidden = false;
  parts.forEach((text, i) => {
    const u = new SpeechSynthesisUtterance(text);
    u.voice = voice;
    u.addEventListener('boundary', () => {
      if (id !== run) return;
      setLevel(1);
      setTimeout(() => {
        if (id === run) setLevel(0.25);
      }, 110);
    });
    u.addEventListener('end', () => {
      if (i === parts.length - 1) finish(id);
    });
    u.addEventListener('error', () => finish(id));
    synth.speak(u);
  });
  return true;
}

// A speak button for one piece of text, or null when there is no speechSynthesis. It stays hidden until a local voice exists.
export function speakButton(getText) {
  if (!synth) return null;
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'speak';
  b.dataset.speak = '';
  b.textContent = 'Speak';
  b.hidden = localVoices().length === 0;
  b.addEventListener('click', () => {
    if (speaking) stop();
    else speak(getText());
  });
  return b;
}

function sync() {
  const any = localVoices().length > 0;
  for (const b of document.querySelectorAll('[data-speak]')) b.hidden = !any;
  if (picker) {
    const keep = chosen()?.name ?? '';
    picker.replaceChildren(...localVoices().map((v) => {
      const o = document.createElement('option');
      o.value = v.name;
      o.textContent = v.name;
      return o;
    }));
    picker.value = keep;
    picker.hidden = !any;
  }
  if (capsule) capsule.disabled = !any;
  if (!speaking) label(any ? 'Voice ready' : 'No local voice');
}

// Brief me (any [data-brief] button) arms the voice; brief.js then fills #briefing-body and the heading reads "Briefing <day>".
function watchBriefing() {
  const body = document.getElementById('briefing-body');
  const heading = document.getElementById('briefing-h');
  if (!body || !heading) return;
  new MutationObserver(() => {
    if (!armed || !/^Briefing \d/.test(heading.textContent)) return;
    armed = false;
    speak(Array.from(body.querySelectorAll('h3, p, li'), (n) => n.textContent));
  }).observe(body, { childList: true });
}

function init() {
  if (!synth || !panel) return;
  const note = panel.querySelector('.v-note');
  const pickId = 'voice-pick';
  const lab = document.createElement('label');
  lab.className = 'hidden';
  lab.htmlFor = pickId;
  lab.textContent = 'Voice';
  picker = document.createElement('select');
  picker.id = pickId;
  picker.hidden = true;
  picker.addEventListener('change', () => remember(picker.value));
  stopButton = document.createElement('button');
  stopButton.type = 'button';
  stopButton.className = 'v-stop';
  stopButton.id = 'voice-stop';
  stopButton.textContent = 'Stop';
  stopButton.hidden = true;
  stopButton.addEventListener('click', stop);
  // The voice picker sits in a small settings fold at the panel's corner, so nothing runs past the rail.
  const settings = document.createElement('details');
  settings.className = 'v-set';
  const summary = document.createElement('summary');
  summary.setAttribute('aria-label', 'Voice settings');
  const gear = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  gear.setAttribute('class', 'ico');
  gear.setAttribute('viewBox', '0 0 24 24');
  gear.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', 'M12 9a3 3 0 1 0 0 6a3 3 0 1 0 0-6zM12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1');
  gear.append(path);
  summary.append(gear);
  settings.append(summary, lab, picker);
  panel.querySelector('h2')?.after(settings);
  note?.before(stopButton);
  if (note) note.textContent = 'Reads the briefing aloud';

  document.addEventListener('click', (event) => {
    if (localVoices().length > 0 && event.target instanceof Element && event.target.closest('[data-brief]') !== null) armed = true;
  });
  if (capsule) {
    capsule.addEventListener('click', () => {
      if (speaking) {
        stop();
        return;
      }
      armed = localVoices().length > 0;
      document.querySelector('[data-brief]:not([disabled])')?.click();
    });
  }
  synth.addEventListener('voiceschanged', sync);
  watchBriefing();
  sync();
}

init();
window.addEventListener('pagehide', () => {
  if (synth) synth.cancel();
});
