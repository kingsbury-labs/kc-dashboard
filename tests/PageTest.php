<?php
require_once __DIR__ . '/../src/View.php';

function page_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpage_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function page_server(string $host): array
{
    return ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET'];
}

function page_assert_headers(array $headers): void
{
    foreach (['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer'] as $name => $value) {
        assert_same($value, $headers[$name] ?? null, $name);
    }
    $csp = $headers['Content-Security-Policy'] ?? '';
    foreach (["default-src 'self'", "form-action 'self' https://www.google.com", "frame-ancestors 'none'",
        "require-trusted-types-for 'script'", "trusted-types 'none'", "base-uri 'none'"] as $part) {
        assert_true(str_contains($csp, $part), "csp $part");
    }
}

test('foreign host gives 403 without reading the token', function () {
    $dir = page_dir();
    $token = str_repeat('ab', 32);
    file_put_contents($dir . '/token', $token);
    [$status, $headers, $body] = View::page(page_server('evil.com'), $dir . '/token', new Log($dir . '/log'));
    assert_same(403, $status);
    assert_true(!str_contains($body, $token), 'token in body');
    page_assert_headers($headers);
});

test('good pair gives meta token, search form and headers', function () {
    $dir = page_dir();
    $token = str_repeat('cd', 32);
    file_put_contents($dir . '/token', $token);
    [$status, $headers, $body] = View::page(page_server('localhost:8787'), $dir . '/token', new Log($dir . '/log'));
    assert_same(200, $status);
    assert_true(str_contains($body, '<meta name="kc-token" content="' . $token . '">'), 'meta');
    assert_true(str_contains($body, 'action="https://www.google.com/search"'), 'action');
    assert_true(str_contains($body, 'method="get"'), 'method');
    page_assert_headers($headers);
});

test('exception in the guard path gives bare 500', function () {
    $dir = page_dir();
    $token = str_repeat('ef', 32);
    file_put_contents($dir . '/token', $token . 'x');
    [$status, $headers, $body] = View::page(page_server('localhost:8787'), $dir . '/token', new Log($dir . '/log'));
    assert_same(500, $status);
    assert_true(!str_contains($body, $token) && !str_contains($body, '#0 '), 'leak');
    page_assert_headers($headers);
});

test('error response carries no message or trace', function () {
    $token = str_repeat('12', 32);
    [$status, $headers, $body] = Response::error(new RuntimeException('boom ' . $token));
    assert_same(500, $status);
    assert_same('{"error":"internal"}', $body);
    assert_true(!str_contains($body, $token) && !str_contains($body, '#0 '), 'leak');
    page_assert_headers($headers);
});

test('a 403 never creates the token file', function () {
    $dir = page_dir();
    [$status] = View::page(page_server('evil.com'), $dir . '/token', new Log($dir . '/log'));
    assert_same(403, $status);
    assert_true(!file_exists($dir . '/token'), 'token file created');
});

test('the token occurs exactly once in the body, in its meta tag', function () {
    $dir = page_dir();
    $token = str_repeat('7e', 32);
    file_put_contents($dir . '/token', $token);
    [, , $body] = View::page(page_server('127.0.0.1:8787'), $dir . '/token', new Log($dir . '/log'), [
        'answers' => fn() => [['project' => '', 'question' => 'q', 'status' => 'done', 'answer' => 'a', 'error' => null]],
        'clients' => fn() => ['clients' => [], 'leads' => []],
        'elevated' => fn() => true,
    ]);
    assert_same(1, substr_count($body, $token), 'token count');
    assert_same(1, preg_match('/<meta name="kc-token" content="' . $token . '">/', $body), 'in the meta tag');
});

function page_copy(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$from/$f") ? page_copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }
}

function page_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? page_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

function page_child(array $argv, array $env): string
{
    $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
    assert_true(is_resource($proc), 'could not start ' . $argv[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out;
}

test('a true fatal in the front controller gives the JSON 500 with no trace', function () {
    $app = dirname(__DIR__);
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcfatal_' . bin2hex(random_bytes(6));
    try {
        foreach (['src', 'config', 'public'] as $d) {
            page_copy("$app/$d", "$t/$d");
        }
        mkdir("$t/data");
        // Redeclaring a built-in is a compile error: no handler can catch it, only a shutdown function sees it.
        file_put_contents("$t/config/projects.php", "<?php\nfunction strlen() {}\n");
        $index = str_replace('/', '\\', "$t/public/index.php");
        // The CGI variables Apache would set; REDIRECT_STATUS satisfies cgi.force_redirect.
        $out = page_child(['C:\\xampp\\php\\php-cgi.exe', $index], ['REDIRECT_STATUS' => '200',
            'SCRIPT_FILENAME' => $index, 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET']);
        assert_true(preg_match('/^Status: 500\b/mi', $out) === 1, 'status 500: ' . substr($out, 0, 300));
        assert_true(preg_match('#^Content-Type: application/json#mi', $out) === 1, 'json content type');
        assert_true(str_ends_with($out, '{"error":"internal"}'), 'json body: ' . substr($out, -200));
        assert_true(!str_contains($out, '#0 ') && stripos($out, 'redeclare') === false, 'leak');
    } finally {
        page_rmtree($t);
    }
});

test('emit sends no body once headers are already sent', function () {
    $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcemit_' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__) . '/src/Http/Response.php', true)
        . ";\necho 'partial';\nflush();\nResponse::emit(Response::json(500, ['error' => 'internal']));\n");
    try {
        assert_same('partial', page_child([PHP_BINARY, $script], []));
    } finally {
        @unlink($script);
    }
});
