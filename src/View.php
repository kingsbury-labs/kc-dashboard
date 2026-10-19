<?php
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Http/Token.php';
require_once __DIR__ . '/Http/RequestGuard.php';
require_once __DIR__ . '/Http/Response.php';
require_once __DIR__ . '/Projects/ProjectList.php';
require_once __DIR__ . '/Clients/DashboardParser.php';
require_once __DIR__ . '/Clients/PipelineParser.php';

// The new-tab page and its clients endpoint. Every value from a file, the database or a model is escaped here.
class View
{
    /**
     * The guard runs before the token is read, so a refused request never touches it, and before any of $deps:
     * 'projects' => ProjectList, 'answers' => fn(): array, 'clients' => fn(): array, 'elevated' => fn(): bool,
     * 'map' => fn(): array (View::projectRows), 'now' => fn(): array (a saved api/now.php snapshot; never a scan).
     * A failing source leaves its part of the page empty, not a 500.
     */
    public static function page(array $server, string $tokenPath, Log $log, array $deps = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $projects = isset($deps['projects']) ? $deps['projects']->names() : [];
            $answers = self::source($deps['answers'] ?? null, [], $log, 'answers');
            $clients = self::source($deps['clients'] ?? null, ['clients' => [], 'leads' => []], $log, 'clients');
            $elevated = self::source($deps['elevated'] ?? null, false, $log, 'elevation') === true;
            $map = self::source($deps['map'] ?? null, [], $log, 'map');
            $running = self::source($deps['now'] ?? null, [], $log, 'now');
            $strip = self::section(fn() => self::strip(self::cleanSnapshot($running), is_array($map) ? $map : []), $log, 'strip');
            $tiles = self::section(fn() => self::tiles(is_array($map) ? $map : [], $now ?? time()), $log, 'tiles');
            return Response::html(200, self::render($token, $projects, $answers, $clients, $elevated,
                $strip !== '' ? $strip : '<li class="quiet">Nothing running.</li>', $tiles));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    /**
     * GET /wall.php, the second-monitor HUD. A page like the new tab, so checkRead, and $deps run only after it:
     * 'map' and 'now' as for page(), 'panels' => ['attention' => bool, 'news' => bool] for the panels whose API exists.
     */
    public static function wall(array $server, string $tokenPath, Log $log, array $deps = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $map = self::source($deps['map'] ?? null, [], $log, 'map');
            $running = self::source($deps['now'] ?? null, [], $log, 'now');
            $strip = self::section(fn() => self::strip(self::cleanSnapshot($running), is_array($map) ? $map : []), $log, 'strip');
            $tiles = self::section(fn() => self::tiles(is_array($map) ? $map : [], $now ?? time()), $log, 'tiles');
            $panels = is_array($deps['panels'] ?? null) ? $deps['panels'] : [];
            return Response::html(200, self::renderWall($token, $strip !== '' ? $strip : '<li class="quiet">Nothing running.</li>',
                $tiles, ($panels['attention'] ?? false) === true, ($panels['news'] ?? false) === true));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    private static function renderWall(string $token, string $strip, string $tiles, bool $attention, bool $news): string
    {
        $t = self::e($token);
        $scripts = ($attention ? '<script type="module" src="/assets/attention.js"></script>' . "\n" : '')
            . ($news ? '<script type="module" src="/assets/news.js"></script>' . "\n" : '');
        $extra = ($attention ? '<section class="panel attention" aria-labelledby="attention-h">' . "\n"
                . '<h2 id="attention-h">Needs attention</h2>' . "\n" . '<ul id="attention" class="list"></ul>' . "\n</section>\n" : '')
            . ($news ? '<section class="panel news" aria-labelledby="news-h">' . "\n"
                . '<h2 id="news-h">Headlines</h2>' . "\n" . '<ul id="news" class="list"></ul>' . "\n</section>\n" : '');
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<noscript><meta http-equiv="refresh" content="30"></noscript>
<title>KC Wall</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<link rel="stylesheet" href="/assets/wall.css">
<script type="module" src="/assets/orb.js"></script>
<script type="module" src="/assets/projects.js"></script>
<script type="module" src="/assets/wall.js"></script>
$scripts</head>
<body class="wall">
<main class="wall-grid">
<section class="wall-orb" aria-labelledby="wall-h">
<h1 id="wall-h">Kingsbury Creative</h1>
<div class="orb" aria-hidden="true">
<div class="orb-static" id="orb-static"></div>
<canvas id="orb" class="orb-canvas" width="480" height="480" hidden></canvas>
</div>
<button type="button" id="wall-full" hidden>Fullscreen</button>
<p class="note" id="wall-note" aria-live="polite"></p>
</section>

<section class="strip" aria-labelledby="strip-h">
<h2 id="strip-h">Running now</h2>
<ul id="running" class="strip-list">$strip</ul>
</section>

<section class="panel map" aria-labelledby="map-h">
<h2 id="map-h">Projects</h2>
<ul id="projects" class="tiles">$tiles</ul>
</section>
$extra</main>
</body>
</html>
HTML;
    }

    // GET /api/projects.php: {"projects": [...]} in map order. The database opens only after the guard and token pass.
    public static function projects(array $server, string $tokenPath, Closure $pdo, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, ['projects' => self::projectRows($pdo())]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'projects']);
            return Response::error($e);
        }
    }

    private const PROJECT_COLS = ['slug', 'branch', 'ahead', 'behind', 'dirty', 'last_sha', 'last_date', 'last_subject',
        'loop_state', 'loop_card', 'loop_note', 'checklist_cards', 'checklist_passing', 'scanned_at', 'error'];

    // The projects table without paths: running loop first, then dirty, then newest commit, then by name.
    // Sorted here, not in SQL: commit dates carry their own UTC offsets, so comparing the strings would misorder them.
    public static function projectRows(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT ' . implode(', ', self::PROJECT_COLS) . ' FROM projects')->fetchAll(PDO::FETCH_ASSOC);
        $key = function (array $r): array {
            $t = $r['last_date'] !== null ? strtotime((string) $r['last_date']) : false;
            return [$r['loop_state'] === 'running' ? 0 : 1, (int) $r['dirty'] > 0 ? 0 : 1, $t === false ? PHP_INT_MAX : -$t];
        };
        usort($rows, fn(array $a, array $b): int => [$key($a), $a['slug']] <=> [$key($b), $b['slug']]);
        return $rows;
    }

    // GET /api/clients.php: {"clients": [...], "leads": [...]}. Needs the token, read only after checkRead passes.
    public static function clients(array $server, string $tokenPath, array $config, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, self::clientsData($config, $log));
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'clients']);
            return Response::error($e);
        }
    }

    // Active clients with their links, soonest due first (no date last), and the active leads.
    public static function clientsData(array $config, ?Log $log = null): array
    {
        $dir = rtrim((string) ($config['dir'] ?? ''), '\\/');
        $dash = @file_get_contents($dir . DIRECTORY_SEPARATOR . '_DASHBOARD.md');
        $pipe = @file_get_contents($dir . DIRECTORY_SEPARATOR . '_PIPELINE.md');
        $parsed = DashboardParser::parse((string) $dash);
        if ($parsed['duplicates'] !== []) {
            $log?->info('clients: duplicate slug in _DASHBOARD.md', ['slugs' => $parsed['duplicates']]);
        }
        $clients = DashboardParser::withLinks($parsed['clients'], (array) ($config['links'] ?? []));
        $order = array_keys($clients);
        usort($order, function (int $a, int $b) use ($clients): int {
            $da = $clients[$a]['due'];
            $db = $clients[$b]['due'];
            if ($da !== $db) {
                return $da === null ? 1 : ($db === null ? -1 : strcmp($da, $db));
            }
            return $a <=> $b;
        });
        return [
            'clients' => array_map(fn($i) => $clients[$i], $order),
            'leads' => PipelineParser::parse((string) $pipe),
            'duplicates' => $parsed['duplicates'],
        ];
    }

    private static function source($fn, $default, Log $log, string $what)
    {
        if (!is_callable($fn)) {
            return $default;
        }
        try {
            return $fn();
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'page ' . $what]);
            return $default;
        }
    }

    public const SNAPSHOT_CAP = 262144;

    // The saved api/now.php snapshot: a missing, over-cap or unreadable file is no snapshot.
    public static function readSnapshot(string $file, int $cap = self::SNAPSHOT_CAP): array
    {
        $raw = @file_get_contents($file, false, null, 0, $cap + 1);
        if ($raw === false || strlen($raw) > $cap) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // One section of the page in its own try: a bad value leaves that section empty, never a 500.
    private static function section(callable $fn, Log $log, string $what): string
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'page ' . $what]);
            return '';
        }
    }

    // The snapshot file is not trusted: keep the four lists, each entry an array, each field a string, int or bool.
    private static function cleanSnapshot($now): array
    {
        $out = [];
        foreach (['sessions', 'loops', 'tasks', 'ports'] as $key) {
            if (!is_array($now) || !isset($now[$key]) || !is_array($now[$key])) {
                continue;
            }
            $out[$key] = [];
            foreach ($now[$key] as $item) {
                if (is_array($item)) {
                    $out[$key][] = array_filter($item, fn($v) => is_string($v) || is_int($v) || is_bool($v));
                }
            }
        }
        return $out;
    }

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    // The running strip: sessions busy or waiting, running loops, failed tasks, listening ports. Loops come from the
    // snapshot when there is one, otherwise from the projects table, so the strip shows loops even before any poll.
    private static function strip(array $now, array $map): string
    {
        $items = '';
        foreach ((array) ($now['sessions'] ?? []) as $s) {
            if (in_array($s['status'] ?? null, ['busy', 'waiting'], true)) {
                $what = ($s['status'] === 'waiting' && ($s['waitingFor'] ?? '') !== '') ? 'waiting: ' . $s['waitingFor'] : $s['status'];
                $items .= self::stripItem('session', (string) ($s['project'] ?? $s['name'] ?? 'session'), $what);
            }
        }
        $loops = isset($now['loops']) ? (array) $now['loops']
            : array_map(fn($r) => ['project' => $r['slug'], 'state' => $r['loop_state'], 'card' => $r['loop_card']], $map);
        foreach ($loops as $l) {
            if (($l['state'] ?? null) === 'running') {
                $items .= self::stripItem('loop', (string) ($l['project'] ?? ''), 'card ' . ($l['card'] ?? '?'));
            }
        }
        foreach ((array) ($now['tasks'] ?? []) as $t) {
            if (($t['failed'] ?? false) === true) {
                $items .= self::stripItem('task', (string) ($t['name'] ?? ''), 'failed ' . ($t['lastResult'] ?? ''));
            }
        }
        foreach ((array) ($now['ports'] ?? []) as $p) {
            $items .= self::stripItem('port', ':' . ($p['port'] ?? ''), (string) ($p['program'] ?? ''));
        }
        return $items !== '' ? $items : '<li class="quiet">Nothing running.</li>';
    }

    private static function stripItem(string $kind, string $name, string $what): string
    {
        return '<li data-kind="' . self::e($kind) . '"><span class="name">' . self::e($name) . '</span> <span class="tag">'
            . self::e($what) . '</span></li>';
    }

    private static function tiles(array $rows, int $now): string
    {
        $out = '';
        foreach ($rows as $r) {
            $age = self::age($r['last_date'] ?? null, $now);
            $loop = ($r['loop_state'] ?? null) !== null && $r['loop_state'] !== 'none'
                ? '<p class="loop" data-state="' . self::e($r['loop_state']) . '">Loop ' . self::e($r['loop_state'])
                    . (($r['loop_card'] ?? null) !== null ? ', card ' . self::e($r['loop_card']) : '')
                    . (($r['loop_note'] ?? null) !== null ? ': ' . self::e($r['loop_note']) : '') . '</p>'
                : '';
            $checklist = ($r['checklist_cards'] ?? null) !== null
                ? '<p class="meta">Checklist ' . (int) $r['checklist_passing'] . ' of ' . (int) $r['checklist_cards'] . '</p>' : '';
            $out .= '<li class="tile" data-slug="' . self::e($r['slug'] ?? '') . '"><h3>' . self::e($r['slug'] ?? '') . '</h3>'
                . '<p class="meta">' . self::e($r['branch'] ?? 'no branch') . ' · ' . (int) ($r['dirty'] ?? 0) . ' dirty'
                . ($age !== '' ? ' · <time datetime="' . self::e($r['last_date']) . '">' . self::e($age) . '</time>' : '') . '</p>'
                . (($r['last_subject'] ?? null) !== null ? '<p class="subject">' . self::e($r['last_subject']) . '</p>' : '')
                . $loop . $checklist
                . (($r['error'] ?? null) !== null ? '<p class="error">' . self::e($r['error']) . '</p>' : '') . '</li>';
        }
        return $out;
    }

    // "5m ago", "3h ago", "2d ago"; empty when there is no readable date. projects.js formats the same way.
    private static function age(?string $date, int $now): string
    {
        $t = $date !== null ? strtotime($date) : false;
        if ($t === false) {
            return '';
        }
        $s = max(0, $now - $t);
        return $s < 60 ? 'just now' : ($s < 3600 ? intdiv($s, 60) . 'm ago'
            : ($s < 86400 ? intdiv($s, 3600) . 'h ago' : intdiv($s, 86400) . 'd ago'));
    }

    private static function render(string $token, array $projects, array $answers, array $clients, bool $elevated,
        string $strip, string $tiles): string
    {
        $t = self::e($token);
        $banner = $elevated
            ? '<p class="banner" role="alert">Apache is running elevated. Restart it from a normal, non-admin '
                . 'prompt: an elevated server gives anything that reaches it admin rights.</p>'
            : '';
        $options = '<option value="">Home folder</option>';
        foreach ($projects as $p) {
            $options .= '<option value="' . self::e($p) . '">' . self::e($p) . '</option>';
        }
        $answerItems = implode('', array_map([self::class, 'answerItem'], $answers));
        $clientItems = implode('', array_map([self::class, 'clientItem'], $clients['clients'] ?? []));
        $leadItems = implode('', array_map([self::class, 'leadItem'], $clients['leads'] ?? []));
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<title>KC Dashboard</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<script type="module" src="/assets/app.js"></script>
<script type="module" src="/assets/orb.js"></script>
<script type="module" src="/assets/clients.js"></script>
<script type="module" src="/assets/now.js"></script>
<script type="module" src="/assets/projects.js"></script>
</head>
<body>
$banner
<main>
<section class="strip" aria-labelledby="strip-h">
<h2 id="strip-h" class="hidden">Running now</h2>
<ul id="running" class="strip-list">$strip</ul>
</section>

<form class="search" action="https://www.google.com/search" method="get" role="search">
<label for="q" class="hidden">Search Google</label>
<input type="search" id="q" name="q" placeholder="Search Google" autofocus>
</form>

<section class="panel ask" aria-labelledby="ask-h">
<h2 id="ask-h">Ask Claude</h2>
<div class="orb" aria-hidden="true">
<div class="orb-static" id="orb-static"></div>
<canvas id="orb" class="orb-canvas" width="160" height="160" hidden></canvas>
</div>
<form id="ask-form">
<label for="ask-question">Question</label>
<textarea id="ask-question" name="question" rows="3" maxlength="8000" required></textarea>
<label for="ask-project">Project</label>
<select id="ask-project" name="project">$options</select>
<button type="submit" disabled>Ask</button>
<p class="note" id="ask-note" aria-live="polite"></p>
</form>
<noscript><p class="note">Asking needs JavaScript. Answers below still show.</p></noscript>
</section>

<section class="panel answers" aria-labelledby="answers-h">
<h2 id="answers-h">Answers</h2>
<ol id="answers" class="list">$answerItems</ol>
</section>

<section class="panel clients" aria-labelledby="clients-h">
<h2 id="clients-h">Clients</h2>
<ul id="clients" class="list">$clientItems</ul>
<h3>Leads</h3>
<ul id="leads" class="list">$leadItems</ul>
</section>

<section class="panel map" aria-labelledby="map-h">
<h2 id="map-h">Projects</h2>
<ul id="projects" class="tiles">$tiles</ul>
</section>
</main>
</body>
</html>
HTML;
    }

    private static function answerItem(array $a): string
    {
        $status = (string) ($a['status'] ?? '');
        $project = (string) ($a['project'] ?? '') !== '' ? (string) $a['project'] : 'home';
        $text = $status === 'done' ? ($a['answer'] ?? '') : ($status === 'failed' ? ($a['error'] ?? 'failed') : 'Working on it.');
        return '<li data-status="' . self::e($status) . '"><p class="meta">' . self::e($project) . ' · ' . self::e($status)
            . '</p><p class="question">' . self::e($a['question'] ?? '') . '</p><p class="answer">' . self::e($text) . '</p></li>';
    }

    private const LINK_LABELS = ['site' => 'Site', 'ga4' => 'GA4', 'search_console' => 'Search Console',
        'billing' => 'Billing', 'repo' => 'Repo', 'folder' => 'Folder'];

    private static function clientItem(array $c): string
    {
        $links = '';
        foreach ($c['links'] ?? [] as $kind => $url) {
            if (DashboardParser::safeUrl((string) $url)) {
                $links .= '<li><a href="' . self::e($url) . '" rel="noopener noreferrer" target="_blank">'
                    . self::e(self::LINK_LABELS[$kind] ?? $kind) . '</a></li>';
            }
        }
        return '<li><details><summary><span class="name">' . self::e($c['client'] ?? '') . '</span> <span class="tag">'
            . self::e($c['phase'] ?? '') . '</span>' . ($c['due'] ? ' <time datetime="' . self::e($c['due']) . '">'
            . self::e($c['due']) . '</time>' : '') . '</summary><dl>'
            . self::row('Next milestone', $c['milestone'] ?? '') . self::row('Blocker', $c['blocker'] ?? '')
            . self::row('Status', $c['status'] ?? '') . self::row('Due', $c['due_text'] ?? '')
            . '</dl>' . ($links !== '' ? '<ul class="links">' . $links . '</ul>' : '') . '</details></li>';
    }

    private static function leadItem(array $l): string
    {
        return '<li><details><summary><span class="name">' . self::e($l['business'] ?? '') . '</span> <span class="tag">'
            . self::e($l['stage'] ?? '') . '</span></summary><dl>'
            . self::row('Contact', $l['name'] ?? '') . self::row('Town', $l['town'] ?? '')
            . self::row('Price', $l['price'] ?? '') . self::row('Next action', $l['next_action'] ?? '')
            . self::row('Notes', $l['notes'] ?? '') . '</dl></details></li>';
    }

    private static function row(string $label, string $value): string
    {
        return $value === '' ? '' : '<dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd>';
    }
}
