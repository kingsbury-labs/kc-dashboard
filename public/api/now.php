<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/Running/NowService.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
$token = fn(): string => Token::load($data . '/token', $log);
try {
    // handle() checks again; this one runs before fromConfig lists the project folders.
    $triple = NowService::allowed($_SERVER, $token)
        ? NowService::fromConfig($data, $log)->handle($_SERVER, $token)
        : Response::json(403, ['error' => 'forbidden']);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'now.php']);
    $triple = Response::error($e);
}
Response::emit($triple);
