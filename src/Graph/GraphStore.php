<?php
require_once __DIR__ . '/../Db.php';

/**
 * The project knowledge graph in the dashboard's SQLite (migration 010): typed nodes, typed edges with a source,
 * weight and confirmed flag. replace() swaps one source's edges and leaves the others. walk() follows edges of
 * weight 0.5 and up from one node to projects within three hops, never through another project or a vendor, so a
 * shared host does not tie two projects together.
 */
class GraphStore
{
    public const HOPS = 3;
    public const MIN_WEIGHT = 0.5;

    // Edge type => [forward words, backward words], for a path read aloud.
    private const VERBS = [
        'works_for' => ['works for', 'employs'],
        'owns' => ['owns', 'is owned by'],
        'has_domain' => ['has the domain', 'is the domain of'],
        'uses' => ['uses', 'is used by'],
        'has_repo' => ['has the repo', 'is the repo of'],
        'about' => ['is about', 'has the thread'],
        'emailed_about' => ['emailed about', 'was emailed about by'],
        'topic_of' => ['is a topic of', 'has the topic'],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        Db::migrate($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public static function norm(string $type, string $key): string
    {
        $key = strtolower(trim($key));
        return $type === 'domain' || $type === 'vendor' ? (string) preg_replace('/^www\./', '', $key) : $key;
    }

    public function node(string $type, string $key, ?string $label = null): int
    {
        $key = self::norm($type, $key);
        $this->pdo->prepare('INSERT INTO graph_node (type, key, label) VALUES (?, ?, ?) ON CONFLICT (type, key) DO NOTHING')
            ->execute([$type, $key, mb_substr($label ?? $key, 0, 120)]);
        return (int) $this->find($type, $key);
    }

    public function find(string $type, string $key): ?int
    {
        $q = $this->pdo->prepare('SELECT id FROM graph_node WHERE type = ? AND key = ?');
        $q->execute([$type, self::norm($type, $key)]);
        $id = $q->fetchColumn();
        if ($id === false && $type === 'person') {
            $q = $this->pdo->prepare('SELECT node FROM graph_alias WHERE alias = ?');
            $q->execute([self::norm($type, $key)]);
            $id = $q->fetchColumn();
        }
        return $id === false ? null : (int) $id;
    }

    /**
     * Replaces every edge from $source with $edges: each [srcType, srcKey, srcLabel|null, type, dstType, dstKey,
     * dstLabel|null, weight?, confirmed?].
     */
    public function replace(string $source, array $edges): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM graph_edge WHERE source = ?')->execute([$source]);
            $ins = $this->pdo->prepare('INSERT OR REPLACE INTO graph_edge (src, dst, type, source, weight, confirmed) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($edges as $e) {
                $a = $this->node($e[0], $e[1], $e[2]);
                $b = $this->node($e[4], $e[5], $e[6]);
                $ins->execute([$a, $b, $e[3], $source, (float) ($e[7] ?? 1), (int) ($e[8] ?? 0)]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return count($edges);
    }

    // One edge added beside the source's others (the model's fallback answers).
    public function add(string $source, array $e): void
    {
        $a = $this->node($e[0], $e[1], $e[2]);
        $b = $this->node($e[4], $e[5], $e[6]);
        $this->pdo->prepare('INSERT OR REPLACE INTO graph_edge (src, dst, type, source, weight, confirmed) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$a, $b, $e[3], $source, (float) ($e[7] ?? 1), (int) ($e[8] ?? 0)]);
    }

    /**
     * Projects reachable from $start: project node id => ['label', 'depth', 'confirmed' (a confirmed edge on the
     * path), 'reason' (the shortest path in words)].
     */
    public function walk(int $start): array
    {
        $sql = 'WITH RECURSIVE adj(a, b, eid, fwd) AS (
                SELECT src, dst, id, 1 FROM graph_edge WHERE weight >= :w
                UNION ALL SELECT dst, src, id, 0 FROM graph_edge WHERE weight >= :w),
            walk(node, depth, path, seen) AS (
                SELECT :s, 0, \'\', \',\' || :s || \',\'
                UNION ALL
                SELECT adj.b, walk.depth + 1, walk.path || adj.eid || \':\' || adj.fwd || \';\', walk.seen || adj.b || \',\'
                FROM walk JOIN adj ON adj.a = walk.node JOIN graph_node wn ON wn.id = walk.node
                WHERE walk.depth < :h AND instr(walk.seen, \',\' || adj.b || \',\') = 0
                    AND (walk.depth = 0 OR wn.type NOT IN (\'project\', \'vendor\')))
            SELECT walk.node, walk.depth, walk.path, n.label FROM walk JOIN graph_node n ON n.id = walk.node
            WHERE n.type = \'project\' ORDER BY walk.depth, walk.path';
        $q = $this->pdo->prepare($sql);
        $q->execute([':w' => self::MIN_WEIGHT, ':s' => $start, ':h' => self::HOPS]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $steps = $this->steps((string) $r['path']);
            $confirmed = in_array(true, array_column($steps, 'confirmed'), true);
            $id = (int) $r['node'];
            if (!isset($out[$id]) || ($confirmed && !$out[$id]['confirmed'])) {
                $out[$id] = ['label' => (string) $r['label'], 'depth' => (int) $r['depth'], 'confirmed' => $confirmed,
                    'reason' => $this->words($start, $steps)];
            }
        }
        return $out;
    }

    private function steps(string $path): array
    {
        $out = [];
        $q = $this->pdo->prepare('SELECT e.type, e.confirmed, s.label AS sl, d.label AS dl FROM graph_edge e
            JOIN graph_node s ON s.id = e.src JOIN graph_node d ON d.id = e.dst WHERE e.id = ?');
        foreach (array_filter(explode(';', $path)) as $step) {
            [$eid, $fwd] = array_map('intval', explode(':', $step));
            $q->execute([$eid]);
            $e = $q->fetch(PDO::FETCH_ASSOC);
            if ($e) {
                $out[] = ['type' => $e['type'], 'fwd' => $fwd === 1, 'confirmed' => (int) $e['confirmed'] === 1,
                    'from' => $fwd === 1 ? $e['sl'] : $e['dl'], 'to' => $fwd === 1 ? $e['dl'] : $e['sl']];
            }
        }
        return $out;
    }

    // "Ann Acme works for Acme Ltd, which owns acme-site".
    private function words(int $start, array $steps): string
    {
        $s = '';
        foreach ($steps as $i => $st) {
            $verb = self::VERBS[$st['type']][$st['fwd'] ? 0 : 1] ?? str_replace('_', ' ', $st['type']);
            if ($st['confirmed']) {
                $verb = 'was tagged by Rob as';
            }
            $s .= ($i === 0 ? $st['from'] . ' ' : ', which ') . $verb . ' ' . $st['to'];
        }
        return $s;
    }

    /** That project's people, clients, domains, vendors, repos and topics, labels by type. */
    public function neighbourhood(int $project): array
    {
        $q = $this->pdo->prepare('SELECT n.type, n.label FROM graph_edge e JOIN graph_node n ON n.id = CASE WHEN e.src = :p THEN e.dst ELSE e.src END
            WHERE (e.src = :p OR e.dst = :p) ORDER BY e.id DESC');
        $q->execute([':p' => $project]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['type']][] = $r['label'];
        }
        // People reach a project through its client too.
        $q = $this->pdo->prepare("SELECT DISTINCT p.label FROM graph_edge o JOIN graph_edge w ON w.dst = o.src AND w.type = 'works_for'
            JOIN graph_node p ON p.id = w.src WHERE o.type = 'owns' AND o.dst = ?");
        $q->execute([$project]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $label) {
            $out['person'][] = $label;
        }
        foreach ($out as $k => $v) {
            $out[$k] = array_values(array_unique($v));
        }
        if (isset($out['topic'])) {
            $out['topic'] = array_slice($out['topic'], 0, 5);
        }
        return $out;
    }

    /**
     * Lessons (traps, fixes, dream items) linked to a project or to any of its files, file matches first, then
     * project-only ones; within each, kinds in the order given, then label. Each is ['kind', 'label', 'match'].
     */
    public function lessons(string $project, array $files, array $kinds = ['trap', 'fix', 'dream']): array
    {
        $key = null;
        foreach ([$project, '_' . $project] as $try) {
            $id = $this->find('project', $try);
            if ($id !== null) {
                $key = self::norm('project', $try);
                break;
            }
        }
        if ($key === null || !$kinds) {
            return [];
        }
        $in = fn(array $a): string => implode(',', array_fill(0, count($a), '?'));
        $order = 'CASE n.type ' . implode(' ', array_map(fn($i) => "WHEN ? THEN $i", array_keys($kinds))) . ' END, n.label, n.key';
        $out = [];
        $seen = [];
        $fileIds = [];
        foreach ($files as $f) {
            $f = (string) preg_replace('~^(\./)+~', '', str_replace('\\', '/', trim((string) $f)));
            $fid = $f === '' ? null : $this->find('file', $key . ':' . $f);
            if ($fid !== null) {
                $fileIds[] = $fid;
            }
        }
        $runs = [];
        if ($fileIds) {
            $runs[] = ['file', 'names', $fileIds];
        }
        $runs[] = ['project', 'concerns', [(int) $this->find('project', $key)]];
        foreach ($runs as [$match, $type, $ids]) {
            $q = $this->pdo->prepare('SELECT DISTINCT n.id, n.type, n.label, n.key FROM graph_edge e JOIN graph_node n ON n.id = e.src
                WHERE e.type = ? AND e.dst IN (' . $in($ids) . ') AND n.type IN (' . $in($kinds) . ') ORDER BY ' . $order);
            $q->execute(array_merge([$type], $ids, $kinds, $kinds));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($seen[$r['id']])) {
                    $seen[$r['id']] = true;
                    $out[] = ['kind' => $r['type'], 'label' => $r['label'], 'match' => $match];
                }
            }
        }
        return $out;
    }

    /** @return array<int,string> project node id => label */
    public function projects(): array
    {
        return array_map('strval', $this->pdo->query("SELECT id, label FROM graph_node WHERE type = 'project' ORDER BY label")->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function state(string $key): ?string
    {
        $q = $this->pdo->prepare('SELECT value FROM graph_state WHERE key = ?');
        $q->execute([$key]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setState(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO graph_state (key, value) VALUES (?, ?)')->execute([$key, $value]);
    }

    public function counts(): array
    {
        return ['nodes' => (int) $this->pdo->query('SELECT COUNT(*) FROM graph_node')->fetchColumn(),
            'edges' => (int) $this->pdo->query('SELECT COUNT(*) FROM graph_edge')->fetchColumn()];
    }
}
