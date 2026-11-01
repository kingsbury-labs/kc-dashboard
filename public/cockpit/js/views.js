// The cockpit's screens, ported from the v4 concept with its demo data replaced by the snapshot from data.js.
// Every panel is live: agents, weather, plan limits, finance and news, and (card 109) agenda, email and follow-ups.
import { el, add, btn, card, pill, link, go, ago, clock, icon, notConnected, $ } from './dom.js';
import { state, liveState, waiting, skeletonOn, postFollow } from './data.js';

const STEPS = ['Work', 'Gate', 'Audit', 'Done'];
const PILL = { working: 'working', waiting: 'waiting', done: 'done', failed: 'failed', idle: 'idle' };

function snap() {
  return state.snap ?? { projects: [], sessions: [], agents: [], talk: [], needs: [], files: [] };
}

function everyone() {
  const s = snap();
  return [
    ...s.sessions.map((x) => ({ ...x, name: x.title || `${x.kind === 'loop' ? `${x.project} loop` : x.project} ${x.session.slice(0, 6)}`, type: x.kind, state: liveState(x) })),
    ...s.agents.map((a) => ({ ...a, name: `${a.type} ${a.key.split(':')[1]?.slice(0, 8) ?? ''}` })),
  ];
}

function toolText(a) {
  return a.tool ? `${a.tool}${a.target ? ' · ' + a.target.split(/[\\/]/).slice(-2).join('/') : ''}` : '';
}

function skeleton(c, on) {
  c.classList.toggle('skeleton', on);
}

export function agentProgress(a, tableMode = false) {
  const wrap = el('div', tableMode ? 'agent-progress-cell' : 'agent-progress');
  const stage = a.step;
  if (!stage) {
    wrap.append(el('span', 'interactive-label', 'interactive'));
    return wrap;
  }
  const steps = el('div', 'mini-stepper');
  [1, 2, 3, 4].forEach((n) => steps.append(el('i', 'mini-step ' + (n < stage || stage === 4 ? 'done' : n === stage ? 'active' : ''))));
  const lab = el('div', 'progress-label');
  add(lab, el('span', '', `${STEPS[stage - 1]} · ${stage} of 4${a.card ? ' · card ' + a.card : ''}`), el('span', '', `${stage * 25}%`));
  add(wrap, steps, lab);
  return wrap;
}

function sessionPath(a) {
  return `session/${encodeURIComponent(a.session)}`;
}

const NEEDS_SHOWN = 6;
let needsOpen = false;
export function needsCard() {
  const s = snap();
  const c = card('Needs you', 'needs-card');
  const list = el('div', 'need-list');
  c.querySelector('.card-head').append(pill(s.needs.length ? 'waiting' : 'done'));
  if (!s.needs.length) list.append(el('p', 'agenda-empty', state.snap ? 'Nothing needs you.' : waiting()));
  // The API sends them newest first; the first NEEDS_SHOWN until Rob asks for the rest.
  s.needs.slice(0, needsOpen ? s.needs.length : NEEDS_SHOWN).forEach((x) => {
    const r = btn('', 'need clickable', () => go(x.session ? `session/${encodeURIComponent(x.session)}` : `project/${encodeURIComponent(x.project ?? '')}`));
    add(r, el('b', '', x.title), el('time', '', ago(x.ts)), el('p', '', x.detail));
    list.append(r);
  });
  c.append(list);
  if (s.needs.length > NEEDS_SHOWN) {
    c.append(btn(needsOpen ? 'Show fewer' : `${s.needs.length} waiting, show all`, 'text-btn', () => {
      needsOpen = !needsOpen;
      c.replaceWith(needsCard());
    }));
  }
  skeleton(c, skeletonOn());
  return c;
}

