<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';
require_once __DIR__ . '/../src/Collectors/Calendar.php';
require_once __DIR__ . '/../src/Collectors/Mail.php';

const CY_FIX = __DIR__ . '/fixtures/cockpit-day';
define('CY_TOKEN', str_repeat('7c', 32));
// Sunday 1 November 2026, 05:05 in Toronto, three hours after the clocks went back.
define('CY_NOW', (int) strtotime('2026-11-01T10:05:00Z'));

// A scripted Gmail: answers the five read commands from imap.json, counting literal lengths itself, and records
// every command it is sent (tag stripped). $refuse makes LOGIN fail with a reply that echoes the command back.
function cy_wire(array &$sent, bool $refuse = false, string $fixDir = CY_FIX): ImapWire
{
    $fix = json_decode((string) file_get_contents($fixDir . '/imap.json'), true);
    return new class ($fix, $sent, $refuse) implements ImapWire {
        private string $out;
        public function __construct(private array $fix, private array &$sent, private bool $refuse)
        {
            $this->out = $fix['greeting'] . "\r\n";
        }
        public function send(string $line): void
        {
            [$tag, $cmd] = explode(' ', rtrim($line, "\r\n"), 2);
            $this->sent[] = $cmd;
            $by = array_column($this->fix['messages'], null, 'uid');
            if (str_starts_with($cmd, 'LOGIN ')) {
                $this->out .= $this->refuse ? "$tag NO [AUTHENTICATIONFAILED] Invalid credentials: $cmd\r\n" : "$tag OK fixture authenticated\r\n";
            } elseif (str_starts_with($cmd, 'EXAMINE ')) {
                $this->out .= "* FLAGS (\\Seen)\r\n* 3 EXISTS\r\n$tag OK [READ-ONLY] INBOX opened\r\n";
            } elseif (str_starts_with($cmd, 'UID SEARCH ')) {
                $this->out .= '* SEARCH ' . implode(' ', array_keys($by)) . "\r\n$tag OK done\r\n";
            } elseif (preg_match('/^UID FETCH ([\d,]+) /', $cmd, $m)) {
                foreach (explode(',', $m[1]) as $seq => $uid) {
                    $msg = $by[(int) $uid];
                    $this->out .= '* ' . ($seq + 1) . " FETCH (UID $uid ENVELOPE {$msg['envelope']} BODY[1]<0> {" . strlen($msg['body']) . "}\r\n{$msg['body']})\r\n";
                }
                $this->out .= "$tag OK done\r\n";
            } elseif ($cmd === 'LOGOUT') {
                $this->out .= "* BYE\r\n$tag OK bye\r\n";
            } else {
                $this->out .= "$tag BAD unknown\r\n";
            }
        }
        public function line(): ?string
        {
            $at = strpos($this->out, "\r\n");
            if ($at === false) {
                return null;
            }
            $l = substr($this->out, 0, $at + 2);
            $this->out = substr($this->out, $at + 2);
            return $l;
        }
        public function bytes(int $n): string
        {
            $b = substr($this->out, 0, $n);
            $this->out = substr($this->out, $n);
            return $b;
        }
        public function close(): void
        {
        }
    };
}

function cy_world(): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccy_' . bin2hex(random_bytes(6));
    mkdir($t . '/transcripts', 0777, true);
    $pass = 'fixturepw' . bin2hex(random_bytes(8));
    file_put_contents($t . '/app-password', $pass . "\n");
    file_put_contents($t . '/ical-url', "https://calendar.example.com/private-" . bin2hex(random_bytes(8)) . "/basic.ics\n");
    return [$t, $pass, new Log($t . '/app.log')];
}

function cy_calendar(string $t, Log $log): Calendar
{
    $http = new class implements HttpClient {
        public function get(string $url): array
        {
            return ['status' => 200, 'body' => (string) file_get_contents(CY_FIX . '/calendar.ics')];
        }
    };
    return new Calendar($http, $t . '/ical-url', $t . '/calendar-cache.json', 'America/Toronto', $log);
}

// Every message is tagged unless $tag says otherwise (CockpitMailRelevanceTest covers tagging).
function cy_mail(string $t, Log $log, array &$sent, ?Closure $model = null, bool $refuse = false, ?Closure $tag = null, string $fixDir = CY_FIX): Mail
{
    return new Mail(function () use (&$sent, $refuse, $fixDir): ImapWire {
        return cy_wire($sent, $refuse, $fixDir);
    }, 'rob@example.org', $t . '/app-password', $model ?? fn(array $m): ?array => null, $t . '/mail-cache.json', $log,
        $tag ?? fn(array $m): ?string => 'Acme');
}

