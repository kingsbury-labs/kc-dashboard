// The headlines panel: hottest stories first, a topic filter and a "More" button for the next page. Loads once,
// then every 10 minutes while the tab is visible. Every value from the server is written with textContent; a link
// gets its href only after its protocol checks out as http: or https:.
import { el, token } from './app.js';

const list = document.getElementById('news');
const REFRESH_MS = 600000;
let topic = '';
let page = 1;

function isHttp(url) {
  if (typeof url !== 'string') return false;
  try {
    const p = new URL(url).protocol;
    return p === 'http:' || p === 'https:';
  } catch {
    return false;
  }
}

// Finds the part by id, or makes it next to the list (the wall page ships only the list).
function part(id, make, before = false) {
  let node = document.getElementById(id);
  if (!node) {
    node = make();
    node.id = id;
    before ? list.before(node) : list.after(node);
  }
  return node;
}

function story(s) {
  const li = el('li', 'story');
  const title = el('p', 'subject');
  if (isHttp(s.url)) {
    const a = el('a', null, s.title);
    a.href = s.url;
    a.rel = 'noopener noreferrer';
    a.target = '_blank';
    title.append(a);
  } else {
    title.textContent = String(s.title ?? '');
  }
  const sources = Array.isArray(s.sources) ? s.sources : [];
  const bits = [sources.join(', ')];
  if (s.points) bits.push(`${s.points} points`);
  li.append(title, el('p', 'meta', bits.join(' · ')));
  return li;
}

function filter(topics) {
  const pick = part('news-topic', () => el('select'), true);
  pick.setAttribute('aria-label', 'Topic');
  if (pick.options.length === 0) {
    pick.append(el('option', null, 'All topics'));
    pick.options[0].value = '';
    for (const t of topics) {
      const o = el('option', null, t);
      o.value = t;
      pick.append(o);
    }
    pick.addEventListener('change', () => {
      topic = topics.includes(pick.value) ? pick.value : '';
      load(1, false);
    });
  }
}

function more(show) {
  const button = part('news-more', () => el('button', null, 'More'));
  button.type = 'button';
  button.hidden = !show;
  if (!button.dataset.wired) {
    button.dataset.wired = '1';
    button.addEventListener('click', () => load(page + 1, true));
  }
}

async function load(next, append) {
  const q = new URLSearchParams({ page: String(next) });
  if (topic) q.set('topic', topic);
  try {
    const res = await fetch(`/api/news.php?${q}`, { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) throw new Error(`news ${res.status}`);
    const data = await res.json();
    const stories = (data.stories ?? []).map(story);
    page = next;
    if (append) list.append(...stories);
    else list.replaceChildren(...(stories.length ? stories : [el('li', 'quiet', 'No headlines yet.')]));
    filter(Array.isArray(data.topics) ? data.topics : []);
    more(data.more === true);
  } catch {
    if (!append) list.replaceChildren(el('li', 'quiet', 'Headlines unavailable.'));
  }
}

if (list) {
  load(1, false);
  setInterval(() => {
    if (document.visibilityState === 'visible' && page === 1) load(1, false);
  }, REFRESH_MS);
}
