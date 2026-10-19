<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Numbers/GaCollector.php';
require_once __DIR__ . '/../src/Numbers/StatusCollector.php';
require_once __DIR__ . '/../src/Numbers/BugsinkCollector.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';
require_once __DIR__ . '/Fakes/FakeHttpClient.php';

define('NUM_NOW', strtotime('2026-10-19 12:00:00 UTC'));
const NUM_STATUS_URL = 'https://status.example.test/api/status';
const NUM_BUGSINK = 'https://bugsink.example.test';
// Invented, as every value in these tests is; it stands in for what the token file would hold.
const NUM_FAKE_TOKEN = 'fake-bugsink-token-0123456789abcdef';

function num_config(): array
{
    return [
        'service_account_path' => 'D:\\Dev\\htdocs\\.credentials\\sa.json',
        'properties' => [
            'kingsbury-creative' => ['ga4' => '100000001', 'search_console' => 'sc-domain:kc.example.test'],
            'example-client' => ['ga4' => '100000002', 'search_console' => 'https://client.example.test/'],
        ],
    ];
}

function num_ga(?string $fixture = null, ?ProcessRunner $runner = null, ?array $config = null): GaCollector
{
    $exes = require __DIR__ . '/../config/executables.php';
    return new GaCollector($runner ?? ProcessRunner::fromConfig(), $exes['python'], realpath(__DIR__ . '/../bin/collect_ga.py'),
        $config ?? num_config(), $fixture !== null ? realpath($fixture) : null);
}

function num_tmp(string $what): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnum_' . $what . '_' . bin2hex(random_bytes(4));
}

function num_db(?string $path = null): PDO
{
    $pdo = Db::open($path ?? ':memory:');
    Db::migrate($pdo);
    return $pdo;
}

function num_by_name(PDO $pdo): array
{
    $out = [];
    foreach ((new NumberStore($pdo))->latest() as $s) {
        $out[$s['name']] = $s;
    }
    return $out;
}

// The GA fixture with one property's GA4 totals call answered 403.
function num_403_fixture(): string
{
    $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/numbers/ga_raw.json'), true);
    $raw['properties'][1]['ga4']['totals'] = ['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions']];
    $tmp = num_tmp('ga403') . '.json';
    file_put_contents($tmp, json_encode($raw));
    return $tmp;
}

function num_bugsink(Closure $fetch, ?string $tokenFile = null, array $projects = ['sembr' => 1, 'henike' => 2]): BugsinkCollector
{
    return new BugsinkCollector(['base_url' => NUM_BUGSINK, 'token_path' => $tokenFile ?? 'D:\\nope\\missing.txt',
        'projects' => $projects], new Log(num_tmp('log') . '.log'), $fetch);
}

test('a two-property fixture through collect_ga.py stores two sets of numbers', function () {
    $pdo = num_db();
    $log = new Log(num_tmp('log') . '.log');
    $res = NumbersCollector::run([num_ga(__DIR__ . '/fixtures/numbers/ga_raw.json')], new NumberStore($pdo), $log, NUM_NOW);
    assert_same(['ga4:kingsbury-creative' => 6, 'search_console:kingsbury-creative' => 4, 'ga4:example-client' => 4,
        'search_console:example-client' => 4], $res);
    $s = num_by_name($pdo);
    $kc = $s['ga4:kingsbury-creative']['values'];
    assert_same(1240, $kc['sessions']['value']);
    assert_same(903, $kc['users']['value']);
    assert_same(3, $kc['active_users']['value']);
    assert_same(['value' => 610, 'label' => '/'], $kc['top_page:01']);
    assert_same(['value' => 88, 'label' => '/contact/'], $kc['top_page:03']);
    assert_same(310, $s['ga4:example-client']['values']['sessions']['value']);
    assert_same(0.0211, $s['search_console:kingsbury-creative']['values']['ctr']['value']);
    assert_same(9.5, $s['search_console:example-client']['values']['position']['value']);
    assert_same('ok', $s['ga4:example-client']['status']);
    assert_same(NUM_NOW, $s['ga4:example-client']['last_ok_at']);
});

