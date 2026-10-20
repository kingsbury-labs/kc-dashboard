<?php
// Runs every public/**/*.php under php-cgi, as Apache would, in a temp copy of the app whose executables are all
// stubs that drop a marker into data/. A new endpoint that answers a tokenless GET, a navigation or a POST
// without the token and Origin fails here without anyone having to list it. The pages in SWEEP_PAGES are the
// exceptions: a navigation must render them, so each has its own test below.

const SWEEP_CGI = 'C:\\xampp\\php\\php-cgi.exe';
define('SWEEP_TOKEN', str_repeat('5e', 32));
// ask.php opens and migrates the database before AskApi::ask runs its guard, so without one installed the refused
// request creates dashboard.sqlite. Moving that open behind the guard needs ask.php, which card 74a does not own.
const SWEEP_NEEDS_DB = ['api/ask.php'];
const SWEEP_PAGES = ['index.php', 'projects.php', 'wall.php', 'briefings.php'];
// Each case runs a second time with these removed, so a start the stub misses (below) throws and gives a 500.
const SWEEP_NO_PROCESS = '-ddisable_functions=proc_open,popen,exec,shell_exec,system,passthru,pcntl_exec';

function sweep_copy(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$from/$f") ? sweep_copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }
}

function sweep_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? sweep_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

// Every file and folder under $dir, recursively, with each file's hash.
function sweep_snapshot(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (is_dir("$dir/$f")) {
            $out[$prefix . $f . '/'] = 'dir';
            $out += sweep_snapshot("$dir/$f", $prefix . $f . '/');
        } else {
            $out[$prefix . $f] = md5_file("$dir/$f");
        }
    }
    ksort($out);
    return $out;
}

function sweep_link(string $from, string $to): void
{
    if (!@link($from, $to)) {
        copy($from, $to);
    }
}

// A temp copy of src, config and public, with config/executables.php pointing every name at a stub: php-cgi.exe
// under that name, in its own folder with a php.ini that loads a missing extension. The startup warning goes to
// data/started-<name>.log, then any script it is handed exits at once. A freshly compiled stub would be cleaner,
// but this machine refuses to run unsigned new .exe files (csc output: "Permission denied" from the second run on).
// php reads its ini only after its first option pass, so an argv such as -ano or -NoProfile exits before the
// warning; SWEEP_NO_PROCESS covers those.
function sweep_world(): string
{
    $app = dirname(__DIR__);
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsweep_' . bin2hex(random_bytes(6));
    foreach (['src', 'config', 'public'] as $d) {
        sweep_copy("$app/$d", "$t/$d");
    }
    mkdir("$t/stubs");
    file_put_contents("$t/stubs/exit.php", "<?php\nexit(0);\n");
    // Linked from our own copies: a link to XAMPP's php8ts.dll cannot be deleted while any PHP has it loaded.
    copy(SWEEP_CGI, "$t\\stubs\\php-cgi.exe");
    copy(dirname(SWEEP_CGI) . '\\php8ts.dll', "$t\\stubs\\php8ts.dll");
    $exes = [];
    foreach (array_keys(require "$app/config/executables.php") as $name) {
        mkdir("$t/stubs/$name");
        $exes[$name] = "$t\\stubs\\$name\\$name.exe";
        sweep_link("$t\\stubs\\php-cgi.exe", $exes[$name]);
        sweep_link("$t\\stubs\\php8ts.dll", "$t\\stubs\\$name\\php8ts.dll");
        file_put_contents("$t/stubs/$name/php.ini", "log_errors=1\ndisplay_errors=0\nextension=kc_sweep_marker_missing\n"
            . 'error_log="' . "$t\\data\\started-$name.log" . "\"\nauto_prepend_file=\"$t\\stubs\\exit.php\"\n");
    }
    file_put_contents("$t/config/executables.php", "<?php\nreturn " . var_export($exes, true) . ";\n");
    return $t;
}

// data/ as an installed dashboard has it: the token, which every case needs so that "with the token" means the
// real one, and the database only for the endpoints named in SWEEP_NEEDS_DB.
function sweep_reset_data(string $t, bool $db): void
{
    sweep_rmtree("$t/data");
    mkdir("$t/data");
    file_put_contents("$t/data/token", SWEEP_TOKEN);
    if ($db) {
        $pdo = Db::open("$t/data/dashboard.sqlite");
        Db::migrate($pdo);
        $pdo = null;
    }
}

// Every .php under public/, relative to it, forward slashes.
function sweep_files(string $public): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) === 'php') {
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($public) + 1));
        }
    }
    sort($out);
    return $out;
}

function sweep_request(string $script, string $method, array $headers, string $body = '', array $args = []): string
{
    $env = $headers + ['REDIRECT_STATUS' => '200', 'SCRIPT_FILENAME' => $script, 'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => $method];
    if ($method === 'POST') {
        $env += ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body)];
    }
    $proc = proc_open(array_merge([SWEEP_CGI], $args, [$script]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $env + getenv());
    assert_true(is_resource($proc), 'could not start php-cgi');
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out;
}

