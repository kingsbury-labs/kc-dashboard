<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';
require_once __DIR__ . '/../Llm/Prompt.php';

// Runs `claude -p --model sonnet --output-format json` through ProcessRunner, prompt on stdin, never with a
// permission bypass. A Prompt (untrusted data) runs in a fresh empty temp folder in untrusted mode.
class ClaudeRunner
{
    public const ARGS = ['-p', '--model', 'sonnet', '--output-format', 'json'];
    // Untrusted mode starts from nothing allowed: `--tools ""` disables every built-in tool, `--restricted`
    // ignores user, project and local settings (their allow rules included) and confines file tools to the cwd,
    // `--strict-mcp-config` with no --mcp-config loads no MCP servers. The deny list is a second layer only.
    public const UNTRUSTED = ['--tools', '', '--restricted', '--strict-mcp-config'];
    public const DISALLOWED = 'Bash,Edit,Write,MultiEdit,NotebookEdit,WebFetch,WebSearch';
    // Bash run from claude goes through Git Bash, which rewrites /c-style arguments into paths without this.
    public const ENV = ['MSYS_NO_PATHCONV' => '1'];

    /** @var callable(?string, array): ProcessRunner */
    private $runners;
    private string $exe;
    private string $tempBase;

    /** @param callable(?string $cwdRoot, array $extraEnv): ProcessRunner $runners */
    public function __construct(callable $runners, string $exe, ?string $tempBase = null)
    {
        $this->runners = $runners;
        $this->exe = $exe;
        $this->tempBase = $tempBase ?? sys_get_temp_dir();
    }

    public static function fromConfig(): self
    {
        $exe = (require __DIR__ . '/../../config/executables.php')['claude'];
        return new self(fn(?string $root, array $env) => ProcessRunner::fromConfig($root, $env), $exe);
    }

    /**
     * Trusted text runs in $cwdName under $cwdRoot; a Prompt ignores both and runs in an empty temp folder.
     * @return array{ok:bool, result:?string, usage:?array, error:?string}
     */
    public function run(string|Prompt $prompt, ?string $cwdRoot = null, ?string $cwdName = null, int $timeoutSeconds = 300): array
    {
        $argv = array_merge([$this->exe], self::ARGS);
        $temp = null;
        if ($prompt instanceof Prompt) {
            $argv = array_merge($argv, self::UNTRUSTED);
            $argv[] = '--disallowedTools';
            $argv[] = self::DISALLOWED;
            $cwdRoot = $this->tempBase;
            $cwdName = 'kcclaude_' . bin2hex(random_bytes(8));
            $temp = rtrim($cwdRoot, '\\') . '\\' . $cwdName;
            if (!mkdir($temp)) {
                return self::failed('could not make the temp folder');
            }
            $stdin = $prompt->text();
        } else {
            $stdin = $prompt;
        }
        try {
            $r = ($this->runners)($cwdRoot, self::ENV)->run($argv, $cwdName, $timeoutSeconds, $stdin);
        } catch (Throwable $e) {
            return self::failed('could not start claude: ' . $e->getMessage());
        } finally {
            if ($temp !== null) {
                self::removeTree($temp);
            }
        }
        if ($r['timedOut']) {
            return self::failed('timeout');
        }
        $data = json_decode($r['stdout'], true);
        if (!is_array($data)) {
            return self::failed('claude exited ' . $r['code'] . ' without JSON');
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        $result = is_string($data['result'] ?? null) ? $data['result'] : null;
        if ($r['code'] !== 0 || ($data['is_error'] ?? false) === true || $result === null) {
            return ['ok' => false, 'result' => $result, 'usage' => $usage,
                'error' => 'claude failed (exit ' . $r['code'] . ', ' . (string) ($data['subtype'] ?? 'no subtype') . ')'];
        }
        return ['ok' => true, 'result' => $result, 'usage' => $usage, 'error' => null];
    }

    private static function failed(string $error): array
    {
        return ['ok' => false, 'result' => null, 'usage' => null, 'error' => $error];
    }

    // The folder held only what claude wrote there; a junction inside is removed, never followed.
    private static function removeTree(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '\\' . $f;
            $st = @stat($path);
            $lst = @lstat($path);
            if (is_dir($path) && $st !== false && $lst !== false && $st['ino'] === $lst['ino']) {
                self::removeTree($path);
            } elseif (!@rmdir($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
