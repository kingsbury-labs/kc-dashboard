<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Clients/DashboardParser.php';
require_once __DIR__ . '/../Clients/PipelineParser.php';

// Due dates from _DASHBOARD.md (active projects) and _PIPELINE.md (a date in a lead's next action), and the
// outreach follow-ups the dashboard marks overdue. Past due is high.
class ClientDates extends Collector
{
    public const WINDOW_DAYS = 30;

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public function name(): string
    {
        return 'clients';
    }

    public function collect(int $now): array
    {
        $dash = $this->read('_DASHBOARD.md');
        $pipe = $this->read('_PIPELINE.md');
        $facts = [];
        foreach (DashboardParser::parse($dash)['clients'] as $c) {
            if ($c['due'] !== null) {
                $facts[] = $this->dated('project', $c['slug'] !== '' ? $c['slug'] : $c['client'], $c['client'], $c['milestone'], $c['due'], $now);
            }
        }
        foreach (PipelineParser::parse($pipe) as $l) {
            if (preg_match('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $l['next_action'], $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $name = $l['business'] !== '' ? $l['business'] : $l['name'];
                $facts[] = $this->dated('lead', $name, $name, $l['next_action'], $m[0], $now);
            }
        }
        foreach (DashboardParser::table($dash, 'Pipeline') as $row) {
            $who = $row['prospect'] ?? '';
            if ($who !== '' && stripos($row['status'] ?? '', 'overdue') !== false) {
                $facts[] = [
                    'id' => 'clients:followup:' . self::key($who),
                    'source' => 'clients',
                    'kind' => 'follow_up',
                    'subject' => "Follow up with $who",
                    'salient_hash' => sha1('followup|' . $who . '|high'),
                    'due_date' => null,
                    'severity' => 'high',
                    'evidence' => mb_substr($row['status'], 0, 200),
                    'url' => null,
                    'parser_version' => 'clients/1',
                ];
            }
        }
        return array_values(array_filter($facts));
    }

    private function dated(string $type, string $key, string $who, string $what, string $due, int $now): ?array
    {
        $days = self::daysUntil($due, $now);
        if ($days > self::WINDOW_DAYS) {
            return null;
        }
        $severity = $days < 0 ? 'high' : ($days <= 7 ? 'med' : 'low');
        return [
            'id' => "clients:$type:" . self::key($key),
            'source' => 'clients',
            'kind' => $days < 0 ? 'overdue' : 'due_date',
            'subject' => $days < 0 ? "$who is overdue" : "$who is due",
            'salient_hash' => sha1(implode('|', [$type, $key, $due, $severity])),
            'due_date' => $due,
            'severity' => $severity,
            'evidence' => mb_substr($what, 0, 200),
            'url' => null,
            'parser_version' => 'clients/1',
        ];
    }

    // A plain slug is its own key; anything else (free text from a table cell) is hashed, so an id is always short
    // and clean.
    private static function key(string $text): string
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $text) === 1 ? $text : substr(sha1($text), 0, 16);
    }

    private function read(string $file): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $file;
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException("cannot read $file");
        }
        return $text;
    }
}