test('a 403 on one property stores its error, the other still stores, and the last good numbers stay', function () {
    $pdo = num_db();
    $store = new NumberStore($pdo);
    $log = new Log(num_tmp('log') . '.log');
    NumbersCollector::run([num_ga(__DIR__ . '/fixtures/numbers/ga_raw.json')], $store, $log, NUM_NOW - 3600);
    $fixture = num_403_fixture();
    try {
        $res = NumbersCollector::run([num_ga($fixture)], $store, $log, NUM_NOW);
    } finally {
        @unlink($fixture);
    }
    assert_true(str_starts_with((string) $res['ga4:example-client'], 'ga4: 403 '), 'result: ' . var_export($res['ga4:example-client'], true));
    assert_same(6, $res['ga4:kingsbury-creative']);
    assert_same(4, $res['search_console:example-client']);
    $s = num_by_name($pdo);
    assert_same('error', $s['ga4:example-client']['status']);
    assert_true(str_contains((string) $s['ga4:example-client']['last_error'], 'sufficient permissions'), 'stored error');
    assert_same(NUM_NOW - 3600, $s['ga4:example-client']['last_ok_at'], 'last good run kept');
    assert_same(310, $s['ga4:example-client']['values']['sessions']['value'], 'last good numbers kept');
    assert_same('ok', $s['ga4:kingsbury-creative']['status']);
    assert_same(NUM_NOW, $s['ga4:kingsbury-creative']['last_ok_at']);
});

test('one failing source stores its error and the others still store', function () {
    $pdo = num_db();
    $runner = new FakeProcessRunner();
    $runner->willReturn(1, '', "Traceback...\nRuntimeError: boom");
    $http = new FakeHttpClient();
    $http->willReturn(NUM_STATUS_URL, json_encode(['status' => 'operational', 'components' => [['status' => 'operational']]]));
    $token = num_tmp('token') . '.txt';
    file_put_contents($token, NUM_FAKE_TOKEN . "\n");
    try {
        $res = NumbersCollector::run([
            num_ga(null, $runner),
            new StatusCollector($http, NUM_STATUS_URL),
            num_bugsink(fn($url, $t) => ['status' => 200, 'body' => json_encode(['next' => null, 'results' => [[], []]])], $token),
        ], new NumberStore($pdo), new Log(num_tmp('log') . '.log'), NUM_NOW);
    } finally {
        @unlink($token);
    }
    foreach (['ga4:kingsbury-creative', 'search_console:kingsbury-creative', 'ga4:example-client', 'search_console:example-client'] as $src) {
        assert_same('collect_ga.py exited 1: RuntimeError: boom', $res[$src], $src);
    }
    assert_same(3, $res['status']);
    assert_same(1, $res['bugsink:sembr']);
    $s = num_by_name($pdo);
    assert_same('error', $s['ga4:kingsbury-creative']['status']);
    assert_same('ok', $s['status']['status']);
    assert_same('operational', $s['status']['values']['status']['label']);
    assert_same(2, $s['bugsink:henike']['values']['open_issues']['value']);
});

test('GaCollector passes the credentials path and each property, never a value, and drops a slug it did not send', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, json_encode(['window_days' => 28, 'properties' => [
        ['slug' => 'kingsbury-creative', 'ga4' => ['sessions' => 5, 'users' => 4, 'active_users' => 1, 'top_pages' => []],
            'ga4_error' => null, 'search_console' => ['clicks' => 1, 'impressions' => 2, 'ctr' => 0.5, 'position' => 3.0],
            'search_console_error' => null],
        ['slug' => 'intruder', 'ga4' => ['sessions' => 999], 'ga4_error' => null, 'search_console' => null, 'search_console_error' => null],
    ]]));
    $out = num_ga(null, $runner)->collect();
    $argv = $runner->calls[0]['argv'];
    assert_same(['-I', realpath(__DIR__ . '/../bin/collect_ga.py'), '--credentials', 'D:\\Dev\\htdocs\\.credentials\\sa.json',
        '--property', 'kingsbury-creative', '100000001', 'sc-domain:kc.example.test',
        '--property', 'example-client', '100000002', 'https://client.example.test/'], array_slice($argv, 1));
    assert_same(GaCollector::TIMEOUT, $runner->calls[0]['timeout']);
    assert_same(['ga4:kingsbury-creative', 'search_console:kingsbury-creative', 'ga4:example-client', 'search_console:example-client'],
        array_keys($out));
    assert_same(['error' => 'not reported'], $out['ga4:example-client']);
    $fixtureArgv = num_ga(__DIR__ . '/fixtures/numbers/ga_raw.json', new FakeProcessRunner())->argv();
    assert_same('--from-fixture', $fixtureArgv[3]);
    assert_true(!in_array('--credentials', $fixtureArgv, true), 'fixture run gets credentials');
});

