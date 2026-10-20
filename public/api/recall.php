<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Memory/MemoryStore.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(MemoryApi::recall($_SERVER, $_GET, $data . '/token', function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
}, $log));
