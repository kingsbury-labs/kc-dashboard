<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
Response::emit(View::projectsPage($_SERVER, $data . '/token', $log, [
    'map' => function () use ($data): array {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return View::projectRows($pdo);
    },
    // The last api/now.php snapshot only: a page load never starts a scan.
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
]));
