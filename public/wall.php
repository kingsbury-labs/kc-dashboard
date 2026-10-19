<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
// A panel shows once its card has shipped both its API and its script.
$has = fn(string $name): bool => is_file(__DIR__ . '/api/' . $name . '.php') && is_file(__DIR__ . '/assets/' . $name . '.js');
Response::emit(View::wall($_SERVER, $data . '/token', $log, [
    'map' => function () use ($data): array {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return View::projectRows($pdo);
    },
    // The last api/now.php snapshot only: a page load never starts a scan. The stream brings it up to date.
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
    'panels' => ['attention' => $has('attention'), 'news' => $has('news')],
]));
