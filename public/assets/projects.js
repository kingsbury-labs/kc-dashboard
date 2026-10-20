// The project map table on projects.php. Rebuilds the rows from api/projects.php every 60 seconds while the tab is
// visible. The page already renders the rows without JS; every value from the server is written with textContent.
import { el, token } from './app.js';

const body = document.getElementById('projects');
const REFRESH_MS = 60000;

// Same wording as View::age.
function age(date) {
  const t = Date.parse(date ?? '');
  if (Number.isNaN(t)) return '';
  const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

// Same cells as View::mapRows.
function row(p) {
  const tr = el('tr');
  tr.dataset.slug = p.slug ?? '';
  const name = el('th', null, p.slug);
  name.scope = 'row';
  const when = el('td');
  const a = age(p.last_date);
  if (a) {
    const t = el('time', null, a);
    t.dateTime = p.last_date;
    when.append(t);
  }
  const loop = el('td');
  if (p.loop_state != null && p.loop_state !== 'none') {
    let text = p.loop_state;
    if (p.loop_card != null) text += `, card ${p.loop_card}`;
    if (p.loop_note != null) text += `: ${p.loop_note}`;
    const span = el('span', 'loop', text);
    span.dataset.state = p.loop_state;
    loop.append(span);
  }
  const err = el('td');
  if (p.error != null) err.append(el('span', 'error', p.error));
  tr.append(name, el('td', null, p.branch ?? 'no branch'), el('td', 'n', Number(p.dirty ?? 0)), when,
    el('td', 'subject', p.last_subject ?? ''), loop,
    el('td', 'n', p.checklist_cards != null ? `${Number(p.checklist_passing ?? 0)} of ${Number(p.checklist_cards)}` : ''), err);
  return tr;
}

async function refresh() {
  if (document.visibilityState !== 'visible') return;
  try {
    const res = await fetch('/api/projects.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) return;
    const data = await res.json();
    body.replaceChildren(...(data.projects ?? []).map(row));
  } catch {
    // Keep the rows the page rendered.
  }
}

if (body) setInterval(() => refresh(), REFRESH_MS);
