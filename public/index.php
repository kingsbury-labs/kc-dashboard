<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Sys/Elevation.php';
require_once __DIR__ . '/../src/Jobs/AskHandler.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
Response::emit(View::page($_SERVER, $data . '/token', $log, [
    'projects' => ProjectList::fromConfig($log),
    // Same rows answers.php serves, so the list shows with JS off.
    'answers' => function () use ($data): array {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return json_decode(AskApi::answers($_SERVER, $pdo)[2], true)['answers'] ?? [];
    },
    'clients' => fn(): array => View::clientsData(require __DIR__ . '/../config/clients.php', $log),
    'elevated' => fn(): bool => Elevation::fromConfig($data . '/elevation.json'),
    'map' => function () use ($data): array {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return View::projectRows($pdo);
    },
    // The last api/now.php snapshot only: a page load never starts a scan.
    'now' => fn(): array => (array) (json_decode((string) @file_get_contents($data . '/now-cache.json'), true) ?? []),
]));
