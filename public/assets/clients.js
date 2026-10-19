// Refreshes the clients panel when the tab comes back into view. The page already renders it without JS.
import { el, token } from './app.js';

const clientList = document.getElementById('clients');
const leadList = document.getElementById('leads');
const LABELS = { site: 'Site', ga4: 'GA4', search_console: 'Search Console', billing: 'Billing', repo: 'Repo', folder: 'Folder' };

function row(dl, label, value) {
  if (!value) return;
  dl.append(el('dt', null, label), el('dd', null, value));
}

function summary(name, tag, due) {
  const s = el('summary');
  s.append(el('span', 'name', name), ' ', el('span', 'tag', tag));
  if (due) {
    const t = el('time', null, due);
    t.dateTime = due;
    s.append(' ', t);
  }
  return s;
}

function safeUrl(url) {
  return typeof url === 'string' && /^https?:\/\/[^\s<>"'\\]+$/i.test(url);
}

function clientItem(c) {
  const details = el('details');
  const dl = el('dl');
  row(dl, 'Next milestone', c.milestone);
  row(dl, 'Blocker', c.blocker);
  row(dl, 'Status', c.status);
  row(dl, 'Due', c.due_text);
  details.append(summary(c.client, c.phase, c.due), dl);
  const links = Object.entries(c.links ?? {}).filter(([, url]) => safeUrl(url));
  if (links.length) {
    const ul = el('ul', 'links');
    for (const [kind, url] of links) {
      const a = el('a', null, LABELS[kind] ?? kind);
      a.href = url;
      a.rel = 'noopener noreferrer';
      a.target = '_blank';
      const li = el('li');
      li.append(a);
      ul.append(li);
    }
    details.append(ul);
  }
  const li = el('li');
  li.append(details);
  return li;
}

function leadItem(l) {
  const details = el('details');
  const dl = el('dl');
  row(dl, 'Contact', l.name);
  row(dl, 'Town', l.town);
  row(dl, 'Price', l.price);
  row(dl, 'Next action', l.next_action);
  row(dl, 'Notes', l.notes);
  details.append(summary(l.business, l.stage, null), dl);
  const li = el('li');
  li.append(details);
  return li;
}

async function refresh() {
  try {
    const res = await fetch('/api/clients.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) return;
    const data = await res.json();
    // An open panel stays open: only rebuild when nothing is expanded.
    if (document.querySelector('#clients details[open], #leads details[open]')) return;
    clientList.replaceChildren(...(data.clients ?? []).map(clientItem));
    leadList.replaceChildren(...(data.leads ?? []).map(leadItem));
  } catch {
    // Keep what the page rendered.
  }
}

if (clientList && leadList) {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
  });
}
