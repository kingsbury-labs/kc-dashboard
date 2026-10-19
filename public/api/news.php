<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/News/Scorer.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(NewsApi::get($_SERVER, $data . '/token', function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
}, $log, NewsApi::topics(), $_GET, null, NewsApi::PAGE_SIZE, $data . '/news-cache.json'));
