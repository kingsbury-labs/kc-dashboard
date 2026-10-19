<?php
// The daily read. Usage: php bin/daily-read.php [--db=PATH] [--log=PATH] [--force]
// One claude call a day ranks the attention list; a second run the same day does nothing unless --force.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Attention/DailyRead.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/daily-read.log', 'force' => false];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--force') {
        $opts['force'] = true;
    }
}

$log = new Log($opts['log']);
try {
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $read = new DailyRead($pdo, ClaudeRunner::fromConfig());
    $now = time();
    if (!$opts['force'] && !$read->due($now)) {
        echo "daily-read: already ran today\n";
        exit(0);
    }
    $r = $read->run($now);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'daily-read']);
    fwrite(STDERR, "daily-read: failed\n");
    exit(1);
}
echo 'daily-read: ' . $r['status'] . ', ' . $r['sent'] . " facts sent\n";
if ($r['error'] !== null) {
    $log->error('daily read failed', ['error' => $r['error']]);
    exit(1);
}
exit(0);
