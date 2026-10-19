#!/usr/bin/env node
'use strict';
// Attribute the cost of each headless loop pass to roles (coordinator, subagent types).
// Usage: node loop-cost.cjs [--dir <loop dir>] [--out <report.md>] [--json <dump.json>]
// No dependencies. Reads one file at a time.
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const opt = (k, d) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : d; };
const DIR = opt('--dir', 'C:\\xampp\\htdocs\\sparrowbook\\.claude\\work\\loop');
const OUT = opt('--out', null);
const DETAIL = opt('--detail', '2026-10-10T03-34-55-124Z-card-12.jsonl');

// Relative price weights. 5m cache writes are 1.25x input, 1h writes are 2x.
let W = { input: 1, write5m: 1.25, write1h: 2, read: 0.1, output: 5 };
if (opt('--weights', null)) { const w = opt('--weights').split(',').map(Number); W = { input: w[0], write5m: w[1], write1h: w[2], read: w[3], output: w[4] }; }
const TYPES = ['input', 'write5m', 'write1h', 'read', 'output'];

function weigh(t, outScale) {
  return t.input * W.input + t.write5m * W.write5m + t.write1h * W.write1h + t.read * W.read + t.output * (outScale || 1) * W.output;
}
const zero = () => ({ input: 0, write5m: 0, write1h: 0, read: 0, output: 0 });

// ---------- loop.log ----------
function parseLog() {
  const runs = {}; // stamp -> { cards: {N: {title, line}}, handoff }
  let cur = null;
  const text = fs.readFileSync(path.join(DIR, 'loop.log'), 'utf8').split(/\r?\n/);
  for (const ln of text) {
    const m = ln.match(/^(\S+Z) (.*)$/);
    if (!m) continue;
    const [, iso, rest] = m;
    if (rest.startsWith('start:')) {
      const stamp = iso.replace(/[:.]/g, '-');
      cur = runs[stamp] = { cards: {}, costEquiv: {}, t: Date.parse(iso) };
      continue;
    }
    if (!cur) continue;
    let c = rest.match(/^card-(\d+): (continue|done): (.*)$/);
    if (c) { (cur.cards[c[1]] = cur.cards[c[1]] || {}).title = c[3].slice(0, 90); continue; }
    c = rest.match(/^card-(\d+): exit=.*cost-equiv=\$([\d.]+)/);
    if (c) { (cur.cards[c[1]] = cur.cards[c[1]] || {}).costEquiv = parseFloat(c[2]); continue; }
    c = rest.match(/^handoff: exit=.*cost-equiv=\$([\d.]+)/);
    if (c) cur.handoffCost = parseFloat(c[1]);
  }
  return runs;
}

// ---------- one pass ----------
function readLines(file, fn) {
  const buf = fs.readFileSync(file, 'utf8');
  let s = 0;
  while (s < buf.length) {
    let e = buf.indexOf('\n', s);
    if (e < 0) e = buf.length;
    if (e > s) {
      const line = buf.slice(s, e);
      let o = null;
      try { o = JSON.parse(line); } catch (_) { /* partial line */ }
      if (o) fn(o);
    }
    s = e + 1;
  }
}

