// Ask Claude and the answers list. Every value from the server is written with textContent.
// Every /api/ request sends it: reads as well as writes are refused without it.
export const token = document.querySelector('meta[name="kc-token"]')?.content ?? '';
const form = document.getElementById('ask-form');
const note = document.getElementById('ask-note');
const list = document.getElementById('answers');
const POLL_MS = 2500;
let timer = null;

export function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = String(text ?? '');
  return node;
}

function answerItem(a) {
  const li = el('li');
  li.dataset.status = a.status ?? '';
  const text = a.status === 'done' ? a.answer : a.status === 'failed' ? (a.error ?? 'failed') : 'Working on it.';
  li.append(
    el('p', 'meta', `${a.project || 'home'} · ${a.status ?? ''}`),
    el('p', 'question', a.question),
    el('p', 'answer', text),
  );
  return li;
}

function pending() {
  return list.querySelector('[data-status="queued"], [data-status="running"]') !== null;
}

async function refresh() {
  try {
    const res = await fetch('/api/answers.php', { cache: 'no-store', headers: { 'X-KC-Token': token } });
    if (!res.ok) return;
    const data = await res.json();
    list.replaceChildren(...(data.answers ?? []).map(answerItem));
  } catch {
    // Offline or the server is restarting; the next poll tries again.
  }
}

function poll() {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    await refresh();
    if (pending()) poll();
  }, POLL_MS);
}

if (form) {
  const button = form.querySelector('button[type="submit"]');
  button.disabled = false;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const question = form.question.value.trim();
    if (question === '') return;
    button.disabled = true;
    note.textContent = 'Sending.';
    try {
      const res = await fetch('/api/ask.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-KC-Token': token },
        body: JSON.stringify({ question, project: form.project.value }),
      });
      if (res.ok) {
        form.question.value = '';
        note.textContent = 'Queued. The answer shows below when it is done.';
        await refresh();
        poll();
      } else {
        const data = await res.json().catch(() => ({}));
        note.textContent = `Not sent: ${data.error ?? res.status}.`;
      }
    } catch {
      note.textContent = 'Not sent: the dashboard did not answer.';
    } finally {
      button.disabled = false;
    }
  });
}

if (list && pending()) poll();
