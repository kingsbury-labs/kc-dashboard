<?php
require_once __DIR__ . '/../src/Http/RequestGuard.php';

const GUARD_TOKEN = 'fake-token-0123456789abcdef0123456789abcdef';

function guard_ok(string $host): array
{
    return [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => $host,
        'SERVER_NAME' => 'localhost',
        'REQUEST_METHOD' => 'POST',
        RequestGuard::TOKEN_HEADER => GUARD_TOKEN,
        'HTTP_ORIGIN' => 'http://' . $host,
    ];
}

test('accepts both exact pairs for read and write', function () {
    $g = new RequestGuard(GUARD_TOKEN);
    foreach (['127.0.0.1:8787', 'localhost:8787'] as $host) {
        foreach (['127.0.0.1', '::1'] as $addr) {
            $s = guard_ok($host);
            $s['REMOTE_ADDR'] = $addr;
            assert_true($g->checkRead($s), "read $addr $host");
            assert_true($g->checkWrite($s), "write $addr $host");
        }
    }
});

test('rejects bad address and hosts on read and write', function () {
    $g = new RequestGuard(GUARD_TOKEN);
    $s = guard_ok('localhost:8787');
    $s['REMOTE_ADDR'] = '192.168.1.5';
    $s['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
    $s['HTTP_FORWARDED'] = 'for=127.0.0.1';
    assert_true(!$g->checkRead($s), 'remote addr');
    assert_true(!$g->checkWrite($s), 'remote addr write');
    foreach (['localhost', 'LOCALHOST:8787', 'localhost.:8787', 'localhost:8787,evil.com', '[::1]:8787', 'evil.com'] as $host) {
        $s = guard_ok($host);
        $s['HTTP_X_FORWARDED_HOST'] = 'localhost:8787';
        assert_true(!$g->checkRead($s), "read host $host");
        assert_true(!$g->checkWrite($s), "write host $host");
    }
    $s = guard_ok('localhost:8787');
    unset($s['HTTP_HOST']);
    assert_true(!$g->checkRead($s), 'missing host');
});

test('write rejects method, token and origin cases', function () {
    $g = new RequestGuard(GUARD_TOKEN);
    $cases = [];
    $s = guard_ok('localhost:8787'); $s['REQUEST_METHOD'] = 'GET'; $cases['GET'] = $s;
    $s = guard_ok('localhost:8787'); unset($s[RequestGuard::TOKEN_HEADER]); $cases['no token'] = $s;
    $s = guard_ok('localhost:8787'); $s[RequestGuard::TOKEN_HEADER] = 'fake-token-ffffffffffffffffffffffffffffffff'; $cases['wrong token'] = $s;
    $s = guard_ok('localhost:8787'); $s[RequestGuard::TOKEN_HEADER] = 'fake'; $cases['short token'] = $s;
    $s = guard_ok('localhost:8787'); $s[RequestGuard::TOKEN_HEADER] = ''; $cases['empty token'] = $s;
    foreach (['null', 'chrome-extension://abc', 'http://localhost:8787.evil.com', 'http://user@localhost:8787'] as $o) {
        $s = guard_ok('localhost:8787'); $s['HTTP_ORIGIN'] = $o; $cases["origin $o"] = $s;
    }
    $s = guard_ok('localhost:8787'); unset($s['HTTP_ORIGIN']); $cases['missing origin'] = $s;
    $s = guard_ok('127.0.0.1:8787'); $s['HTTP_ORIGIN'] = 'http://localhost:8787'; $cases['origin other host'] = $s;
    foreach ($cases as $name => $s) {
        assert_same('localhost', $s['SERVER_NAME']);
        assert_true(!$g->checkWrite($s), $name);
    }
});
