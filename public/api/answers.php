<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Jobs/AskHandler.php';

$data = __DIR__ . '/../../data';
try {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    $triple = AskApi::answers($_SERVER, $pdo);
} catch (Throwable $e) {
    (new Log($data . '/app.log'))->exception($e, ['where' => 'answers.php']);
    $triple = Response::error($e);
}
Response::emit($triple);
