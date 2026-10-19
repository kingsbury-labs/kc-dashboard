#!/usr/bin/env node
/**
 * Project Test Runner
 *
 * Usage: node project-test.cjs [args...]
 *        node project-test.cjs --verify
 *        node project-test.cjs --pass <id>
 *        node project-test.cjs --check-scope <brief.md> <start-sha>
 *
 * WHY THIS EXISTS. A PostToolUse hook never sees a failing command (it goes to
 * PostToolUseFailure with no tool_response), so a hook cannot shorten or record
 * the runs that matter. A runner sits outside that: it runs the project's test
 * command itself, records the real exit code, and prints a short version.
 *
 * WHAT IT DOES. Finds the project (first folder up from the cwd with
 * .claude/project.md), runs test_command plus any args, writes the full output
 * to test_log and .claude/work/last-test-run.json, then prints the output in
 * full if short, else the failure blocks, the summary and the log path. Exits
 * with the child's code. No project, or no test_command: one line, exit 2.
 *
 * DONE GATE. Each run leaves a record: "running" at spawn, then "finished" or
 * "timed_out", with a fingerprint of the git tree taken at spawn, and a line in
 * .claude/work/test-runs.jsonl. --verify passes only for a finished, full,
 * green, recent run of the tree as it is now. --pass <id> marks a checklist
 * entry done on that basis. --check-scope compares what changed since a start
 * sha with the brief's "Files you may touch", reports commits the worker made,
 * and records the tree it judged; --pass refuses a different tree. The
 * fingerprint leaves out .claude/work (checklist.json too), the test log, the
 * board and scope_ignore, so the board move after --pass changes nothing.
 *
 * NEVER HIDES A FAILURE. A long run with no summary line prints the tail and
 * says so, rather than guessing what mattered.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const os = require('os');
const { spawn, spawnSync } = require('child_process');

const ANSI = /\u001b\[[0-9;?]*[ -\/]*[@-~]/g;

function stripAnsi(s) {
  return String(s).replace(ANSI, '');
}

function escapeRegex(s) {
  return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function unquote(v) {
  const m = /^(["'])(.*)\1$/.exec(v);
  return m ? m[2] : v;
}

// Flat key: value lines between the first two --- lines.
function parseFrontmatter(text) {
  const lines = String(text).split(/\r?\n/);
  if (lines[0].trim() !== '---') return {};
  const out = {};
  for (let i = 1; i < lines.length; i++) {
    if (lines[i].trim() === '---') return out;
    const m = /^([A-Za-z0-9_]+)\s*:\s*(.*?)\s*$/.exec(lines[i]);
    if (m) out[m[1]] = unquote(m[2]);
  }
  return {};
}

function posInt(v, dflt) {
  const n = parseInt(v, 10);
  return Number.isFinite(n) && n > 0 ? n : dflt;
}

function loadConfig(projectDir) {
  let text;
  try {
    text = fs.readFileSync(path.join(projectDir, '.claude', 'project.md'), 'utf8');
  } catch (e) {
    return null;
  }
  const fm = parseFrontmatter(text.replace(/^﻿/, ''));
  if (!fm.test_command) return null;
  const max = parseInt(fm.test_output_max_lines, 10);
  return {
    command: fm.test_command,
    // A regex so a later hook can anchor it to command position. The runner
    // itself does not use it.
    match: new RegExp(fm.test_match || escapeRegex(fm.test_command)),
    failureStart: fm.test_failure_start ? new RegExp(fm.test_failure_start) : null,
    summary: fm.test_summary ? new RegExp(fm.test_summary) : null,
    log: fm.test_log || '.claude/work/test-last.log',
    maxLines: Number.isFinite(max) ? max : 60,
    timeoutS: posInt(fm.test_timeout_s, 1800),
    verifyMaxAgeMin: posInt(fm.verify_max_age_min, 15),
    // Run after every green filtered run, so a worker's narrow run still
    // meets the project's source scans (SparrowBook, 2026-10-03: two cards
    // passed their filter and failed RoleNameScanTest at the gate).
    filteredAlso: fm.filtered_also || null,
    // Comma-separated files the scope check never judges: ones the owner
    // keeps dirty on purpose, like a local settings file nobody commits.
    scopeIgnore: String(fm.scope_ignore || '').split(',').map((s) => s.trim()).filter(Boolean),
    // The board file is moved after --pass, in the same commit as the work,
    // so it is left out of the tree fingerprint like checklist.json.
    board: fm.board || null,
  };
}

// First folder at or above start that has .claude/project.md.
function findProject(start) {
  let dir = path.resolve(start);
  for (;;) {
    if (fs.existsSync(path.join(dir, '.claude', 'project.md'))) return dir;
    const up = path.dirname(dir);
    if (up === dir) return null;
    dir = up;
  }
}

function splitLines(text) {
  const lines = stripAnsi(text).split(/\r?\n/);
  while (lines.length && lines[lines.length - 1] === '') lines.pop();
  return lines;
}

// Failure blocks, then summary and everything after it.
function filterLines(lines, cfg) {
  const sumIdx = cfg.summary ? lines.findIndex((l) => cfg.summary.test(l)) : -1;
  if (sumIdx < 0) return null;
  const blocks = [];
  if (cfg.failureStart) {
    let cur = null;
    for (let i = 0; i < sumIdx; i++) {
      if (cfg.failureStart.test(lines[i])) {
        if (cur) blocks.push(cur);
        cur = [lines[i]];
      } else if (cur) {
        cur.push(lines[i]);
      }
    }
    if (cur) blocks.push(cur);
  }
  const out = [];
  for (const b of blocks) {
    while (b.length && b[b.length - 1].trim() === '') b.pop();
    out.push(...b, '');
  }
  out.push(...lines.slice(sumIdx));
  return { text: out.join('\n'), summary: lines[sumIdx].trim() };
}

// Characters cmd.exe reads as syntax in or around quotes. Refused before any
// arg is quoted, so quoting only has to protect spaces.
const BAD_ARG = /[&|<>^%"]/;

// Every arg is double-quoted. Trailing backslashes are doubled so they cannot
// escape the closing quote.
function quoteArg(a) {
  return '"' + a.replace(/(\\+)$/, '$1$1') + '"';
}

// What to print for a finished run.
function render(lines, cfg, logPath) {
  if (lines.length <= cfg.maxLines) return lines.join('\n');
  const f = filterLines(lines, cfg);
  if (f) return f.text + '\nFull output: ' + logPath;
  return lines.slice(-cfg.maxLines).join('\n')
    + '\nNo summary line found (run crashed or was cut off). ' + lines.length
    + ' lines in total. Full output: ' + logPath;
}

function git(cwd, args) {
  return spawnSync('git', args, { cwd, maxBuffer: 1 << 30 });
}

// The runner's own records. Left out of the tree fingerprint and the scope
// check, or writing a record would change the tree it records.
function workDir(projectDir) {
  return path.join(projectDir, '.claude', 'work');
}

function relUnix(projectDir, abs) {
  return path.relative(projectDir, abs).split(path.sep).join('/');
}

// Paths, relative to the project, that never count toward the fingerprint:
// the runner's and the loop's own records under .claude/work (checklist.json
// with them), the test log, the board, and the scope_ignore entries the owner
// keeps dirty on purpose. Each one changes after the gate without changing
// the code under test.
function fingerprintExcludes(projectDir, cfg) {
  const out = ['.claude/work'];
  for (const p of [cfg.log, cfg.board].concat(cfg.scopeIgnore || [])) {
    if (!p) continue;
    const rel = relUnix(projectDir, path.resolve(projectDir, p));
    if (rel && !rel.startsWith('..') && !path.isAbsolute(rel)) out.push(rel);
  }
  return out;
}

// The content of the project as git would commit it, less the excludes:
// sha256 of a git tree id. With no ref it is the working tree, tracked and
// untracked non-ignored files alike, staged through a throwaway index so the
// real one is never touched. With a ref ("HEAD") it is that commit's tree.
// HEAD itself is not part of it, so a commit of exactly the tested files
// fingerprints the same as the tree that was tested, and one missing a file
// or carrying an extra change does not. null when this is not a git repo.
function treeFingerprint(projectDir, cfg, ref) {
  const pre = git(projectDir, ['rev-parse', '--show-prefix']);
  if (pre.status !== 0) return null;
  if (git(projectDir, ['rev-parse', '--verify', '-q', 'HEAD']).status !== 0) return null;
  const prefix = pre.stdout.toString('utf8').trim().replace(/\/$/, '');
  const tmp = path.join(os.tmpdir(), 'pt-index-' + process.pid + '-' + crypto.randomBytes(6).toString('hex'));
  const env = Object.assign({}, process.env, { GIT_INDEX_FILE: tmp });
  const g = (args) => spawnSync('git', args, { cwd: projectDir, env, maxBuffer: 1 << 30 });
  try {
    if (ref) {
      if (g(['read-tree', ref]).status !== 0) return null;
    } else {
      // A copy of the real index keeps its file stats, so add only rehashes
      // what changed.
      const gd = git(projectDir, ['rev-parse', '--absolute-git-dir']);
      let copied = false;
      if (gd.status === 0) {
        try { fs.copyFileSync(path.join(gd.stdout.toString('utf8').trim(), 'index'), tmp); copied = true; } catch (e) { /* no index yet */ }
      }
      if (!copied && g(['read-tree', 'HEAD']).status !== 0) return null;
      if (g(['add', '-A', '--', '.']).status !== 0) return null;
    }
    const ex = fingerprintExcludes(projectDir, cfg).map((p) => ':(literal)' + p);
    if (g(['rm', '--cached', '-r', '-q', '--ignore-unmatch', '--'].concat(ex)).status !== 0) return null;
    const w = g(['write-tree']);
    if (w.status !== 0) return null;
    let tree = w.stdout.toString('utf8').trim();
    if (prefix) {
      const sub = g(['rev-parse', tree + ':' + prefix]);
      if (sub.status !== 0) return null;
      tree = sub.stdout.toString('utf8').trim();
    }
    return crypto.createHash('sha256').update('tree\0' + tree).digest('hex');
  } finally {
    for (const f of [tmp, tmp + '.lock']) { try { fs.unlinkSync(f); } catch (e) { /* gone */ } }
  }
}

