// Find's fuzzy match. A query matches a text when its letters appear in order (a subsequence), scored higher for
// consecutive runs, word starts (after a space, slash, dash, dot, underscore or a lower-to-upper case change) and an
// early first hit. A query with no subsequence match still matches with one typo: a letter swapped, missing, extra
// or wrong, at a penalty. Ranked best first. No dependency.

const SEP = /[\s\\/_.\-:·]/;

function wordStart(text, i) {
  if (i === 0) return true;
  const p = text[i - 1];
  return SEP.test(p) || (p === p.toLowerCase() && text[i] !== text[i].toLowerCase());
}

// The subsequence score, or null when the letters are not all there in order.
export function subsequence(query, text) {
  const q = query.toLowerCase();
  const t = text.toLowerCase();
  if (q === '') return 0;
  let score = 0;
  let ti = 0;
  let run = 0;
  let first = -1;
  for (let qi = 0; qi < q.length; qi++) {
    const c = q[qi];
    if (c === ' ') continue;
    let found = -1;
    // Prefer a word-start hit ahead over the next plain one.
    for (let j = ti; j < t.length; j++) {
      if (t[j] === c && wordStart(text, j)) { found = j; break; }
    }
    const plain = t.indexOf(c, ti);
    if (plain === -1) return null;
    if (found === -1 || (plain === ti && run > 0)) found = plain;
    if (first === -1) first = found;
    run = found === ti && qi > 0 ? run + 1 : 0;
    score += 1 + run * 3 + (wordStart(text, found) ? 6 : 0);
    ti = found + 1;
  }
  score += Math.max(0, 8 - first) + (t.includes(q) ? 10 : 0) - t.length * 0.02;
  return score;
}

// Every string one edit away from the query: deletions, adjacent swaps; insertions and substitutions are left to a
// wildcard check below, which is cheaper than generating them.
function variants(q) {
  const out = new Set();
  for (let i = 0; i < q.length; i++) {
    out.add(q.slice(0, i) + q.slice(i + 1));
    if (i < q.length - 1) out.add(q.slice(0, i) + q[i + 1] + q[i] + q.slice(i + 2));
  }
  return out;
}

export function score(query, text) {
  const q = query.trim();
  if (q === '' || !text) return null;
  const s = subsequence(q, text);
  if (s !== null) return s;
  if (q.length < 3) return null;
  let best = null;
  for (const v of variants(q.toLowerCase())) {
    const vs = subsequence(v, text);
    if (vs !== null && (best === null || vs > best)) best = vs;
  }
  // A wrong letter: every letter but one in order.
  if (best === null) {
    for (let i = 0; i < q.length; i++) {
      const vs = subsequence(q.slice(0, i) + q.slice(i + 1), text);
      if (vs !== null && (best === null || vs > best)) best = vs;
    }
  }
  return best === null ? null : best - 12;
}

// items: [{label, detail, kind, path}]. Matches on label first, detail at a discount.
export function search(items, query, limit = 8) {
  const out = [];
  for (const it of items) {
    const a = score(query, it.label);
    const b = it.detail ? score(query, it.detail) : null;
    const s = a === null ? (b === null ? null : b - 5) : Math.max(a, b === null ? -Infinity : b - 5);
    if (s !== null) out.push({ ...it, score: s });
  }
  return out.sort((x, y) => y.score - x.score || x.label.localeCompare(y.label)).slice(0, limit);
}