const WORKING_SHOWN = 8;
export function agentsCard() {
  const working = everyone().filter((a) => a.state === 'working' || a.state === 'waiting');
  const c = card('Working now', 'agents-card');
  c.querySelector('.card-head').append(el('span', 'subtle', `${working.length} active`));
  const list = el('div', 'agent-list');
  if (!working.length) list.append(el('p', 'agenda-empty', state.snap ? 'No agent is working.' : waiting()));
  working.slice(0, WORKING_SHOWN).forEach((a) => {
    const r = el('div', 'agent-row clickable');
    const main = btn('', 'agent-main', () => go(sessionPath(a)));
    const top = el('div', 'agent-top');
    const meta = el('div', 'agent-meta');
    add(top, el('b', '', `${a.name} · ${a.project}`), pill(PILL[a.state] ?? a.state));
    add(meta, el('code', '', toolText(a)), el('span', '', ago(a.started)));
    add(main, top, agentProgress(a), meta);
    add(r, el('i', 'dot ' + (a.state === 'waiting' ? 'waiting' : 'live')), main);
    list.append(r);
  });
  c.append(list);
  skeleton(c, skeletonOn());
  return c;
}

const TALK_SHOWN = 10;
let talkFilter = 'All';
let talkOpen = false;
export function agentTalkCard() {
  const c = card('Agent talk', 'talk-card');
  const filters = el('div', 'talk-filters');
  const feed = el('div', 'talk-feed');
  const more = el('div');
  const kind = { agent: 'hand-off', handback: 'report', send: 'message', question: 'question' };
  function draw() {
    feed.replaceChildren();
    more.replaceChildren();
    const items = snap().talk.filter((x) => talkFilter === 'All' || (talkFilter === 'Hand-offs' && x.kind === 'agent')
      || (talkFilter === 'Questions' && x.kind === 'question') || (talkFilter === 'Needs Rob' && x.cas === 'Needs Rob'));
    if (!items.length) feed.append(el('p', 'agenda-empty', state.snap ? 'No agent talk in the last 7 days.' : waiting()));
    if (items.length > TALK_SHOWN) {
      more.append(btn(talkOpen ? 'Show fewer' : `Show ${items.length - TALK_SHOWN} more`, 'text-btn', () => { talkOpen = !talkOpen; draw(); }));
    }
    items.slice(0, talkOpen ? items.length : TALK_SHOWN).forEach((x) => {
      const r = el('article', 'talk-item');
      const route = el('div', 'talk-route');
      const meta = el('div', 'talk-meta');
      const open = () => go(`session/${encodeURIComponent(x.fromKey.split(':')[0])}`);
      r.tabIndex = 0;
      r.title = x.text;
      r.addEventListener('click', open);
      r.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') open(); });
      add(route, el('b', '', x.from), el('i', '', '→'), el('b', '', x.to), el('small', '', ago(x.ts)));
      add(meta, el('span', 'talk-kind', kind[x.kind] ?? x.kind), el('span', 'cas-state' + (x.cas === 'Needs Rob' ? ' rob' : ''), x.cas));
      add(r, route, el('p', 'talk-message', x.text), meta);
      feed.append(r);
    });
  }
  ['All', 'Hand-offs', 'Questions', 'Needs Rob'].forEach((x) => filters.append(btn(x, 'talk-filter ' + (x === talkFilter ? 'on' : ''), (e) => {
    filters.querySelectorAll('button').forEach((b) => b.classList.remove('on'));
    e.currentTarget.classList.add('on');
    talkFilter = x;
    draw();
  })));
  add(c, filters, feed, more);
  draw();
  skeleton(c, skeletonOn());
  return c;
}

// The daily panels from the snapshot's daily block (card 108). A missing part keeps its shape with dashes.
function daily() {
  return state.snap?.daily ?? {};
}

