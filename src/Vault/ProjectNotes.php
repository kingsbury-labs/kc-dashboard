<?php
require_once __DIR__ . '/Vault.php';

// One `Projects/<slug>.md` per projects-table row (card 73), linked to its client note with `[[client-slug]]` so
// Obsidian's graph joins projects, clients and briefings. Every value from git or the loop is neutralised first.
class ProjectNotes
{
    private Vault $vault;
    /** @var array<string,string> project note slug => client slug */
    private array $clients;
    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(Vault $vault, array $clients)
    {
        $this->vault = $vault;
        $this->clients = $clients;
    }

    // A folder name as a vault file name: lower case, `[a-z0-9-]` only. Empty when nothing is left.
    public static function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>> note slug => row, for every note written or left as it was
     */
    public function write(array $rows): array
    {
        $notes = [];
        $this->errors = [];
        foreach ($rows as $row) {
            $slug = self::slug((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            // Two folders that fold to one name (`Site` and `site`) get distinct notes, never one overwriting the other.
            $base = $slug;
            for ($n = 2; isset($notes[$slug]) || isset($this->errors[$slug]); $n++) {
                $slug = $base . '-' . $n;
            }
            // One project that cannot be written (a reserved name, a locked file) keeps its error and the run goes on.
            try {
                $this->vault->write('Projects/' . $slug . '.md', $this->render($slug, $row));
                $notes[$slug] = $row;
            } catch (Throwable $e) {
                $this->errors[$slug] = $e->getMessage();
            }
        }
        return $notes;
    }

    /** @return array<string,string> note slug => why its note was not written, for the last write() */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(string $slug, array $row): string
    {
        $v = fn($key) => Vault::neutralise((string) ($row[$key] ?? ''));
        // JSON strings are valid YAML double-quoted scalars, so no value can end the front matter or start a key.
        $q = fn(string $s) => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // The commit's day, from git's %cI; anything else is left blank.
        $date = preg_match('/^(\d{4}-\d{2}-\d{2})T/', (string) ($row['last_date'] ?? ''), $m) ? $m[1] : '';
        $lines = ['---',
            'branch: ' . $q($v('branch')),
            'loop: ' . $q($v('loop_state')),
            'updated: ' . $q($date),
            '---',
            '',
            '# ' . Vault::neutralise((string) ($row['slug'] ?? $slug)),
            ''];
        $client = $this->clients[$slug] ?? null;
        if (is_string($client) && preg_match('/^[a-z0-9-]+$/D', $client)) {
            $lines[] = 'Client: [[' . $client . ']]';
        }
        if (($row['last_subject'] ?? null) !== null) {
            $lines[] = 'Last commit: ' . $v('last_subject') . ($date !== '' ? ' (' . $date . ')' : '');
        }
        $loop = 'Loop: ' . ($v('loop_state') !== '' ? $v('loop_state') : 'unknown');
        foreach (['loop_card' => 'card', 'loop_note' => 'note'] as $key => $label) {
            if (($row[$key] ?? null) !== null) {
                $loop .= ', ' . $label . ' ' . $v($key);
            }
        }
        $lines[] = $loop;
        if (($row['checklist_cards'] ?? null) !== null) {
            $lines[] = 'Checklist: ' . (int) $row['checklist_passing'] . ' of ' . (int) $row['checklist_cards'] . ' passing';
        }
        if (($row['error'] ?? null) !== null) {
            $lines[] = 'Error: ' . $v('error');
        }
        return implode("\n", $lines) . "\n";
    }
}
