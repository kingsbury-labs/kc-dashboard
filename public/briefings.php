<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Briefing/Composer.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
// Reads stored briefings only: a navigation, which any site can cause, never builds one.
Response::emit(View::briefingsPage($_SERVER, $data . '/token', $log, function () use ($data): Composer {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return new Composer($pdo);
}, $_GET));
