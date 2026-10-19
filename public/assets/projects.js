// The project map. Rebuilds the tiles from api/projects.php every 60 seconds while the tab is visible.
// The page already renders the tiles without JS; every value from the server is written with textContent.
import { el, token } from './app.js';

const list = document.getElementById('projects');
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

function tile(p) {
  const li = el('li', 'tile');
  li.dataset.slug = p.slug ?? '';
  const meta = el('p', 'meta', `${p.branch ?? 'no branch'} · ${Number(p.dirty ?? 0)} dirty`);
  const when = age(p.last_date);
  if (when) {
    const t = el('time', null, when);
    t.dateTime = p.last_date;
    meta.append(' · ', t);
  }
  li.append(el('h3', null, p.slug), meta);
  if (p.last_subject != null) li.append(el('p', 'subject', p.last_subject));
  if (p.loop_state != null && p.loop_state !== 'none') {
    let text = `Loop ${p.loop_state}`;
    if (p.loop_card != null) text += `, card ${p.loop_card}`;
    if (p.loop_note != null) text += `: ${p.loop_note}`;
    const loop = el('p', 'loop', text);
    loop.dataset.state = p.loop_state;
    li.append(loop);
  }
  if (p.checklist_cards != null) {
    li.append(el('p', 'meta', `Checklist ${Number(p.checklist_passing ?? 0)} of ${Number(p.checklist_cards)}`));
  }
  if (p.error != null) li.append(el('p', 'error', p.error));
  return li;
}

async function refresh() {
  if (document.visibilityState !== 'visible') return;
  try {
    const res = await fetch('/api/projects.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) return;
    const data = await res.json();
    list.replaceChildren(...(data.projects ?? []).map(tile));
  } catch {
    // Keep the tiles the page rendered.
  }
}

if (list) setInterval(() => refresh(), REFRESH_MS);
