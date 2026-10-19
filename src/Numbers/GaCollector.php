<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Process/ProcessRunner.php';

// The numbers panel: GA4 and Search Console per property (this file, through bin/collect_ga.py), Sembr's status
// endpoint (StatusCollector) and Bugsink counts (BugsinkCollector). The store and the API live here too, since
// GA is most of the rows.

// One collector reads one or more sources. collect() returns, per source name, either its rows or its error;
// a collector that throws records the error on every source it names, and run() goes on to the next.
abstract class NumbersCollector
{
    /** @return string[] the sources this collector writes */
    abstract public function sources(): array;

    /** @return array<string,array{rows?:array<int,array{metric:string,value:int|float|null,label?:?string}>,error?:string}> */
    abstract public function collect(): array;

    /**
     * @param NumbersCollector[] $collectors
     * @return array<string,int|string> per source, the number of rows stored or the error
     */
    public static function run(array $collectors, NumberStore $store, Log $log, int $now): array
    {
        $out = [];
        foreach ($collectors as $c) {
            try {
                $results = $c->collect();
            } catch (Throwable $e) {
                $log->exception($e, ['where' => 'numbers', 'collector' => get_class($c)]);
                $results = array_fill_keys($c->sources(), ['error' => $e->getMessage()]);
            }
            foreach ($results as $source => $r) {
                if (isset($r['error'])) {
                    $store->failed($source, (string) $r['error'], $log);
                    $out[$source] = $log->redact((string) $r['error']);
                } else {
                    $out[$source] = $store->save($source, $r['rows'] ?? [], $now);
                }
            }
        }
        return $out;
    }
}

class NumberStore
{
    private const LABEL_CAP = 300;
    private const ERROR_CAP = 500;

    public function __construct(private PDO $pdo)
    {
    }

    // Replaces the source's rows whole. Only numbers and short strings are kept, whatever a collector hands over.
    public function save(string $source, array $rows, int $now): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM numbers WHERE source = ?')->execute([$source]);
            $ins = $this->pdo->prepare('INSERT OR REPLACE INTO numbers (source, metric, value, label, collected_at) VALUES (?, ?, ?, ?, ?)');
            $n = 0;
            foreach ($rows as $r) {
                $metric = $r['metric'] ?? null;
                $value = $r['value'] ?? null;
                $label = $r['label'] ?? null;
                if (!is_string($metric) || preg_match('/^[a-z_]{1,40}(:\d{2})?$/', $metric) !== 1) {
                    continue;
                }
                $ins->execute([$source, $metric, is_int($value) || is_float($value) ? $value : null,
                    is_string($label) ? mb_substr($label, 0, self::LABEL_CAP) : null, $now]);
                $n++;
            }
            $this->pdo->prepare("INSERT INTO number_sources (name, last_ok_at, last_error, status) VALUES (?, ?, NULL, 'ok')
                ON CONFLICT (name) DO UPDATE SET last_ok_at = excluded.last_ok_at, last_error = NULL, status = 'ok'")
                ->execute([$source, $now]);
            $this->pdo->commit();
            return $n;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Keeps the last good rows, so the page shows them next to the error.
    public function failed(string $source, string $error, Log $log): void
    {
        $this->pdo->prepare("INSERT INTO number_sources (name, last_error, status) VALUES (?, ?, 'error')
            ON CONFLICT (name) DO UPDATE SET last_error = excluded.last_error, status = 'error'")
            ->execute([$source, mb_substr($log->redact($error), 0, self::ERROR_CAP)]);
    }

    /** @return array<int,array{name:string,status:string,last_ok_at:?int,last_error:?string,values:array}> */
    public function latest(): array
    {
        $sources = $this->pdo->query('SELECT name, status, last_ok_at, last_error FROM number_sources ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->pdo->query('SELECT source, metric, value, label FROM numbers ORDER BY source, metric')->fetchAll(PDO::FETCH_ASSOC);
        $values = [];
        foreach ($rows as $r) {
            $v = $r['value'] === null ? null : (float) $r['value'];
            $values[$r['source']][$r['metric']] = ['value' => $v !== null && floor($v) === $v && abs($v) < PHP_INT_MAX ? (int) $v : $v,
                'label' => $r['label']];
        }
        return array_map(fn($s) => [
            'name' => $s['name'],
            'status' => $s['status'],
            'last_ok_at' => $s['last_ok_at'] === null ? null : (int) $s['last_ok_at'],
            'last_error' => $s['last_error'],
            'values' => $values[$s['name']] ?? [],
        ], $sources);
    }
}

// GET /api/numbers.php: the latest value of every number, per source, with each source's last run.
class NumbersApi
{
    // A source with no good run in this long shows as stale. Collection runs a few times a day.
    public const MAX_AGE = 26 * 3600;

    public static function get(array $server, string $tokenPath, Closure $pdo, Log $log, ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, self::data($pdo(), $now ?? time()));
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'numbers']);
            return Response::error($e);
        }
    }

    public static function data(PDO $pdo, int $now): array
    {
        $sources = (new NumberStore($pdo))->latest();
        foreach ($sources as &$s) {
            $s['stale'] = $s['last_ok_at'] === null || $now - $s['last_ok_at'] > self::MAX_AGE || $s['last_ok_at'] > $now + 300;
        }
        return ['sources' => $sources];
    }
}

