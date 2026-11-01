<?php
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';
require_once __DIR__ . '/../src/Collectors/Weather.php';

const CD_FIX = __DIR__ . '/fixtures/cockpit-daily';
define('CD_TOKEN', str_repeat('5d', 32));
define('CD_NOW', (int) strtotime('2026-11-01T10:05:00Z'));

// A stub fetcher: answers from a list in order, a Throwable in the list is thrown. Counts calls.
function cd_http(array $answers): HttpClient
{
    return new class ($answers) implements HttpClient {
        public array $urls = [];
        public function __construct(private array $answers) {}
        public function get(string $url): array
        {
            $this->urls[] = $url;
            $a = array_shift($this->answers);
            if ($a instanceof Throwable) {
                throw $a;
            }
            return $a;
        }
    };
}

// A temp world: empty transcripts, the usage fixtures with set times, a news database with fixture rows.
function cd_world(int $alphaAge, int $betaAge): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccd_' . bin2hex(random_bytes(6));
    mkdir($t . '/transcripts', 0777, true);
    $dirs = [];
    foreach (['alpha' => $alphaAge, 'beta' => $betaAge] as $p => $age) {
        mkdir("$t/$p/loop", 0777, true);
        foreach (glob(CD_FIX . "/usage/$p/loop/*.jsonl") as $f) {
            copy($f, "$t/$p/loop/" . basename($f));
            touch("$t/$p/loop/" . basename($f), CD_NOW - $age);
        }
        $dirs[] = "$t/$p/loop";
    }
    $pdo = Db::open($t . '/dashboard.sqlite');
    Db::migrate($pdo);
    $ins = $pdo->prepare('INSERT INTO news_items (id, source, topic, title, url, canon, published_at, points, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?)');
    $rows = [
        ['Anthropic', 'ai', 'Newest AI story', 'https://example.com/a1', CD_NOW - 600],
        ['OpenAI', 'ai', 'Second AI story', 'http://example.com/a2', CD_NOW - 1200],
        ['Bad', 'ai', 'Script link story', 'javascript:alert(1)', CD_NOW - 60],
        ['web.dev', 'webdev', 'Not AI', 'https://example.com/w1', CD_NOW - 30],
    ];
    for ($i = 3; $i <= 8; $i++) {
        $rows[] = ['Hugging Face', 'ai', "Older AI story $i", "https://example.com/o$i", CD_NOW - 3600 * $i];
    }
    foreach ($rows as $i => $r) {
        $ins->execute(["id$i", $r[0], $r[1], $r[2], $r[3], $r[4], $r[4], $r[4]]);
    }
    return [$t, $dirs, $pdo];
}

function cd_snapshot(string $t, array $dirs, PDO $pdo): array
{
    $deps = [
        'index' => fn(): TranscriptIndex => new TranscriptIndex($t . '/cockpit.sqlite', $t . '/transcripts', null, fn(): int => CD_NOW),
        'projects' => fn(): array => [],
        'clock' => fn(): int => CD_NOW,
        'daily' => ['weather' => $t . '/weather-cache.json', 'news' => fn(): PDO => $pdo, 'finance' => CD_FIX . '/alerts.json',
            'usage' => fn(): array => $dirs],
    ];
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => CD_TOKEN];
    [$status, , $body] = CockpitApi::handle($server, fn(): string => CD_TOKEN, $deps);
    assert_same(200, $status, 'snapshot status: ' . substr($body, 0, 200));
    return json_decode($body, true)['daily'];
}

function cd_weather(string $t, HttpClient $http): Weather
{
    return new Weather($http, $t . '/weather-cache.json', 45.43, -76.35);
}

