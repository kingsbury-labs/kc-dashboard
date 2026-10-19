<?php
require_once __DIR__ . '/../src/Fs/SafeDir.php';

function safedir_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsd_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function safedir_junction(string $link, string $target): void
{
    $proc = proc_open(
        ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
         'New-Item -ItemType Junction -Path $env:KCSD_LINK -Target $env:KCSD_TARGET | Out-Null'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['KCSD_LINK' => $link, 'KCSD_TARGET' => $target]
    );
    assert_true(is_resource($proc), 'could not start powershell');
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

test('a plain folder inside the root is accepted', function () {
    $root = safedir_temp();
    try {
        mkdir($root . '\\plain');
        assert_true(SafeDir::isDirectChild($root, 'plain'), 'plain folder');
    } finally {
        @rmdir($root . '\\plain');
        @rmdir($root);
    }
});

test('a name in the wrong case is rejected', function () {
    $root = safedir_temp();
    try {
        mkdir($root . '\\plain');
        assert_same(false, SafeDir::isDirectChild($root, 'PLAIN'));
    } finally {
        @rmdir($root . '\\plain');
        @rmdir($root);
    }
});

test('a junction pointing outside the root is rejected', function () {
    $root = safedir_temp();
    $outside = safedir_temp();
    try {
        safedir_junction($root . '\\link', $outside);
        assert_same(false, SafeDir::isDirectChild($root, 'link'));
    } finally {
        @rmdir($root . '\\link');
        @rmdir($outside);
        @rmdir($root);
    }
});

test('dot names, separators, drives, streams and NUL are rejected', function () {
    $root = safedir_temp();
    try {
        mkdir($root . '\\a');
        mkdir($root . '\\a\\b');
        foreach (['', '.', '..', 'a\\b', 'a/b', 'C:', 'a:x', "a\0"] as $name) {
            assert_same(false, SafeDir::isDirectChild($root, $name), var_export($name, true));
        }
    } finally {
        @rmdir($root . '\\a\\b');
        @rmdir($root . '\\a');
        @rmdir($root);
    }
});

test('missing folder, a file, and an unresolvable root are rejected', function () {
    $root = safedir_temp();
    try {
        file_put_contents($root . '\\f.txt', 'x');
        assert_same(false, SafeDir::isDirectChild($root, 'missing'), 'missing');
        assert_same(false, SafeDir::isDirectChild($root, 'f.txt'), 'file');
        assert_same(false, SafeDir::isDirectChild($root . '\\nope', 'x'), 'bad root');
    } finally {
        @unlink($root . '\\f.txt');
        @rmdir($root);
    }
});

// mklink through cmd, not New-Item: New-Item stores a volume GUID target as \??\\\?\Volume{...},
// which realpath cannot follow at all, so a test built on it passes with or without the inode check.
function safedir_mklink(string $link, string $target): void
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

test('a dot folder inside the root is rejected', function () {
    $root = safedir_temp();
    try {
        mkdir($root . '\\.hidden');
        mkdir($root . '\\.credentials');
        assert_same(false, SafeDir::isDirectChild($root, '.hidden'), '.hidden');
        assert_same(false, SafeDir::isDirectChild($root, '.credentials'), '.credentials');
    } finally {
        @rmdir($root . '\\.hidden');
        @rmdir($root . '\\.credentials');
        @rmdir($root);
    }
});

function safedir_volume_path(string $path): string
{
    $proc = proc_open(
        ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
         '(Get-Volume -DriveLetter $env:KCSD_DRIVE).UniqueId'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['KCSD_DRIVE' => $path[0]]
    );
    assert_true(is_resource($proc), 'could not start powershell');
    $guid = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    assert_true(str_starts_with($guid, '\\\\?\\Volume{'), 'no volume guid: ' . $guid);
    return rtrim($guid, '\\') . substr($path, 2);
}

test('a junction to a volume GUID path outside the root is rejected', function () {
    $root = safedir_temp();
    $outside = safedir_temp();
    try {
        safedir_mklink($root . '\\vol', safedir_volume_path($outside));
        // The bypass shape: realpath hands back the link itself and is_dir agrees, so only the inode check stops it.
        clearstatcache(true);
        assert_same(realpath($root) . '\\vol', realpath($root . '\\vol'), 'realpath left the junction unresolved');
        assert_true(is_dir($root . '\\vol'), 'junction reads as a folder');
        assert_same(false, SafeDir::isDirectChild($root, 'vol'));
    } finally {
        @rmdir($root . '\\vol');
        @rmdir($outside);
        @rmdir($root);
    }
});

test('a folder swapped for a junction after a check is rejected', function () {
    $root = safedir_temp();
    $outside = safedir_temp();
    try {
        mkdir($root . '\\swap');
        assert_true(SafeDir::isDirectChild($root, 'swap'), 'plain before swap');
        // Swap from another process, as a second request would, so PHP's own rmdir does not clear the cache.
        $proc = proc_open(['cmd.exe', '/c', 'rmdir', $root . '\\swap'], [], $pipes);
        proc_close($proc);
        safedir_junction($root . '\\swap', $outside);
        assert_same(false, SafeDir::isDirectChild($root, 'swap'));
    } finally {
        @rmdir($root . '\\swap');
        @rmdir($outside);
        @rmdir($root);
    }
});
