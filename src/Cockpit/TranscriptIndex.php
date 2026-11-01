<?php
require_once __DIR__ . '/NeverShow.php';
require_once __DIR__ . '/../Log.php';

/**
 * The cockpit's index of Claude Code transcripts (format: .claude/work/agentos/data-spike.md), in its own SQLite file.
 * Incremental by byte offset per transcript: only complete lines past the stored offset are read, so a file being
 * written is safe. A file first seen with no change in the last 7 days is never read. Each call spends at most its
 * budget and leaves the rest for the next. Transcripts are opened read-only. What is kept: who launched whom, the
 * last tool each agent used and its target, file paths touched, and agent-to-agent text cut by NeverShow::text.
 * Never kept: file contents, Bash commands, tool output, or any path NeverShow::blocked refuses.
 */
class TranscriptIndex
{
    public const FIRST_DAYS = 7;
    private const CHUNK = 4194304;
    private const SKIP_TYPES = ['api-request', 'api-request-blob', 'queue-operation', 'file-history-snapshot', 'cost-state'];
    private const READ_TOOLS = ['Read' => 'read', 'Write' => 'write', 'Edit' => 'write', 'MultiEdit' => 'write', 'NotebookEdit' => 'write'];

    private PDO $db;
    private string $dir;
    private ?Log $log;
    private Closure $clock;

    public function __construct(string $dbFile, string $transcriptsDir, ?Log $log = null, ?Closure $clock = null)
    {
        $this->dir = rtrim($transcriptsDir, '\\/');
        $this->log = $log;
        $this->clock = $clock ?? fn(): int => time();
        $this->db = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000;');
        $this->db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS files (path TEXT PRIMARY KEY, offset INTEGER NOT NULL, size INTEGER NOT NULL, skipped INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS sessions (key TEXT PRIMARY KEY, session_id TEXT, agent_id TEXT, project TEXT, parent_key TEXT,
    tool_use_id TEXT, agent_type TEXT, description TEXT, model TEXT, entrypoint TEXT, started INTEGER, ended INTEGER,
    replied INTEGER, tool TEXT, target TEXT, finished INTEGER NOT NULL DEFAULT 0, title TEXT);
CREATE TABLE IF NOT EXISTS messages (id TEXT PRIMARY KEY, kind TEXT, from_key TEXT, to_key TEXT, ts INTEGER, text TEXT,
    agent_type TEXT, description TEXT);
CREATE TABLE IF NOT EXISTS touched (src TEXT, path TEXT, op TEXT, session_key TEXT, ts INTEGER, PRIMARY KEY (src, path, op));
CREATE INDEX IF NOT EXISTS touched_ts ON touched (ts);
CREATE INDEX IF NOT EXISTS messages_ts ON messages (ts);
SQL);
    }

    public function pdo(): PDO
    {
        return $this->db;
    }

