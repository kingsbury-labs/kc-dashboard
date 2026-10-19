#!/usr/bin/env node
'use strict';
// claude-loop: run a project's board one card per fresh headless session.
//
// Each pass is a new `claude -p` with an empty context window, so no pass
// pays to re-read the one before it. State lives on disk (the board,
// checklist.json, the afk log) and the coordinate skill reads it from there.
// The loop waits out a usage limit instead of dying on it, stops on a STOP
// file, and runs /handoff once at the end.
//
//   node .claude/bin/claude-loop.cjs [--project DIR] [--max-cards N]
//        [--model opus] [--effort low|medium|high|xhigh|max]
//        [--afk-flags "--merge-allowed"] [--max-turns N]
//        [--pause-at 0.85] [--weekly-ceiling 0.8] [--max-gate-rounds 3]
//        [--fable-audits 0.25] [--no-handoff] [--claude CMD]
//
// --fable-audits lets audits (the security-auditor, a gate's diff audit) run
// on Fable, never workers or the planner, until Fable's share of the weekly
// window reaches that fraction. Without it a pass is told no Fable at all.
//
// A pass loads no MCP servers unless the project lists the ones it needs in
// .claude/loop-mcp.json (the usual {"mcpServers": {...}} shape). It sees only
// the built-in tools, skills and agents the loop uses (LOOP_TOOLS and the
// lists beside it), plus the project's own; .claude/loop-settings.json is
// merged over that, and loop_tools in project.md adds tools.
//
// The coordinator stops at the gate (status "gate"); this script runs the
// full suite, extra_checks and --verify itself, then resumes that session
// with the result, at most --max-gate-rounds (3) rounds per card. On green
// the session commits without pushing (status "committed"); this script
// checks the commit is the tree it tested, then resumes it to push.
//
// Nothing the session says is taken on its word. A card counts as committed
// only after that check, and as parked only when its branch is where it
// started (bookkeeping aside) with a clean tree. A nonzero exit, an error
// result, or anything else is a protocol failure: the loop stops and leaves
// git as it is. No card starts on a dirty tree.
//
// test_busy in project.md (a regex) names the test command's "someone else
// holds the test database" refusal; the gate waits that out, up to 30
// minutes, instead of counting it as a red suite.
//
// Before each card it reads the quota the last pass reported: at or over
// --pause-at of the 5-hour window it waits for the reset; at or over
// --weekly-ceiling of the week it stops, leaving the rest for daytime.
//
// Stop it cleanly: create .claude/work/loop/STOP in the project. The pass in
// flight finishes its card first.

const fs = require('fs');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const LIMIT = /usage limit|hit your (?:usage )?limit|limit reached|out of (?:extra )?usage/i;
const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
const SAFE_ARG = /^[\w.:=, -]*$/;

function parseArgs(argv) {
  const o = {
    // Opus at low effort measured best on 2026-10-04: $1.02 a card and every
    // gate step run, against $1.04 and a skipped scope check for Sonnet and
    // about $1.90 for Opus at its default effort.
    project: process.cwd(), maxCards: 10, model: 'opus', effort: 'low', afkFlags: '',
    maxTurns: 400, handoff: true, claude: 'claude', limitWaits: 6, maxGateRounds: 3,
    pauseAt: 0.85, weeklyCeiling: 0.8, fableAudits: 0,
  };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    const next = () => {
      if (i + 1 >= argv.length) throw new Error(`${a} needs a value`);
      return argv[++i];
    };
    if (a === '--project') o.project = path.resolve(next());
    else if (a === '--max-cards') o.maxCards = parseInt(next(), 10);
    else if (a === '--model') o.model = next();
    else if (a === '--effort') o.effort = next();
    else if (a === '--afk-flags') o.afkFlags = next();
    else if (a === '--max-turns') o.maxTurns = parseInt(next(), 10);
    else if (a === '--limit-waits') o.limitWaits = parseInt(next(), 10);
    else if (a === '--max-gate-rounds') o.maxGateRounds = parseInt(next(), 10);
    else if (a === '--pause-at') o.pauseAt = parseFloat(next());
    else if (a === '--weekly-ceiling') o.weeklyCeiling = parseFloat(next());
    else if (a === '--fable-audits') o.fableAudits = parseFloat(next());
    else if (a === '--no-handoff') o.handoff = false;
    else if (a === '--claude') o.claude = next();
    else throw new Error(`unknown argument: ${a}`);
  }
  if (o.effort && !['low', 'medium', 'high', 'xhigh', 'max'].includes(o.effort)) {
    throw new Error(`--effort is one of low, medium, high, xhigh, max, not ${o.effort}`);
  }
  for (const [k, v] of [['--model', o.model], ['--afk-flags', o.afkFlags]]) {
    if (!SAFE_ARG.test(v)) throw new Error(`${k} has a character the shell would act on: ${v}`);
  }
  if (!(o.maxCards > 0) || !(o.maxTurns > 0) || !(o.maxGateRounds > 0)) throw new Error('--max-cards, --max-turns and --max-gate-rounds must be positive');
  for (const [k, v] of [['--pause-at', o.pauseAt], ['--weekly-ceiling', o.weeklyCeiling]]) {
    if (!(v > 0 && v <= 1)) throw new Error(`${k} is a fraction of the window, above 0 and at most 1`);
  }
  if (!(o.fableAudits >= 0 && o.fableAudits <= 1)) throw new Error('--fable-audits is a fraction of the weekly window, from 0 to 1');
  return o;
}

// Fable's running share of the weekly window, kept in the loop folder so it
// carries across runs inside one week. A pass's rise in weekly use is charged
// to Fable in proportion to Fable's part of that pass's cost; the count starts
// over when the window's reset time moves.
function chargeFable(budget, before, after, result) {
  const week = windowOf(after, 'seven_day');
  if (!week) return budget;
  const b = budget && budget.resetsAt === week.resetsAt ? { ...budget } : { resetsAt: week.resetsAt, used: 0 };
  const prev = windowOf(before, 'seven_day');
  const rise = prev && prev.resetsAt === week.resetsAt ? Math.max(0, week.utilization - prev.utilization) : 0;
  const mu = (result && result.modelUsage) || {};
  let total = 0;
  let fable = 0;
  for (const [m, u] of Object.entries(mu)) {
    total += u.costUSD || 0;
    if (/fable/i.test(m)) fable += u.costUSD || 0;
  }
  if (total > 0) b.used += rise * (fable / total);
  return b;
}

function fableAllowed(o, budget) {
  return o.fableAudits > 0 && (!budget || budget.used < o.fableAudits);
}

// When does a usage limit lift? Returns a Date, or null when the text names
// no time. Understands the epoch form ("usage limit reached|1759600800") and
// the clock form ("resets 3:45pm", "resets Oct 7, 3pm"), read as local time.
function parseReset(text, now = new Date()) {
  const epoch = /limit reached\|(\d{10})\b/i.exec(text);
  if (epoch) return new Date(parseInt(epoch[1], 10) * 1000);
  const m = /resets?\s+(?:at\s+)?(?:([a-z]{3})[a-z]*\s+(\d{1,2}),?\s+(?:at\s+)?)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i.exec(text);
  if (!m) return null;
  let hour = parseInt(m[3], 10) % 12;
  if (m[5].toLowerCase() === 'pm') hour += 12;
  const d = new Date(now);
  d.setHours(hour, m[4] ? parseInt(m[4], 10) : 0, 0, 0);
  if (m[1] && MONTHS.includes(m[1].toLowerCase())) {
    d.setMonth(MONTHS.indexOf(m[1].toLowerCase()), parseInt(m[2], 10));
    if (d <= now) d.setFullYear(d.getFullYear() + 1);
  } else if (d <= now) {
    d.setDate(d.getDate() + 1);
  }
  return d;
}

// Reads a pass's stream-json output: the final result event, the largest
// context any one turn carried (what that pass paid to re-read), and the
// last rate_limit_event, which carries the 5-hour and weekly utilization
// (0 to 1) and their reset times in epoch seconds.
function summarise(streamText) {
  let result = null;
  let peak = 0;
  let limits = null;
  let sessionId = null;
  for (const line of streamText.split(/\r?\n/)) {
    if (!line.startsWith('{')) continue;
    let ev;
    try { ev = JSON.parse(line); } catch { continue; }
    if (typeof ev.session_id === 'string' && /^[\w-]{8,64}$/.test(ev.session_id)) sessionId = ev.session_id;
    if (ev.type === 'assistant' && ev.message && ev.message.usage) {
      const u = ev.message.usage;
      peak = Math.max(peak, (u.input_tokens || 0) + (u.cache_read_input_tokens || 0) + (u.cache_creation_input_tokens || 0));
    }
    if (ev.type === 'rate_limit_event' && ev.rate_limit_info) limits = ev.rate_limit_info;
    if (ev.type === 'result') result = ev;
  }
  return { result, peak, limits, sessionId };
}

// "opus:$2.42,sonnet:$0.31": list-price cost per model family, from the
// result event's modelUsage, so a log line shows which tier spent the pass.
function modelSplit(result) {
  const mu = (result && result.modelUsage) || {};
  const parts = Object.entries(mu).map(([m, u]) => {
    const fam = (/opus|sonnet|haiku|fable/i.exec(m) || [m])[0].toLowerCase();
    return `${fam}:$${(u.costUSD || 0).toFixed(2)}`;
  });
  return parts.length ? parts.join(',') : '?';
}

