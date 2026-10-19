<?php
require_once __DIR__ . '/Collector.php';

// kc-finance's alerts.json (contract: .claude/work/finance-contract.md), read only. Schema 1 only. Each fact keeps
// the contract's fields and nothing else, so an extra field in the file never reaches the database. The source's
// last good time is the file's generated_at.
class Finance extends Collector
{
    public const MAX_BYTES = 1048576;
    public const URL_PREFIX = 'http://127.0.0.1:8000/';

    private string $path;
    /** @var array<string,string> */
    private array $aliases;
    private ?int $generatedAt = null;

    public function __construct(string $path, array $aliases = [])
    {
        $this->path = $path;
        $this->aliases = $aliases;
    }

    public function name(): string
    {
        return 'finance';
    }

    public function lastOkAt(int $now): int
    {
        return $this->generatedAt ?? $now;
    }

    public function collect(int $now): array
    {
        $this->generatedAt = null;
        if (!is_file($this->path) || filesize($this->path) > self::MAX_BYTES) {
            throw new RuntimeException('finance alerts file is missing or too large');
        }
        $raw = file_get_contents($this->path);
        $data = $raw === false ? null : json_decode($raw, true, 16);
        if (!is_array($data)) {
            throw new RuntimeException('finance alerts file is not valid JSON');
        }
        if (($data['schema'] ?? null) !== 1) {
            throw new RuntimeException('finance alerts schema is not 1');
        }
        $generated = is_string($data['generated_at'] ?? null) ? strtotime($data['generated_at']) : false;
        if ($generated === false || !is_array($data['facts'] ?? null)) {
            throw new RuntimeException('finance alerts file has no generated_at or facts list');
        }
        // Every fact is checked before any is returned, so a bad one leaves nothing half recorded.
        $facts = [];
        foreach ($data['facts'] as $i => $f) {
            $facts[] = $this->map(is_array($f) ? $f : [], (int) $i);
        }
        $this->generatedAt = $generated;
        return $facts;
    }

    private function map(array $f, int $i): array
    {
        $id = $f['id'] ?? null;
        $subject = $f['subject'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($subject) || $subject === '' || !is_string($f['kind'] ?? null) || $f['kind'] === '') {
            throw new RuntimeException("finance fact $i needs id, kind and subject");
        }
        $id = 'finance:' . $id;
        if (strlen($id) > FactStore::MAX_ID_BYTES || !mb_check_encoding($id, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new RuntimeException("finance fact $i has an unusable id");
        }
        $severity = $f['severity'] ?? null;
        if (!is_string($severity) || !isset(FactStore::SEVERITIES[$severity])) {
            throw new RuntimeException("finance fact $i has no valid severity");
        }
        $due = $f['due_date'] ?? null;
        if ($due !== null && (!is_string($due) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $due, $d) !== 1 || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]))) {
            throw new RuntimeException("finance fact $i has a bad due_date");
        }
        $amount = $f['amount_cents'] ?? null;
        if ($amount !== null && !is_int($amount)) {
            throw new RuntimeException("finance fact $i has a bad amount_cents");
        }
        $evidence = is_string($f['evidence'] ?? null) ? $f['evidence'] : null;
        $url = is_string($f['url'] ?? null) && str_starts_with($f['url'], self::URL_PREFIX) ? $f['url'] : null;
        $subject = $this->aliases[$subject] ?? $subject;
        return [
            'id' => $id,
            'source' => 'finance',
            'kind' => $f['kind'],
            'subject' => $subject,
            // The due date and severity are in the hash, so a moved date or a raised severity re-surfaces the fact.
            'salient_hash' => sha1(implode('|', [$f['kind'], $subject, (string) $due, $severity, (string) $amount])),
            'due_date' => $due,
            'severity' => $severity,
            'amount_cents' => $amount,
            'evidence' => $evidence,
            'url' => $url,
            'parser_version' => 'finance/1',
        ];
    }
}
