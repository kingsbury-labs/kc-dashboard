'use strict';
const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const RUNNER = path.join(__dirname, 'project-test.cjs');

const FAILING = [
  '   FAIL  Tests\\Unit\\ZzPlantedFailTest',
  '  x planted one          0.01s',
  '  x planted two',
  '',
  '  ────────────────────',
  '   FAILED  Tests\\Unit\\ZzPlantedFailTest > planted one',
  '  Failed asserting that 2 is identical to 1.',
  '',
  '  at tests\\Unit\\ZzPlantedFailTest.php:11',
  '',
  '  ────────────────────',
  '   FAILED  Tests\\Unit\\ZzPlantedFailTest > planted two',
  '  planted second failure',
  '',
  '  at tests\\Unit\\ZzPlantedFailTest.php:16',
  '',
  '',
  '  Tests:    2 failed, 2 passed (4 assertions)',
  '  Duration: 0.33s',
];

const CANNED = `
const fs = require('fs');
const out = fs.readFileSync(__dirname + '/out.txt', 'utf8');
const code = parseInt(fs.readFileSync(__dirname + '/code.txt', 'utf8'), 10);
process.stdout.write(out);
if (process.argv.length > 2) process.stdout.write('ARGS:' + JSON.stringify(process.argv.slice(2)) + '\\n');
process.exit(code);
`;

const GIT = ['-c', 'user.name=t', '-c', 'user.email=t@example.test'];
function git(dir, ...a) {
  const r = spawnSync('git', GIT.concat(a), { cwd: dir, encoding: 'utf8' });
  assert.strictEqual(r.status, 0, 'git ' + a.join(' ') + ': ' + r.stderr);
  return r.stdout.trim();
}

// A real git repo with one commit. extra: more frontmatter lines.
function project(output, code, extra = []) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pt-'));
  fs.mkdirSync(path.join(dir, '.claude'), { recursive: true });
  fs.writeFileSync(path.join(dir, '.claude', 'project.md'), [
    '---',
    'test_command: node canned.cjs',
    'test_failure_start: ^\\s*FAILED\\s',
    'test_summary: ^\\s*Tests:\\s',
  ].concat(extra, ['---']).join('\n'));
  fs.writeFileSync(path.join(dir, 'canned.cjs'), CANNED);
  fs.writeFileSync(path.join(dir, 'out.txt'), output);
  fs.writeFileSync(path.join(dir, 'code.txt'), String(code));
  fs.mkdirSync(path.join(dir, 'src'));
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'one\n');
  git(dir, 'init', '-q');
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'base');
  return dir;
}

function runner(cwd, args = []) {
  return spawnSync('node', [RUNNER, ...args], { cwd, encoding: 'utf8' });
}
const rec = (d) => JSON.parse(fs.readFileSync(path.join(d, '.claude', 'work', 'last-test-run.json'), 'utf8'));
const logOf = (d) => path.join(d, '.claude', 'work', 'test-last.log');

function longGreen() {
  const l = [];
  for (let i = 0; i < 200; i++) l.push('  ✓ passing test number ' + i);
  l.push('', '  Tests:    200 passed (300 assertions)', '  Duration: 1.00s');
  return l.join('\n') + '\n';
}
function longFailing() {
  const l = [];
  for (let i = 0; i < 100; i++) l.push('  ✓ passing test number ' + i);
  return l.concat(FAILING).join('\n') + '\n';
}

test('no project.md: one line, exit 2', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pt-none-'));
  const r = runner(dir);
  assert.strictEqual(r.status, 2);
  assert.strictEqual(r.stdout.trim().split('\n').length, 1);
  assert.ok(/no \.claude\/project\.md/.test(r.stdout));
});

test('found by walking up from a subfolder', () => {
  const dir = project('ok\n', 0);
  const sub = path.join(dir, 'a', 'b');
  fs.mkdirSync(sub, { recursive: true });
  const r = runner(sub);
  assert.strictEqual(r.status, 0);
  assert.strictEqual(r.stdout, 'ok\n');
  assert.ok(fs.existsSync(logOf(dir)));
});

test('short pass: full output, exit 0, record', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  const r = runner(dir);
  assert.strictEqual(r.status, 0);
  assert.strictEqual(r.stdout, '  Tests:    3 passed\n');
  const x = rec(dir);
  assert.strictEqual(x.exit_code, 0);
  assert.strictEqual(x.filtered, false);
  assert.strictEqual(x.summary, 'Tests:    3 passed');
  assert.strictEqual(x.command, 'node canned.cjs');
});

test('short fail: exit 1 carried through and recorded', () => {
  const dir = project('boom\n', 1);
  const r = runner(dir);
  assert.strictEqual(r.status, 1);
  assert.strictEqual(r.stdout, 'boom\n');
  assert.strictEqual(rec(dir).exit_code, 1);
});

test('long green: summary and path only', () => {
  const dir = project(longGreen(), 0);
  const r = runner(dir);
  assert.strictEqual(r.status, 0);
  assert.ok(r.stdout.includes('Tests:    200 passed'));
  assert.ok(!r.stdout.includes('passing test number'));
  assert.ok(r.stdout.includes('Full output: ' + logOf(dir)));
  assert.strictEqual(rec(dir).filtered, true);
});

test('long with two failures: both blocks, summary, path, exit 1', () => {
  const dir = project(longFailing(), 1);
  const r = runner(dir);
  assert.strictEqual(r.status, 1);
  assert.ok(r.stdout.includes('FAILED  Tests\\Unit\\ZzPlantedFailTest > planted one'));
  assert.ok(r.stdout.includes('Failed asserting that 2 is identical to 1.'));
  assert.ok(r.stdout.includes('FAILED  Tests\\Unit\\ZzPlantedFailTest > planted two'));
  assert.ok(r.stdout.includes('planted second failure'));
  assert.ok(r.stdout.includes('Tests:    2 failed, 2 passed'));
  assert.ok(!r.stdout.includes('passing test number'));
  assert.ok(r.stdout.includes('Full output: ' + logOf(dir)));
  assert.ok(fs.readFileSync(logOf(dir), 'utf8').includes('passing test number 0'));
  assert.strictEqual(rec(dir).exit_code, 1);
});

