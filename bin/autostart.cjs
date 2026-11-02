#!/usr/bin/env node
// Card 120: brings the cockpit back after a restart, with no window and no hands. Started at logon by
// bin/autostart.vbs (a Startup folder shortcut, see bin/install-autostart.cjs). Waits for the dashboard on 8787,
// starts cas-voice through bin/cas-voice-start.cjs if 8766 is not listening, opens Chrome once on monitor 2, then
// stays as a watchdog: every 60 s, cas-voice restarted if 8766 stops answering, at most 3 restarts an hour.
// A second copy exits at once. Log: data/autostart.log. Usage: node bin/autostart.cjs
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const http = require('http');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const LOCK = path.join(DATA, 'autostart.lock');
const MARKER = path.join(DATA, 'cockpit-chrome.pid');
const LOG = path.join(DATA, 'autostart.log');
const COCKPIT = 'http://127.0.0.1:8787/cockpit.php';
const CHROME = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
];
const WAIT_MS = 3 * 60 * 1000;
const EVERY_MS = 60 * 1000;
const MAX_RESTARTS = 3;
const HOUR_MS = 60 * 60 * 1000;

function log(line, file = LOG) {
  try { fs.appendFileSync(file, new Date().toISOString() + ' ' + line + '\n'); } catch { /* data/ missing: nothing to log to */ }
}

function alive(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; }
}

function readPid(file) {
  try { return parseInt(fs.readFileSync(file, 'utf8'), 10); } catch { return NaN; }
}

// True when this process now holds the lock; false when a live copy already does. A stale lock is taken over.
function takeLock(file = LOCK, pid = process.pid) {
  for (let i = 0; i < 2; i++) {
    try {
      fs.writeFileSync(file, String(pid), { flag: 'wx' });
      return true;
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      const other = readPid(file);
      if (other === pid) return true;
      if (alive(other)) return false;
      try { fs.unlinkSync(file); } catch { /* the other copy removed it first */ }
    }
  }
  return false;
}

function chromeArgs() {
  return ['--new-window', '--app=' + COCKPIT, '--window-position=3440,-262', '--window-size=1080,1920', '--start-fullscreen'];
}

// A cockpit window is open when the marker names a live Chrome pid.
function cockpitOpen(file = MARKER) {
  return alive(readPid(file));
}

function openCockpit(file = MARKER, run = spawn) {
  if (cockpitOpen(file)) return null;
  const exe = CHROME.find((p) => fs.existsSync(p)) ?? 'chrome.exe';
  const child = run(exe, chromeArgs(), { detached: true, stdio: 'ignore', windowsHide: true });
  child.unref?.();
  if (child.pid) fs.writeFileSync(file, String(child.pid));
  return child;
}

// The restart cap: history holds the times of past restarts; a restart is allowed while fewer than 3 fell in the last hour.
function mayRestart(history, now) {
  const recent = history.filter((t) => now - t < HOUR_MS);
  history.length = 0;
  history.push(...recent);
  return recent.length < MAX_RESTARTS;
}

function listening(port) {
  return new Promise((done) => {
    const s = net.connect({ host: '127.0.0.1', port });
    s.setTimeout(3000);
    s.once('connect', () => { s.destroy(); done(true); });
    s.once('timeout', () => { s.destroy(); done(false); });
    s.once('error', () => done(false));
  });
}

function answers(url) {
  return new Promise((done) => {
    const r = http.get(url, { timeout: 5000 }, (res) => { res.resume(); done(true); });
    r.once('timeout', () => { r.destroy(); done(false); });
    r.once('error', () => done(false));
  });
}

function startVoice(run = spawn) {
  const child = run(process.execPath, [path.join(__dirname, 'cas-voice-start.cjs')], { detached: true, stdio: 'ignore', windowsHide: true });
  child.unref?.();
  return child;
}

const pause = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
  fs.mkdirSync(DATA, { recursive: true });
  if (!takeLock()) {
    log('another copy holds the lock; exiting');
    process.exit(0);
  }
  const drop = () => { try { if (readPid(LOCK) === process.pid) fs.unlinkSync(LOCK); } catch { /* gone */ } };
  process.on('exit', drop);
  process.on('SIGINT', () => process.exit(0));
  process.on('SIGTERM', () => process.exit(0));

  const until = Date.now() + WAIT_MS;
  while (!(await answers('http://127.0.0.1:8787/')) && Date.now() < until) await pause(5000);
  log((await answers('http://127.0.0.1:8787/')) ? 'dashboard answers on 8787' : 'dashboard not answering after 3 minutes');

  if (!(await listening(8766))) {
    startVoice();
    log('cas-voice started');
  }
  log(openCockpit() ? 'cockpit opened on monitor 2' : 'cockpit already open');

  const history = [];
  setInterval(async () => {
    if (await listening(8766)) return;
    if (!mayRestart(history, Date.now())) {
      log('cas-voice down; 3 restarts in the last hour, waiting');
      return;
    }
    history.push(Date.now());
    startVoice();
    log('cas-voice down; restarted');
  }, EVERY_MS);
}

module.exports = { takeLock, chromeArgs, cockpitOpen, openCockpit, mayRestart, MAX_RESTARTS };

if (require.main === module) main();
