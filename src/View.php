<?php
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Http/Token.php';
require_once __DIR__ . '/Http/RequestGuard.php';
require_once __DIR__ . '/Http/Response.php';
require_once __DIR__ . '/Projects/ProjectList.php';
require_once __DIR__ . '/Clients/DashboardParser.php';
require_once __DIR__ . '/Clients/PipelineParser.php';
require_once __DIR__ . '/Running/NowService.php';
require_once __DIR__ . '/Claude/ClaudeRunner.php';

// The KC Command Center pages and the clients endpoint. Every value from a file, the database or a model is escaped
// here. Every panel renders from the server with its current values, so the page is complete with JS off.
class View
{
    /**
     * GET /. The guard runs before the token is read, so a refused request never touches it, and before any of $deps:
     * 'projects' => ProjectList, 'answers' => fn(): array, 'clients' => fn(): array, 'elevated' => fn(): bool,
     * 'map' => fn(): array (View::projectRows), 'now' => fn(): array (a saved api/now.php snapshot; never a scan),
     * 'attention' => fn(): array (AttentionApi::data), 'news' => fn(): array (NewsApi::data),
     * 'numbers' => fn(): array (NumbersApi::data), 'core' => fn(): array (View::coreData). A failing source leaves
     * its part of the page empty or "not connected", not a 500.
     */
    public static function page(array $server, string $tokenPath, Log $log, array $deps = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $now = $now ?? time();
            $projects = isset($deps['projects']) ? $deps['projects']->names() : [];
            $answers = self::source($deps['answers'] ?? null, [], $log, 'answers');
            $clients = self::source($deps['clients'] ?? null, ['clients' => [], 'leads' => []], $log, 'clients');
            $elevated = self::source($deps['elevated'] ?? null, false, $log, 'elevation') === true;
            $v = self::gather($deps, $log, $now, true);
            $v['clients'] = is_array($clients) ? $clients : ['clients' => [], 'leads' => []];
            $stories = self::source($deps['news'] ?? null, null, $log, 'news');
            $v['news'] = is_array($stories) ? $stories : null;
            $news = self::section(fn() => self::news(is_array($stories) ? $stories : null), $log, 'news');
            $numbers = self::section(fn() => self::numbers($v['numbers']), $log, 'numbers');
            $cc = self::section(fn() => self::commandCenter($v, $now, true), $log, 'command center');
            return Response::html(200, self::render($token, $projects, $answers, $v['clients'], $elevated, $cc,
                $news !== '' ? $news : self::news(null), $numbers !== '' ? $numbers : self::numbers(null), $v, $now));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    /**
     * GET /wall.php, the command center full screen with no rail, for a second monitor. A page like the new tab, so
     * checkRead, and $deps run only after it: as for page(), plus 'panels' => ['attention' => bool, 'news' => bool]
     * for the panels whose API exists. With no attention data, or a failing one, the feed says "Attention
     * unavailable." until attention.js loads the list.
     */
    public static function wall(array $server, string $tokenPath, Log $log, array $deps = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $now = $now ?? time();
            $panels = is_array($deps['panels'] ?? null) ? $deps['panels'] : [];
            $feed = ($panels['attention'] ?? false) === true;
            $news = ($panels['news'] ?? false) === true;
            $v = self::gather($feed ? $deps : array_diff_key($deps, ['attention' => 1]), $log, $now, $feed);
            $clients = self::source($deps['clients'] ?? null, ['clients' => [], 'leads' => []], $log, 'wall clients');
            $v['clients'] = is_array($clients) ? $clients : ['clients' => [], 'leads' => []];
            $cc = self::section(fn() => self::commandCenter($v, $now, $feed), $log, 'wall command center');
            return Response::html(200, self::renderWall($token, $cc, $v, $now, $feed, $news));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    /**
     * GET /projects.php, the full project map as one dense table. checkRead, then 'map' => fn(): array
     * (View::projectRows) and 'now' as for page().
     */
    public static function projectsPage(array $server, string $tokenPath, Log $log, array $deps = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $now = $now ?? time();
            $map = self::source($deps['map'] ?? null, [], $log, 'map');
            $map = is_array($map) ? $map : [];
            $rows = self::section(fn() => self::mapRows($map, $now), $log, 'map rows');
            $running = self::cleanSnapshot(self::source($deps['now'] ?? null, [], $log, 'now'));
            $strip = self::section(fn() => self::strip($running, $map), $log, 'strip');
            return Response::html(200, self::renderProjects($token, $rows, $strip !== '' ? $strip : '<li class="quiet">Nothing running.</li>',
                count($map), $now));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    /**
     * GET /briefings.php: past briefings newest first, and ?day=YYYY-MM-DD opens one as it was stored that day
     * (today's when no day is given). checkRead, then $composer; a navigation reads stored briefings and never builds.
     */
    public static function briefingsPage(array $server, string $tokenPath, Log $log, Closure $composer, array $query = [], ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            $now = $now ?? time();
            $day = is_string($query['day'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query['day']) ? $query['day'] : null;
            $history = [];
            $open = null;
            try {
                $c = $composer();
                $history = $c->history();
                $open = $c->get($day ?? date('Y-m-d', $now)) ?? ($day === null && $history !== [] ? $c->get($history[0]['day']) : null);
            } catch (Throwable $e) {
                $log->info('briefings: source failed', ['error' => $e->getMessage()]);
            }
            $items = '';
            foreach ($history as $h) {
                $current = $open !== null && $h['day'] === $open['day'];
                $items .= '<li class="b-day"><a href="/briefings.php?day=' . self::e($h['day']) . '"' . ($current ? ' aria-current="page"' : '') . '>'
                    . '<time datetime="' . self::e($h['day']) . '">' . self::e(date('D j M Y', (int) strtotime($h['day']))) . '</time></a></li>';
            }
            return Response::html(200, self::renderBriefings($token, $items !== '' ? $items : '<li class="quiet">No briefings yet.</li>',
                $open === null ? '<p class="quiet">' . ($day !== null ? 'No briefing for that day.' : 'No briefing yet. Brief me on the command center builds today\'s.') . '</p>'
                    : self::briefingBody((string) $open['body']), $open['day'] ?? null, $now));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    // A stored briefing's markdown as escaped HTML: `## ` lines are headings, `- ` lines list items, the rest text.
    public static function briefingBody(string $md): string
    {
        $out = '';
        $list = false;
        foreach (preg_split('/\R/', $md) as $line) {
            $isItem = str_starts_with($line, '- ');
            if ($list && !$isItem) {
                $out .= '</ul>';
                $list = false;
            }
            if ($isItem) {
                $out .= ($list ? '' : '<ul class="b-list">') . '<li>' . self::e(substr($line, 2)) . '</li>';
                $list = true;
            } elseif (str_starts_with($line, '## ')) {
                $out .= '<h3>' . self::e(substr($line, 3)) . '</h3>';
            } elseif (trim($line) !== '' && !str_starts_with($line, '# ')) {
                $out .= '<p>' . self::e($line) . '</p>';
            }
        }
        return $out . ($list ? '</ul>' : '');
    }

    // Every source the command center reads, each in its own try: a failing one is null or empty, never a 500.
    private static function gather(array $deps, Log $log, int $now, bool $feed): array
    {
        $map = self::source($deps['map'] ?? null, [], $log, 'map');
        $facts = $feed ? self::source($deps['attention'] ?? null, null, $log, 'attention') : null;
        $numbers = self::source($deps['numbers'] ?? null, null, $log, 'numbers');
        $core = self::source($deps['core'] ?? null, [], $log, 'core');
        $schedule = self::source($deps['schedule'] ?? fn(): array => NowService::schedule(), [], $log, 'schedule');
        $map = is_array($map) ? $map : [];
        $snap = self::cleanSnapshot(self::source($deps['now'] ?? null, [], $log, 'now'));
        $strip = self::section(fn() => self::strip($snap, $map), $log, 'strip');
        return [
            'map' => $map,
            'now' => $snap,
            'strip' => $strip !== '' ? $strip : '<li class="quiet">Nothing running.</li>',
            'facts' => is_array($facts) ? $facts : null,
            'numbers' => is_array($numbers) ? $numbers : null,
            'core' => is_array($core) ? $core : [],
            'schedule' => is_array($schedule) ? $schedule : [],
        ];
    }

    /**
     * What the core overview and LLM panels show that no API serves: Claude Code (its install and version, read
     * from the npm package.json beside claude.exe; nothing is started), the last daily read and the worker queue
     * from the database, and the vault's note counts. Each part is null when its source cannot be read.
     */
    public static function coreData(?Closure $pdo, string $claudeExe, string $vaultRoot, ?Log $log = null): array
    {
        $part = function (string $what, callable $fn) use ($log) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $log?->info('core: source failed', ['source' => $what, 'error' => $e->getMessage()]);
                return null;
            }
        };
        $db = $pdo !== null ? $part('db', $pdo) : null;
        return [
            'claude' => $part('claude', function () use ($claudeExe): array {
                $pkg = dirname($claudeExe, 2) . DIRECTORY_SEPARATOR . 'package.json';
                $json = json_decode((string) @file_get_contents($pkg, false, null, 0, 65536), true);
                $ver = is_array($json) && is_string($json['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+[\w.-]{0,20}$/', $json['version'])
                    ? $json['version'] : null;
                return ['present' => is_file($claudeExe), 'version' => $ver];
            }),
            'read' => $db instanceof PDO ? $part('reads', function () use ($db): array {
                $r = $db->query('SELECT created_at, ranking, error FROM reads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                return $r === false ? ['at' => null, 'ok' => null]
                    : ['at' => (int) $r['created_at'], 'ok' => $r['error'] === null && $r['ranking'] !== null];
            }) : null,
            'queue' => $db instanceof PDO ? $part('jobs', fn(): int => (int) $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'queued'")->fetchColumn()) : null,
            'vault' => $part('vault', function () use ($vaultRoot): ?array {
                $root = rtrim($vaultRoot, '\\/');
                if ($root === '' || !is_dir($root)) {
                    return null;
                }
                $notes = 0;
                $last = null;
                $briefings = 0;
                foreach (['Projects', 'Briefings', 'Memory', 'Boards', 'Maps'] as $folder) {
                    foreach (glob($root . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . '*.md') ?: [] as $f) {
                        $notes++;
                        $briefings += $folder === 'Briefings' ? 1 : 0;
                        $m = @filemtime($f);
                        $last = $m !== false && ($last === null || $m > $last) ? $m : $last;
                    }
                }
                return ['notes' => $notes, 'last' => $last, 'briefings' => $briefings];
            }),
            'memory' => $db instanceof PDO ? $part('memories', fn(): int => (int) $db->query('SELECT COUNT(*) FROM memories')->fetchColumn()) : null,
        ];
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

    // The snapshot file is not trusted: keep the four lists, each entry an array, each field a string, int or bool;
    // the system reading as one such array, and the stale flag only when it is a bool.
    private static function cleanSnapshot($now): array
    {
        $out = [];
        $scalar = fn($v) => is_string($v) || is_int($v) || is_bool($v);
        foreach (['sessions', 'loops', 'tasks', 'ports'] as $key) {
            if (!is_array($now) || !isset($now[$key]) || !is_array($now[$key])) {
                continue;
            }
            $out[$key] = [];
            foreach ($now[$key] as $item) {
                if (is_array($item)) {
                    $out[$key][] = array_filter($item, $scalar);
                }
            }
        }
        if (is_array($now) && is_array($now['system'] ?? null)) {
            $out['system'] = array_filter($now['system'], $scalar);
        }
        if (is_array($now) && is_bool($now['stale'] ?? null)) {
            $out['stale'] = $now['stale'];
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
        foreach (self::loopList($now, $map) as $l) {
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

    // Loops from the snapshot when it has them, otherwise from the projects table.
    private static function loopList(array $now, array $map): array
    {
        return isset($now['loops']) ? (array) $now['loops']
            : array_map(fn($r) => ['project' => $r['slug'] ?? '', 'state' => $r['loop_state'] ?? null, 'card' => $r['loop_card'] ?? null,
                'note' => $r['loop_note'] ?? null], array_filter($map, 'is_array'));
    }

    private static function stripItem(string $kind, string $name, string $what): string
    {
        return '<li data-kind="' . self::e($kind) . '"><span class="name">' . self::e($name) . '</span> <span class="tag">'
            . self::e($what) . '</span></li>';
    }

    // The projects page's table rows, as projects.js rebuilds them.
    private static function mapRows(array $rows, int $now): string
    {
        $out = '';
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $age = self::age($r['last_date'] ?? null, $now);
            $loop = ($r['loop_state'] ?? null) !== null && $r['loop_state'] !== 'none'
                ? '<span class="loop" data-state="' . self::e($r['loop_state']) . '">' . self::e($r['loop_state'])
                    . (($r['loop_card'] ?? null) !== null ? ', card ' . self::e($r['loop_card']) : '')
                    . (($r['loop_note'] ?? null) !== null ? ': ' . self::e($r['loop_note']) : '') . '</span>'
                : '';
            $checklist = ($r['checklist_cards'] ?? null) !== null
                ? (int) $r['checklist_passing'] . ' of ' . (int) $r['checklist_cards'] : '';
            $out .= '<tr data-slug="' . self::e($r['slug'] ?? '') . '"><th scope="row">' . self::e($r['slug'] ?? '') . '</th>'
                . '<td>' . self::e($r['branch'] ?? 'no branch') . '</td><td class="n">' . (int) ($r['dirty'] ?? 0) . '</td>'
                . '<td>' . ($age !== '' ? '<time datetime="' . self::e($r['last_date']) . '">' . self::e($age) . '</time>' : '') . '</td>'
                . '<td class="subject">' . self::e($r['last_subject'] ?? '') . '</td><td>' . $loop . '</td><td class="n">' . $checklist . '</td>'
                . '<td>' . (($r['error'] ?? null) !== null ? '<span class="error">' . self::e($r['error']) . '</span>' : '') . '</td></tr>';
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

    // The attention panel's lists, as attention.js rebuilds them. Null data (no list could be read) says so, and a
    // url is a link only if AttentionApi::safeUrl passed it. Snooze and feedback need JS, so attention.js adds them.
    private static function attention(?array $data): string
    {
        if ($data === null) {
            return '<ul id="attention" class="list"><li class="quiet" data-unavailable="1">Attention unavailable.</li></ul>';
        }
        $items = implode('', array_map([self::class, 'factItem'], (array) ($data['items'] ?? [])));
        $also = (array) ($data['also'] ?? []);
        $sources = '';
        foreach ((array) ($data['sources'] ?? []) as $s) {
            $sources .= '<li data-stale="' . (($s['stale'] ?? true) ? '1' : '0') . '">' . self::e($s['name'] ?? '') . ' · '
                . self::e(($s['last_ok_at'] ?? null) === null ? 'no good run yet' : ($s['status'] ?? '')) . (($s['stale'] ?? true) ? ' · stale' : '')
                . (($s['last_error'] ?? null) !== null ? '<span class="error"> ' . self::e($s['last_error']) . '</span>' : '') . '</li>';
        }
        return '<ul id="attention" class="list">' . ($items !== '' ? $items : '<li class="quiet">Nothing needs you.</li>') . '</ul>'
            . '<ul id="attention-sources" class="sources">' . ($sources !== '' ? $sources : '<li class="quiet">No collector has run yet.</li>')
            . '</ul><details id="attention-also" class="also"' . ($also === [] ? ' hidden' : '') . '><summary>Also seen ('
            . count($also) . ')</summary><ul class="list">' . implode('', array_map([self::class, 'factItem'], $also)) . '</ul></details>';
    }

    // The headlines list, as news.js rebuilds it: the first page, hottest first. A url is a link only if
    // NewsApi::safeUrl passed it and it still starts http:// or https://. The topic filter and "More" need JS.
    private static function news(?array $data): string
    {
        if ($data === null) {
            return '<ul id="news" class="list"><li class="quiet">Headlines unavailable.</li></ul>';
        }
        $items = '';
        foreach ((array) ($data['stories'] ?? []) as $s) {
            $url = $s['url'] ?? null;
            $title = is_string($url) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
                ? '<a href="' . self::e($url) . '" rel="noopener noreferrer" target="_blank">' . self::e($s['title'] ?? '') . '</a>'
                : self::e($s['title'] ?? '');
            $meta = self::e(implode(', ', array_map('strval', (array) ($s['sources'] ?? []))))
                . (($s['points'] ?? 0) > 0 ? ' · ' . (int) $s['points'] . ' points' : '');
            $items .= '<li class="story"><p class="subject">' . $title . '</p><p class="meta">' . $meta . '</p></li>';
        }
        return '<ul id="news" class="list">' . ($items !== '' ? $items : '<li class="quiet">No headlines yet.</li>') . '</ul>';
    }

    private const NUMBER_NAMES = ['sessions' => 'sessions', 'users' => 'users', 'active_users' => 'active now',
        'clicks' => 'clicks', 'impressions' => 'impressions', 'ctr' => 'CTR', 'position' => 'avg position',
        'open_issues' => 'open issues', 'checks' => 'checks', 'down' => 'down'];

    // The numbers list, as numbers.js rebuilds it: one entry per source, its values, top pages and last error.
    private static function numbers(?array $data): string
    {
        if ($data === null) {
            return '<ul id="numbers" class="list"><li class="quiet">Numbers unavailable.</li></ul>';
        }
        $items = '';
        foreach ((array) ($data['sources'] ?? []) as $s) {
            $bits = [];
            $pages = '';
            foreach ((array) ($s['values'] ?? []) as $metric => $v) {
                $value = is_array($v) ? ($v['value'] ?? null) : null;
                $label = is_array($v) && is_string($v['label'] ?? null) ? $v['label'] : '';
                $metric = (string) $metric;
                if (str_starts_with($metric, 'top_page:')) {
                    $pages .= '<li>' . self::e($label . ' · ' . self::numberShown($metric, $value)) . '</li>';
                } elseif ($metric === 'status') {
                    $bits[] = $label !== '' ? $label : 'unknown';
                } elseif (isset(self::NUMBER_NAMES[$metric])) {
                    $bits[] = self::NUMBER_NAMES[$metric] . ' ' . self::numberShown($metric, $value) . ($label === 'partial' ? '+' : '');
                }
            }
            if (($s['last_ok_at'] ?? null) === null) {
                $bits[] = 'no good run yet';
            }
            if (($s['stale'] ?? true) === true) {
                $bits[] = 'stale';
            }
            $error = $s['last_error'] ?? null;
            $items .= '<li class="number" data-status="' . self::e((string) ($s['status'] ?? '')) . '" data-stale="'
                . (($s['stale'] ?? true) === true ? '1' : '0') . '"><p class="subject">' . self::e((string) ($s['name'] ?? ''))
                . '</p><p class="meta">' . self::e(implode(' · ', $bits)) . '</p>'
                . ($pages !== '' ? '<ol class="pages">' . $pages . '</ol>' : '')
                . (is_string($error) && $error !== '' ? '<p class="error">' . self::e($error) . '</p>' : '') . '</li>';
        }
        return '<ul id="numbers" class="list">' . ($items !== '' ? $items : '<li class="quiet">No numbers collected yet.</li>') . '</ul>';
    }

    private static function numberShown(string $metric, $v): string
    {
        if (!is_int($v) && !is_float($v)) {
            return '?';
        }
        return $metric === 'ctr' ? number_format($v * 100, 1) . '%' : ($metric === 'position' ? number_format($v, 1) : (string) $v);
    }

    // A fact's feed tag: WARN for high, INFO for medium, TIP for the rest. attention.js uses the same words.
    private const FEED_TAGS = ['high' => 'WARN', 'med' => 'INFO'];

    private static function factItem(array $f): string
    {
        $url = $f['url'] ?? null;
        $subject = is_string($url) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
            ? '<a href="' . self::e($url) . '" rel="noopener noreferrer" target="_blank">' . self::e($f['subject'] ?? '') . '</a>'
            : self::e($f['subject'] ?? '');
        $due = ($f['due_date'] ?? null) !== null
            ? ' · <time datetime="' . self::e($f['due_date']) . '">' . self::e($f['due_date']) . '</time>' : '';
        $tag = self::FEED_TAGS[(string) ($f['severity'] ?? '')] ?? 'TIP';
        return '<li class="fact" data-severity="' . self::e($f['severity'] ?? '') . '"><span class="feed-tag" data-tag="' . $tag . '">'
            . $tag . '</span><p class="meta">' . self::e($f['severity'] ?? '')
            . ' · ' . self::e($f['source'] ?? '') . $due . '</p><p class="subject">' . $subject . '</p>'
            . (($f['evidence'] ?? null) !== null ? '<p class="evidence">' . self::e($f['evidence']) . '</p>' : '') . '</li>';
    }

    // Line icons, drawn with currentColor. Inline SVG is markup, not an inline style or script, so the CSP holds.
    private const ICONS = [
        'grid' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'folder' => 'M3 6h6l2 2h10v11H3z',
        'bell' => 'M6 16v-5a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
        'news' => 'M4 5h13v14H4zM17 9h3v10h-3M7 9h7M7 13h7M7 16h4',
        'chart' => 'M4 20V4M4 20h16M8 16v-5M12 16V8M16 16v-3',
        'vault' => 'M5 3h14v18H5zM9 8h6M9 12h6M9 16h3',
        'screen' => 'M3 5h18v11H3zM8 20h8M12 16v4',
        'mic' => 'M12 3a3 3 0 0 1 3 3v6a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM5 11a7 7 0 0 0 14 0M12 18v3',
        'core' => 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM12 8a4 4 0 1 0 0 8a4 4 0 1 0 0-8z',
        'clock' => 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18zM12 7v5l3 2',
        'queue' => 'M4 6h16M4 12h16M4 18h10',
        'memory' => 'M12 3l8 4-8 4-8-4zM4 12l8 4 8-4M4 17l8 4 8-4',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
        'code' => 'M8 8l-4 4 4 4M16 8l4 4-4 4M14 5l-4 14',
        'loop' => 'M20 12a8 8 0 1 1-3-6.2M20 4v5h-5',
        'collect' => 'M12 3v12M7 10l5 5 5-5M4 20h16',
        'bolt' => 'M13 3L5 14h6l-1 7 8-11h-6z',
        'play' => 'M7 5v14l11-7z',
        'cpu' => 'M7 7h10v10H7zM10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4',
        'net' => 'M3 9a13 13 0 0 1 18 0M6 12.5a8 8 0 0 1 12 0M9 16a3 3 0 0 1 6 0M12 19.5v.5',
        'search' => 'M10 4a6 6 0 1 0 0 12a6 6 0 1 0 0-12zM15 15l5 5',
        'doc' => 'M6 3h9l4 4v14H6zM15 3v4h4M9 12h7M9 16h7',
        'chat' => 'M4 5h16v11H9l-5 4z',
        'chip' => 'M5 5h14v14H5zM9 9h6v6H9z',
    ];

    private static function icon(string $name): string
    {
        return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . (self::ICONS[$name] ?? '') . '"/></svg>';
    }

    // The bars of a waveform. hud.css moves them while the agent is busy; with reduced motion they hold still.
    private static function wave(string $cls = 'wave', int $bars = 16): string
    {
        return '<span class="' . $cls . '" aria-hidden="true">' . str_repeat('<i></i>', $bars) . '</span>';
    }

    // "in 42 min", "in 3 h 5 min", "in 2 d", "now", "2 h ago". core.js counts the same way each minute.
    private static function eta(int $at, int $now): string
    {
        $d = $at - $now;
        $a = abs($d);
        $text = $a < 60 ? 'now' : ($a < 3600 ? intdiv($a, 60) . ' min'
            : ($a < 86400 ? intdiv($a, 3600) . ' h' . (intdiv($a % 3600, 60) > 0 ? ' ' . intdiv($a % 3600, 60) . ' min' : '')
            : intdiv($a, 86400) . ' d'));
        return $text === 'now' ? 'now' : ($d > 0 ? 'in ' . $text : $text . ' ago');
    }

    // Coverage health for the systems pill: every collector fresh and clean is Optimal; some stale, failing or a
    // failed scheduled task is Degraded; no attention data at all, or every source bad, is Fault.
    private static function health(?array $facts, array $now): array
    {
        if ($facts === null) {
            return ['fault', 'Fault'];
        }
        $sources = array_filter((array) ($facts['sources'] ?? []), 'is_array');
        $bad = count(array_filter($sources, fn($s) => ($s['stale'] ?? true) !== false || ($s['last_error'] ?? null) !== null));
        $failed = count(array_filter((array) ($now['tasks'] ?? []), fn($t) => ($t['failed'] ?? false) === true));
        if ($sources !== [] && $bad === count($sources)) {
            return ['fault', 'Fault'];
        }
        return $sources === [] || $bad > 0 || $failed > 0 ? ['degraded', 'Degraded'] : ['optimal', 'Optimal'];
    }

    /**
     * The agents: Claude Code sessions, each project's loop, then the collectors (the dashboard's scheduled tasks).
     * Each is [kind, name, state, task], state one of busy, waiting, idle, stale, failed or off.
     */
    private static function agents(array $now, array $map, array $schedule = []): array
    {
        $out = [];
        $stale = ($now['stale'] ?? false) === true;
        foreach ((array) ($now['sessions'] ?? []) as $s) {
            $status = in_array($s['status'] ?? null, ['busy', 'waiting', 'idle'], true) ? $s['status'] : 'idle';
            $task = $status === 'waiting' && ($s['waitingFor'] ?? '') !== '' ? 'waiting: ' . $s['waitingFor'] : (string) ($s['name'] ?? '');
            $out[] = ['session', (string) ($s['project'] ?? $s['name'] ?? 'session'), $stale ? 'stale' : $status, $task];
        }
        foreach (self::loopList($now, $map) as $l) {
            $state = ['running' => 'busy', 'stopped' => 'idle', 'stale' => 'stale'][$l['state'] ?? ''] ?? null;
            if ($state === null) {
                continue;
            }
            $task = (($l['card'] ?? null) !== null ? 'card ' . $l['card'] : 'no card') . (($l['note'] ?? null) !== null ? ': ' . $l['note'] : '');
            $out[] = ['loop', (string) ($l['project'] ?? ''), $state, $task];
        }
        foreach ((array) ($now['tasks'] ?? []) as $t) {
            $failed = ($t['failed'] ?? false) === true;
            $state = $failed ? 'failed' : (strcasecmp((string) ($t['status'] ?? ''), 'Running') === 0 ? 'busy' : 'idle');
            $task = $failed ? 'failed ' . ($t['lastResult'] ?? '') : (($t['lastRun'] ?? null) !== null ? 'last run ' . $t['lastRun'] : 'not run yet');
            $out[] = ['collector', (string) ($t['name'] ?? ''), $state, $task];
        }
        // A job register-tasks.ps1 sets up that Task Scheduler does not have is a collector that is off.
        $registered = array_map(fn($t) => strtolower((string) ($t['name'] ?? '')), (array) ($now['tasks'] ?? []));
        foreach ($schedule as $j) {
            if (is_array($j) && is_string($j['name'] ?? null) && !in_array(strtolower($j['name']), $registered, true)) {
                $out[] = ['collector', $j['name'], 'off', 'not registered'];
            }
        }
        return $out;
    }

    private const AGENT_ICONS = ['session' => 'code', 'loop' => 'loop', 'collector' => 'collect'];

    // $tone (0 to 3) picks one of four hues of the kind's colour, so three sessions do not read as one.
    private static function agentTile(array $a, int $tone = 0): string
    {
        [$kind, $name, $state, $task] = $a;
        return '<li class="agent" data-kind="' . self::e($kind) . '" data-state="' . self::e($state) . '" data-tone="' . ($tone % 4) . '">'
            . '<span class="disc">' . self::icon(self::AGENT_ICONS[$kind] ?? 'bolt') . '</span>'
            . '<p class="a-name">' . self::e($name) . '</p><p class="a-state"><span class="dot"></span>' . self::e($state) . '</p>'
            . '<p class="a-task">' . self::e($task) . '</p>' . self::sine() . '</li>';
    }

    // A smooth waveform: two sine paths three times the width of the view, which hud.css slides one period sideways
    // and loops while the agent is busy. core.js draws the same paths with sinePath().
    private static function sine(): string
    {
        return '<span class="wave" aria-hidden="true"><svg viewBox="0 0 120 24" preserveAspectRatio="none" focusable="false">'
            . '<path class="w1" d="' . self::sinePath(40, 7) . '"/><path class="w2" d="' . self::sinePath(60, 4) . '"/></svg></span>';
    }

    private static function sinePath(int $period, int $amp): string
    {
        $d = 'M0 12';
        for ($x = 2; $x <= 360; $x += 2) {
            $d .= 'L' . $x . ' ' . round(12 - $amp * sin($x * 2 * M_PI / $period), 1);
        }
        return $d;
    }

    /**
     * The mission timeline: due dates from the attention facts and the clients file, and the scheduled tasks' next
     * runs, soonest first, at most eight. Each is [at, label, kind, dateOnly].
     */
    private static function missions(?array $facts, array $clients, array $now, int $time, array $schedule = []): array
    {
        $out = [];
        // The jobs register-tasks.ps1 sets up, unless the snapshot already has the registered task's own next run.
        $registered = array_map(fn($t) => strtolower((string) ($t['name'] ?? '')), (array) ($now['tasks'] ?? []));
        foreach ($schedule as $j) {
            if (is_array($j) && is_string($j['name'] ?? null) && !in_array(strtolower($j['name']), $registered, true)) {
                $next = self::nextRun($j, $time);
                if ($next !== null) {
                    $out[] = [$next[0], $j['name'], 'job', false, $j, $next[1]];
                }
            }
        }
        $day = fn(?string $d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? strtotime($d . ' 23:59:59') : false;
        foreach (array_merge((array) ($facts['items'] ?? []), (array) ($facts['also'] ?? [])) as $f) {
            $at = $day($f['due_date'] ?? null);
            if (is_array($f) && $at !== false && $at >= $time - 86400) {
                $out[] = [$at, (string) ($f['subject'] ?? ''), 'due', true];
            }
        }
        foreach ((array) ($clients['clients'] ?? []) as $c) {
            $at = $day($c['due'] ?? null);
            if (is_array($c) && $at !== false && $at >= $time - 86400) {
                $out[] = [$at, (string) ($c['client'] ?? '') . (($c['milestone'] ?? '') !== '' ? ': ' . $c['milestone'] : ''), 'client', true];
            }
        }
        foreach ((array) ($now['tasks'] ?? []) as $t) {
            $at = is_string($t['nextRun'] ?? null) ? strtotime($t['nextRun']) : false;
            if ($at !== false && $at >= $time) {
                $out[] = [$at, (string) ($t['name'] ?? ''), 'task', false];
            }
        }
        usort($out, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_slice($out, 0, 12);
    }

    // A job's next run and how far through its period it is (0 to 100). Repeating jobs start at midnight, as the
    // Task Scheduler trigger does; daily ones run at their time. core.js does the same sums in the browser's time zone.
    private static function nextRun(array $j, int $time): ?array
    {
        $midnight = (int) strtotime('today', $time);
        if (is_int($j['every'] ?? null) && $j['every'] > 0) {
            $period = $j['every'] * 60;
            $next = $midnight + (intdiv($time - $midnight, $period) + 1) * $period;
        } elseif (is_string($j['at'] ?? null) && preg_match('/^(\d{2}):(\d{2})$/', $j['at'], $hm)) {
            $period = 86400;
            $next = $midnight + (int) $hm[1] * 3600 + (int) $hm[2] * 60;
            $next += $next <= $time ? 86400 : 0;
        } else {
            return null;
        }
        return [$next, (int) round(100 * (1 - ($next - $time) / $period))];
    }

    private static function missionItem(array $m, int $now): string
    {
        [$at, $label, $kind, $dateOnly] = $m;
        $job = $kind === 'job' ? $m[4] : null;
        $when = $job === null ? '' : (is_int($job['every'] ?? null) ? ' data-every="' . $job['every'] . '"' : ' data-daily="' . self::e($job['at']) . '"');
        $bar = $job === null ? '' : '<svg class="m-bar" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<rect class="m-track" width="100" height="4" rx="2"/><rect class="m-fill" width="' . max(2, min(100, $m[5])) . '" height="4" rx="2"/></svg>';
        $sub = $job === null ? '' : '<p class="m-sub">' . self::e(is_int($job['every'] ?? null) ? 'every ' . $job['every'] . ' min' : 'daily ' . $job['at']) . '</p>';
        return '<li class="mission" data-kind="' . self::e($kind) . '"' . $when . '><time datetime="' . self::e(date($dateOnly ? 'Y-m-d' : 'c', $at)) . '">'
            . self::e(date($dateOnly ? 'M j' : 'H:i', $at)) . '</time><p class="m-what">' . self::e($label) . '</p>'
            . '<p class="eta" data-at="' . $at . '">' . self::e($dateOnly ? ($at - $now < 86400 ? 'today' : self::eta($at, $now)) : self::eta($at, $now)) . '</p>'
            . $sub . $bar . '</li>';
    }

    private static function gauge(string $id, string $label, $pct): string
    {
        $on = is_int($pct);
        $v = $on ? max(0, min(100, $pct)) : 0;
        return '<li class="gauge" id="gauge-' . $id . '" data-on="' . ($on ? '1' : '0') . '"><svg viewBox="0 0 120 120" aria-hidden="true">'
            . '<circle class="g-track" cx="60" cy="60" r="50" pathLength="100"/><circle class="g-fill" cx="60" cy="60" r="50" pathLength="100"'
            . ' stroke-dasharray="' . $v . ' 100"/></svg><p class="g-label">' . self::e($label) . '</p><p class="g-value num" data-value="'
            . ($on ? $v : '') . '">' . ($on ? $v . '%' : '--') . '</p></li>';
    }

    // A row of the core overview: icon disc, name, and a status word whose tone is ok, warn, fault or off.
    private static function overviewRow(string $id, string $icon, string $name, string $status, string $tone): string
    {
        return '<li class="ov" id="ov-' . $id . '" data-tone="' . $tone . '"><span class="disc">' . self::icon($icon) . '</span>'
            . '<p class="ov-name">' . self::e($name) . '</p><p class="ov-status">' . self::e($status) . '</p></li>';
    }

    // Sessions and users summed over every property's GA4 source that has a good run.
    private static function traffic(?array $numbers): ?array
    {
        if ($numbers === null) {
            return null;
        }
        $sum = ['sessions' => 0, 'users' => 0];
        $any = false;
        foreach ((array) ($numbers['sources'] ?? []) as $s) {
            if (!is_array($s) || ($s['last_ok_at'] ?? null) === null) {
                continue;
            }
            foreach (['sessions', 'users'] as $k) {
                $val = $s['values'][$k]['value'] ?? null;
                if (is_int($val) || is_float($val)) {
                    $sum[$k] += (int) $val;
                    $any = true;
                }
            }
        }
        return $any ? $sum : null;
    }

    // The constellation in Memory insights (sample 7 in miniature): one star per project, then one per vault note, up
    // to 72 in all, on a golden-angle spiral squashed into a disc; the first few stars of each arm are joined. The
    // counts are real; the places are only a pattern.
    private static function constellation(int $projects, int $notes): string
    {
        $p = min(48, max(0, $projects));
        $n = min(72 - $p, max(0, $notes));
        if ($p + $n === 0) {
            return '';
        }
        $pts = [];
        for ($i = 0; $i < $p + $n; $i++) {
            $r = sqrt(($i + 0.6) / ($p + $n)) * 54;
            $a = $i * 2.39996;
            $pts[] = [round(120 + cos($a) * $r * 1.9, 1), round(60 + sin($a) * $r, 1)];
        }
        $lines = '';
        $stars = '';
        foreach ($pts as $i => [$x, $y]) {
            if ($i >= 8 && $i < 40 && $i % 4 !== 0) {
                $lines .= '<line x1="' . $pts[$i - 8][0] . '" y1="' . $pts[$i - 8][1] . '" x2="' . $x . '" y2="' . $y . '"/>';
            }
            $stars .= '<circle class="' . ($i < $p ? 'st-p' : 'st-n') . '" cx="' . $x . '" cy="' . $y . '" r="' . (1 + ($i * 7 % 5) * 0.4) . '"/>';
        }
        return '<svg class="stars" id="stars" viewBox="0 0 240 120" aria-hidden="true" focusable="false"><g class="links">' . $lines
            . '</g><g class="nodes">' . $stars . '</g></svg>';
    }

    private const FEED_ICONS = ['LIVE' => 'loop', 'TIP' => 'cpu', 'WARN' => 'bell', 'INFO' => 'news'];

    // A row of the feed fill: icon disc, title, one line and its tag. A url is a link only if it starts http(s)://.
    private static function feedRow(string $src, string $tag, string $title, string $line, ?string $url = null, ?string $icon = null): string
    {
        $t = is_string($url) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
            ? '<a href="' . self::e($url) . '" rel="noopener noreferrer" target="_blank">' . self::e($title) . '</a>' : self::e($title);
        return '<li class="fi" data-src="' . $src . '" data-tag="' . $tag . '"><span class="disc">' . self::icon($icon ?? self::FEED_ICONS[$tag] ?? 'bolt')
            . '</span><p class="fi-title">' . $t . '</p><p class="fi-line">' . self::e($line) . '</p><span class="feed-tag" data-tag="' . $tag . '">'
            . $tag . '</span></li>';
    }

    /**
     * What the feed shows besides the attention list, from data the page already has: running loops and busy or
     * waiting sessions (LIVE), failed tasks (WARN), system monitor notes such as a nearly full disk (TIP), then the
     * hottest headlines (INFO), and the newest commits across the projects until there are seven rows. core.js
     * rebuilds the live, warn and tip rows from each now.php snapshot.
     */
    private static function feedMore(array $snap, array $map, ?array $news, int $now, array $schedule = []): string
    {
        $rows = self::feedLive($snap, $map);
        $registered = array_map(fn($t) => strtolower((string) ($t['name'] ?? '')), (array) ($snap['tasks'] ?? []));
        $off = count(array_filter($schedule, fn($j) => is_array($j) && is_string($j['name'] ?? null) && !in_array(strtolower($j['name']), $registered, true)));
        if ($off > 0) {
            $rows .= self::feedRow('sched', 'WARN', $off . ' ' . ($off === 1 ? 'job' : 'jobs') . ' not scheduled', 'Run bin\register-tasks.ps1');
        }
        $count = substr_count($rows, '<li ');
        foreach (array_slice((array) ($news['stories'] ?? []), 0, 5) as $s) {
            if (is_array($s) && is_string($s['title'] ?? null)) {
                $count++;
                $rows .= self::feedRow('news', 'INFO', $s['title'], implode(', ', array_map('strval', (array) ($s['sources'] ?? [])))
                    . (($s['points'] ?? 0) > 0 ? ' · ' . (int) $s['points'] . ' points' : ''), is_string($s['url'] ?? null) ? $s['url'] : null);
            }
        }
        $commits = array_filter($map, fn($r) => is_array($r) && is_string($r['last_subject'] ?? null) && is_string($r['last_date'] ?? null)
            && strtotime($r['last_date']) !== false);
        usort($commits, fn($a, $b) => strtotime($b['last_date']) <=> strtotime($a['last_date']));
        foreach (array_slice($commits, 0, max(0, 7 - $count)) as $r) {
            $rows .= self::feedRow('commit', 'INFO', (string) ($r['slug'] ?? '') . ': ' . $r['last_subject'],
                'commit · ' . self::age($r['last_date'], $now) . ((int) ($r['dirty'] ?? 0) > 0 ? ' · ' . (int) $r['dirty'] . ' dirty' : ''), null, 'code');
        }
        return $rows;
    }

    private static function feedLive(array $snap, array $map): string
    {
        $rows = '';
        foreach (self::loopList($snap, $map) as $l) {
            if (($l['state'] ?? null) === 'running') {
                $rows .= self::feedRow('live', 'LIVE', (string) ($l['project'] ?? '') . ' loop running',
                    (($l['card'] ?? null) !== null ? 'card ' . $l['card'] : 'between cards') . (($l['note'] ?? null) !== null ? ': ' . $l['note'] : ''));
            }
        }
        foreach ((array) ($snap['sessions'] ?? []) as $s) {
            if (in_array($s['status'] ?? null, ['busy', 'waiting'], true)) {
                $rows .= self::feedRow('live', 'LIVE', (string) ($s['project'] ?? $s['name'] ?? 'session') . ' session ' . $s['status'],
                    $s['status'] === 'waiting' && ($s['waitingFor'] ?? '') !== '' ? 'waiting: ' . $s['waitingFor'] : (string) ($s['name'] ?? 'Claude Code'));
            }
        }
        foreach ((array) ($snap['tasks'] ?? []) as $t) {
            if (($t['failed'] ?? false) === true) {
                $rows .= self::feedRow('live', 'WARN', (string) ($t['name'] ?? '') . ' failed', 'last result ' . ($t['lastResult'] ?? '?'));
            }
        }
        $sys = is_array($snap['system'] ?? null) && ($snap['system']['connected'] ?? false) === true ? $snap['system'] : [];
        foreach (self::SYSTEM_NOTES as $key => [$limit, $title, $line]) {
            if (is_int($sys[$key] ?? null) && $sys[$key] >= $limit) {
                $rows .= self::feedRow('live', 'TIP', sprintf($title, $sys[$key]), $line);
            }
        }
        return $rows;
    }

    // System monitor readings worth a feed row: [at or above, title, line]. core.js holds the same table.
    private const SYSTEM_NOTES = [
        'disk' => [90, 'Disk %d%% full', 'System drive is nearly out of space'],
        'ram' => [85, 'RAM at %d%%', 'Memory pressure is high'],
        'cpu' => [90, 'CPU at %d%%', 'Something is working the processor hard'],
    ];

    // The models reachable through the configured claude CLI: [name, alias]. The one ClaudeRunner asks with is
    // marked as the one Ask Claude uses.
    private const MODELS = [['Opus', 'opus'], ['Sonnet', 'sonnet'], ['Haiku', 'haiku']];

    private const NC = '<p class="nc">not connected</p>';

    // The panels of the command center grid, in reading order: core, overview, feed, agents, timeline, commands,
    // system monitor, memory insights, LLM status. $feed false leaves the attention list out (the wall without it).
    private static function commandCenter(array $v, int $now, bool $feed): string
    {
        $snap = $v['now'];
        $core = $v['core'];
        $claude = is_array($core['claude'] ?? null) ? $core['claude'] : null;
        $version = $claude !== null && is_string($claude['version'] ?? null) ? $claude['version'] : null;
        $present = $claude !== null && ($claude['present'] ?? false) === true;
        [$healthKey, $healthLabel] = self::health($v['facts'], $snap);

        $read = is_array($core['read'] ?? null) ? $core['read'] : null;
        $readText = $read === null ? ['not connected', 'off'] : (($read['at'] ?? null) === null ? ['no run yet', 'warn']
            : [(($read['ok'] ?? false) === true ? 'ok ' : 'failed ') . date('M j H:i', (int) $read['at']), ($read['ok'] ?? false) === true ? 'ok' : 'fault']);
        $queue = $core['queue'] ?? null;
        $vault = is_array($core['vault'] ?? null) ? $core['vault'] : null;
        $vaultText = $vault === null ? ['not connected', 'off'] : [(int) ($vault['notes'] ?? 0) . ' notes'
            . (is_int($vault['last'] ?? null) ? ' · ' . self::age(date('c', $vault['last']), $now) : ''), 'ok'];
        $memory = $core['memory'] ?? null;
        $overview = self::overviewRow('claude', 'code', 'Claude Code', $present ? 'connected' . ($version !== null ? ' v' . $version : '') : 'not connected', $present ? 'ok' : 'off')
            . self::overviewRow('read', 'clock', 'Daily read', $readText[0], $readText[1])
            . self::overviewRow('queue', 'queue', 'Worker queue', is_int($queue) ? $queue . ' waiting' : 'not connected', is_int($queue) ? ($queue > 0 ? 'warn' : 'ok') : 'off')
            . self::overviewRow('vault', 'vault', 'Vault', $vaultText[0], $vaultText[1])
            . self::overviewRow('memory', 'memory', 'Memory', is_int($memory) ? $memory . ' entries' : 'not connected', is_int($memory) ? 'ok' : 'off')
            . self::overviewRow('system', 'shield', 'System', strtolower($healthLabel), ['optimal' => 'ok', 'degraded' => 'warn', 'fault' => 'fault'][$healthKey]);

        $agents = self::agents($snap, $v['map'], $v['schedule'] ?? []);
        $busy = count(array_filter($agents, fn($a) => $a[2] === 'busy'));
        $tones = [];
        $agentItems = '';
        foreach ($agents as $a) {
            $tones[$a[0]] = ($tones[$a[0]] ?? -1) + 1;
            $agentItems .= self::agentTile($a, $tones[$a[0]]);
        }

        $missions = self::missions($v['facts'], $v['clients'] ?? [], $snap, $now, $v['schedule'] ?? []);
        $missionItems = implode('', array_map(fn($m) => self::missionItem($m, $now), array_slice($missions, 0, 5)));
        $moreMissions = implode('', array_map(fn($m) => self::missionItem($m, $now), array_slice($missions, 5)));

        $sys = is_array($snap['system'] ?? null) && ($snap['system']['connected'] ?? false) === true ? $snap['system'] : null;
        $gauges = self::gauge('cpu', 'CPU', $sys['cpu'] ?? null) . self::gauge('ram', 'RAM', $sys['ram'] ?? null)
            . self::gauge('disk', 'Disk', $sys['disk'] ?? null);

        $notes = $vault !== null ? (int) ($vault['notes'] ?? 0) : null;
        $llama = $sys === null ? null : (($sys['llama'] ?? false) === true);
        $projects = count($v['map']);

        $feedBody = ($feed ? self::attention($v['facts']) : self::NC)
            . '<ul class="list feed-more" id="feed-more">' . self::feedMore($snap, $v['map'], $v['news'] ?? null, $now, $v['schedule'] ?? []) . '</ul>'
            . '<p class="more-link"><a href="/#news-h">View all intelligence</a></p>';
        $feedCount = $v['facts'] !== null ? count((array) ($v['facts']['items'] ?? [])) : 0;

        $coreLabel = $version !== null ? 'Claude Code v' . $version : 'Claude Code not connected';
        $orb = '<div class="orb" aria-hidden="true"><div class="orb-static" id="orb-static"><i></i><i></i><i></i></div>'
            . '<canvas id="orb" class="orb-canvas" width="720" height="540" hidden></canvas></div>';

        $models = '';
        foreach (self::MODELS as [$name, $alias]) {
            $models .= '<li class="provider" data-on="' . ($present ? '1' : '0') . '"><span class="disc">' . self::icon('code') . '</span><p class="p-name">'
                . $name . '</p><p class="p-state">' . ($present ? 'Connected' : 'not connected') . '</p><p class="p-meta">'
                . self::e($present ? 'claude -p --model ' . $alias . ($alias === ClaudeRunner::ARGS[2] ? ' · Ask' : '') : 'claude.exe missing') . '</p></li>';
        }
        $connected = ($present ? count(self::MODELS) : 0) + ($llama === true ? 1 : 0);

        return '<section class="panel core" id="core" aria-labelledby="core-h" data-busy="' . $busy . '">' . $orb
            . '<div class="core-label"><h2 id="core-h" class="core-title"><span class="core-kc">KC</span> <span class="core-sub">AI core</span></h2>'
            . '<p class="core-version" id="core-version">' . self::e($coreLabel) . '</p></div></section>' . "\n"

            . '<section class="panel overview" id="overview" aria-labelledby="overview-h"><h2 id="overview-h">AI core overview</h2>'
            . '<ul class="ov-list">' . $overview . '</ul></section>' . "\n"

            . '<section class="panel feed" id="feed" aria-labelledby="attention-h"><h2 id="attention-h">Live intelligence feed</h2>'
            . '<span class="live" aria-hidden="true">LIVE</span><span class="hidden" id="feed-count">' . $feedCount . '</span>' . $feedBody . '</section>' . "\n"

            . '<section class="panel agents" id="agents" aria-labelledby="agents-h"><h2 id="agents-h">Active agents</h2>'
            . '<p class="panel-meta chip" id="agents-meta">' . $busy . ' busy of ' . count($agents) . '</p>'
            . '<ul class="agent-grid" id="agent-list">' . ($agentItems !== '' ? $agentItems : '<li class="quiet">No agent running.</li>') . '</ul></section>' . "\n"

            . '<section class="panel timeline" id="timeline" aria-labelledby="timeline-h"><h2 id="timeline-h">Mission timeline</h2>'
            . '<p class="panel-meta chip">Today</p>'
            . '<ol class="missions">' . ($missionItems !== '' ? $missionItems : '<li class="quiet">Nothing scheduled.</li>') . '</ol>'
            . ($moreMissions !== '' ? '<details class="more"><summary>View full schedule</summary><ol class="missions">' . $moreMissions . '</ol></details>' : '')
            . '</section>' . "\n"

            . '<section class="panel commands" id="commands" aria-labelledby="commands-h"><h2 id="commands-h">Quick commands</h2><ul class="cmds">'
            . '<li><a class="cmd" href="#ask-question">' . self::icon('chat') . 'Ask Claude</a></li>'
            . '<li><button type="button" class="cmd" data-command="brief" data-brief disabled>' . self::icon('mic') . 'Brief me</button></li>'
            . '<li><a class="cmd" href="/wall.php">' . self::icon('screen') . 'Open wall</a></li>'
            . '<li><button type="button" class="cmd" data-command="collect" disabled>' . self::icon('collect') . 'Run collectors now <span class="nc-tag">not connected</span></button></li>'
            . '</ul></section>' . "\n"

            . '<section class="panel monitor" id="monitor" aria-labelledby="monitor-h" data-on="' . ($sys !== null ? '1' : '0') . '"><h2 id="monitor-h">System monitor</h2>'
            . '<ul class="gauges">' . $gauges . '</ul>' . ($sys === null ? self::NC : '') . '</section>' . "\n"

            . '<section class="panel memory" id="memory" aria-labelledby="memory-h"><h2 id="memory-h">Memory insights</h2>'
            . self::constellation($projects, $notes ?? 0) . '<dl class="counts"><dt>Projects</dt><dd class="num">' . $projects . '</dd>'
            . '<dt>Notes</dt><dd class="num">' . ($notes !== null ? $notes : 'not connected') . '</dd>'
            . '<dt>Memories</dt><dd class="num">' . (is_int($memory) ? $memory : 'not connected') . '</dd>'
            . '<dt>Briefings</dt><dd class="num">' . ($vault !== null ? (int) ($vault['briefings'] ?? 0) : 'not connected') . '</dd></dl>'
            . '<p class="more-link"><a href="/projects.php">View project map</a></p></section>' . "\n"

            . '<section class="panel llm" id="llm" aria-labelledby="llm-h"><h2 id="llm-h">LLM status</h2>'
            . '<p class="panel-meta chip ok" id="llm-meta" data-models="' . ($present ? count(self::MODELS) : 0) . '">' . $connected . ' connected</p><ul class="providers">'
            . $models
            . '<li class="provider" id="llm-local" data-on="' . ($llama === true ? '1' : '0') . '"><span class="disc">' . self::icon('chip') . '</span><p class="p-name">Local model</p>'
            . '<p class="p-state">' . ($llama === null ? 'not connected' : ($llama ? 'Running' : 'Off')) . '</p><p class="p-meta">llama-server · port 8081</p></li>'
            . '</ul></section>' . "\n";
    }

    // The rail: mark, nav with counts, the voice panel. voice.js adds the picker and stop control; none show without JS.
    private static function rail(string $current, int $attention, int $projects): string
    {
        $link = function (string $href, string $icon, string $text, string $key, ?int $count = null) use ($current): string {
            return '<li><a href="' . $href . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . self::icon($icon)
                . '<span>' . self::e($text) . '</span>' . ($count !== null ? '<span class="badge">' . $count . '</span>' : '') . '</a></li>';
        };
        return '<nav class="rail" id="rail" aria-label="Command center">'
            . '<a class="mark" href="/"><span class="mark-ring" aria-hidden="true"></span><span class="mark-name">KC</span>'
            . '<span class="mark-sub">Command Center</span></a><ul class="nav">'
            . $link('/', 'grid', 'Command center', 'home') . $link('/projects.php', 'folder', 'Projects', 'projects', $projects)
            . $link('/#feed', 'bell', 'Attention', 'attention', $attention) . $link('/#news-h', 'news', 'News', 'news')
            . $link('/#numbers-h', 'chart', 'Numbers', 'numbers') . $link('/briefings.php', 'doc', 'Briefings', 'briefings')
            . $link('/#memory', 'vault', 'Vault', 'vault')
            . $link('/wall.php', 'screen', 'Wall', 'wall') . '</ul>'
            . '<section class="voice" id="voice" aria-labelledby="voice-h"><h2 id="voice-h">Voice status</h2>' . self::wave('wave wave-voice', 24)
            . '<p class="v-state">Voice off</p><button type="button" class="mic" id="voice-brief" data-brief disabled>' . self::icon('mic')
            . '<span class="mic-label">Brief me</span></button><p class="v-note">Voice needs JavaScript</p></section></nav>';
    }

    private static function topbar(string $health, string $label, int $now, string $extra): string
    {
        return '<header class="topbar" id="topbar">' . ($health === '' ? '' : '<p class="pill" id="systems" data-health="' . self::e($health)
            . '"><span class="dot"></span>Systems <strong id="systems-word">' . self::e($label) . '</strong></p>')
            . '<p class="clock"><span class="c-date" id="clock-date">' . self::e(date('l j F Y', $now)) . '</span>'
            . '<time class="c-time num" id="clock" datetime="' . self::e(date('c', $now)) . '">' . self::e(date('H:i:s', $now)) . '</time></p>'
            . $extra . '</header>';
    }

    // The footer bar: traffic and dev servers on the left with the running strip, the Talk to Jarvis capsule in the
    // middle (voice.js enables it when a local voice exists), the executive briefing on the right.
    private static function footer(?array $numbers, array $snap, string $strip): string
    {
        $traffic = self::traffic($numbers);
        $ports = count((array) ($snap['ports'] ?? []));
        // Three tiles; the dev servers tile opens the running strip (sessions, loops, failed tasks, ports) in place.
        return '<footer class="footbar" id="footer"><div class="f-left">'
            . '<p class="f-tile">' . self::icon('chart') . '<span class="f-k">Traffic</span><span class="f-v num" id="f-traffic">'
            . ($traffic === null ? 'not connected' : $traffic['sessions'] . ' sessions · ' . $traffic['users'] . ' users') . '</span></p>'
            . '<p class="f-tile">' . self::icon('net') . '<span class="f-k">Network</span><span class="f-v" id="f-online">Local</span></p>'
            . '<details class="f-tile f-run"><summary>' . self::icon('screen') . '<span class="f-k">Dev servers</span><span class="f-v num" id="f-net">'
            . $ports . ' dev ' . ($ports === 1 ? 'server' : 'servers') . '</span></summary>'
            . '<section class="strip" aria-labelledby="strip-h"><h2 id="strip-h">Running now</h2>'
            . '<ul id="running" class="strip-list">' . $strip . '</ul></section></details></div>'
            . '<div class="talk" id="talk"><button type="button" class="capsule" id="talk-button" disabled>' . self::wave('wave wave-talk', 10)
            . '<span class="t-text"><span class="t-main">Talk to Jarvis</span><span class="t-sub">Brief me now by voice</span></span>' . self::wave('wave wave-talk', 10)
            . '</button></div><div class="f-right"><a class="brief" id="exec-brief" href="/briefings.php">' . self::icon('doc')
            . '<span>Executive briefing</span></a></div></footer>';
    }

    private static function render(string $token, array $projects, array $answers, array $clients, bool $elevated,
        string $cc, string $news, string $numbers, array $v, int $now): string
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
        [$health, $label] = self::health($v['facts'], $v['now']);
        $attention = $v['facts'] !== null ? count((array) ($v['facts']['items'] ?? [])) : 0;
        $rail = self::rail('home', $attention, count($v['map']));
        $ico = self::icon('search');
        $search = <<<HTML
<form id="ask-form" class="ask">
<label for="ask-question" class="hidden">Ask Claude</label>
<span class="field">$ico<input type="text" id="ask-question" name="question" maxlength="8000" placeholder="Ask Claude" autocomplete="off" required><kbd>Ctrl K</kbd></span>
<label for="ask-project" class="hidden">Project</label>
<select id="ask-project" name="project">$options</select>
<button type="submit" disabled>Ask</button>
<p class="note" id="ask-note" aria-live="polite"></p>
</form>
<noscript><p class="note">Asking needs JavaScript. Answers below still show.</p></noscript>
<form class="search" action="https://www.google.com/search" method="get" role="search">
<label for="q" class="hidden">Search Google</label>
<input type="search" id="q" name="q" placeholder="Search Google" autofocus>
</form>
HTML;
        $top = self::topbar($health, $label, $now, '<div class="top-tools">' . $search . '</div>');
        $footer = self::footer($v['numbers'], $v['now'], $v['strip']);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<meta name="color-scheme" content="dark">
<title>KC Command Center</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<script type="module" src="/assets/app.js"></script>
<script type="module" src="/assets/orb.js"></script>
<script type="module" src="/assets/core.js"></script>
<script type="module" src="/assets/clients.js"></script>
<script type="module" src="/assets/now.js"></script>
<script type="module" src="/assets/attention.js"></script>
<script type="module" src="/assets/news.js"></script>
<script type="module" src="/assets/numbers.js"></script>
<script type="module" src="/assets/brief.js"></script>
<script type="module" src="/assets/voice.js"></script>
</head>
<body class="cc">
$banner
<h1 class="hidden">KC Command Center</h1>
<div class="cc-shell">
$rail
$top
<main class="cc-grid" id="main">
$cc</main>
$footer
</div>
<div class="deck" id="deck">
<section class="panel briefing" id="briefing" aria-labelledby="briefing-h" hidden>
<h2 id="briefing-h">Briefing</h2>
<div id="briefing-body"></div>
<p class="panel-meta"><a href="/briefings.php">Past briefings</a></p>
</section>

<section class="panel answers" aria-labelledby="answers-h">
<h2 id="answers-h">Answers</h2>
<ol id="answers" class="list">$answerItems</ol>
</section>

<section class="panel news" aria-labelledby="news-h">
<h2 id="news-h">Headlines</h2>
$news
</section>

<section class="panel numbers" aria-labelledby="numbers-h">
<h2 id="numbers-h">Numbers</h2>
$numbers
</section>

<section class="panel clients" aria-labelledby="clients-h">
<h2 id="clients-h">Clients</h2>
<ul id="clients" class="list">$clientItems</ul>
<h3>Leads</h3>
<ul id="leads" class="list">$leadItems</ul>
</section>
</div>
</body>
</html>
HTML;
    }

    // $feed false leaves the attention list out; $news adds the headlines below the grid.
    private static function renderWall(string $token, string $cc, array $v, int $now, bool $feed, bool $news): string
    {
        $t = self::e($token);
        $scripts = ($feed ? '<script type="module" src="/assets/attention.js"></script>' . "\n" : '')
            . ($news ? '<script type="module" src="/assets/news.js"></script>' . "\n" : '');
        [$health, $label] = self::health($v['facts'], $v['now']);
        $top = self::topbar($health, $label, $now, '<div class="top-tools"><button type="button" id="wall-full" hidden>Fullscreen</button>'
            . '<p class="note" id="wall-note" aria-live="polite"></p></div>');
        $footer = self::footer($v['numbers'], $v['now'], $v['strip']);
        $deck = $news ? '<div class="deck" id="deck"><section class="panel news" aria-labelledby="news-h">' . "\n"
            . '<h2 id="news-h">Headlines</h2>' . "\n" . '<ul id="news" class="list"></ul>' . "\n</section></div>\n" : '';
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<meta name="color-scheme" content="dark">
<noscript><meta http-equiv="refresh" content="30"></noscript>
<title>KC Wall</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<link rel="stylesheet" href="/assets/wall.css">
<script type="module" src="/assets/orb.js"></script>
<script type="module" src="/assets/core.js"></script>
<script type="module" src="/assets/wall.js"></script>
$scripts</head>
<body class="wall">
<h1 id="wall-h" class="hidden">KC Command Center wall</h1>
<div class="cc-shell">
$top
<main class="cc-grid" id="main">
$cc</main>
$footer
</div>
$deck</body>
</html>
HTML;
    }

    private static function renderProjects(string $token, string $rows, string $strip, int $count, int $now): string
    {
        $t = self::e($token);
        $rail = self::rail('projects', 0, $count);
        $top = self::topbar('', '', $now, '');
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<meta name="color-scheme" content="dark">
<title>KC Projects</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<script type="module" src="/assets/core.js"></script>
<script type="module" src="/assets/projects.js"></script>
</head>
<body class="cc projects-page">
<h1 class="hidden">KC Projects</h1>
<div class="cc-shell">
$rail
$top
<main class="map-main" id="main">
<section class="panel map" aria-labelledby="map-h">
<h2 id="map-h">Project map</h2>
<div class="table-wrap">
<table class="map-table">
<thead><tr><th scope="col">Project</th><th scope="col">Branch</th><th scope="col">Dirty</th><th scope="col">Last commit</th><th scope="col">Subject</th><th scope="col">Loop</th><th scope="col">Checklist</th><th scope="col">Error</th></tr></thead>
<tbody id="projects">$rows</tbody>
</table>
</div>
</section>
<section class="panel strip" aria-labelledby="strip-h">
<h2 id="strip-h">Running now</h2>
<ul id="running" class="strip-list">$strip</ul>
</section>
</main>
</div>
</body>
</html>
HTML;
    }

    private static function renderBriefings(string $token, string $items, string $body, ?string $day, int $now): string
    {
        $t = self::e($token);
        $rail = self::rail('briefings', 0, 0);
        $top = self::topbar('', '', $now, '');
        $title = $day !== null ? 'Briefing ' . self::e($day) : 'Briefing';
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="kc-token" content="$t">
<meta name="color-scheme" content="dark">
<title>KC Briefings</title>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/hud.css">
<script type="module" src="/assets/core.js"></script>
</head>
<body class="cc briefings-page">
<h1 class="hidden">KC Briefings</h1>
<div class="cc-shell">
$rail
$top
<main class="map-main" id="main">
<section class="panel briefing" id="briefing" aria-labelledby="briefing-h">
<h2 id="briefing-h">$title</h2>
<div id="briefing-body">$body</div>
</section>
<section class="panel history" aria-labelledby="history-h">
<h2 id="history-h">Past briefings</h2>
<ol id="history" class="list">$items</ol>
</section>
</main>
</div>
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
