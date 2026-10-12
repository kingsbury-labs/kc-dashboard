<?php
// Job worker. Usage: php bin/worker.php [--db=PATH] [--registry=FILE] [--budget=SECONDS] [--log=PATH]
// Claims queued jobs one at a time and runs them. The budget is checked between jobs only,
// so a running handler is never killed. Exits when the budget is spent or the queue is empty.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Jobs/Queue.php';
require_once __DIR__ . '/../src/Jobs/Registry.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'registry' => '', 'budget' => '50', 'log' => $root . '/data/worker.log'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(db|registry|budget|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
$deadline = time() + max(0, (int) $opts['budget']);

try {
    $registry = $opts['registry'] !== '' ? Registry::fromFile($opts['registry']) : new Registry();
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $queue = new Queue($pdo);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'worker start']);
    fwrite(STDERR, "worker: cannot start\n");
    exit(1);
}

while (time() < $deadline) {
    $job = $queue->claim();
    if ($job === null) {
        break;
    }
    $id = (int) $job['id'];
    try {
        $queue->complete($id, $registry->run($job['type'], $job['payload']));
    } catch (Throwable $e) {
        $queue->fail($id, $e->getMessage());
        $log->exception($e, ['job' => $id, 'type' => $job['type']]);
    }
}
exit(0);
