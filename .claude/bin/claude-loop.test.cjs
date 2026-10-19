'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
const {
  parseArgs, parseReset, chargeFable, fableAllowed, summarise, readStatus, quotaCall, tierLine, modelSplit, LIMIT,
  trimmedSettings, mergeSettings, loopTools, runGate, gatePrompt, cardPrompt, pushPrompt, LOOP_TOOLS, ciState, ciRepairPrompt, RUNNER_BUSY,
} = require('./claude-loop.cjs');

const LOOP = path.join(__dirname, 'claude-loop.cjs');

// A stand-in for `claude -p`. Each call takes the next step from plan.json:
// {"status": "continue"|"done"|"blocked"|null, "say": "result text"}. It logs
// the prompt it was given, writes the status file, prints stream-json.
// A step may also write files ("write": {path: text}), run commands in the
// project ("run": [[cmd, ...args]], RUNNER is project-test.cjs), exit
// nonzero ("exit") and report another result subtype ("subtype").
const FAKE = `
const fs = require('fs');
const path = require('path');
const dir = __dirname;
const plan = JSON.parse(fs.readFileSync(path.join(dir, 'plan.json'), 'utf8'));
const n = fs.existsSync(path.join(dir, 'calls.txt')) ? fs.readFileSync(path.join(dir, 'calls.txt'), 'utf8').split('\\n').filter(Boolean).length : 0;
let prompt = '';
process.stdin.on('data', (b) => { prompt += b; });
process.stdin.on('end', () => {
  fs.appendFileSync(path.join(dir, 'calls.txt'), prompt.split('\\n')[0] + '\\n');
  fs.writeFileSync(path.join(dir, 'last-prompt.txt'), prompt);
  fs.writeFileSync(path.join(dir, 'last-args.json'), JSON.stringify(process.argv.slice(2)));
  fs.appendFileSync(path.join(dir, 'args.txt'), JSON.stringify(process.argv.slice(2)) + '\\n');
  fs.appendFileSync(path.join(dir, 'prompts.txt'), JSON.stringify(prompt) + '\\n');
  console.log(JSON.stringify({ type: 'system', subtype: 'init', session_id: 'sess-' + n + '-abcdef' }));
  const step = plan[n] || { status: 'done', say: 'ok' };
  for (const [f, c] of Object.entries(step.write || {})) {
    fs.mkdirSync(path.dirname(path.join(process.cwd(), f)), { recursive: true });
    fs.writeFileSync(path.join(process.cwd(), f), c);
  }
  for (const cmd of step.run || []) {
    const a = cmd.map((x) => (x === 'RUNNER' ? __RUNNER__ : x));
    const r = require('child_process').spawnSync(a[0] === 'node' ? process.execPath : a[0], a.slice(1), { cwd: process.cwd(), encoding: 'utf8' });
    fs.appendFileSync(path.join(dir, 'run.txt'), JSON.stringify([n, a.slice(0, 3).join(' '), r.status, (r.stdout || '') + (r.stderr || '')]) + '\\n');
  }
  if (step.status) {
    fs.mkdirSync(path.join(process.cwd(), '.claude/work/loop'), { recursive: true });
    fs.writeFileSync(path.join(process.cwd(), '.claude/work/loop/status.json'), JSON.stringify({ status: step.status, card: 'c' + n, note: 'n' }));
  }
  if (step.limits) console.log(JSON.stringify({ type: 'rate_limit_event', rate_limit_info: step.limits }));
  console.log(JSON.stringify({ type: 'assistant', message: { usage: { input_tokens: 5, cache_read_input_tokens: 1000 * (n + 1), cache_creation_input_tokens: 10 } } }));
  console.log(JSON.stringify({ type: 'result', subtype: step.subtype || 'success', num_turns: 3, result: step.say, usage: { input_tokens: 5, output_tokens: 7 } }));
  process.exitCode = step.exit || 0;
});
`;

function setup(plan, { joined = true } = {}) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'claude-loop-'));
  const proj = path.join(root, 'proj');
  const bin = path.join(root, 'bin');
  fs.mkdirSync(path.join(proj, '.claude'), { recursive: true });
  fs.mkdirSync(bin);
  if (joined) fs.writeFileSync(path.join(proj, '.claude', 'project.md'), '---\ntest_command: x\n---\n');
  fs.writeFileSync(path.join(proj, '.gitignore'), '.claude/work/loop/\n');
  gitIn(proj, ['init', '-q'], ['add', '-A'], ['commit', '-q', '-m', 'init']);
  fs.writeFileSync(path.join(bin, 'fake.cjs'), FAKE.replace('__RUNNER__', JSON.stringify(path.join(__dirname, 'project-test.cjs'))));
  fs.writeFileSync(path.join(bin, 'plan.json'), JSON.stringify(plan));
  return { proj, bin };
}

const ID = ['-c', 'user.email=t@t', '-c', 'user.name=t'];
function gitIn(dir, ...cmds) {
  let out = '';
  for (const a of cmds) {
    const r = spawnSync('git', ID.concat(a), { cwd: dir, encoding: 'utf8' });
    assert.strictEqual(r.status, 0, 'git ' + a.join(' ') + ': ' + r.stderr);
    out = r.stdout.trim();
  }
  return out;
}

// Files a test adds before the run are committed first, so the loop starts
// on a clean tree, unless the test is about a dirty one.
function run({ proj, bin }, extra = [], { dirty = false } = {}) {
  if (!dirty && fs.existsSync(path.join(proj, '.git'))) {
    gitIn(proj, ['add', '-A']);
    if (spawnSync('git', ['diff', '--cached', '--quiet'], { cwd: proj }).status === 1) gitIn(proj, ['commit', '-q', '-m', 'test setup']);
  }
  const home = path.join(path.dirname(bin), 'home');
  fs.mkdirSync(home, { recursive: true });
  const r = spawnSync(process.execPath, [LOOP, '--project', proj, '--claude', `node "${path.join(bin, 'fake.cjs')}"`, ...extra], { encoding: 'utf8', timeout: 60000, env: { ...process.env, CLAUDE_CONFIG_DIR: home } });
  const calls = fs.existsSync(path.join(bin, 'calls.txt')) ? fs.readFileSync(path.join(bin, 'calls.txt'), 'utf8').split('\n').filter(Boolean) : [];
  const logf = path.join(proj, '.claude/work/loop/loop.log');
  return { ...r, calls, log: fs.existsSync(logf) ? fs.readFileSync(logf, 'utf8') : '' };
}

test('runs one pass per card until done, then hands off', () => {
  const r = run(setup([{ status: 'continue', say: 'a' }, { status: 'done', say: 'b' }]));
  assert.strictEqual(r.status, 0, r.stderr);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill.", '/handoff']);
  assert.match(r.log, /card-2: done/);
  assert.match(r.log, /peak-context=2015/);
});

test('a card pass forbids Fable and names Opus as the top tier', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  const prompt = fs.readFileSync(path.join(s.bin, 'last-prompt.txt'), 'utf8');
  assert.match(prompt, /No Fable, for any worker or audit/);
  assert.match(prompt, /Opus is the top tier/);
});

test('--fable-audits allows Fable for audits only, and the budget turns it off', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff', '--fable-audits', '0.25']);
  const prompt = fs.readFileSync(path.join(s.bin, 'last-prompt.txt'), 'utf8');
  assert.match(prompt, /Fable is allowed for audits only/);
  assert.match(prompt, /up to 25% of the weekly limit/);
  assert.doesNotMatch(prompt, /No Fable, for any worker or audit/);
  const o = parseArgs(['--fable-audits', '0.25']);
  assert.strictEqual(fableAllowed(o, null), true);
  assert.strictEqual(fableAllowed(o, { resetsAt: 1, used: 0.25 }), false);
  assert.strictEqual(fableAllowed(parseArgs([]), null), false);
  assert.throws(() => parseArgs(['--fable-audits', '2']), /fraction/);
});

test('chargeFable charges the weekly rise by Fable\'s share of the cost, and starts over with a new week', () => {
  const lim = (u, r) => ({ unifiedWindows: { seven_day: { utilization: u, resetsAt: r } } });
  const res = { modelUsage: { 'claude-fable-5-1': { costUSD: 3 }, 'claude-opus-5-5': { costUSD: 1 } } };
  const b = chargeFable(null, lim(0.10, 100), lim(0.18, 100), res);
  assert.ok(Math.abs(b.used - 0.06) < 1e-9, String(b.used));
  const none = chargeFable(b, lim(0.18, 100), lim(0.20, 100), { modelUsage: { 'claude-opus-5-5': { costUSD: 2 } } });
  assert.ok(Math.abs(none.used - 0.06) < 1e-9);
  const fresh = chargeFable(none, lim(0.20, 100), lim(0.01, 200), res);
  assert.strictEqual(fresh.used, 0);
});

