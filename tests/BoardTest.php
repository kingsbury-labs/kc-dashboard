<?php
require_once __DIR__ . '/../src/Vault/Vault.php';
require_once __DIR__ . '/../src/Vault/Board.php';

function board_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcboard_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function board_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($dir . '\\' . $f) ? board_rmtree($dir . '\\' . $f) : @unlink($dir . '\\' . $f);
        }
    }
    @rmdir($dir);
}

test('a missing board is created with the kanban front matter and the three columns', function () {
    $t = board_temp();
    try {
        assert_same(true, Board::ensure(new Vault($t . '\\kc')));
        $md = file_get_contents($t . '\\kc\\Boards\\rob.md');
        assert_same(1, preg_match('/\A---\n\s*kanban-plugin: basic\s*\n---\n/', $md), 'front matter');
        preg_match_all('/^## (.+)$/m', $md, $m);
        assert_same(['Backlog', 'Doing', 'Done'], $m[1], 'columns in order');
    } finally {
        board_rmtree($t);
    }
});

test("an existing board's bytes are unchanged after a run", function () {
    $t = board_temp();
    try {
        $vault = new Vault($t . '\\kc');
        Board::ensure($vault);
        $path = $t . '\\kc\\Boards\\rob.md';
        $mine = "---\n\nkanban-plugin: basic\n\n---\n\n## Backlog\n\n- [ ] Rob's card\n\n## Doing\n\n## Done\n";
        file_put_contents($path, $mine);
        touch($path, time() - 1000);
        clearstatcache(true);
        $mtime = filemtime($path);
        assert_same(false, Board::ensure($vault), 'reports no write');
        clearstatcache(true);
        assert_same($mine, file_get_contents($path), 'bytes');
        assert_same($mtime, filemtime($path), 'mtime');
    } finally {
        board_rmtree($t);
    }
});
