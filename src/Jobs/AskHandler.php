<?php
require_once __DIR__ . '/../Db.php';
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Fs/SafeDir.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Process/ProcessRunner.php';
require_once __DIR__ . '/../Claude/ClaudeRunner.php';
require_once __DIR__ . '/../Projects/ProjectList.php';
require_once __DIR__ . '/Queue.php';

// Runs one queued ask: rechecks the folder, skips a busy project, runs claude, keeps the last 20 answers.
class AskHandler
{
    public const BUSY = 'finance busy, try later';
    public const KEEP = 20;
    public const MAX_ANSWER = 100000;

    private PDO $pdo;
    private ClaudeRunner $claude;
    private ProcessRunner $git;
    private string $gitExe;
    private int $timeout;

    public function __construct(PDO $pdo, ClaudeRunner $claude, ProcessRunner $git, string $gitExe, int $timeoutSeconds = 300)
    {
        $this->pdo = $pdo;
        $this->claude = $claude;
        $this->git = $git;
        $this->gitExe = $gitExe;
        $this->timeout = $timeoutSeconds;
    }

    public static function fromConfig(PDO $pdo): self
    {
        $exes = require __DIR__ . '/../../config/executables.php';
        return new self($pdo, ClaudeRunner::fromConfig(), ProcessRunner::fromConfig(), $exes['git']);
    }

    public function handle(array $payload): string
    {
        $id = (int) ($payload['ask_id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM asks WHERE id = ?');
        $stmt->execute([$id]);
        $ask = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$ask) {
            throw new RuntimeException('ask not found: ' . $id);
        }
        // Claimed in one statement before anything starts, so the same ask never runs twice.
        $claim = $this->pdo->prepare("UPDATE asks SET status = 'running' WHERE id = ? AND status = 'queued'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) {
            $stmt = $this->pdo->prepare('SELECT status FROM asks WHERE id = ?');
            $stmt->execute([$id]);
            $status = (string) $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($status === 'running') {
                // A recovered job whose first run may still be going, or died mid-claude. Never start it again.
                $this->pdo->prepare("UPDATE asks SET status = 'failed', error = ?, answered_at = ? WHERE id = ? AND status = 'running'")
                    ->execute(['interrupted; not run again', time(), $id]);
                throw new RuntimeException('ask ' . $id . ' was already running');
            }
            return 'ask ' . $id . ' already ' . $status;
        }
        $parent = $ask['cwd_parent'];
        $name = $ask['cwd_name'];
        // At launch, not only at enqueue: the folder may have been swapped for a junction since.
        if (!SafeDir::isDirectChild($parent, $name)) {
            return $this->finish($id, 'failed', null, null, 'project folder failed the safety check');
        }
        if ($ask['busy_if_dirty'] !== null && $this->busy(rtrim($parent, '\\') . '\\' . $name, $ask['busy_if_dirty'])) {
            return $this->finish($id, 'done', self::BUSY, null, null);
        }
        $r = $this->claude->run($ask['question'], $parent, $name, $this->timeout);
        if (!$r['ok']) {
            return $this->finish($id, 'failed', $r['result'], $r['usage'], $r['error']);
        }
        return $this->finish($id, 'done', $r['result'], $r['usage'], null);
    }

    // Uncommitted changes under $path mean the project's own loop is mid-card. A git that fails counts as busy.
    private function busy(string $folder, string $path): bool
    {
        try {
            $r = $this->git->run([$this->gitExe, '-C', $folder, 'status', '--porcelain', '--', $path], null, 30);
        } catch (Throwable $e) {
            return true;
        }
        return $r['timedOut'] || $r['code'] !== 0 || trim($r['stdout']) !== '';
    }

    private function finish(int $id, string $status, ?string $answer, ?array $usage, ?string $error): string
    {
        if ($answer !== null && mb_strlen($answer, 'UTF-8') > self::MAX_ANSWER) {
            $answer = mb_substr($answer, 0, self::MAX_ANSWER, 'UTF-8');
        }
        $this->pdo->prepare('UPDATE asks SET status = ?, answer = ?, usage = ?, error = ?, answered_at = ? WHERE id = ?')
            ->execute([$status, $answer, $usage === null ? null : json_encode($usage), $error, time(), $id]);
        // Queued and running asks are never pruned; only answered ones beyond the newest KEEP.
        $this->pdo->prepare(
            "DELETE FROM asks WHERE status IN ('done', 'failed') AND id NOT IN
             (SELECT id FROM asks WHERE status IN ('done', 'failed') ORDER BY id DESC LIMIT " . self::KEEP . ')'
        )->execute();
        return 'ask ' . $id . ' ' . $status;
    }
}

// The two endpoints behind public/api/ask.php and answers.php, kept here so tests can call them directly.
class AskApi
{
    public const MAX_QUESTION = 8000;

    // POST {"question": "...", "project": "name" or ""}. Blank project means the home folder.
    public static function ask(array $server, string $body, string $tokenPath, Log $log, PDO $pdo, ProjectList $projects): array
    {
        try {
            // Read check first, so a foreign address or Host never creates or reads the token. A request that
            // passes it has loaded the token before checkWrite refuses it.
            if (!(new RequestGuard(''))->checkRead($server)
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkWrite($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $in = json_decode($body, true);
            $question = is_array($in) && is_string($in['question'] ?? null) ? trim($in['question']) : '';
            $project = is_array($in) && is_string($in['project'] ?? '') ? (string) ($in['project'] ?? '') : null;
            if ($question === '' || mb_strlen($question, 'UTF-8') > self::MAX_QUESTION || $project === null) {
                return Response::json(400, ['error' => 'bad request']);
            }
            $entry = $project === '' ? $projects->home() : $projects->resolve($project);
            if ($entry === null) {
                return Response::json(400, ['error' => 'unknown project']);
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO asks (project, cwd_parent, cwd_name, busy_if_dirty, question, created_at)
                               VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$project, $entry['parent'], $entry['name'], $entry['busy_if_dirty'], $question, time()]);
                $askId = (int) $pdo->lastInsertId();
                $jobId = (new Queue($pdo))->enqueue('ask', ['ask_id' => $askId]);
                $pdo->prepare('UPDATE asks SET job_id = ? WHERE id = ?')->execute([$jobId, $askId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return Response::json(202, ['id' => $askId]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'ask']);
            return Response::error($e);
        }
    }

    // GET /api/answers.php. The page's fetch sends the token; the database opens only once the guard passes.
    public static function answersApi(array $server, string $tokenPath, Log $log, Closure $pdo): array
    {
        try {
            // Read check first, so a foreign address or Host never creates or reads the token. A request that
            // passes it has loaded the token before checkApiRead refuses it.
            if (!(new RequestGuard(''))->checkRead($server)
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            return self::answers($server, $pdo());
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'answers']);
            return Response::error($e);
        }
    }

    // The rows behind answersApi, also rendered into the page by index.php, which is checkRead by design.
    public static function answers(array $server, PDO $pdo): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $rows = $pdo->query('SELECT id, project, question, status, answer, error, usage, created_at, answered_at
                                 FROM asks ORDER BY id DESC LIMIT ' . AskHandler::KEEP)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['usage'] = $row['usage'] === null ? null : json_decode($row['usage'], true);
            }
            unset($row);
            return Response::json(200, ['answers' => $rows]);
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }
}
