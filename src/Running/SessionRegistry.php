<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// Live Claude Code sessions from the `<pid>.json` files Claude Code keeps in its sessions folder. The format is
// undocumented and changes between versions, so every field is checked and a file that does not parse is skipped.
// The folder also holds `.key` files: only names matching FILE are ever opened, and $opened records each one.
class SessionRegistry
{
    // \z, not $: `$` also matches before a trailing newline.
    public const FILE = '/^(\d{1,10})\.json\z/';
    public const STATUSES = ['busy', 'idle', 'waiting'];
    public const MAX_FILE = 200000;
    public const MAX_TEXT = 300;
    // A session whose process started more than this far from the file's procStart is a reused PID.
    public const START_SLACK_SECONDS = 2;
    // Two clocks Claude Code writes procStart in, both 100ns units: .NET ticks of CreationDate (procStart) and
    // a UTC FILETIME (procStartFt). The PowerShell query prints both, so each is compared with its own kind.
    private const PS_COMMAND = 'Get-CimInstance Win32_Process -Property ProcessId,CreationDate'
        . ' | Where-Object { $_.CreationDate } | ForEach-Object { \'{0},{1},{2}\' -f $_.ProcessId,'
        . ' $_.CreationDate.Ticks, $_.CreationDate.ToFileTimeUtc() }';

    /** @var string[] every path this reader opened */
    public array $opened = [];

    private string $dir;
    private ProcessRunner $runner;
    private string $powershellExe;
    /** @var array<string,string> slug => folder */
    private array $projects;
    /** @var array<string,string> cwd prefix => replacement */
    private array $aliases;

    public function __construct(string $dir, ProcessRunner $runner, string $powershellExe, array $projects, array $aliases = [])
    {
        $this->dir = rtrim($dir, '\\');
        $this->runner = $runner;
        $this->powershellExe = $powershellExe;
        $this->projects = $projects;
        $this->aliases = $aliases;
    }

    /** @return array<int,array{pid:int,cwd:string,project:?string,status:?string,waitingFor:?string,name:?string,startedAt:?int,updatedAt:?int}> */
    public function read(): array
    {
        $records = [];
        foreach (@scandir($this->dir) ?: [] as $file) {
            if (!preg_match(self::FILE, $file, $m)) {
                continue;
            }
            $record = $this->record($this->dir . '\\' . $file, (int) $m[1]);
            if ($record !== null) {
                $records[] = $record;
            }
        }
        if ($records === []) {
            return [];
        }
        $starts = $this->processStarts();
        $out = [];
        foreach ($records as [$session, $start]) {
            $live = $starts[$session['pid']] ?? null;
            if ($live === null || !self::sameStart($start, $live)) {
                continue;
            }
            $out[] = $session;
        }
        usort($out, fn($a, $b) => $a['pid'] <=> $b['pid']);
        return $out;
    }

    // One session file, or null when it is not a regular file, does not parse, or names another PID.
    private function record(string $path, int $pid): ?array
    {
        clearstatcache(true);
        $lst = @lstat($path);
        if ($pid < 1 || $lst === false || ($lst['mode'] & 0170000) !== 0100000 || $lst['size'] > self::MAX_FILE) {
            return null;
        }
        $this->opened[] = $path;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_string($data['cwd'] ?? null) || (isset($data['pid']) && $data['pid'] !== $pid)) {
            return null;
        }
        $start = ['ticks' => self::digits($data['procStart'] ?? null), 'ft' => self::digits($data['procStartFt'] ?? null)];
        if ($start['ticks'] === null && $start['ft'] === null) {
            return null;
        }
        $cwd = self::text($data['cwd']) ?? '';
        $status = $data['status'] ?? null;
        return [[
            'pid' => $pid,
            'cwd' => $cwd,
            'project' => $this->project($cwd),
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
            'waitingFor' => self::text($data['waitingFor'] ?? null),
            'name' => self::text($data['name'] ?? null),
            'startedAt' => self::millis($data['startedAt'] ?? null),
            'updatedAt' => self::millis($data['updatedAt'] ?? null),
        ], $start];
    }

    /** @return array<int,array{ticks:int,ft:int}> live PID => its start time on both clocks */
    private function processStarts(): array
    {
        $r = $this->runner->run([$this->powershellExe, '-NoProfile', '-NonInteractive', '-Command', self::PS_COMMAND], null, 20);
        if ($r['timedOut'] || $r['code'] !== 0) {
            throw new RuntimeException('process start lookup failed');
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $r['stdout']) as $line) {
            if (preg_match('/^\s*(\d{1,10}),(\d{1,18}),(\d{1,18})\s*$/', $line, $m)) {
                $out[(int) $m[1]] = ['ticks' => (int) $m[2], 'ft' => (int) $m[3]];
            }
        }
        return $out;
    }

    private static function sameStart(array $file, array $live): bool
    {
        $slack = self::START_SLACK_SECONDS * 10000000;
        foreach (['ticks', 'ft'] as $kind) {
            if ($file[$kind] !== null) {
                return abs($file[$kind] - $live[$kind]) <= $slack;
            }
        }
        return false;
    }

    // The project whose folder holds cwd, by string only: nothing on disk is followed.
    private function project(string $cwd): ?string
    {
        $cwd = str_replace('/', '\\', $cwd);
        foreach ($this->aliases as $from => $to) {
            $from = rtrim($from, '\\');
            if (strncasecmp($cwd, $from . '\\', strlen($from) + 1) === 0) {
                $cwd = rtrim($to, '\\') . substr($cwd, strlen($from));
                break;
            }
        }
        $cwd = rtrim($cwd, '\\') . '\\';
        foreach ($this->projects as $slug => $path) {
            $path = rtrim($path, '\\') . '\\';
            if (strncasecmp($cwd, $path, strlen($path)) === 0) {
                return $slug;
            }
        }
        return null;
    }

    // 18 digits at most, so the value fits in a PHP int (ticks today are 18 digits long).
    private static function digits($v): ?int
    {
        return is_string($v) && preg_match('/^\d{1,18}$/', $v) ? (int) $v : null;
    }

    private static function millis($v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : null;
    }

    private static function text($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v), 0, self::MAX_TEXT, 'UTF-8');
    }
}
