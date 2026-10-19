<?php
require_once __DIR__ . '/../src/Vault/Vault.php';
require_once __DIR__ . '/../src/Vault/ProjectNotes.php';
require_once __DIR__ . '/../src/Vault/CanvasExport.php';

function canvas_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccanvas_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function canvas_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($dir . '\\' . $f) ? canvas_rmtree($dir . '\\' . $f) : @unlink($dir . '\\' . $f);
        }
    }
    @rmdir($dir);
}

function canvas_overlap(array $a, array $b): bool
{
    return $a['x'] < $b['x'] + $b['width'] && $b['x'] < $a['x'] + $a['width']
        && $a['y'] < $b['y'] + $b['height'] && $b['y'] < $a['y'] + $a['height'];
}

function canvas_inside(array $inner, array $outer): bool
{
    return $inner['x'] >= $outer['x'] && $inner['y'] >= $outer['y']
        && $inner['x'] + $inner['width'] <= $outer['x'] + $outer['width']
        && $inner['y'] + $inner['height'] <= $outer['y'] + $outer['height'];
}

// Nine projects over every loop state, enough that the running group wraps to a second row.
function canvas_written(): array
{
    $t = canvas_temp();
    $rows = [];
    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $s) {
        $rows[] = ['slug' => $s, 'loop_state' => 'running'];
    }
    $rows[] = ['slug' => 'g', 'loop_state' => 'stale'];
    $rows[] = ['slug' => 'h', 'loop_state' => 'none'];
    $rows[] = ['slug' => 'i', 'loop_state' => null];
    $vault = new Vault($t . '\\kc');
    $notes = (new ProjectNotes($vault, []))->write($rows);
    CanvasExport::write($vault, $notes);
    return [$t, file_get_contents($t . '\\kc\\Maps\\projects.canvas')];
}

test('the canvas parses as JSON with a file node per note and a group per loop state', function () {
    [$t, $raw] = canvas_written();
    try {
        $c = json_decode($raw, true);
        assert_true(is_array($c) && is_array($c['nodes'] ?? null) && is_array($c['edges'] ?? null), 'JSON with nodes and edges');
        $files = array_values(array_filter($c['nodes'], fn($n) => $n['type'] === 'file'));
        $groups = array_values(array_filter($c['nodes'], fn($n) => $n['type'] === 'group'));
        assert_same(9, count($files), 'file nodes');
        assert_same(['running', 'stale', 'none', 'unknown'], array_column($groups, 'label'));
        foreach ($files as $n) {
            assert_true(is_file($t . '\\kc\\' . str_replace('/', '\\', $n['file'])), 'node points at a note: ' . $n['file']);
        }
    } finally {
        canvas_rmtree($t);
    }
});

test('every node has id, type, x, y, width, height; ids are unique; every edge joins two nodes', function () {
    [$t, $raw] = canvas_written();
    try {
        $c = json_decode($raw, true);
        foreach ($c['nodes'] as $n) {
            assert_true(is_string($n['id'] ?? null) && is_string($n['type'] ?? null), 'id and type');
            foreach (['x', 'y', 'width', 'height'] as $k) {
                assert_true(is_int($n[$k] ?? null), $n['id'] . ' ' . $k);
            }
        }
        $ids = array_column($c['nodes'], 'id');
        assert_same(count($ids), count(array_unique($ids)), 'unique ids');
        foreach ($c['edges'] as $e) {
            assert_true(in_array($e['fromNode'] ?? null, $ids, true) && in_array($e['toNode'] ?? null, $ids, true), 'edge ends exist');
        }
    } finally {
        canvas_rmtree($t);
    }
});

test('no two nodes overlap, except a file node inside its own group', function () {
    [$t, $raw] = canvas_written();
    try {
        $nodes = json_decode($raw, true)['nodes'];
        $groupOf = [];
        $group = null;
        foreach ($nodes as $n) {
            if ($n['type'] === 'group') {
                $group = $n;
            } else {
                assert_true($group !== null && canvas_inside($n, $group), $n['id'] . ' sits inside its group');
                $groupOf[$n['id']] = $group['id'];
            }
        }
        foreach ($nodes as $i => $a) {
            foreach ($nodes as $j => $b) {
                if ($j <= $i || ($groupOf[$b['id']] ?? null) === $a['id'] || ($groupOf[$a['id']] ?? null) === $b['id']) {
                    continue;
                }
                assert_true(!canvas_overlap($a, $b), $a['id'] . ' overlaps ' . $b['id']);
            }
        }
    } finally {
        canvas_rmtree($t);
    }
});
