#!/usr/bin/env node
// Card 120: puts "KC Cockpit.lnk" in Rob's Startup folder, pointing at wscript.exe //B bin\autostart.vbs, so the
// cockpit comes back at logon. Safe to run again (the shortcut is rewritten). --remove deletes it.
// Usage: node bin/install-autostart.cjs [--remove]
const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const STARTUP = path.join(process.env.APPDATA ?? '', 'Microsoft', 'Windows', 'Start Menu', 'Programs', 'Startup');
const LNK = path.join(STARTUP, 'KC Cockpit.lnk');
const VBS = path.join(__dirname, 'autostart.vbs');

if (process.argv.includes('--remove')) {
  if (fs.existsSync(LNK)) fs.unlinkSync(LNK);
  console.log('removed ' + LNK);
  process.exit(0);
}

// The shortcut is written by the WScript Shell COM object in a hidden cscript; paths go in as arguments, not code.
const js = path.join(os.tmpdir(), 'kc-install-autostart-' + process.pid + '.js');
fs.writeFileSync(js, [
  'var a = WScript.Arguments;',
  'var s = new ActiveXObject("WScript.Shell").CreateShortcut(a(0));',
  's.TargetPath = a(1);',
  's.Arguments = "//B \\"" + a(2) + "\\"";',
  's.WorkingDirectory = a(3);',
  's.WindowStyle = 7;',
  's.Description = "KC Cockpit autostart";',
  's.Save();',
].join('\r\n'));
const wscript = path.join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', 'wscript.exe');
const r = spawnSync('cscript.exe', ['//nologo', '//E:jscript', js, LNK, wscript, VBS, path.dirname(__dirname)], { windowsHide: true, stdio: 'inherit' });
fs.unlinkSync(js);
if (r.status !== 0) {
  console.error('could not write ' + LNK);
  process.exit(1);
}
console.log('installed ' + LNK);
