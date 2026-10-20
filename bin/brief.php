<?php
// The day's briefing. Usage: php bin/brief.php [--db=PATH] [--log=PATH]
// Builds Briefings/YYYY-MM-DD.md in the vault and stores it; a second run the same day overwrites only today's.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Briefing/Composer.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/brief.log'];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $now = time();
    $b = (new Composer($pdo, Vault::fromConfig()))->build(Composer::gather($pdo, $now), $now);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'brief']);
    fwrite(STDERR, "brief: failed\n");
    exit(1);
}
echo 'brief: wrote ' . $b['day'] . ', ' . count($b['facts']) . " facts\n";
exit(0);