const deg = (v) => (typeof v === 'number' ? `${Math.round(v)}°` : '--');
const money = (c) => (typeof c === 'number' ? (c / 100).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' }) : '–');

function planCard() {
  const u = daily().usage;
  const c = el('section', 'card plan-card');
  const title = el('div', 'plan-title');
  add(title, el('strong', '', 'Plan limits'));
  c.append(title);
  // One group per tool: its own reading age, muted past 30 minutes (the API's stale flag).
  [['Claude', u?.at ? u : null], ['Codex', u?.codex]].forEach(([name, g]) => {
    const group = el('div', 'limit-group' + (g?.stale ? ' stale' : ''));
    add(group, add(el('div', 'limit-head'), el('b', '', name), el('small', 'subtle', g ? `Read ${ago(g.at)} ago` : 'No reading yet')));
    [['5-hour use', g?.five_hour], ['Weekly', g?.seven_day]].forEach(([x, w]) => {
      const r = el('div', 'limit-row');
      const bar = el('div', 'thin-bar');
      const fill = el('i');
      // Unknown is an empty bar, never a full one.
      fill.style.width = w ? `${Math.min(100, Math.max(0, w.percent))}%` : '0%';
      bar.append(fill);
      add(r, el('span', '', x), bar, el('b', '', w ? `${w.percent}%` : '--'), w?.resets ? el('small', 'subtle', `resets ${clock(w.resets)}`) : null);
      group.append(r);
    });
    c.append(group);
  });
  skeleton(c, skeletonOn());
  if (!state.snap) return skeletonOn() ? c : notConnected(c, waiting());
  return u ? c : notConnected(c, 'No reading yet');
}

function weatherCard() {
  const w = daily().weather;
  const c = card('Arnprior Ontario', 'weather-card');
  const a = link('', 'weather-link', 'https://weather.gc.ca/city/pages/on-118_metric_e.html');
  a.setAttribute('aria-label', 'Open Environment Canada weather for Arnprior');
  a.append(c.querySelector('.card-head'));
  const t = el('div', 'weather-top');
  const copy = el('div', 'weather-copy');
  add(copy, el('b', '', w?.condition || 'Weather'),
    el('small', '', w ? `High ${deg(w.high)} · Low ${deg(w.low)} · ${ago(w.at)} ago` : state.snap ? 'No reading yet' : waiting()));
  add(t, el('div', 'weather-temp', deg(w?.temp)), copy);
  a.append(t);
  if (w?.hours?.length) {
    const hours = el('div', 'weather-hours');
    w.hours.forEach((h) => add(hours, add(el('span', 'weather-hour'), el('small', '', h.time), el('b', '', deg(h.temp)))));
    a.append(hours);
  }
  c.append(a);
  skeleton(c, skeletonOn());
  return c;
}

function newsCard() {
  const items = daily().news ?? [];
  const c = card('AI news', 'news-card');
  const list = el('div', 'news-list');
  items.forEach((x) => {
    if (!/^https?:\/\//i.test(x.url)) return;
    const r = link('', 'news-item', x.url);
    add(r, el('b', '', x.title), el('small', '', `${x.source} · ${ago(x.ts)}`));
    list.append(r);
  });
  if (!list.childElementCount) list.append(el('p', 'agenda-empty', state.snap ? 'No AI news stored yet.' : waiting()));
  c.append(list);
  skeleton(c, skeletonOn());
  return c;
}

function financeCard() {
  const f = daily().finance;
  const c = card('kc-finance', 'finance-card');
  if (f) c.querySelector('.card-head').append(el('span', 'subtle', `${ago(f.at)} ago`));
  const due = (x) => (x ? `${x.subject} · ${x.due} · ${money(x.amount_cents)}` : '–');
  const rows = el('div', 'finance-strip');
  [['Receivables', money(f?.receivables_cents)], ['Next due', due(f?.next_due)], ['Reserve', money(f?.reserve_cents)],
    ['Oldest overdue', due(f?.oldest_overdue)]].forEach(([k, v]) => add(rows, add(el('div', 'finance-row'), el('span', '', k), el('b', '', v))));
  c.append(rows);
  if (f?.lines?.length) {
    const lines = el('div', 'finance-lines');
    f.lines.forEach((x) => add(lines, add(el('div', 'finance-line'), el('span', '', x.kind), el('b', '', x.subject), el('small', '', x.date ?? '–'))));
    c.append(lines);
  }
  skeleton(c, skeletonOn());
  if (!state.snap) return skeletonOn() ? c : notConnected(c, waiting());
  return f ? c : notConnected(c, 'Finance file not readable');
}

// The day panels from the snapshot's day block (card 109): calendar and mail caches, and the follow-up list.
function day() {
  return state.snap?.day ?? {};
}

function calendarCard() {
  const k = day().calendar;
  const c = card('Agenda', 'agenda-card');
  const now = new Date();
  c.querySelector('.card-head').append(el('span', 'subtle', now.toLocaleDateString('en-CA', { month: 'long', year: 'numeric' })));
  const busy = new Set((k?.events ?? []).map((e) => new Date(e.start * 1000).getDate()));
  const cal = el('div', 'calendar-grid');
  ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach((x) => cal.append(el('span', 'cal-label', x)));
  const first = new Date(now.getFullYear(), now.getMonth(), 1).getDay();
  const days = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
  for (let i = 0; i < first; i++) cal.append(el('span', 'cal-day muted', ''));
  for (let d = 1; d <= days; d++) cal.append(el('span', 'cal-day' + (d === now.getDate() ? ' today' : '') + (busy.has(d) ? ' busy' : ''), String(d)));
  c.append(cal);
  const list = el('div', 'agenda-list');
  (k?.today ?? []).forEach((e) => {
    const r = el('div', 'agenda-item');
    add(r, el('time', '', e.all_day ? 'All day' : clock(e.start)), add(el('div'), el('b', '', e.title), e.location ? el('small', '', e.location) : null));
    list.append(r);
  });
  if (k && !k.today.length) list.append(el('p', 'agenda-empty', 'Nothing on today.'));
  c.append(list);
  skeleton(c, skeletonOn());
  if (!state.snap) return skeletonOn() ? c : notConnected(c, waiting());
  return k ? c : notConnected(c, 'No calendar reading yet');
}

const EMAIL_SHOWN = 6;
let emailOpen = false;
function emailCard() {
  const m = day().mail;
  const c = card('Email summaries', 'email-card');
  if (m) c.querySelector('.card-head').append(el('span', 'subtle', `${ago(m.at)} ago`));
  if (m?.error) c.append(el('p', 'agenda-empty', m.error));
  const list = el('div', 'email-list');
  const rows = m?.rows ?? [];
  rows.slice(0, emailOpen ? rows.length : EMAIL_SHOWN).forEach((x) => {
    const r = x.link ? link('', 'email-item', x.link) : el('div', 'email-item');
    add(r, el('b', '', x.from), el('span', '', x.subject), x.summary ? el('p', '', x.summary) : null, el('small', '', ago(x.date)));
    list.append(r);
  });
  if (m && !m.rows.length && !m.error) list.append(el('p', 'agenda-empty', 'No new mail in the last two weeks.'));
  c.append(list);
  if (rows.length > EMAIL_SHOWN) {
    c.append(btn(emailOpen ? 'Show fewer' : 'Show all', 'text-btn', () => {
      emailOpen = !emailOpen;
      c.replaceWith(emailCard());
    }));
  }
  skeleton(c, skeletonOn());
  if (!state.snap) return skeletonOn() ? c : notConnected(c, waiting());
  return m ? c : notConnected(c, 'No mail reading yet');
}

function followCard() {
  const f = day().follow ?? { threads: [], items: [] };
  const c = card('Follow-ups', 'follow-card');
  const list = el('div', 'follow-list');
  f.threads.forEach((x) => {
    const r = x.link ? link('', 'follow-item', x.link) : el('div', 'follow-item');
    add(r, el('b', '', `Reply to ${x.from}`), el('small', '', x.subject));
    list.append(r);
  });
  f.items.forEach((x) => {
    const r = el('label', 'follow-item' + (x.done ? ' done' : ''));
    const box = el('input');
    box.type = 'checkbox';
    box.checked = !!x.done;
    box.disabled = !!x.done;
    box.addEventListener('change', () => postFollow({ done: x.id }).catch(() => { box.checked = false; }));
    add(r, box, el('span', '', x.text));
    list.append(r);
  });
  if (!list.childElementCount) list.append(el('p', 'agenda-empty', state.snap ? 'Nothing to follow up.' : waiting()));
  const form = el('form', 'follow-add');
  const input = el('input');
  input.maxLength = 200;
  input.placeholder = 'Add a follow-up';
  input.setAttribute('aria-label', 'Add a follow-up');
  form.append(input);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (text) postFollow({ add: text }).then(() => { input.value = ''; }).catch(() => {});
  });
  add(c, list, form);
  skeleton(c, skeletonOn());
  return c;
}

