// DOM helpers, as the v4 concept wrote them: createElement and textContent only, createElementNS for SVG.
export const D = document;
export const $ = (s) => D.querySelector(s);
export const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

export function el(tag, cls, text) {
  const n = D.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined && text !== null) n.textContent = String(text);
  return n;
}

const PATHS = {
  overview: 'M3 3h7v7H3z M14 3h7v4h-7z M14 11h7v10h-7z M3 14h7v7H3z',
  project: 'M3 6h7l2 2h9v11H3z',
  session: 'M4 5h16v12H8l-4 3z',
  agents: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
  search: 'M21 21l-4.35-4.35 M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
  mic: 'M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3 M19 10v2a7 7 0 0 1-14 0v-2 M12 19v3',
};
const NS = 'http://www.w3.org/2000/svg';

export function svg(tag) {
  return D.createElementNS(NS, tag);
}

export function icon(name) {
  const s = svg('svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('aria-hidden', 'true');
  const p = svg('path');
  p.setAttribute('d', PATHS[name] || '');
  p.setAttribute('stroke', 'currentColor');
  p.setAttribute('fill', 'none');
  p.setAttribute('stroke-linecap', 'round');
  p.setAttribute('stroke-linejoin', 'round');
  s.append(p);
  return s;
}

export function add(parent, ...kids) {
  kids.flat().forEach((k) => k && parent.append(k));
  return parent;
}

export function btn(label, cls, fn) {
  const b = el('button', cls, label);
  b.type = 'button';
  if (fn) b.addEventListener('click', fn);
  return b;
}

export function card(title, cls = '') {
  const c = el('section', 'card ' + cls);
  const h = el('div', 'card-head');
  add(h, el('h2', '', title));
  c.append(h);
  return c;
}

export function pill(state) {
  return el('span', 'pill ' + state, state);
}

export function link(label, cls, href) {
  const a = el('a', cls, label);
  a.href = href;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  return a;
}

export function go(path) {
  location.hash = '#/' + path;
}

// "now", "4m", "2h", "3d" from a unix time.
export function ago(ts, now = Date.now() / 1000) {
  if (!ts) return '';
  const s = Math.max(0, Math.round(now - ts));
  return s < 60 ? 'now' : s < 3600 ? `${Math.floor(s / 60)}m` : s < 86400 ? `${Math.floor(s / 3600)}h` : `${Math.floor(s / 86400)}d`;
}

export function clock(ts) {
  return ts ? new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}

// A panel that has no source yet: its final shape, and one quiet line.
export function notConnected(c, what = 'Not connected yet') {
  c.classList.add('not-connected');
  c.append(el('p', 'agenda-empty', what));
  return c;
}
