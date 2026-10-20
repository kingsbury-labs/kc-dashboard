<?php
const EXT_DIR = __DIR__ . '/../chrome-extension';
const EXT_URL = 'http://127.0.0.1:8787/';

function ext_manifest(): array
{
    $m = json_decode((string) file_get_contents(EXT_DIR . '/manifest.json'), true);
    assert_true(is_array($m), 'manifest.json is valid JSON');
    return $m;
}

test('manifest is MV3 and overrides the new tab with a page that exists', function () {
    $m = ext_manifest();
    assert_same(3, $m['manifest_version'] ?? null, 'manifest_version');
    $page = $m['chrome_url_overrides']['newtab'] ?? null;
    assert_same('newtab.html', $page, 'chrome_url_overrides.newtab');
    assert_true(is_file(EXT_DIR . '/' . $page), 'the new tab page exists');
});

test('manifest asks for no permissions, hosts or content scripts', function () {
    $m = ext_manifest();
    foreach (['permissions', 'optional_permissions', 'host_permissions', 'optional_host_permissions',
        'content_scripts', 'background', 'externally_connectable', 'web_accessible_resources'] as $key) {
        assert_true(!array_key_exists($key, $m), 'manifest has ' . $key);
    }
});

test('newtab.js navigates to exactly the dashboard URL and nothing else', function () {
    $src = (string) file_get_contents(EXT_DIR . '/newtab.js');
    preg_match_all('#[a-z][a-z0-9+.-]*://[^\'"`\s)]*#i', $src, $m);
    assert_same([EXT_URL], $m[0], 'URLs in newtab.js');
    assert_true(str_contains($src, "location.replace('" . EXT_URL . "')"), 'navigates there');
    foreach (['fetch(', 'XMLHttpRequest', 'chrome.', 'eval(', 'innerHTML'] as $bad) {
        assert_true(!str_contains($src, $bad), 'newtab.js uses ' . $bad);
    }
});

test('newtab.html loads only newtab.js, with no inline script', function () {
    $html = (string) file_get_contents(EXT_DIR . '/newtab.html');
    preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER);
    assert_same(1, count($m), 'one script tag');
    assert_true(preg_match('#\bsrc="newtab\.js"#', $m[0][1]) === 1, 'it is newtab.js');
    assert_same('', trim($m[0][2]), 'no inline script');
    assert_true(preg_match('#https?://#i', $html) !== 1, 'no outside URL in the page');
});
