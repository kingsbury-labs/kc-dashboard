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
    foreach (["default-src 'self'", "form-action 'self' https://www.google.com", "frame-ancestors 'none'"] as $part) {
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
