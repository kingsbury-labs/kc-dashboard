<?php
require_once __DIR__ . '/../src/Log.php';

function log_temp_path(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kclog_' . bin2hex(random_bytes(6)) . '.log';
}

test('nested array secret is redacted and absent from the file', function () {
    $path = log_temp_path();
    try {
        $log = new Log($path);
        $log->addSecret('fake-secret-AAA111');
        $log->info('hello fake-secret-AAA111', ['a' => ['b' => ['c' => 'x fake-secret-AAA111 y']], 'n' => 5]);
        $text = file_get_contents($path);
        assert_true(strpos($text, 'fake-secret-AAA111') === false, 'secret leaked');
        assert_true(substr_count($text, '[redacted]') === 2, 'expected two redactions');
        assert_true(strpos($text, '"n":5') !== false, 'non-string kept');
    } finally {
        @unlink($path);
    }
});

test('secret in exception message and trace is redacted', function () {
    $path = log_temp_path();
    try {
        $log = new Log($path);
        $log->addSecret('fake-secret-BBB222');
        $e = new RuntimeException('failed with fake-secret-BBB222');
        $log->exception($e, [], true);
        $log->info('ctx', ['err' => $e]);
        $text = file_get_contents($path);
        assert_true(strpos($text, 'fake-secret-BBB222') === false, 'secret leaked');
        assert_true(strpos($text, 'failed with [redacted]') !== false, 'message not redacted');
    } finally {
        @unlink($path);
    }
});

test('newlines in values cannot forge a second entry', function () {
    $path = log_temp_path();
    try {
        $log = new Log($path);
        $log->info("a\nERROR forged");
        assert_same(1, count(file($path)));
    } finally {
        @unlink($path);
    }
});