const SWEEP_CASES = [
    'GET no token' => ['GET', []],
    'GET cross-site navigate' => ['GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate',
        'HTTP_X_KC_TOKEN' => SWEEP_TOKEN]],
    'GET same-origin navigate' => ['GET', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate',
        'HTTP_X_KC_TOKEN' => SWEEP_TOKEN]],
    'POST no token' => ['POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
        'HTTP_ORIGIN' => 'http://127.0.0.1:8787']],
    'POST wrong Origin' => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'cors',
        'HTTP_ORIGIN' => 'http://evil.com', 'HTTP_X_KC_TOKEN' => SWEEP_TOKEN]],
    'POST cross-site navigate' => ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate',
        'HTTP_ORIGIN' => 'http://evil.com', 'HTTP_X_KC_TOKEN' => SWEEP_TOKEN]],
];

test('every endpoint under public/ refuses tokenless, navigated and foreign requests, and changes nothing', function () {
    $t = sweep_world();
    try {
        $files = array_values(array_diff(sweep_files("$t/public"), SWEEP_PAGES));
        assert_true(count($files) > 0, 'no endpoints found under public/');
        foreach ($files as $rel) {
            $script = str_replace('/', '\\', "$t/public/$rel");
            foreach (SWEEP_CASES as $name => [$method, $headers]) {
                foreach (['stubs' => [], 'no process functions' => [SWEEP_NO_PROCESS]] as $how => $args) {
                    $label = "$rel $name ($how)";
                    sweep_reset_data($t, in_array($rel, SWEEP_NEEDS_DB, true));
                    $before = sweep_snapshot("$t/data");
                    $out = sweep_request($script, $method, $headers, '{"question":"hi","project":""}', $args);
                    assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, $label . ': ' . substr($out, 0, 300));
                    assert_true(str_ends_with($out, '{"error":"forbidden"}'), $label . ' body: ' . substr($out, -200));
                    assert_same($before, sweep_snapshot("$t/data"), $label . ' changed data/');
                }
            }
        }
    } finally {
        sweep_rmtree($t);
    }
});

test('index.php is checkRead and starts nothing beyond the cached whoami', function () {
    $t = sweep_world();
    try {
        assert_true(in_array('index.php', sweep_files("$t/public"), true), 'index.php missing');
        $index = str_replace('/', '\\', "$t/public/index.php");
        sweep_reset_data($t, false);
        $before = sweep_snapshot("$t/data");
        $out = sweep_request($index, 'GET', ['HTTP_HOST' => 'evil.com']);
        assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, 'foreign Host: ' . substr($out, 0, 300));
        assert_same($before, sweep_snapshot("$t/data"), 'foreign Host changed data/');
        // The new tab is a navigation from the browser itself, and another site may navigate here too.
        $loads = [
            ['GET', ['HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_SEC_FETCH_MODE' => 'navigate']],
            ['GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate']],
            ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ORIGIN' => 'http://evil.com']],
        ];
        foreach ($loads as $i => [$method, $headers]) {
            $out = sweep_request($index, $method, $headers);
            assert_true(preg_match('/^Status: 200\b/mi', $out) === 1 || !preg_match('/^Status: /mi', $out), "load $i: "
                . substr($out, 0, 300));
            assert_true(str_contains($out, '<meta name="kc-token"'), "load $i renders the page");
        }
        $started = array_values(array_filter(array_keys(sweep_snapshot("$t/data")), fn($f) => str_starts_with($f, 'started-')));
        assert_same(['started-whoami.log'], $started, 'programs started');
        $lines = preg_split('/\R/', trim((string) file_get_contents("$t/data/started-whoami.log")));
        assert_same(1, count($lines), 'whoami once, from the cache after: ' . implode(' | ', $lines));
        $allowed = ['dashboard.sqlite', 'dashboard.sqlite-shm', 'dashboard.sqlite-wal', 'elevation.json', 'started-whoami.log', 'token'];
        assert_same([], array_values(array_diff(array_keys(sweep_snapshot("$t/data")), $allowed)), 'unexpected files in data/');
    } finally {
        sweep_rmtree($t);
    }
});