test('long with no summary: tail, note, path', () => {
  const l = [];
  for (let i = 0; i < 200; i++) l.push('line ' + i);
  const dir = project(l.join('\n') + '\n', 1);
  const r = runner(dir);
  assert.strictEqual(r.status, 1);
  assert.ok(r.stdout.includes('line 199'));
  assert.ok(r.stdout.includes('line 140'));
  assert.ok(!r.stdout.includes('line 139\n'));
  assert.ok(/No summary line/.test(r.stdout));
  assert.ok(r.stdout.includes('200 lines in total'));
  assert.ok(r.stdout.includes(logOf(dir)));
  assert.strictEqual(rec(dir).filtered, false);
});

test('ANSI and CRLF', () => {
  const text = longFailing().split('\n')
    .map((l) => '\u001b[31m' + l + '\u001b[0m').join('\r\n');
  const dir = project(text, 1);
  const r = runner(dir);
  assert.ok(!/\u001b|\r/.test(r.stdout));
  assert.ok(r.stdout.includes('planted second failure'));
  assert.ok(r.stdout.includes('Tests:    2 failed'));
  assert.strictEqual(rec(dir).summary, 'Tests:    2 failed, 2 passed (4 assertions)');
});

test('extra args reach the command, quoted when spaced', () => {
  const dir = project('ok\n', 0);
  const r = runner(dir, ['--filter=Foo', 'two words']);
  assert.strictEqual(r.status, 0);
  assert.ok(r.stdout.includes('ARGS:["--filter=Foo","two words"]'));
  assert.deepStrictEqual(rec(dir).args, ['--filter=Foo', 'two words']);
});

test('filtered_also: a green filtered run is followed by the also filter, and its exit code wins', () => {
  const dir = project('ok\n', 0, ['filtered_also: Scan']);
  const r = runner(dir, ['--filter=Foo']);
  assert.strictEqual(r.status, 0);
  assert.ok(r.stdout.includes('also ran --filter=Scan'));
  const runs = journal(dir);
  assert.deepStrictEqual(runs.map((j) => j.args), [['--filter=Foo'], ['--filter=Scan']]);

  // The second run fails: the runner exits with its code.
  fs.writeFileSync(path.join(dir, 'canned.cjs'), CANNED.replace(
    'process.exit(code);', 'process.exit(process.argv.includes("--filter=Scan") ? 1 : code);'));
  assert.strictEqual(runner(dir, ['--filter', 'Foo']).status, 1);
});

test('filtered_also: not after a failing run, a full run, or the also filter itself', () => {
  const dir = project('ok\n', 1, ['filtered_also: Scan']);
  assert.strictEqual(runner(dir, ['--filter=Foo']).status, 1);
  fs.writeFileSync(path.join(dir, 'code.txt'), '0');
  runner(dir, []);
  runner(dir, ['--filter=Scan']);
  assert.deepStrictEqual(journal(dir).map((j) => j.args), [['--filter=Foo'], [], ['--filter=Scan']]);
});

// ---- done gate ----

const work = (d, f) => path.join(d, '.claude', 'work', f);
const writeRec = (d, patch) => {
  const r = rec(d);
  fs.writeFileSync(work(d, 'last-test-run.json'), JSON.stringify(Object.assign(r, patch)));
};
const journal = (d) => fs.readFileSync(work(d, 'test-runs.jsonl'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));
function withChecklist(d) {
  fs.writeFileSync(work(d, 'checklist.json'), JSON.stringify([
    { id: '01', passes: false }, { id: '02', passes: false },
  ], null, 2) + '\n');
}
const checklist = (d) => fs.readFileSync(work(d, 'checklist.json'), 'utf8');

test('record: finished, with timestamps, tree and a journal line per run', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  const x = rec(dir);
  assert.strictEqual(x.status, 'finished');
  assert.strictEqual(x.exit_code, 0);
  assert.ok(x.started_at && x.finished_at);
  assert.match(x.tree, /^[0-9a-f]{64}$/);
  assert.strictEqual(journal(dir).length, 1);
  runner(dir);
  assert.strictEqual(journal(dir).length, 2);
});

test('verify: a good full run verifies', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 0);
  assert.strictEqual(r.stdout.trim(), 'verified');
});

test('verify: no record fails', () => {
  const dir = project('ok\n', 0);
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /no test run recorded/);
});

test('verify: a running record left by a killed run fails', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  writeRec(dir, { status: 'running', exit_code: null });
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /status is running/);
  assert.match(r.stdout, /exit code is null/);
});

test('verify: a failing run fails', () => {
  const dir = project('boom\n', 1);
  runner(dir);
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /exit code is 1/);
});

test('verify: a partial run (args) fails', () => {
  const dir = project('ok\n', 0);
  runner(dir, ['--filter=Foo']);
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /partial/);
});

test('verify: a tree change after the run fails, tracked or untracked', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  let r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /tree changed/);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'one\n');
  assert.strictEqual(runner(dir, ['--verify']).status, 0);
  fs.writeFileSync(path.join(dir, 'new.txt'), 'x\n');
  r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /tree changed/);
});

test('verify: an old record fails, and verify_max_age_min moves the limit', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  writeRec(dir, { finished_at: new Date(Date.now() - 60 * 60000).toISOString() });
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /60 min ago, limit is 15/);
  const dir2 = project('ok\n', 0, ['verify_max_age_min: 120']);
  runner(dir2);
  writeRec(dir2, { finished_at: new Date(Date.now() - 60 * 60000).toISOString() });
  assert.strictEqual(runner(dir2, ['--verify']).status, 0);
});

test('verify: not a git repo says so and fails', () => {
  const dir = project('ok\n', 0);
  fs.rmSync(path.join(dir, '.git'), { recursive: true, force: true });
  runner(dir);
  assert.strictEqual(rec(dir).tree, null);
  const r = runner(dir, ['--verify']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /not a git repo/);
});

test('pass: flips only on verify, and leaves the file untouched otherwise', () => {
  const dir = project('ok\n', 0);
  runner(dir);
  withChecklist(dir);
  const before = checklist(dir);
  fs.writeFileSync(path.join(dir, 'new.txt'), 'x\n');
  let r = runner(dir, ['--pass', '01']);
  assert.strictEqual(r.status, 1);
  assert.strictEqual(checklist(dir), before);
  fs.rmSync(path.join(dir, 'new.txt'));
  r = runner(dir, ['--pass', '99']);
  assert.strictEqual(r.status, 1);
  assert.strictEqual(checklist(dir), before);
  r = runner(dir, ['--pass', '01']);
  assert.strictEqual(r.status, 0);
  const list = JSON.parse(checklist(dir));
  assert.strictEqual(list[0].passes, true);
  assert.ok(list[0].verified_at);
  assert.strictEqual(list[0].tree, rec(dir).tree);
  assert.strictEqual(list[1].passes, false);
});

