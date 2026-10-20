<?php
require_once __DIR__ . '/../src/Running/SystemMonitor.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const SM_PS = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';

function sm_cache(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsm_' . bin2hex(random_bytes(6)) . '.json';
}

test('a CIM output fixture gives CPU, RAM and disk percentages and the local model state', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, (string) file_get_contents(__DIR__ . '/fixtures/running/cim.txt'));
    $cache = sm_cache();
    try {
        $r = (new SystemMonitor($runner, SM_PS, $cache, 10, fn(): int => 5000))->read();
        assert_same(['connected' => true, 'cpu' => 15, 'ram' => 54, 'disk' => 40, 'llama' => false, 'at' => 5000], $r);
        assert_same([SM_PS, '-NoProfile', '-NonInteractive', '-Command', SystemMonitor::PS_COMMAND], $runner->calls[0]['argv']);
        assert_true(str_contains(SystemMonitor::PS_COMMAND, 'Get-CimInstance'), 'a CIM query');
    } finally {
        @unlink($cache);
    }
});

test('a failing query gives not connected and no exception', function () {
    $cases = ['exit 1' => [1, '', false], 'timeout' => [0, '15,1,1,1,1,0', true], 'junk' => [0, 'Access denied', false]];
    foreach ($cases as $name => [$code, $out, $timedOut]) {
        $runner = new FakeProcessRunner();
        $runner->willReturn($code, $out, '', $timedOut);
        $cache = sm_cache();
        try {
            $r = (new SystemMonitor($runner, SM_PS, $cache, 10, fn(): int => 1))->read();
            assert_same(['connected' => false, 'cpu' => null, 'ram' => null, 'disk' => null, 'llama' => null, 'at' => 1], $r, $name);
        } finally {
            @unlink($cache);
        }
    }
    $throws = new class extends FakeProcessRunner {
        public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
        {
            throw new RuntimeException('not allowlisted');
        }
    };
    $cache = sm_cache();
    try {
        assert_same(false, (new SystemMonitor($throws, SM_PS, $cache))->read()['connected'], 'runner throws');
    } finally {
        @unlink($cache);
    }
});

test('two calls within 10 s on a fake clock run the query once', function () {
    $runner = new FakeProcessRunner();
    $fixture = (string) file_get_contents(__DIR__ . '/fixtures/running/cim.txt');
    $runner->willReturn(0, $fixture);
    $runner->willReturn(0, $fixture);
    $clock = 1000;
    $cache = sm_cache();
    try {
        $mon = new SystemMonitor($runner, SM_PS, $cache, 10, function () use (&$clock): int {
            return $clock;
        });
        $first = $mon->read();
        $clock = 1009;
        assert_same($first, $mon->read());
        assert_same(1, count($runner->calls), 'queried again inside the window');
        $clock = 1010;
        $mon->read();
        assert_same(2, count($runner->calls), 'a stale reading was served');
    } finally {
        @unlink($cache);
    }
});
