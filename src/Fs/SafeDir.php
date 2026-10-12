<?php

class SafeDir
{
    // True only when $name is a real folder directly inside $root that resolves to itself.
    // Junctions and symlinks resolve elsewhere, so the realpath equality rejects them.
    // A junction to a volume GUID path is left unresolved by realpath, so any reparse
    // point is also caught by stat and lstat disagreeing on the inode.
    public static function isDirectChild(string $root, string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..'
            || strpbrk($name, "/\:\0") !== false) {
            return false;
        }
        // The realpath cache outlives a request under Apache; a folder swapped for a junction must not pass on a stale entry.
        clearstatcache(true);
        $base = realpath($root);
        if ($base === false || !is_dir($base)) {
            return false;
        }
        $path = $root . '\\' . $name;
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return false;
        }
        $st = @stat($path);
        $lst = @lstat($path);
        if ($st === false || $lst === false || $st['ino'] !== $lst['ino'] || $st['dev'] !== $lst['dev']) {
            return false;
        }
        return $real === rtrim($base, '\\') . '\\' . $name;
    }
}
