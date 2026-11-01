<?php
require_once __DIR__ . '/NeverShow.php';
require_once __DIR__ . '/../Collectors/Weather.php';
require_once __DIR__ . '/../Collectors/Finance.php';
require_once __DIR__ . '/../News/TitleFilter.php';
require_once __DIR__ . '/../News/Scorer.php';

/**
 * The daily block of the cockpit snapshot: weather, AI news, kc-finance and plan limits. Every part reads a file or
 * the local database only, never the network, and a part that fails is null so the rest still draws.
 * $deps: 'weather' => cache path, 'news' => fn(): PDO, 'finance' => alerts path, 'usage' => fn(): array of folders.
 */
class CockpitDaily
{
    public const NEWS = 6;
    public const FINANCE_LINES = 8;
    public const USAGE_FILES = 40;
    public const USAGE_TAIL = 1048576;
    public const USAGE_STALE = 1800;

    public static function build(array $deps, int $now, ?Log $log = null): array
    {
        return [
            'weather' => self::part(fn() => isset($deps['weather']) ? Weather::cached((string) $deps['weather'], $now) : null, $log),
            'news' => self::part(fn() => isset($deps['news']) ? self::news(($deps['news'])(), $now) : null, $log),
            'finance' => self::part(fn() => isset($deps['finance']) ? self::finance((string) $deps['finance'], $now) : null, $log),
            'usage' => self::part(fn() => self::limits($deps, $now), $log),
        ];
    }

    // Claude's reading at the top (as before) and Codex's beside it under 'codex'; each is null when it has no reading.
    private static function limits(array $deps, int $now): ?array
    {
        $claude = isset($deps['usage']) ? self::usage(($deps['usage'])(), $now) : null;
        $codex = isset($deps['codex']) ? self::codex((string) $deps['codex'], $now) : null;
        if ($codex === null) {
            return $claude;
        }
        return ($claude ?? ['at' => null, 'age' => null, 'stale' => true, 'five_hour' => null, 'seven_day' => null]) + ['codex' => $codex];
    }

    /**
     * Codex CLI's limits: the last rate_limits reading in the newest rollout file under <dir>/YYYY/MM/DD/ (newest
     * path first, stop at the first file that has one). Only the two windows' used_percent and resets_at are
     * taken; nothing else in those files is kept. A reading's time is its file's modified time.
     */
    public static function codex(string $dir, int $now): ?array
    {
        $files = glob(rtrim($dir, '\\/') . '/*/*/*/rollout-*.jsonl') ?: [];
        rsort($files);
        foreach (array_slice($files, 0, self::USAGE_FILES) as $f) {
            $size = (int) @filesize($f);
            $tail = (string) @file_get_contents($f, false, null, max(0, $size - self::USAGE_TAIL));
            if (!str_contains($tail, '"rate_limits"')) {
                continue;
            }
            $found = null;
            foreach (explode("\n", $tail) as $line) {
                if (str_contains($line, '"rate_limits"')) {
                    $ev = json_decode($line, true);
                    $r = is_array($ev) ? ($ev['payload']['rate_limits'] ?? $ev['rate_limits'] ?? null) : null;
                    if (is_array($r) && (is_array($r['primary'] ?? null) || is_array($r['secondary'] ?? null))) {
                        $found = $r;
                    }
                }
            }
            if ($found !== null) {
                $win = fn($x) => is_array($x) && is_numeric($x['used_percent'] ?? null)
                    ? ['percent' => (int) round((float) $x['used_percent']), 'resets' => is_numeric($x['resets_at'] ?? null) ? (int) $x['resets_at'] : null]
                    : null;
                $mtime = (int) @filemtime($f);
                $age = max(0, $now - $mtime);
                return ['at' => $mtime, 'age' => $age, 'stale' => $age > self::USAGE_STALE,
                    'five_hour' => $win($found['primary'] ?? null), 'seven_day' => $win($found['secondary'] ?? null)];
            }
        }
        return null;
    }

    // The hottest recent AI items with an http(s) link, in Scorer order, bare slugs, versions and quotes dropped.
    // Not clustered: Clusterer would fold same-source look-alike titles, and the panel is one story per item.
    public static function news(PDO $pdo, ?int $now = null): array
    {
        $now = $now ?? time();
        $q = $pdo->prepare("SELECT title, source, url, MIN(COALESCE(published_at, first_seen), first_seen) AS date FROM news_items
            WHERE topic = 'ai' AND url IS NOT NULL ORDER BY date DESC LIMIT 100");
        $q->execute();
        $items = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (preg_match('~^https?://[^\s]+$~i', (string) $r['url']) && TitleFilter::keep((string) $r['title'])) {
                $items[] = ['title' => (string) $r['title'], 'source' => (string) $r['source'], 'url' => (string) $r['url'],
                    'date' => (int) $r['date'], 'sources' => [(string) $r['source']], 'points' => 0];
            }
        }
        $out = [];
        foreach (array_slice(Scorer::rank($items, $now), 0, self::NEWS) as $s) {
            $out[] = ['title' => NeverShow::text($s['title'], null, 200), 'source' => $s['source'], 'url' => $s['url'], 'ts' => $s['date']];
        }
        return $out;
    }

