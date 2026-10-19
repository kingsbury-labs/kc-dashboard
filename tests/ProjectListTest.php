<?php
require_once __DIR__ . '/../src/Projects/ProjectList.php';

function pl_temp(string $prefix = 'kcpl_'): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function pl_mklink(string $link, string $target): void
{
    $proc = proc_open('mklink /J "' . $link . '" "' . $target . '"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($proc), 'could not start cmd');
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

// Removes a temp tree; junctions are removed with rmdir, which never follows them.
function pl_rmtree(string $dir): void
{
    if (!file_exists($dir) && @lstat($dir) === false) {
        return;
    }
    $lst = @lstat($dir);
    $st = @stat($dir);
    if (is_dir($dir) && $lst !== false && $st !== false && $lst['ino'] === $st['ino']) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                pl_rmtree($dir . '\\' . $f);
            }
        }
        @rmdir($dir);
    } elseif (!@rmdir($dir)) {
        @unlink($dir);
    }
}

// Builds the fixture from the plan: a root with alpha/, .credentials/, .hidden/, semi;colon/, a file, a junction,
// one plain extra folder, one extra that is a junction and one extra that collides with alpha.
function pl_fixture(): array
{
    $root = pl_temp();
    $outside = pl_temp();
    $extraParent = pl_temp();
    foreach (['alpha', '.credentials', '.hidden', 'semi;colon'] as $d) {
        mkdir($root . '\\' . $d);
    }
    file_put_contents($root . '\\notes.txt', 'x');
    pl_mklink($root . '\\linked', $outside);
    $extraName = 'extra_' . bin2hex(random_bytes(3));
    mkdir($extraParent . '\\' . $extraName);
    mkdir($extraParent . '\\alpha');
    pl_mklink($extraParent . '\\jextra', $outside);
    return [
        'root' => $root, 'outside' => $outside, 'extraParent' => $extraParent, 'extraName' => $extraName,
        'extra' => [
            ['path' => $extraParent . '\\' . $extraName, 'busy_if_dirty' => 'scripts/'],
            $extraParent . '\\jextra',
            $extraParent . '\\alpha',
        ],
    ];
}

function pl_cleanup(array $f): void
{
    pl_rmtree($f['root']);
    pl_rmtree($f['extraParent']);
    pl_rmtree($f['outside']);
}

test('lists alpha and the plain extra only', function () {
    $f = pl_fixture();
    $log = $f['root'] . '.log';
    try {
        $list = new ProjectList($f['root'], $f['extra'], '', new Log($log));
        assert_same(['alpha', $f['extraName']], $list->names());
        $e = $list->resolve($f['extraName']);
        assert_same($f['extraParent'], $e['parent'] ?? null, 'extra parent');
        assert_same('scripts/', $e['busy_if_dirty'] ?? null, 'busy_if_dirty carried');
        assert_same(['name' => 'alpha', 'parent' => $f['root'], 'busy_if_dirty' => null], $list->resolve('alpha'));
        assert_true(str_contains((string) @file_get_contents($log), 'collides'), 'collision logged');
    } finally {
        pl_cleanup($f);
        @unlink($log);
    }
});

test('names outside the listing resolve to null', function () {
    $f = pl_fixture();
    try {
        $list = new ProjectList($f['root'], $f['extra']);
        foreach (['..', '.', 'alpha/..', 'alpha\\sub', 'C:\\Windows', '.credentials', '.hidden', 'linked', 'jextra',
                  'semi;colon', 'notes.txt', '', 'ALPHA', $f['root'] . '\\alpha'] as $name) {
            assert_same(null, $list->resolve($name), var_export($name, true));
        }
    } finally {
        pl_cleanup($f);
    }
});

test('the listing is fresh on every call', function () {
    $f = pl_fixture();
    try {
        $list = new ProjectList($f['root']);
        assert_same(['alpha'], $list->names());
        mkdir($f['root'] . '\\beta');
        assert_same(['alpha', 'beta'], $list->names());
        // A folder swapped for a junction drops out at once.
        rmdir($f['root'] . '\\beta');
        pl_mklink($f['root'] . '\\beta', $f['outside']);
        assert_same(null, $list->resolve('beta'));
    } finally {
        pl_cleanup($f);
    }
});

test('home is an entry only when it is a plain folder', function () {
    $f = pl_fixture();
    try {
        $home = new ProjectList($f['root'], [], $f['root'] . '\\alpha');
        assert_same(['name' => 'alpha', 'parent' => $f['root'], 'busy_if_dirty' => null], $home->home());
        assert_same(null, (new ProjectList($f['root'], [], $f['root'] . '\\linked'))->home(), 'junction home');
        assert_same(null, (new ProjectList($f['root'], [], ''))->home(), 'no home');
    } finally {
        pl_cleanup($f);
    }
});

test('the config lists D:\\Dev\\htdocs and kc-finance first among extras', function () {
    $c = require __DIR__ . '/../config/projects.php';
    assert_same('D:\\Dev\\htdocs', $c['root']);
    assert_same('C:\\Users\\roban\\kc-finance', $c['extra'][0]['path']);
    assert_same('scripts/', $c['extra'][0]['busy_if_dirty']);
});
