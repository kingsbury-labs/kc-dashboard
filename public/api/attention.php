<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Attention/Selector.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(AttentionApi::get($_SERVER, $data . '/token', function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
}, $log, AttentionApi::linkPorts()));
