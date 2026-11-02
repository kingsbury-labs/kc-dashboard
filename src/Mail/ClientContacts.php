<?php

/**
 * Rob's active clients from Daybook, as {name, email}: name is the company, else the contact. Read-only through a
 * MySQL user with column-level read on clients(company_name, contact_name, email, is_active), one prepared query. The
 * password is read from its file at run time and goes nowhere else. Any failure returns no contacts and logs one
 * line with no values; the list stays in memory and is never cached.
 */
class ClientContacts
{
    public const TIMEOUT = 2;

    /** $rows: fn(): iterable of client rows. @return list<array{name:string, email:string}> */
    public static function read(Closure $rows, Log $log): array
    {
        try {
            $out = [];
            foreach ($rows() as $r) {
                if (!is_array($r) || (array_key_exists('is_active', $r) && !$r['is_active'])) {
                    continue;
                }
                $name = trim((string) ($r['company_name'] ?? ''));
                $name = mb_substr($name !== '' ? $name : trim((string) ($r['contact_name'] ?? '')), 0, Relevance::NAME_MAX);
                $email = strtolower(trim((string) ($r['email'] ?? '')));
                if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[] = ['name' => $name, 'email' => $email];
                }
            }
            return $out;
        } catch (Throwable $e) {
            $log->error('Daybook client contacts could not be read; mail runs without them');
            return [];
        }
    }

    // The live source: throws when the password file is missing or the query fails.
    public static function daybook(string $passwordFile, string $user, string $db): Closure
    {
        return function () use ($passwordFile, $user, $db): array {
            if (!is_file($passwordFile)) {
                throw new RuntimeException('no credential file');
            }
            $pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $db . ';charset=utf8mb4', $user, trim((string) file_get_contents($passwordFile)),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => self::TIMEOUT]);
            $st = $pdo->prepare('SELECT company_name, contact_name, email FROM clients WHERE is_active = ?');
            $st->execute([1]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };
    }
}