test('StatusCollector counts checks that are not ok, and a non-200 is an error', function () {
    $http = new FakeHttpClient();
    $http->willReturn(NUM_STATUS_URL, json_encode(['status' => ['indicator' => 'degraded'],
        'components' => [['name' => 'api', 'status' => 'operational'], ['name' => 'db', 'status' => 'major_outage']],
        'monitors' => [['status' => 'UP']]]));
    $rows = (new StatusCollector($http, NUM_STATUS_URL))->collect()['status']['rows'];
    assert_same(['status' => 'degraded', 'checks' => 3, 'down' => 1],
        array_combine(array_column($rows, 'metric'), array_map(fn($r) => $r['label'] ?? $r['value'], $rows)));
    $bad = new FakeHttpClient();
    $bad->willReturn(NUM_STATUS_URL, 'oops', 503);
    $res = NumbersCollector::run([new StatusCollector($bad, NUM_STATUS_URL)], new NumberStore(num_db()), new Log(num_tmp('log') . '.log'), NUM_NOW);
    assert_same('status endpoint answered 503', $res['status']);
});

test('Bugsink: open issues per project, the token only in the header, never in the database or an error', function () {
    $dbFile = num_tmp('db') . '.sqlite';
    $token = num_tmp('token') . '.txt';
    file_put_contents($token, NUM_FAKE_TOKEN);
    $seen = [];
    $fetch = function (string $url, string $t) use (&$seen): array {
        $seen[] = [$url, $t];
        if (str_contains($url, 'project=2')) {
            throw new RuntimeException('connect failed with Bearer ' . $t);
        }
        if (str_contains($url, 'page=2')) {
            return ['status' => 200, 'body' => json_encode(['next' => 'https://evil.example.test/steal', 'results' => [['is_resolved' => false]]])];
        }
        return ['status' => 200, 'body' => json_encode(['next' => NUM_BUGSINK . '/api/canonical/0/issues/?project=1&page=2',
            'results' => [['is_resolved' => false], ['is_resolved' => true], ['is_muted' => true], ['is_resolved' => false]]])];
    };
    try {
        $pdo = num_db($dbFile);
        $res = NumbersCollector::run([num_bugsink($fetch, $token)], new NumberStore($pdo), new Log(num_tmp('log') . '.log'), NUM_NOW);
        $open = num_by_name($pdo)['bugsink:sembr']['values']['open_issues']['value'];
        $pdo = null;
        $raw = (string) file_get_contents($dbFile) . (string) @file_get_contents($dbFile . '-wal');
    } finally {
        @unlink($token);
        foreach (['', '-wal', '-shm'] as $x) {
            @unlink($dbFile . $x);
        }
    }
    assert_same(1, $res['bugsink:sembr']);
    assert_same(3, $open, 'two open on page one, one on page two');
    assert_same([NUM_BUGSINK . '/api/canonical/0/issues/?project=1', NUM_BUGSINK . '/api/canonical/0/issues/?project=1&page=2',
        NUM_BUGSINK . '/api/canonical/0/issues/?project=2'], array_column($seen, 0), 'next to another host not followed');
    assert_same([NUM_FAKE_TOKEN], array_values(array_unique(array_column($seen, 1))));
    assert_same('connect failed with Bearer [redacted]', $res['bugsink:henike']);
    assert_true(str_contains($raw, 'connect failed with Bearer [redacted]'), 'error stored');
    assert_true(!str_contains($raw, NUM_FAKE_TOKEN), 'token reached the database file');
    $missing = NumbersCollector::run([num_bugsink(fn() => throw new RuntimeException('fetched'))], new NumberStore(num_db()),
        new Log(num_tmp('log') . '.log'), NUM_NOW);
    assert_same(['bugsink:sembr' => 'bugsink token file missing or empty', 'bugsink:henike' => 'bugsink token file missing or empty'], $missing);
});

