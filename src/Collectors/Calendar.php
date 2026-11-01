<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Net/HttpClient.php';

/**
 * Rob's Google Calendar from its secret iCal address, parsed here (no library) and saved, expanded from the start of
 * this month to the end of the next, to a cache file the cockpit reads. Records no facts; fetches at most every 15
 * minutes. The address is read from its file at run time and registered with the log for redaction.
 * Parsed: VEVENT DTSTART/DTEND (UTC, TZID, floating, all-day), SUMMARY, LOCATION, UID, RECURRENCE-ID, EXDATE, and
 * RRULE with FREQ DAILY/WEEKLY/MONTHLY, INTERVAL, COUNT, UNTIL, BYDAY, WKST. Any other rule is shown once, at its
 * DTSTART, and its title listed under 'once'.
 */
class Calendar extends Collector
{
    public const EVERY = 900;
    public const MAX_BYTES = 8388608;
    public const MAX_STEPS = 3000;
    private const RULE_KEYS = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'WKST'];
    private const DAYS = ['MO' => 0, 'TU' => 1, 'WE' => 2, 'TH' => 3, 'FR' => 4, 'SA' => 5, 'SU' => 6];

    private HttpClient $http;
    private string $urlFile;
    private string $cache;
    private string $tz;
    private Log $log;

    public function __construct(HttpClient $http, string $urlFile, string $cache, string $tz, Log $log)
    {
        $this->http = $http;
        $this->urlFile = $urlFile;
        $this->cache = $cache;
        $this->tz = $tz;
        $this->log = $log;
    }

    public function name(): string
    {
        return 'calendar';
    }

    public function lastOkAt(int $now): int
    {
        return self::cached($this->cache, $now)['at'] ?? $now;
    }

    public function collect(int $now): array
    {
        $old = self::cached($this->cache, $now);
        if ($old !== null && $old['age'] < self::EVERY) {
            return [];
        }
        $url = trim((string) @file_get_contents($this->urlFile));
        if (!preg_match('~^https://\S+$~', $url)) {
            throw new RuntimeException('calendar address file is missing or not https');
        }
        $this->log->addSecret($url);
        $res = $this->http->get($url);
        if ($res['status'] !== 200 || strlen($res['body']) > self::MAX_BYTES) {
            throw new RuntimeException('calendar answered ' . $res['status']);
        }
        $zone = new DateTimeZone($this->tz);
        $from = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->modify('first day of this month')->setTime(0, 0);
        $out = self::expand(self::parse((string) $res['body'], $this->tz), $from->getTimestamp(), $from->modify('+2 months')->getTimestamp());
        $tmp = $this->cache . '.tmp';
        if (file_put_contents($tmp, json_encode($out + ['at' => $now], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false
            || !rename($tmp, $this->cache)) {
            throw new RuntimeException('calendar cache could not be written');
        }
        return [];
    }

    // The saved events with their age in seconds, or null when there are none.
    public static function cached(string $path, int $now): ?array
    {
        $data = is_file($path) ? json_decode((string) @file_get_contents($path, false, null, 0, self::MAX_BYTES), true) : null;
        if (!is_array($data) || !is_int($data['at'] ?? null) || !is_array($data['events'] ?? null)) {
            return null;
        }
        return $data + ['age' => max(0, $now - $data['at']), 'once' => []];
    }

    /** VEVENTs: start and end (DateTimeImmutable), all_day, title, location, uid, recurrence_id (unix), rrule, exdates. */
    public static function parse(string $ics, string $tz): array
    {
        $local = new DateTimeZone($tz);
        $lines = explode("\n", (string) preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)));
        $events = [];
        $e = null;
        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r");
            if ($line === 'BEGIN:VEVENT') {
                $e = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if (isset($e['DTSTART'])) {
                    $events[] = self::event($e, $local);
                }
                $e = null;
                continue;
            }
            if ($e === null || !preg_match('/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^:;"]*))*):(.*)$/s', $line, $m)) {
                continue;
            }
            $params = [];
            preg_match_all('/;([A-Za-z0-9-]+)=("[^"]*"|[^:;"]*)/', $m[2], $ps, PREG_SET_ORDER);
            foreach ($ps as $p) {
                $params[strtoupper($p[1])] = trim($p[2], '"');
            }
            $name = strtoupper($m[1]);
            if ($name === 'EXDATE') {
                $e['EXDATE'][] = [$m[3], $params];
            } elseif (!isset($e[$name])) {
                $e[$name] = [$m[3], $params];
            }
        }
        return array_values(array_filter($events));
    }

    private static function event(array $e, DateTimeZone $local): ?array
    {
        [$start, $allDay] = self::when($e['DTSTART'][0], $e['DTSTART'][1], $local);
        if ($start === null) {
            return null;
        }
        $end = isset($e['DTEND']) ? self::when($e['DTEND'][0], $e['DTEND'][1], $local)[0] : null;
        $end = $end !== null && $end >= $start ? $end : ($allDay ? $start->modify('+1 day') : $start);
        $rule = null;
        if (isset($e['RRULE'])) {
            $rule = [];
            foreach (explode(';', $e['RRULE'][0]) as $part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
                $rule[strtoupper($k)] = strtoupper($v);
            }
        }
        $ex = [];
        foreach ($e['EXDATE'] ?? [] as [$v, $p]) {
            foreach (explode(',', $v) as $one) {
                $d = self::when($one, $p, $local)[0];
                if ($d !== null) {
                    $ex[] = $d->getTimestamp();
                }
            }
        }
        $rid = isset($e['RECURRENCE-ID']) ? self::when($e['RECURRENCE-ID'][0], $e['RECURRENCE-ID'][1], $local)[0] : null;
        return ['start' => $start, 'end' => $end, 'all_day' => $allDay, 'title' => self::text($e['SUMMARY'][0] ?? '(no title)'),
            'location' => self::text($e['LOCATION'][0] ?? ''), 'uid' => (string) ($e['UID'][0] ?? ''),
            'recurrence_id' => $rid?->getTimestamp(), 'rrule' => $rule, 'exdates' => $ex];
    }

    // [DateTimeImmutable in its own zone (rules repeat in that wall time) or null, all-day]. A TZID PHP does not
    // know falls back to the local zone.
    private static function when(string $v, array $params, DateTimeZone $local): array
    {
        $v = trim($v);
        if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $v)) {
            $d = DateTimeImmutable::createFromFormat('!Ymd', substr($v, 0, 8), $local);
            return [$d ?: null, true];
        }
        $zone = $local;
        if (str_ends_with($v, 'Z')) {
            $zone = new DateTimeZone('UTC');
        } elseif (isset($params['TZID'])) {
            try {
                $zone = new DateTimeZone($params['TZID']);
            } catch (Exception $e) {
            }
        }
        $d = DateTimeImmutable::createFromFormat('!Ymd\THis', rtrim($v, 'Z'), $zone);
        return [$d ?: null, false];
    }

    private static function text(string $s): string
    {
        return strtr($s, ['\\n' => ' ', '\\N' => ' ', '\\,' => ',', '\\;' => ';', '\\\\' => '\\']);
    }

    /**
     * Every occurrence overlapping [$from, $to): events (start, end, all_day, title, location, repeats) by start,
     * and once: titles of rules not expanded. A moved occurrence (RECURRENCE-ID) replaces its original.
     */
    public static function expand(array $events, int $from, int $to): array
    {
        $moved = [];
        foreach ($events as $e) {
            if ($e['recurrence_id'] !== null) {
                $moved[$e['uid']][] = $e['recurrence_id'];
            }
        }
        $out = [];
        $once = [];
        foreach ($events as $e) {
            $len = $e['end']->getTimestamp() - $e['start']->getTimestamp();
            $starts = [$e['start']];
            if ($e['rrule'] !== null && $e['recurrence_id'] === null) {
                $s = self::starts($e, $to);
                if ($s === null) {
                    $once[] = $e['title'];
                } else {
                    $starts = $s;
                }
            }
            $skip = $e['recurrence_id'] === null ? array_merge($e['exdates'], $moved[$e['uid']] ?? []) : [];
            foreach ($starts as $s) {
                $ts = $s->getTimestamp();
                if (in_array($ts, $skip, true) || $ts >= $to || $ts + max(1, $len) <= $from) {
                    continue;
                }
                $out[] = ['start' => $ts, 'end' => $ts + $len, 'all_day' => $e['all_day'], 'title' => $e['title'],
                    'location' => $e['location'], 'repeats' => $e['rrule'] !== null || $e['recurrence_id'] !== null];
            }
        }
        usort($out, fn($a, $b) => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
        return ['events' => $out, 'once' => array_values(array_unique($once))];
    }

    // The starts a rule gives up to $to, in the event's own wall time, or null for a rule this does not expand.
    private static function starts(array $e, int $to): ?array
    {
        $r = $e['rrule'];
        $freq = $r['FREQ'] ?? '';
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true) || array_diff(array_keys($r), self::RULE_KEYS)) {
            return null;
        }
        $start = $e['start'];
        $step = max(1, (int) ($r['INTERVAL'] ?? 1));
        $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
        $until = isset($r['UNTIL']) ? self::when($r['UNTIL'], [], $start->getTimezone())[0] : null;
        $byday = [];
        foreach (isset($r['BYDAY']) ? explode(',', $r['BYDAY']) : [] as $d) {
            if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m)) {
                return null;
            }
            $byday[] = [(int) ($m[1] ?? 0), self::DAYS[$m[2]]];
        }
        [$h, $i, $s] = array_map('intval', explode(':', $start->format('H:i:s')));
        $out = [];
        $n = 0;
        for ($k = 0; $k < self::MAX_STEPS; $k++) {
            $c = [];
            if ($freq === 'DAILY') {
                $c[] = $start->modify('+' . ($k * $step) . ' days');
            } elseif ($freq === 'WEEKLY') {
                $week = $start->modify('-' . ((int) $start->format('N') - 1) . ' days')->modify('+' . ($k * $step) . ' weeks');
                foreach ($byday ?: [[0, (int) $start->format('N') - 1]] as [, $wd]) {
                    $c[] = $week->modify('+' . $wd . ' days')->setTime($h, $i, $s);
                }
            } else {
                $month = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->modify('+' . ($k * $step) . ' months');
                [$y, $mo, $days] = [(int) $month->format('Y'), (int) $month->format('n'), (int) $month->format('t')];
                if (!$byday) {
                    if ((int) $start->format('j') <= $days) {
                        $c[] = $month->setDate($y, $mo, (int) $start->format('j'));
                    }
                }
                foreach ($byday as [$ord, $wd]) {
                    $all = [];
                    for ($d = 1; $d <= $days; $d++) {
                        $day = $month->setDate($y, $mo, $d);
                        if ((int) $day->format('N') - 1 === $wd) {
                            $all[] = $day;
                        }
                    }
                    $pick = $ord === 0 ? $all : [$all[$ord > 0 ? $ord - 1 : count($all) + $ord] ?? null];
                    foreach (array_filter($pick) as $day) {
                        $c[] = $day;
                    }
                }
            }
            usort($c, fn($a, $b) => $a <=> $b);
            foreach ($c as $d) {
                if ($d < $start) {
                    continue;
                }
                if (($until !== null && $d > $until) || ($count !== null && $n >= $count) || $d->getTimestamp() >= $to) {
                    return $out;
                }
                $n++;
                $out[] = $d;
            }
        }
        return $out;
    }
}
