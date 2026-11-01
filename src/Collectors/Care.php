<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/SslExpiry.php';
require_once __DIR__ . '/../Process/ProcessRunner.php';

/**
 * kc-care's clients as the dashboard sees them, saved to a cache file the cockpit's Care tab reads. Records no facts.
 * Read only against every source: kc-care's clients.json, UptimeRobot's getMonitors (key by path, added to the log's
 * redaction before the request) and the open issues in rob-kingsbury/kc-care through `gh issue list`, the one command
 * this collector can start (see gh()). SSL days come from SslExpiry's fetcher. A failing part leaves its field null
 * so the rest still shows; a down monitor keeps the time it was first seen down across runs, for the incident.
 * $post: fn(string $url, array $fields): string, the response body; the default is a curl POST.
 */
class Care extends Collector
{
    public const UPTIME_URL = 'https://api.uptimerobot.com/v2/getMonitors';
    public const REPO = 'rob-kingsbury/kc-care';
    public const GH_ARGS = ['issue', 'list', '-R', self::REPO, '--state', 'open', '--json', 'number,title,createdAt,url,labels'];
    public const MAX_BYTES = 1048576;

    private string $clients;
    private string $keyFile;
    private string $cache;
    /** @var callable */
    private $post;
    private ProcessRunner $runner;
    private string $gh;
    /** @var callable */
    private $ssl;
    private Log $log;

    public function __construct(string $clients, string $keyFile, string $cache, ProcessRunner $runner, string $gh, Log $log,
        ?callable $post = null, ?callable $ssl = null)
    {
        $this->clients = $clients;
        $this->keyFile = $keyFile;
        $this->cache = $cache;
        $this->runner = $runner;
        $this->gh = $gh;
        $this->log = $log;
        $this->post = $post ?? [self::class, 'curlPost'];
        $this->ssl = $ssl ?? [SslExpiry::class, 'fetchValidTo'];
    }

    public function name(): string
    {
        return 'care';
    }

    public function collect(int $now): array
    {
        $list = json_decode((string) @file_get_contents($this->clients, false, null, 0, self::MAX_BYTES), true);
        if (!is_array($list)) {
            throw new RuntimeException('kc-care clients.json is missing or not JSON');
        }
        $old = json_decode((string) @file_get_contents($this->cache), true);
        $since = [];
        foreach ((array) ($old['clients'] ?? []) as $c) {
            if (is_array($c) && isset($c['key'], $c['down_since']) && is_int($c['down_since'])) {
                $since[$c['key']] = $c['down_since'];
            }
        }
        $monitors = $this->part(fn() => $this->monitors());
        $issues = $this->part(fn() => self::gh($this->runner, $this->gh, self::GH_ARGS));
        $clients = [];
        foreach ($list as $key => $c) {
            $domain = is_array($c) && is_string($c['domain'] ?? null) ? strtolower($c['domain']) : null;
            if (!is_string($key) || $domain === null || preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $domain) !== 1) {
                continue;
            }
            $m = $monitors === null ? null : self::monitorFor($monitors, $domain);
            $down = $m !== null && in_array((int) ($m['status'] ?? 0), [8, 9], true);
            $ranges = $m === null ? [] : explode('-', (string) ($m['custom_uptime_ratio'] ?? ''));
            $validTo = $this->part(fn() => ($this->ssl)($domain));
            $alerts = $issues === null ? null : array_values(array_filter($issues, fn($i) => self::mentions($i, $key, $domain)));
            $clients[] = [
                'key' => $key,
                'domain' => $domain,
                'platform' => is_string($c['platform'] ?? null) ? $c['platform'] : null,
                'monitor' => $m === null ? null : ($down ? 'down' : ((int) ($m['status'] ?? 0) === 2 ? 'up' : 'paused')),
                'down_since' => $down ? ($since[$key] ?? $now) : null,
                'response_ms' => $m !== null && is_numeric($m['average_response_time'] ?? null) ? (int) round((float) $m['average_response_time']) : null,
                'uptime_7' => isset($ranges[0]) && is_numeric($ranges[0]) ? (float) $ranges[0] : null,
                'uptime_30' => isset($ranges[1]) && is_numeric($ranges[1]) ? (float) $ranges[1] : null,
                'alerts' => $alerts,
                'ssl_days' => is_int($validTo) ? self::daysUntil(date('Y-m-d', $validTo), $now) : null,
            ];
        }
        $out = ['at' => $now, 'clients' => $clients];
        $tmp = $this->cache . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($tmp, $this->cache) || @unlink($tmp);
        }
        return [];
    }

    /**
     * The only process this collector starts: gh with exactly GH_ARGS, open issues in rob-kingsbury/kc-care.
     * Anything else is refused before the runner sees it. Returns each issue with a 'critical' flag from its labels.
     */
    public static function gh(ProcessRunner $runner, string $exe, array $args): array
    {
        if ($args !== self::GH_ARGS) {
            throw new ProcessRefused('care may only list kc-care issues');
        }
        $r = $runner->run(array_merge([$exe], $args), null, 30);
        if ($r['code'] !== 0 || $r['timedOut']) {
            throw new RuntimeException('gh issue list failed');
        }
        $rows = json_decode($r['stdout'], true);
        if (!is_array($rows)) {
            throw new RuntimeException('gh issue list gave no JSON');
        }
        $out = [];
        foreach ($rows as $i) {
            if (!is_array($i) || !is_int($i['number'] ?? null)) {
                continue;
            }
            $labels = array_map(fn($l) => strtolower((string) (is_array($l) ? ($l['name'] ?? '') : $l)), (array) ($i['labels'] ?? []));
            $out[] = ['number' => $i['number'], 'title' => (string) ($i['title'] ?? ''), 'created' => (string) ($i['createdAt'] ?? ''),
                'url' => (string) ($i['url'] ?? ''), 'critical' => in_array('critical', $labels, true)];
        }
        return $out;
    }

    private function monitors(): array
    {
        $key = trim((string) @file_get_contents($this->keyFile, false, null, 0, 4096));
        if ($key === '') {
            throw new RuntimeException('no UptimeRobot key');
        }
        $this->log->addSecret($key);
        $body = ($this->post)(self::UPTIME_URL, ['api_key' => $key, 'format' => 'json', 'custom_uptime_ratios' => '7-30',
            'response_times' => '1', 'response_times_limit' => '1']);
        $data = json_decode((string) $body, true);
        if (!is_array($data) || ($data['stat'] ?? null) !== 'ok' || !is_array($data['monitors'] ?? null)) {
            throw new RuntimeException('UptimeRobot answered without monitors');
        }
        return $data['monitors'];
    }

    private static function monitorFor(array $monitors, string $domain): ?array
    {
        foreach ($monitors as $m) {
            $host = is_array($m) ? strtolower((string) parse_url((string) ($m['url'] ?? ''), PHP_URL_HOST)) : '';
            if ($host === $domain || $host === 'www.' . $domain) {
                return $m;
            }
        }
        return null;
    }

    private static function mentions(array $issue, string $key, string $domain): bool
    {
        $t = strtolower($issue['title']);
        return str_contains($t, strtolower($key)) || str_contains($t, $domain);
    }

    private function part(Closure $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->log->exception($e, ['where' => 'care']);
            return null;
        }
    }

    public static function curlPost(string $url, array $fields): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200 || strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('UptimeRobot request failed (' . $status . ')');
        }
        return $body;
    }
}
