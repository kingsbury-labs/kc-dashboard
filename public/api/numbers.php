<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Numbers/GaCollector.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(NumbersApi::get($_SERVER, $data . '/token', function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
}, $log));