export function renderOverview(page) {
  page.classList.add('overview-page');
  const top = el('section', 'daily-top');
  const form = el('form', 'google-form');
  form.action = 'https://www.google.com/search';
  form.method = 'get';
  form.target = '_blank';
  form.append(icon('search'));
  const q = el('input');
  q.name = 'q';
  q.placeholder = 'Search Google';
  q.setAttribute('aria-label', 'Search Google');
  add(form, q, el('small', '', 'Opens Google search'));
  const now = new Date();
  const hour = now.getHours();
  const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
  const n = snap().needs.length;
  const greet = el('div', 'daily-greeting');
  add(greet, el('h1', '', `Good ${part} Rob`), el('p', '', now.toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric' })
    + (state.snap ? ` · ${n === 0 ? 'Nothing needs you' : n === 1 ? 'One thing needs you' : `${n} things need you`}` : '')));
  add(top, form, greet, weatherCard(), planCard());
  page.append(top);
  const wide = matchMedia('(min-width:1100px)').matches;
  const portrait = matchMedia('(min-width:1000px) and (max-aspect-ratio:4/5)').matches;
  const email = emailCard();
  const follow = followCard();
  const finance = financeCard();
  const news = newsCard();
  if (wide || portrait) {
    const cols = el('div', 'daily-cols');
    const a = el('div', 'daily-col');
    const b = el('div', 'daily-col');
    if (portrait) {
      // Card 112: Agent talk is capped at 10, so finance moves right to keep the columns ending near each other.
      add(a, needsCard(), calendarCard(), email, follow);
      add(b, agentTalkCard(), agentsCard(), finance, news);
      add(cols, a, b);
    } else {
      const c = el('div', 'daily-col');
      add(a, needsCard(), email, follow);
      add(b, calendarCard(), finance, news);
      add(c, agentTalkCard(), agentsCard());
      add(cols, a, b, c);
    }
    page.append(cols);
  } else {
    const grid = el('div', 'daily-grid');
    add(grid, needsCard(), calendarCard(), agentTalkCard(), email, finance, follow, news, agentsCard());
    page.append(grid);
  }
  const cmd = $('.command');
  if (cmd && (wide || portrait)) {
    cmd.classList.add('docked');
    top.append(cmd);
  }
}

