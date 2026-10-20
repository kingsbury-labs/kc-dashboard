<?php
require_once __DIR__ . '/../Vault/Vault.php';
require_once __DIR__ . '/../Attention/DailyRead.php';
require_once __DIR__ . '/../News/Scorer.php';
require_once __DIR__ . '/../Numbers/GaCollector.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Log.php';

/**
 * The day's briefing, built in code: the attention top five and the "also seen" count, loops and the cards they
 * passed since the last briefing, projects with a commit in the last 24 hours, the top five headlines, and numbers
 * that moved. The daily read only adds a reason beside a fact it ranked; the list is the code's, so a missing or
 * wrong read hides nothing. Every untrusted field goes through Vault::neutralise before it reaches the markdown.
 */
class Composer
{
    public const TOP = 5;
    public const HEADLINES = 5;
    private const LOOP_STATES = ['running', 'stopped', 'stale'];

    public function __construct(private PDO $pdo, private ?Vault $vault = null)
    {
    }

    /**
     * Everything the briefing reads, from the database: 'attention' (AttentionApi::data), 'ranking'
     * (DailyRead::ranking), 'projects' (projects rows), 'news' (stories, hottest first), 'numbers' (NumberStore::latest).
     */
    public static function gather(PDO $pdo, int $now): array
    {
        $noRun = new ClaudeRunner(function (): never {
            throw new LogicException('the briefing never runs a model');
        }, '');
        return [
            'attention' => AttentionApi::data(new FactStore($pdo), [], $now),
            'ranking' => (new DailyRead($pdo, $noRun))->ranking(),
            'projects' => $pdo->query('SELECT slug, last_date, last_subject, loop_state, loop_card, checklist_passing FROM projects ORDER BY slug')
                ->fetchAll(PDO::FETCH_ASSOC),
            'news' => NewsApi::data(fn() => $pdo, NewsApi::topics(), '', 1, $now, self::HEADLINES, null, false)['stories'],
            'numbers' => (new NumberStore($pdo))->latest(),
        ];
    }