function briefIn(dir, name, files) {
  const f = path.join(dir, '.claude', 'work', name, 'brief.md');
  fs.mkdirSync(path.dirname(f), { recursive: true });
  fs.writeFileSync(f, ['# t', '', '## Files you may touch', ''].concat(
    files.map((x) => '- `' + x + '`'), ['', '## Boundaries', '', 'Not `other.txt`.', '']).join('\n'));
  return f;
}

function briefCard(dir, files) {
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '07-card', files);
  fs.writeFileSync(work(dir, 'checklist.json'), JSON.stringify([
    { id: '07', brief: '.claude/work/07-card/brief.md', start_sha: sha, passes: false },
  ], null, 2) + '\n');
  return { sha, brief };
}

test('pass: a card with a brief is refused until check-scope passed for it', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  runner(dir);
  let r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /no passing --check-scope/);
  assert.strictEqual(JSON.parse(checklist(dir))[0].passes, false);
  r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(r.stdout, /recorded on 07/);
  r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 0, r.stdout);
  const e = JSON.parse(checklist(dir))[0];
  assert.strictEqual(e.passes, true);
  assert.strictEqual(e.scope_ok.start_sha, sha);
});

test('pass: a failed scope check records nothing, and a scope from another sha does not count', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'stray.txt'), 'x\n');
  let r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.strictEqual(JSON.parse(checklist(dir))[0].scope_ok, undefined);
  fs.rmSync(path.join(dir, 'stray.txt'));
  const list = JSON.parse(checklist(dir));
  list[0].scope_ok = { at: new Date().toISOString(), start_sha: 'deadbeef', mutation_evidence: false };
  fs.writeFileSync(work(dir, 'checklist.json'), JSON.stringify(list));
  runner(dir);
  r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /no passing --check-scope/);
});

test('check-scope: a short start sha is resolved and recorded in full, and --pass accepts it', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  runner(dir);
  let r = runner(dir, ['--check-scope', brief, sha.slice(0, 7)]);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(r.stdout, /recorded on 07/);
  assert.strictEqual(JSON.parse(checklist(dir))[0].scope_ok.start_sha, sha);
  r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.strictEqual(JSON.parse(checklist(dir))[0].passes, true);
});

test('pass: a short start_sha in the checklist still matches the full one in the scope record', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  runner(dir);
  assert.strictEqual(runner(dir, ['--check-scope', brief, sha]).status, 0);
  const list = JSON.parse(checklist(dir));
  list[0].start_sha = sha.slice(0, 9);
  fs.writeFileSync(work(dir, 'checklist.json'), JSON.stringify(list));
  runner(dir);
  const r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.strictEqual(JSON.parse(checklist(dir))[0].passes, true);
});

test('check-scope: an unknown or option-like start sha is refused and records nothing', () => {
  const dir = project('ok\n', 0);
  const { brief } = briefCard(dir, ['src/a.txt']);
  for (const bad of ['deadbeef0', '--help', 'not-a-ref']) {
    const r = runner(dir, ['--check-scope', brief, bad]);
    assert.strictEqual(r.status, 1, bad + ': ' + r.stdout);
    assert.match(r.stdout, /project-test: (cannot resolve start sha|not a commit)/);
    assert.doesNotMatch(r.stdout, /scope: ok/);
    assert.strictEqual(JSON.parse(checklist(dir))[0].scope_ok, undefined);
  }
});

test('pass: a start_sha in the checklist that resolves to nothing is refused, never matched as a string', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  runner(dir);
  assert.strictEqual(runner(dir, ['--check-scope', brief, sha]).status, 0);
  const list = JSON.parse(checklist(dir));
  list[0].start_sha = 'deadbeef0';
  list[0].scope_ok.start_sha = 'deadbeef0';
  fs.writeFileSync(work(dir, 'checklist.json'), JSON.stringify(list));
  runner(dir);
  const r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /cannot resolve start sha/);
  assert.strictEqual(JSON.parse(checklist(dir))[0].passes, false);
});

test('check-scope: a scope_ignore file is never judged, any other unlisted file still is', () => {
  const dir = project('ok\n', 0, ['scope_ignore: local.json, other/keep.txt']);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '08', ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'local.json'), '{}\n');
  let r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  fs.writeFileSync(path.join(dir, 'stray.txt'), 'x\n');
  r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /outside the brief: stray\.txt/);
  assert.doesNotMatch(r.stdout, /local\.json/);
});

test('check-scope: a scope_ignore folder skips everything under it, and only that', () => {
  const dir = project('ok\n', 0, ['scope_ignore: notes/pending/']);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '09', ['src/a.txt']);
  fs.mkdirSync(path.join(dir, 'notes', 'pending'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'notes', 'pending', 'p1.md'), 'x\n');
  let r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  fs.writeFileSync(path.join(dir, 'notes', 'other.md'), 'x\n');
  r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /outside the brief: notes\/other\.md/);
});

test('check-scope: listed files pass, relative or absolute, slashes and case either way', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const abs = path.join(dir, 'new.txt');
  const mangled = process.platform === 'win32' ? abs.toUpperCase().replace(/\\/g, '/') : abs;
  const brief = briefIn(dir, '01', ['src\\a.txt', mangled]);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  fs.writeFileSync(path.join(dir, 'new.txt'), 'x\n');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(r.stdout, /scope: ok/);
});

test('check-scope: a listed folder (trailing slash) covers what is inside it, and only that', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['views\\setup\\']);
  fs.mkdirSync(path.join(dir, 'views', 'setup', 'deep'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'views', 'setup', 'deep', 'a.txt'), 'x\n');
  assert.strictEqual(runner(dir, ['--check-scope', brief, sha]).status, 0);
  fs.mkdirSync(path.join(dir, 'views', 'setup-other'));
  fs.writeFileSync(path.join(dir, 'views', 'setup-other', 'b.txt'), 'x\n');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /outside the brief: views\/setup-other\/b\.txt/);
});

