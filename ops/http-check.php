<?php
// Post-install HTTP check (card 07). Real requests with curl; not part of tests/run.php.
// Usage: php ops/http-check.php    Exit 0 when every check passes.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

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
        $body = preg_replace('/<meta\b[^>]*' . preg_quote($token, '/') . '[^>]*>/i', '', $body, 1);
    }
    check(strpos($body, $token) === false, "$label: body does not contain the token");
    check(strpos($r['body'], '#0 ') === false, "$label: body has no stack frames");
}

// Every endpoint that changes state or starts a job. Named, not grepped: the handlers live in src/, so the
// entry files in public/api/ never mention POST. A new write endpoint is added here.
const WRITES = ['/api/ask.php', '/api/feedback.php'];
// Pages render on a navigation and carry the token in one <meta> tag. The stream is checked on its own below.
const PAGES = ['/', '/wall.php', '/projects.php'];
const STREAM = '/api/wall-stream.php';

$apiFiles = glob($root . '/public/api/*.php') ?: [];
$paths = PAGES;
foreach ($apiFiles as $f) {
    $paths[] = '/api/' . basename($f);
}
$writes = array_values(array_intersect(WRITES, $paths));
if ($writes === [] || count($writes) !== count(WRITES)) {
    echo "FAIL write endpoint list does not match public/api/; nothing checked\n";
    exit(1);
}

foreach ($paths as $p) {
    $r = req('GET', BASE . $p, ['Host: evil.com']);
    check(refused($r), "$p refuses Host: evil.com (got {$r['status']})");
    body_clean($r, "$p evil host");

    $r = req('GET', BASE . $p, ['Host: 127.0.0.1:8787']);
    check(stripos($r['headers'], "\ncache-control: no-store") !== false, "$p carries Cache-Control: no-store");
    body_clean($r, "$p GET", in_array($p, PAGES, true));
}

$r = req('GET', BASE . '/wall.php', ['Host: 127.0.0.1:8787', 'Sec-Fetch-Site: none', 'Sec-Fetch-Mode: navigate']);
check($r['status'] === 200 && strpos($r['body'], '<body class="wall">') !== false,
    "/wall.php renders on a navigation (got {$r['status']})");
body_clean($r, '/wall.php navigation', true);

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

// Every other endpoint is a read, and a read needs the token too and must not be a navigation (card 74a).
$reads = array_values(array_diff($paths, PAGES, WRITES, [STREAM]));
if (!in_array('/api/answers.php', $reads, true)) {
    echo "FAIL /api/answers.php is not among the read endpoints; nothing checked\n";
    exit(1);
}
foreach ($reads as $p) {
    $tokenHdr = [TOKEN_HEADER . ': ' . $token];

    $r = req('GET', BASE . $p, ['Host: 127.0.0.1:8787']);
    check($r['status'] === 403, "$p refuses GET without token (got {$r['status']})");
    body_clean($r, "$p no token");

    $r = req('GET', BASE . $p, array_merge(['Host: 127.0.0.1:8787', 'Sec-Fetch-Site: cross-site',
        'Sec-Fetch-Mode: navigate'], $tokenHdr));
    check($r['status'] === 403, "$p refuses a navigation with the token (got {$r['status']})");

    $r = req('GET', BASE . $p, array_merge(['Host: 127.0.0.1:8787', 'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Mode: cors'], $tokenHdr));
    check($r['status'] === 200, "$p answers a same-origin fetch with the token (got {$r['status']})");
    body_clean($r, "$p with token");
}

// The wall stream (card 77): a one-use ticket from a POST with the token, then a GET with it. Neither the token
// nor a ticket is printed.
// Reads at most $cap bytes of the stream, stopping at the first event, so a 55-second stream costs a moment.
function stream_req(string $url, array $headers, int $cap = 8192): array
{
    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, $cap): int {
            $buf .= $chunk;
            return strpos($buf, "event: now\ndata: ") !== false || strlen($buf) >= $cap ? -1 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['status' => $status, 'type' => $type, 'body' => $buf];
}

if (!in_array(STREAM, $paths, true)) {
    check(false, STREAM . ' is missing from public/api/');
} else {
    $s = STREAM;
    $fetch = ['Host: 127.0.0.1:8787', 'Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: cors'];
    $r = req('GET', BASE . $s, $fetch);
    check($r['status'] === 403, "$s refuses GET without a ticket (got {$r['status']})");
    body_clean($r, "$s no ticket");

    $r = req('POST', BASE . $s, array_merge($fetch, ['Origin: ' . GOOD_ORIGIN]));
    check($r['status'] === 403 && strpos($r['body'], 'ticket') === false, "$s refuses POST without the token (got {$r['status']})");
    body_clean($r, "$s POST no token");

    $r = req('POST', BASE . $s, array_merge($fetch, ['Origin: ' . GOOD_ORIGIN, TOKEN_HEADER . ': ' . $token]));
    $ticket = preg_match('/^\{"ticket":"([0-9a-f]{64})"\}$/', $r['body'], $m) === 1 ? $m[1] : '';
    check($r['status'] === 200 && $ticket !== '', "$s gives a ticket for a POST with the token (got {$r['status']})");
    body_clean($r, "$s ticket");

    if ($ticket !== '') {
        $r = stream_req(BASE . $s . '?ticket=' . $ticket, $fetch);
        check($r['status'] === 200 && stripos($r['type'], 'text/event-stream') === 0,
            "$s streams for the ticket (got {$r['status']})");
        check(strpos($r['body'], "event: now\ndata: ") !== false, "$s sends an event: now block");
        check(strpos($r['body'], $token) === false, "$s stream does not contain the token");

        $r = req('GET', BASE . $s . '?ticket=' . $ticket, $fetch);
        check($r['status'] === 403, "$s refuses the same ticket twice (got {$r['status']})");
        check(strpos($r['body'], $ticket) === false, "$s refusal does not echo the ticket");
    }
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
