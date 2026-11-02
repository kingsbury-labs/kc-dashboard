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

function main() {
  if (!fs.existsSync(path.join(PARENT, 'proto', 'server.py'))) {
    console.error('cas-voice not found at ' + PARENT);
    process.exit(1);
  }
  fs.mkdirSync(DATA, { recursive: true });
  const drv = drive();
  if (!drv) {
    fs.appendFileSync(LOG, new Date().toISOString() + ' no free drive letter to map ' + PARENT + '\n');
    console.error('no free drive letter');
    process.exit(1);
  }
  fs.appendFileSync(LOG, '\n' + new Date().toISOString() + ' cas-voice start on ' + drv + '\n');
  const out = fs.openSync(LOG, 'a');
  const child = spawn(drv + '\\venv\\Scripts\\pythonw.exe', [drv + '\\proto\\server.py', '--no-browser'], {
    cwd: drv + '\\proto',
    env: { ...process.env, HF_HOME: drv + '\\hf', PYTHONIOENCODING: 'utf-8', PYTHONUNBUFFERED: '1' },
    detached: true,
    stdio: ['ignore', out, out],
    windowsHide: true,
  });
  fs.writeFileSync(PID, JSON.stringify({ pid: child.pid, started: new Date().toISOString() }));
  child.unref();
  console.log('cas-voice started, pid ' + child.pid);
}

if (require.main === module) main();
