<?php

/**
 * Which of Rob's projects, clients or vendors an email concerns, or null for the Unsorted pile. Rob's corrections win
 * (null hides), then a Daybook client's exact address, then a sender domain on the entity list that is not free mail
 * (no model call), then the local model, which sees the entity names, sender,
 * subject and the first 1,000 characters and may only answer one of those names. Corrections live in
 * data/mail-senders.json as sender address => project name, or null for "not mine". No mail text is stored.
 */
class Relevance
{
    public const CHARS = 1000;
    public const SENDERS_MAX = 2000;
    public const NAME_MAX = 80;

    // Free-mail domains: never a client's domain, matched only by a full client address. Add new ones here.
    public const FREE_MAIL = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com',
        'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        'rogers.com', 'bell.net', 'sympatico.ca', 'shaw.ca', 'telus.net'];

    /** @var array<string,string[]> entity name => sender domains */
    private array $entities;
    private string $senders;
    private ?Closure $ask;
    /** @var list<array{name:string, email:string}> */
    private array $contacts;
    private array $free;

    // $ask: fn(string prompt): ?string, the model's raw answer; null means no model. $contacts: ClientContacts::read.
    // $free: the free-mail list; tests pass example domains, since real free-mail addresses may not sit in fixtures.
    public function __construct(array $entities, string $senders, ?Closure $ask, array $contacts = [], array $free = self::FREE_MAIL)
    {
        foreach ($contacts as $c) {
            $entities[$c['name']] ??= [];
        }
        $this->entities = $entities;
        $this->senders = $senders;
        $this->ask = $ask;
        $this->contacts = $contacts;
        $this->free = $free;
    }

    // The domain, or any parent of it, is on the free-mail list.
    public static function freeMail(string $domain, array $free = self::FREE_MAIL): bool
    {
        foreach ($free as $f) {
            if ($domain === $f || str_ends_with($domain, '.' . $f)) {
                return true;
            }
        }
        return false;
    }

    // The client whose address this is exactly, else whose own (not free-mail) domain it is on, or null.
    public static function byContact(string $addr, array $contacts, array $free = self::FREE_MAIL): ?string
    {
        $addr = strtolower(trim($addr));
        foreach ($contacts as $c) {
            if ($addr !== '' && $c['email'] === $addr) {
                return $c['name'];
            }
        }
        $domain = (string) substr((string) strrchr($addr, '@'), 1);
        if ($domain === '' || self::freeMail($domain, $free)) {
            return null;
        }
        foreach ($contacts as $c) {
            $d = (string) substr((string) strrchr($c['email'], '@'), 1);
            if (!self::freeMail($d, $free) &&($domain === $d || str_ends_with($domain, '.' . $d))) {
                return $c['name'];
            }
        }
        return null;
    }

