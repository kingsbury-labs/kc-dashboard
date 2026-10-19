// The numbers panel: one entry per source (a property's GA4 or Search Console, the status endpoint, a Bugsink
// project), rebuilt the way View::numbers renders it. Loads once, then every 10 minutes while the tab is visible.
// Every value from the server is written with textContent; the panel has no links.
import { el, token } from './app.js';

const list = document.getElementById('numbers');
const REFRESH_MS = 600000;
const NAMES = {
  sessions: 'sessions', users: 'users', active_users: 'active now', clicks: 'clicks', impressions: 'impressions',
  ctr: 'CTR', position: 'avg position', open_issues: 'open issues', checks: 'checks', down: 'down',
};

function shown(metric, v) {
  if (typeof v !== 'number') return '?';
  if (metric === 'ctr') return `${(v * 100).toFixed(1)}%`;
  if (metric === 'position') return v.toFixed(1);
  return String(v);
}

function source(s) {
  const li = el('li', 'number');
  li.dataset.status = String(s.status ?? '');
  li.dataset.stale = s.stale ? '1' : '0';
  const values = s.values && typeof s.values === 'object' ? s.values : {};
  const bits = [];
  const pages = el('ol', 'pages');
  for (const [metric, v] of Object.entries(values)) {
    const value = v && typeof v === 'object' ? v.value : null;
    const label = v && typeof v === 'object' && typeof v.label === 'string' ? v.label : '';
    if (metric.startsWith('top_page:')) pages.append(el('li', null, `${label} · ${shown(metric, value)}`));
    else if (metric === 'status') bits.push(label || 'unknown');
    // A Bugsink count that hit its page cap is a floor.
    else if (NAMES[metric]) bits.push(`${NAMES[metric]} ${shown(metric, value)}${label === 'partial' ? '+' : ''}`);
  }
  if (s.last_ok_at == null) bits.push('no good run yet');
  if (s.stale) bits.push('stale');
  li.append(el('p', 'subject', String(s.name ?? '')), el('p', 'meta', bits.join(' · ')));
  if (pages.children.length) li.append(pages);
  if (typeof s.last_error === 'string' && s.last_error !== '') li.append(el('p', 'error', s.last_error));
  return li;
}

async function load() {
  try {
    const res = await fetch('/api/numbers.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) throw new Error(`numbers ${res.status}`);
    const data = await res.json();
    const items = (Array.isArray(data.sources) ? data.sources : []).map(source);
    list.replaceChildren(...(items.length ? items : [el('li', 'quiet', 'No numbers collected yet.')]));
  } catch {
    list.replaceChildren(el('li', 'quiet', 'Numbers unavailable.'));
  }
}

if (list) {
  load();
  setInterval(() => {
    if (document.visibilityState === 'visible') load();
  }, REFRESH_MS);
}
