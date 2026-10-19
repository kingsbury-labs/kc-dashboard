<?php
// Project scanner. Usage: php bin/collect-projects.php [--db=PATH] [--log=PATH]
// Scans every git repo under the configured root and extras and replaces the projects table. Reads only.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Projects/Scanner.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/collect-projects.log'];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $rows = Scanner::fromConfig($log)->scan();
    Scanner::store($pdo, $rows);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'collect-projects']);
    fwrite(STDERR, "collect-projects: failed\n");
    exit(1);
}
echo count($rows) . " projects\n";
exit(0);
