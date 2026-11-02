<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Log.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/Http/RequestGuard.php';
require_once __DIR__ . '/../../src/Http/Response.php';

// Card 124: GET /api/voice.php, read-only. Says whether the cas-voice engine is up (8766 takes a connection),
// starting (the pid bin/cas-voice-start.cjs wrote to data/cas-voice.pid is alive, 8766 not yet up) or down.
// The guard and the token run before the port or the pid file is looked at. Nothing is started or written.

// True when something on this machine takes a TCP connection on the port within a second.
function voice_listening(int $port): bool
{
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
    if ($s === false) {
        return false;
    }
    fclose($s);
    return true;
}

// Whether a pid is running. tasklist, with no shell; under Apache PHP adds CREATE_NO_WINDOW to every proc_open.
function voice_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    $p = @proc_open(['tasklist', '/fi', 'PID eq ' . $pid, '/fo', 'csv', '/nh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return false;
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return str_contains($out, '"' . $pid . '"');
}

function voice_state(int $port, string $pidFile, ?Closure $alive = null): string
{
    if (voice_listening($port)) {
        return 'up';
    }
    $d = json_decode((string) @file_get_contents($pidFile), true);
    $pid = is_array($d) && is_int($d['pid'] ?? null) ? $d['pid'] : 0;
    return ($alive ?? fn(int $p): bool => voice_alive($p))($pid) ? 'starting' : 'down';
}

function voice_handle(array $server, Closure $token, int $port, string $pidFile, ?Closure $alive = null, ?Log $log = null): array
{
    try {
        if (($server['REQUEST_METHOD'] ?? null) !== 'GET' || !(new RequestGuard($token()))->checkApiRead($server)) {
            return Response::json(403, ['error' => 'forbidden']);
        }
        return Response::json(200, ['state' => voice_state($port, $pidFile, $alive)]);
    } catch (Throwable $e) {
        $log?->exception($e, ['where' => 'voice']);
        return Response::error($e);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $data = __DIR__ . '/../../data';
    $log = new Log($data . '/app.log');
    Response::emit(voice_handle($_SERVER, fn(): string => Token::load($data . '/token', $log), 8766, $data . '/cas-voice.pid', null, $log));
}
