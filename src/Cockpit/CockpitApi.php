<?php
require_once __DIR__ . '/TranscriptIndex.php';
require_once __DIR__ . '/NeverShow.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Projects/LoopState.php';

/**
 * GET /api/cockpit.php: one snapshot of the agent half of the cockpit. The guard runs before the token is read, and
 * the token before the index opens, so a refused request touches nothing. Nothing here starts a process: live
 * session state comes from the saved api/now.php snapshot (and on the page, from the wall stream).
 * Shape: projects, sessions, agents (each with its parent), talk, needs, files, at, pending.
 */
class CockpitApi
{
    public const LIVE_SECONDS = 180;
    public const STEPS = ['work' => 1, 'gate' => 2, 'audit' => 3, 'push' => 4, 'done' => 4];

    /**
     * $deps: 'index' => fn(): TranscriptIndex, 'projects' => fn(): array (name => folder), 'now' => fn(): array
     * (a saved now.php snapshot), 'clock' => fn(): int, 'budget' => float seconds of indexing per request.
     */
    public static function handle(array $server, Closure $token, array $deps, ?Log $log = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard($token()))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, self::snapshot($deps, $log));
        } catch (Throwable $e) {
            $log?->exception($e, ['where' => 'cockpit']);
            return Response::error($e);
        }
    }

    public static function snapshot(array $deps, ?Log $log = null): array
    {
        $now = isset($deps['clock']) ? ($deps['clock'])() : time();
        $index = ($deps['index'])();
        $ran = $index->run((float) ($deps['budget'] ?? 2.0));
        $db = $index->pdo();
        $projects = self::part(fn() => ($deps['projects'] ?? fn() => [])(), [], $log);
        $live = self::part(fn() => ($deps['now'] ?? fn() => [])(), [], $log);
        $loops = [];
        foreach ($projects as $name => $dir) {
            $loops[$name] = self::part(fn() => self::loop((string) $dir), null, $log);
        }
        $passes = [];
        foreach ($loops as $name => $l) {
            foreach ((array) ($l['passes'] ?? []) as $sid => $p) {
                $passes[$sid] = $p + ['project' => $name];
            }
        }
        $since = $now - TranscriptIndex::FIRST_DAYS * 86400;
        $rows = $db->prepare('SELECT * FROM sessions WHERE ended >= ? ORDER BY ended DESC LIMIT 400');
        $rows->execute([$since]);
        $all = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $all[$r['key']] = $r;
        }
        // The live registry names a project, not a session id: its state goes to that project's newest main session.
        $liveBy = [];
        foreach ((array) ($live['sessions'] ?? []) as $s) {
            if (is_array($s) && is_string($s['project'] ?? null) && in_array($s['status'] ?? null, ['busy', 'waiting'], true)) {
                $liveBy[$s['project']] = ($liveBy[$s['project']] ?? null) === 'waiting' ? 'waiting' : $s['status'];
            }
        }
        $newest = [];
        $sessions = [];
        $agents = [];
        foreach ($all as $key => $r) {
            $root = $all[$r['session_id']] ?? null;
            $pass = $passes[$r['session_id']] ?? null;
            $project = (string) ($r['project'] ?? $root['project'] ?? '');
            $first = $r['agent_id'] === null && $pass === null && !isset($newest[$project]);
            if ($first) {
                $newest[$project] = true;
            }
            $state = self::state($r, $now, $first ? ($liveBy[$project] ?? null) : null);
            $base = [
                'key' => $key,
                'session' => $r['session_id'],
                'project' => (string) ($r['project'] ?? $root['project'] ?? $pass['project'] ?? ''),
                'model' => self::model($r['model']),
                'state' => $state,
                'tool' => (string) ($r['tool'] ?? ''),
                'target' => self::path((string) ($r['target'] ?? '')),
                'started' => (int) $r['started'],
                'ended' => (int) $r['ended'],
                'card' => $pass['card'] ?? null,
                'step' => $pass['step'] ?? null,
            ];
            if ($r['agent_id'] === null) {
                $sessions[] = $base + ['kind' => $pass !== null ? 'loop' : 'interactive',
                    'title' => (string) ($r['title'] ?? ''), 'newest' => $first];
            } else {
                $agents[] = $base + ['parent' => $r['parent_key'], 'type' => (string) ($r['agent_type'] ?? 'agent'),
                    'description' => (string) ($r['description'] ?? '')];
            }
        }
        $talk = self::talk($db, $all, $since);
        $files = $db->prepare('SELECT path, op, session_key, MAX(ts) AS ts FROM touched WHERE ts >= ? GROUP BY path, op ORDER BY ts DESC LIMIT 80');
        $files->execute([$since]);
        $touched = [];
        foreach ($files->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (!NeverShow::blocked((string) $f['path'])) {
                $touched[] = ['path' => (string) $f['path'], 'op' => (string) $f['op'], 'session' => (string) $f['session_key'], 'ts' => (int) $f['ts']];
            }
        }
        return [
            'at' => $now,
            'pending' => $ran['pending'],
            'projects' => self::projects($projects, $loops, $sessions, $agents),
            'sessions' => $sessions,
            'agents' => $agents,
            'talk' => $talk,
            'needs' => self::needs($loops, $sessions, $talk, $live),
            'files' => $touched,
        ];
    }

    // working while it wrote in the last few minutes, done once finished or handed back, waiting when the
    // live registry says so, else idle.
    private static function state(array $r, int $now, ?string $live): string
    {
        if ($live === 'waiting') {
            return 'waiting';
        }
        if ((int) $r['finished'] === 1) {
            return 'done';
        }
        if ($live === 'busy' || $now - (int) $r['ended'] <= self::LIVE_SECONDS) {
            return 'working';
        }
        return $r['agent_id'] === null ? 'idle' : 'done';
    }

    private static function model(?string $m): string
    {
        return is_string($m) ? preg_replace('/^claude-/', '', $m) : '';
    }

    private static function path(string $p): string
    {
        return NeverShow::blocked($p) ? '[hidden path]' : $p;
    }

    private static function talk(PDO $db, array $all, int $since): array
    {
        $q = $db->prepare('SELECT * FROM messages WHERE ts >= ? ORDER BY ts DESC LIMIT 60');
        $q->execute([$since]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $from = $all[$m['from_key']] ?? null;
            $to = is_string($m['to_key']) ? ($all[$m['to_key']] ?? null) : null;
            // The coordinator is whoever receives a report or sent the brief.
            $boss = $m['kind'] === 'handback' ? $to : $from;
            $cas = $m['kind'] === 'question' ? 'Needs Rob'
                : ($boss !== null && (int) $boss['replied'] > (int) $m['ts'] ? 'Cas routed it' : 'Cas is watching');
            $out[] = [
                'id' => (string) $m['id'],
                'kind' => (string) $m['kind'],
                'ts' => (int) $m['ts'],
                'from' => self::label($from, (string) $m['from_key']),
                'fromKey' => (string) $m['from_key'],
                'to' => $m['kind'] === 'question' ? 'Rob' : self::label($to, (string) ($m['to_key'] ?? $m['agent_type'] ?? 'agent')),
                'toKey' => (string) ($m['to_key'] ?? ''),
                'project' => (string) ($from['project'] ?? $to['project'] ?? ''),
                'text' => NeverShow::text((string) $m['text']),
                'cas' => $cas,
            ];
        }
        return $out;
    }

    private static function label(?array $s, string $fallback): string
    {
        if ($s === null) {
            return NeverShow::text($fallback, null, 40);
        }
        $who = $s['agent_id'] === null ? 'session ' . substr((string) $s['session_id'], 0, 8)
            : ($s['agent_type'] ?? 'agent') . ' ' . substr((string) $s['agent_id'], 0, 8);
        return $who . ($s['project'] ? ' · ' . $s['project'] : '');
    }

    private static function projects(array $dirs, array $loops, array $sessions, array $agents): array
    {
        $names = array_unique(array_merge(array_keys($dirs), array_column($sessions, 'project'), array_column($agents, 'project')));
        $out = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $l = $loops[$name] ?? null;
            $mine = fn(array $x) => $x['project'] === $name;
            $out[] = [
                'name' => $name,
                'sessions' => count(array_filter($sessions, $mine)),
                'agents' => count(array_filter($agents, $mine)),
                'working' => count(array_filter(array_merge($sessions, $agents), fn($x) => $x['project'] === $name && $x['state'] === 'working')),
                'loop' => $l === null ? null : ['card' => $l['card'], 'status' => $l['status'], 'note' => $l['note'], 'step' => $l['step']],
                'checklist' => $l['checklist'] ?? null,
                'parked' => $l['parked'] ?? [],
            ];
        }
        usort($out, fn($a, $b) => [$b['working'], $b['sessions'] + $b['agents'], $a['name']] <=> [$a['working'], $a['sessions'] + $a['agents'], $b['name']]);
        return $out;
    }

    private static function needs(array $loops, array $sessions, array $talk, array $live): array
    {
        $out = [];
        foreach ((array) ($live['sessions'] ?? []) as $s) {
            if (is_array($s) && ($s['status'] ?? null) === 'waiting') {
                $out[] = ['kind' => 'session', 'title' => NeverShow::text('Waiting: ' . (string) ($s['waitingFor'] ?? 'your answer'), null, 120),
                    'detail' => (string) ($s['project'] ?? 'session'), 'project' => (string) ($s['project'] ?? ''), 'ts' => 0];
            }
        }
        foreach ($loops as $name => $l) {
            foreach ((array) ($l['parked'] ?? []) as $p) {
                $out[] = ['kind' => 'parked', 'title' => 'Parked: ' . $p['title'], 'detail' => $name . ' · card ' . $p['id'], 'project' => $name, 'ts' => 0];
            }
            if (($l['status'] ?? null) === 'blocked') {
                $out[] = ['kind' => 'blocked', 'title' => NeverShow::text('Loop blocked: ' . (string) ($l['note'] ?? ''), null, 120),
                    'detail' => $name . ($l['card'] !== null ? ' · card ' . $l['card'] : ''), 'project' => $name, 'ts' => 0];
            }
        }
        foreach ($talk as $t) {
            if ($t['kind'] === 'question') {
                $out[] = ['kind' => 'question', 'title' => $t['text'], 'detail' => $t['from'], 'session' => explode(':', $t['fromKey'])[0], 'ts' => $t['ts']];
            }
        }
        return $out;
    }

    /**
     * A project's loop as files show it, read only: status.json (card, status, note), checklist.json (counts and
     * parked cards) and the stream files named <run>-card-<n>[-gateN|-pushN|-auditN].jsonl, whose first line gives
     * the pass's session id. Returns null when the project has no .claude/work.
     */
    public static function loop(string $dir): ?array
    {
        $claude = LoopState::subdir(rtrim($dir, '\\/'), '.claude');
        $work = $claude === null ? null : LoopState::subdir($claude, 'work');
        if ($work === null) {
            return null;
        }
        $out = ['card' => null, 'status' => null, 'note' => null, 'step' => null, 'checklist' => null, 'parked' => [], 'passes' => []];
        $done = [];
        $list = json_decode((string) @file_get_contents($work . '\\checklist.json', false, null, 0, 2000000), true);
        if (is_array($list) && array_is_list($list)) {
            $c = LoopState::checklist($work . '\\checklist.json');
            $out['checklist'] = ['cards' => $c['checklist_cards'], 'passing' => $c['checklist_passing']];
            foreach ($list as $e) {
                if (is_array($e) && isset($e['id']) && !empty($e['parked'])) {
                    $out['parked'][] = ['id' => (string) $e['id'], 'title' => NeverShow::text((string) ($e['title'] ?? ''), null, 120)];
                } elseif (is_array($e) && isset($e['id']) && ($e['passes'] ?? null) === true) {
                    $done[(string) $e['id']] = true;
                }
            }
        }
        $loop = LoopState::subdir($work, 'loop');
        if ($loop === null) {
            return $out;
        }
        $status = json_decode((string) @file_get_contents($loop . '\\status.json', false, null, 0, 65536), true);
        if (is_array($status)) {
            $out['card'] = is_scalar($status['card'] ?? null) ? NeverShow::text((string) $status['card'], null, 120) : null;
            $out['status'] = is_string($status['status'] ?? null) ? $status['status'] : null;
            $out['note'] = is_string($status['note'] ?? null) ? NeverShow::text($status['note'], null, 200) : null;
        }
        $files = glob($loop . '\\*-card-*.jsonl') ?: [];
        usort($files, fn($a, $b) => (int) @filemtime($a) <=> (int) @filemtime($b));
        foreach (array_slice($files, -40) as $f) {
            if (!preg_match('/-card-([\w.]+?)(?:-(gate|push|audit|ci)\d*)?\.jsonl$/', basename($f), $m)) {
                continue;
            }
            $pass = $m[2] ?? '';
            $pass = $pass === '' ? 'work' : ($pass === 'ci' ? 'gate' : $pass);
            $step = isset($done[$m[1]]) ? 4 : self::STEPS[$pass];
            $first = (string) @file_get_contents($f, false, null, 0, 65536);
            $sid = preg_match('/"session_id"\s*:\s*"([0-9a-f-]{8,64})"/', $first, $s) ? $s[1] : null;
            if ($sid !== null) {
                $passes = $out['passes'][$sid] ?? null;
                $out['passes'][$sid] = ['card' => $m[1], 'step' => max($step, (int) ($passes['step'] ?? 0)), 'pass' => $pass];
            }
            $out['step'] = $step;
        }
        return $out;
    }

    private static function part(Closure $fn, $default, ?Log $log)
    {
        try {
            $v = $fn();
            return is_array($v) ? $v : $default;
        } catch (Throwable $e) {
            $log?->exception($e, ['where' => 'cockpit source']);
            return $default;
        }
    }
}