    /** Reads new bytes until $budget seconds are spent. Returns ['read' => files read, 'pending' => bool]. */
    public function run(float $budget = 2.0): array
    {
        $end = microtime(true) + $budget;
        $read = 0;
        $pending = false;
        if (!is_dir($this->dir)) {
            return ['read' => 0, 'pending' => false];
        }
        $known = $this->db->query('SELECT path, offset, size, skipped FROM files')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $cutoff = ($this->clock)() - self::FIRST_DAYS * 86400;
        foreach ($this->transcripts() as [$file, $sessionId, $agentId]) {
            $size = @filesize($file);
            if ($size === false) {
                continue;
            }
            $row = $known[$file] ?? null;
            if ($row === null && (int) @filemtime($file) < $cutoff) {
                $this->db->prepare('INSERT OR REPLACE INTO files (path, offset, size, skipped) VALUES (?, ?, ?, 1)')->execute([$file, $size, $size]);
                continue;
            }
            $offset = $row === null ? 0 : (int) $row['offset'];
            if ($size <= $offset && $row !== null) {
                continue;
            }
            if (microtime(true) >= $end) {
                $pending = true;
                break;
            }
            $this->db->beginTransaction();
            try {
                if ($agentId !== null) {
                    $this->meta($file, $sessionId, $agentId);
                }
                $offset = $this->readFrom($file, $offset, $sessionId, $agentId);
                $this->db->prepare('INSERT OR REPLACE INTO files (path, offset, size, skipped) VALUES (?, ?, ?, 0)')->execute([$file, $offset, $size]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                $this->log?->exception($e, ['where' => 'cockpit index']);
            }
            $read++;
            $pending = $pending || $offset < $size;
        }
        return ['read' => $read, 'pending' => $pending];
    }

    // [path, sessionId, agentId|null] for every main and subagent transcript, newest first.
    private function transcripts(): array
    {
        $out = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $project) {
            foreach (glob($project . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $f) {
                $out[] = [$f, basename($f, '.jsonl'), null];
            }
            foreach (glob($project . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'subagents' . DIRECTORY_SEPARATOR . 'agent-*.jsonl') ?: [] as $f) {
                $out[] = [$f, basename(dirname($f, 2)), substr(basename($f, '.jsonl'), 6)];
            }
        }
        $m = [];
        foreach ($out as $i => $t) {
            $m[$i] = (int) @filemtime($t[0]);
        }
        arsort($m);
        return array_map(fn($i) => $out[$i], array_keys($m));
    }

    private function key(string $sessionId, ?string $agentId): string
    {
        return $agentId === null ? $sessionId : $sessionId . ':' . $agentId;
    }

    private function meta(string $file, string $sessionId, string $agentId): void
    {
        $raw = @file_get_contents(substr($file, 0, -6) . '.meta.json', false, null, 0, 65536);
        $m = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($m)) {
            $this->ensure($this->key($sessionId, $agentId), $sessionId, $agentId);
            return;
        }
        $key = $this->key($sessionId, $agentId);
        $parent = is_string($m['parentAgentId'] ?? null) ? $sessionId . ':' . $m['parentAgentId'] : $sessionId;
        $this->ensure($key, $sessionId, $agentId);
        $this->db->prepare('UPDATE sessions SET parent_key = ?, tool_use_id = ?, agent_type = ?, description = ?, model = COALESCE(?, model),
            finished = ? WHERE key = ?')->execute([$parent, self::str($m['toolUseId'] ?? null), self::str($m['agentType'] ?? null),
            self::clean($m['description'] ?? null, 120), self::str($m['model'] ?? null), ($m['finished'] ?? false) ? 1 : 0, $key]);
        if (is_string($m['toolUseId'] ?? null)) {
            $this->db->prepare('UPDATE messages SET to_key = ? WHERE id = ?')->execute([$key, $m['toolUseId']]);
        }
    }

    private function ensure(string $key, string $sessionId, ?string $agentId): void
    {
        $this->db->prepare('INSERT OR IGNORE INTO sessions (key, session_id, agent_id, parent_key) VALUES (?, ?, ?, ?)')
            ->execute([$key, $sessionId, $agentId, $agentId === null ? null : $sessionId]);
    }

    // Complete lines from $offset on, at most CHUNK bytes. Returns the offset after the last complete line.
    private function readFrom(string $file, int $offset, string $sessionId, ?string $agentId): int
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return $offset;
        }
        try {
            fseek($fh, $offset);
            $buf = (string) fread($fh, self::CHUNK);
        } finally {
            fclose($fh);
        }
        $last = strrpos($buf, "\n");
        if ($last === false) {
            return $offset;
        }
        $key = $this->key($sessionId, $agentId);
        $this->ensure($key, $sessionId, $agentId);
        foreach (explode("\n", substr($buf, 0, $last)) as $line) {
            if ($line === '' || preg_match('/^\{"type":"(' . implode('|', self::SKIP_TYPES) . ')"/', $line) === 1) {
                continue;
            }
            $r = json_decode($line, true);
            if (is_array($r)) {
                $this->record($r, $key, $agentId !== null);
            }
        }
        return $offset + $last + 1;
    }

    private function record(array $r, string $key, bool $sub): void
    {
        $ts = is_string($r['timestamp'] ?? null) ? (int) strtotime($r['timestamp']) : 0;
        $cwd = is_string($r['cwd'] ?? null) && !NeverShow::blocked($r['cwd']) ? $r['cwd'] : null;
        $type = $r['type'] ?? null;
        if ($type === 'ai-title' && is_string($r['aiTitle'] ?? null)) {
            $this->db->prepare('UPDATE sessions SET title = ? WHERE key = ?')->execute([NeverShow::text($r['aiTitle'], $this->log, 80), $key]);
        }
        if ($ts > 0) {
            $this->db->prepare('UPDATE sessions SET started = MIN(COALESCE(started, ?), ?), ended = MAX(COALESCE(ended, 0), ?),
                project = COALESCE(project, ?), entrypoint = COALESCE(entrypoint, ?) WHERE key = ?')
                ->execute([$ts, $ts, $ts, $cwd !== null ? basename(str_replace('\\', '/', $cwd)) : null, self::str($r['entrypoint'] ?? null), $key]);
        }
        $msg = $r['message'] ?? null;
        if ($type === 'assistant' && is_array($msg)) {
            if ($ts > 0) {
                $this->db->prepare('UPDATE sessions SET replied = ? WHERE key = ?')->execute([$ts, $key]);
            }
            if (is_string($msg['model'] ?? null) && $msg['model'] !== '<synthetic>') {
                $this->db->prepare('UPDATE sessions SET model = ? WHERE key = ?')->execute([$msg['model'], $key]);
            }
            foreach ((array) ($msg['content'] ?? []) as $b) {
                if (is_array($b) && ($b['type'] ?? null) === 'tool_use') {
                    $this->toolUse($b, $key, $ts, $sub);
                }
            }
        }
        $result = $r['toolUseResult'] ?? null;
        if ($type === 'user' && is_array($result) && is_string($result['agentId'] ?? null) && is_array($msg)) {
            foreach ((array) ($msg['content'] ?? []) as $b) {
                if (is_array($b) && ($b['type'] ?? null) === 'tool_result' && is_string($b['tool_use_id'] ?? null)) {
                    $this->db->prepare('UPDATE messages SET to_key = COALESCE(to_key, ?) WHERE id = ?')
                        ->execute([explode(':', $key)[0] . ':' . $result['agentId'], $b['tool_use_id']]);
                }
            }
        }
    }

    private function toolUse(array $b, string $key, int $ts, bool $sub): void
    {
        $name = self::str($b['name'] ?? null) ?? '';
        $in = is_array($b['input'] ?? null) ? $b['input'] : [];
        $id = self::str($b['id'] ?? null) ?? ($key . ':' . $ts . ':' . $name);
        $path = self::str($in['file_path'] ?? $in['notebook_path'] ?? $in['path'] ?? null);
        $target = '';
        if ($path !== null) {
            $target = NeverShow::blocked($path) ? '[hidden path]' : $path;
            if (!NeverShow::blocked($path)) {
                $op = self::READ_TOOLS[$name] ?? (in_array($name, ['Grep', 'Glob'], true) ? 'search' : null);
                if ($op !== null) {
                    $this->db->prepare('INSERT OR IGNORE INTO touched (src, path, op, session_key, ts) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, $path, $op, $key, $ts]);
                }
            }
        } elseif ($name === 'Agent' || $name === 'Task') {
            $target = (string) (self::str($in['subagent_type'] ?? null) ?? 'agent');
        } elseif ($name === 'Skill') {
            $target = (string) (self::str($in['skill'] ?? null) ?? '');
        } elseif (in_array($name, ['WebFetch', 'mcp__fetch__fetch'], true) && is_string($in['url'] ?? null)) {
            $target = (string) parse_url($in['url'], PHP_URL_HOST);
        }
        $this->db->prepare('UPDATE sessions SET tool = ?, target = ? WHERE key = ?')->execute([$name, mb_substr($target, 0, 200), $key]);
        $sql = 'INSERT OR IGNORE INTO messages (id, kind, from_key, to_key, ts, text, agent_type, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        if (($name === 'Agent' || $name === 'Task') && is_string($in['prompt'] ?? null)) {
            $this->db->prepare($sql)->execute([$id, 'agent', $key, null, $ts, NeverShow::text($in['prompt'], $this->log),
                self::str($in['subagent_type'] ?? null), self::clean($in['description'] ?? null, 120)]);
        } elseif ($name === 'SubagentHandback' && is_string($in['message'] ?? null)) {
            $parent = $this->db->prepare('SELECT parent_key FROM sessions WHERE key = ?');
            $parent->execute([$key]);
            $this->db->prepare($sql)->execute([$id, 'handback', $key, $parent->fetchColumn() ?: explode(':', $key)[0], $ts,
                NeverShow::text($in['message'], $this->log), null, null]);
            $this->db->prepare('UPDATE sessions SET finished = 1 WHERE key = ?')->execute([$key]);
        } elseif ($name === 'SendMessage') {
            $text = $in['message'] ?? $in['content'] ?? null;
            if (is_string($text)) {
                $this->db->prepare($sql)->execute([$id, 'send', $key, self::clean($in['to'] ?? $in['recipient'] ?? null, 80), $ts,
                    NeverShow::text($text, $this->log), null, null]);
            }
        } elseif ($name === 'AskUserQuestion') {
            $q = $in['questions'][0]['question'] ?? $in['question'] ?? null;
            if (is_string($q)) {
                $this->db->prepare($sql)->execute([$id, 'question', $key, 'rob', $ts, NeverShow::text($q, $this->log), null, null]);
            }
        }
    }

    private static function str($v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function clean($v, int $max): ?string
    {
        return is_string($v) && $v !== '' ? NeverShow::text($v, null, $max) : null;
    }
}
