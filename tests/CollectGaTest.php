<?php
require_once __DIR__ . '/../src/Process/ProcessRunner.php';

const CGA_SCRIPT = __DIR__ . '/../bin/collect_ga.py';
const CGA_FIXTURE = __DIR__ . '/fixtures/numbers/ga_raw.json';

// The script on a raw-responses file, through ProcessRunner as GaCollector runs it.
function cga_exec(string $fixture): array
{
    $exes = require __DIR__ . '/../config/executables.php';
    return ProcessRunner::fromConfig()->run([$exes['python'], '-I', realpath(CGA_SCRIPT), '--from-fixture', realpath($fixture)], null, 60);
}

// A fixture written for one test, from raw text, run, then removed.
function cga_exec_text(string $text): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccga_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($tmp, $text);
    try {
        return cga_exec($tmp);
    } finally {
        @unlink($tmp);
    }
}

function cga_run(string $fixture): array
{
    $res = cga_exec($fixture);
    assert_same(0, $res['code'], 'exit code, stderr: ' . $res['stderr']);
    $out = json_decode($res['stdout'], true);
    assert_true(is_array($out), 'stdout is not JSON: ' . substr($res['stdout'], 0, 200));
    return $out;
}

test('collect_ga.py --from-fixture gives the JSON keys for each property', function () {
    $out = cga_run(CGA_FIXTURE);
    assert_same(['window_days', 'properties'], array_keys($out));
    assert_same(28, $out['window_days']);
    assert_same(['kingsbury-creative', 'example-client'], array_column($out['properties'], 'slug'));
    foreach ($out['properties'] as $p) {
        assert_same(['slug', 'ga4_property', 'search_console_site', 'ga4', 'ga4_error', 'search_console', 'search_console_error'],
            array_keys($p), $p['slug']);
        assert_same(['sessions', 'users', 'active_users', 'top_pages'], array_keys($p['ga4']), $p['slug']);
        assert_same(['clicks', 'impressions', 'ctr', 'position'], array_keys($p['search_console']), $p['slug']);
        assert_same(null, $p['ga4_error']);
        assert_same(null, $p['search_console_error']);
    }
    $kc = $out['properties'][0];
    assert_same([1240, 903, 3], [$kc['ga4']['sessions'], $kc['ga4']['users'], $kc['ga4']['active_users']]);
    assert_same(['path' => '/', 'views' => 610], $kc['ga4']['top_pages'][0]);
    assert_same(3, count($kc['ga4']['top_pages']));
    assert_same([87, 4120, 0.0211, 18.4], array_values($kc['search_console']));
    assert_same(0, $out['properties'][1]['ga4']['active_users'], 'an empty realtime report is zero');
});

test('collect_ga.py: a 403 on one property is that property\'s error, and the other still reports', function () {
    $raw = json_decode((string) file_get_contents(CGA_FIXTURE), true);
    $raw['properties'][1]['ga4']['totals'] = ['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions']];
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccga_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($tmp, json_encode($raw));
    try {
        $out = cga_run($tmp);
    } finally {
        @unlink($tmp);
    }
    [$kc, $client] = $out['properties'];
    assert_same(null, $client['ga4']);
    assert_true(str_starts_with((string) $client['ga4_error'], '403 '), 'error: ' . var_export($client['ga4_error'], true));
    assert_same(12, $client['search_console']['clicks'], 'search console of the same property');
    assert_same(1240, $kc['ga4']['sessions'], 'the other property');
    assert_same(null, $kc['ga4_error']);
});

test('collect_ga.py: a failure prints only the exception type, never its message or a traceback', function () {
    $marker = 'kc-marker-' . bin2hex(random_bytes(4));
    $res = cga_exec_text('{"properties": [{"slug": "' . $marker . '", "ga4": ');
    assert_same(1, $res['code']);
    assert_same("collect_ga.py failed: JSONDecodeError\n", str_replace("\r\n", "\n", $res['stderr']));
    assert_true(!str_contains($res['stdout'] . $res['stderr'], $marker), 'fixture text in the output');
    // A failure past the parse, inside the transform: a property that is a string, not an object.
    $res = cga_exec_text('{"properties": ["' . $marker . '"]}');
    assert_same(1, $res['code']);
    assert_same("collect_ga.py failed: AttributeError\n", str_replace("\r\n", "\n", $res['stderr']));
    assert_true(!str_contains($res['stderr'], 'Traceback') && !str_contains($res['stderr'], $marker), 'message or traceback');
});

test('collect_ga.py: a NaN or infinity from the API is null, and the output stays valid JSON', function () {
    $raw = (string) file_get_contents(CGA_FIXTURE);
    $raw = str_replace('{"value": "1240"}', '{"value": "NaN"}', $raw);
    $raw = str_replace('"clicks": 87', '"clicks": NaN', $raw);
    $raw = str_replace('"position": 9.5', '"position": Infinity', $raw);
    $res = cga_exec_text($raw);
    assert_same(0, $res['code'], 'stderr: ' . $res['stderr']);
    assert_true(preg_match('/NaN|Infinity/', $res['stdout']) === 0, 'non-finite number in the output');
    $out = json_decode($res['stdout'], true, 512, JSON_THROW_ON_ERROR);
    [$kc, $client] = $out['properties'];
    assert_same(null, $kc['ga4']['sessions']);
    assert_same(903, $kc['ga4']['users'], 'the rest of the property');
    assert_same(null, $kc['search_console']['clicks']);
    assert_same(null, $client['search_console']['position']);
    assert_same(310, $client['ga4']['sessions'], 'the other property');
    assert_true(str_contains((string) file_get_contents(CGA_SCRIPT), 'allow_nan=False'), 'dump refuses NaN');
});

test('collect_ga.py holds the read-only scopes, no buffer, no --index, no write-mode open(, Google imports only live', function () {
    $src = (string) file_get_contents(CGA_SCRIPT);
    assert_true(str_contains($src, 'https://www.googleapis.com/auth/analytics.readonly'), 'analytics.readonly');
    assert_true(str_contains($src, 'https://www.googleapis.com/auth/webmasters.readonly'), 'webmasters.readonly');
    assert_true(stripos($src, 'buffer') === false, 'buffer');
    assert_true(!str_contains($src, '--index'), '--index');
    preg_match_all('/\bopen\s*\(([^)]*)\)/', $src, $opens);
    assert_true(count($opens[0]) > 0, 'no open( at all: the fixture read moved?');
    foreach ($opens[1] as $args) {
        assert_true(preg_match('/,\s*"r"\s*(,|$)/', $args) === 1, 'open( not in read mode: ' . $args);
    }
    foreach (['write_text', 'write_bytes', 'os.remove', 'os.rename', 'shutil', 'tempfile'] as $w) {
        assert_true(!str_contains($src, $w), $w);
    }
    assert_true(!str_contains($src, 'readwrite') && !str_contains($src, 'auth/analytics"') && !str_contains($src, 'auth/webmasters"'),
        'a scope wider than read-only');
    assert_true(preg_match('/^(from|import)\s+(google|googleapiclient)/m', $src) === 0, 'Google client imported at the top');
});
