<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/News/Scorer.php';
require_once __DIR__ . '/Support/DomSinks.php';

define('NAPI_TOKEN', str_repeat('c4', 32));
define('NAPI_NOW', strtotime('2026-10-19 12:00:00 UTC'));
const NAPI_TOPICS = ['ai', 'webdev', 'php', 'dev'];

function napi_insert(PDO $pdo, string $source, string $topic, string $title, ?string $url, int $hoursAgo, ?int $points = null): void
{
    static $n = 0;
    $pdo->prepare('INSERT INTO news_items (id, source, topic, title, url, canon, published_at, points, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['napi' . ++$n, $source, $topic, $title, $url, Clusterer::canonical($url), NAPI_NOW - $hoursAgo * 3600,
            $points, NAPI_NOW, NAPI_NOW]);
}

// Five stories whose order is plain from their source counts and ages: three sources, two, one, then older ones.
function napi_db(): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    foreach (['OpenAI', 'Simon Willison', 'Latent Space'] as $s) {
        napi_insert($pdo, $s, 'ai', 'Story alpha', 'https://alpha.example.test/a?utm_source=' . $s, 1);
    }
    foreach (['web.dev', 'WebKit'] as $s) {
        napi_insert($pdo, $s, 'webdev', 'Story bravo', 'https://bravo.example.test/b', 1);
    }
    napi_insert($pdo, 'Lobsters', 'dev', 'Story charlie', 'https://charlie.example.test/c', 1);
    napi_insert($pdo, 'PHP', 'php', 'Story delta', 'http://127.0.0.1:8787/api/ask.php', 10);
    napi_insert($pdo, 'Laravel News', 'php', 'Story echo', 'https://echo.example.test/e', 40);
    napi_insert($pdo, 'Old', 'ai', 'Story foxtrot', 'https://foxtrot.example.test/f', 24 * 8);
    return $pdo;
}

function napi_get(array $query = [], array $over = [], int $pageSize = 2, bool &$opened = false): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', NAPI_TOKEN);
    $server = array_filter($over + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => NAPI_TOKEN],
        fn($v) => $v !== null);
    try {
        return NewsApi::get($server, $tmp . '.token', function () use (&$opened): PDO {
            $opened = true;
            return napi_db();
        }, new Log($tmp . '.log'), NAPI_TOPICS, $query, NAPI_NOW, $pageSize);
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
}

function napi_titles(array $res): array
{
    return array_column(json_decode($res[2], true)['stories'], 'title');
}

test('news.php: hottest first, one entry per story, older than the window left out', function () {
    $res = napi_get([], [], 10);
    assert_same(200, $res[0]);
    assert_same(['Story alpha', 'Story bravo', 'Story charlie', 'Story delta', 'Story echo'], napi_titles($res));
    $sources = json_decode($res[2], true)['stories'][0]['sources'];
    sort($sources);
    assert_same(['Latent Space', 'OpenAI', 'Simon Willison'], $sources);
});

test('news.php: page size and the page 2 offset', function () {
    $one = json_decode(napi_get()[2], true);
    assert_same(['Story alpha', 'Story bravo'], array_column($one['stories'], 'title'));
    assert_same(true, $one['more']);
    $two = json_decode(napi_get(['page' => '2'])[2], true);
    assert_same(['Story charlie', 'Story delta'], array_column($two['stories'], 'title'));
    assert_same(2, $two['page']);
    $three = json_decode(napi_get(['page' => '3'])[2], true);
    assert_same(['Story echo'], array_column($three['stories'], 'title'));
    assert_same(false, $three['more']);
});

test('news.php: the topic filter, and an unknown topic or bad page is refused', function () {
    assert_same(['Story delta', 'Story echo'], napi_titles(napi_get(['topic' => 'php'])));
    assert_same(['Story bravo'], napi_titles(napi_get(['topic' => 'webdev'])));
    foreach ([['topic' => 'sports'], ['topic' => ['ai']], ['page' => '0'], ['page' => '-1'], ['page' => '1e3'], ['page' => '101']] as $q) {
        assert_same(400, napi_get($q)[0], json_encode($q));
    }
});

test('news.php: a url to this machine comes back as null', function () {
    $stories = json_decode(napi_get(['topic' => 'php'])[2], true)['stories'];
    assert_same(null, $stories[0]['url']);
    assert_same('https://echo.example.test/e', $stories[1]['url']);
    foreach (['https://localhost/x', 'https://a.localhost/x', 'https://[::1]/x', 'https://2130706433/x', 'javascript:alert(1)',
        'https://LOCALHOST/x', 'https://localhost./x', 'https://%6c%6fcalhost/x', 'https://a.%6cocalhost/x', 'https://0x7f.1/x',
        'https://127.1/x', "https://b\u{fc}cher.example.test/x", 'https://%62%C3%BCcher.example.test/x', 'https://a_b.example.test/x'] as $bad) {
        assert_same(null, NewsApi::safeUrl($bad), $bad);
    }
    assert_same('https://xn--bcher-kva.example.test/x', NewsApi::safeUrl('https://xn--bcher-kva.example.test/x'), 'punycode');
    assert_same('https://Sub.Example.test/x', NewsApi::safeUrl('https://Sub.Example.test/x'), 'mixed case');
    // A browser decodes the host before it resolves it, so the check judges the decoded name too.
    assert_same('https://%61pi.example.test/x', NewsApi::safeUrl('https://%61pi.example.test/x'), 'decoded ordinary host');
});

