<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../View.php';
require_once __DIR__ . '/CockpitApi.php';

// CockpitApi's deps from the running config, shared by api/cockpit.php and bin/collect-facts.php (which refreshes
// the snapshot cache on its run). Every part is a closure or a path: nothing opens until the snapshot is built.
class CockpitDeps
{
    public static function fromConfig(string $data, Log $log): array
    {
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
        return [
            'cache' => $data . '/cockpit-cache.json',
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
                'codex' => (string) $config['codex_sessions_dir'],
                // Loop pass files and every transcript, main and subagent, newest first (CockpitDaily::usage).
                'usage' => fn(): array => array_merge(array_map(fn(string $d): string => $d . '\\.claude\\work\\loop', array_values($projects())),
                    [(string) $config['transcripts_dir']]),
            ],
            'day' => [
                'calendar' => $data . '/' . $collectors['calendar']['cache'],
                'mail' => $data . '/' . $collectors['mail']['cache'],
                'follow' => $data . '/' . $collectors['followups'],
                'tz' => (string) $collectors['calendar']['tz'],
            ],
        ];
    }
}
