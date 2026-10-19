<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Projects/Scanner.php';

define('PAPI_TOKEN', str_repeat('a7', 32));

// A full scanner row with $over applied.
function papi_row(string $slug, array $over = []): array
{
    return $over + ['slug' => $slug, 'path' => 'D:\\Dev\\htdocs\\' . $slug, 'branch' => 'main', 'ahead' => 0, 'behind' => 0,
        'dirty' => 0, 'last_sha' => str_repeat('1', 40), 'last_date' => '2026-10-01T12:00:00+00:00', 'last_subject' => 's',
        'has_claude' => 1, 'loop_state' => 'none', 'loop_card' => null, 'loop_note' => null, 'checklist_cards' => null,
        'checklist_passing' => null, 'scanned_at' => 1000, 'error' => null];
}

function papi_db(array $rows): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    Scanner::store($pdo, $rows);
    return $pdo;
}

// Rows given out of order. Expected: the running loop (old commit), then dirty newest first, then clean newest first.
// "late-offset" is 2026-10-05 20:00 at -05:00, which is 01:00 UTC on the 6th: newer than "utc-6th" by the clock,
// older by the string.
function papi_fixture(): array
{
    return [
        papi_row('clean-old', ['last_date' => '2026-09-01T09:00:00+00:00']),
        papi_row('dirty-old', ['dirty' => 4, 'last_date' => '2026-09-02T09:00:00+00:00']),
        papi_row('utc-6th', ['last_date' => '2026-10-06T00:30:00+00:00']),
        papi_row('looping', ['loop_state' => 'running', 'loop_card' => '75', 'last_date' => '2026-08-01T00:00:00+00:00']),
        papi_row('dirty-new', ['dirty' => 1, 'last_date' => '2026-10-10T09:00:00+00:00']),
        papi_row('late-offset', ['last_date' => '2026-10-05T20:00:00-05:00']),
        papi_row('stale-loop', ['loop_state' => 'stale', 'loop_card' => '3', 'last_date' => '2026-07-01T00:00:00+00:00']),
        papi_row('no-commit', ['last_date' => null, 'last_subject' => null]),
    ];
}

const PAPI_ORDER = ['looping', 'dirty-new', 'dirty-old', 'late-offset', 'utc-6th', 'clean-old', 'stale-loop', 'no-commit'];

// A same-origin fetch from the page with the token; $over changes or (with null) removes a header.
function papi_get(string $host, array $over = [], ?Closure $pdo = null, bool &$opened = false): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', PAPI_TOKEN);
    $server = array_filter($over + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => PAPI_TOKEN],
        fn($v) => $v !== null);
    $pdo = $pdo ?? fn(): PDO => papi_db(papi_fixture());
    try {
        return View::projects($server, $tmp . '.token', function () use ($pdo, &$opened): PDO {
            $opened = true;
            return $pdo();
        }, new Log($tmp . '.log'));
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
}

test('projects.php: foreign Host rejected before the database opens', function () {
    $opened = false;
    [$status, , $body] = papi_get('evil.com', [], null, $opened);
    assert_same(403, $status);
    assert_same('{"error":"forbidden"}', $body);
    assert_true(!$opened, 'database opened for a refused request');
});

test('projects.php: no token, a wrong token, a navigation or a POST is refused before the database opens', function () {
    $refused = [
        'no token' => [RequestGuard::TOKEN_HEADER => null],
        'wrong token' => [RequestGuard::TOKEN_HEADER => str_repeat('0', 64)],
        'no token, curl' => [RequestGuard::TOKEN_HEADER => null, 'HTTP_SEC_FETCH_SITE' => null, 'HTTP_SEC_FETCH_MODE' => null],
        'cross-site navigate' => ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'],
        'same-origin navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'],
        'cross-site cors' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        'POST' => ['REQUEST_METHOD' => 'POST'],
    ];
    foreach ($refused as $name => $over) {
        $opened = false;
        [$status, , $body] = papi_get('127.0.0.1:8787', $over, null, $opened);
        assert_same(403, $status, $name);
        assert_same('{"error":"forbidden"}', $body, $name);
        assert_true(!$opened, "$name: database opened");
    }
});

test('projects.php: rows in the stated order, running loop then dirty then newest commit', function () {
    [$status, , $body] = papi_get('localhost:8787');
    assert_same(200, $status);
    $rows = json_decode($body, true)['projects'];
    assert_same(PAPI_ORDER, array_column($rows, 'slug'));
    assert_same('75', $rows[0]['loop_card']);
    assert_same(4, $rows[2]['dirty']);
});

test('projects.php: rows carry no filesystem path', function () {
    [, , $body] = papi_get('localhost:8787');
    $rows = json_decode($body, true)['projects'];
    assert_true(!array_key_exists('path', $rows[0]), 'path key');
    assert_true(!str_contains($body, 'Dev\\\\htdocs'), 'path value');
});

test('projects.php: one invalid UTF-8 commit subject still gives the whole JSON body', function () {
    $rows = papi_fixture();
    $rows[0]['last_subject'] = "fix \xff\xfe caf\xc3";
    [$status, , $body] = papi_get('localhost:8787', [], fn(): PDO => papi_db($rows));
    assert_same(200, $status);
    $data = json_decode($body, true);
    assert_true(is_array($data), 'body is not JSON: ' . var_export($body, true));
    assert_same(PAPI_ORDER, array_column($data['projects'], 'slug'));
    $clean = array_column($data['projects'], 'last_subject', 'slug')['clean-old'];
    assert_true(str_contains($clean, "\u{FFFD}"), 'substituted: ' . $clean);
});
