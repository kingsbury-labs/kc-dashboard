<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/View.php';
require_once __DIR__ . '/../../src/Cockpit/CockpitApi.php';

// The cockpit's snapshot. CockpitApi::handle runs the guard and the token before any of these closures, so a refused
// request opens no database and lists no folder. Nothing here starts a process.
$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
$config = require __DIR__ . '/../../config/running.php';
Response::emit(CockpitApi::handle($_SERVER, fn(): string => Token::load($data . '/token', $log), [
    'index' => fn(): TranscriptIndex => new TranscriptIndex($data . '/cockpit.sqlite', (string) $config['transcripts_dir'], $log),
    'projects' => function () use ($log): array {
        $list = ProjectList::fromConfig($log);
        $out = [];
        foreach ($list->names() as $name) {
            $out[$name] = rtrim($list->resolve($name)['parent'], '\\') . '\\' . $name;
        }
        return $out;
    },
    'now' => fn(): array => View::readSnapshot($data . '/now-cache.json'),
], $log));
