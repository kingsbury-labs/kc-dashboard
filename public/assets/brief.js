// Brief me, and remember / recall from the Ask box. Brief me POSTs to api/brief.php for today's briefing, which builds
// it when there is none. Ask box input starting "remember " goes to api/remember.php and "recall " to api/recall.php
// by a plain prefix match, never to a model. Every value from the server is written with textContent.
import { el, token } from './app.js';

const panel = document.getElementById('briefing');
const heading = document.getElementById('briefing-h');
const body = document.getElementById('briefing-body');
const question = document.getElementById('ask-question');
const note = document.getElementById('ask-note');

function show(title) {
  if (!panel) return null;
  heading.textContent = title;
  body.replaceChildren();
  panel.hidden = false;
  panel.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  return body;
}

// Same reading as View::briefingBody: "## " is a heading, "- " a list item, anything else a line of text.
function render(md, into) {
  let list = null;
  for (const line of md.split(/\r?\n/)) {
    if (line.startsWith('- ')) {
      if (!list) list = into.appendChild(el('ul', 'b-list'));
      list.appendChild(el('li', '', line.slice(2)));
      continue;
    }
    list = null;
    if (line.startsWith('## ')) into.appendChild(el('h3', '', line.slice(3)));
    else if (line.trim() !== '' && !line.startsWith('# ')) into.appendChild(el('p', '', line));
  }
}

async function brief(button) {
  button.disabled = true;
  const into = show('Briefing');
  if (!into) return;
  into.appendChild(el('p', 'quiet', 'Building the briefing.'));
  try {
    const res = await fetch('/api/brief.php', { method: 'POST', cache: 'no-store', headers: { 'X-KC-Token': token } });
    const data = await res.json().catch(() => ({}));
    into.replaceChildren();
    if (!res.ok) {
      into.appendChild(el('p', 'quiet', `No briefing: ${data.error ?? res.status}.`));
      return;
    }
    heading.textContent = `Briefing ${data.day}`;
    render(String(data.body ?? ''), into);
  } catch {
    into.replaceChildren(el('p', 'quiet', 'No briefing: the dashboard did not answer.'));
  } finally {
    button.disabled = false;
  }
}

for (const button of document.querySelectorAll('[data-brief]')) {
  button.disabled = false;
  button.addEventListener('click', () => brief(button));
}

async function remember(text) {
  note.textContent = 'Saving.';
  try {
    const res = await fetch('/api/remember.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-KC-Token': token },
      body: JSON.stringify({ text }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      question.value = '';
      note.textContent = 'Remembered.';
    } else {
      note.textContent = `Not saved: ${data.error ?? res.status}.`;
    }
  } catch {
    note.textContent = 'Not saved: the dashboard did not answer.';
  }
}

async function recall(q) {
  note.textContent = 'Looking.';
  try {
    const res = await fetch(`/api/recall.php?${new URLSearchParams({ q })}`, { cache: 'no-store', headers: { 'X-KC-Token': token } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      note.textContent = `No recall: ${data.error ?? res.status}.`;
      return;
    }
    note.textContent = '';
    const into = show(`Recall: ${q}`);
    if (!into) return;
    const matches = Array.isArray(data.matches) ? data.matches : [];
    if (matches.length === 0) into.appendChild(el('p', 'quiet', 'Nothing remembered with those words.'));
    const list = into.appendChild(el('ul', 'b-list'));
    for (const m of matches) list.appendChild(el('li', '', `${m.date} ${m.time} ${m.text}`));
  } catch {
    note.textContent = 'No recall: the dashboard did not answer.';
  }
}

// Capture on the document runs before app.js's submit handler on the form, so a match never reaches Ask Claude.
document.addEventListener('submit', (event) => {
  if (!question || event.target !== question.form) return;
  const value = question.value;
  const m = /^(remember|recall) ([\s\S]+)$/i.exec(value.trimStart());
  if (!m || m[2].trim() === '') return;
  event.preventDefault();
  event.stopImmediatePropagation();
  if (m[1].toLowerCase() === 'remember') remember(m[2].trim());
  else recall(m[2].trim());
}, true);
