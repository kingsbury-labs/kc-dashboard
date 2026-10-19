<?php
// News feeds. Usage: php bin/fetch-news.php [--db=PATH] [--log=PATH]
// Fetches every feed in config/feeds.php and stores its items. One failing feed does not stop the rest.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Net/CurlHttpClient.php';
require_once __DIR__ . '/../src/News/FetchNews.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/fetch-news.log'];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $cfg = require $root . '/config/feeds.php';
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $results = (new FetchNews(new CurlHttpClient(), $pdo, $cfg['feeds']))->run(time(), $log);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'fetch-news']);
    fwrite(STDERR, "fetch-news: failed\n");
    exit(1);
}
$failed = 0;
foreach ($results as $name => $r) {
    echo $name . ': ' . (is_int($r) ? "$r items" : 'error') . "\n";
    $failed += is_int($r) ? 0 : 1;
}
exit($failed > 0 ? 1 : 0);