function analyse(file, fallbackTotal, rates) {
  const agentTool = {}; // tool_use id -> { type, desc, parentMsgParent }
  const msgs = {};      // message.id -> { model, parent, tok{}, ctx }
  const results = {};   // Agent tool_use id -> text
  let result = null;
  let firstDesc = '';
  const toolUseParent = {}; // tool_use id -> parent_tool_use_id of the message holding it

  readLines(file, (e) => {
    if (e.type === 'assistant' && e.message) {
      const m = e.message, u = m.usage || {};
      const cc = u.cache_creation || {};
      let w5 = cc.ephemeral_5m_input_tokens || 0, w1 = cc.ephemeral_1h_input_tokens || 0;
      const wt = u.cache_creation_input_tokens || 0;
      if (w5 + w1 < wt) w1 += wt - (w5 + w1); // unsplit remainder: assume 1h
      const tok = { input: u.input_tokens || 0, write5m: w5, write1h: w1, read: u.cache_read_input_tokens || 0, output: u.output_tokens || 0 };
      const rec = msgs[m.id] || (msgs[m.id] = { model: m.model, parent: e.parent_tool_use_id || null, tok: zero(), ctx: 0 });
      for (const k of TYPES) if (tok[k] > rec.tok[k]) rec.tok[k] = tok[k];
      const ctx = tok.input + tok.read + tok.write5m + tok.write1h;
      if (ctx > rec.ctx) rec.ctx = ctx;
      for (const b of m.content || []) {
        if (b.type === 'tool_use') {
          toolUseParent[b.id] = e.parent_tool_use_id || null;
          if (b.name === 'Agent' || b.name === 'Task') {
            if (!firstDesc && b.input && b.input.description) firstDesc = b.input.description;
            agentTool[b.id] = { type: (b.input && b.input.subagent_type) || 'general-purpose', desc: (b.input && b.input.description) || '' };
          }
        }
      }
    } else if (e.type === 'system' && e.subtype === 'task_started' && e.tool_use_id) {
      const a = agentTool[e.tool_use_id] || (agentTool[e.tool_use_id] = { type: 'general-purpose', desc: '' });
      if (e.subagent_type) a.type = e.subagent_type;
      if (e.description && !a.desc) a.desc = e.description;
    } else if (e.type === 'user' && e.message && Array.isArray(e.message.content)) {
      for (const b of e.message.content) {
        if (b.type === 'tool_result' && b.tool_use_id) {
          let text = '';
          const r = e.tool_use_result;
          if (r && r.handbackReport && r.handbackReport.text) text = r.handbackReport.text;
          else if (typeof b.content === 'string') text = b.content;
          else if (Array.isArray(b.content)) text = b.content.map((x) => x.text || '').join('\n');
          if (r && r.agentType && agentTool[b.tool_use_id]) agentTool[b.tool_use_id].resultType = r.agentType;
          if (!(b.tool_use_id in results) || text.length > results[b.tool_use_id].length) results[b.tool_use_id] = text;
        }
      }
    } else if (e.type === 'result') {
      result = e;
    }
  });

  // role of a message: nearest named ancestor Agent call
  function roleOf(parent) {
    let p = parent, guard = 0;
    while (p && guard++ < 20) {
      if (agentTool[p]) return { role: agentTool[p].type, callId: p };
      p = toolUseParent[p] || null;
    }
    return { role: 'coordinator', callId: null };
  }

  // tokens by (model, role); and by call id
  const byModelRole = {};
  const calls = {}; // callId -> { type, desc, ctx, tok }
  const raw = {};   // model -> tokens
  for (const id of Object.keys(msgs)) {
    const m = msgs[id];
    const { role, callId } = roleOf(m.parent);
    const key = m.model + '|' + role;
    const bucket = byModelRole[key] || (byModelRole[key] = { model: m.model, role, tok: zero() });
    const rw = raw[m.model] || (raw[m.model] = zero());
    for (const k of TYPES) { bucket.tok[k] += m.tok[k]; rw[k] += m.tok[k]; }
    if (callId) {
      const c = calls[callId] || (calls[callId] = { type: role, desc: agentTool[callId].desc, ctx: 0, tok: zero(), byModel: {} });
      for (const k of TYPES) c.tok[k] += m.tok[k];
      if (m.ctx > c.ctx) c.ctx = m.ctx;
      const bm = c.byModel[m.model] || (c.byModel[m.model] = zero());
      for (const k of TYPES) bm[k] += m.tok[k];
    }
  }

  // cost split
  const mu = result && result.modelUsage ? result.modelUsage : null;
  const total = result && typeof result.total_cost_usd === 'number' ? result.total_cost_usd : (fallbackTotal != null ? fallbackTotal : null);
  const usedFallback = !(result && typeof result.total_cost_usd === 'number') && fallbackTotal != null;
  const roleCost = {};   // role -> $
  const callCost = {};   // callId -> $
  const detail = [];     // rows for detail
  let flag = '';
  const add = (o, k, v) => { o[k] = (o[k] || 0) + v; };
  const modelCosts = {};
  if (mu) {
    for (const [model, v] of Object.entries(mu)) modelCosts[model] = v.costUSD || 0;
  } else if (total !== null) {
    flag = usedFallback ? 'no-result-used-log-cost' : 'no-modelUsage';
    // split the pass total across models by stream weight
    const mw = {}; let sum = 0;
    for (const b of Object.values(byModelRole)) { const w = weigh(b.tok); mw[b.model] = (mw[b.model] || 0) + w; sum += w; }
    for (const [m, w] of Object.entries(mw)) modelCosts[m] = sum ? total * w / sum : 0;
  } else {
    if (rates) {
      flag = 'no-result-estimated';
      for (const [m, r] of Object.entries(raw)) if (rates[m]) modelCosts[m] = weigh(r, rates[m].os) * rates[m].rate;
    } else flag = 'no-result';
  }
  for (const [model, cost] of Object.entries(modelCosts)) {
    const buckets = Object.values(byModelRole).filter((b) => b.model === model);
    // The stream's output_tokens are partial (counted at message start). Scale each role's stream output so the model's total matches modelUsage.outputTokens.
    const rawOut = raw[model] ? raw[model].output : 0;
    const muOut = mu && mu[model] ? (mu[model].outputTokens || 0) : 0;
    const os = mu ? (rawOut > 0 && muOut > 0 ? muOut / rawOut : 1) : (rates && rates[model] ? rates[model].os : 1);
    const sum = buckets.reduce((s, b) => s + weigh(b.tok, os), 0);
    if (!sum) { add(roleCost, 'unattributed', cost); continue; }
    for (const b of buckets) {
      const share = weigh(b.tok, os) / sum;
      add(roleCost, b.role, cost * share);
      detail.push({ model, role: b.role, tok: b.tok, outScale: os, weight: weigh(b.tok, os), share, cost: cost * share });
    }
    for (const [id, c] of Object.entries(calls)) {
      const bm = c.byModel[model];
      if (bm) add(callCost, id, cost * weigh(bm, os) / sum);
    }
  }
  // models in stream but missing from modelUsage
  const missingModels = mu ? Object.keys(raw).filter((m) => !(m in mu)) : [];

  // reconciliation
  const recon = {};
  if (mu) {
    for (const [model, v] of Object.entries(mu)) {
      const r = raw[model] || zero();
      recon[model] = {
        stream: { input: r.input, write: r.write5m + r.write1h, read: r.read, output: r.output },
        mu: { input: v.inputTokens || 0, write: v.cacheCreationInputTokens || 0, read: v.cacheReadInputTokens || 0, output: v.outputTokens || 0, thinking: v.thinkingTokens || 0 },
      };
    }
  }

  const audits = [];
  for (const [id, a] of Object.entries(agentTool)) {
    if (a.type !== 'security-auditor') continue;
    const c = calls[id];
    audits.push({ id, desc: a.desc, cost: callCost[id] || 0, ctx: c ? c.ctx : 0, text: results[id] || '' });
  }
  const roleTotals = {};
  for (const b of Object.values(byModelRole)) {
    const r = roleTotals[b.role] || (roleTotals[b.role] = zero());
    for (const k of TYPES) r[k] += b.tok[k];
  }
  return { firstDesc, resultText: result && typeof result.result === 'string' ? result.result : '', total: total !== null ? total : Object.values(modelCosts).reduce((a, b) => a + b, 0), roleCost, audits, recon, flag, missingModels, detail, calls: Object.keys(agentTool).length, roleTotals, rawByModel: raw, hasMU: !!mu };
}

