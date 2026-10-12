<?php
require_once __DIR__ . '/../../src/Process/ProcessRunner.php';

// Records what would have been started and returns scripted results; starts nothing.
class FakeProcessRunner extends ProcessRunner
{
    /** @var array<int,array{argv:array,cwd:?string,timeout:int,stdin:?string}> */
    public array $calls = [];
    /** @var array<int,array{code:int,stdout:string,stderr:string,timedOut:bool}> */
    private array $results = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function willReturn(int $code = 0, string $stdout = '', string $stderr = '', bool $timedOut = false): void
    {
        $this->results[] = ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
    }

    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        $this->calls[] = ['argv' => $argv, 'cwd' => $cwdName, 'timeout' => $timeoutSeconds, 'stdin' => $stdin];
        return array_shift($this->results) ?? ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}
