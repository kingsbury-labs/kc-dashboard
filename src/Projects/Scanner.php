<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Process/ProcessRunner.php';
require_once __DIR__ . '/ProjectList.php';
require_once __DIR__ . '/GitStatus.php';
require_once __DIR__ . '/LoopState.php';

// One row per git repo directly under the root or in an extra folder. Reads only: nothing is written into
// any project. A project that fails keeps its error on its row and the scan goes on.
class Scanner
{
    // Most of its entries are junctions into D:\Dev\htdocs, which SafeDir rejects, so it would scan nothing true.
    private const NEVER_ROOT = 'C:\\xampp\\htdocs';

    private ProjectList $projects;
    private GitStatus $git;
    private LoopState $loop;
    private ?Log $log;

    public function __construct(string $root, array $extra, GitStatus $git, LoopState $loop, ?Log $log = null)
    {
        if (strcasecmp(rtrim($root, '\\'), self::NEVER_ROOT) === 0) {
            throw new InvalidArgumentException('C:\\xampp\\htdocs is never a scan root');
        }
        $this->projects = new ProjectList($root, $extra, '', $log);
        $this->git = $git;
        $this->loop = $loop;
        $this->log = $log;
    }

    public static function fromConfig(?Log $log = null): self
    {
        $c = require __DIR__ . '/../../config/projects.php';
        $exes = require __DIR__ . '/../../config/executables.php';
        $git = new GitStatus(fn(array $env) => ProcessRunner::fromConfig(null, $env), $exes['git']);
        return new self($c['root'], $c['extra'] ?? [], $git, new LoopState(ProcessRunner::fromConfig(), $exes['tasklist']), $log);
    }

    /** @return array<int,array<string,mixed>> */
    public function scan(?int $now = null): array
    {
        $now = $now ?? time();
        $rows = [];
        foreach ($this->projects->names() as $name) {
            $entry = $this->projects->resolve($name);
            $path = rtrim($entry['parent'], '\\') . '\\' . $name;
            // lstat never follows: a .git file (gitdir: ...) or link could point at a network path.
            clearstatcache(true);
            $dotGit = @lstat($path . '\\.git');
            if ($dotGit === false) {
                continue;
            }
            $row = ['slug' => $name, 'path' => $path, 'branch' => null, 'ahead' => null, 'behind' => null, 'dirty' => null,
                'last_sha' => null, 'last_date' => null, 'last_subject' => null,
                'has_claude' => LoopState::plainDir($path, '.claude') ? 1 : 0,
                'loop_state' => null, 'loop_card' => null, 'loop_note' => null, 'checklist_cards' => null,
                'checklist_passing' => null, 'scanned_at' => $now, 'error' => null];
            $errors = [];
            if (($dotGit['mode'] & 0170000) === 0100000) {
                $errors[] = '.git is a file, not a folder';
            } elseif (!LoopState::plainDir($path, '.git')) {
                $errors[] = '.git is not a plain folder';
            } else {
                try {
                    $row = array_merge($row, $this->git->read($path));
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
            try {
                $row = array_merge($row, $this->loop->read($path));
            } catch (Throwable $e) {
                $errors[] = 'loop: ' . $e->getMessage();
            }
            if ($errors) {
                $row['error'] = implode('; ', $errors);
                $this->log?->info('project scan error', ['project' => $name, 'error' => $row['error']]);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // Replaces the projects table with this scan, so a folder that went away loses its row.
    public static function store(PDO $pdo, array $rows): void
    {
        $cols = ['slug', 'path', 'branch', 'ahead', 'behind', 'dirty', 'last_sha', 'last_date', 'last_subject', 'has_claude',
            'loop_state', 'loop_card', 'loop_note', 'checklist_cards', 'checklist_passing', 'scanned_at', 'error'];
        $insert = $pdo->prepare('INSERT INTO projects (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM projects');
            foreach ($rows as $row) {
                $insert->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