test('check-scope: an unlisted untracked file fails, backticks outside the section are ignored', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'other.txt'), 'x\n');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /outside the brief: other\.txt/);
});

test('check-scope: a tracked file changed outside the list fails', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['new.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /outside the brief: src\/a\.txt/);
});

test('check-scope: a commit by the worker fails even when every path is listed', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  git(dir, 'commit', '-q', '-am', 'worker commit');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /the worker committed: 1 commit/);
});

test('check-scope: paths outside the project are reported, not judged', () => {
  const outer = project('ok\n', 0);
  const dir = path.join(outer, 'inner');
  fs.mkdirSync(path.join(dir, '.claude'), { recursive: true });
  fs.writeFileSync(path.join(dir, '.claude', 'project.md'), '---\ntest_command: node ../canned.cjs\n---\n');
  git(outer, 'add', '-A');
  git(outer, 'commit', '-q', '-m', 'inner');
  const sha = git(outer, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['in.txt']);
  fs.writeFileSync(path.join(dir, 'in.txt'), 'x\n');
  fs.writeFileSync(path.join(outer, 'sibling.txt'), 'x\n');
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(r.stdout, /outside the project, not judged: .*sibling\.txt/);
});

test('check-scope: mutation evidence is yes after a fail then a pass, else no', () => {
  const dir = project('ok\n', 0);
  const sha = git(dir, 'rev-parse', 'HEAD');
  const brief = briefIn(dir, '01', ['code.txt']);
  runner(dir);
  let r = runner(dir, ['--check-scope', brief, sha]);
  assert.match(r.stdout, /mutation evidence: no/);
  fs.writeFileSync(path.join(dir, 'code.txt'), '1');
  runner(dir);
  r = runner(dir, ['--check-scope', brief, sha]);
  assert.match(r.stdout, /mutation evidence: no/);
  fs.writeFileSync(path.join(dir, 'code.txt'), '0');
  runner(dir);
  r = runner(dir, ['--check-scope', brief, sha]);
  assert.match(r.stdout, /mutation evidence: yes/);
  assert.strictEqual(r.status, 0);
});

test('check-scope: runs from before the brief folder existed do not count', () => {
  const dir = project('boom\n', 1);
  const sha = git(dir, 'rev-parse', 'HEAD');
  runner(dir);
  fs.writeFileSync(path.join(dir, 'code.txt'), '0');
  runner(dir);
  const brief = briefIn(dir, '01', ['code.txt']);
  const r = runner(dir, ['--check-scope', brief, sha]);
  assert.match(r.stdout, /mutation evidence: no/);
});

test('args: syntax characters are refused with exit 2 and nothing runs', () => {
  const dir = project('ok\n', 0);
  for (const bad of ['a|b', '50%', 'a&b', 'a<b', 'a>b', 'a^b', 'a"b']) {
    const r = runner(dir, [bad]);
    assert.strictEqual(r.status, 2, bad);
    assert.strictEqual(r.stdout.trim().split('\n').length, 1);
  }
  assert.ok(!fs.existsSync(work(dir, 'last-test-run.json')));
});

test('args: all args are double-quoted, a trailing backslash survives', () => {
  const dir = project('ok\n', 0);
  const r = runner(dir, ['plain', 'C:\\some dir\\']);
  assert.strictEqual(r.status, 0);
  assert.ok(r.stdout.includes('ARGS:["plain","C:\\\\some dir\\\\"]'), r.stdout);
  assert.strictEqual(rec(dir).command, 'node canned.cjs "plain" "C:\\some dir\\\\"');
});

test('timeout: a child past test_timeout_s is killed with its tree, exit 124, timed_out', () => {
  const dir = project('ok\n', 0);
  fs.writeFileSync(path.join(dir, 'sleeper.cjs'),
    "require('fs').writeFileSync(__dirname + '/pid.txt', String(process.pid));\nsetTimeout(() => {}, 60000);\n");
  fs.writeFileSync(path.join(dir, '.claude', 'project.md'),
    '---\ntest_command: node sleeper.cjs\ntest_timeout_s: 2\n---\n');
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'sleeper');
  const t0 = Date.now();
  const r = runner(dir);
  assert.strictEqual(r.status, 124);
  assert.ok(Date.now() - t0 < 30000);
  assert.strictEqual(rec(dir).status, 'timed_out');
  assert.strictEqual(rec(dir).exit_code, 124);
  assert.match(r.stdout, /timed out after 2 s/);
  const pid = parseInt(fs.readFileSync(path.join(dir, 'pid.txt'), 'utf8'), 10);
  let alive = true;
  try { process.kill(pid, 0); } catch (e) { alive = false; }
  assert.strictEqual(alive, false, 'grandchild ' + pid + ' still running');
  assert.strictEqual(journal(dir).length, 1);
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});

test('decoding: multi-byte characters survive across chunks', () => {
  const dir = project('', 0);
  fs.writeFileSync(path.join(dir, 'canned.cjs'), [
    "const b = Buffer.from('é✓', 'utf8');",
    'process.stdout.write(b.subarray(0, 1));',
    "setTimeout(() => { process.stdout.write(b.subarray(1)); process.stdout.write('\\n'); }, 300);",
  ].join('\n'));
  const r = runner(dir);
  assert.strictEqual(r.stdout, 'é✓\n');
});

// ---- scope tied to the tree, and the fingerprint the loop compares ----

const { treeFingerprint, dirtyPaths, loadConfig } = require('./project-test.cjs');

test('pass: a file added after check-scope is refused until the scope is checked again', () => {
  const dir = project('ok\n', 0);
  const { sha, brief } = briefCard(dir, ['src/a.txt']);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  let r = runner(dir, ['--check-scope', brief, sha]);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(JSON.parse(checklist(dir))[0].scope_ok.tree, /^[0-9a-f]{64}$/);
  fs.writeFileSync(path.join(dir, 'late.txt'), 'x\n');
  runner(dir);
  r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /tree changed since --check-scope passed for 07/);
  assert.strictEqual(JSON.parse(checklist(dir))[0].passes, false);
  // The scope check now sees late.txt and fails, so it records nothing.
  assert.strictEqual(runner(dir, ['--check-scope', brief, sha]).status, 1);
  fs.rmSync(path.join(dir, 'late.txt'));
  runner(dir);
  assert.strictEqual(runner(dir, ['--pass', '07']).status, 0);
});