// What `git status` shows in the project that is not the loop's or the
// runner's own record, the test log, or a scope_ignore entry. Paths are
// relative to the project. null when git cannot say.
function dirtyPaths(projectDir, cfg) {
  const top = git(projectDir, ['rev-parse', '--show-toplevel']);
  if (top.status !== 0) return null;
  const topDir = real(top.stdout.toString('utf8').trim());
  const root = real(projectDir);
  const st = git(projectDir, ['-c', 'core.quotepath=off', 'status', '--porcelain', '-z', '--untracked-files=all', '--', '.']);
  if (st.status !== 0) return null;
  const work = workDir(root);
  const skipFiles = new Set([
    path.join(work, 'last-test-run.json'), path.join(work, 'test-runs.jsonl'), path.join(work, 'test-run.lock'), path.resolve(root, cfg.log),
  ].concat((cfg.scopeIgnore || []).map((p) => path.resolve(root, p))).map(normPath));
  const skipDirs = [path.join(work, 'loop')].concat((cfg.scopeIgnore || []).filter((p) => /[\\/]$/.test(p))
    .map((p) => path.resolve(root, p))).map((d) => normPath(d).replace(/\/$/, '') + '/');
  const parts = st.stdout.toString('utf8').split('\0');
  const out = [];
  for (let i = 0; i < parts.length; i++) {
    const e = parts[i];
    if (e.length < 4) continue;
    // A rename or copy carries its source path as the next entry.
    if (/^[RC]/.test(e)) i++;
    const abs = path.resolve(topDir, e.slice(3));
    const n = normPath(abs);
    if (skipFiles.has(n) || skipDirs.some((d) => n.startsWith(d))) continue;
    out.push(path.relative(root, abs).split(path.sep).join('/'));
  }
  return out.sort();
}

