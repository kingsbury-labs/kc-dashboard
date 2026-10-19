<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// A project's claude-loop state from its `.claude/work/loop/` files, plus its checklist counts. Only reads.
// running: `lock` holds a live node.exe PID and there is no STOP. stopped: the same live loop with STOP
// (it is winding down). stale: a lock whose PID is dead or not node.exe; the loop deletes its lock on exit,
// so a leftover lock means it crashed, STOP or not. none: no lock.
class LoopState
{
    public const MAX_FILE = 2000000;
    public const MAX_TEXT = 500;

    private ProcessRunner $runner;
    private string $tasklistExe;

    public function __construct(ProcessRunner $runner, string $tasklistExe)
    {
        $this->runner = $runner;
        $this->tasklistExe = $tasklistExe;
    }

    /** @return array{loop_state:string,loop_card:?string,loop_note:?string,checklist_cards:?int,checklist_passing:?int} */
    public function read(string $project): array
    {
        $out = ['loop_state' => 'none', 'loop_card' => null, 'loop_note' => null,
            'checklist_cards' => null, 'checklist_passing' => null];
        // Each level must be a plain folder, so a junction or link cannot send the reads to a network path.
        $claude = self::subdir(rtrim($project, '\\'), '.claude');
        $work = $claude === null ? null : self::subdir($claude, 'work');
        if ($work === null) {
            return $out;
        }
        $out = array_merge($out, self::checklist($work . '\\checklist.json'));
        $loop = self::subdir($work, 'loop');
        if ($loop === null) {
            return $out;
        }
        $lock = self::readFile($loop . '\\lock');
        if ($lock === null) {
            return $out;
        }
        $pid = preg_match('/^\s*([1-9]\d{0,9})\s*$/', $lock, $m) ? (int) $m[1] : 0;
        if ($pid === 0 || !$this->isNode($pid)) {
            $out['loop_state'] = 'stale';
            return $out;
        }
        // lstat, not file_exists: it never follows a link.
        if (@lstat($loop . '\\STOP') !== false) {
            $out['loop_state'] = 'stopped';
            return $out;
        }
        $out['loop_state'] = 'running';
        // status.json is the loop's own claim; a malformed one leaves the card unknown, never an error.
        $status = json_decode((string) self::readFile($loop . '\\status.json'), true);
        if (is_array($status)) {
            $out['loop_card'] = self::text($status['card'] ?? null);
            $out['loop_note'] = self::text($status['note'] ?? null);
        }
        return $out;
    }

    // True when the PID is alive and its image is node.exe. A tasklist that fails throws: unknown is not dead.
    public function isNode(int $pid): bool
    {
        $r = $this->runner->run([$this->tasklistExe, '/fo', 'csv', '/nh', '/fi', 'PID eq ' . $pid], null, 15);
        if ($r['timedOut'] || $r['code'] !== 0) {
            throw new RuntimeException('tasklist failed');
        }
        foreach (preg_split('/\r?\n/', $r['stdout']) as $line) {
            $row = str_getcsv($line);
            if (count($row) >= 2 && $row[1] === (string) $pid) {
                return strcasecmp($row[0], 'node.exe') === 0;
            }
        }
        return false;
    }

    /** @return array{checklist_cards:?int,checklist_passing:?int} */
    public static function checklist(string $file): array
    {
        $list = json_decode((string) self::readFile($file), true);
        if (!is_array($list) || !array_is_list($list)) {
            return ['checklist_cards' => null, 'checklist_passing' => null];
        }
        $passing = 0;
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry['passes'] ?? null) === true) {
                $passing++;
            }
        }
        return ['checklist_cards' => count($list), 'checklist_passing' => $passing];
    }

    // The folder $parent\$name when it is a plain folder; null when absent. Anything else (a junction, a symlink,
    // a file) throws. lstat goes first and never follows a link; then the same stat/lstat and realpath checks as SafeDir.
    public static function subdir(string $parent, string $name): ?string
    {
        clearstatcache(true);
        $path = $parent . '\\' . $name;
        $lst = @lstat($path);
        if ($lst === false) {
            return null;
        }
        if (!self::plainDir($parent, $name)) {
            throw new RuntimeException($name . ' is not a plain folder');
        }
        return $path;
    }

    public static function plainDir(string $parent, string $name): bool
    {
        clearstatcache(true);
        $path = $parent . '\\' . $name;
        $lst = @lstat($path);
        if ($lst === false || ($lst['mode'] & 0170000) !== 0040000) {
            return false;
        }
        $st = @stat($path);
        if ($st === false || $st['ino'] !== $lst['ino'] || $st['dev'] !== $lst['dev']) {
            return false;
        }
        $base = realpath($parent);
        return $base !== false && realpath($path) === rtrim($base, '\\') . '\\' . $name;
    }

    // Regular files only, checked with lstat first, so a file link is never opened.
    private static function readFile(string $path): ?string
    {
        clearstatcache(true);
        $lst = @lstat($path);
        if ($lst === false || ($lst['mode'] & 0170000) !== 0100000 || $lst['size'] > self::MAX_FILE) {
            return null;
        }
        $s = @file_get_contents($path);
        return $s === false ? null : $s;
    }

    private static function text($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v), 0, self::MAX_TEXT, 'UTF-8');
    }
}