test('pass: a scope record with no tree (written before trees were recorded) is refused', () => {
  const dir = project('ok\n', 0);
  const { sha } = briefCard(dir, ['src/a.txt']);
  const list = JSON.parse(checklist(dir));
  list[0].scope_ok = { at: new Date().toISOString(), start_sha: sha, mutation_evidence: true };
  fs.writeFileSync(work(dir, 'checklist.json'), JSON.stringify(list));
  runner(dir);
  const r = runner(dir, ['--pass', '07']);
  assert.strictEqual(r.status, 1);
  assert.match(r.stdout, /tree changed since --check-scope/);
});

test('fingerprint: the board, the test log and scope_ignore entries do not count; code does', () => {
  const dir = project('ok\n', 0, ['board: docs/BOARD.md', 'scope_ignore: local.json, notes/']);
  fs.mkdirSync(path.join(dir, 'docs'));
  fs.writeFileSync(path.join(dir, 'docs', 'BOARD.md'), 'Now: 07\n');
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'board');
  const cfg = loadConfig(dir);
  const base = treeFingerprint(dir, cfg);
  fs.writeFileSync(path.join(dir, 'docs', 'BOARD.md'), 'Done: 07\n');
  fs.writeFileSync(path.join(dir, 'local.json'), '{}\n');
  fs.mkdirSync(path.join(dir, 'notes'));
  fs.writeFileSync(path.join(dir, 'notes', 'n.md'), 'x\n');
  assert.strictEqual(treeFingerprint(dir, cfg), base);
  runner(dir);
  assert.strictEqual(runner(dir, ['--verify']).status, 0);
  fs.writeFileSync(path.join(dir, 'docs', 'BOARD.md'), 'Done: 07, 08\n');
  assert.strictEqual(runner(dir, ['--verify']).status, 0, 'a board move after the run still verifies');
  fs.writeFileSync(path.join(dir, 'src', 'b.txt'), 'new\n');
  assert.notStrictEqual(treeFingerprint(dir, cfg), base);
});

test('fingerprint: a commit of exactly the tested tree matches it, a partial commit does not', () => {
  const dir = project('ok\n', 0, ['board: BOARD.md']);
  const cfg = loadConfig(dir);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  fs.writeFileSync(path.join(dir, 'src', 'new.txt'), 'new\n');
  const tested = treeFingerprint(dir, cfg);
  // Only a.txt is staged: the commit leaves new.txt out.
  git(dir, 'add', 'src/a.txt');
  git(dir, 'commit', '-q', '-m', 'partial');
  assert.notStrictEqual(treeFingerprint(dir, cfg, 'HEAD'), tested);
  assert.strictEqual(treeFingerprint(dir, cfg), tested, 'the working tree is still the tested one');
  fs.writeFileSync(path.join(dir, 'BOARD.md'), 'moved\n');
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'the rest, with the board');
  assert.strictEqual(treeFingerprint(dir, cfg, 'HEAD'), tested);
});

test('fingerprint: the real index is left as it was', () => {
  const dir = project('ok\n', 0);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  fs.writeFileSync(path.join(dir, 'untracked.txt'), 'x\n');
  const before = git(dir, 'status', '--porcelain');
  treeFingerprint(dir, loadConfig(dir));
  assert.strictEqual(git(dir, 'status', '--porcelain'), before);
});

test('dirtyPaths: reports changes, skips the runner records, the loop folder and scope_ignore', () => {
  const dir = project('ok\n', 0, ['scope_ignore: local.json, notes/']);
  const cfg = loadConfig(dir);
  runner(dir);
  fs.mkdirSync(work(dir, 'loop'), { recursive: true });
  fs.writeFileSync(work(dir, 'loop/status.json'), '{}');
  fs.writeFileSync(path.join(dir, 'local.json'), '{}\n');
  fs.mkdirSync(path.join(dir, 'notes', 'deep'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'notes', 'deep', 'n.md'), 'x\n');
  assert.deepStrictEqual(dirtyPaths(dir, cfg), []);
  fs.writeFileSync(path.join(dir, 'src', 'a.txt'), 'two\n');
  fs.writeFileSync(work(dir, 'checklist.json'), '[]');
  assert.deepStrictEqual(dirtyPaths(dir, cfg), ['.claude/work/checklist.json', 'src/a.txt']);
  const plain = fs.mkdtempSync(path.join(os.tmpdir(), 'pt-nogit-'));
  assert.strictEqual(dirtyPaths(plain, cfg), null);
});

// ---- One run at a time per project -----------------------------------------

const pt = require('./project-test.cjs');

test('lock: a second run is refused with exit 75 and leaves the first run\'s record alone', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  assert.strictEqual(runner(dir).status, 0);
  const before = fs.readFileSync(path.join(dir, '.claude', 'work', 'last-test-run.json'), 'utf8');
  const release = pt.acquireLock(dir, pt.loadConfig(dir));
  assert.ok(release);
  const r = runner(dir);
  assert.strictEqual(r.status, 75);
  assert.strictEqual(r.stdout.trim(), pt.BUSY);
  assert.strictEqual(fs.readFileSync(path.join(dir, '.claude', 'work', 'last-test-run.json'), 'utf8'), before);
  release();
  assert.strictEqual(fs.existsSync(pt.lockPath(dir)), false);
  assert.strictEqual(runner(dir).status, 0);
});

test('lock: released after a run, red or green', () => {
  const dir = project(FAILING.join('\n') + '\n', 1);
  assert.strictEqual(runner(dir).status, 1);
  assert.strictEqual(fs.existsSync(pt.lockPath(dir)), false);
});

test('lock: one held by a process that is gone is taken over', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  const dead = spawnSync(process.execPath, ['-e', '0']).pid;
  fs.mkdirSync(path.dirname(pt.lockPath(dir)), { recursive: true });
  fs.writeFileSync(pt.lockPath(dir), JSON.stringify({ pid: dead, started_at: new Date().toISOString() }));
  assert.strictEqual(runner(dir).status, 0);
});

test('lock: one older than the timeout is taken over even if its pid is alive', () => {
  const dir = project('  Tests:    3 passed\n', 0, ['test_timeout_s: 10']);
  fs.mkdirSync(path.dirname(pt.lockPath(dir)), { recursive: true });
  fs.writeFileSync(pt.lockPath(dir), JSON.stringify({ pid: process.pid, started_at: new Date(Date.now() - 3600e3).toISOString() }));
  assert.strictEqual(runner(dir).status, 0);
});