function readRecord(projectDir) {
  try {
    return JSON.parse(fs.readFileSync(path.join(workDir(projectDir), 'last-test-run.json'), 'utf8'));
  } catch (e) {
    return null;
  }
}

function writeRecord(projectDir, rec) {
  fs.mkdirSync(workDir(projectDir), { recursive: true });
  fs.writeFileSync(path.join(workDir(projectDir), 'last-test-run.json'), JSON.stringify(rec, null, 2) + '\n');
}

function readJournal(projectDir) {
  let text;
  try { text = fs.readFileSync(path.join(workDir(projectDir), 'test-runs.jsonl'), 'utf8'); } catch (e) { return []; }
  const out = [];
  for (const l of text.split(/\r?\n/)) {
    if (!l.trim()) continue;
    try { out.push(JSON.parse(l)); } catch (e) { /* a torn line */ }
  }
  return out;
}

// Reasons the last record does not count as a pass. Empty means verified.
function verifyReasons(projectDir, cfg) {
  const r = readRecord(projectDir);
  if (!r) return ['no test run recorded'];
  const why = [];
  if (r.status !== 'finished') why.push('last run status is ' + r.status + ', not finished');
  if (r.exit_code !== 0) why.push('last run exit code is ' + r.exit_code + ', not 0');
  if (Array.isArray(r.args) && r.args.length) why.push('last run was partial (args: ' + r.args.join(' ') + ')');
  if (!r.tree) {
    why.push('no tree fingerprint (not a git repo)');
  } else {
    const now = treeFingerprint(projectDir, cfg);
    if (!now) why.push('tree cannot be fingerprinted now (not a git repo)');
    else if (now !== r.tree) why.push('tree changed since the run');
  }
  const fin = Date.parse(r.finished_at);
  if (!Number.isFinite(fin)) why.push('no finished_at');
  else if (Date.now() - fin > cfg.verifyMaxAgeMin * 60000) {
    why.push('last run finished ' + Math.round((Date.now() - fin) / 60000) + ' min ago, limit is ' + cfg.verifyMaxAgeMin);
  }
  return why;
}

