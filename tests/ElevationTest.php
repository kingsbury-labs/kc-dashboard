<?php
require_once __DIR__ . '/../src/Sys/Elevation.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const ELEV_WHOAMI = 'C:\\fake\\whoami.exe';

function elev_with(string $fixture): array
{
    $fake = new FakeProcessRunner();
    $fake->willReturn(0, (string) file_get_contents(__DIR__ . '/fixtures/whoami/' . $fixture));
    return [Elevation::isElevated($fake, ELEV_WHOAMI), $fake];
}

test('High Mandatory Level sets the warning, through whoami /groups', function () {
    [$elevated, $fake] = elev_with('high.txt');
    assert_same(true, $elevated);
    assert_same([ELEV_WHOAMI, '/groups'], $fake->calls[0]['argv']);
});

test('the SID decides: System level and a German High label both warn', function () {
    assert_same(true, elev_with('system.txt')[0], 'S-1-16-16384');
    assert_same(true, elev_with('high-de.txt')[0], 'S-1-16-12288 without the English label');
});

test('Medium Mandatory Level does not', function () {
    assert_same(false, elev_with('medium.txt')[0]);
    $fake = new FakeProcessRunner();
    $fake->willReturn(0, "Mandatory Label\\High Mandatory Level   Label   S-1-16-8192\n");
    assert_same(false, Elevation::isElevated($fake, ELEV_WHOAMI), 'label text alone is not enough');
});

test('a missing whoami sets nothing and does not throw', function () {
    $missing = 'C:\\kc-no-such-folder\\whoami.exe';
    assert_same(false, Elevation::isElevated(new ProcessRunner(['whoami' => $missing]), $missing));
});

test('a failing whoami is not read as elevated', function () {
    $fake = new FakeProcessRunner();
    $fake->willReturn(1, (string) file_get_contents(__DIR__ . '/fixtures/whoami/high.txt'));
    assert_same(false, Elevation::isElevated($fake, ELEV_WHOAMI));
});

test('two page renders within a minute start whoami once', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcelev_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('3c', 32));
    $fake = new FakeProcessRunner();
    $high = (string) file_get_contents(__DIR__ . '/fixtures/whoami/high.txt');
    $fake->willReturn(0, $high);
    $fake->willReturn(0, $high);
    $clock = 1_000_000;
    $deps = ['elevated' => function () use ($dir, $fake, &$clock) {
        return Elevation::cached($dir . '/elevation.json', fn() => Elevation::isElevated($fake, ELEV_WHOAMI),
            function () use (&$clock) {
                return $clock;
            });
    }];
    $render = fn() => View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787',
        'REQUEST_METHOD' => 'GET'], $dir . '/token', new Log($dir . '/log'), $deps)[2];
    try {
        assert_true(str_contains($render(), 'class="banner"'), 'first render banner');
        $clock += 59;
        assert_true(str_contains($render(), 'class="banner"'), 'second render banner, from the cache');
        assert_same(1, count($fake->calls), 'whoami starts within a minute');
        $clock += 1;
        $render();
        assert_same(2, count($fake->calls), 'whoami starts again after a minute');
    } finally {
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
});

test('a cache stamped in the future or malformed is refreshed', function () {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcelevc_' . bin2hex(random_bytes(6)) . '.json';
    $runs = 0;
    $check = function () use (&$runs) {
        $runs++;
        return false;
    };
    try {
        file_put_contents($path, json_encode(['at' => 2_000_000, 'elevated' => true]));
        assert_same(false, Elevation::cached($path, $check, fn() => 1_000_000), 'future stamp');
        file_put_contents($path, '{"at":"soon","elevated":"yes"}');
        assert_same(false, Elevation::cached($path, $check, fn() => 1_000_000), 'malformed');
        assert_same(2, $runs);
    } finally {
        @unlink($path);
    }
});
