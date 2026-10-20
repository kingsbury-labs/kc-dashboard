<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// CPU, RAM and disk use, and whether llama-server is running, from one fixed PowerShell CIM query. No request
// data ever reaches its argv. The answer, a failed one included, is cached for cache_seconds in a file, so open
// tabs and the wall stream cost one query per window. A query that fails gives connected false, never an exception.
class SystemMonitor
{
    public const PS_COMMAND = '$c = (Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average;'
        . ' $o = Get-CimInstance Win32_OperatingSystem;'
        . ' $d = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID=\'C:\'";'
        . ' $l = @(Get-CimInstance Win32_Process -Filter "Name=\'llama-server.exe\'").Count;'
        . ' \'{0},{1},{2},{3},{4},{5}\' -f [int]$c, $o.TotalVisibleMemorySize, $o.FreePhysicalMemory, $d.Size, $d.FreeSpace, $l';

    private ProcessRunner $runner;
    private string $powershellExe;
    private string $cacheFile;
    private int $cacheSeconds;
    /** @var Closure(): int */
    private Closure $clock;

    public function __construct(ProcessRunner $runner, string $powershellExe, string $cacheFile, int $cacheSeconds = 10,
        ?Closure $clock = null)
    {
        $this->runner = $runner;
        $this->powershellExe = $powershellExe;
        $this->cacheFile = $cacheFile;
        $this->cacheSeconds = $cacheSeconds;
        $this->clock = $clock ?? fn(): int => time();
    }

    /** @return array{connected:bool,cpu:?int,ram:?int,disk:?int,llama:?bool,at:int} */
    public function read(): array
    {
        $now = ($this->clock)();
        $cached = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (is_array($cached) && is_int($cached['at'] ?? null) && $now >= $cached['at'] && $now - $cached['at'] < $this->cacheSeconds) {
            return $cached;
        }
        $data = $this->query() + ['at' => $now];
        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false && !@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
        }
        return $data;
    }

    private function query(): array
    {
        $none = ['connected' => false, 'cpu' => null, 'ram' => null, 'disk' => null, 'llama' => null];
        try {
            $r = $this->runner->run([$this->powershellExe, '-NoProfile', '-NonInteractive', '-Command', self::PS_COMMAND], null, 20);
        } catch (Throwable $e) {
            return $none;
        }
        if ($r['timedOut'] || $r['code'] !== 0
            || !preg_match('/^\s*(\d{1,3}),(\d{1,15}),(\d{1,15}),(\d{1,18}),(\d{1,18}),(\d{1,6})\s*$/m', $r['stdout'], $m)) {
            return $none;
        }
        $pct = fn(int $total, int $free): ?int => $total > 0 && $free <= $total ? (int) round(($total - $free) * 100 / $total) : null;
        return ['connected' => true, 'cpu' => min(100, (int) $m[1]), 'ram' => $pct((int) $m[2], (int) $m[3]),
            'disk' => $pct((int) $m[4], (int) $m[5]), 'llama' => (int) $m[6] > 0];
    }
}
