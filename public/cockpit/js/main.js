// The cockpit shell: rail, top bar with Find and the date and time as text, the Cas bar, and the hash router. Each new snapshot
// redraws the current screen; the shell, Find and the Cas bar stay put so typing is never interrupted.
import { el, add, btn, icon, go, $, D } from './dom.js';
import { begin, subscribe, findItems, state } from './data.js';
import { search } from './fuzzy.js';
import { available, start, setMode, toggleTalk, currentMode, MODES } from './voice.js';
import { renderOverview, renderProject, renderSession, renderAgents, renderCare } from './views.js';

let page = null;
let find = null;
let finder = null;
// "Mic open" in the top bar whenever the mic is live (card 110).
const micOpen = el('span', 'mic-open', 'Mic open');
micOpen.hidden = true;

function route() {
  const parts = location.hash.replace(/^#\//, '').split('/').map(decodeURIComponent);
  return { name: parts[0] || 'overview', id: parts[1] || '' };
}

function commandBar() {
  const c = el('div', 'command');
  const main = el('div', 'command-main');
  const input = el('input');
  input.placeholder = 'Ask Cas to check anything';
  input.setAttribute('aria-label', 'Command Cas');
  const mic = btn('', 'mic');
  mic.setAttribute('aria-label', 'Talk to Cas');
  mic.append(icon('mic'));
  const dot = el('div', 'cas-dot');
  // Card 110: Always on / Click to talk / Off, one button cycling through the three in text.
  const toggle = btn('', 'voice-mode');
  toggle.setAttribute('aria-label', 'Voice mode');
  add(main, dot, input, toggle, mic);
  const thread = el('div', 'voice-thread');
  thread.setAttribute('aria-live', 'polite');
  const v = el('div', 'voice-line');
  const status = el('span', '', '');
  v.append(status);
  add(c, thread, main, v);
  let fade = null;
  const turn = (who, text) => {
    thread.append(el('p', `voice-turn ${who}`, text));
    while (thread.children.length > 6) thread.firstChild.remove();
    thread.classList.remove('faded');
    clearTimeout(fade);
    fade = setTimeout(() => thread.classList.add('faded'), 20000);
  };
  // The status line is the button's title too: the portrait hero hides the line itself (card 121), so a message Rob must see goes in the thread.
  const say = (text, loud = false) => {
    status.textContent = text;
    toggle.title = text;
    c.classList.add('active');
    if (loud) turn('cas', text);
  };
  const LABEL = { off: 'Voice engine is off', idle: 'Idle', listening: 'Listening', thinking: 'Thinking', speaking: 'Speaking', starting: 'Voice engine starting', down: 'Voice engine not running' };
  const order = Object.keys(MODES);
  const showMode = () => { toggle.textContent = MODES[currentMode()]; };
  start({
    state(s) {
      const k = s in LABEL ? s : 'idle';
      c.dataset.voice = k;
      say(k === 'off' && currentMode() === 'off' && available() ? 'Voice is off' : LABEL[k]);
    },
    turn,
    mic(on) { micOpen.hidden = !on; },
  });
  showMode();
  toggle.addEventListener('click', () => {
    setMode(order[(order.indexOf(currentMode()) + 1) % order.length]).then(showMode);
    showMode();
  });
  mic.addEventListener('click', () => {
    if (!available()) say('Voice engine is off', true);
    else if (currentMode() === 'click') toggleTalk();
    else say(currentMode() === 'always' ? 'Say Hey Cas or Time to work' : 'Voice is off. Choose Always on or Click to talk.', true);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') say('Asking Cas is not connected yet. Find (Ctrl K) searches what is here.', true);
  });
  // The dot breathes only while an agent is actually working.
  subscribe(() => {
    const busy = (state.snap?.agents ?? []).some((a) => a.state === 'working') || (state.now?.sessions ?? []).some((s) => s.status === 'busy');
    dot.classList.toggle('working', busy);
  });
  return c;
}

function findBox() {
  // Card 121: the finder is a panel the rail's Find button (or Ctrl K) opens; it is not in the top bar.
  const label = el('label', 'finder');
  label.hidden = true;
  label.append(icon('search'));
  const input = el('input');
  input.placeholder = 'Find a project session or file';
  input.setAttribute('aria-label', 'Find');
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', 'find-results');
  input.autocomplete = 'off';
  const list = el('ul', 'find-results');
  list.id = 'find-results';
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  let hits = [];
  let at = 0;
  const close = () => {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
  };
  const open = (h) => {
    if (!h) return;
    go(h.path);
    input.value = '';
    close();
    input.blur();
  };
  const draw = () => {
    list.replaceChildren();
    hits.forEach((h, i) => {
      const li = el('li', 'find-hit' + (i === at ? ' on' : ''));
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === at));
      add(li, el('span', 'find-kind', h.kind), el('b', '', h.label), el('small', '', h.detail));
      li.addEventListener('mousedown', (e) => { e.preventDefault(); open(h); });
      list.append(li);
    });
    if (!hits.length && input.value.trim()) list.append(el('li', 'find-empty', state.snap ? 'Nothing matches.' : 'Still loading.'));
    list.hidden = !input.value.trim();
    input.setAttribute('aria-expanded', String(!list.hidden));
  };
  input.addEventListener('input', () => {
    hits = search(findItems(), input.value);
    at = 0;
    draw();
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (hits.length) at = (at + (e.key === 'ArrowDown' ? 1 : hits.length - 1)) % hits.length;
      draw();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      open(hits[at]);
    } else if (e.key === 'Escape') {
      e.stopPropagation();
      close();
      input.blur();
    }
  });
  input.addEventListener('blur', () => {
    close();
    label.hidden = true;
  });
  label.append(input, el('span', 'key', 'Ctrl K'), list);
  find = input;
  finder = label;
  return label;
}