test('a card pass forbids SendMessage and sends a card back to a fresh foreground worker', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  const prompt = fs.readFileSync(path.join(s.bin, 'last-prompt.txt'), 'utf8');
  assert.match(prompt, /Never use SendMessage in this pass/);
  assert.match(prompt, /fresh foreground worker \(run_in_background false\) whose prompt is the brief's path plus the failing command's output/);
});

test("the loop's folder ignores itself in git", () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  assert.strictEqual(fs.readFileSync(path.join(s.proj, '.claude/work/loop/.gitignore'), 'utf8'), '*\n');
});

test('a pass loads no MCP servers by default', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  const args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  assert.ok(args.includes('--strict-mcp-config'));
  const cfg = args[args.indexOf('--mcp-config') + 1];
  assert.strictEqual(path.basename(cfg), 'mcp-none.json');
  assert.deepStrictEqual(JSON.parse(fs.readFileSync(cfg, 'utf8')), { mcpServers: {} });
});

test("a project's loop-mcp.json replaces the empty set", () => {
  const s = setup([{ status: 'done' }]);
  fs.writeFileSync(path.join(s.proj, '.claude', 'loop-mcp.json'), '{"mcpServers":{"fetch":{}}}');
  run(s, ['--no-handoff']);
  const args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  assert.strictEqual(args[args.indexOf('--mcp-config') + 1], path.join(s.proj, '.claude', 'loop-mcp.json'));
});

test('a pass skips the persona voice files but keeps corrections', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  const args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  const cfg = JSON.parse(fs.readFileSync(args[args.indexOf('--settings') + 1], 'utf8'));
  assert.deepStrictEqual(cfg.claudeMdExcludes, ['**/personas/cas/soul.md', '**/personas/cas/voice-samples.md']);
});

test("a project's loop-settings.json is merged over the trimmed defaults", () => {
  const s = setup([{ status: 'done' }]);
  fs.writeFileSync(path.join(s.proj, '.claude', 'loop-settings.json'), JSON.stringify({
    claudeMdExcludes: ['x.md'], permissions: { allow: ['Bash(gh pr merge:*)'], deny: ['Agent(extra)'] },
  }));
  run(s, ['--no-handoff']);
  const args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  const cfgFile = args[args.indexOf('--settings') + 1];
  assert.strictEqual(cfgFile, path.join(s.proj, '.claude', 'work', 'loop', 'settings.json'));
  const cfg = JSON.parse(fs.readFileSync(cfgFile, 'utf8'));
  assert.deepStrictEqual(cfg.claudeMdExcludes, ['x.md']);
  assert.deepStrictEqual(cfg.permissions.allow, ['Bash(gh pr merge:*)']);
  assert.ok(cfg.permissions.deny.includes('Agent(general-purpose)'));
  assert.ok(cfg.permissions.deny.includes('Agent(extra)'));
  assert.strictEqual(cfg.disableBundledSkills, true);
});

test('a Sonnet coordinator pins the planner, audits and risky workers to Opus', () => {
  assert.strictEqual(tierLine('opus'), '');
  assert.match(tierLine('sonnet'), /model "opus" on every planner Agent call, every security-auditor, and the worker for any card on a risky path/);
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff', '--model', 'sonnet']);
  assert.match(fs.readFileSync(path.join(s.bin, 'last-prompt.txt'), 'utf8'), /You are the coordinator on sonnet/);
});

test('the default is Opus at low effort, and --effort changes the level', () => {
  const s = setup([{ status: 'continue' }, { status: 'done' }]);
  run(s, ['--no-handoff', '--max-cards', '1']);
  let args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  assert.strictEqual(args[args.indexOf('--effort') + 1], 'low');
  assert.strictEqual(args[args.indexOf('--model') + 1], 'opus');
  run(s, ['--no-handoff', '--max-cards', '1', '--effort', 'high']);
  args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  assert.strictEqual(args[args.indexOf('--effort') + 1], 'high');
});

test('modelSplit names each model family with its cost', () => {
  assert.strictEqual(modelSplit({ modelUsage: { 'claude-opus-5-5': { costUSD: 2.4172 }, 'claude-sonnet-5-5': { costUSD: 0.305 } } }), 'opus:$2.42,sonnet:$0.30');
  assert.strictEqual(modelSplit({}), '?');
});

test('blocked stops the run and still hands off', () => {
  const r = run(setup([{ status: 'blocked', say: 'x' }]));
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", '/handoff']);
});

test('max-cards caps the run', () => {
  const r = run(setup([{ status: 'continue' }, { status: 'continue' }, { status: 'continue' }]), ['--max-cards', '2', '--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /max-cards \(2\) reached/);
});

test('a STOP file before the first pass runs nothing', () => {
  const s = setup([{ status: 'continue' }]);
  fs.mkdirSync(path.join(s.proj, '.claude/work/loop'), { recursive: true });
  fs.writeFileSync(path.join(s.proj, '.claude/work/loop/STOP'), '');
  const r = run(s);
  assert.deepStrictEqual(r.calls, []);
  assert.match(r.log, /STOP file found/);
});

test('two passes in a row with no status stop the run', () => {
  const r = run(setup([{ status: null }, { status: null }, { status: 'continue' }]), ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /two passes in a row/);
});

test('a usage limit reruns the same pass after the reset', () => {
  const past = Math.floor(Date.now() / 1000) - 600;
  const r = run(setup([{ status: 'continue', say: `Claude AI usage limit reached|${past}` }, { status: 'done', say: 'ok' }]), ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /card-1: usage limit, waiting/);
  assert.match(r.log, /card-1: done/);
});

const nowS = () => Math.floor(Date.now() / 1000);
const quota = (five, week, status = 'allowed', resetsAt = nowS() - 600) => ({
  status, resetsAt,
  unifiedWindows: { five_hour: { utilization: five, resetsAt }, seven_day: { utilization: week, resetsAt: nowS() + 86400 } },
});

test('weekly use at the ceiling stops before the next card', () => {
  const r = run(setup([{ status: 'continue', limits: quota(0.2, 0.81) }, { status: 'continue' }]));
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", '/handoff']);
  assert.match(r.log, /weekly use at 81%, at or over the 80% ceiling: stopping/);
  assert.match(r.log, /5h=20% week=81%/);
});

test('5-hour use at the pause point waits for its reset, then carries on', () => {
  const r = run(setup([{ status: 'continue', limits: quota(0.9, 0.3) }, { status: 'done' }]), ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /5-hour use at 90%, pausing until/);
});

test('a rejected rate_limit_event reruns the pass even with no limit wording', () => {
  const r = run(setup([{ status: 'continue', say: 'stopped', limits: quota(1, 0.3, 'rejected') }, { status: 'done' }]), ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /card-1: usage limit, waiting/);
});

test('quotaCall goes below both lines', () => {
  const o = parseArgs([]);
  assert.strictEqual(quotaCall(quota(0.5, 0.5), o).act, 'go');
  assert.strictEqual(quotaCall(null, o).act, 'go');
  assert.strictEqual(quotaCall(quota(0.85, 0.5), o).act, 'wait');
  assert.strictEqual(quotaCall(quota(0.85, 0.8), o).act, 'stop');
});

test('a project without project.md is refused', () => {
  const r = run(setup([], { joined: false }));
  assert.strictEqual(r.status, 2);
  assert.match(r.stderr, /has not joined/);
});

test('a second loop on the same project is refused while the first lives', () => {
  const s = setup([]);
  fs.mkdirSync(path.join(s.proj, '.claude/work/loop'), { recursive: true });
  fs.writeFileSync(path.join(s.proj, '.claude/work/loop/lock'), String(process.pid));
  const r = run(s);
  assert.strictEqual(r.status, 2);
  assert.match(r.stderr, /another loop/);
});

test('parseReset reads the epoch form', () => {
  assert.strictEqual(parseReset('Claude AI usage limit reached|1759600800').getTime(), 1759600800000);
});

test('parseReset reads a clock time, rolling to tomorrow when it has passed', () => {
  const now = new Date(2026, 9, 4, 16, 0);
  assert.deepStrictEqual(parseReset("You've hit your limit · resets 3:45pm (America/Toronto)", now), new Date(2026, 9, 5, 15, 45));
  assert.deepStrictEqual(parseReset('resets 9pm', now), new Date(2026, 9, 4, 21, 0));
  assert.deepStrictEqual(parseReset('resets at 12am', now), new Date(2026, 9, 5, 0, 0));
});

test('parseReset reads a dated weekly reset', () => {
  const now = new Date(2026, 9, 4, 16, 0);
  assert.deepStrictEqual(parseReset('weekly limit · resets Oct 7, 3pm', now), new Date(2026, 9, 7, 15, 0));
});

test('parseReset returns null when no time is given', () => {
  assert.strictEqual(parseReset('usage limit reached'), null);
});

test('LIMIT matches the known wordings and not ordinary text', () => {
  assert.ok(LIMIT.test("You've hit your limit · resets 3pm"));
  assert.ok(LIMIT.test('Claude AI usage limit reached|1'));
  assert.ok(!LIMIT.test('Card 39 committed, suite green'));
});

test('summarise takes the result and the peak context', () => {
  const s = summarise([
    JSON.stringify({ type: 'assistant', message: { usage: { input_tokens: 1, cache_read_input_tokens: 90000, cache_creation_input_tokens: 9 } } }),
    'not json',
    JSON.stringify({ type: 'assistant', message: { usage: { input_tokens: 1, cache_read_input_tokens: 50000 } } }),
    JSON.stringify({ type: 'result', num_turns: 2 }),
  ].join('\n'));
  assert.strictEqual(s.peak, 90010);
  assert.strictEqual(s.result.num_turns, 2);
});

test('readStatus refuses an unknown status', () => {
  const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cl-')), 's.json');
  fs.writeFileSync(f, '{"status":"maybe"}');
  assert.strictEqual(readStatus(f), null);
  fs.writeFileSync(f, '{"status":"done"}');
  assert.strictEqual(readStatus(f).status, 'done');
});

test('parseArgs refuses shell characters in values that reach the shell', () => {
  assert.throws(() => parseArgs(['--model', 'opus & del x']), /character the shell/);
  assert.throws(() => parseArgs(['--afk-flags', '--merge-allowed | x']), /character the shell/);
  assert.strictEqual(parseArgs(['--afk-flags', '--merge-allowed --allow-outward deploy']).afkFlags, '--merge-allowed --allow-outward deploy');
  assert.throws(() => parseArgs(['--pause-at', '85']), /fraction/);
  assert.throws(() => parseArgs(['--effort', 'turbo']), /--effort is one of/);
});

// ---- Trimmed setup -------------------------------------------------------

function fakeHome() {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'cl-home-'));
  for (const s of ['coordinate', 'brief', 'handoff', 'afk', 'seo-audit', 'shared-name', '.trash', 'synced']) fs.mkdirSync(path.join(home, 'skills', s), { recursive: true });
  fs.mkdirSync(path.join(home, 'commands'));
  fs.writeFileSync(path.join(home, 'commands', 'research.md'), '');
  fs.mkdirSync(path.join(home, 'agents'));
  for (const a of ['implementer', 'sweeper', 'planner', 'security-auditor', 'seo-geo', 'payment-integration']) fs.writeFileSync(path.join(home, 'agents', a + '.md'), '');
  fs.writeFileSync(path.join(home, 'settings.json'), JSON.stringify({ enabledPlugins: { 'aikido@x': true, 'telegram@x': true } }));
  return home;
}

