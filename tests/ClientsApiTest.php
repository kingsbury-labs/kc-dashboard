<?php
require_once __DIR__ . '/../src/View.php';

function capi_config(): array
{
    return ['dir' => __DIR__ . '/fixtures/clients', 'links' => [
        'pat-example' => [
            'site' => 'https://pat.example', 'ga4' => 'https://analytics.google.com/p1',
            'search_console' => 'https://search.google.com/search-console?resource_id=sc-domain:pat.example',
            'billing' => 'https://billing.example/pat', 'repo' => 'https://github.com/example/pat',
            'folder' => 'https://drive.example/pat',
        ],
        'birch-lane' => ['site' => 'javascript:alert(document.cookie)', 'repo' => 'https://github.com/example/birch'],
    ]];
}

function capi_get(string $host): array
{
    $log = new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccapi_' . bin2hex(random_bytes(4)) . '.log');
    return View::clients(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET'],
        capi_config(), $log);
}

test('foreign Host is refused with no client data', function () {
    [$status, , $body] = capi_get('evil.com');
    assert_same(403, $status);
    assert_true(!str_contains($body, 'pat-example') && !str_contains($body, 'Sample'), 'data leaked');
});

test('active clients and leads come back with every field and six link kinds', function () {
    [$status, $headers, $body] = capi_get('127.0.0.1:8787');
    assert_same(200, $status);
    assert_same('application/json', $headers['Content-Type']);
    $data = json_decode($body, true);
    $pat = array_values(array_filter($data['clients'], fn($c) => $c['slug'] === 'pat-example'))[0];
    foreach (['client', 'slug', 'phase', 'milestone', 'due', 'due_text', 'status', 'blocker', 'links'] as $k) {
        assert_true(array_key_exists($k, $pat), 'client field ' . $k);
    }
    assert_same(DashboardParser::LINK_KINDS, array_keys($pat['links']));
    assert_same(3, count($data['leads']));
    assert_same(['name', 'business', 'town', 'stage', 'price', 'next_action', 'notes'], array_keys($data['leads'][0]));
});

test('due dates soonest first, undated last in file order', function () {
    $data = json_decode(capi_get('localhost:8787')[2], true);
    assert_same(['fern-valley', 'pat-example', 'two-wet-dogs', 'birch-lane', 'sample-studio'],
        array_column($data['clients'], 'slug'));
});

test('a javascript: link is dropped, its sibling kept', function () {
    [, , $body] = capi_get('localhost:8787');
    assert_true(stripos($body, 'javascript:') === false, 'javascript: in body');
    $data = json_decode($body, true);
    $birch = array_values(array_filter($data['clients'], fn($c) => $c['slug'] === 'birch-lane'))[0];
    assert_same(['repo' => 'https://github.com/example/birch'], $birch['links']);
});
