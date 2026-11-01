<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/View.php';
require_once __DIR__ . '/../../src/Cockpit/CockpitApi.php';

// The cockpit's snapshot. CockpitApi::handle runs the guard and the token before any of these closures, so a refused
// request opens no database and lists no folder. Nothing here starts a process or touches the network: the weather
// is the cache bin/collect-facts.php writes.
$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
$config = require __DIR__ . '/../../config/running.php';
$collectors = require __DIR__ . '/../../config/collectors.php';
$projects = function () use ($log): array {
    $list = ProjectList::fromConfig($log);
    $out = [];
    foreach ($list->names() as $name) {
        $out[$name] = rtrim($list->resolve($name)['parent'], '\\') . '\\' . $name;
    }
    return $out;
};
Response::emit(CockpitApi::handle($_SERVER, fn(): string => Token::load($data . '/token', $log), [
    'index' => fn(): TranscriptIndex => new TranscriptIndex($data . '/cockpit.sqlite', (string) $config['transcripts_dir'], $log),
    'projects' => $projects,
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
    'daily' => [
        'weather' => $data . '/' . $collectors['weather']['cache'],
        'news' => function () use ($data): PDO {
            $pdo = Db::open($data . '/dashboard.sqlite');
            Db::migrate($pdo);
            return $pdo;
        },
        'finance' => (string) $collectors['finance_path'],
        'usage' => fn(): array => array_merge(array_map(fn(string $d): string => $d . '\\.claude\\work\\loop', array_values($projects())),
            [(string) $config['transcripts_dir']]),
    ],
], $log));