test('trimmedSettings turns off unused user skills, plugins and agents, and leaves the project its own', () => {
  const home = fakeHome();
  const proj = fs.mkdtempSync(path.join(os.tmpdir(), 'cl-proj-'));
  fs.mkdirSync(path.join(proj, '.claude', 'skills', 'shared-name'), { recursive: true });
  fs.mkdirSync(path.join(proj, '.claude', 'agents'), { recursive: true });
  fs.writeFileSync(path.join(proj, '.claude', 'agents', 'payment-integration.md'), '');
  const t = trimmedSettings(proj, home);
  assert.deepStrictEqual(t.skillOverrides, { doctor: 'off', 'seo-audit': 'off', research: 'off' });
  assert.deepStrictEqual(t.enabledPlugins, { 'aikido@x': false, 'telegram@x': false });
  assert.ok(t.permissions.deny.includes('Agent(seo-geo)'));
  assert.ok(t.permissions.deny.includes('Agent(Explore)'));
  for (const keep of ['implementer', 'sweeper', 'planner', 'security-auditor', 'payment-integration']) {
    assert.ok(!t.permissions.deny.includes(`Agent(${keep})`), keep);
  }
  assert.strictEqual(t.skillListingMaxDescChars, 1);
  assert.deepStrictEqual(t.claudeMdExcludes, ['**/personas/cas/soul.md', '**/personas/cas/voice-samples.md']);
});

test('mergeSettings lets the project win on a key and unions permission lists', () => {
  const m = mergeSettings(
    { a: 1, skillOverrides: { x: 'off', y: 'off' }, permissions: { deny: ['A'] } },
    { a: 2, skillOverrides: { y: 'on' }, permissions: { deny: ['A', 'B'], allow: ['C'] } },
  );
  assert.deepStrictEqual(m, { a: 2, skillOverrides: { x: 'off', y: 'on' }, enabledPlugins: {}, permissions: { deny: ['A', 'B'], allow: ['C'] } });
});

test('loopTools is the fixed set plus loop_tools, and refuses a name that is not a tool', () => {
  assert.strictEqual(loopTools({}), LOOP_TOOLS.join(','));
  assert.strictEqual(loopTools({ loop_tools: 'WebFetch, Bash' }), LOOP_TOOLS.concat('WebFetch').join(','));
  assert.throws(() => loopTools({ loop_tools: 'Bash;rm' }), /not a tool/);
});

test('a pass gets --tools with the trimmed set', () => {
  const s = setup([{ status: 'done' }]);
  run(s, ['--no-handoff']);
  const args = JSON.parse(fs.readFileSync(path.join(s.bin, 'last-args.json'), 'utf8'));
  assert.strictEqual(args[args.indexOf('--tools') + 1], LOOP_TOOLS.join(','));
});

// ---- The gate in code ----------------------------------------------------

// A git project whose test command is a node script that exits with the
// number in suite-exit.txt (0 when absent), with an optional extra check.
function gitProject(extra) {
  const proj = fs.mkdtempSync(path.join(os.tmpdir(), 'cl-gate-'));
  fs.mkdirSync(path.join(proj, '.claude'), { recursive: true });
  fs.writeFileSync(path.join(proj, 'suite.cjs'), "const fs=require('fs');const c=fs.existsSync('suite-exit.txt')?parseInt(fs.readFileSync('suite-exit.txt','utf8'),10):0;console.log(c?'FAILED SomeTest':'Tests: 3 passed');process.exit(c);\n");
  fs.writeFileSync(path.join(proj, 'check.cjs'), "const fs=require('fs');if(fs.existsSync('check-touch.txt'))fs.writeFileSync('touched.txt','x');if(fs.existsSync('check-fail.txt')){console.log('hook test broke');process.exit(3)}\n");
  fs.writeFileSync(path.join(proj, '.gitignore'), 'suite-exit.txt\ncheck-fail.txt\ncheck-touch.txt\nstorage/\n.claude/work/\n');
  fs.writeFileSync(path.join(proj, '.claude', 'project.md'), `---\ntest_command: node suite.cjs\ntest_log: storage/test.log\n${extra === false ? '' : 'extra_checks: node check.cjs\n'}---\n`);
  for (const a of [['init', '-q'], ['add', '-A'], ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'init']]) {
    const r = spawnSync('git', a, { cwd: proj, encoding: 'utf8' });
    assert.strictEqual(r.status, 0, r.stderr);
  }
  return proj;
}
const cfgOf = (proj) => require('./project-test.cjs').parseFrontmatter(fs.readFileSync(path.join(proj, '.claude', 'project.md'), 'utf8'));

test('runGate passes when the suite, the extra check and verify all exit 0', () => {
  const proj = gitProject();
  const g = runGate(proj, cfgOf(proj));
  assert.deepStrictEqual(g, { ok: true, steps: ['full suite', 'extra_checks', 'verify'] });
});

test('runGate skips extra_checks when project.md has none', () => {
  const proj = gitProject(false);
  assert.deepStrictEqual(runGate(proj, cfgOf(proj)).steps, ['full suite', 'verify']);
});

test('runGate stops at a red suite and hands back its output', () => {
  const proj = gitProject();
  fs.writeFileSync(path.join(proj, 'suite-exit.txt'), '1');
  const g = runGate(proj, cfgOf(proj));
  assert.strictEqual(g.ok, false);
  assert.strictEqual(g.step, 'full suite');
  assert.strictEqual(g.code, 1);
  assert.match(g.output, /FAILED SomeTest/);
});

test('runGate stops at a failing extra check', () => {
  const proj = gitProject();
  fs.writeFileSync(path.join(proj, 'check-fail.txt'), '');
  const g = runGate(proj, cfgOf(proj));
  assert.strictEqual(g.step, 'extra_checks');
  assert.strictEqual(g.code, 3);
  assert.match(g.output, /hook test broke/);
});

test('runGate fails verify when a check changes the tree after the suite', () => {
  const proj = gitProject();
  fs.writeFileSync(path.join(proj, 'check-touch.txt'), '');
  const g = runGate(proj, cfgOf(proj));
  assert.strictEqual(g.step, 'verify');
  assert.match(g.output, /tree changed since the run/);
});

