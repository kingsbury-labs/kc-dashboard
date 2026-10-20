<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Attention/Selector.php';
require_once __DIR__ . '/../src/Numbers/GaCollector.php';

$data = __DIR__ . '/../data';
$log = new Log($data . '/app.log');
$db = function () use ($data): PDO {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return $pdo;
};
// A panel shows once its card has shipped both its API and its script.
$has = fn(string $name): bool => is_file(__DIR__ . '/api/' . $name . '.php') && is_file(__DIR__ . '/assets/' . $name . '.js');
Response::emit(View::wall($_SERVER, $data . '/token', $log, [
    'map' => fn(): array => View::projectRows($db()),
    // The last api/now.php snapshot only: a page load never starts a scan. The stream brings it up to date.
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
    'attention' => fn(): array => AttentionApi::data(new FactStore($db()), AttentionApi::linkPorts(), time()),
    'numbers' => fn(): array => NumbersApi::data($db(), time()),
    'clients' => fn(): array => View::clientsData(require __DIR__ . '/../config/clients.php', $log),
    'core' => fn(): array => View::coreData($db, (require __DIR__ . '/../config/executables.php')['claude'],
        (string) ((require __DIR__ . '/../config/vault.php')['root'] ?? ''), $log),
    'panels' => ['attention' => $has('attention'), 'news' => $has('news')],
]));