// ---------- audit classification ----------
// Keyword pass over the report text. Rough on purpose.
function classify(text) {
  if (!text || text.length < 40) return { cls: 'unclear', quote: (text || '').trim().slice(0, 160) };
  const t = text.replace(/\r/g, '');
  const bl = t.match(/#+\s*Bottom line\s*\n+([\s\S]{0,900})/i);
  const head = (bl ? bl[1] : t.slice(0, 1200));
  const headings = (t.match(/^(#{1,4} .*|\*\*[^*\n]{3,80}\*\*:?)\s*$/gm) || []).join('\n');
  const allSents = head.replace(/\n+/g, ' ').split(/(?<=[.!?])\s+/).map((x) => x.replace(/^[-*#\s]+/, '').replace(/^\*\*[^*]{0,60}\*\*\s*/, '')).filter((x) => x.length > 5);
  const sents = allSents.filter((x) => x.length > 25);
  const skip = /^(\*\*)?(I read|I had no|I ran|I couldn't write|I worked|The project has no|Read |Project rules|Scope|Coverage|The text below)/i;
  const vw = /found|nothing|no |finding|exploit|attack|vulnerab|bypass|escalat|medium|high|low|clean|verdict|holds|summary/i;
  const verdict = sents.find((x) => !skip.test(x) && vw.test(x)) || sents.find((x) => !skip.test(x)) || sents[0] || head;
  const quote = verdict.trim().slice(0, 200);
  const first = (allSents.slice(0, 3).join(' ') || head).toLowerCase();
  // drop negated severity phrases before looking for real ones
  const scrub = (head + '\n' + headings).toLowerCase()
    .replace(/no (high or medium|high|medium)[^.\n]*/g, ' ')
    .replace(/(medium|high)[- ]or[- ](worse|higher)|medium or worse|high[- ]severity|at medium or worse|high and medium/g, ' ');
  const sevReal = /\*\*\s*(medium|high|critical)\b|\b(medium|high|critical)\s*(\(|,|:|\.\*\*|\*\*|latent|live)|\b(medium|high)\b[^\n]{0,12}(finding|bypass|issue)|## .*\b(medium|high)\b|### .*\b(medium|high)\b/.test(scrub);
  const counted = /\b(one|two|three|four)\s+(\w+\s+){0,2}(low\s+)?(findings?|bypass|attack|issues?|defects?|bugs?|violations?|weakness(es)?|gaps?|escalations?|ways?)\b|\b(one|two|three)\s+low\b|low findings|low finding|paths where|is false|latent finding/.test(scrub)
    && !/\b(no|not|nothing)\s+(\w+\s+){0,2}(findings?|attack)\b[^.]*\./.test(first.slice(0, 40));
  const headingOnly = /^\*\*findings?\*\*|^#+ finding|\bfinding \((low|medium)/m.test(headings.toLowerCase());
  const neg = /\b(no|nothing|none|clean|holds?|don't|doesn't|can't|cannot|not exploitable|nothing is exploitable)\b/.test(first);
  const latent = /latent|observation|not exploitable|hardening|not security/.test((head + headings).toLowerCase());
  let c;
  if (sevReal) c = 'finding';
  else if (counted) c = 'finding';
  else if (headingOnly && !neg) c = 'finding';
  else if (latent) c = 'observations only';
  else if (neg) c = 'clean';
  else c = 'unclear';
  return { cls: c, quote };
}

// ---------- main ----------
const short = (t) => t.replace(/ (Committed|Parked|Audit done|Audit found|Every|Card \d+ committed|Merged).*$/, '').slice(0, 60).replace(/\|/g, '/');
const money = (x) => '$' + x.toFixed(2);
const pct = (x) => (x * 100).toFixed(1) + '%';
const median = (a) => { if (!a.length) return 0; const s = [...a].sort((x, y) => x - y); const n = s.length; return n % 2 ? s[(n - 1) / 2] : (s[n / 2 - 1] + s[n / 2]) / 2; };

const runs = parseLog();
const files = fs.readdirSync(DIR).filter((f) => f.endsWith('.jsonl')).sort();
const passes = [];
let rates = null;
for (const pass of [1, 2]) {
 if (pass === 2) {
  // price the passes that ended without a result event (still running or killed) at the average rate seen elsewhere
  const acc = {};
  for (const p of passes) { if (!p.hasMU) continue; for (const [m, r] of Object.entries(p.rawByModel)) { const a = acc[m] || (acc[m] = { out: 0, muOut: 0 }); a.out += r.output; } for (const d of p.detail) { const a = acc[d.model]; a.cost = (a.cost || 0) + d.cost; a.w = (a.w || 0) + d.weight; } }
  rates = {};
  for (const [m, a] of Object.entries(acc)) if (a.w) rates[m] = { rate: a.cost / a.w, os: 1 };
  // os: modelUsage output over stream output, from the passes that have both
  const o = {};
  for (const p of passes) if (p.hasMU) for (const d of p.detail) { const x = o[d.model] || (o[d.model] = { n: 0, d: 0 }); x.n += d.tok.output * d.outScale; x.d += d.tok.output; }
  for (const [m, x] of Object.entries(o)) if (rates[m] && x.d) rates[m].os = x.n / x.d;
  for (let i = 0; i < passes.length; i++) if (passes[i].flag === 'no-result' ) { const f = passes[i].file; const re = analyse(path.join(DIR, f), null, rates); Object.assign(passes[i], re); }
  break;
 }
for (const f of files) {
  const m = f.match(/^(.+Z)-(card-(\d+)|handoff)(?:-retry\d+)?\.jsonl$/);
  const stamp = m ? m[1] : f;
  const isHandoff = m && m[2] === 'handoff';
  const n = m && m[3];
  // The file stamp and the start line differ by a couple of milliseconds, so join on the nearest start within 5 seconds.
  const ft = Date.parse(stamp.replace(/^(\d{4}-\d\d-\d\dT\d\d)-(\d\d)-(\d\d)-(\d{3})Z$/, '$1:$2:$3.$4Z'));
  let run = runs[stamp];
  if (!run) {
    let best = null;
    for (const r of Object.values(runs)) { const d = Math.abs(r.t - ft); if (d < 5000 && (best === null || d < best.d)) best = { d, r }; }
    run = best ? best.r : undefined;
  }
  let title = isHandoff ? 'handoff' : '(no title)';
  let costEquiv = null;
  if (run && !isHandoff && run.cards[n]) { title = (run.cards[n].title || title); costEquiv = run.cards[n].costEquiv; }
  if (run && isHandoff) costEquiv = run.handoffCost;
  const a = analyse(path.join(DIR, f), costEquiv);
  if (title === '(no title)' || title === 'handoff') {
    const d = a.firstDesc || (a.resultText || '').replace(/s+/g, ' ').slice(0, 80);
    if (d) title = (title === 'handoff' ? 'handoff: ' : '') + d;
  }
  passes.push({ file: f, stamp, title, costEquiv, ...a });
  if (f === DETAIL) passes[passes.length - 1].isDetail = true;
}
}

// 1. totals by role
const grand = passes.reduce((s, p) => s + p.total, 0);
const roleAgg = {};
for (const p of passes) {
  for (const [r, c] of Object.entries(p.roleCost)) {
    const a = roleAgg[r] || (roleAgg[r] = { cost: 0, passes: 0 });
    a.cost += c; a.passes += 1;
  }
}
// sanity: per-pass sums
let maxDrift = 0;
for (const p of passes) { const s = Object.values(p.roleCost).reduce((a, b) => a + b, 0); maxDrift = Math.max(maxDrift, Math.abs(s - p.total)); }

// 2/3. audits
const audits = [];
for (const p of passes) for (const a of p.audits) audits.push({ ...a, title: p.title, file: p.file, ...classify(a.text) });
const auditCosts = audits.map((a) => a.cost);
const cardsWithAudit = {};
for (const a of audits) { const k = a.file; cardsWithAudit[k] = (cardsWithAudit[k] || 0) + a.cost; }
const cardAuditCosts = Object.values(cardsWithAudit);
const clsCount = {};
for (const a of audits) clsCount[a.cls] = (clsCount[a.cls] || 0) + 1;

// 5. reconciliation aggregate per model
const reconAgg = {};
let noMU = 0, noResult = 0, noResultLog = 0;
const missingModelPasses = [];
for (const p of passes) {
  if (p.flag === 'no-modelUsage') noMU++;
  if (p.flag === 'no-result-used-log-cost') noResultLog++;
  if (p.flag === 'no-result') noResult++;
  if (p.missingModels.length) missingModelPasses.push(p.file + ' ' + p.missingModels.join(','));
  for (const [model, r] of Object.entries(p.recon)) {
    const a = reconAgg[model] || (reconAgg[model] = { stream: { input: 0, write: 0, read: 0, output: 0 }, mu: { input: 0, write: 0, read: 0, output: 0, thinking: 0 }, passes: 0, worst: {} });
    for (const k of ['input', 'write', 'read', 'output']) {
      a.stream[k] += r.stream[k]; a.mu[k] += r.mu[k];
      if (r.mu[k] > 1000) {
        const d = Math.abs(r.stream[k] - r.mu[k]) / r.mu[k];
        if (!a.worst[k] || d > a.worst[k].d) a.worst[k] = { d, file: p.file };
      }
    }
    a.mu.thinking += r.mu.thinking; a.passes++;
  }
}

const L = [];
L.push('# Loop cost by role');
L.push('');
L.push(`Generated by C:\\Users\\roban\\.claude\\bin\\loop-cost.cjs over ${passes.length} stream files in ${DIR}.`);
L.push(`Total across all passes: ${money(grand)} (sum of modelUsage costUSD; ${passes.filter((p) => p.flag === 'no-result-estimated').length} passes with no result event are estimated, see section 5). Per-pass role costs sum to the pass total to within ${maxDrift.toExponential(1)} dollars.`);
L.push('');
L.push('Method: per pass and per model, weight each role by input ' + W.input + ', cache write ' + W.write1h + ' (1-hour) or ' + W.write5m + ' (5-minute), cache read ' + W.read + ', output ' + W.output + ', then split that model costUSD in proportion. Stream output_tokens are partial (about 2 percent of the real figure), so each model stream output is scaled up to modelUsage.outputTokens before weighing, spread by each role share of the stream output. A subagent role is the subagent_type of the nearest Agent call above its messages. Each message.id is counted once, taking the max seen per token type.');
L.push('');
L.push('## 1. Cost by role');
L.push('');
L.push('| Role | Cost | Share | Passes it appeared in |');
L.push('|---|---|---|---|');
for (const [r, a] of Object.entries(roleAgg).sort((x, y) => y[1].cost - x[1].cost)) L.push(`| ${r} | ${money(a.cost)} | ${pct(a.cost / grand)} | ${a.passes} of ${passes.length} |`);
L.push('');

L.push('## 2. Security-auditor');
L.push('');
L.push(`Audit calls: ${audits.length}. Total ${money(auditCosts.reduce((a, b) => a + b, 0))} (${pct(auditCosts.reduce((a, b) => a + b, 0) / grand)} of all spend). Median per call ${money(median(auditCosts))}, mean ${money(auditCosts.length ? auditCosts.reduce((a, b) => a + b, 0) / auditCosts.length : 0)}, max ${money(Math.max(0, ...auditCosts))}.`);
L.push(`Cards that had at least one audit: ${cardAuditCosts.length}. Median audit cost per such card ${money(median(cardAuditCosts))}, mean ${money(cardAuditCosts.length ? cardAuditCosts.reduce((a, b) => a + b, 0) / cardAuditCosts.length : 0)}.`);
const ctxs = audits.map((a) => a.ctx);
L.push(`Peak context per audit call (input + cache read + cache write, max over its messages): median ${Math.round(median(ctxs)).toLocaleString()}, max ${Math.max(0, ...ctxs).toLocaleString()}.`);
L.push('');
L.push('## 3. Did each audit find something');
L.push('');
L.push(`Classified by keywords on the "Bottom line" section of the report text (or the first 1500 characters if there is none): finding if it has severity headings or finding numbers and no "no findings" wording; observations only if it says nothing is exploitable but mentions latent, observation or hardening points; clean if it says no findings and nothing else; unclear if the report text is missing or has neither. Rough, a keyword pass, not a read of every report. Counts: ${Object.entries(clsCount).map(([k, v]) => `${k} ${v}`).join(', ')}.`);
L.push('');
L.push('| # | Card | Cost | Peak ctx | Class | Quote |');
L.push('|---|---|---|---|---|---|');
audits.forEach((a, i) => L.push(`| ${i + 1} | ${short(a.title)} (${a.file.slice(2, 16)} ${(a.file.match(/card-[0-9]+/) || [''])[0]}) | ${money(a.cost)} | ${a.ctx.toLocaleString()} | ${a.cls} | ${a.quote.replace(/\|/g, '/').replace(/\n/g, ' ')} |`));
L.push('');

L.push('## 4. Ten most expensive passes');
L.push('');
const top = [...passes].sort((a, b) => b.total - a.total).slice(0, 10);
L.push('| Pass | Card | Total | Split |');
L.push('|---|---|---|---|');
for (const p of top) L.push(`| ${p.file.replace('.jsonl', '')} | ${short(p.title)} | ${money(p.total)} | ${Object.entries(p.roleCost).sort((a, b) => b[1] - a[1]).map(([r, c]) => `${r} ${money(c)}`).join(', ')} |`);
L.push('');

L.push('## 5. Reconciliation: stream tokens against modelUsage');
L.push('');
L.push('Percent = (stream sum - modelUsage) / modelUsage, summed over every pass that has both. Negative means the stream shows less than modelUsage.');
L.push('');
L.push('| Model | Passes | Input | Cache write | Cache read | Output | Thinking tokens in modelUsage (not a stream field) |');
L.push('|---|---|---|---|---|---|---|');
const dp = (s, m) => (m ? ((s - m) / m * 100).toFixed(1) + '%' : (s ? 'n/a' : '0'));
for (const [model, a] of Object.entries(reconAgg)) L.push(`| ${model} | ${a.passes} | ${dp(a.stream.input, a.mu.input)} | ${dp(a.stream.write, a.mu.write)} | ${dp(a.stream.read, a.mu.read)} | ${dp(a.stream.output, a.mu.output)} | ${a.mu.thinking.toLocaleString()} |`);
L.push('');
L.push('Absolute totals (stream / modelUsage):');
L.push('');
for (const [model, a] of Object.entries(reconAgg)) L.push(`- ${model}: input ${a.stream.input.toLocaleString()} / ${a.mu.input.toLocaleString()}, write ${a.stream.write.toLocaleString()} / ${a.mu.write.toLocaleString()}, read ${a.stream.read.toLocaleString()} / ${a.mu.read.toLocaleString()}, output ${a.stream.output.toLocaleString()} / ${a.mu.output.toLocaleString()}`);
L.push('');
L.push('Worst single pass per token type (only where modelUsage has over 1,000 of that type):');
L.push('');
for (const [model, a] of Object.entries(reconAgg)) L.push(`- ${model}: ` + Object.entries(a.worst).map(([k, w]) => `${k} ${pct(w.d)} (${w.file.slice(0, 30)})`).join(', '));
L.push('');
L.push('Other checks:');
L.push('');
L.push(`- Passes with no modelUsage in the result (fell back to total_cost_usd split by stream weight): ${noMU}. Passes with no result event (still running or killed): ${passes.filter((p) => p.flag === 'no-result-estimated').map((p) => p.file.slice(0, 22) + ' ' + (p.file.match(/card-\d+/) || [''])[0] + ' ~' + money(p.total)).join(', ') || 'none'}, priced at the average dollars per weighted token from the other passes; ${noResultLog} more priced from the loop.log cost-equiv.`);
L.push(`- Passes where a stream model is missing from modelUsage: ${missingModelPasses.length}${missingModelPasses.length ? ' (' + missingModelPasses.slice(0, 5).join('; ') + ')' : ''}.`);
// cost-equiv comparison
let ceN = 0, ceSum = 0, ceTot = 0;
for (const p of passes) if (p.costEquiv != null) { ceN++; ceSum += p.costEquiv; ceTot += p.total; }
L.push(`- loop.log cost-equiv against result cost, over the ${ceN} passes that have both: log ${money(ceSum)}, result ${money(ceTot)}.`);
// cache TTL
let w5 = 0, w1 = 0;
for (const p of passes) for (const d of p.detail) { w5 += d.tok.write5m; w1 += d.tok.write1h; }
L.push(`- Cache writes in the stream: ${w1.toLocaleString()} tokens at the 1-hour TTL and ${w5.toLocaleString()} at the 5-minute TTL (${pct(w5 / ((w5 + w1) || 1))} 5-minute). The brief assumed all 1-hour.`);
L.push('');

// detail for one pass
const dpass = passes.find((p) => p.isDetail);
if (dpass) {
  L.push(`## Hand check: ${dpass.file}`);
  L.push('');
  L.push(`Card: ${dpass.title}. Pass total ${money(dpass.total)}.`);
  L.push('');
  L.push('| Model | Role | Input | Write 5m | Write 1h | Read | Output | Weight | Share | Cost |');
  L.push('|---|---|---|---|---|---|---|---|---|---|');
  for (const d of dpass.detail) L.push(`| ${d.model} | ${d.role} | ${d.tok.input} | ${d.tok.write5m} | ${d.tok.write1h} | ${d.tok.read} | ${d.tok.output} | ${Math.round(d.weight).toLocaleString()} | ${pct(d.share)} | ${money(d.cost)} |`);
  L.push('');
}

if (OUT) { fs.mkdirSync(path.dirname(OUT), { recursive: true }); fs.writeFileSync(OUT, L.join('\n') + '\n'); console.log('wrote ' + OUT); }
else console.log(L.join('\n'));
