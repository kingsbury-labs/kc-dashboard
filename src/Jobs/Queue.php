<?php

class Queue
{
    private PDO $pdo;
    /** @var callable(): int */
    private $clock;

    public function __construct(PDO $pdo, ?callable $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? 'time';
    }

    public function enqueue(string $type, array $payload = []): int
    {
        $this->pdo->prepare('INSERT INTO jobs (type, payload, created_at) VALUES (?, ?, ?)')
            ->execute([$type, json_encode($payload), $this->now()]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Claims the oldest queued job in one statement; null when none is free. */
    public function claim(): ?array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'running', claimed_at = ?, attempts = attempts + 1
             WHERE id = (SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1)
             AND status = 'queued'
             RETURNING *"
        );
        $stmt->execute([$this->now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'], true) ?? [];
        return $row;
    }

    public function complete(int $id, ?string $result = null): void
    {
        $this->finish($id, 'done', $result, null);
    }

    public function fail(int $id, string $error): void
    {
        $this->finish($id, 'failed', null, $error);
    }

    /** Puts running jobs claimed more than $timeoutS ago back to queued; returns how many. */
    public function recoverStale(int $timeoutS): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'queued', claimed_at = NULL
             WHERE status = 'running' AND claimed_at <= ?"
        );
        $stmt->execute([$this->now() - $timeoutS]);
        return $stmt->rowCount();
    }

    public function length(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'queued'")->fetchColumn();
    }

    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'], true) ?? [];
        return $row;
    }

    private function finish(int $id, string $status, ?string $result, ?string $error): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status = ?, result = ?, error = ?, finished_at = ? WHERE id = ? AND status = 'running'"
        )->execute([$status, $result, $error, $this->now(), $id]);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
