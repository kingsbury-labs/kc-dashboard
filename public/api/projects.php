<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/View.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(View::projects($_SERVER, $data . '/token', function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
}, $log));
