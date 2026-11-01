#!/usr/bin/env node
// Card 110: starts the local cas-voice engine for the cockpit, hidden and detached, then exits. For Rob or a logon
// task; the cockpit page never starts it. Usage: node bin/cas-voice-start.cjs
const { spawn } = require('child_process');
const fs = require('fs');

const RUN = 'D:\\Dev\\cas-voice\\proto\\run.cmd';

if (!fs.existsSync(RUN)) {
  console.error('cas-voice not found at ' + RUN);
  process.exit(1);
}
const child = spawn('cmd.exe', ['/d', '/c', RUN, '--no-browser'], {
  cwd: 'D:\\Dev\\cas-voice\\proto',
  detached: true,
  stdio: 'ignore',
  windowsHide: true,
});
child.unref();
console.log('cas-voice started');