// The snapshot's raw body and its day block, through the API with the guard and token.
function cy_snapshot(string $t, Log $log): array
{
    $deps = [
        'index' => fn(): TranscriptIndex => new TranscriptIndex($t . '/cockpit.sqlite', $t . '/transcripts', null, fn(): int => CY_NOW),
        'projects' => fn(): array => [],
        'clock' => fn(): int => CY_NOW,
        'day' => ['calendar' => $t . '/calendar-cache.json', 'mail' => $t . '/mail-cache.json', 'follow' => $t . '/followups.json',
            'senders' => $t . '/mail-senders.json', 'tz' => 'America/Toronto'],
    ];
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => CY_TOKEN];
    [$status, , $body] = CockpitApi::handle($server, fn(): string => CY_TOKEN, $deps, $log);
    assert_same(200, $status, 'snapshot status: ' . substr($body, 0, 200));
    return [$body, json_decode($body, true)['day']];
}

$cyLocal = fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('America/Toronto'))->format('m-d H:i');

test('the snapshot returns the month with recurring events expanded and today\'s agenda', function () use ($cyLocal) {
    [$t, , $log] = cy_world();
    assert_same([], cy_calendar($t, $log)->collect(CY_NOW));
    [, $d] = cy_snapshot($t, $log);
    $c = $d['calendar'];
    assert_same('2026-11', $c['month']);
    $got = array_map(fn($e) => $cyLocal($e['start']) . ' ' . $e['title'], $c['events']);
    assert_same([
        '11-01 00:00 Rent due', '11-01 07:00 Gym', '11-01 14:00 Client call with Acme',
        '11-02 09:00 Standup', '11-03 07:00 Gym', '11-04 09:00 Standup', '11-05 07:00 Gym', '11-07 07:00 Gym',
        '11-09 09:00 Standup', '11-15 08:00 Backup check', '11-16 11:00 Standup', '11-18 09:00 Standup', '11-27 10:00 Invoice run',
    ], $got, implode(' | ', $got));
    assert_same(['Rent due', 'Gym', 'Client call with Acme'], array_column($c['today'], 'title'));
    assert_same([true, 'Room 4, Arnprior'], [$c['today'][0]['all_day'], $c['today'][2]['location']]);
    assert_same(3600, $c['today'][2]['end'] - $c['today'][2]['start']);
    assert_same(['Birthday'], $c['once'], 'a yearly rule is not expanded and is named');
});

test('email rows carry the stubbed model\'s summaries and the reader sends only the five read commands', function () {
    [$t, $pass, $log] = cy_world();
    $sent = [];
    $calls = [];
    $model = function (array $m) use (&$calls): ?array {
        $calls[] = $m;
        return ['summary' => 'About: ' . $m['subject'], 'reply' => $m['uid'] !== 102];
    };
    assert_same([], cy_mail($t, $log, $sent, $model)->collect(CY_NOW));
    assert_same(['LOGIN', 'EXAMINE', 'UID SEARCH', 'UID FETCH', 'LOGOUT'],
        array_map(fn($c) => preg_replace('/^(UID \w+|\w+).*$/s', '$1', $c), $sent));
    assert_true(str_ends_with($sent[3], '(UID ENVELOPE BODY.PEEK[1]<0.2000>)'), $sent[3]);
    assert_same('EXAMINE INBOX', $sent[1]);
    $text = array_column($calls, 'text', 'uid');
    assert_true(str_starts_with($text[101], 'Hi Rob, can you resend'), $text[101]);
    assert_same('Top stories this week Nothing you need to act on.', $text[102]);
    [$body, $d] = cy_snapshot($t, $log);
    $rows = $d['mail']['rows'];
    assert_same(['Bob Fixture', 'news@example.net', 'Ann Client'], array_column($rows, 'from'));
    assert_same(['About: Site is down?', 'About: Weekly digest — November', 'About: Invoice question'], array_column($rows, 'summary'));
    assert_same('https://mail.google.com/mail/u/0/#search/rfc822msgid%3Am101%40example.com', $rows[2]['link']);
    assert_same(['Site is down?', 'Invoice question'], array_column($d['follow']['threads'], 'subject'));
    assert_true(!str_contains($body, 'resend the October'), 'mail text is never in the output');
    assert_true(!str_contains((string) file_get_contents($t . '/mail-cache.json'), 'resend the October'), 'nor in the cache');
    assert_true(!str_contains($body, $pass), 'the app password is not in the API output');
    $sent = [];
    cy_mail($t, $log, $sent, $model)->collect(CY_NOW + Mail::EVERY + 1);
    assert_same(['LOGIN', 'EXAMINE', 'UID SEARCH', 'LOGOUT'], array_map(fn($c) => preg_replace('/^(UID \w+|\w+).*$/s', '$1', $c), $sent),
        'summaries are cached by UID');
    assert_same(3, count($calls));
});