function windowOf(limits, name) {
  const w = limits && limits.unifiedWindows && limits.unifiedWindows[name];
  return w && typeof w.utilization === 'number' ? w : null;
}

// What the quota says to do before the next card: go, wait until a time,
// or stop. Pauses on the 5-hour window, stops on the weekly one, so an
// overnight run leaves the rest of the week for daytime work.
function quotaCall(limits, o) {
  const week = windowOf(limits, 'seven_day');
  if (week && week.utilization >= o.weeklyCeiling) return { act: 'stop', why: `weekly use at ${Math.round(week.utilization * 100)}%` };
  const five = windowOf(limits, 'five_hour');
  if (five && five.utilization >= o.pauseAt && five.resetsAt) {
    return { act: 'wait', until: five.resetsAt * 1000 + 2 * 60e3, why: `5-hour use at ${Math.round(five.utilization * 100)}%` };
  }
  return { act: 'go' };
}

function readStatus(file, allowed = ['gate', 'committed', 'continue', 'done', 'blocked']) {
  try {
    const s = JSON.parse(fs.readFileSync(file, 'utf8'));
    return allowed.includes(s.status) ? s : null;
  } catch { return null; }
}

// ---- What git says happened ----------------------------------------------
//
// The session's status is a claim. These read the outcome from git, so a
// card is only taken as committed or parked when the tree shows it.

function gitOut(project, args) {
  const r = spawnSync('git', args, { cwd: project, encoding: 'utf8', windowsHide: true, maxBuffer: 1 << 28 });
  return r.status === 0 ? r.stdout.trim() : null;
}

function headState(project) {
  return {
    sha: gitOut(project, ['rev-parse', '--verify', '-q', 'HEAD']),
    branch: gitOut(project, ['symbolic-ref', '--short', '-q', 'HEAD']) || '',
  };
}

const short = (sha) => String(sha || '?').slice(0, 9);

function listed(paths) {
  return paths.slice(0, 12).join(', ') + (paths.length > 12 ? ` and ${paths.length - 12} more` : '');
}

