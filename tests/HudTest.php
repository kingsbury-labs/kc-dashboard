<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

const HUD_ASSETS = __DIR__ . '/../public/assets';

function hud_page(array $deps = []): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kchud_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('4d', 32));
    try {
        [$status, , $body] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), $deps, strtotime('2026-10-19T12:00:00+00:00'));
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_same(200, $status);
    return $body;
}

test('orb.js exports setLevel and setState and handles motion, visibility and frames', function () {
    $src = (string) file_get_contents(HUD_ASSETS . '/orb.js');
    assert_true(preg_match('/export function setLevel\b/', $src) === 1, 'setLevel export');
    assert_true(preg_match('/export function setState\b/', $src) === 1, 'setState export');
    foreach (['prefers-reduced-motion', 'visibilitychange', 'requestAnimationFrame', 'cancelAnimationFrame'] as $want) {
        assert_true(str_contains($src, $want), "orb.js holds $want");
    }
    foreach (['idle', 'thinking', 'speaking'] as $s) {
        assert_true(str_contains($src, "'$s'"), "state $s");
    }
});

// True when every channel of a #rrggbb colour is below 0x30.
function hud_dark(string $hex): bool
{
    return preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) === 1
        && max(hexdec($m[1]), hexdec($m[2]), hexdec($m[3])) < 0x30;
}

test('hud.css keeps colours on :root, is always dark, no max-width query and no wide uppercase tracking', function () {
    $css = (string) file_get_contents(HUD_ASSETS . '/hud.css');
    assert_true(preg_match('/:root\s*\{[^}]*--accent:\s*#/', $css) === 1, 'tokens on :root');
    assert_true(preg_match('/prefers-color-scheme:\s*light/', $css) === 0, 'a light theme block');
    assert_true(preg_match('/:root\s*\{[^}]*--bg:\s*(#[0-9a-f]{6})/i', $css, $bg) === 1 && hud_dark($bg[1]), ':root background is dark');
    assert_true(preg_match('/\bbody\s*\{[^}]*background-color:\s*(#[0-9a-f]{6})/i', $css, $body) === 1 && hud_dark($body[1]), 'body background is dark');
    assert_true(preg_match('/@media[^{]*max-width/i', $css) === 0, 'max-width media query');
    assert_true(str_contains($css, 'min-width'), 'wide layout through min-width');
    preg_match_all('/\{([^{}]*)\}/', $css, $rules);
    foreach ($rules[1] as $body) {
        if (stripos($body, 'text-transform: uppercase') !== false && preg_match('/letter-spacing:\s*([\d.]+)em/', $body, $m)) {
            assert_true((float) $m[1] < 0.1, 'uppercase rule with letter-spacing ' . $m[1] . 'em');
        }
    }
    assert_true(preg_match('/#f3efe7/i', $css) === 0, 'cream colour');
});

test('the page has the orb canvas, the no-JS orb and the HUD stylesheet, and no comma in a heading', function () {
    $body = hud_page();
    assert_true(str_contains($body, '<canvas id="orb"'), 'canvas');
    assert_true(str_contains($body, 'id="orb-static"'), 'no-JS fallback element');
    assert_true(str_contains($body, '/assets/hud.css') && str_contains($body, '/assets/orb.js'), 'assets linked');
    assert_true(strpos($body, 'id="orb"') > strpos($body, 'id="core"') && strpos($body, 'id="orb"') < strpos($body, 'id="overview"'),
        'orb sits in the core panel');
    preg_match_all('#<h[1-6][^>]*>([^<]*)</h[1-6]>#', $body, $h);
    assert_true(count($h[1]) > 0, 'headings found');
    foreach ($h[1] as $heading) {
        assert_true(!str_contains($heading, ','), "comma in heading: $heading");
    }
});

test('the core scripts animate with frames, honour reduced motion and pause while hidden', function () {
    $src = (string) file_get_contents(HUD_ASSETS . '/orb.js') . (string) file_get_contents(HUD_ASSETS . '/core.js');
    foreach (['requestAnimationFrame', 'prefers-reduced-motion', 'visibilitychange'] as $want) {
        assert_true(str_contains($src, $want), "orb.js or core.js holds $want");
    }
    assert_true(str_contains((string) file_get_contents(HUD_ASSETS . '/orb.js'), 'export function setAgents'), 'agents on the globe');
});

test('app.css and wall.css have no light theme, no max-width query and no wide uppercase tracking', function () {
    foreach (['app.css', 'wall.css', 'hud.css'] as $name) {
        $css = (string) file_get_contents(HUD_ASSETS . '/' . $name);
        assert_true(preg_match('/prefers-color-scheme:\s*light/', $css) === 0, "$name light theme");
        assert_true(preg_match('/@media[^{]*max-width/i', $css) === 0, "$name max-width media query");
        preg_match_all('/\{([^{}]*)\}/', $css, $rules);
        foreach ($rules[1] as $body) {
            if (stripos($body, 'text-transform: uppercase') !== false && preg_match('/letter-spacing:\s*([\d.]+)em/', $body, $m)) {
                assert_true((float) $m[1] < 0.1, "$name uppercase rule with letter-spacing " . $m[1] . 'em');
            }
        }
        assert_true(preg_match('/#f3efe7/i', $css) === 0, "$name cream colour");
    }
});

test('DomSinks finds nothing in the assets, orb.js included', function () {
    assert_true(is_file(HUD_ASSETS . '/orb.js'), 'orb.js exists');
    assert_same([], DomSinks::find(HUD_ASSETS));
});