test('gatePrompt says carry on to commit on a pass, another round on a fail, park on the last', () => {
  const ok = gatePrompt({ ok: true }, { extra_checks: 'node c.cjs' }, 's.json', 1, 3);
  assert.match(ok, /^Gate result from claude-loop: passed\./);
  assert.match(ok, /extra_checks \(node c\.cjs\) exited 0/);
  assert.match(ok, /Carry on from step 6 \(Commit\)/);
  const bad = { ok: false, step: 'full suite', command: 'node project-test.cjs', code: 1, output: 'FAILED X' };
  const again = gatePrompt(bad, {}, 's.json', 1, 3);
  assert.match(again, /^Gate result from claude-loop: failed\./);
  assert.match(again, /FAILED X/);
  assert.match(again, /write status "gate" again/);
  assert.match(gatePrompt(bad, {}, 's.json', 3, 3), /round 3 of 3 .*Park it/);
});

test('a card pass tells the coordinator to stop at the gate and never run the suite itself', () => {
  const p = cardPrompt(parseArgs([]), '.claude/work/loop/status.json');
  assert.match(p, /Gate steps 4 and 5 \(the full suite, extra_checks, R --verify\) are run by claude-loop in code, not by you/);
  assert.match(p, /"status": "gate"/);
});

test('readStatus accepts gate, and summarise picks up the session id', () => {
  const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cl-')), 's.json');
  fs.writeFileSync(f, '{"status":"gate"}');
  assert.strictEqual(readStatus(f).status, 'gate');
  assert.strictEqual(summarise(JSON.stringify({ type: 'system', subtype: 'init', session_id: 'abc-12345678' })).sessionId, 'abc-12345678');
  assert.strictEqual(summarise(JSON.stringify({ type: 'system', session_id: 'x & y' })).sessionId, null);
});

function gateSetup(plan) {
  const s = setup(plan);
  const proj = gitProject();
  fs.rmSync(s.proj, { recursive: true, force: true });
  fs.renameSync(proj, s.proj);
  return s;
}
const argsLog = (s) => fs.readFileSync(path.join(s.bin, 'args.txt'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));
const promptsLog = (s) => fs.readFileSync(path.join(s.bin, 'prompts.txt'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));

test('a green gate resumes the same session to commit', () => {
  const s = gateSetup([{ status: 'gate' }, { status: 'done' }]);
  const r = run(s, ['--no-handoff']);
  assert.strictEqual(r.status, 0, r.stderr);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: passed.']);
  const a = argsLog(s);
  assert.ok(!a[0].includes('--resume'));
  assert.strictEqual(a[1][a[1].indexOf('--resume') + 1], 'sess-0-abcdef');
  assert.match(r.log, /gate round 1: passed/);
  assert.match(r.log, /card-1: done/);
});

test('a red gate resumes with the failing output, and the next round can pass', () => {
  const s = gateSetup([{ status: 'gate' }, { status: 'gate', fix: true }, { status: 'continue' }, { status: 'done' }]);
  fs.writeFileSync(path.join(s.proj, 'suite-exit.txt'), '1');
  // The fake "worker" fixes the suite during the first resumed pass.
  const fake = fs.readFileSync(path.join(s.bin, 'fake.cjs'), 'utf8')
    .replace("const step = plan[n] || { status: 'done', say: 'ok' };", "const step = plan[n] || { status: 'done', say: 'ok' };\n  if (step.fix) fs.unlinkSync(path.join(process.cwd(), 'suite-exit.txt'));");
  fs.writeFileSync(path.join(s.bin, 'fake.cjs'), fake);
  const r = run(s, ['--no-handoff']);
  assert.strictEqual(r.status, 0, r.stderr);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: failed.', 'Gate result from claude-loop: passed.', "Coordinate from this project's own copy of the skill."]);
  assert.match(promptsLog(s)[1], /full suite failed: `node project-test\.cjs` exited 1/);
  assert.match(promptsLog(s)[1], /FAILED SomeTest/);
  assert.match(r.log, /gate round 1: full suite failed, exit 1/);
  assert.match(r.log, /gate round 2: passed/);
});

test('a card still at the gate at the round cap stops before running another gate', () => {
  const s = gateSetup([{ status: 'gate' }, { status: 'gate' }, { status: 'gate' }, { status: 'gate' }]);
  fs.writeFileSync(path.join(s.proj, 'suite-exit.txt'), '1');
  const r = run(s, ['--no-handoff', '--max-gate-rounds', '1']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: failed.']);
  assert.match(promptsLog(s)[1], /round 1 of 1 .*Park it/);
  assert.strictEqual((r.log.match(/gate round \d+: running/g) || []).length, 1);
  assert.match(r.log, /protocol failure: still at the gate after 1 round, /);
});

// ---- Code-owned outcomes -------------------------------------------------

// A gate project with a board, a checklist entry 07 and, if asked, a bare
// remote, so a fake session can gate, pass, commit and push for real.
function commitSetup(plan, { remote = false } = {}) {
  const s = gateSetup(plan);
  const md = path.join(s.proj, '.claude', 'project.md');
  fs.writeFileSync(md, fs.readFileSync(md, 'utf8').replace('---\n', '---\nboard: BOARD.md\nscope_ignore: local.json\n'));
  fs.writeFileSync(path.join(s.proj, 'BOARD.md'), 'Now: 07\n');
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'board']);
  fs.mkdirSync(path.join(s.proj, '.claude', 'work'), { recursive: true });
  fs.writeFileSync(path.join(s.proj, '.claude', 'work', 'checklist.json'), JSON.stringify([{ id: '07', passes: false }]));
  if (remote) {
    const bare = fs.mkdtempSync(path.join(os.tmpdir(), 'cl-remote-'));
    gitIn(bare, ['init', '-q', '--bare']);
    gitIn(s.proj, ['remote', 'add', 'origin', bare]);
  }
  return s;
}
const G = (...a) => ['git', ...ID, ...a];
const WORK = { status: 'gate', write: { 'src.txt': 'work\n' } };
const COMMIT = { status: 'committed', write: { 'BOARD.md': 'Done: 07\n' }, run: [['node', 'RUNNER', '--pass', '07'], G('add', '-A'), G('commit', '-q', '-m', 'card 07')] };
const head = (s) => gitIn(s.proj, ['rev-parse', 'HEAD']);

test('a green gate, a commit checked against the tested tree, then the push', () => {
  const s = commitSetup([WORK, COMMIT, { status: 'continue', run: [G('push', '-q', '-u', 'origin', 'HEAD')] }, { status: 'done' }], { remote: true });
  const before = head(s);
  const r = run(s, ['--no-handoff']);
  assert.strictEqual(r.status, 0, r.stderr);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: passed.', 'Commit check from claude-loop: passed.', "Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /card-1: commit \w+ on \S+ is the tree the gate tested; resuming to push/);
  assert.match(r.log, /card-1: continue/);
  assert.match(r.log, /card-2: done/);
  assert.doesNotMatch(r.log, /protocol failure|push did not land/);
  assert.notStrictEqual(head(s), before);
  assert.match(promptsLog(s)[1], /do not push and do not open the PR yet/);
  assert.match(promptsLog(s)[2], /push \S+ in its own call, then open the PR/);
});

test('continue without a gate, with HEAD moved by a code commit, is a protocol failure and no handoff', () => {
  const s = commitSetup([{ status: 'continue', write: { 'src.txt': 'ungated\n' }, run: [G('add', '-A'), G('commit', '-q', '-m', 'ungated')] }]);
  const r = run(s);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /card-1: protocol failure: status continue, but \S+ moved from \w+ to \w+ with no commit checked by claude-loop: the commits change src\.txt/);
  assert.match(r.log, /no handoff: a protocol failure/);
});

test('"committed" with no green gate is a protocol failure', () => {
  const s = commitSetup([{ status: 'committed', write: { 'src.txt': 'x\n' }, run: [G('add', '-A'), G('commit', '-q', '-m', 'x')] }]);
  const r = run(s, ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /protocol failure: it wrote "committed" but claude-loop saw no green gate for this card/);
});

test('a nonzero exit, or a result that is not success, fails whatever the status file says', () => {
  let r = run(setup([{ status: 'continue', exit: 1 }, { status: 'done' }]));
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /card-1: protocol failure: claude exited 1 \(its status file says continue\)/);
  r = run(setup([{ status: 'done', subtype: 'error_max_turns' }]));
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /protocol failure: the result was error_max_turns, not success/);
});

