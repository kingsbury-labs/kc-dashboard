<?php
// Lessons for a brief. Usage: php bin/brief-context.php --project <name> --files <a,b,...> [--limit N] [--db PATH]
// Prints, one per line, the traps, past fixes and pending dream proposal items the knowledge graph links to that
// project or to any of those files (paths relative to the project folder): "<kind> [file|project] <label>", file
// matches first, then project-only ones, at most --limit (default 8) of each kind. Reads only; no output means none.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Graph/GraphStore.php';

$opt = ['project' => '', 'files' => '', 'limit' => '8', 'db' => dirname(__DIR__) . '/data/dashboard.sqlite'];
$args = array_slice($_SERVER['argv'] ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    if (preg_match('/^--(project|files|limit|db)=(.*)$/s', $args[$i], $m)) {
        $opt[$m[1]] = $m[2];
    } elseif (preg_match('/^--(project|files|limit|db)$/', $args[$i], $m) && isset($args[$i + 1])) {
        $opt[$m[1]] = $args[++$i];
    }
}
if ($opt['project'] === '') {
    fwrite(STDERR, "brief-context: --project <name> is required\n");
    exit(2);
}
if (!is_file($opt['db'])) {
    fwrite(STDERR, "brief-context: no graph database at " . $opt['db'] . "\n");
    exit(1);
}

try {
    $g = new GraphStore(Db::open($opt['db']));
    $files = array_values(array_filter(array_map('trim', explode(',', $opt['files'])), fn($f) => $f !== ''));
    $limit = max(1, (int) $opt['limit']);
    $count = [];
    foreach ($g->lessons($opt['project'], $files) as $l) {
        $count[$l['kind']] = ($count[$l['kind']] ?? 0) + 1;
        if ($count[$l['kind']] <= $limit) {
            echo $l['kind'] . ' [' . $l['match'] . '] ' . $l['label'] . "\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "brief-context: failed: " . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
