<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/FeedParser.php';
require_once __DIR__ . '/Clusterer.php';

// How hot a story is: each distinct source covering it adds SOURCE_WEIGHT, Hacker News points add their log, and
// the sum falls with the story's age in hours, HN-style. A date in the future counts as now.
class Scorer
{
    public const SOURCE_WEIGHT = 2.0;
    public const GRAVITY = 1.5;

    public static function score(array $story, int $now): float
    {
        $age = max(0, $now - (int) ($story['date'] ?? $now)) / 3600;
        $base = self::SOURCE_WEIGHT * count($story['sources'] ?? []) + log(1 + max(0, (int) ($story['points'] ?? 0)));
        return $base / pow($age + 2, self::GRAVITY);
    }

    /** Hottest first; a tie goes to the newer story, then the title. */
    public static function rank(array $stories, int $now): array
    {
        foreach ($stories as &$s) {
            $s['score'] = self::score($s, $now);
        }
        unset($s);
        usort($stories, fn($a, $b) => [$b['score'], $b['date'] ?? 0, $a['title']] <=> [$a['score'], $a['date'] ?? 0, $b['title']]);
        return $stories;
    }
}

// GET /api/news.php?page=N&topic=T. The guard runs before the token is read, and both before the database opens.
class NewsApi
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 100;
    // Stories older than this are not shown; FetchNews prunes them later.
    public const WINDOW = 7 * 86400;
    private const LOAD_CAP = 3000;

    /** @param string[] $topics the topics in config/feeds.php; any other topic is refused */
    public static function get(array $server, string $tokenPath, Closure $pdo, Log $log, array $topics, array $query,
        ?int $now = null, int $pageSize = self::PAGE_SIZE, ?string $cache = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $topic = $query['topic'] ?? '';
            $page = $query['page'] ?? '1';
            if (!is_string($topic) || ($topic !== '' && !in_array($topic, $topics, true))) {
                return Response::json(400, ['error' => 'bad topic']);
            }
            if (!is_string($page) || preg_match('/^[1-9]\d{0,2}$/', $page) !== 1 || (int) $page > self::MAX_PAGE) {
                return Response::json(400, ['error' => 'bad page']);
            }
            return Response::json(200, self::data($pdo, $topics, $topic, (int) $page, $now ?? time(), $pageSize, $cache));
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'news']);
            return Response::error($e);
        }
    }

    public const CACHE_SECONDS = 60;
    private const CACHE_CAP = 4194304;

    /**
     * The page as the API and the no-JS panel both show it. $pdo opens the database only when the ranked list is
     * not in $cache (a file in data/) from the last CACHE_SECONDS, so a page load does not cluster 3000 rows each time.
     * Only news.php writes it ($write): a navigation, which any site can cause, reads data/ but never writes there.
     * news.js calls news.php straight after each page load, so the next new tab finds the cache warm.
     */
    public static function data(Closure $pdo, array $topics, string $topic, int $page, int $now,
        int $pageSize = self::PAGE_SIZE, ?string $cache = null, bool $write = true): array
    {
        $stories = $cache !== null ? self::readCache($cache, $now) : null;
        if ($stories === null) {
            $stories = self::ranked($pdo(), $now);
            if ($cache !== null && $write) {
                self::writeCache($cache, $now, $stories);
            }
        }
        if ($topic !== '') {
            $stories = array_values(array_filter($stories, fn($s) => in_array($topic, $s['topics'], true)));
        }
        $slice = array_slice($stories, ($page - 1) * $pageSize, $pageSize);
        return [
            'stories' => array_map(fn($s) => [
                'title' => $s['title'],
                'url' => self::safeUrl($s['url']),
                'sources' => $s['sources'],
                'topics' => $s['topics'],
                'points' => $s['points'],
                'date' => gmdate('c', (int) $s['date']),
                'score' => round($s['score'], 4),
            ], $slice),
            'page' => $page,
            'more' => count($stories) > $page * $pageSize,
            'topic' => $topic,
            'topics' => array_values($topics),
        ];
    }

    // Every story in the window, hottest first. An item's date is the earlier of the feed's date and first_seen,
    // so a feed that dates its items in the future gets no fresher score and no longer stay in the window.
    private static function ranked(PDO $pdo, int $now): array
    {
        $st = $pdo->prepare('SELECT source, topic, title, url, canon, first_seen, points,
                MIN(COALESCE(published_at, first_seen), first_seen) AS date
            FROM news_items WHERE MIN(COALESCE(published_at, first_seen), first_seen) >= ?
            ORDER BY first_seen DESC LIMIT ' . self::LOAD_CAP);
        // Bound as an integer: execute([...]) binds text, and SQLite ranks every integer below every text value.
        $st->bindValue(1, $now - self::WINDOW, PDO::PARAM_INT);
        $st->execute();
        $items = array_map(fn($r) => ['source' => (string) $r['source'], 'topic' => (string) $r['topic'],
            'title' => (string) $r['title'], 'url' => $r['url'], 'canon' => $r['canon'], 'date' => (int) $r['date'],
            'first_seen' => (int) $r['first_seen'], 'points' => $r['points'] === null ? null : (int) $r['points']],
            $st->fetchAll(PDO::FETCH_ASSOC));
        return array_map(fn($s) => ['title' => $s['title'], 'url' => $s['url'], 'sources' => $s['sources'],
            'topics' => $s['topics'], 'points' => $s['points'], 'date' => (int) $s['date'], 'score' => (float) $s['score']],
            Scorer::rank(Clusterer::cluster($items), $now));
    }

    // Null unless the file was written in the last CACHE_SECONDS (and not in the future) and every story has the
    // shape ranked() gives. The file is ours, but it is still read as data.
    private static function readCache(string $file, int $now): ?array
    {
        $raw = @file_get_contents($file, false, null, 0, self::CACHE_CAP + 1);
        $data = is_string($raw) && strlen($raw) <= self::CACHE_CAP ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_int($data['at'] ?? null) || $data['at'] > $now || $now - $data['at'] >= self::CACHE_SECONDS
            || !is_array($data['stories'] ?? null)) {
            return null;
        }
        $strings = fn($v) => is_array($v) && array_filter($v, fn($x) => !is_string($x)) === [];
        foreach ($data['stories'] as $s) {
            if (!is_array($s) || !is_string($s['title'] ?? null) || !(is_string($s['url'] ?? null) || ($s['url'] ?? 0) === null)
                || !$strings($s['sources'] ?? null) || !$strings($s['topics'] ?? null) || !is_int($s['points'] ?? null)
                || !is_int($s['date'] ?? null) || !(is_float($s['score'] ?? null) || is_int($s['score'] ?? null))) {
                return null;
            }
        }
        return $data['stories'];
    }

    // Written whole to a temp name and renamed, so a reader never sees half a file. A failed write only costs a rebuild.
    private static function writeCache(string $file, int $now, array $stories): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['at' => $now, 'stories' => $stories], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json !== false && strlen($json) <= self::CACHE_CAP && @file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * Checked again on the way out: http(s) only, and never this machine or a bare address. The host is lowercased
     * and percent-decoded first, and must then be plain ASCII labels (an internationalised name only as punycode).
     */
    public static function safeUrl($url): ?string
    {
        $url = is_string($url) ? FeedParser::safeUrl($url) : null;
        if ($url === null) {
            return null;
        }
        $h = rtrim(strtolower(rawurldecode((string) parse_url($url, PHP_URL_HOST))), '.');
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $h) !== 1
            || $h === 'localhost' || str_ends_with($h, '.localhost')
            || preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/', $h) === 1) {
            return null;
        }
        return $url;
    }

    /** @return string[] the topics in config/feeds.php, in order */
    public static function topics(?array $config = null): array
    {
        $config = $config ?? require __DIR__ . '/../../config/feeds.php';
        return array_values(array_unique(array_map(fn($f) => (string) $f['topic'], $config['feeds'] ?? [])));
    }
}