test('news data comes from the cache for 60 seconds, then the database again; a bad cache file is rebuilt', function () {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnapi_cache_' . bin2hex(random_bytes(4)) . '.json';
    $opens = 0;
    $pdo = function () use (&$opens): PDO {
        $opens++;
        return napi_db();
    };
    try {
        NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW, 10, $cache, false);
        assert_true(!is_file($cache), 'a read-only call wrote the cache');
        $opens = 0;
        $first = NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW, 10, $cache);
        assert_same(1, $opens);
        $again = NewsApi::data($pdo, NAPI_TOPICS, 'php', 1, NAPI_NOW + 59, 10, $cache);
        assert_same(1, $opens, 'database opened inside 60 seconds');
        assert_same(['Story delta', 'Story echo'], array_column($again['stories'], 'title'), 'topic filter on the cached list');
        assert_same($first['stories'][0], NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW + 59, 10, $cache)['stories'][0]);
        NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW + 60, 10, $cache);
        assert_same(2, $opens, 'cache used at 60 seconds');
        NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW + 30, 10, $cache);
        assert_same(3, $opens, 'a cache written in the future was used');
        file_put_contents($cache, json_encode(['at' => NAPI_NOW + 30, 'stories' => [['title' => ['x']]]]));
        $rebuilt = NewsApi::data($pdo, NAPI_TOPICS, '', 1, NAPI_NOW + 31, 10, $cache);
        assert_same(4, $opens, 'a bad cache file was used');
        assert_same('Story alpha', $rebuilt['stories'][0]['title']);
    } finally {
        @unlink($cache);
    }
});

test('news.php: foreign Host, no token, a navigation or a POST is refused before the database opens', function () {
    $refused = [
        'foreign host' => ['HTTP_HOST' => 'evil.com'],
        'no token' => [RequestGuard::TOKEN_HEADER => null],
        'navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'],
        'cross-site' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        'POST' => ['REQUEST_METHOD' => 'POST'],
    ];
    foreach ($refused as $name => $over) {
        $opened = false;
        [$status, , $body] = napi_get([], $over, 2, $opened);
        assert_same(403, $status, $name);
        assert_same('{"error":"forbidden"}', $body, $name);
        assert_true(!$opened, "$name: database opened");
    }
});

test('news.js sets rel="noopener noreferrer" and assigns href only after an http:/https: check', function () {
    $src = (string) file_get_contents(__DIR__ . '/../public/assets/news.js');
    assert_true(str_contains($src, "a.rel = 'noopener noreferrer';"), 'rel');
    assert_true(preg_match("/function isHttp\(url\) \{.*?return p === 'http:' \|\| p === 'https:';/s", $src) === 1, 'protocol check');
    preg_match_all('/\.href\s*=|setAttribute\(\s*[\'"]href/', $src, $all);
    assert_same(1, count($all[0]), 'one href assignment');
    assert_true(preg_match('/if \(isHttp\(s\.url\)\) \{\s*const a = el\(\'a\', null, s\.title\);\s*a\.href = s\.url;/', $src) === 1,
        'href assigned inside the isHttp check');
});

test('the page renders the first page of headlines with JS off, escaped, a link only for an http(s) url', function () {
    require_once __DIR__ . '/../src/View.php';
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcnapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', NAPI_TOKEN);
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'];
    $xss = '<img src=x onerror=alert(1)>';
    try {
        [$status, , $body] = View::page($server, $tmp . '.token', new Log($tmp . '.log'), ['news' => fn() => ['stories' => [
            ['title' => $xss, 'url' => 'https://a.example.test/x', 'sources' => [$xss], 'points' => 12],
            ['title' => 'No link', 'url' => 'javascript:alert(1)', 'sources' => ['B'], 'points' => 0],
        ]]]);
        [, , $broken] = View::page($server, $tmp . '.token', new Log($tmp . '.log'), ['news' => fn() => throw new RuntimeException('x')]);
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
    assert_same(200, $status);
    assert_true(str_contains($body, '/assets/news.js'), 'script');
    assert_true(!str_contains($body, $xss), 'raw payload on the page');
    assert_true(str_contains($body, '<a href="https://a.example.test/x" rel="noopener noreferrer" target="_blank">&lt;img'), 'link');
    assert_true(str_contains($body, '<p class="subject">No link</p>'), 'javascript: url is text');
    assert_true(!str_contains($body, 'javascript:'), 'javascript: url on the page');
    assert_true(str_contains($broken, '<ul id="news" class="list"><li class="quiet">Headlines unavailable.</li></ul>'), 'failed source');
});

test('DomSinks clean with news.js in public/assets', function () {
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
});
