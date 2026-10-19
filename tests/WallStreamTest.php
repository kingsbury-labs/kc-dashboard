<?php
// Runs public/api/wall-stream.php in a child php-cgi, as Apache would, in a temp copy of the app. php-cgi rather
// than php.exe so the Status line shows. Every process function is disabled, so the scan's sources fail soft and
// nothing is started; the projects and sessions config point at empty temp folders.

const WS_CGI = 'C:\\xampp\\php\\php-cgi.exe';
define('WS_TOKEN', str_repeat('7a', 32));
const WS_NO_PROCESS = '-ddisable_functions=proc_open,popen,exec,shell_exec,system,passthru,pcntl_exec';

function ws_copy(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$from/$f") ? ws_copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }
}

function ws_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? ws_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

function ws_world(): string
{
    $app = dirname(__DIR__);
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcws_' . bin2hex(random_bytes(6));
    foreach (['src', 'config', 'public'] as $d) {
        ws_copy("$app/$d", "$t/$d");
    }
    foreach (['data', 'sessions', 'htdocs', 'home'] as $d) {
        mkdir("$t/$d");
    }
    file_put_contents("$t/data/token", WS_TOKEN);
    file_put_contents("$t/config/running.php", "<?php\nreturn " . var_export(['sessions_dir' => "$t\\sessions",
        'task_folder' => '\\KC Dashboard\\', 'aliases' => [], 'cache_seconds' => 3], true) . ";\n");
    file_put_contents("$t/config/projects.php", "<?php\nreturn " . var_export(['root' => "$t\\htdocs", 'home' => "$t\\home",
        'extra' => []], true) . ";\n");
    return $t;
}

/** @return array{0:string,1:float} output and seconds taken */
function ws_request(string $t, string $method, array $headers, string $query = ''): array
{
    $script = "$t\\public\\api\\wall-stream.php";
    $env = $headers + ['REDIRECT_STATUS' => '200', 'SCRIPT_FILENAME' => $script, 'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => $method,
        'QUERY_STRING' => $query, 'KC_WALL_SECONDS' => '2'];
    if ($method === 'POST') {
        $env += ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '2'];
    }
    $start = microtime(true);
    $proc = proc_open([WS_CGI, WS_NO_PROCESS, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, $env + getenv());
    assert_true(is_resource($proc), 'could not start php-cgi');
    fwrite($pipes[0], $method === 'POST' ? '{}' : '');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$out, microtime(true) - $start];
}

const WS_FETCH = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors'];

function ws_ticket(string $t): string
{
    [$out] = ws_request($t, 'POST', WS_FETCH + ['HTTP_ORIGIN' => 'http://127.0.0.1:8787', 'HTTP_X_KC_TOKEN' => WS_TOKEN]);
    assert_true(preg_match('/"ticket":"([0-9a-f]{64})"/', $out, $m) === 1, 'no ticket: ' . substr($out, 0, 300));
    return $m[1];
}

function ws_refused(string $out, string $label): void
{
    assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, $label . ': ' . substr($out, 0, 300));
    assert_true(!str_contains($out, 'event:') && !str_contains($out, 'retry:'), $label . ' streamed');
}

test('a ticket opens a stream with retry and an event: now block that ends inside its run length', function () {
    $t = ws_world();
    try {
        [$out, $took] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . ws_ticket($t));
        assert_true(preg_match('/^Status: 403\b/mi', $out) === 0, 'refused: ' . substr($out, 0, 300));
        assert_true(preg_match('#^Content-type: text/event-stream#mi', $out) === 1, 'content type: ' . substr($out, 0, 300));
        assert_true(preg_match('/^retry: 3000$/m', $out) === 1, 'retry line');
        assert_true(preg_match('/^event: now\ndata: (\{.*\})$/m', $out, $m) === 1, 'event: now block: ' . substr($out, -400));
        $data = json_decode($m[1], true);
        assert_true(is_array($data) && array_key_exists('sessions', $data) && array_key_exists('ports', $data), 'payload: ' . $m[1]);
        assert_true($took >= 1.5, "held the stream for its run length: $took s");
        assert_true($took < 8, "ended inside the limit: $took s");
    } finally {
        ws_rmtree($t);
    }
});