export function crumbs(items) {
  const c = el('div', 'crumbs');
  items.forEach((x, i) => {
    if (i) c.append(el('i', '', '/'));
    c.append(x.path ? btn(x.label, '', () => go(x.path)) : el('span', '', x.label));
  });
  return c;
}

function head(title, desc, side) {
  const h = el('header', 'page-head');
  const a = el('div');
  add(a, el('h1', '', title), el('p', '', desc));
  add(h, a, side || null);
  return h;
}

function fileRow(op, path, ts) {
  const r = el('div', 'file-row');
  add(r, el('span', 'op', op), el('code', '', path), el('time', '', clock(ts)));
  return r;
}

// Each agent under its parent, depth first.
function tree(rootKeys, all) {
  const out = [];
  const kids = (k) => all.filter((a) => a.parent === k);
  const walk = (a, level) => {
    out.push([level, a]);
    kids(a.key).forEach((c) => walk(c, level + 1));
  };
  rootKeys.forEach((a) => walk(a, 0));
  return out;
}

function treeCard(roots, all) {
  const t = card('Who launched whom');
  const rows = tree(roots, all);
  if (!rows.length) t.append(el('p', 'agenda-empty', 'No sessions in the last 7 days.'));
  rows.slice(0, 40).forEach(([level, a]) => {
    const r = el('div', 'tree-row clickable');
    r.style.setProperty('--level', level);
    r.addEventListener('click', () => go(sessionPath(a)));
    add(r, el('i', 'dot ' + (a.state === 'working' ? 'live' : a.state === 'waiting' ? 'waiting' : '')),
      add(el('div'), el('b', '', a.name), el('small', '', [a.type, a.model, toolText(a)].filter(Boolean).join(' · '))), pill(PILL[a.state] ?? a.state));
    t.append(r);
  });
  return t;
}

