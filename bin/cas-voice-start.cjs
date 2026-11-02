#!/usr/bin/env node
// Card 110: starts the local cas-voice engine for the cockpit, hidden and detached, then exits. For Rob or a logon
// task; the cockpit page never starts it. Usage: node bin/cas-voice-start.cjs
// Card 124: does the proto's run script's job itself so nothing can show a window: maps the project to a short drive
// letter (Windows path limit), then runs the venv's pythonw.exe, which has no console; no shell, no console python. Output is
// appended to data/cas-voice.log after a start line; the engine's pid and start time go to data/cas-voice.pid,
// which api/voice.php reads to tell "starting" from "down".
const { spawn, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const net = require('net');

const PARENT = 'D:\\Dev\\cas-voice';
const DATA = path.join(__dirname, '..', 'data');
const LOG = path.join(DATA, 'cas-voice.log');
const PID = path.join(DATA, 'cas-voice.pid');
const LETTERS = ['V', 'W', 'X', 'Y', 'Z'];

// A letter already mapped to the project, else the first free one mapped now, as the proto's run script does.
function drive() {
  const have = LETTERS.find((l) => fs.existsSync(l + ':\\proto\\server.py'));
  if (have) return have + ':';
  for (const l of LETTERS) {
    if (fs.existsSync(l + ':\\')) continue;
    if (spawnSync('subst', [l + ':', PARENT], { windowsHide: true }).status === 0) return l + ':';
  }
  return null;
}

const PORT = 8766;
const STARTING_MS = 5 * 60 * 1000; // models take about 2.5 minutes to load
const STALE_LOCK_MS = 60 * 1000;

function listening(port) {
  return new Promise((resolve) => {
    const s = net.connect({ port, host: '127.0.0.1' });
    const done = (v) => { s.destroy(); resolve(v); };
    s.setTimeout(1000, () => done(false));
    s.on('connect', () => done(true));
    s.on('error', () => done(false));
  });
}

function alive(pid) {
  try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; }
}

// Exclusive lock for the check-and-spawn; a lock older than 60 s is dead and replaced. Answers a release function or null.
function takeLock(lock, now) {
  for (let i = 0; i < 2; i++) {
    try {
      fs.closeSync(fs.openSync(lock, 'wx'));
      return () => { try { fs.unlinkSync(lock); } catch (e) { /* gone already */ } };
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      let age = 0;
      try { age = now() - fs.statSync(lock).mtimeMs; } catch (e2) { continue; }
      if (age < STALE_LOCK_MS) return null;
      try { fs.unlinkSync(lock); } catch (e2) { /* another run replaced it */ }
    }
  }
  return null;
}

// deps are for the tests: data dir, port, spawn, drive, probe, alive, now. Answers 'running', 'starting', 'locked', or the pid.
async function run(deps = {}) {
  const data = deps.data || DATA;
  const log = path.join(data, 'cas-voice.log');
  const pidFile = path.join(data, 'cas-voice.pid');
  const now = deps.now || Date.now;
  const spawn = deps.spawn || require('child_process').spawn;
  const note = (m) => fs.appendFileSync(log, new Date().toISOString() + ' ' + m + '\n');
  fs.mkdirSync(data, { recursive: true });
  const release = takeLock(path.join(data, 'cas-voice.lock'), now);
  if (!release) { note('cas-voice already starting (another start holds the lock)'); return 'locked'; }
  try {
    if (await (deps.probe || listening)(deps.port || PORT)) { note('cas-voice already running'); return 'running'; }
    try {
      const p = JSON.parse(fs.readFileSync(pidFile, 'utf8'));
      if (p && p.pid && now() - Date.parse(p.started) < STARTING_MS && (deps.alive || alive)(p.pid)) {
        note('cas-voice already starting (pid ' + p.pid + ')');
        return 'starting';
      }
    } catch (e) { /* no pid file, or unreadable */ }
    const drv = (deps.drive || drive)();
    if (!drv) {
      note('no free drive letter to map ' + PARENT);
      return null;
    }
    fs.appendFileSync(log, '\n' + new Date().toISOString() + ' cas-voice start on ' + drv + '\n');
    const out = fs.openSync(log, 'a');
    const child = spawn(drv + '\\venv\\Scripts\\pythonw.exe', [drv + '\\proto\\server.py', '--no-browser'], {
      cwd: drv + '\\proto',
      env: { ...process.env, HF_HOME: drv + '\\hf', PYTHONIOENCODING: 'utf-8', PYTHONUNBUFFERED: '1' },
      detached: true,
      stdio: ['ignore', out, out],
      windowsHide: true,
    });
    fs.writeFileSync(pidFile, JSON.stringify({ pid: child.pid, started: new Date(now()).toISOString() }));
    child.unref();
    return child.pid;
  } finally {
    release();
  }
}

async function main() {
  if (!fs.existsSync(path.join(PARENT, 'proto', 'server.py'))) {
    console.error('cas-voice not found at ' + PARENT);
    process.exit(1);
  }
  const r = await run();
  if (r === null) {
    console.error('no free drive letter');
    process.exit(1);
  }
  console.log(typeof r === 'number' ? 'cas-voice started, pid ' + r : 'cas-voice ' + (r === 'running' ? 'already running' : 'already starting'));
}

module.exports = { run, takeLock };
if (require.main === module) main();
