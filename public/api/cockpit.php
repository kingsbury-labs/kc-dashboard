<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/Cockpit/CockpitDeps.php';

// The cockpit's snapshot, answered from data/cockpit-cache.json. CockpitApi::handle runs the guard and the token
// before any of these closures, so a refused request opens no database and lists no folder. Nothing here starts a
// process or touches the network: the weather is the cache bin/collect-facts.php writes. A cache past its ttl is
// rebuilt after the response has gone, so the page never waits on the index.
$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
$deps = CockpitDeps::fromConfig($data, $log);
// POST adds to or ticks off the local follow-up list (CockpitDay::write, behind checkWrite).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Response::emit(CockpitDay::write($_SERVER, fn(): string => Token::load($data . '/token', $log),
        fn(): string => (string) file_get_contents('php://input', false, null, 0, 4096), $deps['day']['follow'], time(), $log));
    return;
}
$answer = CockpitApi::handle($_SERVER, fn(): string => Token::load($data . '/token', $log), $deps, $log);
if ($answer[0] !== 200 || !CockpitApi::stale($deps)) {
    Response::emit($answer);
    return;
}
ignore_user_abort(true);
set_time_limit(60);
$answer[1]['Content-Length'] = (string) strlen($answer[2]);
$answer[1]['Connection'] = 'close';
Response::emit($answer);
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    CockpitApi::refresh($deps, $log);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'cockpit refresh']);
}
