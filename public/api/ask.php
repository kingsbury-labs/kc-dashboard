<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Jobs/AskHandler.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
try {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    $triple = AskApi::ask($_SERVER, (string) file_get_contents('php://input', false, null, 0, 65536),
        $data . '/token', $log, $pdo, ProjectList::fromConfig($log));
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'ask.php']);
    $triple = Response::error($e);
}
Response::emit($triple);
