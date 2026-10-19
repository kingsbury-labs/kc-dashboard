<?php
require_once __DIR__ . '/../src/Vault/Vault.php';
require_once __DIR__ . '/../src/Vault/ProjectNotes.php';

function vault_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcvault_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

// mklink through cmd, as ProjectScannerTest does.
function vault_mklink(string $link, string $target): void
{
    $proc = proc_open('mklink /J "' . $link . '" "' . $target . '"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($proc), 'could not start cmd');
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

// Removes a junction without following it, then plain files and folders.
function vault_rmtree(string $dir): void
{
    $st = @stat($dir);
    $lst = @lstat($dir);
    if ($st !== false && $lst !== false && $st['ino'] !== $lst['ino']) {
        @rmdir($dir);
        return;
    }
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '\\' . $f;
        is_dir($p) ? vault_rmtree($p) : @unlink($p);
    }
    @rmdir($dir);
}

function vault_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException | RuntimeException $e) {
        return true;
    }
    return false;
}

function vault_files(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

test('a write to Projects/a.md lands with no temp file left', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $vault = new Vault($root);
        assert_true(is_dir($root), 'root created when missing');
        assert_same(true, $vault->write('Projects/a.md', "hello\n"));
        assert_same("hello\n", file_get_contents($root . '\\Projects\\a.md'));
        assert_same(['a.md'], vault_files($root . '\\Projects'), 'only the note in the folder');
        assert_same(true, $vault->write('Projects/a.md', "second\n"));
        assert_same("second\n", file_get_contents($root . '\\Projects\\a.md'), 'replaced');
        assert_same(['a.md'], vault_files($root . '\\Projects'), 'no temp file after a replace');
    } finally {
        vault_rmtree($t);
    }
});

test('../x.md, C:\\x.md, a folder outside the fixed set and odd names are refused', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $vault = new Vault($root);
        foreach (['../x.md', 'Projects/../x.md', 'Projects/../../x.md', '..\\x.md', 'C:\\x.md', 'C:/x.md',
            '/x.md', 'Other/x.md', 'Projects/A.md', 'Projects/a.php', 'Projects/sub/a.md', 'Projects\\a.md',
            'Projects/.a.md', 'Projects/a.md ', "Projects/a.md\0"] as $bad) {
            assert_true(vault_throws(fn() => $vault->write($bad, 'x')), 'refused: ' . $bad);
        }
        assert_same(['kc'], vault_files($t), 'nothing written beside the root');
        assert_same([], vault_files($root), 'no folder made for a refused path');
    } finally {
        vault_rmtree($t);
    }
});

test('a junction root is refused, and so is a junction folder inside the root', function () {
    $t = vault_temp();
    try {
        mkdir($t . '\\outside');
        vault_mklink($t . '\\link', $t . '\\outside');
        assert_true(vault_throws(fn() => new Vault($t . '\\link')), 'junction root refused');
        assert_true(vault_throws(fn() => new Vault('relative\\kc')), 'relative root refused');
        mkdir($t . '\\kc');
        vault_mklink($t . '\\kc\\Projects', $t . '\\outside');
        $vault = new Vault($t . '\\kc');
        assert_true(vault_throws(fn() => $vault->write('Projects/a.md', 'x')), 'junction folder refused');
        assert_same([], vault_files($t . '\\outside'), 'nothing reached the junction target');
    } finally {
        vault_rmtree($t);
    }
});

test('a commit subject holding [[evil]] and obsidian://open is written neutralised', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $notes = new ProjectNotes(new Vault($root), ['site' => 'acme-co']);
        $notes->write([['slug' => 'Site', 'branch' => "main\n---\nevil: [[x]]", 'loop_state' => 'none',
            'last_date' => '2026-10-19T10:00:00-04:00', 'last_subject' => 'fix [[evil]] see obsidian://open?vault=x',
            'loop_note' => '![[secret]] file:///C:/x', 'loop_card' => null, 'error' => null]]);
        $md = file_get_contents($root . '\\Projects\\site.md');
        assert_true(strpos($md, '[[evil') === false && strpos($md, '[[x') === false && strpos($md, '[[secret') === false,
            'no live wikilink from repo text');
        assert_true(stripos($md, 'obsidian:') === false && stripos($md, 'file:') === false, 'no live scheme');
        assert_true(strpos($md, 'evil') !== false, 'the words are kept');
        assert_true(strpos($md, 'Client: [[acme-co]]') !== false, 'the code-made client link is live');
        assert_same(2, substr_count($md, "---\n"), 'front matter has its two fences only');
        assert_true(strpos($md, "updated: \"2026-10-19\"\n") !== false, 'updated is the commit day');
    } finally {
        vault_rmtree($t);
    }
});

