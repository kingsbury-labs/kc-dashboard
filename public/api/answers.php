<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Jobs/AskHandler.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
try {
    $triple = AskApi::answersApi($_SERVER, $data . '/token', $log, function () use ($data): PDO {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return $pdo;
    });
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'answers.php']);
    $triple = Response::error($e);
}
Response::emit($triple);
