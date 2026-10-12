<?php
// Post-install HTTP check (card 07). Real requests with curl; not part of tests/run.php.
// Usage: php ops/http-check.php    Exit 0 when every check passes.

const BASE = 'http://127.0.0.1:8787';
const PORT80 = ['http://127.0.0.1', 'http://[::1]', 'https://127.0.0.1'];
const GOOD_ORIGIN = 'http://127.0.0.1:8787';
const TOKEN_HEADER = 'X-KC-Token';

$root = dirname(__DIR__);
$tokenFile = $root . '/data/token';
// Held only to search response bodies for it; never printed.
$token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';

$failures = 0;
if ($token === '') {
    echo "FAIL data/token missing or empty; run the install first\n";
    exit(1);
}

function req(string $method, string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROXY => '',
    ]);
    if (strpos($url, 'https://127.0.0.1') === 0) {
        // XAMPP's self-signed local cert only.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size), 'error' => ''];
}

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function refused(array $r): bool
{
    return $r['status'] >= 400 && $r['status'] < 500;
}

function body_clean(array $r, string $label, bool $allowMeta = false): void
{
    global $token;
    $body = $r['body'];
    if ($allowMeta) {
        // Card 11 puts the token in one <meta> tag on /; drop that tag only.
        $body = preg_replace('/<meta[^>]*' . preg_quote($token, '/') . '[^>]*>/i', '', $body, 1);
    }
    check(strpos($body, $token) === false, "$label: body does not contain the token");
    check(strpos($r['body'], '#0 ') === false, "$label: body has no stack frames");
}

$apiFiles = glob($root . '/public/api/*.php') ?: [];
$paths = ['/'];
$writes = [];
foreach ($apiFiles as $f) {
    $p = '/api/' . basename($f);
    $paths[] = $p;
    if (preg_match('/[\'"]POST[\'"]/', (string) file_get_contents($f))) {
        $writes[] = $p;
    }
}

foreach ($paths as $p) {
    $r = req('GET', BASE . $p, ['Host: evil.com']);
    check(refused($r), "$p refuses Host: evil.com (got {$r['status']})");
    body_clean($r, "$p evil host");

    $r = req('GET', BASE . $p, ['Host: 127.0.0.1:8787']);
    check(stripos($r['headers'], "\ncache-control: no-store") !== false, "$p carries Cache-Control: no-store");
    body_clean($r, "$p GET", $p === '/');
}

foreach ($writes as $p) {
    $tokenHdr = [TOKEN_HEADER . ': ' . $token];

    $r = req('GET', BASE . $p, array_merge(['Host: 127.0.0.1:8787', 'Origin: ' . GOOD_ORIGIN], $tokenHdr));
    check(refused($r), "$p refuses GET (got {$r['status']})");

    $r = req('POST', BASE . $p, ['Host: 127.0.0.1:8787', 'Origin: ' . GOOD_ORIGIN]);
    check(refused($r), "$p refuses POST without token (got {$r['status']})");
    body_clean($r, "$p no token");

    $r = req('POST', BASE . $p, array_merge(['Host: 127.0.0.1:8787', 'Origin: http://evil.com'], $tokenHdr));
    check(refused($r), "$p refuses bad Origin (got {$r['status']})");
    body_clean($r, "$p bad origin");
}

$inherited = ['/phpmyadmin/', '/cgi-bin/', '/php-cgi/', '/server-status', '/server-info', '/licenses/', '/webalizer/', '/icons/', '/examples/'];
foreach (array_merge(['/data/token', '/src/bootstrap.php', '/tests/run.php', '/.git/HEAD'], $inherited) as $p) {
    $r = req('GET', BASE . $p, ['Host: 127.0.0.1:8787']);
    check(in_array($r['status'], [403, 404], true), "8787 $p is 403/404 (got {$r['status']})");
    body_clean($r, "8787 $p");
}

foreach (PORT80 as $base) {
    foreach (['/_kc-dashboard/', '/_kc-dashboard/public/', '/_kc-dashboard/data/token'] as $p) {
        $r = req('GET', $base . $p, ['Host: localhost']);
        check($r['status'] === 403, "$base$p is 403 (got {$r['status']})");
        body_clean($r, "$base$p");
    }
}

echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
