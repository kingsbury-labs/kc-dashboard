<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/FactStore.php';

// The display budget: the top five by severity then due date, every other visible fact in the "also seen" fold.
// Ranking never drops a fact; a held one always goes to the fold.
class Selector
{
    public const TOP = 5;

    /** @return array{shown: array, also: array} */
    public static function select(array $facts, int $top = self::TOP): array
    {
        usort($facts, function (array $a, array $b): int {
            $sa = FactStore::SEVERITIES[$a['severity'] ?? ''] ?? 0;
            $sb = FactStore::SEVERITIES[$b['severity'] ?? ''] ?? 0;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            $da = $a['due_date'] ?? null;
            $db = $b['due_date'] ?? null;
            if ($da !== $db) {
                return $da === null ? 1 : ($db === null ? -1 : strcmp($da, $db));
            }
            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        $shown = [];
        $also = [];
        foreach ($facts as $f) {
            if (($f['state'] ?? null) !== 'held' && count($shown) < $top) {
                $shown[] = $f;
            } else {
                $also[] = $f;
            }
        }
        return ['shown' => $shown, 'also' => $also];
    }
}

// GET /api/attention.php and POST /api/feedback.php. Each guard runs before the token is read, and both before the
// database opens.
class AttentionApi
{
    private const ITEM_COLS = ['id', 'source', 'kind', 'subject', 'due_date', 'severity', 'amount_cents', 'evidence', 'state'];
    private const SNOOZE_DAYS = [1, 7, 30];

    /**
     * {"items": [...], "also": [...], "sources": [...]}. $ports are the http://127.0.0.1 ports a fact's url may
     * point at; any other url comes back as null.
     */
    public static function get(array $server, string $tokenPath, Closure $pdo, Log $log, array $ports, ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, self::data(new FactStore($pdo()), $ports, $now ?? time()));
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'attention']);
            return Response::error($e);
        }
    }

    // The list as the page and the API both show it: no salient hash, no parser internals, only safe urls.
    public static function data(FactStore $store, array $ports, int $now): array
    {
        $picked = Selector::select($store->visible($now));
        $item = function (array $f) use ($ports): array {
            $out = [];
            foreach (self::ITEM_COLS as $c) {
                $out[$c] = $f[$c] ?? null;
            }
            $out['amount_cents'] = $out['amount_cents'] === null ? null : (int) $out['amount_cents'];
            $out['url'] = self::safeUrl($f['url'] ?? null, $ports, (string) ($f['source'] ?? ''));
            return $out;
        };
        return ['items' => array_map($item, $picked['shown']), 'also' => array_map($item, $picked['also']),
            'sources' => $store->sources($now)];
    }

    public const FINANCE_PREFIX = 'http://127.0.0.1:8000/';

    // config/running.php's link_ports, integers only. No key means no local port links at all.
    public static function linkPorts(?array $config = null): array
    {
        $config = $config ?? require __DIR__ . '/../../config/running.php';
        return array_values(array_filter((array) ($config['link_ports'] ?? []), fn($p) => is_int($p) && $p > 0 && $p < 65536));
    }

    /**
     * A finance fact links only under FINANCE_PREFIX (the finance contract). Any other source: https:// to a host
     * that is not this machine, or http://127.0.0.1:<port> for a listed port. Anything else is not a link.
     */
    public static function safeUrl($url, array $ports, ?string $source = null): ?string
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f<>"\'\\\\`]/', $url) === 1) {
            return null;
        }
        if ($source === 'finance') {
            return str_starts_with($url, self::FINANCE_PREFIX) ? $url : null;
        }
        if (str_starts_with($url, 'https://')) {
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) && $host !== '' && !self::loopback($host) ? $url : null;
        }
        if (preg_match('#^http://127\.0\.0\.1:(\d{1,5})(?:/|$)#', $url, $m) === 1 && in_array((int) $m[1], $ports, true)) {
            return $url;
        }
        return null;
    }

    // localhost and its subdomains, any IPv6 literal, a dotted address whose first number is 0 or 127, and any
    // number-only host (2130706433, 0x7f.1) that a browser would read as an address.
    private static function loopback(string $host): bool
    {
        $h = rtrim(strtolower($host), '.');
        if ($h === 'localhost' || str_ends_with($h, '.localhost') || str_starts_with($h, '[')) {
            return true;
        }
        if (preg_match('/^(\d{1,3})\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $h, $m) === 1) {
            return (int) $m[1] === 127 || (int) $m[1] === 0;
        }
        return preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/', $h) === 1;
    }

    /**
     * {"id": "...", "reason": "..."} saves one label; {"id": "...", "snooze_days": 1|7|30} snoozes. 400 for a body
     * that is neither, 404 for an unknown fact.
     */
    public static function feedback(array $server, string $body, string $tokenPath, Closure $pdo, Log $log, ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkWrite($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $now = $now ?? time();
            $in = json_decode($body, true);
            $id = is_array($in) && is_string($in['id'] ?? null) ? $in['id'] : '';
            if ($id === '' || strlen($id) > 512) {
                return Response::json(400, ['error' => 'bad request']);
            }
            if (array_key_exists('snooze_days', $in)) {
                if (!in_array($in['snooze_days'], self::SNOOZE_DAYS, true)) {
                    return Response::json(400, ['error' => 'bad snooze']);
                }
                $store = new FactStore($pdo());
                if ($store->get($id) === null) {
                    return Response::json(404, ['error' => 'unknown fact']);
                }
                $store->snooze($id, $now + $in['snooze_days'] * 86400);
                return Response::json(200, ['ok' => true]);
            }
            if (!in_array($in['reason'] ?? null, FactStore::REASONS, true)) {
                return Response::json(400, ['error' => 'bad reason']);
            }
            if (!(new FactStore($pdo()))->feedback($id, $in['reason'], $now)) {
                return Response::json(404, ['error' => 'unknown fact']);
            }
            return Response::json(200, ['ok' => true]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'feedback']);
            return Response::error($e);
        }
    }
}
