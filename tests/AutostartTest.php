<?php
// Card 120: the logon launcher, its shortcut installer and the Apache service script. The launcher's pieces run
// in node against temp files; nothing here starts Chrome, cas-voice, a service or a network call.
define('AS_ROOT', dirname(__DIR__));

// Runs a node script that requires bin/autostart.cjs as `a`; answers its JSON output.
function as_node(string $body): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcas_' . bin2hex(random_bytes(6));
    mkdir($t);
    $script = $t . '/t.cjs';
    file_put_contents($script, 'const a = require(' . json_encode(AS_ROOT . '/bin/autostart.cjs') . ");\nconst fs = require('fs');\nconst T = "
        . json_encode($t) . ";\n" . $body);
    $proc = proc_open(['node', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $d = json_decode((string) $out, true);
    assert_true(is_array($d), "node said: $out $err");
    return $d;
}

test('every spawn in autostart.cjs and install-autostart.cjs passes windowsHide: true', function () {
    foreach (['autostart.cjs', 'install-autostart.cjs'] as $f) {
        $src = (string) file_get_contents(AS_ROOT . "/bin/$f");
        preg_match_all('~\b(?:spawn|spawnSync|exec|execSync|execFile|execFileSync|run)\(([^;]*)~', $src, $m);
        $calls = array_filter($m[1], fn($c) => !str_starts_with(trim($c), ')'));
        assert_true(count($calls) > 0, "$f: no spawn found");
        foreach ($calls as $c) {
            assert_true(str_contains($c, 'windowsHide: true'), "$f spawn without windowsHide: $c");
        }
    }
    assert_true(str_contains((string) file_get_contents(AS_ROOT . '/bin/autostart.vbs'), '""", 0, False'), 'vbs runs node hidden');
});

test('cas-voice-start.cjs logs to data/cas-voice.log, writes the pid file and runs pythonw hidden, never a console', function () {
    $src = (string) file_get_contents(AS_ROOT . '/bin/cas-voice-start.cjs');
    assert_true(!str_contains($src, "stdio: 'ignore'"), "stdio: 'ignore' hides the engine's output");
    assert_true(str_contains($src, "path.join(DATA, 'cas-voice.log')") && str_contains($src, "path.join(data, 'cas-voice.pid')"), 'log and pid paths');
    assert_true(str_contains($src, "stdio: ['ignore', out, out]") && str_contains($src, "fs.openSync(log, 'a')"), 'output not appended to the log');
    assert_true(str_contains($src, "' cas-voice start on '"), 'no start line');
    assert_true(str_contains($src, 'fs.writeFileSync(pidFile, JSON.stringify({ pid: child.pid, started:'), 'no pid file');
    preg_match_all('~\b(?:spawn|spawnSync|exec|execSync|execFile|execFileSync)\(([^;]*)~', $src, $m);
    assert_same(2, count($m[1]), 'spawns');
    foreach ($m[1] as $c) {
        assert_true(str_contains($c, 'windowsHide: true'), "spawn without windowsHide: $c");
    }
    assert_true(str_contains($src, "'\\\\venv\\\\Scripts\\\\pythonw.exe'"), 'not pythonw');
    assert_true(!preg_match('~python\.exe|cmd\.exe|run\.cmd~i', $src), 'a console launch');
    $auto = (string) file_get_contents(AS_ROOT . '/bin/autostart.cjs');
    assert_true(str_contains($auto, "path.join(__dirname, 'cas-voice-start.cjs')"), 'the watchdog uses another launcher');
});

// Card 125: runs a node script that requires bin/cas-voice-start.cjs as `v`, with a temp data dir D and a fake spawn
// that counts calls; answers its JSON output.
function sv_node(string $body): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsv_' . bin2hex(random_bytes(6));
    mkdir($t);
    $script = $t . '/t.cjs';
    file_put_contents($script, 'const v = require(' . json_encode(AS_ROOT . '/bin/cas-voice-start.cjs') . ");\nconst fs = require('fs');\nconst net = require('net');\nconst D = "
        . json_encode($t) . ";\nconst calls = [];\nconst spawn = (exe, args, opts) => { calls.push({ exe, opts }); return { pid: 4242, unref() {} }; };\n"
        . "const base = { data: D, spawn, drive: () => 'V:', probe: async () => false };\nconst has = (f) => fs.existsSync(D + '/' + f);\n(async () => {\n" . $body . "\n})();");
    $proc = proc_open(['node', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $d = json_decode((string) $out, true);
    assert_true(is_array($d), "node said: $out $err");
    return $d;
}

test('the voice launcher spawns nothing when 8766 is already listening', function () {
    $d = sv_node('const srv = net.createServer().listen(0, "127.0.0.1", async () => {
  const r = await v.run({ ...base, probe: undefined, port: srv.address().port });
  srv.close();
  console.log(JSON.stringify({ r, n: calls.length, pid: has("cas-voice.pid"), log: fs.readFileSync(D + "/cas-voice.log", "utf8") }));
});');
    assert_same('running', $d['r']);
    assert_same(0, $d['n'], 'spawned with a listener up');
    assert_same(false, $d['pid'], 'wrote a pid file');
    assert_true(str_contains($d['log'], 'already running'), 'no log line');
});

test('the voice launcher spawns nothing while the pid file names a live engine under 5 minutes old, and starts when it is old or dead', function () {
    $d = sv_node('const w = (age, pid) => fs.writeFileSync(D + "/cas-voice.pid", JSON.stringify({ pid, started: new Date(Date.now() - age).toISOString() }));
w(60000, process.pid);
const young = await v.run(base);
const n1 = calls.length;
w(6 * 60000, process.pid);
const old = await v.run(base);
w(60000, 2147483000);
const dead = await v.run(base);
console.log(JSON.stringify({ young, n1, old, dead, n: calls.length, log: fs.readFileSync(D + "/cas-voice.log", "utf8") }));');
    assert_same('starting', $d['young']);
    assert_same(0, $d['n1'], 'spawned over a young live engine');
    assert_same(4242, $d['old'], 'did not restart an old pid');
    assert_same(4242, $d['dead'], 'did not restart a dead pid');
    assert_same(2, $d['n']);
    assert_true(str_contains($d['log'], 'already starting (pid ' . getmypid()) || str_contains($d['log'], 'already starting (pid '), 'no log line');
});

test('two voice launcher runs started together spawn once, only the spawner writes the pid, spawn is pythonw and hidden', function () {
    $d = sv_node('const rs = await Promise.all([v.run(base), v.run(base)]);
const pid = JSON.parse(fs.readFileSync(D + "/cas-voice.pid", "utf8"));
console.log(JSON.stringify({ rs, n: calls.length, pid: pid.pid, exe: calls[0].exe, hide: calls[0].opts.windowsHide, lock: has("cas-voice.lock") }));');
    assert_same(1, $d['n'], 'spawned twice');
    assert_same(4242, $d['pid']);
    assert_true(str_ends_with($d['exe'], 'pythonw.exe'), 'not pythonw');
    assert_same(true, $d['hide'], 'windowsHide');
    assert_same(false, $d['lock'], 'lock left behind');
    $d = sv_node('fs.writeFileSync(D + "/cas-voice.lock", "x");
const fresh = await v.run(base);
const n1 = calls.length, p1 = has("cas-voice.pid");
const old = new Date(Date.now() - 120000);
fs.utimesSync(D + "/cas-voice.lock", old, old);
const stale = await v.run(base);
console.log(JSON.stringify({ fresh, n1, p1, stale, n: calls.length }));');
    assert_same('locked', $d['fresh']);
    assert_same(0, $d['n1']);
    assert_same(false, $d['p1'], 'a refused run wrote the pid');
    assert_same(4242, $d['stale'], 'stale lock not replaced');
});

test('autostart exits when another copy holds the lock and takes a stale one', function () {
    $d = as_node('const lock = T + "/lock";
fs.writeFileSync(lock, String(' . getmypid() . '));
const held = a.takeLock(lock, process.pid);
fs.writeFileSync(lock, "999999");
const stale = a.takeLock(lock, process.pid);
console.log(JSON.stringify({ held, stale, now: fs.readFileSync(lock, "utf8") === String(process.pid) }));');
    assert_same(false, $d['held'], 'took a lock a live copy holds');
    assert_same(true, $d['stale'], 'stale lock not taken');
    assert_true($d['now'], 'lock not rewritten with this pid');
    $src = (string) file_get_contents(AS_ROOT . '/bin/autostart.cjs');
    assert_true((bool) preg_match('~if \(!takeLock\(\)\) \{[^}]*process\.exit\(0\)~', $src), 'main does not exit on a held lock');
});

test('it opens Chrome on monitor 2 in app mode once, and not again while the marker is live', function () {
    $d = as_node('const calls = [];
const run = (exe, args, opts) => { calls.push({ exe, args, opts }); return { pid: ' . getmypid() . ', unref() {} }; };
const m = T + "/marker";
a.openCockpit(m, run);
a.openCockpit(m, run);
console.log(JSON.stringify(calls));');
    assert_same(1, count($d), 'Chrome opened twice');
    assert_same(['--new-window', '--app=http://127.0.0.1:8787/cockpit.php', '--window-position=3440,-262', '--window-size=1080,1920', '--start-fullscreen'], $d[0]['args']);
    assert_true(str_ends_with(strtolower($d[0]['exe']), 'chrome.exe'), 'not chrome');
    assert_same(true, $d[0]['opts']['windowsHide'] ?? null, 'windowsHide');
});

test('the watchdog caps cas-voice restarts at 3 an hour', function () {
    $d = as_node('const h = [];
const H = 3600000, t0 = 10 * H, out = [];
for (const at of [t0, t0 + 1000, t0 + 2000, t0 + 3000]) { const ok = a.mayRestart(h, at); out.push(ok); if (ok) h.push(at); }
out.push(a.mayRestart(h, t0 + H + 1));
console.log(JSON.stringify(out));');
    assert_same([true, true, true, false, true], $d);
    assert_true(str_contains((string) file_get_contents(AS_ROOT . '/bin/autostart.cjs'), 'if (!mayRestart(history, Date.now()))'), 'watchdog skips the cap');
});

test('install-apache-autostart.ps1 backs up xampp-control.ini and starts Apache as Rob, never as a service', function () {
    $src = (string) file_get_contents(AS_ROOT . '/ops/install-apache-autostart.ps1');
    $copy = strpos($src, 'Copy-Item $Ini');
    $write = strpos($src, 'Set-Content $Ini');
    assert_true($copy !== false && $write !== false && $copy < $write, 'ini written before a backup');
    foreach (["'\\KC Dashboard\\'", '-AtLogOn', '-RunLevel Limited', '-RestartCount', 'Register-ScheduledTask', "'Apache=0'", '127.0.0.1:8787'] as $need) {
        assert_true(str_contains($src, $need), "missing $need");
    }
    assert_true(!str_contains($src, '-k install'), 'installs a service');
    assert_true(!preg_match('/RunLevel\s+Highest/i', $src), 'runs elevated');
    assert_true(str_contains($src, '$Httpd = \'C:\xampp\apache\bin\httpd.exe\''), 'httpd path');
});
