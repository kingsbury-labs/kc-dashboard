<?php
require_once __DIR__ . '/../Projects/LoopState.php';
require_once __DIR__ . '/../Running/SessionRegistry.php';

// What the voice PM reads every 12 minutes: open asks, the conch holder, each project's loop, the queue, peer
// sessions, the go pending and the findings that match the HQ check. Reads only, and only the named files:
// every path opened lands in $opened, and nothing here lists a folder that could hold a key or a token.
class PmState
{
    public const STALL_MINUTES = 30;
    public const IDLE_MINUTES = 30;
    public const REFUSAL_MINUTES = 12;
    public const LIMITS = ['Small' => 20, 'Normal' => 60, 'Risky' => 120];
    public const MAX_FILE = 1000000;
    public const MAX_TEXT = 300;
    public const MAX_LIST = 50;

    /** @var string[] every path this reader opened */
    public array $opened = [];

    /**
     * @param array{asks:string,conch:string,refusals:string,go_pending:string} $paths
     * @param array<string,string> $projects slug => folder
     */
    public function __construct(private array $paths, private array $projects, private LoopState $loops,
        private SessionRegistry $sessions)
    {
    }

    public function build(int $now): array
    {
        $asks = $this->openAsks();
        $loops = [];
        $queue = [];
        $findings = [];
        foreach ($this->projects as $slug => $folder) {
            $loop = $this->loop($slug, rtrim($folder, '\\'), $now, $queue, $findings);
            if ($loop !== null) {
                $loops[] = $loop;
            }
        }
        $sessions = $this->peerSessions($now);
        foreach ($sessions as $s) {
            if (in_array($s['status'], ['idle', 'waiting'], true) && $s['idle_minutes'] !== null
                && $s['idle_minutes'] >= self::IDLE_MINUTES && $s['project'] !== null) {
                foreach ($asks as $ask) {
                    if (self::mentions($ask, $s['project'])) {
                        $findings[] = ['type' => 'idle_with_open_work', 'project' => $s['project'], 'pid' => $s['pid'],
                            'idle_minutes' => $s['idle_minutes'], 'ask' => $ask];
                        break;
                    }
                }
            }
        }
        foreach ($this->recentRefusals($now) as $r) {
            $findings[] = ['type' => 'chrome_without_conch', 'cwd' => $r['cwd'], 'tool' => $r['tool'], 'at' => $r['at']];
        }
        return [
            'generated_at' => $now,
            'asks' => $asks,
            'conch' => $this->conch(),
            'loops' => $loops,
            'queue' => $queue,
            'sessions' => $sessions,
            'go_pending' => $this->goPending(),
            'findings' => $findings,
        ];
    }

    private function loop(string $slug, string $folder, int $now, array &$queue, array &$findings): ?array
    {
        $loopDir = $folder . '\\.claude\\work\\loop';
        $work = $folder . '\\.claude\\work';
        try {
            $state = $this->loops->read($folder);
        } catch (Throwable $e) {
            return ['project' => $slug, 'state' => 'unreadable'];
        }
        // LoopState opens these three itself.
        array_push($this->opened, $work . '\\checklist.json');
        $running = $state['loop_state'] === 'running';
        $lastLog = null;
        if ($state['loop_state'] !== 'none') {
            array_push($this->opened, $loopDir . '\\lock');
            clearstatcache(true);
            $mt = @filemtime($loopDir . '\\loop.log');
            $lastLog = $mt === false ? null : $mt;
        }
        foreach ($this->unpassed($work . '\\checklist.json') as $card) {
            if (count($queue) < self::MAX_LIST) {
                $queue[] = ['project' => $slug] + $card;
            }
        }
        if ($running) {
            if ($lastLog !== null && $now - $lastLog >= self::STALL_MINUTES * 60) {
                $findings[] = ['type' => 'loop_stalled', 'project' => $slug, 'card' => $state['loop_card'],
                    'quiet_minutes' => intdiv($now - $lastLog, 60)];
            }
            $this->oversized($slug, $folder, $state['loop_card'], $now, $findings);
        }
        return ['project' => $slug, 'state' => $state['loop_state'], 'card' => $state['loop_card'],
            'note' => $state['loop_note'], 'last_log_at' => $lastLog,
            'cards' => $state['checklist_cards'], 'passing' => $state['checklist_passing']];
    }

    private function oversized(string $slug, string $folder, ?string $card, int $now, array &$findings): void
    {
        if ($card === null) {
            return;
        }
        $work = $folder . '\\.claude\\work';
        $brief = $this->briefFor($work, $card);
        if ($brief === null) {
            return;
        }
        $text = $this->readFile($brief) ?? '';
        $size = preg_match('/^#[^\n]*\((Small|Normal|Risky)\)\s*$/m', $text, $m) ? $m[1] : null;
        if ($size === null) {
            return;
        }
        $status = json_decode((string) $this->readFile($work . '\\loop\\status.json'), true);
        $started = is_array($status) ? self::time($status['card_started_at'] ?? $status['started_at'] ?? null) : null;
        $started ??= $this->cardStart($work . '\\loop', $card);
        if ($started !== null) {
            $minutes = intdiv($now - $started, 60);
            if ($minutes > self::LIMITS[$size]) {
                $findings[] = ['type' => 'card_oversized', 'project' => $slug, 'card' => $card, 'size' => $size,
                    'minutes' => $minutes, 'limit' => self::LIMITS[$size]];
            }
        }
        if ($size !== 'Risky') {
            $hit = $this->riskyPathNamed($folder . '\\.claude\\project.md', $text);
            if ($hit !== null) {
                $findings[] = ['type' => 'card_oversized', 'project' => $slug, 'card' => $card, 'size' => $size,
                    'risky_path' => $hit];
            }
        }
    }

