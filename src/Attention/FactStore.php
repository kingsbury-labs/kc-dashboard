<?php
require_once __DIR__ . '/../Log.php';

// The facts table, its append-only observations, the sources' health and Rob's labels. Every method takes the
// clock as an argument, so a test runs on a fixed one.
class FactStore
{
    public const SEVERITIES = ['high' => 3, 'med' => 2, 'low' => 1];
    public const REASONS = ['useful', 'already knew', 'not actionable', 'too early', 'irrelevant', 'wrong evidence', 'resolved'];
    // Days out at which a fact re-surfaces, once each, nearest last.
    public const CROSSINGS = [30, 7, 1];
    // Seconds since the last good run before a source shows as stale. Collectors run every 30 minutes, so the
    // default allows three missed runs; finance syncs once a day.
    public const MAX_AGES = ['finance' => 26 * 3600];
    public const DEFAULT_MAX_AGE = 2 * 3600;
    public const TOO_EARLY_SECONDS = 7 * 86400;
    // A last good run further ahead than this is a broken clock or a forged row, not a fresh source.
    public const FUTURE_SLACK = 300;
    // feedback.php refuses longer ids, so a longer one could never be labelled or cleared.
    public const MAX_ID_BYTES = 512;

    private const COLS = ['id', 'source', 'kind', 'subject', 'salient_hash', 'due_date', 'severity', 'amount_cents', 'evidence',
        'url', 'parser_version'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inserts a new fact as 'new', or updates a known id in place. A known fact goes back to 'new' only when its
     * salient hash changed, its severity rose, or it crossed a 30, 7 or 1 days-out mark it had not crossed yet.
     * The id must start with "<source>:", and a known id stays with the source that first recorded it.
     */
    public function record(array $fact, int $now): void
    {
        foreach (['id', 'source', 'kind', 'subject', 'salient_hash'] as $k) {
            if (!is_string($fact[$k] ?? null) || $fact[$k] === '') {
                throw new InvalidArgumentException('fact needs ' . $k);
            }
        }
        $id = $fact['id'];
        if (strlen($id) > self::MAX_ID_BYTES || !mb_check_encoding($id, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new InvalidArgumentException('fact id must be at most 512 bytes of UTF-8 with no control characters');
        }
        if (!str_starts_with($id, $fact['source'] . ':')) {
            throw new InvalidArgumentException('fact id must start with its source and a colon');
        }
        if (!isset(self::SEVERITIES[$fact['severity'] ?? ''])) {
            throw new InvalidArgumentException('fact severity must be high, med or low');
        }
        $due = $fact['due_date'] ?? null;
        if ($due !== null && (!is_string($due) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $due, $d) !== 1
            || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]))) {
            throw new InvalidArgumentException('fact due_date must be a real YYYY-MM-DD date');
        }
        $values = array_map(fn($c) => $fact[$c] ?? null, self::COLS);
        $mark = self::mark($due, $now);
        $old = $this->get($id);
        if ($old !== null && $old['source'] !== $fact['source']) {
            throw new InvalidArgumentException('fact id belongs to another source');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO observations (fact_id, salient_hash, severity, evidence, parser_version, collected_at)
                                 VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$fact['id'], $fact['salient_hash'], $fact['severity'], $fact['evidence'] ?? null,
                    $fact['parser_version'] ?? null, $now]);
            if ($old === null) {
                $this->pdo->prepare('INSERT INTO facts (' . implode(', ', self::COLS) . ', collected_at, state, crossing) VALUES ('
                    . implode(', ', array_fill(0, count(self::COLS), '?')) . ", ?, 'new', ?)")
                    ->execute(array_merge($values, [$now, $mark]));
            } else {
                $changed = $old['salient_hash'] !== $fact['salient_hash'];
                $rose = self::SEVERITIES[$fact['severity']] > (self::SEVERITIES[$old['severity']] ?? 0);
                $old_mark = $old['crossing'] === null ? null : (int) $old['crossing'];
                $crossed = $mark !== null && ($old_mark === null || $mark < $old_mark);
                // A moved due date starts its marks over, so a date pushed out re-shows at 30, 7 and 1 again.
                $moved = $old['due_date'] !== $due;
                $crossing = ($changed || $crossed || $moved) ? $mark : $old_mark;
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_slice(self::COLS, 1)));
                $resurface = $changed || $rose || $crossed;
                $this->pdo->prepare("UPDATE facts SET $set, collected_at = ?, crossing = ?"
                    . ($resurface ? ", state = 'new', snoozed_until = NULL" : '') . ' WHERE id = ?')
                    ->execute(array_merge(array_slice($values, 1), [$now, $crossing, $fact['id']]));
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // The nearest crossing mark a due date has reached on $now's day, or null when it is further than 30 days out.
    public static function mark(?string $due, int $now): ?int
    {
        if ($due === null) {
            return null;
        }
        // Rounded, since a day across a daylight-saving change is 23 or 25 hours long.
        $days = (int) round((strtotime($due . ' 00:00:00') - strtotime('today', $now)) / 86400);
        $mark = null;
        foreach (self::CROSSINGS as $c) {
            if ($days <= $c) {
                $mark = $c;
            }
        }
        return $mark;
    }

    public function get(string $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM facts WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function setState(string $id, string $state): void
    {
        $this->pdo->prepare('UPDATE facts SET state = ?, snoozed_until = NULL WHERE id = ?')->execute([$state, $id]);
    }

    public function snooze(string $id, int $until): void
    {
        $this->pdo->prepare("UPDATE facts SET state = 'snoozed', snoozed_until = ? WHERE id = ?")->execute([$until, $id]);
    }

    // Everything not dismissed or acted on, and not snoozed past $now.
    public function visible(int $now): array
    {
        $q = $this->pdo->prepare("SELECT * FROM facts WHERE state IN ('new', 'shown', 'held')
                                  OR (state = 'snoozed' AND (snoozed_until IS NULL OR snoozed_until <= ?)) ORDER BY id");
        $q->execute([$now]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Saves one label with the fact's current salient hash, then applies what the reason means for the fact:
     * resolved is acted on, too early snoozes a week, useful changes nothing, the rest dismiss it until it changes.
     * False for an unknown reason or fact, and then nothing is saved.
     */
    public function feedback(string $id, string $reason, int $now): bool
    {
        if (!in_array($reason, self::REASONS, true)) {
            return false;
        }
        $fact = $this->get($id);
        if ($fact === null) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO feedback (fact_id, salient_hash, reason, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$id, $fact['salient_hash'], $reason, $now]);
            if ($reason === 'resolved') {
                $this->setState($id, 'acted');
            } elseif ($reason === 'too early') {
                $this->snooze($id, $now + self::TOO_EARLY_SECONDS);
            } elseif ($reason !== 'useful') {
                $this->setState($id, 'dismissed');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public function labels(string $id): array
    {
        $q = $this->pdo->prepare('SELECT fact_id, salient_hash, reason, created_at FROM feedback WHERE fact_id = ? ORDER BY id');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sourceOk(string $name, int $now): void
    {
        $this->pdo->prepare("INSERT INTO sources (name, last_ok_at, last_error, status) VALUES (?, ?, NULL, 'ok')
                             ON CONFLICT (name) DO UPDATE SET last_ok_at = excluded.last_ok_at, last_error = NULL, status = 'ok'")
            ->execute([$name, $now]);
    }

    // A failed run keeps the last good time, so the page can say how long the source has been failing. The error
    // goes through the log's redaction first: it is shown on the page.
    public function sourceFailed(string $name, string $error, Log $log): void
    {
        $this->pdo->prepare("INSERT INTO sources (name, last_error, status) VALUES (?, ?, 'error')
                             ON CONFLICT (name) DO UPDATE SET last_error = excluded.last_error, status = 'error'")
            ->execute([$name, $log->redact($error)]);
    }

    // Every source with a stale flag: no good run at all, the last one older than the source's maximum age, or one
    // dated more than FUTURE_SLACK ahead of $now.
    public function sources(int $now, array $maxAges = self::MAX_AGES): array
    {
        $rows = $this->pdo->query('SELECT name, last_ok_at, last_error, status FROM sources ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $max = $maxAges[$r['name']] ?? self::DEFAULT_MAX_AGE;
            $r['last_ok_at'] = $r['last_ok_at'] === null ? null : (int) $r['last_ok_at'];
            $r['stale'] = $r['last_ok_at'] === null || $now - $r['last_ok_at'] > $max || $r['last_ok_at'] > $now + self::FUTURE_SLACK;
        }
        unset($r);
        return $rows;
    }
}
