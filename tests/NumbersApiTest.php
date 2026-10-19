<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Numbers/GaCollector.php';
require_once __DIR__ . '/Support/DomSinks.php';

define('NUMAPI_TOKEN', str_repeat('d5', 32));
define('NUMAPI_NOW', strtotime('2026-10-19 12:00:00 UTC'));

function numapi_db(): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $store = new NumberStore($pdo);
    $store->save('ga4:kingsbury-creative', [['metric' => 'sessions', 'value' => 1240], ['metric' => 'users', 'value' => 903],
        ['metric' => 'top_page:01', 'value' => 610, 'label' => '/']], NUMAPI_NOW - 600);
    $store->save('search_console:kingsbury-creative', [['metric' => 'ctr', 'value' => 0.0211]], NUMAPI_NOW - 600);
    $store->save('ga4:example-client', [['metric' => 'sessions', 'value' => 300]], NUMAPI_NOW - 600);
    $store->save('ga4:example-client', [['metric' => 'sessions', 'value' => 310]], NUMAPI_NOW - 27 * 3600);
    $store->failed('ga4:example-client', '403 denied', new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnumapi.log'));
    return $pdo;
}

function numapi_get(array $over = [], bool &$opened = false): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnumapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', NUMAPI_TOKEN);
    $server = array_filter($over + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => NUMAPI_TOKEN],
        fn($v) => $v !== null);
    try {
        return NumbersApi::get($server, $tmp . '.token', function () use (&$opened): PDO {
            $opened = true;
            return numapi_db();
        }, new Log($tmp . '.log'), NUMAPI_NOW);
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
}

test('numbers.php returns the latest values per source with its last run', function () {
    [$status, , $body] = numapi_get();
    assert_same(200, $status);
    $sources = [];
    foreach (json_decode($body, true)['sources'] as $s) {
        $sources[$s['name']] = $s;
    }
    assert_same(['ga4:example-client', 'ga4:kingsbury-creative', 'search_console:kingsbury-creative'], array_keys($sources));
    $kc = $sources['ga4:kingsbury-creative'];
    assert_same(['sessions' => ['value' => 1240, 'label' => null], 'top_page:01' => ['value' => 610, 'label' => '/'],
        'users' => ['value' => 903, 'label' => null]], $kc['values']);
    assert_same(['ok', NUMAPI_NOW - 600, null, false], [$kc['status'], $kc['last_ok_at'], $kc['last_error'], $kc['stale']]);
    assert_same(0.0211, $sources['search_console:kingsbury-creative']['values']['ctr']['value']);
    $client = $sources['ga4:example-client'];
    assert_same(310, $client['values']['sessions']['value'], 'the later save replaced the rows');
    assert_same(['error', '403 denied', true], [$client['status'], $client['last_error'], $client['stale']]);
});

test('numbers.php: foreign Host, no token, a navigation or a POST is refused before the database opens', function () {
    $refused = [
        'foreign host' => ['HTTP_HOST' => 'evil.com'],
        'no token' => [RequestGuard::TOKEN_HEADER => null],
        'wrong token' => [RequestGuard::TOKEN_HEADER => str_repeat('00', 32)],
        'navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'],
        'cross-site' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        'POST' => ['REQUEST_METHOD' => 'POST'],
    ];
    foreach ($refused as $name => $over) {
        $opened = false;
        [$status, , $body] = numapi_get($over, $opened);
        assert_same(403, $status, $name);
        assert_same('{"error":"forbidden"}', $body, $name);
        assert_true(!$opened, "$name: database opened");
    }
});

test('the page renders the numbers with JS off, escaped, and says so when they cannot be read', function () {
    require_once __DIR__ . '/../src/View.php';
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnumapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', NUMAPI_TOKEN);
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'];
    $xss = '<img src=x onerror=alert(1)>';
    try {
        [$status, , $body] = View::page($server, $tmp . '.token', new Log($tmp . '.log'), ['numbers' => fn() => ['sources' => [
            ['name' => 'ga4:kingsbury-creative', 'status' => 'ok', 'last_ok_at' => 1, 'last_error' => null, 'stale' => false,
                'values' => ['sessions' => ['value' => 1240, 'label' => null], 'top_page:01' => ['value' => 610, 'label' => $xss]]],
            ['name' => 'search_console:x', 'status' => 'error', 'last_ok_at' => null, 'last_error' => $xss, 'stale' => true,
                'values' => ['ctr' => ['value' => 0.0211, 'label' => null]]],
        ]]]);
        [, , $broken] = View::page($server, $tmp . '.token', new Log($tmp . '.log'), ['numbers' => fn() => throw new RuntimeException('x')]);
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
    assert_same(200, $status);
    assert_true(str_contains($body, '<script type="module" src="/assets/numbers.js"></script>'), 'script');
    assert_true(!str_contains($body, $xss), 'raw payload on the page');
    assert_true(str_contains($body, '<p class="meta">sessions 1240</p><ol class="pages"><li>&lt;img'), 'values and top page');
    assert_true(str_contains($body, '<p class="meta">CTR 2.1% · no good run yet · stale</p><p class="error">&lt;img'), 'error source');
    assert_true(str_contains($broken, '<ul id="numbers" class="list"><li class="quiet">Numbers unavailable.</li></ul>'), 'failed source');
});

test('numbers.js sends X-KC-Token on its fetch and sets no href', function () {
    $src = (string) file_get_contents(__DIR__ . '/../public/assets/numbers.js');
    preg_match_all('/\bfetch\s*\(([^;]*)\);/', $src, $m);
    assert_same(1, count($m[0]), 'one fetch');
    assert_true(str_contains($m[1][0], "'X-KC-Token': token"), 'token header');
    assert_true(preg_match('/href|innerHTML|\.src\s*=/', $src) === 0, 'a link or markup sink');
});

test('DomSinks clean with numbers.js in public/assets', function () {
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
});