test('wall.php is checkRead and starts no program at all', function () {
    $t = sweep_world();
    try {
        assert_same(SWEEP_PAGES, array_values(array_intersect(SWEEP_PAGES, sweep_files("$t/public"))), 'a page is missing');
        $wall = str_replace('/', '\\', "$t/public/wall.php");
        sweep_reset_data($t, false);
        $before = sweep_snapshot("$t/data");
        $out = sweep_request($wall, 'GET', ['HTTP_HOST' => 'evil.com']);
        assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, 'foreign Host: ' . substr($out, 0, 300));
        assert_same($before, sweep_snapshot("$t/data"), 'foreign Host changed data/');
        $loads = [
            ['GET', ['HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_SEC_FETCH_MODE' => 'navigate']],
            ['GET', ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate']],
            ['GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate']],
            ['POST', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ORIGIN' => 'http://evil.com']],
        ];
        foreach ($loads as $i => [$method, $headers]) {
            foreach (['stubs' => [], 'no process functions' => [SWEEP_NO_PROCESS]] as $how => $args) {
                $out = sweep_request($wall, $method, $headers, '', $args);
                assert_true(preg_match('/^Status: 200\b/mi', $out) === 1 || !preg_match('/^Status: /mi', $out), "load $i ($how): "
                    . substr($out, 0, 300));
                assert_true(str_contains($out, '<body class="wall">'), "load $i ($how) renders the wall");
            }
        }
        $files = array_keys(sweep_snapshot("$t/data"));
        assert_same([], array_values(array_filter($files, fn($f) => str_starts_with($f, 'started-'))), 'programs started');
        $allowed = ['dashboard.sqlite', 'dashboard.sqlite-shm', 'dashboard.sqlite-wal', 'token'];
        assert_same([], array_values(array_diff($files, $allowed)), 'unexpected files in data/');
    } finally {
        sweep_rmtree($t);
    }
});

test('projects.php is checkRead and starts no program at all', function () {
    $t = sweep_world();
    try {
        $page = str_replace('/', '\\', "$t/public/projects.php");
        sweep_reset_data($t, false);
        $before = sweep_snapshot("$t/data");
        $out = sweep_request($page, 'GET', ['HTTP_HOST' => 'evil.com']);
        assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, 'foreign Host: ' . substr($out, 0, 300));
        assert_same($before, sweep_snapshot("$t/data"), 'foreign Host changed data/');
        foreach (['stubs' => [], 'no process functions' => [SWEEP_NO_PROCESS]] as $how => $args) {
            $out = sweep_request($page, 'GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'], '', $args);
            assert_true(preg_match('/^Status: 200\b/mi', $out) === 1 || !preg_match('/^Status: /mi', $out), "load ($how): " . substr($out, 0, 300));
            assert_true(str_contains($out, '<tbody id="projects">'), "load ($how) renders the map");
        }
        $files = array_keys(sweep_snapshot("$t/data"));
        assert_same([], array_values(array_filter($files, fn($f) => str_starts_with($f, 'started-'))), 'programs started');
        assert_same([], array_values(array_diff($files, ['dashboard.sqlite', 'dashboard.sqlite-shm', 'dashboard.sqlite-wal', 'token'])),
            'unexpected files in data/');
    } finally {
        sweep_rmtree($t);
    }
});

test('briefings.php is checkRead, starts no program and builds no briefing', function () {
    $t = sweep_world();
    try {
        $page = str_replace('/', '\\', "$t/public/briefings.php");
        sweep_reset_data($t, false);
        $before = sweep_snapshot("$t/data");
        $out = sweep_request($page, 'GET', ['HTTP_HOST' => 'evil.com']);
        assert_true(preg_match('/^Status: 403\b/mi', $out) === 1, 'foreign Host: ' . substr($out, 0, 300));
        assert_same($before, sweep_snapshot("$t/data"), 'foreign Host changed data/');
        foreach (['stubs' => [], 'no process functions' => [SWEEP_NO_PROCESS]] as $how => $args) {
            $out = sweep_request($page, 'GET', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'], '', $args);
            assert_true(preg_match('/^Status: 200\b/mi', $out) === 1 || !preg_match('/^Status: /mi', $out), "load ($how): " . substr($out, 0, 300));
            assert_true(str_contains($out, '<ol id="history" class="list">'), "load ($how) renders the history");
        }
        $files = array_keys(sweep_snapshot("$t/data"));
        assert_same([], array_values(array_diff($files, ['dashboard.sqlite', 'dashboard.sqlite-shm', 'dashboard.sqlite-wal', 'token'])),
            'unexpected files in data/');
    } finally {
        sweep_rmtree($t);
    }
});

test('app.js and clients.js send X-KC-Token on every fetch', function () {
    foreach (['app.js', 'clients.js'] as $name) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/public/assets/' . $name);
        preg_match_all('/\bfetch\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
        assert_true(count($m[0]) > 0, "$name has no fetch");
        foreach ($m[0] as [$match, $at]) {
            // The call's own argument list, up to its matching close paren.
            $depth = 0;
            for ($i = $at + strlen($match) - 1; $i < strlen($src); $i++) {
                $depth += $src[$i] === '(' ? 1 : ($src[$i] === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $call = substr($src, $at, $i - $at + 1);
            assert_true(str_contains($call, "'X-KC-Token': token"), "$name: fetch without the token: $call");
        }
    }
});