function doVerify(projectDir, cfg, cb) {
  const why = verifyReasons(projectDir, cfg);
  if (why.length) cb(1, why.map((w) => 'not verified: ' + w).join('\n') + '\n');
  else cb(0, 'verified\n');
}

function doPass(projectDir, cfg, id, cb) {
  if (!id) { cb(2, 'project-test: --pass needs an id\n'); return; }
  const file = path.join(workDir(projectDir), 'checklist.json');
  let list;
  try { list = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) {
    cb(1, 'project-test: cannot read ' + file + '\n');
    return;
  }
  const entry = Array.isArray(list) ? list.find((e) => e && String(e.id) === String(id)) : null;
  if (!entry) { cb(1, 'project-test: no checklist entry with id ' + id + '\n'); return; }
  // A card with a brief passes only after --check-scope passed for that
  // brief from this card's start_sha. Two loop passes on 2026-10-04 skipped
  // the scope step; the record is what makes it unskippable.
  if (entry.brief) {
    const s = entry.scope_ok;
    if (!s || !entry.start_sha || s.start_sha !== entry.start_sha) {
      cb(1, 'not passed: no passing --check-scope ' + entry.brief + ' ' + (entry.start_sha || '<start_sha>')
        + ' on record for ' + id + '. Run it first.\n');
      return;
    }
    // The scope check judged one tree. A file added or changed after it was
    // never judged, so the check has to be run again on the tree as it is.
    const now = treeFingerprint(projectDir, cfg);
    if (!s.tree || !now || s.tree !== now) {
      cb(1, 'not passed: the tree changed since --check-scope passed for ' + id
        + '. Run --check-scope ' + entry.brief + ' ' + entry.start_sha + ' again.\n');
      return;
    }
  }
  const why = verifyReasons(projectDir, cfg);
  if (why.length) { cb(1, why.map((w) => 'not verified: ' + w).join('\n') + '\n'); return; }
  entry.passes = true;
  entry.verified_at = new Date().toISOString();
  entry.tree = readRecord(projectDir).tree;
  fs.writeFileSync(file, JSON.stringify(list, null, 2) + '\n');
  cb(0, 'verified, ' + id + ' passes\n');
}