test('Bugsink: a stack trace from inside openIssues does not carry the token, even with exception args on', function () {
    $token = num_tmp('token') . '.txt';
    file_put_contents($token, NUM_FAKE_TOKEN);
    $caught = null;
    // The fake's own token parameter is marked too, so only the collector's frames can leak it.
    $fetch = function (string $url, #[\SensitiveParameter] string $t) use (&$caught): array {
        return $caught === null ? ['status' => 500, 'body' => ''] : ['status' => 200, 'body' => '{"results":[]}'];
    };
    $before = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        $c = num_bugsink($fetch, $token, ['sembr' => 1]);
        // Called through a closure bound to the collector (openIssues is private), so no Reflection frame holds the token.
        $call = Closure::bind(function (#[\SensitiveParameter] string $t) {
            return $this->openIssues(NUM_BUGSINK, 1, $t);
        }, $c, BugsinkCollector::class);
        try {
            $call(trim((string) file_get_contents($token)));
        } catch (RuntimeException $e) {
            $caught = $e;
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $before);
        @unlink($token);
    }
    assert_true($caught !== null, 'openIssues did not throw on a 500');
    $trace = $caught->getTraceAsString();
    assert_true(str_contains($trace, 'openIssues('), 'trace has the frame: ' . $trace);
    assert_true(!str_contains($trace, substr(NUM_FAKE_TOKEN, 0, 8)), 'token prefix in the trace: ' . $trace);
});

test('Bugsink: a count stopped by the page cap is stored as partial and shown as a floor', function () {
    $token = num_tmp('token') . '.txt';
    file_put_contents($token, NUM_FAKE_TOKEN);
    $pages = 0;
    $fetch = function (string $url) use (&$pages): array {
        $pages++;
        return ['status' => 200, 'body' => json_encode(['next' => NUM_BUGSINK . '/api/canonical/0/issues/?project=1&page=' . ($pages + 1),
            'results' => [['is_resolved' => false], ['is_resolved' => false]]])];
    };
    $pdo = num_db();
    try {
        NumbersCollector::run([num_bugsink($fetch, $token, ['sembr' => 1])], new NumberStore($pdo), new Log(num_tmp('log') . '.log'), NUM_NOW);
    } finally {
        @unlink($token);
    }
    assert_same(10, $pages, 'page cap');
    $s = num_by_name($pdo)['bugsink:sembr'];
    assert_same(['value' => 20, 'label' => 'partial'], $s['values']['open_issues']);
    $whole = num_db();
    file_put_contents($token, NUM_FAKE_TOKEN);
    try {
        NumbersCollector::run([num_bugsink(fn() => ['status' => 200, 'body' => '{"next":null,"results":[{}]}'], $token, ['sembr' => 1])],
            new NumberStore($whole), new Log(num_tmp('log') . '.log'), NUM_NOW);
    } finally {
        @unlink($token);
    }
    assert_same(['value' => 1, 'label' => null], num_by_name($whole)['bugsink:sembr']['values']['open_issues'], 'a full count');
    require_once __DIR__ . '/../src/View.php';
    $tmp = num_tmp('page');
    file_put_contents($tmp . '.token', str_repeat('e6', 32));
    try {
        [, , $body] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $tmp . '.token', new Log($tmp . '.log'), ['numbers' => fn() => NumbersApi::data($pdo, NUM_NOW)]);
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
    assert_true(str_contains($body, '<p class="meta">open issues 20+</p>'), 'floor shown');
    assert_true(str_contains((string) file_get_contents(__DIR__ . '/../public/assets/numbers.js'), "label === 'partial' ? '+' : ''"), 'numbers.js');
});

test('config/properties.php holds credential paths under .credentials, not values', function () {
    $cfg = require __DIR__ . '/../config/properties.php';
    assert_same(['base_url', 'token_path', 'projects'], array_keys($cfg['bugsink']));
    foreach ([$cfg['bugsink']['token_path'], $cfg['service_account_path']] as $path) {
        assert_true(is_string($path) && str_starts_with($path, 'D:\\Dev\\htdocs\\.credentials\\'), 'path: ' . var_export($path, true));
        assert_true(preg_match('/^[A-Za-z]:\\\\[^:*?"<>|\/\0]+\.(txt|json)$/', $path) === 1, 'not a plain file path: ' . $path);
    }
    assert_true(str_starts_with($cfg['bugsink']['base_url'], 'https://'), 'bugsink https');
    assert_same('https://status.sembr.co/api/status', $cfg['status_url']);
    $src = (string) file_get_contents(__DIR__ . '/../config/properties.php');
    assert_true(preg_match('/[\'"](token|api_key|secret|password)[\'"]\s*=>/i', $src) === 0, 'a credential value key');
    assert_true(preg_match('/[A-Za-z0-9_\-]{32,}/', $src) === 0, 'a token-like string');
    foreach ($cfg['properties'] as $slug => $p) {
        assert_true(preg_match('/^\d+$/', $p['ga4']) === 1, "$slug ga4");
        assert_true(preg_match('#^(https://|sc-domain:)#', $p['search_console']) === 1, "$slug search console");
    }
});
