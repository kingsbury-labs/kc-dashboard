<?php
require_once __DIR__ . '/../src/Jobs/Queue.php';
require_once __DIR__ . '/../src/Jobs/Registry.php';

function worker_run(string $db, array $extra = []): int
{
    $log = $db . '.log';
    $cmd = array_merge([
        PHP_BINARY, __DIR__ . '/../bin/worker.php',
        '--db=' . $db,
        '--registry=' . __DIR__ . '/fixtures/worker/registry.php',
        '--log=' . $log,
    ], $extra);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

test('worker runs a job to done with the handler output', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        $id = $q->enqueue('test.echo', ['word' => 'hi']);
        assert_same(0, worker_run($path));
        $job = $q->get($id);
        assert_same('done', $job['status']);
        assert_same('echo:hi', $job['result']);
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
        @unlink($path . '.log');
    }
});

test('worker fails an unknown type and a throwing handler without re-queueing', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        $unknown = $q->enqueue('no.such.type');
        $throws = $q->enqueue('test.throw');
        assert_same(0, worker_run($path));
        assert_same('failed', $q->get($unknown)['status']);
        assert_true(str_contains($q->get($unknown)['error'], 'Unknown job type'), 'unknown error text');
        assert_same('failed', $q->get($throws)['status']);
        assert_same('handler blew up', $q->get($throws)['error']);
        assert_same(0, $q->length());
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
        @unlink($path . '.log');
    }
});

test('worker with a spent budget leaves jobs queued', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        $id = $q->enqueue('test.echo');
        assert_same(0, worker_run($path, ['--budget=0']));
        assert_same('queued', $q->get($id)['status']);
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
        @unlink($path . '.log');
    }
});
