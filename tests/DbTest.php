<?php
function db_temp_path(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcdb_' . bin2hex(random_bytes(6)) . '.sqlite';
}

function db_cleanup(string $path): void
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

test('migrating twice records one migration row', function () {
    $path = db_temp_path();
    try {
        $pdo = Db::open($path);
        Db::migrate($pdo);
        Db::migrate($pdo);
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        $pdo = null;
    } finally {
        db_cleanup($path);
    }
});

test('open sets WAL and busy_timeout', function () {
    $path = db_temp_path();
    try {
        $pdo = Db::open($path);
        assert_same('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        assert_same(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $pdo = null;
    } finally {
        db_cleanup($path);
    }
});
