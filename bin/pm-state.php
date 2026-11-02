<?php
// Live PM state. Usage: php bin/pm-state.php --out=PATH   (or --out PATH)
// Writes one JSON file the voice PM reads every 12 minutes. Reads only; the file is replaced atomically.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Process/ProcessRunner.php';
require_once __DIR__ . '/../src/Projects/ProjectList.php';
require_once __DIR__ . '/../src/Projects/LoopState.php';
require_once __DIR__ . '/../src/Running/SessionRegistry.php';
require_once __DIR__ . '/../src/Pm/PmState.php';

$out = null;
$args = array_slice($_SERVER['argv'] ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    if (preg_match('/^--out=(.+)$/s', $args[$i], $m)) {
        $out = $m[1];
    } elseif ($args[$i] === '--out' && isset($args[$i + 1])) {
        $out = $args[++$i];
    }
}
if ($out === null || $out === '') {
    fwrite(STDERR, "pm-state: --out <file> is required\n");
    exit(2);
}

try {
    $c = require __DIR__ . '/../config/running.php';
    $exes = require __DIR__ . '/../config/executables.php';
    $list = ProjectList::fromConfig();
    $projects = [];
    foreach ($list->names() as $name) {
        $projects[$name] = rtrim($list->resolve($name)['parent'], '\\') . '\\' . $name;
    }
    $runner = ProcessRunner::fromConfig();
    $pm = (string) getenv('USERPROFILE') . '\\.claude\\pm';
    $paths = [
        'asks' => $pm . '\\asks.md',
        'conch' => $pm . '\\conch.json',
        'refusals' => $pm . '\\conch-refusals.jsonl',
        'go_pending' => (string) getenv('LOCALAPPDATA') . '\\cas-voice\\go-pending.json',
    ];
    $state = (new PmState($paths, $projects, new LoopState($runner, $exes['tasklist']),
        new SessionRegistry($c['sessions_dir'], $runner, $exes['powershell'], $projects, $c['aliases'] ?? [])))->build(time());
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = $out . '.tmp' . getmypid();
    if (file_put_contents($tmp, $json) === false || !@rename($tmp, $out)) {
        @unlink($tmp);
        throw new RuntimeException('could not write the output file');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "pm-state: failed: " . $e->getMessage() . "\n");
    exit(1);
}
echo count($state['findings']) . " findings\n";
exit(0);
