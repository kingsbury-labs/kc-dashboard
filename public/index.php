<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Sys/Elevation.php';
require_once __DIR__ . '/../src/Jobs/AskHandler.php';
require_once __DIR__ . '/../src/Attention/Selector.php';
require_once __DIR__ . '/../src/News/Scorer.php';
require_once __DIR__ . '/../src/Numbers/GaCollector.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
$db = function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
};
Response::emit(View::page($_SERVER, $data . '/token', $log, [
    'projects' => ProjectList::fromConfig($log),
    // Same rows answers.php serves, so the list shows with JS off.
    'answers' => fn(): array => json_decode(AskApi::answers($_SERVER, $db())[2], true)['answers'] ?? [],
    'clients' => fn(): array => View::clientsData(require __DIR__ . '/../config/clients.php', $log),
    'elevated' => fn(): bool => Elevation::fromConfig($data . '/elevation.json'),
    'map' => fn(): array => View::projectRows($db()),
    // The last api/now.php snapshot only: a page load never starts a scan.
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
    // Same list attention.php serves, so it shows with JS off.
    'attention' => fn(): array => AttentionApi::data(new FactStore($db()), AttentionApi::linkPorts(), time()),
    // The first page news.php serves, so headlines show with JS off.
    'news' => fn(): array => NewsApi::data($db, NewsApi::topics(), '', 1, time(), NewsApi::PAGE_SIZE, $data . '/news-cache.json', false),
    // Same list numbers.php serves, so the numbers show with JS off.
    'numbers' => fn(): array => NumbersApi::data($db(), time()),
    // Files and the database only; nothing is started.
    'core' => fn(): array => View::coreData($db, (require __DIR__ . '/../config/executables.php')['claude'],
        (string) ((require __DIR__ . '/../config/vault.php')['root'] ?? ''), $log),
]));