test('a park that leaves its work on a wip branch and commits only the board passes', () => {
  const s = commitSetup([{
    status: 'continue',
    write: { 'src.txt': 'unfinished\n' },
    run: [G('checkout', '-q', '-b', 'wip/07-x'), G('add', '-A'), G('commit', '-q', '-m', 'wip'), G('checkout', '-q', '-'),
      ['node', '-e', "require('fs').writeFileSync('BOARD.md', 'Blocked: 07')"], G('commit', '-q', '-am', 'park 07')],
  }, { status: 'done' }]);
  const r = run(s, ['--no-handoff']);
  assert.doesNotMatch(r.log, /protocol failure/);
  assert.match(r.log, /card-1: continue/);
  assert.match(r.log, /card-2: done/);
});

test('a park that ends on its wip branch is a protocol failure', () => {
  const s = commitSetup([{ status: 'continue', write: { 'src.txt': 'unfinished\n' }, run: [G('checkout', '-q', '-b', 'wip/07-x'), G('add', '-A'), G('commit', '-q', '-m', 'wip')] }]);
  const r = run(s, ['--no-handoff']);
  assert.match(r.log, /protocol failure: status continue, but the card started on \S+ and ended on wip\/07-x/);
});

test('a dirty tree before a card stops the loop before any pass; a scope_ignore file does not', () => {
  const s = commitSetup([{ status: 'done' }]);
  fs.writeFileSync(path.join(s.proj, 'local.json'), '{}');
  fs.writeFileSync(path.join(s.proj, 'stray.txt'), 'left by another session\n');
  let r = run(s, [], { dirty: true });
  assert.deepStrictEqual(r.calls, []);
  assert.match(r.log, /before card 1: the tree is not clean: stray\.txt\. Stopping/);
  assert.doesNotMatch(r.log, /handoff/);
  fs.rmSync(path.join(s.proj, 'stray.txt'));
  r = run(s, ['--no-handoff'], { dirty: true });
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
});

test('a pass that leaves the tree dirty with no status stops the loop', () => {
  const r = run(setup([{ status: null, write: { 'half.txt': 'x' } }, { status: 'continue' }]), ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill."]);
  assert.match(r.log, /protocol failure: status none, but the tree is not clean: half\.txt/);
});

test('a project that is not a git repository is refused', () => {
  const s = setup([{ status: 'done' }]);
  fs.rmSync(path.join(s.proj, '.git'), { recursive: true, force: true });
  const r = run(s);
  assert.strictEqual(r.status, 2);
  assert.match(r.stderr, /not a git repository/);
  assert.deepStrictEqual(r.calls, []);
});

test('the quota is checked before the gate runs, not between the gate and the resume', () => {
  const s = gateSetup([{ status: 'gate', limits: quota(0.9, 0.3) }, { status: 'done' }]);
  const r = run(s, ['--no-handoff']);
  const pause = r.log.indexOf('5-hour use at 90%, pausing until');
  assert.ok(pause > 0, r.log);
  assert.match(r.log, /pausing until .* before the gate/);
  assert.ok(pause < r.log.indexOf('gate round 1: running'), r.log);
});

test('a resume that waits out a usage limit runs the gate again first', () => {
  const past = Math.floor(Date.now() / 1000) - 600;
  const s = gateSetup([{ status: 'gate' }, { status: null, say: `Claude AI usage limit reached|${past}` }, { status: 'done' }]);
  const r = run(s, ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: passed.', 'Gate result from claude-loop: passed.']);
  assert.match(r.log, /the resume waited out a usage limit, so the gate runs again/);
  assert.strictEqual((r.log.match(/gate round 1: passed/g) || []).length, 2);
});

test('a commit that differs from the tested tree stops the loop before the push', () => {
  const changed = { ...COMMIT, run: [['node', 'RUNNER', '--pass', '07'], ['node', '-e', "require('fs').writeFileSync('src.txt', 'edited after the gate')"], G('add', '-A'), G('commit', '-q', '-m', 'x')] };
  const s = commitSetup([WORK, changed, { status: 'continue' }]);
  const r = run(s, ['--no-handoff']);
  assert.deepStrictEqual(r.calls, ["Coordinate from this project's own copy of the skill.", 'Gate result from claude-loop: passed.']);
  assert.match(r.log, /protocol failure: the committed tree \(\w+\) is not the tree the gate tested/);
});

test('a partial commit (a tested file left unstaged) stops the loop before the push', () => {
  const partial = { ...COMMIT, run: [['node', 'RUNNER', '--pass', '07'], G('add', 'BOARD.md'), G('commit', '-q', '-m', 'board only')] };
  const r = run(commitSetup([WORK, partial, { status: 'continue' }]), ['--no-handoff']);
  assert.strictEqual(r.calls.length, 2);
  assert.match(r.log, /protocol failure: after the commit the tree is not clean: src\.txt/);
});

test('a commit without R --pass stops the loop', () => {
  const nopass = { ...COMMIT, run: [G('add', '-A'), G('commit', '-q', '-m', 'x')] };
  const r = run(commitSetup([WORK, nopass, { status: 'continue' }]), ['--no-handoff']);
  assert.strictEqual(r.calls.length, 2);
  assert.match(r.log, /protocol failure: no checklist entry has passes true for the tested tree/);
});

test('a commit pushed before the check stops the loop', () => {
  const early = { ...COMMIT, run: COMMIT.run.concat([G('push', '-q', '-u', 'origin', 'HEAD')]) };
  const r = run(commitSetup([WORK, early, { status: 'continue' }], { remote: true }), ['--no-handoff']);
  assert.strictEqual(r.calls.length, 2);
  assert.match(r.log, /protocol failure: \w+ is already on origin\/\S+: it was pushed before claude-loop checked it/);
});

test('a new commit after the checked one, in the push pass, stops the loop', () => {
  const sneak = { status: 'continue', write: { 'src.txt': 'more\n' }, run: [G('commit', '-q', '-am', 'sneak')] };
  const r = run(commitSetup([WORK, COMMIT, sneak]), ['--no-handoff']);
  assert.match(r.log, /protocol failure: status continue, but HEAD moved after claude-loop checked \w+: the commits change src\.txt/);
});

// ---- Busy, not red -------------------------------------------------------

const BUSY_MSG = 'Another test run is using the test database. Wait for it to finish, then run the suite again.';
function busyProject(busyRuns, withKey = true) {
  const proj = gitProject(false);
  fs.writeFileSync(path.join(proj, 'suite.cjs'), `const fs=require('fs');const f='storage/busy-left.txt';const n=fs.existsSync(f)?parseInt(fs.readFileSync(f,'utf8'),10):0;if(n>0){fs.writeFileSync(f,String(n-1));console.log(${JSON.stringify(BUSY_MSG)});process.exit(1)}console.log('Tests: 3 passed');\n`);
  const md = path.join(proj, '.claude', 'project.md');
  if (withKey) fs.writeFileSync(md, fs.readFileSync(md, 'utf8').replace('---\n', '---\ntest_busy: Another test run is using the test database\n'));
  gitIn(proj, ['add', '-A'], ['commit', '-q', '-m', 'busy suite']);
  fs.mkdirSync(path.join(proj, 'storage'), { recursive: true });
  fs.writeFileSync(path.join(proj, 'storage', 'busy-left.txt'), String(busyRuns));
  return proj;
}

test('runGate waits out a busy test database (test_busy) and then passes', () => {
  const proj = busyProject(2);
  const seen = [];
  const g = runGate(proj, cfgOf(proj), undefined, { busyWaitMs: 20, onBusy: (step, k) => seen.push(`${step} ${k}`) });
  assert.strictEqual(g.ok, true, g.output);
  assert.strictEqual(g.busyWaits, 2);
  assert.deepStrictEqual(seen, ['full suite 1', 'full suite 2']);
});

test('runGate without test_busy counts the busy refusal as a red suite', () => {
  const proj = busyProject(1, false);
  const g = runGate(proj, cfgOf(proj), undefined, { busyWaitMs: 20 });
  assert.strictEqual(g.ok, false);
  assert.strictEqual(g.busy, undefined);
  assert.match(g.output, /Another test run/);
});

test('runGate gives up on a busy test database after the bound, marked busy, not red', () => {
  const proj = busyProject(1000);
  const t0 = Date.now();
  const g = runGate(proj, cfgOf(proj), undefined, { busyWaitMs: 50, busyMaxMs: 2000 });
  assert.strictEqual(g.ok, false);
  assert.strictEqual(g.busy, true);
  assert.ok(g.busyWaits >= 1 && Date.now() - t0 < 30000);
});

test('the pass prompts carry the new order: board move, R --pass, commit, stop; push only after the check', () => {
  const ok = gatePrompt({ ok: true }, {}, 's.json', 1, 3);
  const i = (re) => ok.search(re);
  assert.ok(i(/move the card on the board/) < i(/R --pass <id>/) && i(/R --pass <id>/) < i(/; commit\./), ok);
  assert.match(ok, /"status": "committed"/);
  assert.match(cardPrompt(parseArgs([]), 's.json'), /claude-loop refuses to start a pass on a dirty tree/);
  assert.match(pushPrompt({ sha: 'abcdef1234', branch: 'feat/x' }, 's.json'), /^Commit check from claude-loop: passed\.[\s\S]*push feat\/x in its own call/);
});

test('a pass kills what it left running, even an orphan whose parent already exited', { skip: process.platform !== 'win32' }, async () => {
  const { runPass } = require('./claude-loop.cjs');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'loop-tree-'));
  const beat = path.join(dir, 'beat.txt');
  fs.writeFileSync(path.join(dir, 'sleeper.cjs'), `setInterval(() => require('fs').writeFileSync(${JSON.stringify(beat)}, String(Date.now())), 100);`);
  // detached: without it Windows takes the sleeper down with its parent here, so there would be no orphan to find.
  fs.writeFileSync(path.join(dir, 'middle.cjs'), `require('child_process').spawn(process.execPath, [${JSON.stringify(path.join(dir, 'sleeper.cjs'))}], { stdio: 'ignore', windowsHide: true, detached: true }); setTimeout(() => process.exit(0), 3000);`);
  fs.writeFileSync(path.join(dir, 'fake.cjs'), `process.stdin.resume(); process.stdin.on('end', () => { require('child_process').spawn(process.execPath, [${JSON.stringify(path.join(dir, 'middle.cjs'))}], { stdio: 'ignore', windowsHide: true }); setTimeout(() => process.exit(0), 6000); });`);
  for (const f of ['mcp.json', 'settings.json']) fs.writeFileSync(path.join(dir, f), '{}');
  const o = { claude: `node "${path.join(dir, 'fake.cjs')}"`, project: dir, model: 'x', maxTurns: 1,
    mcp: path.join(dir, 'mcp.json'), settings: path.join(dir, 'settings.json'), treeEveryMs: 500 };
  const r = await runPass(o, 'go', path.join(dir, 'out.jsonl'));
  assert.ok(r.killed >= 1, `killed ${r.killed}`);
  const a = fs.readFileSync(beat, 'utf8');
  await new Promise((res) => setTimeout(res, 1000));
  assert.strictEqual(fs.readFileSync(beat, 'utf8'), a, 'the orphan is still writing');
});

