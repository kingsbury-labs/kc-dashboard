<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Memory/MemoryStore.php';

define('MEM_NOW', strtotime('2026-10-19 09:41:00'));

function mem_world(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcmem_' . bin2hex(random_bytes(6));
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    return [$pdo, new Vault($root), $root];
}

function mem_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? mem_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

test('remember appends a line and recall finds it by one word', function () {
    [$pdo, $vault, $root] = mem_world();
    try {
        $m = new MemoryStore($pdo, $vault);
        $file = $root . '\\Memory\\2026-10.md';
        assert_same('- 09:41 Henike wants the quote by Friday', $m->remember('Henike wants the quote by Friday', MEM_NOW));
        assert_same("- 09:41 Henike wants the quote by Friday\n", file_get_contents($file));
        // A line Rob typed into the note by hand stays.
        file_put_contents($file, file_get_contents($file) . '- 10:00 typed in Obsidian');
        $m->remember('Sembr invoice run is Tuesday', MEM_NOW + 600);
        assert_same("- 09:41 Henike wants the quote by Friday\n- 10:00 typed in Obsidian\n- 09:51 Sembr invoice run is Tuesday\n",
            file_get_contents($file));
        assert_same([['date' => '2026-10-19', 'time' => '09:41', 'text' => 'Henike wants the quote by Friday']], $m->recall('henike'));
        assert_same(['Sembr invoice run is Tuesday'], array_column($m->recall('tuesday'), 'text'));
        assert_same([], $m->recall('henike tuesday'), 'every word must match');
        assert_same([], $m->recall('" OR * NEAR('), 'FTS5 syntax is only words');
        assert_same(2, $m->count());
    } finally {
        mem_rmtree($root);
    }
});

test('2,001 characters are refused and 2,000 are saved', function () {
    [$pdo, $vault, $root] = mem_world();
    try {
        $m = new MemoryStore($pdo, $vault);
        assert_same(null, $m->remember(str_repeat('é', 2001), MEM_NOW));
        assert_same([0, false], [$m->count(), file_exists($root . '\\Memory\\2026-10.md')], 'nothing saved');
        assert_true($m->remember(str_repeat('é', 2000), MEM_NOW) !== null, '2,000 characters saved');
        assert_same(null, $m->remember('   ', MEM_NOW), 'blank refused');
        assert_same(1, $m->count());
    } finally {
        mem_rmtree($root);
    }
});

test('[[x]] and obsidian:// in the text are neutralised in the file', function () {
    [$pdo, $vault, $root] = mem_world();
    try {
        (new MemoryStore($pdo, $vault))->remember("see [[x]] and obsidian://open?vault=kc\n# heading [y](https://e.example)", MEM_NOW);
        $file = (string) file_get_contents($root . '\\Memory\\2026-10.md');
        foreach (['[[', ']]', 'obsidian:', 'https:', '//', "\n#", '](', '[y'] as $bad) {
            assert_true(!str_contains($file, $bad), "memory file holds $bad");
        }
        assert_same(1, substr_count($file, "\n"), 'one line');
    } finally {
        mem_rmtree($root);
    }
});