export function renderProject(page, name) {
  const s = snap();
  const p = s.projects.find((x) => x.name === name) ?? { name, sessions: 0, agents: 0, loop: null, checklist: null, parked: [] };
  page.append(crumbs([{ label: 'Overview', path: 'overview' }, { label: 'Projects' }, { label: name }]));
  const side = el('div', 'head-side');
  if (p.checklist) side.append(el('span', 'branch', `${p.checklist.passing} of ${p.checklist.cards} cards pass`));
  side.append(pill(p.working ? 'working' : 'idle'));
  page.append(head(name, `${p.sessions} sessions · ${p.agents} agents in the last 7 days`, side));
  const grid = el('div', 'project-grid');
  const pipe = card(p.loop?.card ? `Card ${p.loop.card} pipeline` : 'Loop pipeline');
  if (p.loop?.status) pipe.querySelector('.card-head').append(el('span', 'subtle', p.loop.status));
  const steps = el('div', 'stepper');
  const at = p.loop?.step ?? 0;
  STEPS.forEach((x, i) => {
    const st = el('div', 'step ' + (i + 1 < at || at === 4 ? 'done' : i + 1 === at ? 'active' : ''));
    add(st, el('i'), el('span', '', x));
    steps.append(st);
  });
  pipe.append(steps);
  if (p.loop?.note) pipe.append(el('p', 'subtle', p.loop.note));
  p.parked.forEach((x) => pipe.append(el('p', 'subtle', `Parked: card ${x.id} ${x.title}`)));
  grid.append(pipe);
  const all = everyone().filter((a) => a.project === name);
  grid.append(treeCard(all.filter((a) => !a.parent), all));
  const keys = new Set(all.map((a) => a.key));
  const files = card('Files touched', 'files-card wide');
  const rows = s.files.filter((f) => keys.has(f.session)).slice(0, 20);
  if (!rows.length) files.append(el('p', 'agenda-empty', 'No file reads or writes recorded.'));
  rows.forEach((f) => files.append(fileRow(f.op, f.path, f.ts)));
  grid.append(files);
  page.append(grid);
}