// Runs bin/collect_ga.py through ProcessRunner and turns its JSON into rows: 'ga4:<slug>' and
// 'search_console:<slug>' per configured property. A property the script did not report, or reported with an
// error, records that error; a slug the config does not list is ignored.
class GaCollector extends NumbersCollector
{
    public const TIMEOUT = 180;
    private const TOP_PAGES = 10;

    /**
     * @param array{service_account_path:string,properties:array<string,array{ga4:string,search_console:string}>} $config
     * @param ?string $fixture a raw-responses file: the script runs only its transform, with no credentials
     */
    public function __construct(private ProcessRunner $runner, private string $python, private string $script,
        private array $config, private ?string $fixture = null)
    {
    }

    public function sources(): array
    {
        $out = [];
        foreach (array_keys($this->config['properties'] ?? []) as $slug) {
            $out[] = 'ga4:' . $slug;
            $out[] = 'search_console:' . $slug;
        }
        return $out;
    }

    /** @return string[] */
    public function argv(): array
    {
        $argv = [$this->python, '-I', $this->script];
        if ($this->fixture !== null) {
            return array_merge($argv, ['--from-fixture', $this->fixture]);
        }
        $argv[] = '--credentials';
        $argv[] = (string) ($this->config['service_account_path'] ?? '');
        foreach ($this->config['properties'] ?? [] as $slug => $p) {
            array_push($argv, '--property', (string) $slug, (string) ($p['ga4'] ?? ''), (string) ($p['search_console'] ?? ''));
        }
        return $argv;
    }

    public function collect(): array
    {
        $res = $this->runner->run($this->argv(), null, self::TIMEOUT);
        if ($res['timedOut']) {
            throw new RuntimeException('collect_ga.py timed out');
        }
        if ($res['code'] !== 0) {
            $last = trim((string) strrchr("\n" . trim($res['stderr']), "\n"));
            throw new RuntimeException('collect_ga.py exited ' . $res['code'] . ($last !== '' ? ': ' . $last : ''));
        }
        $data = json_decode($res['stdout'], true);
        if (!is_array($data) || !is_array($data['properties'] ?? null)) {
            throw new RuntimeException('collect_ga.py gave no readable JSON');
        }
        $reported = [];
        foreach ($data['properties'] as $p) {
            if (is_array($p) && is_string($p['slug'] ?? null)) {
                $reported[$p['slug']] = $p;
            }
        }
        $out = [];
        foreach (array_keys($this->config['properties'] ?? []) as $slug) {
            $p = $reported[$slug] ?? null;
            $out['ga4:' . $slug] = $p === null ? ['error' => 'not reported'] : self::ga4($p);
            $out['search_console:' . $slug] = $p === null ? ['error' => 'not reported'] : self::search($p);
        }
        return $out;
    }

    private static function ga4(array $p): array
    {
        if (is_string($p['ga4_error'] ?? null) || !is_array($p['ga4'] ?? null)) {
            return ['error' => 'ga4: ' . (is_string($p['ga4_error'] ?? null) ? $p['ga4_error'] : 'no data')];
        }
        $g = $p['ga4'];
        $rows = [];
        foreach (['sessions', 'users', 'active_users'] as $m) {
            $rows[] = ['metric' => $m, 'value' => self::num($g[$m] ?? null)];
        }
        foreach (array_slice(is_array($g['top_pages'] ?? null) ? array_values($g['top_pages']) : [], 0, self::TOP_PAGES) as $i => $page) {
            if (is_array($page) && is_string($page['path'] ?? null)) {
                $rows[] = ['metric' => sprintf('top_page:%02d', $i + 1), 'value' => self::num($page['views'] ?? null), 'label' => $page['path']];
            }
        }
        return ['rows' => $rows];
    }

    private static function search(array $p): array
    {
        if (is_string($p['search_console_error'] ?? null) || !is_array($p['search_console'] ?? null)) {
            return ['error' => 'search console: ' . (is_string($p['search_console_error'] ?? null) ? $p['search_console_error'] : 'no data')];
        }
        $rows = [];
        foreach (['clicks', 'impressions', 'ctr', 'position'] as $m) {
            $rows[] = ['metric' => $m, 'value' => self::num($p['search_console'][$m] ?? null)];
        }
        return ['rows' => $rows];
    }

    private static function num($v): int|float|null
    {
        return is_int($v) || is_float($v) ? $v : null;
    }
}
