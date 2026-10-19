<?php
require_once __DIR__ . '/../Fs/SafeDir.php';

class ProcessRefused extends RuntimeException
{
}

class ProcessRunner
{
    public const DEFAULT_ENV = [
        'SystemRoot', 'SystemDrive', 'windir', 'TEMP', 'TMP', 'PATH', 'PATHEXT',
        'USERPROFILE', 'USERNAME', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA',
    ];

    /** @var array<string,string> name => absolute path */
    private array $executables;
    /** @var string[] */
    private array $envAllow;
    private ?string $cwdRoot;
    private string $taskkill;
    /** @var array<string,string> fixed values set on every child, over anything inherited */
    private array $extraEnv;

    public function __construct(array $executables, ?string $cwdRoot = null, array $envAllow = self::DEFAULT_ENV,
        string $taskkill = 'C:\\Windows\\System32\\taskkill.exe', array $extraEnv = [])
    {
        $this->executables = $executables;
        $this->cwdRoot = $cwdRoot;
        $this->envAllow = $envAllow;
        $this->taskkill = $taskkill;
        $this->extraEnv = $extraEnv;
    }

    public static function fromConfig(?string $cwdRoot = null, array $extraEnv = []): self
    {
        return new self(require __DIR__ . '/../../config/executables.php', $cwdRoot, self::DEFAULT_ENV,
            'C:\\Windows\\System32\\taskkill.exe', $extraEnv);
    }

    // Returns the resolved real path of an allowlisted .exe, or throws.
    public function resolveExe(string $path): string
    {
        // Drive-absolute only: no UNC, no device paths, no PATH lookup, no colon after the drive (blocks ::$DATA).
        if (!preg_match('/^[A-Za-z]:\\\\[^:*?"<>|\/\0]+$/', $path)) {
            throw new ProcessRefused('not an absolute path');
        }
        $listed = false;
        foreach ($this->executables as $allowed) {
            if (strcasecmp($allowed, $path) === 0) {
                $listed = true;
                break;
            }
        }
        if (!$listed) {
            throw new ProcessRefused('not in the executable allowlist');
        }
        clearstatcache(true);
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new ProcessRefused('executable not found');
        }
        // Checked on both the given and the resolved path, so trailing dots, spaces and short names cannot hide a .bat.
        if (strtolower(substr($path, -4)) !== '.exe' || strtolower(substr($real, -4)) !== '.exe') {
            throw new ProcessRefused('only .exe files may be started');
        }
        return $real;
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            foreach ($this->envAllow as $allowed) {
                if (strcasecmp($name, $allowed) === 0) {
                    $env[$name] = $value;
                }
            }
        }
        // Windows names are case-insensitive: drop any inherited spelling first, or the child sees two.
        foreach ($this->extraEnv as $name => $value) {
            foreach (array_keys($env) as $have) {
                if (strcasecmp($have, $name) === 0) {
                    unset($env[$have]);
                }
            }
            $env[$name] = (string) $value;
        }
        return $env;
    }

    /**
     * @param string[] $argv first item is the executable's absolute path
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        if ($argv === [] || array_values($argv) !== $argv) {
            throw new ProcessRefused('argv must be a non-empty list');
        }
        foreach ($argv as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                throw new ProcessRefused('argv items must be strings without NUL');
            }
        }
        $argv[0] = $this->resolveExe($argv[0]);
        $env = $this->environment();

        $root = null;
        if ($cwdName !== null) {
            if ($this->cwdRoot === null) {
                throw new ProcessRefused('no cwd root configured');
            }
            $root = realpath($this->cwdRoot);
            if ($root === false) {
                throw new ProcessRefused('cwd root does not exist');
            }
        }

        // All stdio goes through temp files: Windows pipes ignore non-blocking mode, so a write to a child
        // that never reads, or a read from one that never writes, would block past the timeout.
        $inFile = tempnam(sys_get_temp_dir(), 'kcpi');
        $outFile = tempnam(sys_get_temp_dir(), 'kcpo');
        $errFile = tempnam(sys_get_temp_dir(), 'kcpe');
        $cleanup = static function () use ($inFile, $outFile, $errFile): void {
            @unlink($inFile);
            @unlink($outFile);
            @unlink($errFile);
        };
        file_put_contents($inFile, (string) $stdin);

        $cwd = null;
        if ($root !== null) {
            // Last thing before launch, so a folder swapped for a junction since any earlier check is caught.
            if (!SafeDir::isDirectChild($root, $cwdName)) {
                $cleanup();
                throw new ProcessRefused('cwd is not a safe folder');
            }
            $cwd = rtrim($root, '\\') . '\\' . $cwdName;
        }
        $proc = proc_open($argv, [0 => ['file', $inFile, 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']],
            $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $cleanup();
            throw new RuntimeException('process failed to start');
        }

        $timedOut = false;
        $code = -1;
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                $this->killTree($status['pid']);
                break;
            }
            usleep(20000);
        }
        $closed = -1;
        if ($timedOut) {
            // proc_close waits without limit, so only call it once the kill has actually landed.
            $killDeadline = microtime(true) + 3;
            while (proc_get_status($proc)['running'] && microtime(true) < $killDeadline) {
                usleep(20000);
            }
        }
        if (!proc_get_status($proc)['running']) {
            $closed = proc_close($proc);
        } else {
            // Dropping the resource without proc_close does not wait on the child.
            unset($proc);
        }
        $out = (string) @file_get_contents($outFile);
        $err = (string) @file_get_contents($errFile);
        $cleanup();
        if ($code === -1 && !$timedOut) {
            $code = $closed;
        }
        return ['code' => $code, 'stdout' => $out, 'stderr' => $err, 'timedOut' => $timedOut];
    }

    private function killTree(int $pid): void
    {
        // Same shape checks as resolveExe, without the allowlist: taskkill is fixed by the constructor.
        clearstatcache(true);
        $real = preg_match('/^[A-Za-z]:\\\\[^:*?"<>|\/\0]+$/', $this->taskkill) ? realpath($this->taskkill) : false;
        if ($real === false || !is_file($real) || strtolower(substr($real, -4)) !== '.exe'
            || strtolower(substr($this->taskkill, -4)) !== '.exe') {
            return;
        }
        $kill = proc_open([$real, '/PID', (string) $pid, '/T', '/F'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment(), ['bypass_shell' => true]);
        if (is_resource($kill)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($kill);
        }
    }
}