// Backticked paths under "## Files you may touch".
function briefAllowed(text) {
  const out = [];
  let on = false;
  for (const l of String(text).split(/\r?\n/)) {
    if (/^##\s/.test(l)) on = /^##\s+Files you may touch\s*$/i.test(l);
    else if (on) for (const m of l.matchAll(/`([^`]+)`/g)) out.push(m[1]);
  }
  return out;
}

// Slashes equal; case-insensitive on Windows.
function normPath(p) {
  const n = p.split('\\').join('/');
  return process.platform === 'win32' ? n.toLowerCase() : n;
}

function real(p) {
  try { return fs.realpathSync.native(p); } catch (e) { return path.resolve(p); }
}

function doCheckScope(projectDir, cfg, briefPath, sha, cb) {
  if (!briefPath || !sha) { cb(2, 'project-test: --check-scope needs <brief.md> <start-sha>\n'); return; }
  let text;
  try { text = fs.readFileSync(path.resolve(briefPath), 'utf8'); } catch (e) {
    cb(1, 'project-test: cannot read brief ' + briefPath + '\n');
    return;
  }
  const top = git(projectDir, ['rev-parse', '--show-toplevel']);
  if (top.status !== 0) { cb(1, 'project-test: not a git repo\n'); return; }
  const topDir = real(top.stdout.toString('utf8').trim());
  const root = real(projectDir);
  const diff = git(topDir, ['-c', 'core.quotepath=off', 'diff', '--name-only', '-z', sha]);
  if (diff.status !== 0) {
    cb(1, 'project-test: git diff ' + sha + ' failed: ' + diff.stderr.toString('utf8').trim() + '\n');
    return;
  }
  const others = git(topDir, ['ls-files', '--others', '--exclude-standard', '-z']);
  const changed = new Set(diff.stdout.toString('utf8').split('\0').filter(Boolean)
    .concat(others.stdout.toString('utf8').split('\0').filter(Boolean)));
  const listed = briefAllowed(text);
  const allowed = new Set(listed.map((p) => normPath(path.resolve(root, p))));
  // A path ending in a slash is a folder: everything under it is allowed.
  const folders = listed.filter((p) => /[\\/]$/.test(p)).map((p) => normPath(path.resolve(root, p)) + '/');
  const logAbs = normPath(path.resolve(root, cfg.log));
  const workAbs = normPath(workDir(root));
  const ignored = new Set((cfg.scopeIgnore || []).map((p) => normPath(path.resolve(root, p))));
  // An entry ending in a slash is a folder: everything under it is skipped.
  const ignoredDirs = (cfg.scopeIgnore || []).filter((p) => /[\\/]$/.test(p)).map((p) => normPath(path.resolve(root, p)) + '/');
  const bad = [];
  const outside = [];
  for (const f of changed) {
    const abs = path.resolve(topDir, f);
    const n = normPath(abs);
    const rel = path.relative(root, abs);
    if (rel.startsWith('..') || path.isAbsolute(rel)) { outside.push(abs); continue; }
    if (n === logAbs || n === workAbs || n.startsWith(workAbs + '/') || ignored.has(n) || ignoredDirs.some((d) => n.startsWith(d))) continue;
    if (!allowed.has(n) && !folders.some((d) => n.startsWith(d))) bad.push(rel.split(path.sep).join('/'));
  }
  const commits = git(topDir, ['rev-list', sha + '..HEAD']);
  const made = commits.status === 0 ? commits.stdout.toString('utf8').split('\n').filter(Boolean) : [];
  const lines = [];
  for (const b of bad.sort()) lines.push('outside the brief: ' + b);
  if (made.length) lines.push('the worker committed: ' + made.length + ' commit(s) since ' + sha);
  if (commits.status !== 0) lines.push('git rev-list ' + sha + '..HEAD failed');
  for (const o of outside.sort()) lines.push('outside the project, not judged: ' + o);
  // A failing run followed later by a passing one, since the brief's folder existed.
  let born = 0;
  try {
    const st = fs.statSync(path.dirname(path.resolve(briefPath)));
    born = st.birthtimeMs || st.mtimeMs;
  } catch (e) { /* no folder, no cutoff */ }
  let sawFail = false;
  let evidence = false;
  for (const j of readJournal(projectDir)) {
    if (Date.parse(j.started_at) < born) continue;
    if (j.exit_code !== 0) sawFail = true;
    else if (sawFail) evidence = true;
  }
  lines.push('mutation evidence: ' + (evidence ? 'yes' : 'no'));
  const fail = bad.length > 0 || made.length > 0 || commits.status !== 0;
  lines.push(fail ? 'scope: FAIL' : 'scope: ok');
  if (!fail) recordScope(projectDir, briefPath, sha, evidence, lines, treeFingerprint(projectDir, cfg));
  cb(fail ? 1 : 0, lines.join('\n') + '\n');
}

// Marks the checklist entry whose brief this is, so --pass can see the scope
// check ran and passed from the same start sha, on the same tree.
function recordScope(projectDir, briefPath, sha, evidence, lines, tree) {
  const file = path.join(workDir(projectDir), 'checklist.json');
  let list;
  try { list = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { return; }
  if (!Array.isArray(list)) return;
  const want = normPath(real(path.resolve(briefPath)));
  const entry = list.find((e) => e && e.brief && normPath(real(path.resolve(projectDir, e.brief))) === want);
  if (!entry) { lines.push('no checklist entry names this brief, so nothing recorded'); return; }
  entry.scope_ok = { at: new Date().toISOString(), start_sha: sha, mutation_evidence: evidence, tree };
  fs.writeFileSync(file, JSON.stringify(list, null, 2) + '\n');
  lines.push('recorded on ' + entry.id);
}

// shell: true puts a shell between us and the test command, and on Windows
// killing the shell leaves the grandchild alive, so kill the whole tree.
function killTree(child) {
  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/T', '/F', '/PID', String(child.pid)]);
  } else {
    try { process.kill(-child.pid, 'SIGKILL'); } catch (e) { child.kill('SIGKILL'); }
  }
}

function doRun(projectDir, cfg, args, cb) {
  const command = [cfg.command].concat(args.map(quoteArg)).join(' ');
  const logPath = path.resolve(projectDir, cfg.log);
  const startedAt = new Date().toISOString();
  const tree = treeFingerprint(projectDir, cfg);
  writeRecord(projectDir, { status: 'running', exit_code: null, started_at: startedAt, command, args, tree });
  let buf = '';
  let timedOut = false;
  const child = spawn(command, {
    cwd: projectDir, shell: true, env: process.env, detached: process.platform !== 'win32',
  });
  child.stdout.setEncoding('utf8');
  child.stderr.setEncoding('utf8');
  child.stdout.on('data', (d) => { buf += d; });
  child.stderr.on('data', (d) => { buf += d; });
  child.on('error', (e) => { buf += 'project-test: ' + e.message + '\n'; });
  const timer = setTimeout(() => {
    timedOut = true;
    buf += '\nproject-test: timed out after ' + cfg.timeoutS + ' s, killed the process tree\n';
    killTree(child);
  }, cfg.timeoutS * 1000);
  child.on('close', (code) => {
    clearTimeout(timer);
    const exitCode = timedOut ? 124 : (typeof code === 'number' ? code : 1);
    const lines = splitLines(buf);
    const sumLine = cfg.summary ? lines.find((l) => cfg.summary.test(l)) : undefined;
    const filtered = lines.length > cfg.maxLines && !!sumLine;
    fs.mkdirSync(path.dirname(logPath), { recursive: true });
    fs.writeFileSync(logPath, buf);
    const finishedAt = new Date().toISOString();
    const rec = {
      status: timedOut ? 'timed_out' : 'finished',
      exit_code: exitCode,
      started_at: startedAt,
      finished_at: finishedAt,
      at: finishedAt,
      command,
      args,
      tree,
      lines: lines.length,
      summary: sumLine ? sumLine.trim() : null,
      filtered,
      log: logPath,
    };
    writeRecord(projectDir, rec);
    fs.appendFileSync(path.join(workDir(projectDir), 'test-runs.jsonl'), JSON.stringify(rec) + '\n');
    cb(exitCode, render(lines, cfg, logPath) + '\n');
  });
}

// One test run at a time per project. Two suites on one test database
// migrate and truncate it under each other and fail tests neither broke
// (Sembr and SparrowBook both, more than once), and most projects have no
// lock of their own. The second run is refused before it writes a record or
// starts the test command, so the run in flight keeps its record; claude-loop
// recognises BUSY (with exit 75) and waits it out. A lock whose process is
// gone, or older than two test timeouts plus five minutes, is stale and taken
// over. Runs that bypass this runner are not covered.
const BUSY = 'project-test: another test run is using this project (test-run.lock). Wait for it to finish, then run again.';

function lockPath(projectDir) {
  return path.join(workDir(projectDir), 'test-run.lock');
}

function pidAlive(pid) {
  if (!(pid > 0)) return false;
  try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; }
}

// Returns a release function, or null when a live run holds the lock.
function acquireLock(projectDir, cfg, now = Date.now()) {
  const file = lockPath(projectDir);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const body = JSON.stringify({ pid: process.pid, started_at: new Date(now).toISOString() });
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      fs.writeFileSync(file, body, { flag: 'wx' });
      let released = false;
      const release = () => {
        if (released) return;
        released = true;
        process.removeListener('exit', release);
        try {
          const held = JSON.parse(fs.readFileSync(file, 'utf8'));
          if (held.pid === process.pid) fs.unlinkSync(file);
        } catch (e) { /* gone */ }
      };
      process.on('exit', release);
      return release;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
    }
    let raw = null;
    try { raw = fs.readFileSync(file, 'utf8'); } catch (e) { continue; /* released meanwhile */ }
    let held = null;
    try { held = JSON.parse(raw); } catch (e) { /* unreadable: stale */ }
    // A live pid is busy. The age line is only for a pid Windows has handed
    // to another process; one run can take two timeouts (filtered_also).
    const age = held ? now - Date.parse(held.started_at) : Infinity;
    if (held && pidAlive(held.pid) && !(age > (2 * cfg.timeoutS + 300) * 1000)) return null;
    // Take the stale lock over by renaming it aside, then check the moved file
    // is the one judged stale. A runner that judged the same file stale a
    // moment later would otherwise move the fresh lock the first one just made:
    // that one goes back, and this run counts as busy.
    const aside = file + '.stale.' + process.pid;
    try { fs.renameSync(file, aside); } catch (e) { continue; /* another run moved it first */ }
    let moved = null;
    try { moved = fs.readFileSync(aside, 'utf8'); } catch (e) { /* gone */ }
    if (moved !== raw) {
      try { fs.linkSync(aside, file); } catch (e) { /* a third run holds it now */ }
      try { fs.unlinkSync(aside); } catch (e) { /* gone */ }
      return null;
    }
    try { fs.unlinkSync(aside); } catch (e) { /* gone */ }
  }
  return null;
}

// Flags are read first; anything else goes to the test command.
function run(cwd, args, cb) {
  const projectDir = findProject(cwd);
  const cfg = projectDir && loadConfig(projectDir);
  if (!cfg) {
    cb(2, 'project-test: no .claude/project.md with test_command found from ' + cwd + '\n');
    return;
  }
  if (args[0] === '--verify') return doVerify(projectDir, cfg, cb);
  if (args[0] === '--pass') return doPass(projectDir, cfg, args[1], cb);
  if (args[0] === '--check-scope') return doCheckScope(projectDir, cfg, args[1], args[2], cb);
  const badArg = args.find((a) => BAD_ARG.test(a));
  if (badArg !== undefined) {
    cb(2, 'project-test: refused arg ' + JSON.stringify(badArg) + ', it contains one of & | < > ^ % "\n');
    return;
  }
  const release = acquireLock(projectDir, cfg);
  if (!release) {
    cb(75, BUSY + '\n');
    return;
  }
  const done = (code, text) => { release(); cb(code, text); };
  const also = cfg.filteredAlso && '--filter=' + cfg.filteredAlso;
  const filtered = args.some((a) => /^--filter(=|$)/.test(a));
  if (!also || !filtered || (args.length === 1 && args[0] === also)) {
    doRun(projectDir, cfg, args, done);
    return;
  }
  doRun(projectDir, cfg, args, (code, text) => {
    if (code !== 0) return done(code, text);
    doRun(projectDir, cfg, [also], (code2, text2) => {
      done(code2, text + 'project-test: also ran ' + also + ' (filtered_also in project.md)\n' + text2);
    });
  });
}

module.exports = {
  stripAnsi, parseFrontmatter, loadConfig, findProject, splitLines, filterLines, render, run,
  treeFingerprint, dirtyPaths, readRecord, acquireLock, lockPath, BUSY,
};

if (require.main === module) {
  run(process.cwd(), process.argv.slice(2), (code, text) => {
    process.stdout.write(text, () => process.exit(code));
  });
}
