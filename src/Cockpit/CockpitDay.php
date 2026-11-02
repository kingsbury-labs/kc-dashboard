<?php
require_once __DIR__ . '/NeverShow.php';
require_once __DIR__ . '/../Collectors/Calendar.php';
require_once __DIR__ . '/../Collectors/Mail.php';
require_once __DIR__ . '/../Mail/Relevance.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';

/**
 * The day block of the cockpit snapshot: agenda, email summaries and follow-ups. Calendar and mail are the caches
 * bin/collect-facts.php writes, so nothing here touches the network; a part that fails is null so the rest draws.
 * $deps: 'calendar' => cache path, 'mail' => cache path, 'follow' => the local follow-up list, 'tz' => display zone.
 * write() is the one change: add an item to the follow-up list or tick one off, behind checkWrite.
 */
class CockpitDay
{
    public const MAIL_ROWS = 10;
    public const FOLLOW_ITEMS = 100;
    public const FOLLOW_TEXT = 200;
    public const FOLLOW_SHOWN_DONE = 172800;

    public static function build(array $deps, int $now, ?Log $log = null): array
    {
        $tz = (string) ($deps['tz'] ?? 'America/Toronto');
        $mail = self::part(fn() => isset($deps['mail']) ? self::mail((string) $deps['mail'], $now, isset($deps['senders']) ? (string) $deps['senders'] : null) : null, $log);
        return [
            'calendar' => self::part(fn() => isset($deps['calendar']) ? self::calendar((string) $deps['calendar'], $now, $tz) : null, $log),
            'mail' => $mail,
            'follow' => self::part(fn() => self::follow($mail, isset($deps['follow']) ? (string) $deps['follow'] : null, $now), $log),
        ];
    }