// ---- The runner's own lock, with no test_busy ------------------------------

test('runGate waits out the runner lock in a project with no test_busy, then passes', () => {
  const proj = gitProject(false);
  const pt = require('./project-test.cjs');
  const ptCfg = pt.loadConfig(proj);
  let release = pt.acquireLock(proj, ptCfg);
  assert.ok(release, 'the test took the lock');
  const seen = [];
  const g = runGate(proj, cfgOf(proj), undefined, {
    busyWaitMs: 20,
    onBusy: (step, k) => { seen.push(`${step} ${k}`); if (release) { release(); release = null; } },
  });
  assert.strictEqual(g.ok, true, g.output);
  assert.strictEqual(g.busyWaits, 1);
  assert.deepStrictEqual(seen, ['full suite 1']);
});

test('the runner refusal is the line the gate matches', () => {
  assert.strictEqual(RUNNER_BUSY, require('./project-test.cjs').BUSY);
});

// ---- CI owned by the loop -------------------------------------------------

function ghStub(pr, checks) {
  return (project, args) => {
    if (args[1] === 'view') return pr;
    return checks;
  };
}
const mkQ = () => ({ n: 1, branch: 'feat/x', sha: 'abc1234567', since: Date.now() });
const open = { ok: true, data: { number: 7, state: 'OPEN', headRefOid: 'abc1234567' } };

test('ciState: a merged PR is merged', () => {
  const s = ciState('.', mkQ(), Date.now(), ghStub({ ok: true, data: { number: 7, state: 'MERGED', headRefOid: 'abc1234567' } }));
  assert.strictEqual(s.state, 'merged');
});

test('ciState: a closed PR, or a head that is not the tested commit, fails', () => {
  assert.strictEqual(ciState('.', mkQ(), Date.now(), ghStub({ ok: true, data: { number: 7, state: 'CLOSED', headRefOid: 'abc1234567' } })).state, 'fail');
  const s = ciState('.', mkQ(), Date.now(), ghStub({ ok: true, data: { number: 7, state: 'OPEN', headRefOid: 'fff0000000' } }));
  assert.strictEqual(s.state, 'fail');
  assert.match(s.why, /not the tested/);
});

test('ciState: pending while any check runs, pass when none fail', () => {
  assert.strictEqual(ciState('.', mkQ(), Date.now(), ghStub(open, { ok: true, data: [{ name: 'a', bucket: 'pass' }, { name: 'b', bucket: 'pending' }] })).state, 'pending');
  const q = mkQ();
  const green = ghStub(open, { ok: true, data: [{ name: 'a', bucket: 'pass' }, { name: 'b', bucket: 'skipping' }] });
  const t = Date.now();
  assert.strictEqual(ciState('.', q, t, green).state, 'pending', 'the first green reading only starts the clock');
  assert.strictEqual(ciState('.', q, t + 10e3, green).state, 'pending', 'too soon after the first');
  assert.strictEqual(ciState('.', q, t + 61e3, green).state, 'pass');
});

test('ciState: a check that registers late restarts the green clock', () => {
  const q = mkQ();
  const t = Date.now();
  assert.strictEqual(ciState('.', q, t, ghStub(open, { ok: true, data: [{ name: 'a', bucket: 'pass' }] })).state, 'pending');
  assert.strictEqual(ciState('.', q, t + 61e3, ghStub(open, { ok: true, data: [{ name: 'a', bucket: 'pass' }, { name: 'late', bucket: 'pass' }] })).state, 'pending');
  assert.strictEqual(ciState('.', q, t + 122e3, ghStub(open, { ok: true, data: [{ name: 'a', bucket: 'pass' }, { name: 'late', bucket: 'pass' }] })).state, 'pass');
});

test('ciState: an old merged PR on a reused branch name is not this card', () => {
  const s = ciState('.', mkQ(), Date.now(), ghStub({ ok: true, data: { number: 3, state: 'MERGED', headRefOid: 'old0000000' } }));
  assert.strictEqual(s.state, 'fail');
  assert.match(s.why, /no PR carries this card/);
});

test('ciState: a PR based on anything but the default branch fails', () => {
  const q = { ...mkQ(), base: 'main' };
  const s = ciState('.', q, Date.now(), ghStub({ ok: true, data: { number: 7, state: 'OPEN', headRefOid: 'abc1234567', baseRefName: 'feat/w' } }));
  assert.strictEqual(s.state, 'fail');
  assert.match(s.why, /based on feat\/w, not main/);
});

test('ciState: a failed or cancelled check fails, even beside a pending one', () => {
  const f = ciState('.', mkQ(), Date.now(), ghStub(open, { ok: true, data: [{ name: 'tests', bucket: 'fail' }, { name: 'b', bucket: 'pending' }] }));
  assert.strictEqual(f.state, 'fail');
  assert.match(f.why, /tests \(fail\)/);
  assert.strictEqual(ciState('.', mkQ(), Date.now(), ghStub(open, { ok: true, data: [{ name: 'lint', bucket: 'cancel' }] })).state, 'fail');
});

test('ciState: no checks yet is pending, then a failure once the grace runs out', () => {
  const none = ghStub(open, { ok: false, err: 'no checks reported' });
  const q = mkQ();
  assert.strictEqual(ciState('.', q, q.since + 60e3, none).state, 'pending');
  assert.strictEqual(ciState('.', q, q.since + 16 * 60e3, none).state, 'fail');
});

test('ciState: gh failing on the PR is an error, not a pass', () => {
  assert.strictEqual(ciState('.', mkQ(), Date.now(), ghStub({ ok: false, err: 'no pull requests found' })).state, 'error');
});

test('pushPrompt: with CI owned by the loop, the pass neither waits nor merges', () => {
  const p = pushPrompt({ sha: 'abc1234567', branch: 'feat/x' }, '.claude/work/loop/status.json', true);
  assert.match(p, /do not merge it/);
  assert.match(p, /next card builds on feat\/x/);
  assert.doesNotMatch(pushPrompt({ sha: 'abc1234567', branch: 'feat/x' }, 's.json', false), /do not merge/);
});