test('neutralise leaves no link, image, HTML, autolink, scheme, UNC path, tag or comment', function () {
    $in = "[a](https://e.com) ![i](x.png) [[w]] ![[e]] <img src=x> <https://a.b> www.e.com //cdn.e "
        . "\\\\srv\\share mailto:a@b.c javascript:alert(1) data:text/html,x obsidian://open file:x "
        . "#tag %%hide%% `code` line\nbreak \u{202E}rtl "
        // MathJax: a javascript: link, a fixed overlay, and a beacon whose URL CSS escapes rebuild after neutralise.
        . '$\href{javascript: alert(2)}{diff}$ $$\style{position: fixed; top: 0}{x}$$ '
        . '\style{background: url(https\3a\2f\2f evil.example/p.png)}{y} '
        // Dataview inline fields, an underscore before www., a dataviewjs fence, and invisible characters.
        . "(loop :: x) (loop_:: y) _www.evil.com ~~~dataviewjs zero\u{200B}width tag\u{E0041}char para\u{2029}sep";
    $out = Vault::neutralise($in);
    foreach (['[', ']', '<', '>', '//', '\\', '#', '%%', '`', "\n", '$', '{', '}', '~', '::'] as $bad) {
        assert_true(strpos($out, $bad) === false, 'survived: ' . $bad . ' in ' . $out);
    }
    assert_same(0, preg_match('/www\./i', $out), 'no www.: ' . $out);
    assert_same(0, preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{E0000}-\x{E007F}]/u', $out), 'no invisible characters');
    assert_same(0, preg_match('/[A-Za-z][A-Za-z0-9+.\-]*:/', $out), 'no scheme, with a space after the colon or not: ' . $out);
    assert_true(strpos($out, 'alert') !== false && strpos($out, 'share') !== false && strpos($out, 'evil') !== false, 'text kept');
    assert_same('Card 12: fixed the 10:30 build', Vault::neutralise('Card 12: fixed the 10:30 build'), 'plain prose untouched');
});

test('a neutralised value placed at the start of a line forms no block syntax', function () {
    $starts = ['# h', '## h', '> q', '- l', '+ l', '* l', '| a | b |', '$$x$$', '~~~dataviewjs', '```js', '1. x', '2) x',
        '---', '***', '___', '===', '    code', "\tcode", '<div>', '%% hide', '[^1]: note', '![[x]]', 'key:: v'];
    foreach ($starts as $s) {
        $out = Vault::neutralise($s);
        $md = "text\n" . $out . "\nmore";
        assert_same(0, preg_match('/^(?:[#>\-+*=_|~$`<%\[\s]|\d+[.)])/mu', substr($md, 5)), 'block syntax from ' . var_export($s, true) . ': ' . $out);
        assert_same(0, preg_match('/::/', $out), 'no inline field from ' . $s);
    }
});

test('Windows reserved names are refused; a bad project row leaves the others written', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $vault = new Vault($root);
        foreach (['con', 'nul', 'aux', 'prn', 'com1', 'com9', 'lpt1', 'lpt9'] as $n) {
            assert_true(vault_throws(fn() => $vault->write('Projects/' . $n . '.md', 'x')), 'refused: ' . $n);
            assert_true(vault_throws(fn() => $vault->write('Maps/' . $n . '.canvas', 'x')), 'refused: ' . $n . '.canvas');
        }
        assert_same(true, $vault->write('Projects/console.md', 'x'), 'a longer name is fine');
        $notes = new ProjectNotes($vault, []);
        $written = $notes->write([['slug' => 'alpha'], ['slug' => 'NUL'], ['slug' => 'beta']]);
        assert_same(['alpha', 'beta'], array_keys($written), 'the rows after the bad one are written');
        assert_same(['nul'], array_keys($notes->errors()), 'the bad row keeps its error');
    } finally {
        vault_rmtree($t);
    }
});

// PHP's symlink() needs SeCreateSymbolicLinkPrivilege on Windows, which this account does not have, so a file
// symlink at the target cannot be made here. A hard link needs no privilege and is the same threat: a write that
// went through the old file would land in a file outside the vault.
test('a hard link at the target is replaced, not written through', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $vault = new Vault($root);
        $vault->write('Projects/a.md', 'first');
        mkdir($t . '\\outside');
        unlink($root . '\\Projects\\a.md');
        file_put_contents($t . '\\outside\\keep.md', 'outside bytes');
        assert_true(link($t . '\\outside\\keep.md', $root . '\\Projects\\a.md'), 'hard link created');
        assert_same(true, $vault->write('Projects/a.md', 'vault bytes'));
        assert_same('outside bytes', file_get_contents($t . '\\outside\\keep.md'), 'the outside file is untouched');
        assert_same('vault bytes', file_get_contents($root . '\\Projects\\a.md'));
    } finally {
        vault_rmtree($t);
    }
});

test('unchanged content leaves the mtime unchanged; a change rewrites it', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $vault = new Vault($root);
        $notes = new ProjectNotes($vault, []);
        $row = ['slug' => 'alpha', 'branch' => 'main', 'loop_state' => 'running', 'last_date' => '2026-10-19T10:00:00Z',
            'last_subject' => 'one'];
        $notes->write([$row]);
        $path = $root . '\\Projects\\alpha.md';
        touch($path, time() - 1000);
        clearstatcache(true);
        $before = filemtime($path);
        $notes->write([$row]);
        clearstatcache(true);
        assert_same($before, filemtime($path), 'mtime after an unchanged run');
        assert_same(false, $vault->write('Projects/alpha.md', file_get_contents($path)), 'unchanged write reports no write');
        $notes->write([['last_subject' => 'two'] + $row]);
        clearstatcache(true);
        assert_true(filemtime($path) > $before, 'changed content rewritten');
        assert_true(strpos(file_get_contents($path), 'Last commit: two') !== false, 'new subject');
    } finally {
        vault_rmtree($t);
    }
});

test('two folders that fold to one slug get two notes', function () {
    $t = vault_temp();
    try {
        $root = $t . '\\kc';
        $written = (new ProjectNotes(new Vault($root), []))->write([['slug' => 'Site'], ['slug' => 'site'], ['slug' => '___']]);
        assert_same(['site', 'site-2'], array_keys($written));
        assert_same(['site-2.md', 'site.md'], vault_files($root . '\\Projects'));
    } finally {
        vault_rmtree($t);
    }
});