    // This month's events and today's, from the calendar cache, in the display zone.
    public static function calendar(string $path, int $now, string $tz): ?array
    {
        $c = Calendar::cached($path, $now);
        if ($c === null) {
            return null;
        }
        $day = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($tz))->setTime(0, 0);
        $month = $day->modify('first day of this month');
        $in = fn(int $a, int $b) => array_values(array_filter($c['events'], fn($e) => is_array($e)
            && (int) $e['start'] < $b && max((int) $e['end'], (int) $e['start'] + 1) > $a));
        $clean = fn(array $list) => array_map(fn($e) => ['start' => (int) $e['start'], 'end' => (int) $e['end'], 'all_day' => (bool) $e['all_day'],
            'title' => NeverShow::text((string) $e['title'], null, 160), 'location' => NeverShow::text((string) $e['location'], null, 160),
            'repeats' => (bool) ($e['repeats'] ?? false)], $list);
        return [
            'at' => $c['at'],
            'age' => $c['age'],
            'month' => $month->format('Y-m'),
            'events' => $clean($in($month->getTimestamp(), $month->modify('+1 month')->getTimestamp())),
            'today' => self::agenda($clean($in($day->getTimestamp(), $day->modify('+1 day')->getTimestamp())), $now),
            'once' => array_values(array_map('strval', (array) $c['once'])),
        ];
    }

    // Today's agenda (card 120): what is on or still to come first, soonest first; finished events below, latest first.
    public static function agenda(array $events, int $now): array
    {
        usort($events, function ($a, $b) use ($now) {
            $pa = max($a['end'], $a['start'] + 1) <= $now;
            $pb = max($b['end'], $b['start'] + 1) <= $now;
            return $pa !== $pb ? $pa <=> $pb : ($pa ? $b['start'] <=> $a['start'] : $a['start'] <=> $b['start']);
        });
        return $events;
    }

    // Newest first, undated last (card 120).
    public static function newestFirst(array $rows, string $key): array
    {
        usort($rows, fn($a, $b) => ($b[$key] ?? PHP_INT_MIN) <=> ($a[$key] ?? PHP_INT_MIN));
        return $rows;
    }

    // Only mail tagged with a project, after Rob's corrections in $senders (Relevance).
    public static function mail(string $path, int $now, ?string $senders = null): ?array
    {
        $m = Mail::cached($path, $now);
        if ($m === null) {
            return null;
        }
        $rows = [];
        foreach (array_slice(Relevance::apply($m['rows'], $senders), 0, self::MAIL_ROWS) as $r) {
            $link = (string) ($r['link'] ?? '');
            $rows[] = ['from' => NeverShow::text((string) ($r['from'] ?? ''), null, 80), 'subject' => NeverShow::text((string) ($r['subject'] ?? ''), null, 200),
                'sender' => (string) ($r['addr'] ?? ''), 'project' => NeverShow::text((string) $r['project'], null, Relevance::NAME_MAX),
                'reason' => NeverShow::text((string) ($r['reason'] ?? ''), null, 200),
                'rule' => is_int($r['rule'] ?? null) ? $r['rule'] : null, 'kind' => ($r['kind'] ?? null) === 'about' ? 'about' : 'from',
                'date' => is_int($r['date'] ?? null) ? $r['date'] : null,
                'link' => str_starts_with($link, 'https://mail.google.com/') ? $link : null,
                'summary' => is_string($r['summary'] ?? null) ? NeverShow::text($r['summary'], null, 200) : null,
                'reply' => ($r['reply'] ?? false) === true];
        }
        // The Unsorted pile (card 131, folds card 117's Unsure): every row no project claims, until Rob tags its sender.
        $unsure = [];
        foreach (Relevance::unsorted($m['rows'], $senders) as $r) {
            $link = (string) ($r['link'] ?? '');
            $unsure[] = ['from' => NeverShow::text((string) ($r['from'] ?? ''), null, 80), 'subject' => NeverShow::text((string) ($r['subject'] ?? ''), null, 200),
                'sender' => (string) ($r['addr'] ?? ''), 'reason' => NeverShow::text((string) ($r['reason'] ?? ''), null, 200),
                'date' => is_int($r['date'] ?? null) ? $r['date'] : null,
                'link' => str_starts_with($link, 'https://mail.google.com/') ? $link : null];
        }
        return ['at' => $m['at'], 'age' => $m['age'], 'error' => $m['error'], 'rows' => self::newestFirst($rows, 'date'),
            'unsure' => array_slice(self::newestFirst($unsure, 'date'), 0, self::MAIL_ROWS)];
    }

    // Threads that need a reply, and the local list: open items, and ones ticked off in the last two days.
    public static function follow(?array $mail, ?string $path, int $now): array
    {
        $threads = array_values(array_map(fn($r) => ['from' => $r['from'], 'subject' => $r['subject'], 'date' => $r['date'], 'link' => $r['link']],
            array_filter($mail['rows'] ?? [], fn($r) => $r['reply'])));
        $items = array_values(array_filter($path === null ? [] : self::items($path),
            fn($x) => $x['done'] === null || $now - $x['done'] < self::FOLLOW_SHOWN_DONE));
        return self::followOrder($threads, $items);
    }

    // Follow-ups (card 120): no item carries a due date, so threads come newest first, undated last, and open items
    // lead the ticked ones, newest first within each.
    public static function followOrder(array $threads, array $items): array
    {
        $threads = self::newestFirst($threads, 'date');
        usort($items, fn($a, $b) => [$a['done'] !== null, $b['done'] ?? 0, $b['at']] <=> [$b['done'] !== null, $a['done'] ?? 0, $a['at']]);
        return ['threads' => $threads, 'items' => $items];
    }

    /** @return array<int,array{id:string,text:string,at:int,done:?int}> */
    public static function items(string $path): array
    {
        $d = json_decode((string) @file_get_contents($path, false, null, 0, 262144), true);
        $out = [];
        foreach (is_array($d) && array_is_list($d) ? $d : [] as $x) {
            if (is_array($x) && is_string($x['id'] ?? null) && is_string($x['text'] ?? null) && is_int($x['at'] ?? null)) {
                $out[] = ['id' => $x['id'], 'text' => $x['text'], 'at' => $x['at'], 'done' => is_int($x['done'] ?? null) ? $x['done'] : null];
            }
        }
        return $out;
    }

    /**
     * POST: {"add": "text"} or {"done": "id"}, or a mail correction {"sender": address, "project": name or null}
     * into $senders. checkWrite (address, Host, POST, token, Origin) runs before the body is read. Answers the list
     * as follow() shows it, or the stored correction.
     */
    public static function write(array $server, Closure $token, Closure $body, string $path, int $now, ?Log $log = null, ?string $senders = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || !(new RequestGuard($token()))->checkWrite($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $in = json_decode((string) $body(), true);
            if ($senders !== null && is_array($in) && array_key_exists('sender', $in)) {
                $p = $in['project'] ?? null;
                if (!is_string($in['sender']) || ($p !== null && !is_string($p))) {
                    return Response::json(400, ['error' => 'send sender and project']);
                }
                try {
                    return Response::json(200, Relevance::correct($senders, $in['sender'], $p));
                } catch (InvalidArgumentException $e) {
                    return Response::json(400, ['error' => $e->getMessage()]);
                }
            }
            $lock = fopen($path . '.lock', 'c');
            flock($lock, LOCK_EX);
            try {
                $items = self::items($path);
                if (is_string($in['add'] ?? null)) {
                    $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $in['add']));
                    if ($text === '' || mb_strlen($text) > self::FOLLOW_TEXT) {
                        return Response::json(400, ['error' => 'text must be 1 to ' . self::FOLLOW_TEXT . ' characters']);
                    }
                    $items[] = ['id' => bin2hex(random_bytes(6)), 'text' => $text, 'at' => $now, 'done' => null];
                    $items = array_slice($items, -self::FOLLOW_ITEMS);
                } elseif (is_string($in['done'] ?? null)) {
                    $found = false;
                    foreach ($items as &$x) {
                        if (hash_equals($x['id'], $in['done'])) {
                            $x['done'] = $now;
                            $found = true;
                        }
                    }
                    unset($x);
                    if (!$found) {
                        return Response::json(404, ['error' => 'no such item']);
                    }
                } else {
                    return Response::json(400, ['error' => 'send add or done']);
                }
                $tmp = $path . '.' . getmypid() . '.tmp';
                if (file_put_contents($tmp, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $path)) {
                    throw new RuntimeException('follow-up list could not be written');
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return Response::json(200, ['items' => self::follow(null, $path, $now)['items']]);
        } catch (Throwable $e) {
            $log?->exception($e, ['where' => 'cockpit follow-up']);
            return Response::error($e);
        }
    }

    private static function part(Closure $fn, ?Log $log)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $log?->exception($e, ['where' => 'cockpit day']);
            return null;
        }
    }
}
