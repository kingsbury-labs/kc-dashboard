<?php
require_once __DIR__ . '/../src/Cockpit/CockpitPage.php';

// Card 110: the cockpit's voice line to cas-voice. A made-up token, never the real one.
define('CV_VOICE', str_repeat('3e', 32));
define('CV_JS', dirname(__DIR__) . '/public/cockpit/js');

function cv_world(): string
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccv_' . bin2hex(random_bytes(6));
    mkdir($t);
    file_put_contents($t . '/token', str_repeat('7c', 32));
    return $t;
}

function cv_page(string $t, string $voicePath): array
{
    return CockpitPage::handle(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'HTTP_SEC_FETCH_SITE' => 'none',
        'HTTP_SEC_FETCH_MODE' => 'navigate'], $t . '/token', new Log($t . '/app.log'), $voicePath);
}

function cv_directives(string $csp): array
{
    return array_map('trim', explode(';', $csp));
}

test('the cockpit CSP adds only connect-src for the local voice socket and other pages are unchanged', function () {
    $t = cv_world();
    [, $headers] = cv_page($t, $t . '/missing');
    $base = Response::SECURITY_HEADERS;
    $csp = $headers['Content-Security-Policy'];
    unset($headers['Content-Security-Policy'], $headers['Content-Type'], $base['Content-Security-Policy']);
    assert_same($base, $headers, 'every other header unchanged');
    $extra = array_values(array_diff(cv_directives($csp), cv_directives(Response::SECURITY_HEADERS['Content-Security-Policy'])));
    assert_same(["connect-src 'self' ws://127.0.0.1:8766"], $extra);
    assert_same([], array_diff(cv_directives(Response::SECURITY_HEADERS['Content-Security-Policy']), cv_directives($csp)), 'nothing dropped');
    [, $h] = Response::html(200, '');
    assert_same(['Content-Type' => 'text/html; charset=utf-8'] + Response::SECURITY_HEADERS, $h, 'other html pages');
    [, $h] = Response::json(200, []);
    assert_same(['Content-Type' => 'application/json'] + Response::SECURITY_HEADERS, $h, 'json');
});

test('voice.js connects only to ws://127.0.0.1:8766/ws and sends hello with the token first', function () {
    $src = (string) file_get_contents(CV_JS . '/voice.js');
    preg_match_all('~[a-z]+://[^\'"`\s)]*~i', $src, $m);
    $urls = array_values(array_unique(array_filter($m[0], fn($u) => !str_starts_with($u, 'D:'))));
    assert_same(["ws://127.0.0.1:8766/ws"], $urls, 'URLs in voice.js');
    assert_same(1, substr_count($src, 'new WebSocket('), 'one socket');
    assert_true(str_contains($src, 'new WebSocket(URL_WS)'), 'the socket uses the one URL');
    $first = strpos($src, '.send(');
    assert_true($first !== false, 'sends nothing');
    $line = substr($src, $first, (int) strpos($src, "\n", $first) - $first);
    assert_true(str_contains($line, "type: 'hello', token: token()"), "first send is not hello: $line");
    $open = substr($src, (int) strpos($src, 's.onopen'), 200);
    assert_true(strpos($open, "'hello'") < strpos($open, "'mode'"), 'hello before mode on open');
    assert_true(str_contains($src, "addModule('/cockpit/js/mic-worklet.js')"), 'the worklet loads from self, no blob');
});

test('the voice token reaches the page but never app.log', function () {
    $t = cv_world();
    file_put_contents($t . '/voice-token', CV_VOICE . "\n");
    $log = new Log($t . '/app.log');
    [$status, , $body] = CockpitPage::handle(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'HTTP_SEC_FETCH_SITE' => 'none',
        'HTTP_SEC_FETCH_MODE' => 'navigate'], $t . '/token', $log, $t . '/voice-token');
    assert_same(200, $status);
    assert_true(str_contains($body, '<meta name="kc-voice-token" content="' . CV_VOICE . '">'), 'voice meta');
    assert_true(!str_contains($body, 'Voice engine is off'), 'engine shown off with a token');
    $log->info('voice check ' . CV_VOICE);
    assert_true(!str_contains((string) @file_get_contents($t . '/app.log'), CV_VOICE), 'token in app.log');
});

test('with no token file, or a bad one, the page renders Voice engine is off', function () {
    $t = cv_world();
    file_put_contents($t . '/bad', 'not-a-token');
    foreach (['missing' => $t . '/missing', 'malformed' => $t . '/bad', 'unset' => ''] as $name => $path) {
        [$status, , $body] = cv_page($t, $path);
        assert_same(200, $status, $name);
        assert_true(str_contains($body, 'Voice engine is off'), "$name: no off state");
        assert_true(!str_contains($body, 'kc-voice-token'), "$name: voice meta");
    }
});

test('voice.js uses no innerHTML and keeps the mode in localStorage inside try/catch', function () {
    foreach (['voice.js', 'mic-worklet.js'] as $f) {
        $src = (string) file_get_contents(CV_JS . "/$f");
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML'] as $bad) {
            assert_true(!str_contains($src, $bad), "$f uses $bad");
        }
    }
    $lines = explode("\n", (string) file_get_contents(CV_JS . '/voice.js'));
    $uses = 0;
    foreach ($lines as $i => $line) {
        if (str_contains($line, 'localStorage.')) {
            $uses++;
            assert_true(str_contains($line, 'try {') || str_ends_with(rtrim($lines[$i - 1] ?? ''), 'try {'), 'outside try: ' . trim($line));
        }
    }
    $src = implode("\n", $lines);
    assert_true(str_contains($src, 'localStorage.getItem(KEY)') && str_contains($src, 'localStorage.setItem(KEY'), 'mode read and written');
    assert_same(2, $uses);
});
