<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Process/ProcessRunner.php';
require_once __DIR__ . '/../Projects/ProjectList.php';
require_once __DIR__ . '/../Projects/LoopState.php';
require_once __DIR__ . '/SessionRegistry.php';
require_once __DIR__ . '/Ports.php';
require_once __DIR__ . '/Tasks.php';
require_once __DIR__ . '/SystemMonitor.php';

// What is running right now: Claude Code sessions, loops, the dashboard's scheduled tasks and listening ports.
// One scan serves every request for cache_seconds, shared through a locked file, so many open tabs cost one scan.
// A source that fails gives an empty list and its name in `errors`; the others still come back.
class NowService
{
    private SessionRegistry $sessions;
    private LoopState $loops;
    private Tasks $tasks;
    private Ports $ports;
    /** @var array<string,string> slug => folder */
    private array $projects;
    private string $cacheFile;
    private int $cacheSeconds;
    /** @var Closure(): int */
    private Closure $clock;
    private ?Log $log;
    private ?SystemMonitor $system;

    public function __construct(SessionRegistry $sessions, LoopState $loops, Tasks $tasks, Ports $ports, array $projects,
        string $cacheFile, int $cacheSeconds = 3, ?Closure $clock = null, ?Log $log = null, ?SystemMonitor $system = null)
    {
        $this->system = $system;
        $this->sessions = $sessions;
        $this->loops = $loops;
        $this->tasks = $tasks;
        $this->ports = $ports;
        $this->projects = $projects;
        $this->cacheFile = $cacheFile;
        $this->cacheSeconds = $cacheSeconds;
        $this->clock = $clock ?? fn(): int => time();
        $this->log = $log;
    }

    public static function fromConfig(string $dataDir, ?Log $log = null): self
    {
        $c = require __DIR__ . '/../../config/running.php';
        $exes = require __DIR__ . '/../../config/executables.php';
        $list = ProjectList::fromConfig($log);
        $projects = [];
        foreach ($list->names() as $name) {
            $projects[$name] = rtrim($list->resolve($name)['parent'], '\\') . '\\' . $name;
        }
        $runner = ProcessRunner::fromConfig();
        return new self(
            new SessionRegistry($c['sessions_dir'], $runner, $exes['powershell'], $projects, $c['aliases'] ?? []),
            new LoopState($runner, $exes['tasklist']),
            new Tasks($runner, $exes['schtasks'], $c['task_folder']),
            new Ports($runner, $exes['netstat'], $exes['tasklist'], (array) ($c['port_programs'] ?? []), (array) ($c['port_always'] ?? [])),
            $projects, $dataDir . '/now-cache.json', (int) ($c['cache_seconds'] ?? 3), null, $log,
            new SystemMonitor($runner, $exes['powershell'], $dataDir . '/system-cache.json', (int) ($c['system_cache_seconds'] ?? 10)));
    }

    // $token is called only once the address and Host checks pass, so a refused request never reads it.
    public static function allowed(array $server, Closure $token): bool
    {
        return (new RequestGuard(''))->checkRead($server) && ($server['REQUEST_METHOD'] ?? null) === 'GET'
            && (new RequestGuard($token()))->checkApiRead($server);
    }

    public function handle(array $server, Closure $token): array
    {
        try {
            if (!self::allowed($server, $token)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return Response::json(200, $this->now());
        } catch (Throwable $e) {
            $this->log?->exception($e, ['where' => 'now']);
            return Response::error($e);
        }
    }

    /** @return array{sessions:array,loops:array,tasks:array,ports:array,at:int,errors:string[],stale:bool} */
    public function now(): array
    {
        $lock = fopen($this->cacheFile . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('cache lock not writable');
        }
        try {
            // Never queue: while another request scans, this one answers from the last snapshot.
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                $last = $this->snapshot();
                if ($last === null) {
                    return ['sessions' => [], 'loops' => [], 'tasks' => [], 'ports' => [], 'at' => ($this->clock)(),
                        'errors' => [], 'stale' => true];
                }
                $last['stale'] = ($this->clock)() - $last['at'] >= $this->cacheSeconds;
                return $last;
            }
            $last = $this->snapshot();
            $now = ($this->clock)();
            if ($last !== null && $now >= $last['at'] && $now - $last['at'] < $this->cacheSeconds) {
                return $last;
            }
            $data = $this->scan();
            // Stamped when the scan ends, so a scan longer than the window still serves the next requests.
            $data['at'] = ($this->clock)();
            $data['stale'] = false;
            $this->save($data);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function snapshot(): ?array
    {
        $cached = json_decode((string) @file_get_contents($this->cacheFile), true);
        return is_array($cached) && is_int($cached['at'] ?? null) ? $cached : null;
    }

    // Written aside and renamed in, so a request reading without the lock never sees half a file.
    private function save(array $data): void
    {
        $tmp = $this->cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        if (!@rename($tmp, $this->cacheFile)) {
            // Windows refuses the rename while a reader has the file open; that reader keeps the old snapshot.
            @unlink($tmp);
        }
    }

    private function scan(): array
    {
        $errors = [];
        $source = function (string $name, callable $fn) use (&$errors): array {
            try {
                return $fn();
            } catch (Throwable $e) {
                $errors[] = $name;
                $this->log?->info('now: source failed', ['source' => $name, 'error' => $e->getMessage()]);
                return [];
            }
        };
        $data = [
            'sessions' => $source('sessions', fn() => $this->sessions->read()),
            'loops' => $source('loops', fn() => $this->loopRows()),
            'tasks' => $source('tasks', fn() => $this->tasks->read()),
            'ports' => $source('ports', fn() => $this->ports->read()),
        ];
        if ($this->system !== null) {
            $data['system'] = $this->system->read();
        }
        $data['errors'] = $errors;
        return $data;
    }

    // Every project with a loop lock (running, stopped or stale). One project that fails is skipped, not fatal.
    private function loopRows(): array
    {
        $out = [];
        foreach ($this->projects as $slug => $path) {
            try {
                $state = $this->loops->read($path);
            } catch (Throwable $e) {
                $this->log?->info('now: loop read failed', ['project' => $slug, 'error' => $e->getMessage()]);
                continue;
            }
            if ($state['loop_state'] !== 'none') {
                $out[] = ['project' => $slug, 'state' => $state['loop_state'], 'card' => $state['loop_card'],
                    'note' => $state['loop_note']];
            }
        }
        return $out;
    }
}