// A fake gh over the test's bare remote: every PR is #1, open until merged,
// its head is the branch's upstream, its checks are green. Merges are
// recorded by head sha in merged.json next to it, and listed in gh-calls.txt.
const FAKE_GH = `
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const a = process.argv.slice(2); const dir = __dirname;
const mf = path.join(dir, 'merged.json'); const merged = fs.existsSync(mf) ? JSON.parse(fs.readFileSync(mf, 'utf8')) : [];
const bf = path.join(dir, 'branch.txt');
const headOf = (b) => execFileSync('git', ['rev-parse', 'refs/remotes/origin/' + b], { encoding: 'utf8' }).trim();
if (a[0] === 'pr' && a[1] === 'view') {
  if (!/^[0-9]+$/.test(a[2])) fs.writeFileSync(bf, a[2]);
  const h = headOf(fs.readFileSync(bf, 'utf8'));
  console.log(JSON.stringify({ number: 1, state: merged.includes(h) ? 'MERGED' : 'OPEN', headRefOid: h }));
} else if (a[0] === 'pr' && a[1] === 'checks') {
  console.log(JSON.stringify([{ name: 'tests', bucket: fs.existsSync(path.join(dir, 'red.txt')) ? 'fail' : 'pass' }]));
} else if (a[0] === 'pr' && a[1] === 'merge') {
  const h = headOf(fs.readFileSync(bf, 'utf8')); merged.push(h); fs.writeFileSync(mf, JSON.stringify(merged));
  fs.appendFileSync(path.join(dir, 'gh-calls.txt'), 'merge ' + h + String.fromCharCode(10));
} else if (a[0] === 'pr' && a[1] === 'list') {
  const af = path.join(dir, 'adopt.json');
  console.log(a.includes('--author') && fs.existsSync(af) ? fs.readFileSync(af, 'utf8') : '[]');
}
`;

function ciSetup(plan, ciMax) {
  const s = commitSetup(plan, { remote: true });
  const md = path.join(s.proj, '.claude', 'project.md');
  fs.writeFileSync(md, fs.readFileSync(md, 'utf8').replace('---\n', `---\nci: required\nci_max: ${ciMax}\n`));
  fs.writeFileSync(path.join(s.bin, 'gh.cjs'), FAKE_GH);
  return s;
}
function runCi(s, extra) {
  const prev = [process.env.CLAUDE_LOOP_GH, process.env.CLAUDE_LOOP_CI_POLL_MS];
  process.env.CLAUDE_LOOP_GH = path.join(s.bin, 'gh.cjs');
  process.env.CLAUDE_LOOP_CI_POLL_MS = '200';
  try { return run(s, extra); } finally {
    for (const [k, v] of [['CLAUDE_LOOP_GH', prev[0]], ['CLAUDE_LOOP_CI_POLL_MS', prev[1]]]) { if (v === undefined) delete process.env[k]; else process.env[k] = v; }
  }
}
const PUSH = (status, upstream = true) => ({ status, run: [G('push', '-q', ...(upstream ? ['-u'] : []), 'origin', 'HEAD')] });
const WORK2 = { status: 'gate', write: { 'src.txt': 'work two\n' } };
const COMMIT2 = { ...COMMIT, write: { 'BOARD.md': 'Done: 07 again\n' } };

test('ci: the push pass is told not to wait or merge, and the loop merges green PRs in order', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('continue'), WORK2, COMMIT2, PUSH('done')], 1);
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.match(promptsLog(s)[2], /do not merge it: claude-loop watches CI/);
  assert.match(r.log, /card-2: 1 PR\(s\) in CI, the most ci_max \(1\) allows; waiting before the push/);
  const merges = fs.readFileSync(path.join(s.bin, 'gh-calls.txt'), 'utf8').trim().split('\n');
  assert.strictEqual(merges.length, 2, r.log);
  assert.doesNotMatch(r.log, /protocol failure|left unsettled/);
});

test('ci: without --merge-allowed a green PR is left open and the loop carries on', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done')], 2);
  const r = runCi(s, ['--no-handoff']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.match(r.log, /is green, left open/);
  assert.strictEqual(fs.existsSync(path.join(s.bin, 'gh-calls.txt')), false);
});

// The held card is gated, so it goes onto the red PR for the repair; the
// repair here writes no fixed status, so the loop stops with nothing merged.
test('ci: a red PR the repair cannot fix stops the loop before the next card, nothing merged', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('continue'), WORK2, COMMIT2, PUSH('done')], 1);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, /failed CI: PR #1: tests \(fail\)/);
  assert.match(r.log, /card-2: \w+ was pushed onto \S+'s red PR, which did not settle/);
  assert.strictEqual(fs.existsSync(path.join(s.bin, 'gh-calls.txt')), false);
});

// A repair pass as the fake runs it: the CI goes green (red.txt removed), a
// fix is committed on the PR's branch and pushed, and HEAD stays there.
const REPAIR = (bin, status = 'fixed') => ({
  status,
  write: { 'fix.txt': 'fixed\n' },
  run: [['node', '-e', `require('fs').rmSync(${JSON.stringify(path.join(bin, 'red.txt'))}, { force: true })`],
    G('add', '-A'), G('commit', '-q', '-m', 'fix CI'), G('push', '-q', 'origin', 'HEAD')],
});

test('ci: a red PR gets a repair pass, which pushes a fix that goes green and merges', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done')], 2);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('done'), REPAIR(s.bin)]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  const repairPrompt = JSON.parse(fs.readFileSync(path.join(s.bin, 'prompts.txt'), 'utf8').trim().split('\n').pop());
  assert.match(repairPrompt, /failed these CI checks:\n- tests/);
  assert.match(repairPrompt, /Never weaken, skip or delete a check/);
  assert.match(r.log, /Repair 1 of 2: a fresh pass fixes/);
  assert.match(r.log, /repair 1 pushed \w+ to \S+ \(n\); watching CI again/);
  assert.match(r.log, /ci: card-1 PR #1 merged/);
  const merges = fs.readFileSync(path.join(s.bin, 'gh-calls.txt'), 'utf8').trim().split('\n');
  assert.strictEqual(merges[0], `merge ${head(s)}`, 'the merge is of the repaired head');
});

test('ci: a repair pass that writes blocked stops the loop with the PR left open', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done')], 2);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('done'), { status: 'blocked' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, /the repair pass wrote blocked/);
  assert.match(r.log, /left unsettled/);
  assert.strictEqual(fs.existsSync(path.join(s.bin, 'gh-calls.txt')), false);
});

test('ci: a repair that says fixed but pushed nothing is not believed', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done')], 2);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('done'), { status: 'fixed' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, /said fixed, but nothing new is on origin/);
  assert.strictEqual(fs.existsSync(path.join(s.bin, 'gh-calls.txt')), false);
});