test('a ticket works once', function () {
    $t = ws_world();
    try {
        $ticket = ws_ticket($t);
        [$first] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . $ticket);
        assert_true(str_contains($first, 'event: now'), 'first use streams');
        [$again] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . $ticket);
        ws_refused($again, 'second use');
    } finally {
        ws_rmtree($t);
    }
});

test('a foreign Host gets 403 and no stream, and does not spend the ticket', function () {
    $t = ws_world();
    try {
        $ticket = ws_ticket($t);
        [$out] = ws_request($t, 'GET', WS_FETCH + ['HTTP_HOST' => 'evil.com'], 'ticket=' . $ticket);
        ws_refused($out, 'foreign Host');
        [$out] = ws_request($t, 'GET', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate'], 'ticket=' . $ticket);
        ws_refused($out, 'navigation');
        [$out] = ws_request($t, 'GET', ['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_SEC_FETCH_MODE' => 'cors'], 'ticket=' . $ticket);
        ws_refused($out, 'another localhost port');
        [$out] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . $ticket);
        assert_true(str_contains($out, 'event: now'), 'ticket still good after the refusals');
    } finally {
        ws_rmtree($t);
    }
});

test('no ticket, a made-up ticket and an expired ticket are refused', function () {
    $t = ws_world();
    try {
        [$out] = ws_request($t, 'GET', WS_FETCH);
        ws_refused($out, 'no ticket');
        [$out] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . str_repeat('ab', 32));
        ws_refused($out, 'made-up ticket');
        [$out] = ws_request($t, 'GET', WS_FETCH + ['HTTP_X_KC_TOKEN' => WS_TOKEN]);
        ws_refused($out, 'the token header in place of a ticket');
        $old = str_repeat('cd', 32);
        file_put_contents("$t/data/wall-tickets.json", json_encode([hash('sha256', $old) => time() - 1]));
        [$out] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . $old);
        ws_refused($out, 'expired ticket');
    } finally {
        ws_rmtree($t);
    }
});

// flock itself cannot be made to fail here; a ticket file that cannot be opened takes the same refusal.
test('an unusable ticket file gives 503 and no ticket and no stream', function () {
    $t = ws_world();
    try {
        mkdir("$t/data/wall-tickets.json");
        [$out] = ws_request($t, 'POST', WS_FETCH + ['HTTP_ORIGIN' => 'http://127.0.0.1:8787', 'HTTP_X_KC_TOKEN' => WS_TOKEN]);
        assert_true(preg_match('/^Status: 503\b/mi', $out) === 1 && !str_contains($out, '"ticket"'), 'mint: ' . substr($out, 0, 300));
        [$out] = ws_request($t, 'GET', WS_FETCH, 'ticket=' . str_repeat('ab', 32));
        assert_true(preg_match('/^Status: 503\b/mi', $out) === 1, 'stream: ' . substr($out, 0, 300));
        assert_true(!str_contains($out, 'event:'), 'streamed without the ticket file');
    } finally {
        ws_rmtree($t);
    }
});

test('a ticket needs the token and the Origin, and a refused POST stores nothing', function () {
    $t = ws_world();
    try {
        [$out] = ws_request($t, 'POST', WS_FETCH + ['HTTP_ORIGIN' => 'http://127.0.0.1:8787']);
        ws_refused($out, 'no token');
        [$out] = ws_request($t, 'POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_ORIGIN' => 'http://evil.com', 'HTTP_X_KC_TOKEN' => WS_TOKEN]);
        ws_refused($out, 'foreign Origin');
        assert_true(!is_file("$t/data/wall-tickets.json"), 'ticket file created by a refused POST');
        assert_true(!str_contains($out, 'ticket'), 'ticket in a refusal');
    } finally {
        ws_rmtree($t);
    }
});