test('lock: --verify and --pass do not take the lock', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  assert.strictEqual(runner(dir).status, 0);
  const release = pt.acquireLock(dir, pt.loadConfig(dir));
  assert.strictEqual(runner(dir, ['--verify']).status, 0);
  release();
});

test('lock: the lock file never makes the tree dirty', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  fs.writeFileSync(path.join(dir, '.gitignore'), '');
  const release = pt.acquireLock(dir, pt.loadConfig(dir));
  assert.ok(!pt.dirtyPaths(dir, pt.loadConfig(dir)).includes('.claude/work/test-run.lock'));
  release();
});

test('lock: a live run past one timeout is still busy (filtered_also runs twice)', () => {
  const dir = project('  Tests:    3 passed\n', 0, ['test_timeout_s: 10']);
  fs.mkdirSync(path.dirname(pt.lockPath(dir)), { recursive: true });
  fs.writeFileSync(pt.lockPath(dir), JSON.stringify({ pid: process.pid, started_at: new Date(Date.now() - 100e3).toISOString() }));
  assert.strictEqual(runner(dir).status, 75);
});

test('lock: a takeover that finds a fresh lock moved aside puts it back and counts as busy', () => {
  const dir = project('  Tests:    3 passed\n', 0);
  const cfg = pt.loadConfig(dir);
  const file = pt.lockPath(dir);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const dead = spawnSync(process.execPath, ['-e', '0']).pid;
  fs.writeFileSync(file, JSON.stringify({ pid: dead, started_at: new Date().toISOString() }));
  // Another runner replaces the stale lock between this one's read and rename.
  const fresh = JSON.stringify({ pid: process.pid, started_at: new Date().toISOString(), other: true });
  const realRead = fs.readFileSync;
  let reads = 0;
  fs.readFileSync = function (p, ...rest) {
    const out = realRead.call(fs, p, ...rest);
    if (p === file && ++reads === 1) fs.writeFileSync(file, fresh);
    return out;
  };
  try {
    assert.strictEqual(pt.acquireLock(dir, cfg), null);
  } finally { fs.readFileSync = realRead; }
  assert.strictEqual(fs.readFileSync(file, 'utf8'), fresh);
});

// ---- Sharded full runs --------------------------------------------------------

// A list command that prints every file under tests/ as an absolute path, in
// PHPUnit's --list-test-files shape, with a noise line first.
const LIST = `
const fs = require('fs');
const path = require('path');
if (fs.existsSync(__dirname + '/list-fail.txt')) { console.log('list broke'); process.exit(4); }
console.log('Available test files:');
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) =>
  e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]);
const dir = path.join(__dirname, 'tests');
if (fs.existsSync(dir)) for (const f of walk(dir).sort()) console.log(' - ' + f);
`;

// A shard: records which files it got under which SHARD, then passes, fails,
// crashes, sleeps or waits per file content. A shard with no files says so and
// fails, because a real test command given no files runs the whole suite.
const SHARD = `
const fs = require('fs');
const args = process.argv.slice(2);
if (args.some((a) => a.startsWith('--'))) { console.log('FILTERED ' + args.join(' ')); process.exit(0); }
const n = process.env.SHARD;
fs.appendFileSync(__dirname + '/ran-' + n + '.txt', args.join('\\n') + '\\n');
if (!args.length) { console.log('NO FILES, the whole suite would run'); process.exit(3); }
let failed = 0, passed = 0;
const blocks = [];
for (let i = 0; i < 15; i++) console.log('  progress line ' + n + '-' + i);
function finish() {
  console.log(blocks.join('\\n'));
  console.log('  Tests:    ' + (failed ? failed + ' failed, ' : '') + passed + ' passed');
  console.log('  Duration: 0.1s');
  process.exit(failed ? 1 : 0);
}
for (const f of args) {
  const t = fs.readFileSync(f, 'utf8');
  if (t.includes('CRASH')) { console.log('Fatal error in ' + f); process.exit(255); }
  if (t.includes('SLEEP')) {
    fs.writeFileSync(__dirname + '/pid-' + n + '.txt', String(process.pid));
    setTimeout(() => {}, 60000);
    return;
  }
  if (t.includes('WAIT')) {
    fs.writeFileSync(__dirname + '/started.txt', n);
    const go = setInterval(() => { if (fs.existsSync(__dirname + '/go.txt')) { clearInterval(go); passed++; finish(); } }, 50);
    return;
  }
  if (t.includes('FAIL')) { failed++; blocks.push('   FAILED  ' + f + ' > planted', '  planted failure in ' + f, ''); } else passed++;
}
finish();
`;

const SETUP = `
const fs = require('fs');
const busy = __dirname + '/setup-busy.txt';
if (fs.existsSync(busy)) fs.writeFileSync(__dirname + '/setup-overlap.txt', process.env.SHARD);
fs.writeFileSync(busy, process.env.SHARD);
fs.appendFileSync(__dirname + '/setup-' + process.env.SHARD + '.txt', 'setup\\n');
setTimeout(() => {
  fs.rmSync(busy, { force: true });
  if (fs.existsSync(__dirname + '/setup-fail-' + process.env.SHARD + '.txt')) { console.log('setup broke in shard ' + process.env.SHARD); process.exit(7); }
}, 300);
`;

// files: { 'tests/a.test': 'ok', ... }. extra: more frontmatter lines.
function shardProject(files, extra = []) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pt-shard-'));
  fs.mkdirSync(path.join(dir, '.claude'), { recursive: true });
  fs.writeFileSync(path.join(dir, '.claude', 'project.md'), [
    '---',
    'test_command: node shard.cjs',
    'test_failure_start: ^\\s*FAILED\\s',
    'test_summary: ^\\s*(Tests|Duration):\\s',
    'test_output_max_lines: 20',
    'test_shards: 3',
    'test_shard_list: node list.cjs',
    'test_shard_env: SHARD={n}, OTHER=x{n}y',
  ].concat(extra, ['---']).join('\n'));
  fs.writeFileSync(path.join(dir, 'list.cjs'), LIST);
  fs.writeFileSync(path.join(dir, 'shard.cjs'), SHARD);
  fs.writeFileSync(path.join(dir, 'setup.cjs'), SETUP);
  // What the fixture writes while it runs is not part of the tree under test.
  fs.writeFileSync(path.join(dir, '.gitignore'), ['ran-*', 'pid-*', 'setup-*.txt', 'env-*', 'started.txt', 'go.txt', 'list-fail.txt', ''].join('\n'));
  for (const [f, body] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(dir, f)), { recursive: true });
    fs.writeFileSync(path.join(dir, f), body);
  }
  git(dir, 'init', '-q');
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'base');
  return dir;
}