    // The brief of a card: the `brief.md` in the work folder named `<card>` or `<card>-...`.
    private function briefFor(string $work, string $card): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $card)) {
            return null;
        }
        foreach (@scandir($work) ?: [] as $dir) {
            if ($dir === $card || strncmp($dir, $card . '-', strlen($card) + 1) === 0) {
                $path = $work . '\\' . $dir . '\\brief.md';
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        return null;
    }

    // When the card began: the creation time of the newest `*-card-<card>.jsonl` in the loop folder.
    private function cardStart(string $loopDir, string $card): ?int
    {
        $best = null;
        foreach (@scandir($loopDir) ?: [] as $f) {
            if (preg_match('/-card-' . preg_quote($card, '/') . '\.jsonl\z/', $f)) {
                clearstatcache(true);
                $t = @filectime($loopDir . '\\' . $f);
                if ($t !== false && ($best === null || $t > $best)) {
                    $best = $t;
                }
            }
        }
        return $best;
    }

    // The first risky path from the project's project.md that the brief text names, or null.
    private function riskyPathNamed(string $projectMd, string $brief): ?string
    {
        $md = $this->readFile($projectMd);
        if ($md === null || !preg_match('/\*\*Risky paths\*\*(.*?)(?:\r?\n\s*\r?\n|\z)/s', $md, $m)) {
            return null;
        }
        $hay = strtolower(str_replace('\\', '/', $brief));
        preg_match_all('/`([^`]+)`/', $m[1], $found);
        foreach ($found[1] as $path) {
            $p = strtolower(str_replace('\\', '/', trim($path)));
            if ($p !== '' && str_contains($hay, $p)) {
                return $path;
            }
        }
        return null;
    }

    /** @return array<int,array{id:?string,title:?string}> */
    private function unpassed(string $checklist): array
    {
        $list = json_decode((string) $this->readFile($checklist), true);
        $out = [];
        if (is_array($list) && array_is_list($list)) {
            foreach ($list as $entry) {
                if (is_array($entry) && ($entry['passes'] ?? null) !== true) {
                    $out[] = ['id' => self::text($entry['id'] ?? $entry['card'] ?? null),
                        'title' => self::text($entry['title'] ?? $entry['name'] ?? null)];
                }
            }
        }
        return $out;
    }

    /** @return string[] */
    private function openAsks(): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $this->readFile($this->paths['asks'])) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_contains($line, 'CLOSED') && count($out) < self::MAX_LIST) {
                $out[] = self::text($line);
            }
        }
        return $out;
    }

    private function conch(): ?array
    {
        $c = json_decode((string) $this->readFile($this->paths['conch']), true);
        if (!is_array($c)) {
            return null;
        }
        return ['project' => self::text($c['project'] ?? null), 'path' => self::text($c['path'] ?? null),
            'since' => self::text($c['since'] ?? null), 'by' => self::text($c['by'] ?? null)];
    }

    private function goPending(): ?array
    {
        $g = json_decode((string) $this->readFile($this->paths['go_pending']), true);
        if (!is_array($g)) {
            return null;
        }
        $out = [];
        foreach ($g as $k => $v) {
            // Approvals are signed: nothing that looks like a key, MAC or token is carried over.
            if (is_string($k) && !preg_match('/mac|key|sig|token|secret/i', $k) && (is_scalar($v) || $v === null)) {
                $out[$k] = is_string($v) ? self::text($v) : $v;
            }
        }
        return $out === [] ? null : $out;
    }

    /** @return array<int,array{at:int,cwd:?string,tool:?string}> */
    private function recentRefusals(int $now): array
    {
        $text = $this->readFile($this->paths['refusals']);
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            $r = json_decode($line, true);
            if (!is_array($r)) {
                continue;
            }
            $at = self::time($r['at'] ?? null);
            if ($at !== null && $now - $at <= self::REFUSAL_MINUTES * 60 && $at <= $now + 60) {
                $out[] = ['at' => $at, 'cwd' => self::text($r['cwd'] ?? null), 'tool' => self::text($r['tool'] ?? null)];
            }
        }
        return array_slice($out, -self::MAX_LIST);
    }

    private function peerSessions(int $now): array
    {
        try {
            $rows = $this->sessions->read();
        } catch (Throwable $e) {
            $rows = [];
        }
        array_push($this->opened, ...$this->sessions->opened);
        $out = [];
        foreach ($rows as $s) {
            $out[] = ['pid' => $s['pid'], 'project' => $s['project'], 'status' => $s['status'], 'name' => $s['name'],
                'idle_minutes' => $s['updatedAt'] === null ? null : max(0, intdiv($now - intdiv($s['updatedAt'], 1000), 60))];
        }
        return $out;
    }

    private static function mentions(string $ask, string $project): bool
    {
        return preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($project, '/') . '(?![A-Za-z0-9_-])/i', $ask) === 1;
    }

    private static function time($v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return (int) ($v > 99999999999 ? $v / 1000 : $v);
        }
        if (is_string($v) && ($t = strtotime($v)) !== false) {
            return $t;
        }
        return null;
    }

    // A regular file under MAX_FILE, checked with lstat so a link is never opened; null otherwise.
    private function readFile(string $path): ?string
    {
        clearstatcache(true);
        $lst = @lstat($path);
        if ($lst === false || ($lst['mode'] & 0170000) !== 0100000 || $lst['size'] > self::MAX_FILE) {
            return null;
        }
        $this->opened[] = $path;
        $s = @file_get_contents($path);
        return $s === false ? null : $s;
    }

    private static function text($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v), 0, self::MAX_TEXT, 'UTF-8');
    }
}
