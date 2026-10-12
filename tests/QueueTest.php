<?php
require_once __DIR__ . '/../src/Jobs/Queue.php';

function queue_db(): array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcq_' . bin2hex(random_bytes(6)) . '.sqlite';
    $pdo = Db::open($path);
    Db::migrate($pdo);
    return [$path, $pdo];
}

function queue_cleanup(string $path): void
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

test('enqueue stores the payload and length counts queued jobs', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        $id = $q->enqueue('demo', ['a' => 1]);
        $q->enqueue('demo');
        assert_same(2, $q->length());
        assert_same(['a' => 1], $q->get($id)['payload']);
        assert_same('queued', $q->get($id)['status']);
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
    }
});

test('two connections claiming one job give one winner', function () {
    [$path, $pdo] = queue_db();
    try {
        $a = new Queue($pdo);
        $b = new Queue(Db::open($path));
        $a->enqueue('demo');
        $first = $a->claim();
        $second = $b->claim();
        assert_true($first !== null, 'first claim should win');
        assert_same(null, $second, 'second claim');
        assert_same('running', $first['status']);
        assert_same(1, (int) $first['attempts']);
        $pdo = $a = $b = null;
    } finally {
        queue_cleanup($path);
    }
});

test('claim takes jobs oldest first and returns null when empty', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        assert_same(null, $q->claim());
        $one = $q->enqueue('demo');
        $two = $q->enqueue('demo');
        assert_same($one, (int) $q->claim()['id']);
        assert_same($two, (int) $q->claim()['id']);
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
    }
});

test('complete stores the result and a failed job stores its error', function () {
    [$path, $pdo] = queue_db();
    try {
        $q = new Queue($pdo);
        $ok = $q->enqueue('demo');
        $bad = $q->enqueue('demo');
        $q->claim();
        $q->claim();
        $q->complete($ok, 'answer');
        $q->fail($bad, 'boom: no such host');
        $done = $q->get($ok);
        $failed = $q->get($bad);
        assert_same('done', $done['status']);
        assert_same('answer', $done['result']);
        assert_same('failed', $failed['status']);
        assert_same('boom: no such host', $failed['error']);
        assert_true($failed['finished_at'] !== null, 'finished_at set');
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
    }
});

test('a claim older than the timeout returns to queued, a fresh one stays', function () {
    [$path, $pdo] = queue_db();
    try {
        $now = 1000;
        $q = new Queue($pdo, function () use (&$now) { return $now; });
        $old = $q->enqueue('demo');
        $q->claim();
        $now = 1500;
        $fresh = $q->enqueue('demo');
        $q->claim();
        $now = 1700;
        assert_same(1, $q->recoverStale(600));
        assert_same('queued', $q->get($old)['status']);
        assert_same('running', $q->get($fresh)['status']);
        assert_same(1, $q->length());
        $again = $q->claim();
        assert_same($old, (int) $again['id']);
        assert_same(2, (int) $again['attempts']);
        $pdo = $q = null;
    } finally {
        queue_cleanup($path);
    }
});
