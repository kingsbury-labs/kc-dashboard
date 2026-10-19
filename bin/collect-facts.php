<?php
// Rule collectors. Usage: php bin/collect-facts.php [--db=PATH] [--log=PATH]
// Runs every collector, records its facts and its run in `sources`. One failing collector does not stop the rest.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Attention/FactStore.php';
require_once __DIR__ . '/../src/Net/CurlHttpClient.php';
require_once __DIR__ . '/../src/Collectors/SslExpiry.php';
require_once __DIR__ . '/../src/Collectors/EndOfLife.php';
require_once __DIR__ . '/../src/Collectors/ClientDates.php';
require_once __DIR__ . '/../src/Collectors/Finance.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/collect-facts.log'];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $cfg = require $root . '/config/collectors.php';
    $clients = require $root . '/config/clients.php';
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $results = Collector::run([
        new SslExpiry($cfg['ssl_hosts']),
        new EndOfLife(new CurlHttpClient(), $cfg['eol_products']),
        new ClientDates($clients['dir']),
        new Finance($cfg['finance_path'], $cfg['finance_aliases']),
    ], new FactStore($pdo), $log, time());
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'collect-facts']);
    fwrite(STDERR, "collect-facts: failed\n");
    exit(1);
}
$failed = 0;
foreach ($results as $name => $r) {
    echo $name . ': ' . (is_int($r) ? "$r facts" : 'error') . "\n";
    $failed += is_int($r) ? 0 : 1;
}
exit($failed > 0 ? 1 : 0);
