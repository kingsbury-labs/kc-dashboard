<?php
require_once __DIR__ . '/../public/api/voice.php';

// Card 124: api/voice.php against a fake listener on a free loopback port and a fake pid file. A made-up token.
define('VA_TOKEN', str_repeat('5d', 32));

function va_server(array $extra = []): array
{
    return $extra + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_X_KC_TOKEN' => VA_TOKEN];
}

function va_pid(int $pid): string
{
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcva_' . bin2hex(random_bytes(6)) . '.pid';
    file_put_contents($f, json_encode(['pid' => $pid, 'started' => '2026-11-01T00:00:00Z']));
    return $f;
}

// A port nothing listens on: bound, read, closed.
function va_closed_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

function va_state(array $answer): string
{
    assert_same(200, $answer[0]);
    return json_decode($answer[2], true)['state'];
}

test('voice.php says up when the port takes a connection, whatever the pid file says', function () {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    assert_same('up', va_state(voice_handle(va_server(), fn() => VA_TOKEN, $port, va_pid(0))));
    fclose($s);
});

test('voice.php says starting while the pid is alive and the port is not up, down when the pid is dead or missing', function () {
    $port = va_closed_port();
    assert_same('starting', va_state(voice_handle(va_server(), fn() => VA_TOKEN, $port, va_pid(getmypid()))), 'live pid');
    assert_same('down', va_state(voice_handle(va_server(), fn() => VA_TOKEN, $port, va_pid(4194300))), 'dead pid');
    assert_same('down', va_state(voice_handle(va_server(), fn() => VA_TOKEN, $port, sys_get_temp_dir() . '/kcva_missing.pid')), 'no file');
    assert_same(true, voice_alive(getmypid()));
    assert_same(false, voice_alive(4194300));
});

test('voice.php refuses a request without the token, with a wrong one, a POST or another site', function () {
    $port = va_closed_port();
    $pid = va_pid(getmypid());
    $no = va_server();
    unset($no['HTTP_X_KC_TOKEN']);
    foreach (['no token' => $no, 'wrong' => va_server(['HTTP_X_KC_TOKEN' => str_repeat('00', 32)]),
        'post' => ['REQUEST_METHOD' => 'POST'] + va_server(),
        'cross-site' => va_server(['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'cors'])] as $name => $server) {
        [$status, , $body] = voice_handle($server, fn() => VA_TOKEN, $port, $pid);
        assert_same(403, $status, $name);
        assert_true(!str_contains($body, 'state'), "$name leaks the state");
    }
    [$status] = voice_handle(va_server(), fn() => '', $port, $pid);
    assert_same(403, $status, 'empty server token');
});
