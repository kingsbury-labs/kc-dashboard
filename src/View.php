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
     * 'projects' => ProjectList, 'answers' => fn(): array, 'clients' => fn(): array, 'elevated' => fn(): bool.
     * A failing answers, clients or elevation source leaves its part of the page empty, not a 500.
     */
    public static function page(array $server, string $tokenPath, Log $log, array $deps = []): array
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
            return Response::html(200, self::render($token, $projects, $answers, $clients, $elevated));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    // GET /api/clients.php: {"clients": [...], "leads": [...]}.
    public static function clients(array $server, array $config, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
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

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function render(string $token, array $projects, array $answers, array $clients, bool $elevated): string
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
<script type="module" src="/assets/app.js"></script>
<script type="module" src="/assets/clients.js"></script>
</head>
<body>
$banner
<main>
<form class="search" action="https://www.google.com/search" method="get" role="search">
<label for="q" class="hidden">Search Google</label>
<input type="search" id="q" name="q" placeholder="Search Google" autofocus>
</form>

<section class="panel ask" aria-labelledby="ask-h">
<h2 id="ask-h">Ask Claude</h2>
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
