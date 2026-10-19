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

test('worker recovers a claim older than the stale timeout and runs it', function () {
    [$path, $pdo] = queue_db();
    try {
        // A worker that claimed these and died: one 1000 s ago, one 10 s ago.
        $old = new Queue($pdo, fn() => time() - 1000);
        $stale = $old->enqueue('test.echo', ['word' => 'again']);
        assert_same($stale, (int) $old->claim()['id']);
        $recent = new Queue($pdo, fn() => time() - 10);
        $live = $recent->enqueue('test.echo', ['word' => 'live']);
        assert_same($live, (int) $recent->claim()['id']);
        assert_same(0, worker_run($path, ['--stale=600']));
        $q = new Queue($pdo);
        assert_same('done', $q->get($stale)['status']);
        assert_same('echo:again', $q->get($stale)['result']);
        assert_same('2', (string) $q->get($stale)['attempts'], 'claimed twice');
        assert_same('running', $q->get($live)['status'], 'a recent claim is left alone');
        $pdo = $q = $old = $recent = null;
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