// Files a park may commit on the working branch: the board, .claude/work/
// (the brief, checklist.json) and the afk log. None of them is code, so
// none can turn the suite red for the next card.
function bookkeepingProblem(project, cfg, from, to) {
  if (gitOut(project, ['merge-base', '--is-ancestor', from, to]) === null) return `${short(to)} does not descend from ${short(from)}`;
  const names = gitOut(project, ['-c', 'core.quotepath=off', 'diff', '--name-only', '--relative', from, to]);
  if (names === null) return `git diff ${short(from)} ${short(to)} failed`;
  const ok = ['.claude/work/', cfg.board, cfg.afk_log || '.claude/work/log.md']
    .filter(Boolean).map((p) => p.split('\\').join('/').replace(/^\.\//, ''));
  const code = names.split('\n').filter(Boolean).filter((f) => !ok.some((p) => (p.endsWith('/') ? f.startsWith(p) : f === p)));
  return code.length ? `the commits change ${listed(code)}` : null;
}

function treeProblem(project, ptCfg) {
  const dirty = require('./project-test.cjs').dirtyPaths(project, ptCfg);
  if (dirty === null) return 'git status failed';
  return dirty.length ? `the tree is not clean: ${listed(dirty)}` : null;
}

// A parked card, a card that found nothing to do, or a pass with no status:
// the same branch, the same HEAD (or only bookkeeping on top), a clean tree.
function parkProblem(project, ptCfg, cfg, start) {
  const t = treeProblem(project, ptCfg);
  if (t) return t;
  const now = headState(project);
  if (now.branch !== start.branch) return `the card started on ${start.branch} and ended on ${now.branch || 'a detached HEAD'}`;
  if (now.sha === start.sha) return null;
  const b = bookkeepingProblem(project, cfg, start.sha, now.sha);
  return b ? `${start.branch} moved from ${short(start.sha)} to ${short(now.sha)} with no commit checked by claude-loop: ${b}` : null;
}

// The session says it committed. True only when this runner saw a green gate
// for the card, HEAD moved, the tree is clean, the committed tree is the tree
// the gate tested (board, checklist, test log and scope_ignore aside),
// R --pass marked that tree, and nothing has been pushed yet.
function commitProblem(project, ptCfg, start, gateTree) {
  if (!gateTree) return 'it wrote "committed" but claude-loop saw no green gate for this card';
  const t = treeProblem(project, ptCfg);
  if (t) return `after the commit ${t}`;
  const now = headState(project);
  if (!now.branch) return 'HEAD is detached after the commit';
  if (/^wip\//.test(now.branch)) return `the commit is on ${now.branch}, a parking branch`;
  if (now.sha === start.sha) return 'HEAD has not moved since the card started';
  const pt = require('./project-test.cjs');
  if (pt.treeFingerprint(project, ptCfg, 'HEAD') !== gateTree) return `the committed tree (${short(now.sha)}) is not the tree the gate tested`;
  let list = null;
  try { list = JSON.parse(fs.readFileSync(path.join(project, '.claude', 'work', 'checklist.json'), 'utf8')); } catch { /* none */ }
  if (!Array.isArray(list) || !list.some((e) => e && e.passes === true && e.tree === gateTree)) {
    return 'no checklist entry has passes true for the tested tree, so R --pass did not run on it';
  }
  const remote = gitOut(project, ['branch', '-r', '--contains', now.sha]);
  if (remote) return `${short(now.sha)} is already on ${remote.split('\n')[0].trim()}: it was pushed before claude-loop checked it`;
  return null;
}

// Commits that passed this runner's gate and commit check, newest last, in
// gated.json in the loop folder. It outlives the run, so a later run that
// adopts a red PR can tell a held card on its branch from ungated work.
const GATED_KEEP = 500;
function readGated(dir) {
  try {
    const a = JSON.parse(fs.readFileSync(path.join(dir, 'gated.json'), 'utf8'));
    return Array.isArray(a) ? a.filter((x) => typeof x === 'string') : [];
  } catch { return []; }
}

function recordGated(dir, sha) {
  const a = readGated(dir).filter((x) => x !== sha).concat(sha).slice(-GATED_KEEP);
  try { fs.writeFileSync(path.join(dir, 'gated.json'), JSON.stringify(a) + '\n'); return true; } catch { return false; }
}

// True when to descends from from and every commit between them is gated.
function allGated(project, dir, from, to) {
  if (gitOut(project, ['merge-base', '--is-ancestor', from, to]) === null) return false;
  const shas = gitOut(project, ['rev-list', `${from}..${to}`]);
  if (!shas) return false;
  const gated = new Set(readGated(dir));
  return shas.split('\n').every((c) => gated.has(c.trim()));
}

// After the push pass: still on the checked commit (bookkeeping aside), clean.
function afterPushProblem(project, ptCfg, cfg, committed) {
  const t = treeProblem(project, ptCfg);
  if (t) return `after the push ${t}`;
  const now = headState(project);
  if (now.branch === committed.branch && now.sha === committed.sha) return null;
  // A merge the afk flags allow (gh pr merge --delete-branch) leaves the
  // session on the default branch, pulled. That is fine when the checked
  // commit reached the remote and HEAD holds nothing the remote does not.
  if (gitOut(project, ['branch', '-r', '--contains', committed.sha]) && gitOut(project, ['rev-list', 'HEAD', '--not', '--remotes']) === '') return null;
  if (now.branch !== committed.branch) return `the commit was checked on ${committed.branch}, and the pass ended on ${now.branch || 'a detached HEAD'}`;
  const b = bookkeepingProblem(project, cfg, committed.sha, now.sha);
  return b ? `HEAD moved after claude-loop checked ${short(committed.sha)}: ${b}` : null;
}

// Exit code and result subtype of a pass. Anything but 0 and "success" means
// the session did not finish, whatever its status file says.
function passProblem(p) {
  if (p.r.code !== 0) return `claude exited ${p.r.code}`;
  if (!p.res || p.res.subtype !== 'success') return `the result was ${(p.res && p.res.subtype) || 'missing'}, not success`;
  if (p.res.is_error) return 'the result is marked as an error';
  return null;
}

// When the coordinator runs below the top tier, the judgement-heavy roles are
// pinned to Opus by name, so a cheaper coordinator never means a cheaper
// planner, audit or risky-path worker.
function tierLine(model) {
  if (/opus/i.test(model)) return '';
  return `\n- You are the coordinator on ${model}, to save usage. Set model "opus" on every planner Agent call, every security-auditor, and the worker for any card on a risky path in project.md. Other workers stay on Sonnet, sweeps on Haiku. If the gate's diff review leaves you unsure, start a security-auditor on Opus rather than passing it.`;
}

function cardPrompt(o, statusRel) {
  return `Coordinate from this project's own copy of the skill.

Read .claude/skills/coordinate/SKILL.md and follow it. This project keeps its own coordinate, brief and afk skills under .claude/skills/; wherever a rule names one of them, read that file instead of calling the Skill tool, which loads the shared user-level copy.

You are one pass of an unattended loop (claude-loop). Nobody is at the keyboard. Rules for this pass:

- Exactly one card. Pick it as the coordinate skill says, take it through the whole gate, commit it, then stop. Never start a second card.
- Behave as /afk with these flags: ${o.afkFlags || 'none'}. .claude/skills/afk/SKILL.md says what they allow. Never ask a question, never ScheduleWakeup or CronCreate.
${o.fableOk
    ? `- Fable is allowed for audits only: Rob gave it for this run, up to ${Math.round(o.fableAudits * 100)}% of the weekly limit, which claude-loop tracks. Set model "fable" on every security-auditor and audit Agent call. Workers and the planner never run on Fable; Opus is their top tier.`
    : "- No Fable, for any worker or audit: Fable needs Rob's permission each time and he is not here to give it. Opus is the top tier for this pass."}${tierLine(o.model)}
- Start workers and the security-auditor with run_in_background false, so this pass waits for them. Never end your turn while anything you started is still running.
- Never use SendMessage in this pass. Ending your turn ends this session and kills any agent still working, so a card that goes back for another round gets a fresh foreground worker (run_in_background false) whose prompt is the brief's path plus the failing command's output. This overrides the coordinate skill's "back to the same worker".
- A parked card's unfinished work never goes on the branch other cards build on. Commit it to its own branch, wip/<card id>-<short name>, cut from where the card started, push that branch, return to the working branch, and leave the working tree clean. Ungated work on the shared branch turns the suite red for every card after it. On the working branch a park may commit only the board, files under .claude/work/ and the afk log, nothing else.
- State lives on disk, not in this conversation: the board, .claude/work/checklist.json, the afk log. claude-loop refuses to start a pass on a dirty tree, so the tree you start on is clean; leave it clean.
- Gate steps 4 and 5 (the full suite, extra_checks, R --verify) are run by claude-loop in code, not by you. Never run them yourself. When a card has passed gate steps 1 to 3 (scope check, diff read, security-auditor where the skill calls for one), write ${statusRel} as {"status": "gate", "card": "<id and title>", "note": "<one plain line>"} and end your turn without touching the tree again. claude-loop then runs steps 4 and 5 and resumes this same session with the result: on a pass you commit without pushing and stop, claude-loop checks the commit is the tree it tested, then resumes you once more to push and open the PR. On a failure the card goes another round. This overrides the skill's steps 4 to 6 for this pass only.
- claude-loop checks every outcome in git, not in your status. It takes continue, done or blocked only when the working branch is where the card started (apart from a commit of only the board, .claude/work/ and the afk log) with a clean tree, or after a commit it checked itself. Anything else, a pass that ends on an error, or a commit pushed before its check stops the whole loop for Rob.
- The last thing you do: write ${statusRel} as JSON, {"status": "gate" | "continue" | "done" | "blocked", "card": "<id and title>", "note": "<one plain line>"} ("committed" only when the gate result tells you to). continue means the card was committed or parked and more remain. done means no unparked card is left. blocked means something stops every remaining card (a broken suite, a refused permission, anything that needs Rob).`;
}

function handoffPrompt(logRel) {
  return `/handoff

This is the closing pass of an unattended loop run (claude-loop). The work happened in earlier passes, each its own session. Read ${logRel}, the board, .claude/work/checklist.json and the afk log for what was done and what was parked. Do not start any card.`;
}

// The MCP servers a pass may load: the project's .claude/loop-mcp.json if it
// has one, otherwise none. Headless runs ignore the per-project /mcp
// switches and load every server (measured 2026-10-04: 85.6k tokens at
// start with them, 68.8k with --strict-mcp-config and none).
function mcpConfig(project, dir) {
  const own = path.join(project, '.claude', 'loop-mcp.json');
  if (fs.existsSync(own)) return own;
  const empty = path.join(dir, 'mcp-none.json');
  fs.writeFileSync(empty, '{"mcpServers":{}}\n');
  return empty;
}

// Instruction files a pass skips: the project's .claude/loop-settings.json if
// it has one, otherwise the persona's voice files, which shape how a reply to
// Rob reads and do nothing for a pass nobody reads live. corrections.md stays:
// it carries rules a commit must follow. Measured 2026-10-04: 68.7k tokens at
// start with them, 62.4k without, paid again by every agent in the pass.
const LOOP_MD_EXCLUDES = ['**/personas/cas/soul.md', '**/personas/cas/voice-samples.md'];

// What a pass keeps of Rob's user-level setup. Everything else he owns is
// listed in every turn of every pass and none of it is used: measured
// 2026-10-10 from thecupboard with a one-word prompt, 58.1k tokens at start
// with all of it, 27.3k with only these. The project's own skills and agents
// are never touched.
const LOOP_SKILLS = ['coordinate', 'brief', 'handoff', 'afk'];
const LOOP_AGENTS = ['implementer', 'sweeper', 'planner', 'security-auditor'];
const BUILTIN_AGENTS = ['claude', 'general-purpose', 'Explore', 'Plan', 'claude-code-guide', 'statusline-setup'];
// Built-in tools, for the coordinator and, by inheritance, every agent it
// starts. Taken from 116 saved passes: nothing outside this set was called by
// a coordinator or a worker apart from SendMessage, which the card prompt
// forbids. A project adds more with loop_tools in project.md.
const LOOP_TOOLS = ['Bash', 'PowerShell', 'Read', 'Edit', 'Write', 'Glob', 'Grep', 'Agent', 'Skill', 'Monitor', 'TaskStop', 'ToolSearch'];

function claudeHome() {
  return process.env.CLAUDE_CONFIG_DIR || path.join(require('os').homedir(), '.claude');
}

function names(dir, kind) {
  try {
    return fs.readdirSync(dir, { withFileTypes: true })
      .filter((d) => (kind === 'dir' ? d.isDirectory() && !d.name.startsWith('.') && d.name !== 'synced' : d.isFile() && d.name.endsWith('.md')))
      .map((d) => d.name.replace(/\.md$/, ''));
  } catch { return []; }
}

// The trimmed defaults: every user-level skill and command off except
// LOOP_SKILLS, bundled skills off, skill descriptions cut to nothing (the
// pass names its skills, it never matches on a description), the user's
// plugins off (their hooks are banners and tips, no gate), and every agent
// but LOOP_AGENTS denied, so the Agent tool stops describing them.
function trimmedSettings(project, home) {
  const projSkills = new Set(names(path.join(project, '.claude', 'skills'), 'dir').concat(names(path.join(project, '.claude', 'commands'), 'md')));
  const projAgents = new Set(names(path.join(project, '.claude', 'agents'), 'md'));
  const skillOverrides = { doctor: 'off' };
  for (const s of names(path.join(home, 'skills'), 'dir').concat(names(path.join(home, 'commands'), 'md'))) {
    if (!LOOP_SKILLS.includes(s) && !projSkills.has(s)) skillOverrides[s] = 'off';
  }
  const deny = BUILTIN_AGENTS.concat(names(path.join(home, 'agents'), 'md'))
    .filter((a) => !LOOP_AGENTS.includes(a) && !projAgents.has(a))
    .map((a) => `Agent(${a})`);
  let user = {};
  try { user = JSON.parse(fs.readFileSync(path.join(home, 'settings.json'), 'utf8')); } catch { /* none */ }
  const enabledPlugins = {};
  for (const p of Object.keys(user.enabledPlugins || {})) enabledPlugins[p] = false;
  return {
    claudeMdExcludes: LOOP_MD_EXCLUDES,
    disableBundledSkills: true,
    skillListingMaxDescChars: 1,
    skillOverrides,
    enabledPlugins,
    permissions: { deny },
  };
}

// The project's file wins key by key; permission lists and the two maps are
// merged, so a project's allow rules sit beside the loop's deny rules.
function mergeSettings(base, own) {
  const out = { ...base, ...own };
  for (const k of ['skillOverrides', 'enabledPlugins']) out[k] = { ...(base[k] || {}), ...(own[k] || {}) };
  const perms = { ...(base.permissions || {}) };
  for (const [k, v] of Object.entries(own.permissions || {})) {
    perms[k] = Array.isArray(v) && Array.isArray(perms[k]) ? [...new Set(perms[k].concat(v))] : v;
  }
  out.permissions = perms;
  return out;
}

function loopSettings(project, dir, home = claudeHome()) {
  let own = {};
  const ownFile = path.join(project, '.claude', 'loop-settings.json');
  if (fs.existsSync(ownFile)) own = JSON.parse(fs.readFileSync(ownFile, 'utf8'));
  const file = path.join(dir, 'settings.json');
  fs.writeFileSync(file, JSON.stringify(mergeSettings(trimmedSettings(project, home), own), null, 2) + '\n');
  return file;
}

function projectConfig(project) {
  const { parseFrontmatter } = require('./project-test.cjs');
  return parseFrontmatter(fs.readFileSync(path.join(project, '.claude', 'project.md'), 'utf8').replace(/^﻿/, ''));
}

function loopTools(cfg) {
  const extra = String((cfg && cfg.loop_tools) || '').split(/[\s,]+/).filter(Boolean);
  for (const t of extra) if (!/^\w+$/.test(t)) throw new Error(`loop_tools in project.md has a name that is not a tool: ${t}`);
  return [...new Set(LOOP_TOOLS.concat(extra))].join(',');
}

// Gate steps 4 and 5 of the coordinate skill, run here at no token cost
// instead of by the coordinator: the full suite through the runner, then
// extra_checks from project.md, then the runner's --verify. Stops at the
// first failure. The coordinator still runs --pass itself before it commits,
// which verifies again.
//
// test_busy in project.md is a regex for "another run holds the test
// database" (SparrowBook's: Another test run is using the test database).
// The runner's own lock refusal (RUNNER_BUSY) counts the same in every
// project, with or without test_busy. A
// failing step whose output matches either is waited out and run again, every
// busyWaitMs up to busyMaxMs in all, instead of being a red suite; still
// busy after that comes back as { ok: false, busy: true }.
const RUNNER_BUSY = require('./project-test.cjs').BUSY;
const BUSY_WAIT_MS = 60e3;
const BUSY_MAX_MS = 30 * 60e3;

function sleepSync(ms) { Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms); }

function runGate(project, cfg, runner = path.join(__dirname, 'project-test.cjs'), opts = {}) {
  const timeout = (parseInt(cfg.test_timeout_s, 10) || 1800) * 1000 + 60e3;
  const busy = cfg.test_busy ? new RegExp(cfg.test_busy) : null;
  const waitMs = opts.busyWaitMs ?? BUSY_WAIT_MS;
  // At least as long as the runner's stale line (two timeouts and five
  // minutes), so a lock left by a pid Windows reused can be outwaited.
  const maxMs = opts.busyMaxMs ?? Math.max(BUSY_MAX_MS, ((parseInt(cfg.test_timeout_s, 10) || 1800) * 2 + 360) * 1000);
  const t0 = Date.now();
  let busyWaits = 0;
  const steps = [
    { name: 'full suite', label: 'node project-test.cjs', cmd: process.execPath, args: [runner] },
  ];
  if (cfg.extra_checks) steps.push({ name: 'extra_checks', label: cfg.extra_checks, cmd: cfg.extra_checks, args: [], shell: true });
  steps.push({ name: 'verify', label: 'node project-test.cjs --verify', cmd: process.execPath, args: [runner, '--verify'] });
  for (const s of steps) {
    for (;;) {
      const r = spawnSync(s.cmd, s.args, { cwd: project, shell: !!s.shell, encoding: 'utf8', timeout, windowsHide: true, maxBuffer: 1 << 28 });
      const code = r.status === null ? 124 : r.status;
      if (code === 0) break;
      const all = `${r.stdout || ''}${r.stderr || ''}${r.error ? r.error.message + '\n' : ''}`;
      const tail = all.split(/\r?\n/).slice(-80).join('\n').slice(-6000);
      const fail = { ok: false, step: s.name, command: s.label, code, output: tail };
      const plain = all.replace(/\u001b\[[0-9;?]*[ -/]*[@-~]/g, '');
      const runnerBusy = code === 75 && s.name !== 'extra_checks' && plain.includes(RUNNER_BUSY);
      if (!runnerBusy && !(busy && busy.test(plain))) return fail;
      if (Date.now() - t0 + waitMs > maxMs) return { ...fail, busy: true, busyWaits };
      busyWaits++;
      if (opts.onBusy) opts.onBusy(s.name, busyWaits);
      sleepSync(waitMs);
    }
  }
  return busyWaits ? { ok: true, steps: steps.map((s) => s.name), busyWaits } : { ok: true, steps: steps.map((s) => s.name) };
}

const GATE_PASSED = 'Gate result from claude-loop: passed.';
const GATE_FAILED = 'Gate result from claude-loop: failed.';

function gatePrompt(g, cfg, statusRel, round, maxRounds) {
  if (g.ok) {
    return `${GATE_PASSED}

After you stopped, claude-loop ran gate steps 4 and 5 on the tree exactly as you left it: the full suite through R exited 0${cfg.extra_checks ? `, extra_checks (${cfg.extra_checks}) exited 0` : ''}, and R --verify exited 0. Do not run them again. Carry on from step 6 (Commit) for this card, in this order: branch if the card needs one; move the card on the board; R --pass <id> (it verifies again, and the board is left out of what it compares); stage every file the gate tested together with the board move and checklist.json, leaving out only the scope_ignore files in project.md; commit. Do not change any other file after the gate, do not push and do not open the PR yet. Then write ${statusRel} as {"status": "committed", "card": "<id and title>", "note": "<one plain line>"} and stop. claude-loop checks that the commit is exactly the tree it tested and the tree is clean, then resumes you to push and open the PR. If R --pass refuses, do not commit: park the card as the skill says and write continue, done or blocked.`;
  }
  const last = round >= maxRounds;
  return `${GATE_FAILED}

After you stopped, claude-loop ran gate steps 4 and 5 on the tree as you left it. ${g.step} failed: \`${g.command}\` exited ${g.code}. Its output, the last lines:

\`\`\`
${g.output}
\`\`\`

${last
    ? `This was round ${round} of ${maxRounds} for this card at the gate. Park it as the coordinate skill says, log why, and write ${statusRel} (continue, done or blocked).`
    : `The card goes back for another round as the pass rules say: a fresh foreground worker whose prompt is the brief's path plus this output (step up a tier if this is its second failure). Gate the new work again from step 1, and when it passes steps 1 to 3, write status "gate" again and stop.`}`;
}

const COMMIT_CHECKED = 'Commit check from claude-loop: passed.';

// ci: required hands CI to this script: the pass pushes and opens the PR and
// never waits on checks, so the next card is built while CI runs. Before each
// push the queue is cut to ci_max - 1 PRs still in CI (default 2 in CI at
// once; a project whose CI runners contend sets ci_max: 1 and still gets a
// card built while the last one is checked). PRs merge strictly in the order
// they were pushed: each card branches from the one before, so a later PR
// carries the earlier commits and must never land first.
function pushPrompt(c, statusRel, ciByLoop) {
  const tail = ciByLoop
    ? `then open the PR against the default branch (or note the open one this branch already has). Do not wait for its checks and do not merge it: claude-loop watches CI and merges PRs in order. The next card builds on ${c.branch}. Stay on ${c.branch} at ${short(c.sha)}: no new commit, no checkout, no pull.`
    : `then open the PR (or note the open one this branch already has), and merge only if the afk flags allow it. Stay on ${c.branch} at ${short(c.sha)}: no new commit, no checkout, no pull, apart from what an allowed merge does by itself.`;
  return `${COMMIT_CHECKED}

claude-loop checked ${short(c.sha)} on ${c.branch}: it is exactly the tree the gate tested, R --pass marked it, and the working tree is clean. Now finish step 6: push ${c.branch} in its own call, ${tail} Then write ${statusRel} as the pass rules say (continue, done or blocked) and stop.`;
}

// CLAUDE_LOOP_CI_POLL_MS shortens the poll and the green gap under test.
const CI_POLL_MS = parseInt(process.env.CLAUDE_LOOP_CI_POLL_MS, 10) || 60e3;
const CI_MAX_MS = 3 * 3600e3;
const CI_NO_CHECKS_MS = 15 * 60e3;

// gh, or under test a node script named by CLAUDE_LOOP_GH standing in for it.
function gh(project, args, timeout = 60e3) {
  const fake = process.env.CLAUDE_LOOP_GH;
  return spawnSync(fake ? process.execPath : 'gh', fake ? [fake, ...args] : args,
    { cwd: project, encoding: 'utf8', windowsHide: true, timeout, maxBuffer: 1 << 24 });
}

function ghJson(project, args) {
  const r = gh(project, args);
  try { return { ok: true, data: JSON.parse(r.stdout) }; } catch { return { ok: false, err: `${r.stderr || r.error || ''}`.trim().split('\n')[0] }; }
}

// Where one pushed card's PR stands: pending, pass, merged or fail (with why),
// or error when gh could not say. Reads the PR by branch, and refuses a PR
// whose head is not the commit the gate tested.
// q.base is the default branch when the loop knows it. A pass is only
// returned on the second green reading at least CI_GREEN_GAP_MS after the
// first (q.greenAt), so a slower workflow that registers its checks late is
// not merged past.
const CI_GREEN_GAP_MS = CI_POLL_MS;

function ciState(project, q, now = Date.now(), read = ghJson) {
  const pr = read(project, ['pr', 'view', q.branch, '--json', 'number,state,headRefOid,baseRefName']);
  if (!pr.ok) return { state: 'error', why: `gh pr view ${q.branch}: ${pr.err || 'no output'}` };
  const { number, state, headRefOid, baseRefName } = pr.data;
  // gh pr view <branch> falls back to the last closed or merged PR on that
  // name, so a PR is this card's only when its head is the tested commit.
  if (headRefOid !== q.sha) return { state: 'fail', number, why: `PR #${number}'s head is ${short(headRefOid)}, not the tested ${short(q.sha)}: no PR carries this card` };
  if (q.base && baseRefName && baseRefName !== q.base) return { state: 'fail', number, why: `PR #${number} is based on ${baseRefName}, not ${q.base}` };
  if (state === 'MERGED') return { state: 'merged', number };
  if (state !== 'OPEN') return { state: 'fail', number, why: `PR #${number} is ${state.toLowerCase()}` };
  const ch = read(project, ['pr', 'checks', String(number), '--json', 'name,bucket,link']);
  const checks = ch.ok && Array.isArray(ch.data) ? ch.data : [];
  if (!checks.length) {
    return now - q.since > CI_NO_CHECKS_MS
      ? { state: 'fail', number, why: `PR #${number} has no checks after ${CI_NO_CHECKS_MS / 60e3} min` }
      : { state: 'pending', number };
  }
  const red = checks.filter((c) => c.bucket === 'fail' || c.bucket === 'cancel');
  // failed carries the red checks themselves, which is what makes a failure
  // repairable (see ciRepairPrompt); settled says no check is still running,
  // so a repair sees every failure at once rather than one per round.
  if (red.length) {
    return {
      state: 'fail', number, why: `PR #${number}: ${listed(red.map((c) => `${c.name} (${c.bucket})`))}`,
      failed: red.map((c) => ({ name: c.name, link: c.link || '' })),
      settled: !checks.some((c) => c.bucket === 'pending'),
    };
  }
  if (checks.some((c) => c.bucket === 'pending')) { q.greenAt = null; return { state: 'pending', number }; }
  if (!q.greenAt || (q.greenChecks || 0) < checks.length) { q.greenAt = now; q.greenChecks = checks.length; return { state: 'pending', number }; }
  if (now - q.greenAt < CI_GREEN_GAP_MS) return { state: 'pending', number };
  return { state: 'pass', number };
}

// A red PR used to stop the loop for good, so one failed grep check on card
// N left every later card waiting for a person (Sembr, 2026-10-12 and
// 2026-10-14). A repair is a fresh pass told exactly which checks failed; it
// fixes the PR's own branch, pushes, and returns to where the loop was, and
// claude-loop checks all three in git before it watches CI again. CI is the
// gate for the fix, so the repair runs no full suite of its own. A failure
// with no code cause (a CI service refusing connections, a lost runner) is
// re-run instead: the pass runs gh run rerun --failed, pushes nothing and
// writes rerun, and claude-loop checks that the head did not move (Sembr,
// 2026-10-16, where a correct re-run of a flaked job stopped the loop).
const CI_REPAIRS = 2;
function ciRepairPrompt(q, s, here, statusRel) {
  const checks = s.failed.map((c) => `- ${c.name}${c.link ? `: ${c.link}` : ''}`).join('\n');
  const back = here.branch === q.branch
    ? `5. Stay on ${q.branch}, at the commit you pushed (at ${short(q.sha)} if you pushed nothing), with a clean working tree.`
    : `5. Check out ${here.branch} again. It must be at ${short(here.sha)} with a clean working tree: do not merge, commit or pull anything on it.`;
  return `You are one pass of an unattended loop (claude-loop). Nobody is at the keyboard. Never ask a question, never ScheduleWakeup or CronCreate, never use SendMessage, and start nothing in the background.

PR #${s.number} (branch ${q.branch}, head ${short(q.sha)}) failed these CI checks:
${checks}

Your job is to make them pass, and nothing else.
1. Read why each one failed. A job's log is \`gh api repos/{owner}/{repo}/actions/jobs/<job id>/logs\` (the job id is the last number in its link), which works while the run is still going, where \`gh run view --log-failed\` does not. Read only the failing step's lines.
2. Check out ${q.branch}. Make the smallest change that fixes the cause. Never weaken, skip or delete a check, a test or an assertion to get green, and never add a baseline, ignore or suppression entry. If you are sure the check itself is wrong, change nothing and write blocked with the reason.
3. Run the tests that cover what you changed, filtered, through the project's test command, and the failing check itself if it runs locally. Format as the project says.
4. Commit on ${q.branch} as the project's commit rules say, then push ${q.branch} in its own call. No force push, no rebase, no amend.
${back}
If the log shows no code cause at all, because the runner or a CI service failed (a service container refusing connections, a network timeout, a lost runner) and not the code or a test, skip steps 2 to 4: change, commit and push nothing, run \`gh run rerun <run id> --failed\` (the run id is the number after /runs/ in the check's link) for each failed run, then do step 5. Write rerun only after that command succeeded; a test that fails on its own is a code cause, not a flake.
Then write ${statusRel} as {"status": "fixed" | "rerun" | "blocked", "card": "PR #${s.number}", "note": "<one plain line: the cause and the fix>"} and stop. claude-loop checks the push, or for rerun that nothing was pushed, and watches CI again.`;
}

// Merges PR number with a merge commit, leaving the local checkout alone
// (gh pr merge --delete-branch would switch branches under the next card),
// then deletes the remote branch unless an open PR is based on it (deleting
// a base closes the PRs on it). True when GitHub says it merged.
function mergePr(project, number, branch, sha) {
  // --match-head-commit: GitHub refuses the merge if the head moved after
  // the checks were read, so nothing untested lands.
  const r = gh(project, ['pr', 'merge', String(number), '--merge', '--match-head-commit', sha], 120e3);
  const after = ghJson(project, ['pr', 'view', String(number), '--json', 'state']);
  if (!(after.ok && after.data.state === 'MERGED')) return { ok: false, why: `${r.stderr || r.stdout || ''}`.trim().split('\n')[0] || 'not merged' };
  const based = ghJson(project, ['pr', 'list', '--base', branch, '--state', 'open', '--json', 'number']);
  if (based.ok && Array.isArray(based.data) && !based.data.length) {
    spawnSync('git', ['push', 'origin', '--delete', branch], { cwd: project, windowsHide: true, timeout: 60e3 });
  }
  return { ok: true };
}

// Every process on the box as pid -> { ppid, created }, or null when the
// query fails. Windows only; elsewhere a pass's children die with its group.
function processTable() {
  if (process.platform !== 'win32') return null;
  const r = spawnSync('powershell', ['-NoProfile', '-NonInteractive', '-Command',
    'Get-CimInstance Win32_Process | % { $c = 0; if ($_.CreationDate) { $c = $_.CreationDate.ToFileTimeUtc() }; "$($_.ProcessId) $($_.ParentProcessId) $c" }'],
  { encoding: 'utf8', windowsHide: true, timeout: 30e3, maxBuffer: 1 << 24 });
  if (r.status !== 0) return null;
  const t = new Map();
  for (const line of r.stdout.split(/\r?\n/)) {
    const [pid, ppid, created] = line.trim().split(' ');
    if (pid) t.set(pid, { ppid, created });
  }
  return t;
}

// Adds every live descendant of root, or of a pid already in seen, to seen
// (pid -> creation time). Walking from seen as well as root keeps a
// grandchild whose own parent has already exited, which is how a pass's
// background worker outlived the pass on 2026-10-10 (115i).
function collectTree(root, seen) {
  const t = processTable();
  if (!t) return;
  // The loop itself and everything above it are never a target.
  const never = new Set();
  for (let pid = String(process.pid); pid && t.has(pid) && !never.has(pid); pid = t.get(pid).ppid) never.add(pid);
  never.add(String(process.pid));
  const rootAt = t.get(String(root));
  let grew = true;
  while (grew) {
    grew = false;
    for (const [pid, p] of t) {
      if (seen.has(pid) || never.has(pid)) continue;
      const parent = p.ppid === String(root) ? rootAt : (seen.has(p.ppid) ? { created: seen.get(p.ppid) } : null);
      if (!parent && p.ppid !== String(root)) continue;
      // A child is never older than its parent: an older one only carries a
      // stale parent id that a later process reused.
      if (parent && parent.created !== '0' && p.created !== '0' && BigInt(p.created) < BigInt(parent.created)) continue;
      seen.set(pid, p.created); grew = true;
    }
  }
}

// Kills whatever in seen is still alive with the same creation time, so a
// reused pid is never touched. Returns how many it killed.
function killSurvivors(seen) {
  const t = processTable();
  if (!t) return 0;
  let n = 0;
  for (const [pid, created] of seen) {
    const p = t.get(pid);
    if (!p || created === '0' || p.created !== created) continue;
    spawnSync('taskkill', ['/F', '/T', '/PID', pid], { windowsHide: true });
    n++;
  }
  return n;
}

function runPass(o, prompt, streamFile, resume) {
  return new Promise((resolve) => {
    for (const p of [o.mcp, o.settings]) {
      if (/["%^&|<>]/.test(p)) throw new Error(`config path has a character the shell would act on: ${p}`);
    }
    if (resume && !/^[\w-]{8,64}$/.test(resume)) throw new Error(`session id has a character the shell would act on: ${resume}`);
    const args = ['-p', '--output-format', 'stream-json', '--verbose',
      '--permission-mode', 'auto', '--model', o.model, '--max-turns', String(o.maxTurns),
      '--strict-mcp-config', '--mcp-config', `"${o.mcp}"`, '--settings', `"${o.settings}"`]
      .concat(o.tools ? ['--tools', o.tools] : [])
      .concat(o.effort ? ['--effort', o.effort] : [])
      .concat(resume ? ['--resume', resume] : []);
    // claude is an npm .cmd shim on Windows, which Node only starts through
    // the shell. Every argument is fixed or checked by SAFE_ARG; the prompt
    // goes in on stdin, never through the shell.
    // CLAUDE_LOOP_PASS marks a headless pass so coordinator-only.cjs lets it write code and commit.
    const child = spawn(o.claude, args, { cwd: o.project, shell: true, windowsHide: true, env: { ...process.env, CLAUDE_LOOP_PASS: '1' } });
    const out = fs.createWriteStream(streamFile);
    let text = '';
    let err = '';
    child.stdout.on('data', (b) => { out.write(b); text += b; });
    child.stderr.on('data', (b) => { err += b; });
    // A pass is over only when everything it started is gone: a background
    // agent left running edits the tree under the next card.
    const seen = new Map();
    const watch = setInterval(() => collectTree(child.pid, seen), o.treeEveryMs || 15e3);
    child.on('close', (code) => {
      clearInterval(watch);
      collectTree(child.pid, seen);
      const killed = killSurvivors(seen);
      out.end();
      resolve({ code, text, err, killed });
    });
    child.stdin.end(prompt);
  });
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function main() {
  const o = parseArgs(process.argv.slice(2));
  if (!fs.existsSync(path.join(o.project, '.claude', 'project.md'))) {
    console.error(`${o.project} has no .claude/project.md, so it has not joined the coordinate loop.`);
    process.exit(2);
  }
  const dir = path.join(o.project, '.claude', 'work', 'loop');
  fs.mkdirSync(dir, { recursive: true });
  // The loop's own files never belong in a commit, and an untracked folder
  // would fail a pass's clean-tree check.
  fs.writeFileSync(path.join(dir, '.gitignore'), '*\n');
  const stopFile = path.join(dir, 'STOP');
  const statusFile = path.join(dir, 'status.json');
  const logFile = path.join(dir, 'loop.log');
  const lockFile = path.join(dir, 'lock');
  const statusRel = '.claude/work/loop/status.json';
  o.mcp = mcpConfig(o.project, dir);
  o.settings = loopSettings(o.project, dir);
  const cfg = projectConfig(o.project);
  o.tools = loopTools(cfg);
  const ptCfg = require('./project-test.cjs').loadConfig(o.project);
  if (!ptCfg) { console.error(`${o.project}/.claude/project.md has no test_command, so it has not joined the coordinate loop.`); process.exit(2); }
  if (gitOut(o.project, ['rev-parse', '--verify', '-q', 'HEAD']) === null) {
    console.error(`${o.project} is not a git repository with a commit. claude-loop reads every card's outcome from git, so it will not run here.`);
    process.exit(2);
  }
  if (cfg.test_busy) {
    try { new RegExp(cfg.test_busy); } catch (e) { console.error(`test_busy in project.md is not a valid regex: ${e.message}`); process.exit(2); }
  }

  try {
    fs.writeFileSync(lockFile, String(process.pid), { flag: 'wx' });
  } catch {
    const pid = parseInt(fs.readFileSync(lockFile, 'utf8'), 10);
    let alive = false;
    try { process.kill(pid, 0); alive = true; } catch { /* gone */ }
    if (alive) { console.error(`another loop (pid ${pid}) is running on this project`); process.exit(2); }
    fs.writeFileSync(lockFile, String(process.pid));
  }
  const unlock = () => { try { fs.unlinkSync(lockFile); } catch { /* already gone */ } };
  process.on('exit', unlock);
  process.on('SIGINT', () => process.exit(130));

  const log = (msg) => {
    const line = `${new Date().toISOString()} ${msg}`;
    fs.appendFileSync(logFile, line + '\n');
    console.log(line);
  };
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  log(`start: project=${o.project} model=${o.model} effort=${o.effort || 'default'} max-cards=${o.maxCards} afk-flags=${o.afkFlags || 'none'} fable-audits=${o.fableAudits || 'none'} mcp=${path.basename(o.mcp)}`);

  // Sleeps until a time, checking for STOP each minute. False if stopped.
  async function waitUntil(until) {
    while (Date.now() < until) {
      if (fs.existsSync(stopFile)) { log('STOP file found while waiting'); return false; }
      await sleep(Math.min(60e3, until - Date.now()));
    }
    return true;
  }

  let limits = null;
  const fableFile = path.join(dir, 'fable-budget.json');
  let fableBudget = null;
  try { fableBudget = JSON.parse(fs.readFileSync(fableFile, 'utf8')); } catch { /* none yet */ }

  // A pass that ends on a usage limit is rerun after the reset. Returns the
  // pass summary, or null when the loop should stop. refresh, when given,
  // rebuilds the prompt before a rerun (a gate result goes stale in a wait)
  // and returns it, or null to stop.
  async function pass(label, prompt, resume, refresh) {
    for (let waits = 0; ; waits++) {
      if (waits && refresh) {
        prompt = refresh();
        if (prompt === null) return null;
      }
      const streamFile = path.join(dir, `${stamp}-${label}${waits ? '-retry' + waits : ''}.jsonl`);
      const r = await runPass(o, prompt, streamFile, resume);
      const s = summarise(r.text);
      const res = s.result || {};
      if (s.limits) {
        fableBudget = chargeFable(fableBudget, limits, s.limits, res);
        try { fs.writeFileSync(fableFile, JSON.stringify(fableBudget) + '\n'); } catch { /* kept in memory */ }
        limits = s.limits;
      }
      const u = res.usage || {};
      const five = windowOf(s.limits, 'five_hour');
      const week = windowOf(s.limits, 'seven_day');
      log(`${label}: exit=${r.code} turns=${res.num_turns ?? '?'} peak-context=${s.peak} `
        + `in=${u.input_tokens ?? '?'} cache-write=${u.cache_creation_input_tokens ?? '?'} `
        + `cache-read=${u.cache_read_input_tokens ?? '?'} out=${u.output_tokens ?? '?'} `
        + `cost-equiv=$${res.total_cost_usd ?? '?'} subtype=${res.subtype ?? 'none'} `
        + `5h=${five ? Math.round(five.utilization * 100) + '%' : '?'} week=${week ? Math.round(week.utilization * 100) + '%' : '?'} `
        + `models=${modelSplit(res)}${r.killed ? ` killed-leftovers=${r.killed}` : ''}`);
      // A pass that exits non-zero without a result line left its only
      // evidence on stderr (card 31, 2026-10-15, exit 1 and nothing logged).
      if (r.code !== 0 && r.err.trim()) log(`${label}: stderr, last lines:\n${r.err.trim().split(/\r?\n/).slice(-15).join('\n')}`);
      const said = `${res.result || ''}\n${r.err}`;
      const rejected = s.limits && s.limits.status === 'rejected';
      if (!rejected && !LIMIT.test(said)) return { r, res, sessionId: s.sessionId };
      if (waits >= o.limitWaits) { log(`${label}: usage limit ${waits + 1} times running, stopping`); return null; }
      const reset = rejected && s.limits.resetsAt ? new Date(s.limits.resetsAt * 1000) : parseReset(said);
      const until = reset ? reset.getTime() + 2 * 60e3 : Date.now() + 30 * 60e3;
      log(`${label}: usage limit, waiting until ${new Date(until).toLocaleString()}${reset ? '' : ' (no reset time given, trying again in 30 min)'}`);
      if (!(await waitUntil(until))) return null;
    }
  }

  // A protocol failure: the session's word and git disagree, or the session
  // did not finish. The loop stops and leaves git exactly as it is for Rob;
  // no handoff, which could commit what was never gated.
  let failed = false;
  const protocolFailure = (n, why) => {
    failed = true;
    log(`card-${n}: protocol failure: ${why}. Stopping the loop; git is left as it is for Rob.`);
  };

  // One gate round: the suite, extra_checks and verify, then the fingerprint
  // of the tree they passed on, which a later commit must match.
  function gateRound(n, round, card) {
    log(`card-${n}: gate round ${round}: running the suite, extra checks and verify for ${card || '?'}`);
    const g = runGate(o.project, cfg, undefined, {
      onBusy: (step, k) => log(`card-${n}: gate round ${round}: ${step} found the test command busy (test_busy), waiting to run it again (${k})`),
    });
    if (g.busy) {
      log(`card-${n}: gate round ${round}: the test command was still busy after ${Math.round(BUSY_MAX_MS / 60e3)} min, stopping without counting a round`);
      return g;
    }
    g.tree = g.ok ? require('./project-test.cjs').treeFingerprint(o.project, ptCfg) : null;
    log(`card-${n}: gate round ${round}: ${g.ok ? 'passed' : `${g.step} failed, exit ${g.code}`}`);
    return g;
  }

  // PRs pushed and still owed a CI result, oldest first (see pushPrompt).
  const ciByLoop = cfg.ci === 'required';
  const ciMax = parseInt(cfg.ci_max, 10) > 0 ? parseInt(cfg.ci_max, 10) : 2;
  const mergeAllowed = /(^|\s)--merge-allowed(\s|$)/.test(o.afkFlags);
  const ciQueue = [];
  let ciStopped = false;
  // The repo's default branch, asked once; null when gh cannot say, which
  // leaves the base unchecked rather than failing every PR.
  let defaultBase;
  const defaultBranch = () => {
    if (defaultBase === undefined) {
      const r = ghJson(o.project, ['repo', 'view', '--json', 'defaultBranchRef']);
      defaultBase = (r.ok && r.data && r.data.defaultBranchRef && r.data.defaultBranchRef.name) || null;
    }
    return defaultBase;
  };

  // Why a red PR cannot get a repair pass, or null when it can. Only a
  // failure that names its checks is repairable (a moved head, a closed PR
  // or a wrong base is not a code problem), only the oldest PR when it is
  // the only one in CI (a later PR already pushed would still carry the old
  // code), and never on a dirty tree. When the working branch is the PR's
  // own branch with commits on top, a push from the repair would publish
  // them: allowed only when every one of them passed the gate (gated.json),
  // which is the card ci_max held back before its push (Sembr, 2026-10-15
  // and 2026-10-16, where refusing it deadlocked the loop). pushHeld then
  // pushes it first. Any ungated commit on top still refuses.
  function repairBlocker(q, s) {
    if (!s.failed || !s.failed.length) return 'the failure is not a check';
    if ((q.repairs || 0) >= CI_REPAIRS) return `${CI_REPAIRS} repairs already tried`;
    if (ciQueue.length !== 1) return `${ciQueue.length} PRs are in CI, and a repair only fixes the oldest`;
    const here = headState(o.project);
    if (here.branch === q.branch && here.sha !== q.sha && !allGated(o.project, dir, q.sha, here.sha)) return `${q.branch} carries an unpushed commit on top of the PR`;
    return treeProblem(o.project, ptCfg);
  }

  // The gated commits repairBlocker let through go onto the PR first, fast-
  // forward only, and CI is watched on that head; a repair, if it is still
  // red, then fixes the combined branch. null when nothing is held on the
  // PR's branch, true when the push landed, false when it did not.
  const heldPushed = new Set();
  function pushHeld(q, label) {
    const here = headState(o.project);
    if (here.branch !== q.branch || here.sha === q.sha) return null;
    const r = spawnSync('git', ['push', '-q', 'origin', `HEAD:refs/heads/${q.branch}`], { cwd: o.project, windowsHide: true, timeout: 120e3 });
    spawnSync('git', ['fetch', '-q', 'origin', q.branch], { cwd: o.project, windowsHide: true, timeout: 120e3 });
    const pushed = gitOut(o.project, ['rev-parse', '--verify', '-q', `refs/remotes/origin/${q.branch}`]);
    if (r.status !== 0 || pushed !== here.sha) {
      log(`${label}: the push of the gated ${short(here.sha)} held on ${q.branch} did not land (git exit ${r.status}, origin/${q.branch} at ${short(pushed)})`);
      return false;
    }
    heldPushed.add(here.sha);
    log(`${label}: pushed the gated ${short(here.sha)} held on ${q.branch}; watching CI on it`);
    Object.assign(q, { sha: here.sha, since: Date.now(), greenAt: null, greenChecks: 0 });
    return true;
  }

  // One repair pass for a red PR, then the git checks that it did what it
  // said. True when a new commit descending from the old head is on the PR's
  // remote branch and the checkout is back where the loop left it, or, for a
  // rerun, when origin's branch is still the watched head and the checkout is
  // exactly where it was. A rerun counts against CI_REPAIRS like a fix, so a
  // PR that keeps failing still stops at the cap; the loop waits one poll
  // before reading CI again, so the old red checks are not read as a new
  // failure before the re-run registers.
  async function repairCi(q, s, label) {
    q.repairs = (q.repairs || 0) + 1;
    const here = headState(o.project);
    log(`${label} failed CI: ${s.why}. Repair ${q.repairs} of ${CI_REPAIRS}: a fresh pass fixes ${q.branch}`);
    try { fs.unlinkSync(statusFile); } catch { /* none */ }
    const p = await pass(`${q.label || `card-${q.n}`}-ci${q.repairs}`, ciRepairPrompt(q, s, here, statusRel));
    if (!p) return false;
    const st = readStatus(statusFile, ['fixed', 'rerun', 'blocked']);
    const bad = passProblem(p);
    if (bad) { log(`${label}: the repair pass did not finish: ${bad}`); return false; }
    if (!st || st.status === 'blocked') { log(`${label}: the repair pass wrote ${st ? `${st.status}: ${st.note || ''}` : 'no status'}`); return false; }
    spawnSync('git', ['fetch', '-q', 'origin', q.branch], { cwd: o.project, windowsHide: true, timeout: 120e3 });
    const pushed = gitOut(o.project, ['rev-parse', '--verify', '-q', `refs/remotes/origin/${q.branch}`]);
    if (st.status === 'rerun') {
      if (pushed !== q.sha) { log(`${label}: the repair pass said rerun, but origin/${q.branch} is at ${short(pushed)}, not the watched ${short(q.sha)}`); return false; }
      const now = headState(o.project);
      if (now.branch !== here.branch || now.sha !== here.sha) { failed = true; log(`${label}: the repair pass left HEAD on ${now.branch || 'a detached HEAD'} at ${short(now.sha)}, not ${here.branch} at ${short(here.sha)}. Stopping; git is left as it is for Rob.`); return false; }
      const dirty = treeProblem(o.project, ptCfg);
      if (dirty) { failed = true; log(`${label}: after the repair pass ${dirty}. Stopping; git is left as it is for Rob.`); return false; }
      log(`${label}: repair ${q.repairs} re-ran the failed checks on ${short(q.sha)}, nothing pushed (${st.note || 'no note'}); watching CI again`);
      Object.assign(q, { since: Date.now(), greenAt: null, greenChecks: 0 });
      return waitUntil(Date.now() + CI_POLL_MS);
    }
    if (!pushed || pushed === q.sha) { log(`${label}: the repair pass said fixed, but nothing new is on origin/${q.branch}`); return false; }
    if (gitOut(o.project, ['merge-base', '--is-ancestor', q.sha, pushed]) === null) { failed = true; log(`${label}: origin/${q.branch} at ${short(pushed)} does not descend from ${short(q.sha)}: history was rewritten. Stopping; git is left as it is for Rob.`); return false; }
    const now = headState(o.project);
    const want = here.branch === q.branch ? { branch: q.branch, sha: pushed } : here;
    if (now.branch !== want.branch || now.sha !== want.sha) { failed = true; log(`${label}: the repair pass left HEAD on ${now.branch || 'a detached HEAD'} at ${short(now.sha)}, not ${want.branch} at ${short(want.sha)}. Stopping; git is left as it is for Rob.`); return false; }
    const dirty = treeProblem(o.project, ptCfg);
    if (dirty) { failed = true; log(`${label}: after the repair pass ${dirty}. Stopping; git is left as it is for Rob.`); return false; }
    log(`${label}: repair ${q.repairs} pushed ${short(pushed)} to ${q.branch} (${st.note || 'no note'}); watching CI again`);
    Object.assign(q, { sha: pushed, since: Date.now(), greenAt: null, greenChecks: 0 });
    return true;
  }

  // An earlier run that stopped on a red PR left it open. Such a PR is
  // adopted into the queue, oldest first, so it is repaired and merged before
  // anything new is pushed. Only the account's own open PRs that are the
  // checkout itself or that it builds on (the head is an ancestor of HEAD):
  // that leaves dependabot and unrelated PRs alone.
  function adoptOpenPrs() {
    const r = ghJson(o.project, ['pr', 'list', '--state', 'open', '--author', '@me', '--limit', '50', '--json', 'number,headRefName,headRefOid,createdAt']);
    if (!r.ok || !Array.isArray(r.data)) return;
    const mine = r.data
      .filter((pr) => pr.headRefOid && gitOut(o.project, ['merge-base', '--is-ancestor', pr.headRefOid, 'HEAD']) !== null)
      .sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)));
    for (const pr of mine) {
      ciQueue.push({ n: 0, label: `pr-${pr.number}`, branch: pr.headRefName, sha: pr.headRefOid, since: Date.now(), base: defaultBranch() });
      log(`ci: adopted open PR #${pr.number} (${pr.headRefName} at ${short(pr.headRefOid)}): the checkout builds on it, so it settles before the next push`);
    }
  }

  // Waits until at most keep PRs are still in CI, merging green ones in
  // order when the afk flags allow it. False when the loop should stop: a
  // red or stuck PR, a merge that did not land, gh failing, or STOP.
  async function settleCi(keep) {
    let errors = 0;
    while (ciQueue.length > keep) {
      const q = ciQueue[0];
      const s = ciState(o.project, q);
      const label = `ci: card-${q.n}${s.number ? ` PR #${s.number}` : ''}`;
      if (s.state === 'error') {
        if (++errors >= 5) { ciStopped = true; log(`${label}: ${s.why}, five times running. Stopping; the PR is left as it is.`); return false; }
      } else {
        errors = 0;
      }
      if (s.state === 'merged') { log(`${label} was merged`); ciQueue.shift(); continue; }
      if (s.state === 'pass') {
        if (!mergeAllowed) { log(`${label} is green, left open: the afk flags do not allow a merge`); ciQueue.shift(); continue; }
        const m = mergePr(o.project, s.number, q.branch, q.sha);
        if (!m.ok) { ciStopped = true; log(`${label} is green but did not merge: ${m.why}. Stopping.`); return false; }
        log(`${label} merged`);
        ciQueue.shift();
        continue;
      }
      if (s.state === 'fail') {
        const cant = repairBlocker(q, s);
        const held = cant ? null : pushHeld(q, label);
        if (held) continue;
        if (held === false) { ciStopped = true; log(`${label} failed CI: ${s.why}. Stopping: later cards build on it.`); return false; }
        if (!cant && !s.settled) {
          // Wait for the rest of the run, so one repair sees every red check.
          if (Date.now() - q.since > CI_MAX_MS) { ciStopped = true; log(`${label} still in CI after ${CI_MAX_MS / 3600e3} h, stopping`); return false; }
          if (!(await waitUntil(Date.now() + CI_POLL_MS))) return false;
          continue;
        }
        if (!cant && await repairCi(q, s, label)) continue;
        ciStopped = true;
        log(`${label} failed CI: ${s.why}. ${cant ? `Not repaired: ${cant}. ` : ''}Stopping: later cards build on it. ${ciQueue.length - 1} later PR(s) left open.`);
        return false;
      }
      if (Date.now() - q.since > CI_MAX_MS) { ciStopped = true; log(`${label} still in CI after ${CI_MAX_MS / 3600e3} h, stopping`); return false; }
      if (!(await waitUntil(Date.now() + CI_POLL_MS))) return false;
    }
    return true;
  }

  // An adopted PR settles before the first card, so new work starts on code
  // that is green and merged rather than on the red code it would inherit.
  if (ciByLoop) {
    adoptOpenPrs();
    if (ciQueue.length) {
      log(`ci: settling ${ciQueue.length} adopted PR(s) before the first card`);
      if (!(await settleCi(0))) ciStopped = true;
    }
  }
  let cards = 0;
  let misses = 0;
  while (!ciStopped && cards < o.maxCards) {
    if (fs.existsSync(stopFile)) { log('STOP file found, stopping'); break; }
    const q = quotaCall(limits, o);
    if (q.act === 'stop') { log(`${q.why}, at or over the ${Math.round(o.weeklyCeiling * 100)}% ceiling: stopping`); break; }
    if (q.act === 'wait') {
      log(`${q.why}, pausing until ${new Date(q.until).toLocaleString()}`);
      if (!(await waitUntil(q.until))) break;
    }
    // Another session's edits, or a pass that died part-way, would become
    // this card's work. Refuse to build on either.
    const dirty = treeProblem(o.project, ptCfg);
    if (dirty) { failed = true; log(`before card ${cards + 1}: ${dirty}. Stopping; commit, park or remove that first.`); break; }
    const start = headState(o.project);
    if (!start.sha || !start.branch) { failed = true; log(`before card ${cards + 1}: HEAD is ${start.sha ? 'detached' : 'unreadable'}, stopping`); break; }
    try { fs.unlinkSync(statusFile); } catch { /* none yet */ }
    const wasFableOk = o.fableOk;
    o.fableOk = fableAllowed(o, fableBudget);
    if (o.fableAudits > 0 && (wasFableOk === undefined || wasFableOk !== o.fableOk)) {
      log(`fable for audits: ${o.fableOk ? 'on' : 'off'}, ${Math.round(((fableBudget && fableBudget.used) || 0) * 100)}% of the week used of ${Math.round(o.fableAudits * 100)}%`);
    }
    let p = await pass(`card-${cards + 1}`, cardPrompt(o, statusRel));
    if (!p) break;
    cards++;
    const n = cards;
    // The coordinator stops at the gate: the mechanical steps run here, then
    // its session resumes with the result. After a green gate it commits and
    // stops; the commit is checked here, then it resumes once more to push.
    // A STOP file or the weekly ceiling does not cut a card off here; the
    // card in flight finishes first.
    let rounds = 0;
    let gateTree = null;
    let committed = null;
    let st = null;
    let halted = false;
    for (;;) {
      st = readStatus(statusFile);
      const bad = passProblem(p);
      if (bad) { protocolFailure(n, `${bad}${st ? ` (its status file says ${st.status})` : ''}`); break; }
      if (st && st.status === 'gate') {
        if (committed) { protocolFailure(n, `it wrote "gate" after claude-loop checked its commit ${short(committed.sha)}`); break; }
        if (rounds >= o.maxGateRounds) { protocolFailure(n, `still at the gate after ${rounds} round${rounds === 1 ? '' : 's'}, the most --max-gate-rounds allows`); break; }
        if (!p.sessionId) { protocolFailure(n, 'no session id to resume at the gate'); break; }
        rounds++;
        // Quota first: a wait between the gate and the resume would age the
        // run past verify_max_age_min, and R --pass would then refuse it.
        const q2 = quotaCall(limits, o);
        if (q2.act === 'wait') {
          log(`${q2.why}, pausing until ${new Date(q2.until).toLocaleString()} before the gate`);
          if (!(await waitUntil(q2.until))) { halted = true; break; }
        }
        const round = rounds;
        let g = gateRound(n, round, st.card);
        if (g.busy) { halted = true; break; }
        gateTree = g.tree;
        try { fs.unlinkSync(statusFile); } catch { /* gone */ }
        const sid = p.sessionId;
        const card = st.card;
        p = await pass(`card-${n}-gate${round}`, gatePrompt(g, cfg, statusRel, round, o.maxGateRounds), sid, () => {
          log(`card-${n}: gate round ${round}: the resume waited out a usage limit, so the gate runs again`);
          g = gateRound(n, round, card);
          if (g.busy) return null;
          gateTree = g.tree;
          return gatePrompt(g, cfg, statusRel, round, o.maxGateRounds);
        });
        if (!p) { halted = true; break; }
        if (!p.sessionId) p.sessionId = sid;
        continue;
      }
      if (st && st.status === 'committed') {
        const why = committed ? `it wrote "committed" a second time` : commitProblem(o.project, ptCfg, start, gateTree);
        if (why) { protocolFailure(n, why); break; }
        committed = headState(o.project);
        if (!recordGated(dir, committed.sha)) log(`card-${n}: note: could not write gated.json, so a red PR under ${short(committed.sha)} cannot be repaired while it is held`);
        log(`card-${n}: commit ${short(committed.sha)} on ${committed.branch} is the tree the gate tested; resuming to push`);
        if (!p.sessionId) { protocolFailure(n, 'no session id to resume for the push'); break; }
        if (ciByLoop && ciQueue.length >= ciMax) {
          log(`card-${n}: ${ciQueue.length} PR(s) in CI, the most ci_max (${ciMax}) allows; waiting before the push`);
          if (!(await settleCi(ciMax - 1))) {
            halted = true;
            log(heldPushed.has(committed.sha)
              ? `card-${n}: ${short(committed.sha)} was pushed onto ${committed.branch}'s red PR, which did not settle`
              : `card-${n}: ${short(committed.sha)} stays committed on ${committed.branch}, not pushed`);
            break;
          }
        }
        // pushHeld put this card on its PR and the PR settled with it. A push
        // pass now could push a merged and deleted branch back, or open a
        // second PR, so the card ends here and the next pass reads the board.
        // No pass ran since repairCi checked HEAD, so afterPushProblem is not
        // the check: with the branch deleted no remote holds the card, and a
        // repair commit on top is not a bookkeeping change.
        if (heldPushed.has(committed.sha)) {
          log(`card-${n}: push pass skipped: ${short(committed.sha)} went out with ${committed.branch}'s PR, which settled, and a push now could bring a merged branch back or open a second PR`);
          const now = headState(o.project);
          const why = treeProblem(o.project, ptCfg)
            || (now.branch !== committed.branch || gitOut(o.project, ['merge-base', '--is-ancestor', committed.sha, now.sha]) === null
              ? `HEAD is on ${now.branch || 'a detached HEAD'} at ${short(now.sha)}, not on ${committed.branch} at or above ${short(committed.sha)}` : null);
          if (why) { protocolFailure(n, why); break; }
          st = { status: 'continue', card: st.card, note: 'its PR settled with it; no push pass' };
          break;
        }
        try { fs.unlinkSync(statusFile); } catch { /* gone */ }
        const sid = p.sessionId;
        p = await pass(`card-${n}-push`, pushPrompt(committed, statusRel, ciByLoop), sid);
        if (!p) { halted = true; break; }
        if (!p.sessionId) p.sessionId = sid;
        continue;
      }
      // continue, done, blocked, or no status at all.
      const why = committed ? afterPushProblem(o.project, ptCfg, cfg, committed) : parkProblem(o.project, ptCfg, cfg, start);
      if (why) { protocolFailure(n, `status ${st ? st.status : 'none'}, but ${why}`); break; }
      if (committed) {
        const up = gitOut(o.project, ['rev-parse', '--verify', '-q', `${committed.branch}@{u}`]);
        if (up !== committed.sha && headState(o.project).sha === committed.sha) log(`card-${n}: note: ${committed.branch} has no upstream at ${short(committed.sha)}, so the push did not land`);
        // On a remote branch at all, upstream or not: a push without -u sets
        // none, and a PR nobody queues is never watched or merged.
        const pushed = !!gitOut(o.project, ['branch', '-r', '--contains', committed.sha]);
        if (ciByLoop && !pushed) log(`card-${n}: ${short(committed.sha)} is on no remote branch, so there is no PR to watch`);
        if (ciByLoop && pushed) {
          // A project that keeps one branch per feature pushes each card onto
          // the same PR: its checks now run on this head, which carries the
          // earlier card, so the earlier entry is replaced, not kept.
          for (let i = ciQueue.length - 1; i >= 0; i--) if (ciQueue[i].branch === committed.branch) ciQueue.splice(i, 1);
          ciQueue.push({ n, branch: committed.branch, sha: committed.sha, since: Date.now(), base: defaultBranch() });
        }
      }
      break;
    }
    if (failed || halted) break;
    if (!st) {
      misses++;
      log(`card-${n}: no status file written (${misses} in a row)`);
      if (misses >= 2) { log('two passes in a row ended without a status, stopping'); break; }
      continue;
    }
    misses = 0;
    log(`card-${n}: ${st.status}: ${st.card || ''} ${st.note || ''}`.trim());
    if (st.status !== 'continue') break;
  }
  if (cards >= o.maxCards) log(`max-cards (${o.maxCards}) reached`);
  if (ciQueue.length && !failed && !ciStopped && !fs.existsSync(stopFile)) {
    log(`ci: waiting for the last ${ciQueue.length} PR(s) in CI`);
    await settleCi(0);
  }
  if (ciQueue.length) log(`ci: ${ciQueue.length} PR(s) left unsettled: ${ciQueue.map((q) => q.branch).join(', ')}`);

  if (o.handoff && cards > 0) {
    const t = failed ? 'a protocol failure' : treeProblem(o.project, ptCfg);
    if (t) log(`no handoff: ${t}`);
    else await pass('handoff', handoffPrompt('.claude/work/loop/loop.log'));
  }
  log('end');
}

if (require.main === module) {
  main().catch((e) => { console.error(e.message); process.exit(1); });
}

module.exports = {
  parseArgs, parseReset, chargeFable, fableAllowed, collectTree, killSurvivors, runPass, summarise, readStatus, quotaCall, tierLine, modelSplit, LIMIT,
  trimmedSettings, mergeSettings, loopSettings, loopTools, runGate, gatePrompt, cardPrompt, pushPrompt, LOOP_TOOLS,
  parkProblem, commitProblem, afterPushProblem, passProblem, ciState, ciRepairPrompt, RUNNER_BUSY,
};
