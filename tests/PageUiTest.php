<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

const PAGEUI_ASSETS = __DIR__ . '/../public/assets';

function pageui_render(array $deps): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpageui_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('9f', 32));
    [$status, , $body] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
        $dir . '/token', new Log($dir . '/log'), $deps);
    assert_same(200, $status);
    @unlink($dir . '/token');
    @unlink($dir . '/log');
    @rmdir($dir);
    return $body;
}

test('picker options come from ProjectList', function () {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpick_' . bin2hex(random_bytes(6));
    foreach (['', '\\alpha', '\\beta-2', '\\.hidden'] as $d) {
        mkdir($root . $d);
    }
    try {
        $body = pageui_render(['projects' => new ProjectList($root)]);
        preg_match_all('/<option value="([^"]*)">/', $body, $m);
        assert_same(['', 'alpha', 'beta-2'], $m[1]);
    } finally {
        foreach (['\\alpha', '\\beta-2', '\\.hidden', ''] as $d) {
            @rmdir($root . $d);
        }
    }
});

test('an answer holding <script> renders escaped', function () {
    $body = pageui_render(['answers' => fn() => [
        ['id' => 1, 'project' => '"><script>p()</script>', 'question' => '<img src=x onerror=q()>', 'status' => 'done',
            'answer' => '<script>alert(1)</script>', 'error' => null],
    ]]);
    assert_true(str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'escaped answer');
    foreach (['<script>alert', '<script>p()', '<img src=x'] as $raw) {
        assert_true(!str_contains($body, $raw), 'raw markup: ' . $raw);
    }
});

test('clients render with their links, and a bad link never reaches the page', function () {
    $body = pageui_render(['clients' => fn() => ['clients' => [
        ['client' => 'A <b>', 'slug' => 'a', 'phase' => 'Design', 'milestone' => 'm', 'due' => null, 'due_text' => 'TBD',
            'status' => 's', 'blocker' => 'b', 'links' => ['site' => 'https://a.example', 'repo' => 'javascript:x()']],
    ], 'leads' => [['name' => 'N', 'business' => 'Biz', 'town' => 'T', 'stage' => 'Lead', 'price' => '$1',
        'next_action' => 'Call', 'notes' => 'x']]]]);
    assert_true(str_contains($body, 'href="https://a.example"'), 'site link');
    assert_true(!str_contains($body, 'javascript:'), 'javascript link');
    assert_true(str_contains($body, 'A &lt;b&gt;') && str_contains($body, 'Biz'), 'client and lead');
});

test('the elevated banner shows only when elevated', function () {
    assert_true(str_contains(pageui_render(['elevated' => fn() => true]), 'class="banner"'), 'banner when elevated');
    assert_true(!str_contains(pageui_render(['elevated' => fn() => false]), 'class="banner"'), 'banner when not');
    assert_true(!str_contains(pageui_render(['elevated' => function () {
        throw new RuntimeException('whoami broke');
    }]), 'class="banner"'), 'banner when the check throws');
});

test('no JS file in public/assets uses a markup or code sink', function () {
    $dirs = [PAGEUI_ASSETS];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PAGEUI_ASSETS, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST) as $f) {
        if ($f->isDir()) {
            $dirs[] = $f->getPathname();
        }
    }
    $found = 0;
    foreach ($dirs as $dir) {
        $found += count(glob($dir . '/*.js') ?: []);
        assert_same([], DomSinks::find($dir));
    }
    assert_true($found > 0, 'no JS files found under public/assets');
    assert_true(str_contains((string) file_get_contents(PAGEUI_ASSETS . '/app.js'), 'textContent'), 'uses textContent');
});

test('DomSinks finds each sink it lists', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsinks_' . bin2hex(random_bytes(6));
    mkdir($dir);
    try {
        foreach (DomSinks::SINKS as $i => $sink) {
            file_put_contents($dir . "/s$i.js", "x.$sink");
        }
        $planted = ['f1' => 'const f = Function("a", s);', 'f2' => 'window.Function (s)();',
            't1' => "setTimeout('go()', 10);", 't2' => 'setInterval(`go()`, 10);', 't3' => 'setTimeout( code, 5);'];
        foreach ($planted as $name => $src) {
            file_put_contents("$dir/$name.js", $src);
        }
        $hits = DomSinks::find($dir);
        assert_same(count(DomSinks::SINKS) + count($planted), count($hits), implode(', ', $hits));
        foreach (glob($dir . '/*.js') as $f) {
            unlink($f);
        }
        file_put_contents("$dir/ok.js", "setTimeout(() => go(), 1); setInterval(async () => go(), 1);\n"
            . "setTimeout(function () {}, 1); const myFunction = () => 1; myFunction();");
        assert_same([], DomSinks::find($dir), 'function literals and names ending in Function');
    } finally {
        array_map('unlink', glob($dir . '/*.js'));
        rmdir($dir);
    }
});

test('app.css is mobile first: no max-width media query, a dark variant on :root', function () {
    $css = (string) file_get_contents(PAGEUI_ASSETS . '/app.css');
    assert_true(preg_match('/@media[^{]*max-width/i', $css) === 0, 'max-width media query');
    assert_true(preg_match('/@media[^{]*min-width/i', $css) === 1, 'min-width layer');
    assert_true(preg_match('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root/i', $css) === 1, 'dark tokens');
});

test('with JS off the search works and the ask form says it needs JS', function () {
    $body = pageui_render([]);
    assert_true(str_contains($body, '<noscript>'), 'noscript note');
    assert_true(preg_match('/<button type="submit" disabled>/', $body) === 1, 'ask button off until JS');
    assert_true(preg_match('/<script(?![^>]*type="module")/', $body) === 0, 'only module scripts');
});
