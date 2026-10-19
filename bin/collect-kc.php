<?php
// Numbers for every property. Usage: php bin/collect-kc.php [--db=PATH] [--log=PATH] [--fixture=PATH]
// GA4 and Search Console through bin/collect_ga.py, Sembr's status endpoint and Bugsink, from config/properties.php.
// One failing source records its error and the rest still store. --fixture runs the GA script's transform only.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

// This script does not load bootstrap.php, which sets the same: a stack trace never carries argument values.
ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Net/CurlHttpClient.php';
require_once __DIR__ . '/../src/Numbers/GaCollector.php';
require_once __DIR__ . '/../src/Numbers/StatusCollector.php';
require_once __DIR__ . '/../src/Numbers/BugsinkCollector.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/collect-kc.log', 'fixture' => null];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log|fixture)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $cfg = require $root . '/config/properties.php';
    $exes = require $root . '/config/executables.php';
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $collectors = [
        new GaCollector(ProcessRunner::fromConfig(), $exes['python'], realpath($root . '/bin/collect_ga.py'), $cfg, $opts['fixture']),
        new StatusCollector(new CurlHttpClient(), $cfg['status_url']),
        new BugsinkCollector($cfg['bugsink'], $log),
    ];
    $results = NumbersCollector::run($collectors, new NumberStore($pdo), $log, time());
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'collect-kc']);
    fwrite(STDERR, "collect-kc: failed\n");
    exit(1);
}
$failed = 0;
foreach ($results as $name => $r) {
    echo $name . ': ' . (is_int($r) ? "$r values" : 'error') . "\n";
    $failed += is_int($r) ? 0 : 1;
}
exit($failed > 0 ? 1 : 0);
