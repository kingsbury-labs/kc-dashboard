<?php
require_once __DIR__ . '/../Vault/Vault.php';
require_once __DIR__ . '/../Fs/SafeDir.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/Response.php';
require_once __DIR__ . '/../Log.php';

// What Rob tells the dashboard to remember: one `- HH:MM text` line appended to Memory/YYYY-MM.md, and a row with
// an FTS5 index so recall finds it by any word. The text is neutralised before either sees it.
class MemoryStore
{
    public const MAX_CHARS = 2000;
    public const RECALL_LIMIT = 50;

    // Recall needs no vault; remember does.
    public function __construct(private PDO $pdo, private ?Vault $vault = null)
    {
    }

    public static function tooLong(string $text): bool
    {
        return mb_strlen(mb_scrub($text, 'UTF-8'), 'UTF-8') > self::MAX_CHARS;
    }

    // The line written, or null when the text is empty or over MAX_CHARS characters (nothing is saved then).
    public function remember(string $text, int $now): ?string
    {
        if ($this->vault === null) {
            throw new LogicException('remember needs the vault');
        }
        if (self::tooLong($text)) {
            return null;
        }
        $clean = Vault::neutralise($text);
        if ($clean === '') {
            return null;
        }
        $line = '- ' . date('H:i', $now) . ' ' . $clean;
        $month = date('Y-m', $now) . '.md';
        $current = $this->current($month);
        if ($current !== '' && !str_ends_with($current, "\n")) {
            $current .= "\n";
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO memories (created_at, text) VALUES (?, ?)')->execute([$now, $clean]);
            $this->pdo->prepare('INSERT INTO memories_fts (rowid, text) VALUES (?, ?)')
                ->execute([(int) $this->pdo->lastInsertId(), $clean]);
            $this->vault->write('Memory/' . $month, $current . $line . "\n");
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $line;
    }

    /**
     * Memories holding every word of $query, newest first: [{date, time, text}]. Each word is quoted, so nothing in
     * the query is read as FTS5 syntax.
     */
    public function recall(string $query, int $limit = self::RECALL_LIMIT): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_scrub($query, 'UTF-8'), $m);
        $words = array_slice(array_values(array_unique($m[0])), 0, 20);
        if ($words === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT m.created_at, m.text FROM memories_fts JOIN memories m ON m.id = memories_fts.rowid
            WHERE memories_fts MATCH ? ORDER BY m.created_at DESC, m.id DESC LIMIT ' . max(1, $limit));
        $st->execute([implode(' ', array_map(fn($w) => '"' . $w . '"', $words))]);
        return array_map(fn($r) => ['date' => date('Y-m-d', (int) $r['created_at']), 'time' => date('H:i', (int) $r['created_at']),
            'text' => (string) $r['text']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM memories')->fetchColumn();
    }

    // The month file as it is now, read only through a plain Memory folder and a regular file; '' when there is none.
    private function current(string $name): string
    {
        $root = $this->vault->root();
        clearstatcache(true);
        if (@lstat($root . '\\Memory') === false || !SafeDir::isDirectChild($root, 'Memory')) {
            return '';
        }
        $st = @lstat($root . '\\Memory\\' . $name);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            return '';
        }
        return (string) @file_get_contents($root . '\\Memory\\' . $name);
    }
}

// POST /api/remember.php and GET /api/recall.php. The address and Host checks run before the token is read, and
// the database and vault open only after the full check passes, so a refused request touches nothing.
class MemoryApi
{
    /** @param Closure(): array{0: PDO, 1: Vault} $open */
    public static function remember(array $server, string $body, string $tokenPath, Closure $open, Log $log, ?int $now = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || !(new RequestGuard(Token::load($tokenPath, $log)))->checkWrite($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $data = json_decode($body, true);
            $text = is_array($data) && is_string($data['text'] ?? null) ? $data['text'] : '';
            if (trim($text) === '') {
                return Response::json(400, ['error' => 'text is required']);
            }
            if (MemoryStore::tooLong($text)) {
                return Response::json(400, ['error' => 'text is over ' . MemoryStore::MAX_CHARS . ' characters']);
            }
            [$pdo, $vault] = $open();
            $line = (new MemoryStore($pdo, $vault))->remember($text, $now ?? time());
            return $line === null ? Response::json(400, ['error' => 'text is required']) : Response::json(200, ['saved' => $line]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'remember']);
            return Response::error($e);
        }
    }

    public static function recall(array $server, array $query, string $tokenPath, Closure $pdo, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server) || ($server['REQUEST_METHOD'] ?? null) !== 'GET'
                || !(new RequestGuard(Token::load($tokenPath, $log)))->checkApiRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $q = is_string($query['q'] ?? null) ? mb_substr($query['q'], 0, 200, 'UTF-8') : '';
            return Response::json(200, ['matches' => (new MemoryStore($pdo()))->recall($q)]);
        } catch (Throwable $e) {
            $log->exception($e, ['where' => 'recall']);
            return Response::error($e);
        }
    }
}
