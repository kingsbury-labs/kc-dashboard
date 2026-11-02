<?php

/**
 * Rob's clients as {name, email}, read at run time from clients/_CONTACTS.md (gitignored, read-only): the first
 * markdown table with an Email column, one entry per address, name = Business if set, else Name. Every row counts,
 * whatever _PIPELINE.md says of it. A missing or unreadable file returns no contacts and logs one line naming the
 * file, never a value; the list stays in memory and is never cached.
 */
class ClientContacts
{
    public const FILE = '_CONTACTS.md';
    public const BYTES = 1048576;

    /** $file: the contacts file; null means _CONTACTS.md in config/clients.php's dir. @return list<array{name:string, email:string}> */
    public static function read(Log $log, ?string $file = null): array
    {
        $file ??= rtrim((string) (require __DIR__ . '/../../config/clients.php')['dir'], '\\/') . '/' . self::FILE;
        $md = is_file($file) ? @file_get_contents($file, false, null, 0, self::BYTES) : false;
        if (!is_string($md)) {
            $log->error(self::FILE . ' could not be read; mail runs without client contacts');
            return [];
        }
        return self::parse($md);
    }

    /** @return list<array{name:string, email:string}> */
    public static function parse(string $md): array
    {
        $head = null;
        $out = [];
        foreach (preg_split('/\R/', $md) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                if ($out !== []) {
                    break;
                }
                $head = null;
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if ($head === null) {
                $lower = array_map('strtolower', $cells);
                $head = in_array('email', $lower, true) ? $lower : null;
                continue;
            }
            if (preg_match('/^[\s:|-]+$/', $line)) {
                continue;
            }
            $r = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
            $name = trim((string) ($r['business'] ?? ''));
            $name = mb_substr($name !== '' ? $name : trim((string) ($r['name'] ?? '')), 0, Relevance::NAME_MAX);
            foreach (preg_split('/[\s,;]+/', strtolower((string) ($r['email'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $email) {
                if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[] = ['name' => $name, 'email' => $email];
                }
            }
        }
        return $out;
    }
}
