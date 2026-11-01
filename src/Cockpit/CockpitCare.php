<?php
require_once __DIR__ . '/NeverShow.php';

/**
 * The care block of the cockpit snapshot, from the cache the Care collector writes; never the network. Each client
 * gets a status: red when its monitor is down or an open alert is labelled critical, amber for any other open alert
 * or SSL under SSL_AMBER days, else green. Every red client raises one incident with a stable id (client and the
 * time it went down, or the alert number), so the page notifies once per incident. Null when there is no cache.
 */
class CockpitCare
{
    public const SSL_AMBER = 21;

    public static function build(?string $cache, int $now): ?array
    {
        $raw = $cache === null ? null : json_decode((string) @file_get_contents($cache), true);
        if (!is_array($raw) || !is_array($raw['clients'] ?? null)) {
            return null;
        }
        $clients = [];
        $incidents = [];
        foreach ($raw['clients'] as $c) {
            if (!is_array($c) || !is_string($c['key'] ?? null) || !is_string($c['domain'] ?? null)) {
                continue;
            }
            $alerts = [];
            foreach ((array) ($c['alerts'] ?? []) as $a) {
                if (is_array($a) && is_int($a['number'] ?? null)) {
                    $alerts[] = ['number' => $a['number'], 'title' => NeverShow::text((string) ($a['title'] ?? ''), null, 160),
                        'url' => preg_match('~^https://github\.com/~', (string) ($a['url'] ?? '')) ? (string) $a['url'] : '',
                        'critical' => ($a['critical'] ?? false) === true];
                }
            }
            $down = ($c['monitor'] ?? null) === 'down';
            $critical = array_values(array_filter($alerts, fn($a) => $a['critical']));
            $ssl = is_int($c['ssl_days'] ?? null) ? $c['ssl_days'] : null;
            $status = $down || $critical ? 'red' : ($alerts || ($ssl !== null && $ssl < self::SSL_AMBER) ? 'amber' : 'green');
            $since = is_int($c['down_since'] ?? null) ? $c['down_since'] : null;
            $row = [
                'key' => $c['key'],
                'domain' => $c['domain'],
                'status' => $status,
                'monitor' => is_string($c['monitor'] ?? null) ? $c['monitor'] : null,
                'down_since' => $down ? $since : null,
                'response_ms' => is_int($c['response_ms'] ?? null) ? $c['response_ms'] : null,
                'uptime_7' => is_numeric($c['uptime_7'] ?? null) ? (float) $c['uptime_7'] : null,
                'uptime_30' => is_numeric($c['uptime_30'] ?? null) ? (float) $c['uptime_30'] : null,
                'alert_count' => is_array($c['alerts'] ?? null) ? count($alerts) : null,
                'alerts' => $alerts,
                'ssl_days' => $ssl,
            ];
            $clients[] = $row;
            if ($down) {
                $incidents[] = ['id' => 'down:' . $c['key'] . ':' . (int) $since, 'client' => $c['key'], 'domain' => $c['domain'],
                    'kind' => 'down', 'since' => $since, 'text' => $c['domain'] . ' is down'];
            } elseif ($critical) {
                $incidents[] = ['id' => 'alert:' . $c['key'] . ':' . $critical[0]['number'], 'client' => $c['key'], 'domain' => $c['domain'],
                    'kind' => 'alert', 'since' => null, 'text' => $c['domain'] . ': ' . $critical[0]['title']];
            }
        }
        $order = ['red' => 0, 'amber' => 1, 'green' => 2];
        usort($clients, fn($a, $b) => [$order[$a['status']], $a['domain']] <=> [$order[$b['status']], $b['domain']]);
        return ['at' => is_int($raw['at'] ?? null) ? $raw['at'] : null, 'clients' => $clients, 'incidents' => $incidents];
    }
}