    // The entity list from the running config: names and domains only, read-only.
    public static function fromConfig(string $senders, ?Closure $ask): self
    {
        $mail = (require __DIR__ . '/../../config/collectors.php')['mail'];
        $projects = require __DIR__ . '/../../config/projects.php';
        $clients = require __DIR__ . '/../../config/clients.php';
        $e = [];
        foreach ((array) ($mail['vendors'] ?? []) as $name => $domains) {
            $e[$name] = array_map('strtolower', (array) $domains);
        }
        foreach (array_merge(glob(rtrim($projects['root'], '\\/') . '/*', GLOB_ONLYDIR) ?: [], array_column($projects['extra'] ?? [], 'path')) as $dir) {
            $e[basename($dir)] ??= [];
        }
        foreach (glob(rtrim((string) $clients['dir'], '\\/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!str_starts_with(basename($dir), '_')) {
                $e[basename($dir)] ??= [];
            }
        }
        foreach ((array) ($clients['links'] ?? []) as $slug => $links) {
            $host = parse_url((string) ($links['site'] ?? ''), PHP_URL_HOST);
            $e[$slug] = array_merge($e[$slug] ?? [], is_string($host) ? [strtolower(preg_replace('/^www\./', '', $host))] : []);
        }
        $care = json_decode((string) @file_get_contents((string) ($mail['care_clients'] ?? ''), false, null, 0, 1048576), true);
        foreach (is_array($care) ? $care : [] as $k => $c) {
            $name = is_string($k) ? $k : (string) ($c['key'] ?? '');
            $domain = strtolower(preg_replace('/^www\./', '', (string) ($c['domain'] ?? '')));
            if ($name !== '') {
                $e[$name] = array_merge($e[$name] ?? [], $domain !== '' ? [$domain] : []);
            }
        }
        return new self($e, $senders, $ask);
    }

    public function names(): array
    {
        return array_map('strval', array_keys($this->entities));
    }

    // $m: from, addr, subject, text. The project name, or null to hide.
    public function tag(array $m): ?string
    {
        $addr = strtolower((string) ($m['addr'] ?? ''));
        $learned = self::senders($this->senders);
        if (array_key_exists($addr, $learned)) {
            return $learned[$addr];
        }
        if (($client = self::byContact($addr, $this->contacts, $this->free)) !== null) {
            return $client;
        }
        $domain = (string) substr((string) strrchr($addr, '@'), 1);
        foreach (self::freeMail($domain, $this->free) ? [] : $this->entities as $name => $domains) {
            foreach ($domains as $d) {
                if ($d !== '' && !self::freeMail($d, $this->free) && ($domain === $d || str_ends_with($domain, '.' . $d))) {
                    return (string) $name;
                }
            }
        }
        if ($this->ask === null) {
            return null;
        }
        try {
            $answer = ($this->ask)(self::prompt($m, $this->names()));
        } catch (Throwable $e) {
            return null;
        }
        return is_string($answer) ? self::read($answer, $this->names()) : null;
    }

    public static function prompt(array $m, array $names): string
    {
        return "Rob runs these projects, clients and vendors: " . implode(', ', $names) . ".\n"
            . "Does this email concern one of them? Promotions, newsletters and personal shopping do not.\n"
            . "Answer only JSON: {\"project\": \"<one name from the list>\"} or {\"project\": null}.\n\n"
            . 'From: ' . ($m['from'] ?? '') . ' <' . ($m['addr'] ?? '') . ">\nSubject: " . ($m['subject'] ?? '') . "\n\n"
            . mb_substr((string) ($m['text'] ?? ''), 0, self::CHARS);
    }

    // A name from the list, matched without case, or null.
    public static function read(string $answer, array $names): ?string
    {
        $d = json_decode(trim($answer), true);
        if (!is_array($d) && preg_match('/\{.*\}/s', $answer, $x)) {
            $d = json_decode($x[0], true);
        }
        $p = is_array($d) && is_string($d['project'] ?? null) ? strtolower(trim($d['project'])) : null;
        foreach ($p === null ? [] : $names as $n) {
            if (strtolower($n) === $p) {
                return $n;
            }
        }
        return null;
    }

    /** @return array<string,?string> sender address => project, or null for not mine */
    public static function senders(string $path): array
    {
        $d = is_file($path) ? json_decode((string) @file_get_contents($path, false, null, 0, 262144), true) : null;
        $out = [];
        foreach (is_array($d) ? $d : [] as $addr => $p) {
            if (is_string($addr) && ($p === null || is_string($p))) {
                $out[strtolower($addr)] = $p;
            }
        }
        return $out;
    }

    // Rows with Rob's corrections applied, untagged rows dropped.
    public static function apply(array $rows, ?string $path): array
    {
        $learned = $path === null ? [] : self::senders($path);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $addr = strtolower((string) ($r['addr'] ?? ''));
            if (array_key_exists($addr, $learned)) {
                $r['project'] = $learned[$addr];
            }
            if (is_string($r['project'] ?? null) && $r['project'] !== '') {
                $out[] = $r;
            }
        }
        return $out;
    }

    // Rows no project claims and Rob has not corrected, kept for the Unsorted pile; old rows with project null too.
    public static function unsorted(array $rows, ?string $path): array
    {
        $learned = $path === null ? [] : self::senders($path);
        return array_values(array_filter($rows, fn($r) => is_array($r) && !(is_string($r['project'] ?? null) && $r['project'] !== '')
            && !array_key_exists(strtolower((string) ($r['addr'] ?? '')), $learned)));
    }

    // Stores one correction. Throws InvalidArgumentException on a bad sender or name.
    public static function correct(string $path, string $sender, ?string $project): array
    {
        $sender = strtolower(trim($sender));
        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('sender must be an email address');
        }
        if ($project !== null) {
            $project = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $project));
            if ($project === '' || mb_strlen($project) > self::NAME_MAX) {
                throw new InvalidArgumentException('project must be 1 to ' . self::NAME_MAX . ' characters');
            }
        }
        $lock = fopen($path . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $all = self::senders($path);
            unset($all[$sender]);
            $all[$sender] = $project;
            $all = array_slice($all, -self::SENDERS_MAX, null, true);
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (file_put_contents($tmp, json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $path)) {
                throw new RuntimeException('mail sender list could not be written');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['sender' => $sender, 'project' => $project];
    }
}
