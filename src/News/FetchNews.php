<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Net/HttpClient.php';
require_once __DIR__ . '/FeedParser.php';
require_once __DIR__ . '/Clusterer.php';

// Fetches every feed in config/feeds.php and stores its items. A feed whose url is not https://, whose response is
// not a 200, is over the size cap or will not parse records its error in news_feeds and the next feed still runs.
// An item already stored keeps its row and first_seen; its title, points and last_seen are refreshed.
class FetchNews
{
    public const MAX_BYTES = 1048576;
    // Items older than this are deleted at the end of each run.
    public const KEEP = 30 * 86400;

    private HttpClient $http;
    private PDO $pdo;
    private array $feeds;
    private int $maxBytes;

    /** @param array<int,array{name:string, url:string, topic:string}> $feeds */
    public function __construct(HttpClient $http, PDO $pdo, array $feeds, int $maxBytes = self::MAX_BYTES)
    {
        $this->http = $http;
        $this->pdo = $pdo;
        $this->feeds = $feeds;
        $this->maxBytes = $maxBytes;
    }

    /** @return array<string,int|string> per feed name, the number of items stored or the error */
    public function run(int $now, ?Log $log = null): array
    {
        $out = [];
        foreach ($this->feeds as $feed) {
            $name = (string) ($feed['name'] ?? '');
            try {
                $items = $this->fetch($feed);
                $this->pdo->beginTransaction();
                foreach ($items as $item) {
                    $this->store($item, $now);
                }
                $this->pdo->commit();
                $this->feedOk($name, $now);
                $out[$name] = count($items);
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $msg = mb_substr($log !== null ? $log->redact($e->getMessage()) : $e->getMessage(), 0, 300, 'UTF-8');
                $this->feedFailed($name, $msg);
                $log?->exception($e, ['where' => 'fetch-news', 'feed' => $name]);
                $out[$name] = $msg;
            }
        }
        // By the earlier of the feed's date and first_seen, so an item a feed keeps listing is not stored fresh again
        // once pruned: its old date prunes it on the same run. Bound as an integer, since SQLite ranks text above any number.
        $prune = $this->pdo->prepare('DELETE FROM news_items WHERE MIN(COALESCE(published_at, first_seen), first_seen) < ?');
        $prune->bindValue(1, $now - self::KEEP, PDO::PARAM_INT);
        $prune->execute();
        return $out;
    }

    private function fetch(array $feed): array
    {
        $url = (string) ($feed['url'] ?? '');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException('feed url must be https://');
        }
        $res = $this->http->get($url);
        if ($res['status'] !== 200) {
            throw new RuntimeException('feed answered ' . $res['status']);
        }
        if (strlen($res['body']) > $this->maxBytes) {
            throw new RuntimeException('feed is over ' . $this->maxBytes . ' bytes');
        }
        return FeedParser::parse($res['body'], $feed);
    }

    private function store(array $item, int $now): void
    {
        $url = FeedParser::safeUrl($item['url']);
        $canon = Clusterer::canonical($url);
        $id = hash('sha256', $item['source'] . "\0" . ($canon ?? 'title:' . mb_strtolower($item['title'], 'UTF-8')));
        $this->pdo->prepare('INSERT INTO news_items (id, source, topic, title, url, canon, published_at, points, first_seen, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET title = excluded.title, topic = excluded.topic, points = excluded.points,
                published_at = COALESCE(excluded.published_at, published_at), last_seen = excluded.last_seen')
            ->execute([$id, $item['source'], $item['topic'], $item['title'], $url, $canon, $item['date'], $item['points'],
                $now, $now]);
    }

    private function feedOk(string $name, int $now): void
    {
        $this->pdo->prepare("INSERT INTO news_feeds (name, last_ok_at, last_error, status) VALUES (?, ?, NULL, 'ok')
            ON CONFLICT (name) DO UPDATE SET last_ok_at = excluded.last_ok_at, last_error = NULL, status = 'ok'")
            ->execute([$name, $now]);
    }

    private function feedFailed(string $name, string $error): void
    {
        $this->pdo->prepare("INSERT INTO news_feeds (name, last_error, status) VALUES (?, ?, 'error')
            ON CONFLICT (name) DO UPDATE SET last_error = excluded.last_error, status = 'error'")
            ->execute([$name, $error]);
    }
}
