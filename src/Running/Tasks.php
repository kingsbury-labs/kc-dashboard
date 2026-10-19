<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// The dashboard's own scheduled tasks (one Task Scheduler folder) from `schtasks /query /fo csv /v`. Columns are
// found by header name, and only the few below are kept: "Task To Run" holds a command line and never leaves here.
class Tasks
{
    private const COLUMNS = ['TaskName', 'Status', 'Last Run Time', 'Last Result', 'Next Run Time'];
    // Last Result codes that are not failures: success, running now, never run yet.
    private const NOT_FAILED = ['0', '267009', '267011'];

    private ProcessRunner $runner;
    private string $schtasksExe;
    private string $folder;

    public function __construct(ProcessRunner $runner, string $schtasksExe, string $folder)
    {
        $this->runner = $runner;
        $this->schtasksExe = $schtasksExe;
        $this->folder = '\\' . trim($folder, '\\') . '\\';
    }

    /** @return array<int,array{name:string,status:?string,lastRun:?string,lastResult:?string,nextRun:?string,failed:bool}> */
    public function read(): array
    {
        $r = $this->runner->run([$this->schtasksExe, '/query', '/fo', 'csv', '/v'], null, 20);
        if ($r['timedOut'] || $r['code'] !== 0) {
            throw new RuntimeException('schtasks failed');
        }
        // Parsed as a stream, not split on newlines first: a quoted field (a task's description) may hold one.
        $csv = fopen('php://temp', 'r+');
        fwrite($csv, $r['stdout']);
        rewind($csv);
        $index = null;
        $width = 0;
        $out = [];
        // No escape character: a Windows path ending in a backslash ("D:\x\") must not swallow the quote.
        while (($row = fgetcsv($csv, 0, ',', '"', '')) !== false) {
            // The header repeats once per Task Scheduler folder. Only its first two cells identify it, so a
            // field that merely says TaskName cannot redefine the columns.
            if (($row[0] ?? null) === 'HostName' && ($row[1] ?? null) === 'TaskName') {
                $index = array_flip($row);
                $width = count($row);
                continue;
            }
            if ($index === null || count($row) !== $width || array_diff(self::COLUMNS, array_keys($index)) !== []) {
                continue;
            }
            $name = $row[$index['TaskName']] ?? '';
            if (strncasecmp($name, $this->folder, strlen($this->folder)) !== 0) {
                continue;
            }
            $result = self::text($row[$index['Last Result']] ?? null);
            $out[] = [
                'name' => self::text(substr($name, strlen($this->folder))) ?? '',
                'status' => self::text($row[$index['Status']] ?? null),
                'lastRun' => self::text($row[$index['Last Run Time']] ?? null),
                'lastResult' => $result,
                'nextRun' => self::text($row[$index['Next Run Time']] ?? null),
                'failed' => $result !== null && !in_array($result, self::NOT_FAILED, true),
            ];
        }
        fclose($csv);
        return $out;
    }

    // Console output is in the OEM code page, not UTF-8; printable ASCII only keeps json_encode working.
    private static function text(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        return substr(preg_replace('/[^\x20-\x7e]+/', '?', trim($v)), 0, 200);
    }
}
