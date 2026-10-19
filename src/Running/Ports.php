<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// Listening TCP ports with the program and PID that own them. netstat gives port and PID, tasklist gives the
// image name. Neither call asks for a command line, so none can reach the output.
class Ports
{
    private ProcessRunner $runner;
    private string $netstatExe;
    private string $tasklistExe;

    public function __construct(ProcessRunner $runner, string $netstatExe, string $tasklistExe)
    {
        $this->runner = $runner;
        $this->netstatExe = $netstatExe;
        $this->tasklistExe = $tasklistExe;
    }

    /** @return array<int,array{port:int,program:?string,pid:int}> */
    public function read(): array
    {
        $listening = [];
        foreach (preg_split('/\r?\n/', $this->call([$this->netstatExe, '-ano', '-p', 'tcp'])) as $line) {
            if (preg_match('/^\s*TCP\s+\S*:(\d{1,5})\s+\S+\s+LISTENING\s+(\d{1,10})\s*$/i', $line, $m)) {
                $port = (int) $m[1];
                $pid = (int) $m[2];
                if ($port >= 1 && $port <= 65535) {
                    $listening[$port . ':' . $pid] = ['port' => $port, 'pid' => $pid];
                }
            }
        }
        if ($listening === []) {
            return [];
        }
        $images = [];
        foreach (preg_split('/\r?\n/', $this->call([$this->tasklistExe, '/fo', 'csv', '/nh'])) as $line) {
            $row = str_getcsv($line, ',', '"', '');
            if (count($row) >= 2 && preg_match('/^\d{1,10}$/', $row[1])) {
                // Console output is in the OEM code page, not UTF-8; printable ASCII only keeps json_encode working.
                $images[(int) $row[1]] = substr(preg_replace('/[^\x20-\x7e]+/', '?', $row[0]), 0, 100);
            }
        }
        $out = [];
        foreach ($listening as $l) {
            $out[] = ['port' => $l['port'], 'program' => $images[$l['pid']] ?? null, 'pid' => $l['pid']];
        }
        usort($out, fn($a, $b) => [$a['port'], $a['pid']] <=> [$b['port'], $b['pid']]);
        return $out;
    }

    private function call(array $argv): string
    {
        $r = $this->runner->run($argv, null, 15);
        if ($r['timedOut'] || $r['code'] !== 0) {
            throw new RuntimeException(basename($argv[0]) . ' failed');
        }
        return $r['stdout'];
    }
}