test('the snapshot API returns weather, news, finance and usage blocks with the fixture values', function () {
    [$t, $dirs, $pdo] = cd_world(7200, 600);
    $http = cd_http([['status' => 200, 'body' => (string) file_get_contents(CD_FIX . '/open-meteo.json')]]);
    assert_same([], cd_weather($t, $http)->collect(CD_NOW - 120));
    assert_true(str_contains($http->urls[0], 'latitude=45.43&longitude=-76.35'), $http->urls[0]);
    $d = cd_snapshot($t, $dirs, $pdo);
    $w = $d['weather'];
    assert_same([3.4, 'Overcast', 8.6, 1.2, CD_NOW - 120, 120], [$w['temp'], $w['condition'], $w['high'], $w['low'], $w['at'], $w['age']]);
    assert_same(['07:00', 4.0, 'Light rain'], [$w['hours'][0]['time'], (float) $w['hours'][0]['temp'],$w['hours'][0]['condition']]);
    assert_same(5, count($w['hours']), 'the next 5 hours');
    $titles = array_column($d['news'], 'title');
    assert_same(6, count($d['news']));
    assert_same(['Newest AI story', 'Second AI story'], array_slice($titles, 0, 2));
    assert_true(!in_array('Script link story', $titles, true) && !in_array('Not AI', $titles, true), implode(' | ', $titles));
    assert_same(['Anthropic', 'https://example.com/a1', CD_NOW - 600], [$d['news'][0]['source'], $d['news'][0]['url'], $d['news'][0]['ts']]);
    $f = $d['finance'];
    assert_same(245000, $f['receivables_cents']);
    assert_same(null, $f['reserve_cents'], 'a figure the file lacks is null, never invented');
    assert_same(['subject' => 'Gamma Fixture Inc', 'due' => '2026-11-04', 'amount_cents' => 80000], $f['next_due']);
    assert_same(['subject' => 'Acme Fixture Co', 'due' => '2026-09-20', 'amount_cents' => 120000], $f['oldest_overdue']);
    assert_same((int) strtotime('2026-11-01T08:00:00Z'), $f['at']);
    $u = $d['usage'];
    assert_same([57, 1762002000], [$u['five_hour']['percent'], $u['five_hour']['resets']]);
    assert_same([42, 600, false], [$u['seven_day']['percent'], $u['age'], $u['stale']]);
    assert_true(!str_contains(json_encode($d), 'cost'), 'no cost anywhere');
});

test('a failed weather fetch returns the last cached reading with its age, never an error', function () {
    [$t, $dirs, $pdo] = cd_world(7200, 600);
    $ok = ['status' => 200, 'body' => (string) file_get_contents(CD_FIX . '/open-meteo.json')];
    $http = cd_http([$ok, new RuntimeException('timed out'), ['status' => 503, 'body' => 'down']]);
    $w = cd_weather($t, $http);
    $w->collect(CD_NOW - 3600);
    assert_same([], $w->collect(CD_NOW - 3000), 'inside 15 minutes the cache is used');
    assert_same(1, count($http->urls), 'no fetch inside 15 minutes');
    foreach ([CD_NOW - 1800, CD_NOW - 600] as $when) {
        try {
            $w->collect($when);
            assert_true(false, 'a failed fetch must throw for the collector run');
        } catch (RuntimeException $e) {
            assert_true($e->getMessage() !== '', 'failure reason');
        }
    }
    $d = cd_snapshot($t, $dirs, $pdo);
    assert_same([3.4, CD_NOW - 3600, 3600], [$d['weather']['temp'], $d['weather']['at'], $d['weather']['age']]);
    assert_same(CD_NOW - 3600, $w->lastOkAt(CD_NOW), 'last good is the cached reading');
});

test('usage picks the newest reading across two fixture files and mutes it past 30 minutes', function () {
    [$t, $dirs, $pdo] = cd_world(2400, 7200);
    $u = cd_snapshot($t, $dirs, $pdo)['usage'];
    assert_same([22, 31], [$u['five_hour']['percent'], $u['seven_day']['percent']], 'the newer file, its last event');
    assert_same([2400, true], [$u['age'], $u['stale']]);
});

test('a missing source is null in the daily block and the snapshot still answers', function () {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccd_' . bin2hex(random_bytes(6));
    mkdir($t . '/transcripts', 0777, true);
    $pdo = new PDO('sqlite::memory:');
    assert_same(['weather' => null, 'news' => null, 'finance' => null, 'usage' => null],
        CockpitDaily::build(['weather' => $t . '/none.json', 'news' => fn(): PDO => $pdo, 'finance' => $t . '/none.json',
            'usage' => fn(): array => [$t . '/nowhere']], CD_NOW));
    assert_same(null, cd_snapshot($t, [$t . '/nowhere'], $pdo)['usage']);
});
