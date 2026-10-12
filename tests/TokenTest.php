<?php
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Http/Token.php';

test('token is created once and reused without rewriting', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kctok_' . bin2hex(random_bytes(6));
    mkdir($dir);
    $path = $dir . DIRECTORY_SEPARATOR . 'token';
    $logPath = $dir . DIRECTORY_SEPARATOR . 'log';
    try {
        $log = new Log($logPath);
        $first = Token::load($path, $log);
        assert_true((bool) preg_match('/^[0-9a-f]{64}$/', $first), 'format');
        touch($path, time() - 100);
        clearstatcache();
        $mtime = filemtime($path);
        $second = Token::load($path, $log);
        clearstatcache();
        assert_true($first === $second, 'same value');
        assert_same($mtime, filemtime($path), 'mtime');
        assert_same(['token'], array_values(array_diff(scandir($dir), ['.', '..', 'log'])), 'one file');
        assert_same('[redacted]', $log->redact($first));
    } finally {
        @unlink($path);
        @unlink($logPath);
        @rmdir($dir);
    }
});