    /**
     * kc-finance's alerts.json, read only and tolerantly: Finance::collect refuses the whole file for one bad fact,
     * which the collector run needs and the panel does not. A fact without id, kind or subject is skipped, a bad
     * date or amount is null. A figure the file does not carry is null, shown as a dash.
     */
    public static function finance(string $path, int $now): ?array
    {
        if (!is_file($path) || filesize($path) > Finance::MAX_BYTES) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true, 16);
        if (!is_array($data) || !is_array($data['facts'] ?? null)) {
            return null;
        }
        $facts = [];
        foreach ($data['facts'] as $x) {
            $ok = fn($k) => is_array($x) && is_string($x[$k] ?? null) && $x[$k] !== '';
            if (!$ok('id') || !$ok('kind')) {
                continue;
            }
            $due = is_string($x['due_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $x['due_date']) ? $x['due_date'] : null;
            $cents = is_int($x['amount_cents'] ?? null) ? $x['amount_cents'] : null;
            // A null or empty subject is labelled from the kind, as Finance::collect does.
            $subject = $ok('subject') ? $x['subject'] : Finance::label($x['kind'], $cents, $due);
            $facts[] = ['kind' => $x['kind'], 'subject' => NeverShow::text($subject, null, 120), 'due_date' => $due, 'amount_cents' => $cents];
        }
        $generated = is_string($data['generated_at'] ?? null) ? strtotime($data['generated_at']) : false;
        $amount = function (string $kind) use ($facts): ?int {
            foreach ($facts as $x) {
                if (preg_match($kind, $x['kind']) && is_int($x['amount_cents'])) {
                    return $x['amount_cents'];
                }
            }
            return null;
        };
        $today = date('Y-m-d', $now);
        $pick = function (callable $keep) use ($facts): ?array {
            $best = null;
            foreach ($facts as $x) {
                if ($x['due_date'] !== null && $keep($x) && ($best === null || $x['due_date'] < $best['due_date'])) {
                    $best = $x;
                }
            }
            return $best === null ? null : ['subject' => $best['subject'], 'due' => $best['due_date'], 'amount_cents' => $best['amount_cents']];
        };
        return [
            'at' => $generated === false ? $now : $generated,
            'receivables_cents' => $amount('/receivable/i'),
            'reserve_cents' => $amount('/reserve/i'),
            'next_due' => $pick(fn($x) => $x['due_date'] >= $today && !preg_match('/overdue/i', $x['kind'])),
            'oldest_overdue' => $pick(fn($x) => (bool) preg_match('/overdue/i', $x['kind'])),
            'alerts' => count($facts),
            'lines' => array_map(fn($x) => ['kind' => str_replace(['-', '_'], ' ', $x['kind']), 'subject' => $x['subject'], 'date' => $x['due_date']],
                array_slice(array_values(array_filter($facts, fn($x) => !preg_match('/receivable|reserve/i', $x['kind']))), 0, self::FINANCE_LINES)),
        ];
    }

    /**
     * The newest rate_limit_event across stream files (*.jsonl in each folder, one level down, and in the subagents
     * folders of a transcripts tree: <dir>/<project>/<session>/subagents/*.jsonl), newest file first;
     * within a file its last event. A reading's time is its file's modified time. Null when none carries one.
     */
    public static function usage(array $dirs, int $now): ?array
    {
        $files = [];
        foreach ($dirs as $d) {
            if (is_string($d) && is_dir($d)) {
                foreach (array_merge(glob($d . '/*.jsonl') ?: [], glob($d . '/*/*.jsonl') ?: [], glob($d . '/*/*/subagents/*.jsonl') ?: []) as $f) {
                    $files[$f] = (int) @filemtime($f);
                }
            }
        }
        arsort($files);
        foreach (array_slice($files, 0, self::USAGE_FILES, true) as $f => $mtime) {
            $size = (int) @filesize($f);
            $tail = (string) @file_get_contents($f, false, null, max(0, $size - self::USAGE_TAIL));
            if (!str_contains($tail, 'rate_limit_event')) {
                continue;
            }
            $found = null;
            foreach (explode("\n", $tail) as $line) {
                if (str_contains($line, '"rate_limit_event"')) {
                    $ev = json_decode($line, true);
                    $w = $ev['rate_limit_info']['unifiedWindows'] ?? null;
                    if (($ev['type'] ?? null) === 'rate_limit_event' && is_array($w)) {
                        $found = $w;
                    }
                }
            }
            if ($found !== null) {
                $win = fn($x) => is_array($x) && is_numeric($x['utilization'] ?? null)
                    ? ['percent' => (int) round((float) $x['utilization'] * 100), 'resets' => is_numeric($x['resetsAt'] ?? null) ? (int) $x['resetsAt'] : null]
                    : null;
                $age = max(0, $now - $mtime);
                return ['at' => $mtime, 'age' => $age, 'stale' => $age > self::USAGE_STALE,
                    'five_hour' => $win($found['five_hour'] ?? null), 'seven_day' => $win($found['seven_day'] ?? null)];
            }
        }
        return null;
    }

    private static function part(Closure $fn, ?Log $log)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $log?->exception($e, ['where' => 'cockpit daily']);
            return null;
        }
    }
}