function openFind() {
  if (!finder) return;
  finder.hidden = false;
  find.focus();
}

function shell() {
  const app = $('#app');
  const rail = el('nav', 'rail');
  rail.setAttribute('aria-label', 'Primary');
  rail.append(el('div', 'brand', 'KC'));
  const findBtn = btn('', 'nav-btn find-btn', openFind);
  findBtn.setAttribute('aria-label', 'Find a project session or file (Ctrl K)');
  add(findBtn, icon('search'), el('span', '', 'Find'));
  rail.append(findBtn);
  const navs = [['overview', 'Overview', 'overview'], ['project', 'Project', 'project'], ['session', 'Session', 'session'], ['agents', 'Agents', 'agents'], ['care', 'Care', 'care']];
  const buttons = navs.map(([path, label, ic]) => {
    const b = btn('', 'nav-btn');
    b.dataset.route = path;
    b.setAttribute('aria-label', label);
    add(b, icon(ic), el('span', '', label));
    b.addEventListener('click', () => {
      const s = state.snap;
      if (path === 'project') go(`project/${encodeURIComponent(s?.projects?.[0]?.name ?? '')}`);
      else if (path === 'session') go(`session/${encodeURIComponent(s?.sessions?.[0]?.session ?? '')}`);
      else go(path);
    });
    rail.append(b);
    return b;
  });
  rail.append(el('div', 'nav-spacer'));
  const main = el('div', 'shell');
  const top = el('header', 'topbar');
  // Plain muted text, "Sunday, November 1 · 1:58 PM" (Rob, 2026-11-01: no pills).
  const time = el('time', 'topbar-time');
  add(top, el('div', 'mobile-mark', 'KC Cockpit'), micOpen, time);
  page = el('main', 'page');
  page.id = 'page';
  add(main, top, page);
  app.replaceChildren(rail, main);
  D.body.append(findBox(), commandBar());
  const tick = () => {
    const d = new Date();
    time.textContent = `${d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })} · ${d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
  };
  tick();
  setInterval(tick, 15000);
  return buttons;
}

let navButtons = [];

function render(scroll) {
  const r = route();
  navButtons.forEach((b) => b.classList.toggle('active', b.dataset.route === r.name || (r.name === 'overview' && b.dataset.route === 'overview')));
  // The docked Cas bar lives inside the overview's top row: take it back out before the page is cleared.
  const cmd = $('.command');
  if (cmd && cmd.parentElement !== D.body) {
    cmd.classList.remove('docked');
    D.body.append(cmd);
  }
  const fresh = el('main', 'page');
  fresh.id = 'page';
  if (r.name === 'project') renderProject(fresh, r.id);
  else if (r.name === 'session') renderSession(fresh, r.id);
  else if (r.name === 'agents') renderAgents(fresh);
  else if (r.name === 'care') renderCare(fresh, r.id);
  else renderOverview(fresh);
  page.replaceWith(fresh);
  page = fresh;
  if (scroll) scrollTo(0, 0);
}

navButtons = shell();
addEventListener('hashchange', () => render(true));
addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openFind();
  }
  if (e.key === 'Escape' && D.activeElement === D.body) go('overview');
});
// A redraw would steal focus from a control inside the page, so it waits until focus leaves it.
subscribe(() => {
  if (!page.contains(D.activeElement)) render(false);
});
if (!location.hash) location.hash = '#/overview';
render(true);
begin();
