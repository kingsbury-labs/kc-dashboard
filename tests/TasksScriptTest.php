<?php
require_once __DIR__ . '/../src/Process/ProcessRunner.php';

// Allowlisted for this test only; config/executables.php is not what lets this test start it.
const TASKS_PS = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
const TASKS_PHP = 'c:\\xampp\\php\\php.exe';

// The stated schedule of every bin/*.php job. A new job without a line here fails the coverage test.
const TASKS_EXPECTED = [
    'worker.php' => 'repeat PT1M',
    'fetch-news.php' => 'repeat PT15M',
    'collect-facts.php' => 'repeat PT30M',
    'collect-projects.php' => 'repeat PT5M',
    'write-vault.php' => 'repeat PT5M',
    'collect-kc.php' => 'daily',
    'daily-read.php' => 'daily 06:00',
    'brief.php' => 'daily 06:15',
];

function tasks_src(): string
{
    return (string) file_get_contents(__DIR__ . '/../bin/register-tasks.ps1');
}

function tasks_repo(): string
{
    return (string) realpath(__DIR__ . '/..');
}

// One -WhatIf run, through ProcessRunner. A wrapper shadows every cmdlet that changes Task Scheduler
// with a function that prints a marker instead, so even a broken -WhatIf guard registers nothing.
function tasks_whatif(): array
{
    static $run = null;
    if ($run !== null) {
        return $run;
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kctasks_' . bin2hex(random_bytes(6));
    mkdir($dir);
    $wrapper = $dir . DIRECTORY_SEPARATOR . 'whatif.ps1';
    // ScheduledTasks is a CDXML module of functions: imported later, it would replace a shadow, so import it first,
    // shadow in global scope, and refuse to run the script (exit 3) unless every shadow is what resolves.
    $shadow = "Import-Module ScheduledTasks\n";
    foreach (['Register-ScheduledTask', 'Unregister-ScheduledTask', 'Set-ScheduledTask', 'Start-ScheduledTask',
        'Enable-ScheduledTask', 'Disable-ScheduledTask'] as $cmd) {
        // Straight to the console, so a caller's Out-Null cannot swallow the marker.
        $shadow .= 'function global:' . $cmd . " { [Console]::Out.WriteLine('SCHEDULER-CALLED " . $cmd . "') }\n"
            . 'if ((Get-Command ' . $cmd . ").Source -ne '') { exit 3 }\n";
    }
    file_put_contents($wrapper, $shadow . "& '" . tasks_repo() . "\\bin\\register-tasks.ps1' -WhatIf\n");
    try {
        $runner = new ProcessRunner(['powershell' => TASKS_PS]);
        $r = $runner->run([TASKS_PS, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $wrapper], null, 90);
    } finally {
        @unlink($wrapper);
        @rmdir($dir);
    }
    $tasks = [];
    foreach (preg_split('/\r?\n/', $r['stdout']) as $line) {
        if (str_starts_with($line, 'WHATIF | ')) {
            $f = explode(' | ', $line);
            $tasks[] = ['name' => $f[1], 'when' => $f[2], 'logon' => $f[3], 'instances' => $f[4],
                'runlevel' => $f[5], 'actions' => array_slice($f, 6)];
        }
    }
    return $run = ['result' => $r, 'tasks' => $tasks];
}

test('the script has no elevation, no KC Finance name, no logon trigger and no mail job', function () {
    $src = tasks_src();
    assert_true(stripos($src, 'Highest') === false, 'no -RunLevel Highest');
    assert_true(str_contains($src, '-RunLevel Limited'), 'runs limited');
    assert_true(str_contains($src, '-LogonType S4U'), 'S4U');
    assert_true(str_contains($src, '-MultipleInstances IgnoreNew'), 'IgnoreNew');
    assert_true(str_contains($src, '-WorkingDirectory $Repo'), 'working directory');
    assert_true(str_contains($src, "\$Php = '" . TASKS_PHP . "'"), 'absolute php.exe');
    assert_true(preg_match("/Name\s*=\s*'KC Finance/i", $src) !== 1, 'no KC Finance task');
    assert_true(stripos($src, 'AtLogOn') === false && stripos($src, 'AtStartup') === false, 'nothing at logon');
    assert_true(preg_match('/Scripts = @\([^)]*mail/i', $src) !== 1, 'no mail task');
    // Module-qualified calls would get past the test's shadow functions.
    assert_true(stripos($src, 'ScheduledTasks\\') === false, 'no module-qualified scheduler cmdlet');
    assert_same(1, substr_count($src, 'Register-ScheduledTask -'), 'one register call');
});

test('a -WhatIf run through ProcessRunner registers nothing and prints every task', function () {
    $w = tasks_whatif();
    assert_same(0, $w['result']['code'], 'exit code; stderr: ' . $w['result']['stderr']);
    assert_true(!str_contains($w['result']['stdout'], 'SCHEDULER-CALLED'), 'a scheduler cmdlet was called: ' . $w['result']['stdout']);
    assert_true(!str_contains($w['result']['stdout'], 'registered '), 'reported a registration');
    assert_same(7, count($w['tasks']), 'task lines');
});

test('every bin/*.php job has a task with its interval, S4U, IgnoreNew and the repo as working directory', function () {
    $tasks = tasks_whatif()['tasks'];
    $repo = tasks_repo();
    $jobs = array_map('basename', glob($repo . '/bin/*.php'));
    sort($jobs);
    $expected = array_keys(TASKS_EXPECTED);
    sort($expected);
    assert_same($expected, $jobs, 'bin/*.php jobs with a stated schedule');
    foreach (TASKS_EXPECTED as $job => $when) {
        $want = TASKS_PHP . ' "' . $repo . '\\bin\\' . $job . '" @ ' . $repo;
        $found = null;
        foreach ($tasks as $t) {
            foreach ($t['actions'] as $a) {
                if (strcasecmp($a, $want) === 0) {
                    $found = $t;
                }
            }
        }
        assert_true($found !== null, 'no task runs ' . $job);
        assert_true($found['when'] === $when || ($when === 'daily' && preg_match('/^daily \d\d:\d\d$/', $found['when']) === 1),
            $job . ' runs ' . $found['when'] . ', expected ' . $when);
        assert_same('S4U', $found['logon'], $job . ' logon');
        assert_same('IgnoreNew', $found['instances'], $job . ' instances');
        assert_same('Limited', $found['runlevel'], $job . ' run level');
    }
});

test('tasks live under \\KC Dashboard\\, none named KC Finance, and write-vault runs after collect-projects', function () {
    foreach (tasks_whatif()['tasks'] as $t) {
        assert_true(str_starts_with($t['name'], '\\KC Dashboard\\'), 'task path of ' . $t['name']);
        assert_true(stripos(substr($t['name'], strlen('\\KC Dashboard\\')), 'KC Finance') !== 0, 'KC Finance name');
        assert_true(stripos($t['name'], 'mail') === false, 'mail task');
        if (str_contains($t['actions'][0], 'collect-projects.php')) {
            assert_same(2, count($t['actions']), 'collect-projects task actions');
            assert_true(str_contains($t['actions'][1], 'write-vault.php'), 'write-vault second');
        }
    }
});