function nineFiles(over = {}) {
  const f = {};
  for (const x of ['a', 'b', 'c', 'd', 'e', 'f']) f['tests/' + x + '.test'] = 'ok ' + x;
  f['tests/group/g1.test'] = 'ok g1';
  f['tests/group/g2.test'] = 'ok g2';
  f['tests/group/g3.test'] = 'ok g3';
  return Object.assign(f, over);
}

// What each shard was given, from the ran-<n>.txt files the fixture writes.
function ranByShard(dir) {
  const out = {};
  for (const f of fs.readdirSync(dir)) {
    const m = /^ran-(.+)\.txt$/.exec(f);
    if (m) out[m[1]] = fs.readFileSync(path.join(dir, f), 'utf8').split('\n').filter(Boolean).map((p) => p.split('\\').join('/'));
  }
  return out;
}

test('shards: assignment puts every file in exactly one shard, keeps a together group whole, never makes an empty shard', () => {
  const files = ['t/a', 't/b', 't/c', 't/d', 't/ops/x', 't/ops/y', 't/ops/z', 't/e'];
  const shards = pt.assignShards(files, 3, ['t/ops/'], () => 1);
  assert.strictEqual(shards.length, 3);
  assert.deepStrictEqual(shards.flat().sort(), files.slice().sort());
  assert.ok(shards.every((s) => s.length > 0));
  assert.strictEqual(shards.filter((s) => s.includes('t/ops/x')).length, 1);
  const ops = shards.find((s) => s.includes('t/ops/x'));
  assert.ok(ops.includes('t/ops/y') && ops.includes('t/ops/z'));
  assert.deepStrictEqual(pt.checkAssignment(files, shards), []);
  // Same input, same answer.
  assert.deepStrictEqual(pt.assignShards(files, 3, ['t/ops/'], () => 1), shards);
  // Fewer files than shards: fewer shards, none empty.
  const two = pt.assignShards(['t/a', 't/b'], 4, [], () => 1);
  assert.strictEqual(two.length, 2);
  assert.ok(two.every((s) => s.length === 1));
  // The checker catches a missing, a doubled and a stray file, and an empty shard.
  assert.match(pt.checkAssignment(['a', 'b'], [['a']]).join('\n'), /not in any shard: b/);
  assert.match(pt.checkAssignment(['a'], [['a'], ['a']]).join('\n'), /in 2 shards: a/);
  assert.match(pt.checkAssignment(['a'], [['a', 'z']]).join('\n'), /not listed: z/);
  assert.match(pt.checkAssignment(['a'], [['a'], []]).join('\n'), /shard 2 is empty/);
});

test('shards: a shard command too long for the shell, or a file name the shell would read, is refused', () => {
  const cfg = pt.loadConfig(shardProject(nineFiles()));
  assert.strictEqual(pt.shardCommand(cfg, ['tests/a.test', 'tests/b c.test']).command, 'node shard.cjs "tests/a.test" "tests/b c.test"');
  const many = [];
  for (let i = 0; i < 3000; i++) many.push('tests/Feature/Some/Deep/Folder/File' + i + 'Test.php');
  assert.match(pt.shardCommand(cfg, many).error, /characters.*raise test_shards/);
  assert.match(pt.shardCommand(cfg, ['tests/50%.test']).error, /refused file name/);
});

test('shards: a green sharded run gives every listed file to exactly one shard, each with its own env, and verifies', () => {
  const dir = shardProject(nineFiles(), ['test_shard_together: tests/group/']);
  const r = runner(dir);
  assert.strictEqual(r.status, 0, r.stdout);
  const ran = ranByShard(dir);
  assert.deepStrictEqual(Object.keys(ran).sort(), ['1', '2', '3']);
  const all = Object.values(ran).flat();
  assert.deepStrictEqual(all.slice().sort(), Object.keys(nineFiles()).sort());
  assert.strictEqual(new Set(all).size, all.length, 'no file ran twice');
  assert.strictEqual(Object.values(ran).filter((l) => l.some((p) => p.startsWith('tests/group/'))).length, 1);
  const x = rec(dir);
  assert.strictEqual(x.status, 'finished');
  assert.strictEqual(x.exit_code, 0);
  assert.deepStrictEqual(x.args, []);
  assert.strictEqual(x.shards.length, 3);
  assert.deepStrictEqual(x.shards.map((s) => s.exit_code), [0, 0, 0]);
  assert.strictEqual(x.shards.reduce((a, s) => a + s.files, 0), 9);
  assert.match(r.stdout, /Tests: {4}\d passed/);
  assert.doesNotMatch(r.stdout, /progress line/);
  assert.match(r.stdout, /3 shards, all passed/);
  assert.strictEqual(journal(dir).length, 1);
  assert.strictEqual(runner(dir, ['--verify']).status, 0);
});

test('shards: the {n} placeholder reaches each shard as its own value', () => {
  const dir = shardProject(nineFiles());
  fs.writeFileSync(path.join(dir, 'shard.cjs'), "require('fs').writeFileSync(__dirname + '/env-' + process.env.SHARD + '.txt', process.env.OTHER); console.log('  Tests:    1 passed');");
  git(dir, 'add', '-A');
  git(dir, 'commit', '-q', '-m', 'env');
  assert.strictEqual(runner(dir).status, 0);
  for (const n of [1, 2, 3]) assert.strictEqual(fs.readFileSync(path.join(dir, 'env-' + n + '.txt'), 'utf8'), 'x' + n + 'y');
});

