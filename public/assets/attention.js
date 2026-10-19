// The attention list, its "also seen" fold and the coverage strip. Loads once, then every 60 seconds while the
// tab is visible. Every value from the server is written with textContent; a url is a link only after the
// server has checked it and only if it still starts the way the server allows.
import { el, token } from './app.js';

const list = document.getElementById('attention');
const REFRESH_MS = 60000;
const REASONS = ['useful', 'already knew', 'not actionable', 'too early', 'irrelevant', 'wrong evidence', 'resolved'];

function linkable(url) {
  return typeof url === 'string' && (url.startsWith('https://') || url.startsWith('http://127.0.0.1:'));
}

// Finds the part by id, or makes it after the list (the wall page ships only the list).
function part(id, make) {
  let node = document.getElementById(id);
  if (!node) {
    node = make();
    node.id = id;
    list.after(node);
  }
  return node;
}

async function send(body) {
  try {
    const res = await fetch('/api/feedback.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-KC-Token': token }, body: JSON.stringify(body) });
    if (res.ok) await refresh(true);
  } catch {
    // The next refresh shows the list as the server has it.
  }
}

function actions(f) {
  const box = el('div', 'actions');
  const snooze = el('button', null, 'Snooze a week');
  snooze.type = 'button';
  snooze.addEventListener('click', () => send({ id: f.id, snooze_days: 7 }));
  const pick = el('select');
  pick.setAttribute('aria-label', 'Feedback');
  pick.append(el('option', null, 'Feedback'));
  for (const r of REASONS) {
    const o = el('option', null, r);
    o.value = r;
    pick.append(o);
  }
  pick.addEventListener('change', () => {
    if (REASONS.includes(pick.value)) send({ id: f.id, reason: pick.value });
  });
  box.append(snooze, pick);
  return box;
}

function item(f) {
  const li = el('li', 'fact');
  li.dataset.severity = f.severity ?? '';
  const meta = el('p', 'meta', `${f.severity ?? ''} · ${f.source ?? ''}`);
  if (f.due_date) {
    const t = el('time', null, f.due_date);
    t.dateTime = f.due_date;
    meta.append(' · ', t);
  }
  const subject = el('p', 'subject');
  if (linkable(f.url)) {
    const a = el('a', null, f.subject);
    a.href = f.url;
    a.rel = 'noopener noreferrer';
    a.target = '_blank';
    subject.append(a);
  } else {
    subject.textContent = String(f.subject ?? '');
  }
  li.append(meta, subject);
  if (f.evidence != null) li.append(el('p', 'evidence', f.evidence));
  li.append(actions(f));
  return li;
}

function source(s) {
  const li = el('li', null, `${s.name ?? ''} · ${s.last_ok_at == null ? 'no good run yet' : s.status ?? ''}${s.stale ? ' · stale' : ''}`);
  li.dataset.stale = s.stale ? '1' : '0';
  if (s.last_error != null) li.append(el('span', 'error', ` ${s.last_error}`));
  return li;
}

function render(data) {
  const items = data.items ?? [];
  const also = data.also ?? [];
  const sources = data.sources ?? [];
  list.replaceChildren(...(items.length ? items.map(item) : [el('li', 'quiet', 'Nothing needs you.')]));
  const fold = part('attention-also', () => el('details', 'also'));
  fold.hidden = also.length === 0;
  const alsoList = el('ul', 'list');
  alsoList.append(...also.map(item));
  fold.replaceChildren(el('summary', null, `Also seen (${also.length})`), alsoList);
  const strip = part('attention-sources', () => el('ul', 'sources'));
  strip.replaceChildren(...(sources.length ? sources.map(source) : [el('li', 'quiet', 'No collector has run yet.')]));
}

async function refresh(force = false) {
  if (!force && document.visibilityState !== 'visible') return;
  try {
    const res = await fetch('/api/attention.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) throw new Error(`attention ${res.status}`);
    render(await res.json());
  } catch {
    unavailable();
  }
}

// A failed read says so: an empty or old list would read as "nothing needs you".
function unavailable() {
  const li = el('li', 'quiet', 'Attention unavailable.');
  li.dataset.unavailable = '1';
  list.replaceChildren(li);
}

if (list) {
  refresh(true);
  setInterval(() => refresh(), REFRESH_MS);
}
