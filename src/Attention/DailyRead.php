<?php
require_once __DIR__ . '/FactStore.php';
require_once __DIR__ . '/Selector.php';
require_once __DIR__ . '/../Claude/ClaudeRunner.php';
require_once __DIR__ . '/../Llm/Prompt.php';
require_once __DIR__ . '/../Llm/StructuredOutput.php';

/**
 * One model call a day that ranks the attention list. It sends the facts that are new or changed since the last
 * good read in full, and every other visible fact as id plus running state, all inside Prompt's data block, and
 * runs it through ClaudeRunner's untrusted mode. The model may rank and give a reason; it never hides a fact, never
 * names one it was not sent, and never supplies a url.
 */
class DailyRead
{
    public const TASK = 'You are ranking the items on a sole proprietor\'s attention list for today. The data block is'
        . ' JSON: "new" holds items that are new or changed since the last read, "running" holds the items already on'
        . ' the list by id and state. Rank the items that most need attention today, 1 first. Reply with only a JSON'
        . ' object of the form {"items": [{"id": "<an id from the data>", "rank": 1, "reason": "<one plain sentence>"}]}.'
        . ' Use only ids that appear in the data. Put no links or URLs in a reason.';
    public const REASON_MAX = 280;
    // Characters of each text field sent, and bytes of the whole data block.
    public const TEXT_CAPS = ['kind' => 200, 'subject' => 200, 'evidence' => 500];
    public const DATA_MAX_BYTES = 65536;
    public const RESULT_MAX = 65536;
    public const ITEM_SCHEMA = [
        'id' => ['type' => 'string', 'required' => true],
        'rank' => ['type' => 'int', 'required' => true],
        'reason' => ['type' => 'string', 'max' => self::REASON_MAX],
    ];
    private const SENT_COLS = ['id', 'source', 'kind', 'subject', 'due_date', 'severity', 'amount_cents', 'evidence'];

    private PDO $pdo;
    private ClaudeRunner $runner;
    /** @var ?callable(): string */
    private $boundaries;
    private int $maxBytes;

    public function __construct(PDO $pdo, ClaudeRunner $runner, ?callable $boundaries = null, int $maxBytes = self::DATA_MAX_BYTES)
    {
        $this->pdo = $pdo;
        $this->runner = $runner;
        $this->boundaries = $boundaries;
        $this->maxBytes = $maxBytes;
    }