export function renderSession(page, id) {
  const s = snap();
  const all = everyone();
  const me = all.find((a) => a.key === id) ?? all.find((a) => a.session === id && !a.parent);
  const mine = all.filter((a) => a.session === id);
  const project = me?.project ?? mine[0]?.project ?? '';
  page.append(crumbs([{ label: 'Overview', path: 'overview' }, { label: project, path: `project/${encodeURIComponent(project)}` }, { label: id.slice(0, 8) }]));
  const side = el('div', 'head-side');
  if (me) add(side, pill(PILL[me.state] ?? me.state), el('span', '', ago(me.started)));
  page.append(head(me?.name ?? id.slice(0, 8), me ? [me.model, me.kind ?? me.type, me.card ? `card ${me.card}` : ''].filter(Boolean).join(' · ') : 'Not in the last 7 days', side));
  const grid = el('div', 'session-grid');
  const trans = card('Agent talk in this session');
  const talk = s.talk.filter((x) => x.fromKey.split(':')[0] === id || x.toKey.split(':')[0] === id).slice().reverse();
  if (!talk.length) trans.append(el('p', 'agenda-empty', 'No briefs or reports yet.'));
  talk.forEach((x) => {
    const e = el('div', 'event ' + (x.kind === 'question' ? 'rob' : 'cas'));
    add(e, el('time', '', clock(x.ts)), el('p', '', `${x.from} → ${x.to}: ${x.text}`));
    trans.append(e);
  });
  if (me?.tool) {
    const e = el('div', 'event tool');
    add(e, el('time', '', clock(me.ended)), el('p', '', toolText(me)));
    trans.append(e);
  }
  grid.append(trans);
  const sidecol = el('aside');
  const trace = card('Subagent trace');
  const t0 = Math.min(...mine.map((a) => a.started).filter(Boolean), Infinity);
  const t1 = Math.max(...mine.map((a) => a.ended).filter(Boolean), -Infinity);
  const span = Math.max(1, t1 - t0);
  mine.slice(0, 16).forEach((a) => {
    const r = el('div', 'trace-row');
    const tr = el('div', 'trace-track');
    const i = el('i', a.state === 'waiting' ? 'wait' : '');
    i.style.left = `${((a.started - t0) / span) * 100}%`;
    i.style.width = `${Math.max(2, ((a.ended - a.started) / span) * 100)}%`;
    tr.append(i);
    add(r, el('span', '', a.parent ? a.type : 'session'), tr);
    trace.append(r);
  });
  sidecol.append(trace);
  const files = card('Files read and written');
  files.style.marginTop = '16px';
  const keys = new Set(mine.map((a) => a.key));
  const rows = s.files.filter((f) => keys.has(f.session)).slice(0, 20);
  if (!rows.length) files.append(el('p', 'agenda-empty', 'No file reads or writes recorded.'));
  rows.forEach((f) => files.append(fileRow(f.op, f.path, f.ts)));
  sidecol.append(files);
  grid.append(sidecol);
  page.append(grid);
}

let agentFilter = 'All';
export function renderAgents(page) {
  const list = everyone();
  const projects = new Set(list.map((a) => a.project).filter(Boolean));
  page.append(crumbs([{ label: 'Overview', path: 'overview' }, { label: 'Agents' }]));
  page.append(head('Every agent', `The last 7 days across ${projects.size} projects`));
  const count = (st) => list.filter((a) => a.state === st).length;
  const tools = el('div', 'toolbar');
  const body = el('tbody');
  const apply = () => body.querySelectorAll('tr').forEach((r) => { r.hidden = agentFilter !== 'All' && r.dataset.state !== agentFilter.toLowerCase(); });
  [['All', list.length], ['Working', count('working')], ['Waiting', count('waiting')], ['Idle', count('idle')], ['Done', count('done')]].forEach((x) => {
    tools.append(btn(`${x[0]} ${x[1]}`, 'filter ' + (x[0] === agentFilter ? 'on' : ''), (e) => {
      tools.querySelectorAll('button').forEach((b) => b.classList.remove('on'));
      e.currentTarget.classList.add('on');
      agentFilter = x[0];
      apply();
    }));
  });
  page.append(tools);
  const wrap = el('div', 'table-wrap');
  const table = el('table');
  const thead = el('thead');
  const hr = el('tr');
  ['Agent', 'Project', 'Type', 'Model', 'State', 'Progress', 'Current tool', 'Elapsed'].forEach((x) => hr.append(el('th', '', x)));
  thead.append(hr);
  list.forEach((a) => {
    const r = el('tr');
    r.dataset.state = a.state;
    r.addEventListener('click', () => go(sessionPath(a)));
    [a.name, a.project, a.type, a.model].forEach((x) => r.append(el('td', '', x)));
    const st = el('td');
    const pc = el('td');
    st.append(pill(PILL[a.state] ?? a.state));
    pc.append(agentProgress(a, true));
    r.append(st, pc, el('td', 'tool-cell', toolText(a)), el('td', '', ago(a.started, a.ended || undefined)));
    body.append(r);
  });
  apply();
  add(table, thead, body);
  wrap.append(table);
  if (!list.length) wrap.append(el('p', 'agenda-empty', state.snap ? 'No agents in the last 7 days.' : waiting()));
  page.append(wrap);
}