test('with the model down an email row shows sender and subject only', function () {
    [$t, , $log] = cy_world();
    $sent = [];
    cy_mail($t, $log, $sent)->collect(CY_NOW);
    [, $d] = cy_snapshot($t, $log);
    assert_same([null, 'Site is down?', 'Bob Fixture'], [$d['mail']['rows'][0]['summary'], $d['mail']['rows'][0]['subject'], $d['mail']['rows'][0]['from']]);
    assert_same([], $d['follow']['threads']);
});

test('a refused sign-in says so on the panel and the app password never reaches the log or the API', function () {
    [$t, $pass, $log] = cy_world();
    $sent = [];
    $pdo = Db::open($t . '/dashboard.sqlite');
    Db::migrate($pdo);
    $r = Collector::run([cy_calendar($t, $log), cy_mail($t, $log, $sent, null, true)], new FactStore($pdo), $log, CY_NOW);
    assert_same(0, $r['calendar']);
    assert_true(is_string($r['mail']) && !str_contains($r['mail'], $pass), 'run result');
    assert_same(['LOGIN', 'LOGOUT'], array_map(fn($c) => strtok($c, ' '), $sent));
    $logText = (string) file_get_contents($t . '/app.log');
    assert_true(str_contains($logText, 'Invalid credentials') && str_contains($logText, '[redacted]'), $logText);
    assert_true(!str_contains($logText, $pass), 'the app password is in the log');
    assert_true(!str_contains($logText, 'calendar.example.com/private-'), 'nor the calendar address');
    [$body, $d] = cy_snapshot($t, $log);
    assert_same(Mail::REFUSED, $d['mail']['error']);
    assert_same(13, count($d['calendar']['events']), 'the calendar still draws');
    assert_true(!str_contains($body, $pass), 'the app password is in the API output');
});

test('the IMAP command list allows reading and nothing else', function () {
    foreach (['LOGIN "a@b.c" "pw"', 'EXAMINE INBOX', 'UID SEARCH SINCE 18-Oct-2026', 'UID FETCH 1,2 (UID ENVELOPE BODY.PEEK[1]<0.2000>)', 'LOGOUT'] as $ok) {
        assert_true(ImapReader::allowed($ok), $ok);
    }
    foreach (['SELECT INBOX', 'EXAMINE Trash', 'UID STORE 1 +FLAGS (\\Seen)', 'UID FETCH 1 (BODY[1])', 'UID FETCH 1 (UID ENVELOPE BODY[1]<0.2000>)',
        'UID COPY 1 Trash', 'UID MOVE 1 Trash', 'EXPUNGE', 'APPEND INBOX {3}', "LOGOUT\r\nk9 EXPUNGE", 'UID FETCH 1 (UID ENVELOPE BODY.PEEK[1]<0.2000>) X'] as $no) {
        assert_true(!ImapReader::allowed($no), $no);
    }
    foreach (glob(__DIR__ . '/../src/Mail/*.php') as $f) {
        assert_true(!preg_match('/\b(STORE|COPY|MOVE|EXPUNGE|APPEND|SELECT)\b/i', (string) file_get_contents($f), $m), basename($f) . ' contains ' . ($m[1] ?? ''));
    }
});

test('a follow-up write needs the token and Origin, then adds and ticks off', function () {
    [$t] = cy_world();
    $path = $t . '/followups.json';
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://127.0.0.1:8787'];
    $tok = fn(): string => CY_TOKEN;
    [$status] = CockpitDay::write($server, $tok, fn(): string => '{"add":"Call Ann back"}', $path, CY_NOW);
    assert_same(403, $status, 'no token');
    assert_true(!is_file($path), 'nothing written without the token');
    $with = $server + [RequestGuard::TOKEN_HEADER => CY_TOKEN];
    [$status] = CockpitDay::write(['HTTP_ORIGIN' => 'http://evil.example'] + $with, $tok, fn(): string => '{"add":"x"}', $path, CY_NOW);
    assert_same(403, $status, 'wrong Origin');
    [$status, , $body] = CockpitDay::write($with, $tok, fn(): string => '{"add":"Call Ann back"}', $path, CY_NOW);
    assert_same(200, $status, $body);
    $id = json_decode($body, true)['items'][0]['id'];
    [$status, , $body] = CockpitDay::write($with, $tok, fn(): string => json_encode(['done' => $id]), $path, CY_NOW + 60);
    assert_same([200, CY_NOW + 60], [$status, json_decode($body, true)['items'][0]['done']]);
    assert_same(400, CockpitDay::write($with, $tok, fn(): string => '{"add":""}', $path, CY_NOW)[0]);
    assert_same([], CockpitDay::follow(null, $path, CY_NOW + 3 * 86400)['items'], 'a ticked item drops off after two days');
});