    // True when no read, good or failed, has started since midnight of $now's day.
    public function due(int $now): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM reads WHERE created_at >= ? LIMIT 1');
        $q->execute([strtotime('today', $now)]);
        return $q->fetchColumn() === false;
    }

    /**
     * Runs one read. "nothing new" makes no call. A failed call or a reply that is not valid is stored with its
     * error and a NULL ranking, so the previous ranking stands and the next read sends the same facts again.
     * @return array{status: string, sent: int, error: ?string}
     */
    public function run(int $now): array
    {
        // Most pressing first, so a fact that misses the byte cap is one that can best wait for the next read.
        $picked = Selector::select((new FactStore($this->pdo))->visible($now), PHP_INT_MAX);
        $facts = array_merge($picked['shown'], $picked['also']);
        $known = $this->lastGood()['known'] ?? [];
        $new = [];
        $running = [];
        $carried = [];
        // The skeleton {"new":[],"running":[]} plus, below, each item and its comma.
        $bytes = 25;
        foreach ($facts as $f) {
            $fp = self::fingerprint($f);
            $isNew = ($known[$f['id']] ?? null) !== $fp;
            if ($isNew) {
                $item = [];
                foreach (self::SENT_COLS as $c) {
                    $item[$c] = $f[$c] ?? null;
                }
                $item['amount_cents'] = $item['amount_cents'] === null ? null : (int) $item['amount_cents'];
                foreach (self::TEXT_CAPS as $c => $max) {
                    if (is_string($item[$c])) {
                        $item[$c] = mb_substr($item[$c], 0, $max, 'UTF-8');
                    }
                }
            } else {
                $item = ['id' => $f['id'], 'state' => $f['state'], 'severity' => $f['severity'], 'due_date' => $f['due_date']];
            }
            $size = strlen(self::encode($item)) + 1;
            if ($bytes + $size > $this->maxBytes) {
                // Over the cap: not sent, so a new one stays new for the next read, and a running one keeps the
                // fingerprint it had. Either way it is still on the page.
                if (!$isNew) {
                    $carried[$f['id']] = $fp;
                }
                continue;
            }
            $bytes += $size;
            $carried[$f['id']] = $fp;
            if ($isNew) {
                $new[] = $item;
            } else {
                $running[] = $item;
            }
        }
        if ($new === []) {
            return ['status' => 'nothing new', 'sent' => 0, 'error' => null];
        }
        $data = self::encode(['new' => $new, 'running' => $running]);
        $prompt = new Prompt(self::TASK, $data, $this->boundaries);
        $sent = array_merge(array_column($new, 'id'), array_column($running, 'id'));
        $fingerprints = $carried;
        $r = $this->runner->run($prompt);
        $ranking = $r['ok'] ? self::parseReply((string) $r['result'], $sent) : null;
        $error = !$r['ok'] ? (string) $r['error'] : ($ranking === null ? 'the reply was not a valid ranking' : null);
        $result = is_string($r['result']) ? mb_substr($r['result'], 0, self::RESULT_MAX, 'UTF-8') : null;
        $this->pdo->prepare('INSERT INTO reads (created_at, prompt_hash, ids_sent, known, result, ranking, usage, error)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$now, hash('sha256', $prompt->text()), json_encode($sent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode((object) $fingerprints, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result,
                $ranking === null ? null : json_encode((object) $ranking, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $r['usage'] === null ? null : json_encode($r['usage']), $error]);
        return ['status' => $error === null ? 'ok' : 'failed', 'sent' => count($sent), 'error' => $error];
    }

    /** The last good read's ranking: id => ['rank' => int, 'reason' => ?string], rank 1 first. Empty before one. */
    public function ranking(): array
    {
        return $this->lastGood()['ranking'] ?? [];
    }

    // Adds each fact's rank and reason, null when the read left it out. Every fact goes back out: a model's
    // silence or a last place is never a reason to hide one.
    public function annotate(array $facts): array
    {
        $ranking = $this->ranking();
        foreach ($facts as &$f) {
            $f['rank'] = $ranking[$f['id'] ?? '']['rank'] ?? null;
            $f['reason'] = $ranking[$f['id'] ?? '']['reason'] ?? null;
        }
        unset($f);
        return $facts;
    }

    /**
     * Checks a reply against {"items": [{id, rank, reason}]}. An id that was not sent, a repeat, a bad item or a
     * rank below 1 is dropped; ranks are renumbered 1.. in the model's order. Null when the reply is not that shape.
     */
    public static function parseReply(string $text, array $sentIds): ?array
    {
        $text = trim($text);
        // Models often fence JSON even when told not to.
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $text, $m)) {
            $text = trim($m[1]);
        }
        $data = json_decode($text, true);
        if (!is_array($data) || !is_array($data['items'] ?? null) || !array_is_list($data['items'])) {
            return null;
        }
        $sent = array_flip($sentIds);
        $picked = [];
        foreach ($data['items'] as $i => $raw) {
            $item = is_array($raw) ? StructuredOutput::validate($raw, self::ITEM_SCHEMA) : null;
            if ($item === null || !isset($sent[$item['id']]) || isset($picked[$item['id']]) || $item['rank'] < 1) {
                continue;
            }
            $picked[$item['id']] = [$item['rank'], $i, isset($item['reason']) ? self::scrubReason($item['reason']) : null];
        }
        uasort($picked, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        $n = 0;
        foreach ($picked as $id => $p) {
            $out[(string) $id] = ['rank' => ++$n, 'reason' => $p[2]];
        }
        return $out;
    }

    /**
     * A reason is plain text or nothing. Any reason with a link shape in it is dropped whole, the fact kept: a
     * colon followed by a non-space (any scheme, slashless ones too), `//`, a backslash, `www.`, or a host name.
     * Failing closed costs a reason now and then; a missed shape would put a model-written link on the page.
     */
    public static function scrubReason(string $reason): ?string
    {
        $reason = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $reason) ?? '');
        if ($reason === '' || preg_match('~:\S|//|\\\\|www\.|\p{L}[\p{L}\p{N}-]*\.\p{L}{2,}~iu', $reason) !== 0) {
            return null;
        }
        return $reason;
    }

    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // What makes a fact worth sending again in full: its salient hash, severity or due date changed.
    private static function fingerprint(array $f): string
    {
        return hash('sha256', $f['salient_hash'] . "\0" . $f['severity'] . "\0" . ($f['due_date'] ?? ''));
    }

    private function lastGood(): ?array
    {
        $row = $this->pdo->query('SELECT known, ranking FROM reads WHERE ranking IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['known' => (array) json_decode($row['known'], true), 'ranking' => (array) json_decode($row['ranking'], true)];
    }
}
