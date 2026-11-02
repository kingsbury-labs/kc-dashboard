<?php
require_once __DIR__ . '/GraphStore.php';
require_once __DIR__ . '/../Mail/Relevance.php';

/**
 * Which project an inbox message concerns, from the knowledge graph. Order: Rob's "not mine"; then the sender's
 * person node, vendor or domain walked to projects within three hops, where one project (or the one a confirmed
 * edge reaches) tags it with the path as the reason and no model call; a vendor used by several projects says so
 * instead of guessing. Otherwise, or on a tie, the local model sees the candidate projects with their neighbourhood
 * and answers {project, confidence, reason}; below 0.6 the message goes to Unsure. An answer the model gives is kept
 * as an unconfirmed low-weight edge, which the walk does not follow.
 */
class Classifier
{
    public const SURE = 0.6;
    public const CANDIDATES = 12;
    public const MODEL_WEIGHT = 0.2;

    private GraphStore $g;
    private string $senders;
    private ?Closure $ask;
    private array $rules;

    public const REASONS = [1 => 'A client contact on From, To or Cc', 2 => "The client's own domain", 3 => 'A thread Rob wrote to the client',
        4 => "Names the client's site or business", 5 => 'Tie broken by the sender name', 6 => 'An inquiry from the studio site'];

    // $ask: fn(string prompt): ?string, the model's raw answer; null means no model. $rules: MailRules::context, or a
    // bare contact list (ClientContacts::read).
    public function __construct(GraphStore $g, string $senders, ?Closure $ask, array $rules = [])
    {
        $this->g = $g;
        $this->senders = $senders;
        $this->ask = $ask;
        $this->rules = array_is_list($rules) ? ['contacts' => $rules] : $rules;
    }

    /** $m: from, addr, to, cc, sent_to, subject, text. @return array{project:?string, reason:string, unsure:bool, rule?:int, kind?:string} */
    public function tag(array $m): array
    {
        $addr = strtolower(trim((string) ($m['addr'] ?? '')));
        $learned = Relevance::senders($this->senders);
        if (array_key_exists($addr, $learned) && $learned[$addr] === null) {
            return ['project' => null, 'reason' => 'Rob marked this sender not his', 'unsure' => false];
        }
        // Rob's client pattern (MailRules, rules 1 to 6 in order) before the walk; Rob's own correction beats it.
        if (!array_key_exists($addr, $learned) && ($p = MailRules::place($m, $this->rules)) !== null) {
            return $p + ['reason' => self::REASONS[$p['rule']], 'unsure' => false];
        }
        $tie = [];
        foreach ($this->starts($addr) as [$type, $id]) {
            $found = $this->g->walk($id);
            if ($found === []) {
                continue;
            }
            $sure = array_filter($found, fn($p) => $p['confirmed']);
            $pick = $sure !== [] ? $sure : $found;
            if (count($pick) === 1) {
                $p = reset($pick);
                return ['project' => $p['label'], 'reason' => $p['reason'], 'unsure' => false];
            }
            if ($type === 'vendor') {
                $labels = array_column($pick, 'label');
                $vendor = (string) $this->g->pdo()->query('SELECT label FROM graph_node WHERE id = ' . (int) $id)->fetchColumn();
                return ['project' => $vendor . ', several projects', 'reason' => $vendor . ' is used by ' . implode(', ', $labels), 'unsure' => false];
            }
            $tie = $pick;
            break;
        }
        return $this->askModel($m, $addr, $tie);
    }

    // Where a sender enters the graph: its person node, then a vendor or domain for its address's domain.
    private function starts(string $addr): array
    {
        $out = [];
        if (($id = $this->g->find('person', $addr)) !== null) {
            $out[] = ['person', $id];
        }
        $domain = (string) substr((string) strrchr($addr, '@'), 1);
        for ($d = $domain; $d !== '' && str_contains($d, '.'); $d = (string) substr($d, strpos($d, '.') + 1)) {
            foreach (['vendor', 'domain'] as $type) {
                if (($id = $this->g->find($type, $d)) !== null) {
                    $out[] = [$type, $id];
                }
            }
        }
        return $out;
    }

    private function askModel(array $m, string $addr, array $tie): array
    {
        $unsure = ['project' => null, 'reason' => 'No path in the graph and the model was not sure', 'unsure' => true];
        if ($this->ask === null) {
            return $unsure;
        }
        $candidates = $tie !== [] ? $tie : array_map(fn($l) => ['label' => $l], $this->g->projects());
        $candidates = array_slice($candidates, 0, self::CANDIDATES, true);
        try {
            $answer = ($this->ask)(self::prompt($m, $this->context($candidates)));
        } catch (Throwable $e) {
            return $unsure;
        }
        $d = is_string($answer) ? self::json($answer) : null;
        $labels = array_column($candidates, 'label');
        $project = null;
        foreach ($labels as $l) {
            if (is_string($d['project'] ?? null) && strcasecmp($l, trim($d['project'])) === 0) {
                $project = $l;
            }
        }
        $confidence = is_numeric($d['confidence'] ?? null) ? (float) $d['confidence'] : 0.0;
        $reason = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($d['reason'] ?? ''))), 0, 200);
        if (is_array($d) && array_key_exists('project', $d) && $d['project'] === null && $confidence >= self::SURE) {
            return ['project' => null, 'reason' => $reason, 'unsure' => false];
        }
        if ($project === null || $confidence < self::SURE) {
            return ['project' => null, 'reason' => $reason !== '' ? $reason : $unsure['reason'], 'unsure' => true];
        }
        if ($addr !== '') {
            $this->g->add('model', ['person', $addr, $addr, 'emailed_about', 'project', $project, $project, self::MODEL_WEIGHT, 0]);
        }
        return ['project' => $project, 'reason' => 'The model: ' . ($reason !== '' ? $reason : 'best match'), 'unsure' => false];
    }

    // Each candidate with its neighbourhood, one paragraph each.
    private function context(array $candidates): string
    {
        $ids = array_flip($this->g->projects());
        $out = '';
        foreach ($candidates as $c) {
            $id = $ids[$c['label']] ?? null;
            $n = $id === null ? [] : $this->g->neighbourhood((int) $id);
            $out .= '- ' . $c['label'];
            foreach (['client' => 'client', 'person' => 'people', 'domain' => 'domains', 'vendor' => 'vendors', 'repo' => 'repo', 'topic' => 'recent threads'] as $k => $word) {
                if (!empty($n[$k])) {
                    $out .= "; $word: " . implode(', ', $n[$k]);
                }
            }
            $out .= "\n";
        }
        return $out;
    }

    public static function prompt(array $m, string $context): string
    {
        return "Rob's projects, with who and what belongs to each:\n" . $context
            . "Does this email concern one of these projects? Promotions, newsletters and personal shopping do not.\n"
            . "Answer only JSON: {\"project\": \"<one project name>\" or null, \"confidence\": 0 to 1, \"reason\": \"<one short sentence>\"}.\n\n"
            . 'From: ' . ($m['from'] ?? '') . ' <' . ($m['addr'] ?? '') . ">\nSubject: " . ($m['subject'] ?? '') . "\n\n"
            . mb_substr((string) ($m['text'] ?? ''), 0, Relevance::CHARS);
    }

    private static function json(string $answer): ?array
    {
        $d = json_decode(trim($answer), true);
        if (!is_array($d) && preg_match('/\{.*\}/s', $answer, $x)) {
            $d = json_decode($x[0], true);
        }
        return is_array($d) ? $d : null;
    }
}