test('ci: an open PR the checkout builds on is adopted, repaired and merged before the next push', () => {
  const s = ciSetup([], 1);
  const branch = gitIn(s.proj, ['symbolic-ref', '--short', 'HEAD']);
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'ci setup'], ['push', '-q', '-u', 'origin', 'HEAD']);
  const prHead = head(s);
  fs.writeFileSync(path.join(s.bin, 'adopt.json'), JSON.stringify([{ number: 1, headRefName: branch, headRefOid: prHead, createdAt: '2026-10-14T00:00:00Z' }]));
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([REPAIR(s.bin), { status: 'done' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.match(r.log, new RegExp(`ci: adopted open PR #1 \\(${branch} at ${prHead.slice(0, 9)}\\)`));
  const log = r.log;
  assert.ok(log.indexOf('PR #1 merged') > -1 && log.indexOf('PR #1 merged') < log.indexOf('card-1:'), `the adopted PR merges before the first card:\n${log}`);
});

test('ci: an adopted PR that cannot be repaired stops the loop before any card starts', () => {
  const s = ciSetup([], 1);
  const branch = gitIn(s.proj, ['symbolic-ref', '--short', 'HEAD']);
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'ci setup'], ['push', '-q', '-u', 'origin', 'HEAD']);
  fs.writeFileSync(path.join(s.bin, 'adopt.json'), JSON.stringify([{ number: 1, headRefName: branch, headRefOid: head(s), createdAt: '2026-10-14T00:00:00Z' }]));
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([{ status: 'blocked' }, { status: 'blocked' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, /the repair pass wrote blocked/);
  assert.doesNotMatch(r.log, /card-1:/);
});

// The stall of 2026-10-15 and 2026-10-16: a gated card held back by ci_max
// sits on the red PR's own branch, and the loop would neither push it nor
// repair the PR.
test('ci: a gated card held by ci_max on a red PR\'s branch is pushed first, then repaired with it', () => {
  const s = ciSetup([], 1);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('continue'), WORK2, COMMIT2, REPAIR(s.bin), { status: 'done' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  const card2 = (/card-2: commit (\w+) on/.exec(r.log) || [])[1];
  assert.ok(card2, r.log);
  assert.match(r.log, new RegExp(`pushed the gated ${card2} held on \\S+; watching CI on it`));
  assert.doesNotMatch(r.log, /unpushed commit on top/);
  assert.match(r.log, /Repair 1 of 2: a fresh pass fixes/);
  assert.match(r.log, /ci: card-1 PR #1 merged/);
  assert.match(r.log, /card-2: continue/);
  assert.match(r.log, /card-3: done/);
  assert.doesNotMatch(r.log, /left unsettled|protocol failure|failed CI: PR #1's head/);
});

// The stall of 2026-10-16: a repair pass that finds a runner flake re-runs
// the failed jobs and pushes nothing. As the fake runs it, the re-run goes
// green when green is true (red.txt removed); otherwise it stays red.
const RERUN = (bin, green) => ({
  status: 'rerun',
  run: green ? [['node', '-e', `require('fs').rmSync(${JSON.stringify(path.join(bin, 'red.txt'))}, { force: true })`]] : [],
});

test('ci: a repair pass that re-runs a flaked job pushes nothing, and the loop watches CI again, merges and carries on', () => {
  const s = ciSetup([], 1);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('continue'), WORK2, COMMIT2, RERUN(s.bin, true), { status: 'done' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.match(r.log, /Repair 1 of 2: a fresh pass fixes/);
  assert.match(r.log, /repair 1 re-ran the failed checks on \w+, nothing pushed \(n\); watching CI again/);
  assert.match(r.log, /ci: card-1 PR #1 merged/);
  const merges = fs.readFileSync(path.join(s.bin, 'gh-calls.txt'), 'utf8').trim().split('\n');
  assert.strictEqual(merges[0], `merge ${head(s)}`, 'the merge is of the same head, nothing new pushed');
  assert.match(r.log, /card-3: done/);
  assert.doesNotMatch(r.log, /left unsettled|protocol failure|Stopping/);
});

test('ci: a PR that stays red after re-runs stops at the repair cap', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done')], 2);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('done'), RERUN(s.bin, false), RERUN(s.bin, false), { status: 'blocked' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, /Repair 2 of 2: a fresh pass fixes/);
  assert.match(r.log, /Not repaired: 2 repairs already tried\. Stopping: later cards build on it/);
  assert.strictEqual((r.log.match(/re-ran the failed checks/g) || []).length, 2, r.log);
  assert.doesNotMatch(r.log, /Repair 3 of/);
  assert.strictEqual(fs.existsSync(path.join(s.bin, 'gh-calls.txt')), false);
});

test('ciRepairPrompt: a runner flake is re-run, not patched, and written as rerun', () => {
  const p = ciRepairPrompt({ branch: 'card/a', sha: 'aaa1111111' }, { number: 9, failed: [{ name: 'tests', link: 'https://x/runs/55/job/123' }] }, { branch: 'card/a', sha: 'aaa1111111' }, 'st.json');
  assert.match(p, /gh run rerun <run id> --failed/);
  assert.match(p, /"status": "fixed" \| "rerun" \| "blocked"/);
});

test('ci: a held card that went out with its PR gets no push pass, so the merged branch stays deleted', () => {
  const s = ciSetup([], 1);
  const branch = gitIn(s.proj, ['symbolic-ref', '--short', 'HEAD']);
  // Let the merge delete the remote branch, as GitHub does, though it is the bare remote's own HEAD.
  gitIn(gitIn(s.proj, ['remote', 'get-url', 'origin']), ['config', 'receive.denyDeleteCurrent', 'ignore']);
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, PUSH('continue'), WORK2, COMMIT2, REPAIR(s.bin), { status: 'done' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.strictEqual(r.calls.filter((c) => c === 'Commit check from claude-loop: passed.').length, 1, r.calls.join('\n'));
  assert.match(r.log, /card-2: push pass skipped: \w+ went out with \S+'s PR, which settled/);
  assert.match(r.log, /card-2: continue/);
  assert.strictEqual(gitIn(s.proj, ['ls-remote', '--heads', 'origin', branch]), '', 'the merged branch is not pushed back');
  assert.doesNotMatch(r.log, /left unsettled|protocol failure/);
});

test('ci: an ungated commit on top of a red PR still blocks the repair, even above a gated one', () => {
  const s = ciSetup([], 1);
  const branch = gitIn(s.proj, ['symbolic-ref', '--short', 'HEAD']);
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'ci setup'], ['push', '-q', '-u', 'origin', 'HEAD']);
  const prHead = head(s);
  fs.writeFileSync(path.join(s.proj, 'gated.txt'), 'g\n');
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'gated']);
  const gated = head(s);
  fs.writeFileSync(path.join(s.proj, 'ungated.txt'), 'u\n');
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'ungated']);
  fs.mkdirSync(path.join(s.proj, '.claude', 'work', 'loop'), { recursive: true });
  fs.writeFileSync(path.join(s.proj, '.claude', 'work', 'loop', 'gated.json'), JSON.stringify([gated]));
  fs.writeFileSync(path.join(s.bin, 'adopt.json'), JSON.stringify([{ number: 1, headRefName: branch, headRefOid: prHead, createdAt: '2026-10-14T00:00:00Z' }]));
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([{ status: 'blocked' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r.log, new RegExp(`Not repaired: ${branch} carries an unpushed commit on top of the PR\\. Stopping`));
  assert.doesNotMatch(r.log, /pushed the gated/);
  assert.deepStrictEqual(r.calls, []);
  assert.strictEqual(gitIn(s.proj, ['rev-parse', `refs/remotes/origin/${branch}`]), prHead, 'nothing was pushed');
});

test('ci: the gated record outlives the run, so a later run adopts the red PR, pushes the held card and repairs it', () => {
  const s = ciSetup([WORK, COMMIT, { status: 'done' }], 1);
  const branch = gitIn(s.proj, ['symbolic-ref', '--short', 'HEAD']);
  gitIn(s.proj, ['add', '-A'], ['commit', '-q', '-m', 'ci setup'], ['push', '-q', '-u', 'origin', 'HEAD']);
  const prHead = head(s);
  // The first run gates and commits a card and stops before its push.
  const r1 = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.match(r1.log, /card-1: done/, r1.log);
  const held = head(s);
  assert.notStrictEqual(held, prHead);
  // The second run finds the PR open and red with that card unpushed on it.
  fs.writeFileSync(path.join(s.bin, 'adopt.json'), JSON.stringify([{ number: 1, headRefName: branch, headRefOid: prHead, createdAt: '2026-10-14T00:00:00Z' }]));
  fs.writeFileSync(path.join(s.bin, 'red.txt'), 'x');
  fs.writeFileSync(path.join(s.bin, 'plan.json'), JSON.stringify([WORK, COMMIT, { status: 'done' }, REPAIR(s.bin), { status: 'done' }]));
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  const log = r.log.slice(r1.log.length);
  assert.match(log, new RegExp(`ci: adopted open PR #1 \\(${branch} at ${prHead.slice(0, 9)}\\)`));
  assert.match(log, new RegExp(`pushed the gated ${held.slice(0, 9)} held on ${branch}; watching CI on it`));
  assert.match(log, /Repair 1 of 2: a fresh pass fixes/);
  assert.ok(log.indexOf('PR #1 merged') > -1 && log.indexOf('PR #1 merged') < log.indexOf('card-1:'), `the adopted PR merges before the first card:\n${log}`);
});

test('ciRepairPrompt: off the PR branch it must come back to the exact commit; on it, stay at the push', () => {
  const s = { number: 9, failed: [{ name: 'lint', link: 'https://x/job/123' }] };
  const off = ciRepairPrompt({ branch: 'card/a', sha: 'aaa1111111' }, s, { branch: 'card/b', sha: 'bbb2222222' }, 'st.json');
  assert.match(off, /- lint: https:\/\/x\/job\/123/);
  assert.match(off, /Check out card\/b again\. It must be at bbb222222/);
  const on = ciRepairPrompt({ branch: 'card/a', sha: 'aaa1111111' }, s, { branch: 'card/a', sha: 'aaa1111111' }, 'st.json');
  assert.match(on, /Stay on card\/a, at the commit you pushed/);
});

test('ci: a push without -u is still queued, watched and merged', () => {
  const s = ciSetup([WORK, COMMIT, PUSH('done', false)], 2);
  const r = runCi(s, ['--no-handoff', '--afk-flags', '--merge-allowed']);
  assert.strictEqual(r.status, 0, r.stderr + r.log);
  assert.match(r.log, /ci: card-1 PR #1 merged/);
  assert.doesNotMatch(r.log, /left unsettled|on no remote branch/);
});

test('runGate: a red suite that prints the busy line without exit 75 is red at once', () => {
  const proj = gitProject(false);
  fs.writeFileSync(path.join(proj, 'suite.cjs'), `console.log(${JSON.stringify(RUNNER_BUSY)});process.exit(1);\n`);
  gitIn(proj, ['add', '-A'], ['commit', '-q', '-m', 'red suite quoting the busy line']);
  const g = runGate(proj, cfgOf(proj), undefined, { busyWaitMs: 20, busyMaxMs: 2000 });
  assert.strictEqual(g.ok, false);
  assert.strictEqual(g.busy, undefined);
  assert.strictEqual(g.busyWaits, undefined);
});