test('shards: one failing shard fails the run, its failure block is printed, and --verify refuses', () => {
  const dir = shardProject(nineFiles({ 'tests/d.test': 'FAIL here' }));
  const r = runner(dir);
  assert.strictEqual(r.status, 1, r.stdout);
  assert.match(r.stdout, /FAILED {2}.*d\.test > planted/);
  assert.match(r.stdout, /planted failure in .*d\.test/);
  assert.match(r.stdout, /Tests: {4}1 failed/);
  assert.doesNotMatch(r.stdout, /progress line/);
  assert.match(r.stdout, /Full output: /);
  assert.match(r.stdout, /1 of 3 shards failed/);
  assert.strictEqual(rec(dir).exit_code, 1);
  assert.ok(fs.readFileSync(logOf(dir), 'utf8').includes('progress line 1-0'), 'the log keeps everything');
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});

test('shards: a shard that crashes without a summary fails the run and its tail is printed', () => {
  const dir = shardProject(nineFiles({ 'tests/e.test': 'CRASH' }));
  const r = runner(dir);
  assert.strictEqual(r.status, 255, r.stdout);
  assert.match(r.stdout, /Fatal error in .*e\.test/);
  assert.match(r.stdout, /printed no summary line/);
  assert.strictEqual(rec(dir).exit_code, 255);
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});

test('shards: a failed setup fails its shard and that shard\'s tests never run', () => {
  const dir = shardProject(nineFiles(), ['test_shard_setup: node setup.cjs']);
  fs.writeFileSync(path.join(dir, 'setup-fail-2.txt'), 'x');
  const r = runner(dir);
  assert.strictEqual(r.status, 7, r.stdout);
  assert.match(r.stdout, /setup broke in shard 2/);
  assert.match(r.stdout, /shard 2 of 3: exit 7.*setup failed/);
  const ran = ranByShard(dir);
  assert.ok(!ran['2'], 'shard 2 ran tests after its setup failed');
  assert.ok(ran['1'] && ran['3']);
  for (const n of [1, 2, 3]) assert.ok(fs.existsSync(path.join(dir, 'setup-' + n + '.txt')));
  assert.strictEqual(rec(dir).exit_code, 7);
});

test('shards: setups run one at a time, never two at once', () => {
  // Sembr, 2026-10-22: four migrate:fresh at once failed two shards with
  // "tuple concurrently updated" on ALTER ROLE, a role being cluster-wide.
  const dir = shardProject(nineFiles(), ['test_shard_setup: node setup.cjs']);
  const r = runner(dir);
  assert.strictEqual(r.status, 0, r.stdout);
  for (const n of [1, 2, 3]) assert.ok(fs.existsSync(path.join(dir, 'setup-' + n + '.txt')));
  assert.ok(!fs.existsSync(path.join(dir, 'setup-overlap.txt')), 'two setups ran at once');
});

test('shards: a list command that fails, or lists nothing, fails the run and starts no shard', () => {
  const dir = shardProject(nineFiles());
  fs.writeFileSync(path.join(dir, 'list-fail.txt'), 'x');
  let r = runner(dir);
  assert.strictEqual(r.status, 4, r.stdout);
  assert.match(r.stdout, /test_shard_list/);
  assert.deepStrictEqual(ranByShard(dir), {});
  assert.strictEqual(rec(dir).status, 'finished');
  assert.strictEqual(rec(dir).exit_code, 4);
  fs.rmSync(path.join(dir, 'list-fail.txt'));
  fs.rmSync(path.join(dir, 'tests'), { recursive: true });
  r = runner(dir);
  assert.strictEqual(r.status, 1, r.stdout);
  assert.match(r.stdout, /listed no test files/);
  assert.deepStrictEqual(ranByShard(dir), {});
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});

test('shards: test_shards without test_shard_list is refused before anything runs', () => {
  const dir = project('ok\n', 0, ['test_shards: 4']);
  const r = runner(dir);
  assert.strictEqual(r.status, 2);
  assert.match(r.stdout, /test_shard_list/);
  assert.ok(!fs.existsSync(work(dir, 'last-test-run.json')));
});

test('shards: past test_timeout_s every shard is killed, exit 124, timed_out', () => {
  const dir = shardProject(nineFiles({ 'tests/a.test': 'SLEEP' }), ['test_timeout_s: 3']);
  const t0 = Date.now();
  const r = runner(dir);
  assert.strictEqual(r.status, 124, r.stdout);
  assert.ok(Date.now() - t0 < 30000);
  assert.strictEqual(rec(dir).status, 'timed_out');
  assert.strictEqual(rec(dir).exit_code, 124);
  assert.match(r.stdout, /timed out after 3 s/);
  const pidFile = fs.readdirSync(dir).find((f) => /^pid-/.test(f));
  const pid = parseInt(fs.readFileSync(path.join(dir, pidFile), 'utf8'), 10);
  let alive = true;
  try { process.kill(pid, 0); } catch (e) { alive = false; }
  assert.strictEqual(alive, false, 'shard ' + pid + ' still running');
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});

test('shards: the project lock is held for the whole sharded run', async () => {
  const { spawn } = require('child_process');
  const dir = shardProject(nineFiles({ 'tests/a.test': 'WAIT' }));
  const first = spawn('node', [RUNNER], { cwd: dir });
  let out = '';
  first.stdout.on('data', (d) => { out += d; });
  const closed = new Promise((res) => first.on('close', res));
  const t0 = Date.now();
  while (!fs.existsSync(path.join(dir, 'started.txt'))) {
    assert.ok(Date.now() - t0 < 30000, 'the waiting shard never started');
    await new Promise((res) => setTimeout(res, 50));
  }
  assert.ok(fs.existsSync(pt.lockPath(dir)));
  const second = runner(dir);
  assert.strictEqual(second.status, 75);
  assert.strictEqual(second.stdout.trim(), pt.BUSY);
  fs.writeFileSync(path.join(dir, 'go.txt'), 'x');
  assert.strictEqual(await closed, 0, out);
  assert.strictEqual(fs.existsSync(pt.lockPath(dir)), false);
  assert.strictEqual(journal(dir).length, 1);
});

test('shards: a run with args is a partial run and is not sharded', () => {
  const dir = shardProject(nineFiles());
  const r = runner(dir, ['--filter=Foo']);
  assert.strictEqual(r.status, 0, r.stdout);
  assert.match(r.stdout, /FILTERED --filter=Foo/);
  assert.deepStrictEqual(ranByShard(dir), {});
  assert.strictEqual(rec(dir).shards, undefined);
  assert.strictEqual(runner(dir, ['--verify']).status, 1);
});
