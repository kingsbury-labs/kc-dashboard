// The cockpit shell: rail, top bar with Find and the clock, the Cas bar, and the hash router. Each new snapshot
// redraws the current screen; the shell, Find and the Cas bar stay put so typing is never interrupted.
import { el, add, btn, icon, go, $, D } from './dom.js';
import { begin, subscribe, findItems, state } from './data.js';
import { search } from './fuzzy.js';
import { available } from './voice.js';
import { renderOverview, renderProject, renderSession, renderAgents } from './views.js';

let page = null;
let find = null;

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
  add(main, dot, input, mic);
  const v = el('div', 'voice-line');
  const status = el('span', '', '');
  v.append(status);
  add(c, main, v);
  const say = (text) => {
    status.textContent = text;
    c.classList.add('active');
  };
  mic.addEventListener('click', () => say(available() ? 'Listening' : 'Voice is not connected yet.'));
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') say('Asking Cas is not connected yet. Find (Ctrl K) searches what is here.');
  });
  // The dot breathes only while an agent is actually working.
  subscribe(() => {
    const busy = (state.snap?.agents ?? []).some((a) => a.state === 'working') || (state.now?.sessions ?? []).some((s) => s.status === 'busy');
    dot.classList.toggle('working', busy);
  });
  return c;
}

function findBox() {
  const label = el('label', 'search');
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
  input.addEventListener('blur', close);
  label.append(input, el('span', 'key', 'Ctrl K'), list);
  find = input;
  return label;
}

function shell() {
  const app = $('#app');
  const rail = el('nav', 'rail');
  rail.setAttribute('aria-label', 'Primary');
  rail.append(el('div', 'brand', 'KC'));
  const navs = [['overview', 'Overview', 'overview'], ['project', 'Project', 'project'], ['session', 'Session', 'session'], ['agents', 'Agents', 'agents']];
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
  const time = btn('', 'range-btn clock');
  time.disabled = true;
  add(top, el('div', 'mobile-mark', 'KC Cockpit'), findBox(), time, el('div', 'avatar', 'RB'));
  page = el('main', 'page');
  page.id = 'page';
  add(main, top, page);
  app.replaceChildren(rail, main);
  D.body.append(commandBar());
  const tick = () => { time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
  tick();
  setInterval(tick, 1000);
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
    find?.focus();
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