    /** Builds today's briefing from $in, writes Briefings/YYYY-MM-DD.md and stores it. A rerun replaces only today's. */
    public function build(array $in, int $now): array
    {
        if ($this->vault === null) {
            throw new LogicException('building a briefing needs the vault');
        }
        $b = $this->compose($in, $now);
        $this->vault->write('Briefings/' . $b['day'] . '.md', $b['body']);
        $this->pdo->prepare('INSERT OR REPLACE INTO briefings (day, created_at, body, facts, snapshot) VALUES (?, ?, ?, ?, ?)')
            ->execute([$b['day'], $now, $b['body'], json_encode($b['facts'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($b['snapshot'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        return $this->get($b['day']);
    }

    /** @return array{day: string, body: string, facts: string[], snapshot: array} */
    public function compose(array $in, int $now): array
    {
        $day = date('Y-m-d', $now);
        $prev = $this->previous($day);
        $n = fn($s) => Vault::neutralise((string) $s);
        $lines = ['# Briefing ' . $day, ''];

        $attention = is_array($in['attention'] ?? null) ? $in['attention'] : [];
        $items = array_slice(array_values((array) ($attention['items'] ?? [])), 0, self::TOP);
        $rest = array_slice(array_values((array) ($attention['items'] ?? [])), self::TOP);
        $also = array_merge($rest, array_values((array) ($attention['also'] ?? [])));
        $ranking = is_array($in['ranking'] ?? null) ? $in['ranking'] : [];
        $lines[] = '## Attention';
        foreach ($items as $f) {
            $id = (string) ($f['id'] ?? '');            $parts = [$n($f['severity'] ?? ''), $n($f['kind'] ?? ''), $n($f['subject'] ?? '')];
            if (($f['due_date'] ?? null) !== null) {
                $parts[] = 'due ' . $n($f['due_date']);
            }
            if (($f['evidence'] ?? null) !== null && $f['evidence'] !== '') {
                $parts[] = $n($f['evidence']);
            }
            $reason = $ranking[$id]['reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $parts[] = 'read: ' . $n($reason);
            }
            $parts[] = 'id ' . $n($id);
            $lines[] = '- ' . implode(' · ', array_filter($parts, fn($p) => $p !== ''));
        }
        if ($items === []) {
            $lines[] = 'Nothing needs you.';
        }
        $lines[] = 'Also seen: ' . count($also);
        $lines[] = $ranking === [] ? 'No daily read yet.' : 'Reasons are from the last daily read.';
        $lines[] = '';

        $loops = [];
        $passing = [];
        $commits = [];
        foreach ((array) ($in['projects'] ?? []) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if (($p['checklist_passing'] ?? null) !== null) {
                $passing[$slug] = (int) $p['checklist_passing'];
            }
            if (in_array($p['loop_state'] ?? null, self::LOOP_STATES, true)) {
                $was = $prev['loops'][$slug] ?? null;
                $count = $passing[$slug] ?? null;
                $line = '- ' . $n($slug) . ' · ' . $n($p['loop_state']) . (($p['loop_card'] ?? null) !== null ? ' · card ' . $n($p['loop_card']) : '');
                if ($count !== null) {
                    $line .= ' · ' . $count . ' passing' . ($was !== null ? ' (' . ($count - $was >= 0 ? '+' : '') . ($count - $was) . ' since last briefing)' : '');
                }
                $loops[] = $line;
            }
            $t = ($p['last_date'] ?? null) !== null ? strtotime((string) $p['last_date']) : false;
            if ($t !== false && $t > $now - 86400 && $t <= $now + 300) {
                $commits[] = '- ' . $n($slug) . ' · ' . $n($p['last_subject'] ?? '');
            }
        }
        $lines[] = '## Loops';
        array_push($lines, ...($loops !== [] ? $loops : ['No loop running.']));
        $lines[] = '';
        $lines[] = '## Commits since yesterday';
        array_push($lines, ...($commits !== [] ? $commits : ['No commits.']));
        $lines[] = '';

        $lines[] = '## Headlines';
        $news = array_slice(array_values((array) ($in['news'] ?? [])), 0, self::HEADLINES);
        foreach ($news as $s) {
            $lines[] = '- ' . $n($s['title'] ?? '') . (is_array($s['sources'] ?? null) && $s['sources'] !== []
                ? ' · ' . $n(implode(', ', array_map('strval', $s['sources']))) : '');
        }
        if ($news === []) {
            $lines[] = 'No headlines.';
        }
        $lines[] = '';

        // Values only: a label (a GA page path, a status word) is never carried into the briefing.
        $values = [];
        foreach ((array) ($in['numbers'] ?? []) as $src) {
            foreach ((array) ($src['values'] ?? []) as $metric => $v) {
                if (is_array($v) && ($v['label'] ?? null) === null && (is_int($v['value'] ?? null) || is_float($v['value'] ?? null))) {
                    $values[(string) ($src['name'] ?? '') . ' ' . $metric] = $v['value'];
                }
            }
        }
        $moved = [];
        foreach ($values as $key => $v) {
            $was = $prev['numbers'][$key] ?? null;
            if ($was !== null && $was != $v) {
                $moved[] = '- ' . $n($key) . ': ' . $was . ' to ' . $v;
            }
        }
        $lines[] = '## Numbers that moved';
        array_push($lines, ...($moved !== [] ? $moved : [$prev === null ? 'First briefing: nothing to compare.' : 'Nothing moved.']));
        $lines[] = '';

        $ids = array_values(array_unique(array_map(fn($f) => (string) ($f['id'] ?? ''), array_merge($items, $also))));
        return ['day' => $day, 'body' => implode("\n", $lines), 'facts' => $ids, 'snapshot' => ['loops' => $passing, 'numbers' => $values]];
    }

    /** @return ?array{day: string, created_at: int, body: string, facts: string[]} */
    public function get(string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT day, created_at, body, facts FROM briefings WHERE day = ?');
        $st->execute([$day]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['day' => $r['day'], 'created_at' => (int) $r['created_at'], 'body' => $r['body'],
            'facts' => (array) json_decode($r['facts'], true)];
    }

    /** Every stored briefing's day and time, newest first. */
    public function history(int $limit = 400): array
    {
        $rows = $this->pdo->query('SELECT day, created_at FROM briefings ORDER BY day DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['day' => $r['day'], 'created_at' => (int) $r['created_at']], $rows);
    }

    // The last briefing before $day's snapshot, or null when there is none.
    private function previous(string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT snapshot FROM briefings WHERE day < ? ORDER BY day DESC LIMIT 1');
        $st->execute([$day]);
        $s = $st->fetchColumn();
        if ($s === false) {
            return null;
        }
        $d = json_decode((string) $s, true);
        return ['loops' => (array) ($d['loops'] ?? []), 'numbers' => (array) ($d['numbers'] ?? [])];
    }
}

// POST /api/brief.php: today's briefing, built on demand when there is none. Building writes the database and the
// vault, so it is a write: checkWrite (token, Origin, Host), and a GET is refused. Nothing opens before the check.
class BriefApi
{
    /** @param Closure(): array{0: PDO, 1: Vault} $open */
    public static function post(array $server, string $tokenPath, Closure $open, Log $log, ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || !(new RequestGuard(Token::load($tokenPath, $log)))->checkWrite($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $now = $now ?? time();
            [$pdo, $vault] = $open();
            $c = new Composer($pdo, $vault);
            $b = $c->get(date('Y-m-d', $now)) ?? $c->build(Composer::gather($pdo, $now), $now);
            return Response::json(200, ['day' => $b['day'], 'created_at' => $b['created_at'], 'body' => $b['body']]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'brief']);
            return Response::error($e);
        }
    }
}
