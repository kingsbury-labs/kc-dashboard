<?php

/**
 * Rob's client pattern (card 133), one small pure function per rule, run in priority order by place():
 * 1 an exact _CONTACTS.md address on From, To or Cc; 2 the client's own non-free-mail domain there, system senders
 * excluded; 3 a thread Rob wrote to a rule 1 or 2 address; 4 the client's site domain or business name in the
 * subject or first lines, tagged "about"; 6 the studio site's inquiry relay matched to a _PIPELINE.md name.
 * Rule 5, the display name, only breaks a tie inside rule 3 or 4 and never places a row alone. A sender on NEVER
 * (payment, hosting, platform, newsletter, Rob's studio) is never "from" a client; rule 4 may still tag it "about".
 *
 * $m: addr, from (display name), to, cc (address lists), subject, text, sent_to (addresses Rob wrote to in this
 * thread). $ctx: contacts (ClientContacts::read), free (free-mail list), terms (client => site domains and names),
 * pipeline (lower-case lead name => label), studio (Rob's own domains). Nothing here is stored.
 */
class MailRules
{
    // Local parts that are a platform speaking for a domain, never a person. Matched whole or before + . _ -.
    public const SYSTEM_LOCAL = ['wordpress', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'billing', 'notifications',
        'notification', 'notify', 'mailer-daemon', 'postmaster', 'bounce', 'bounces', 'invoice', 'invoices', 'alerts', 'news', 'newsletter'];

    // Payment, hosting, platform and newsletter senders: never a client's mail, whatever it names. Subdomains count.
    public const NEVER = ['stripe.com', 'interac.ca', 'cloudflare.com', 'godaddy.com', 'whc.ca', 'github.com', 'google.com',
        'helcim.com', 'digitalocean.com', 'paypal.com', 'squareup.com', 'mailchimp.com', 'mcsv.net', 'substack.com',
        'beehiiv.com', 'convertkit.com', 'pagescms.org'];

    // How much of the body rule 4 reads: the first lines only.
    public const ABOUT_CHARS = 400;
    public const INQUIRY = '/^\s*New project inquiry from\s+(.+?)\s*$/i';

    public static function domain(string $addr): string
    {
        return strtolower((string) substr((string) strrchr(trim($addr), '@'), 1));
    }

    public static function under(string $domain, string $d): bool
    {
        return $d !== '' && ($domain === $d || str_ends_with($domain, '.' . $d));
    }

    public static function system(string $addr): bool
    {
        $local = strtolower((string) strstr(trim($addr), '@', true));
        foreach (self::SYSTEM_LOCAL as $s) {
            if ($local === $s || preg_match('/^' . preg_quote($s, '/') . '[+._-]/', $local)) {
                return true;
            }
        }
        return false;
    }

    // A payment, hosting, platform or newsletter sender, or Rob's own studio.
    public static function never(string $addr, array $studio = [], array $never = self::NEVER): bool
    {
        $domain = self::domain($addr);
        foreach (array_merge($never, $studio) as $d) {
            if (self::under($domain, strtolower($d))) {
                return true;
            }
        }
        return false;
    }

    /** From, To and Cc, lower case, From first. @return list<string> */
    public static function parties(array $m): array
    {
        $all = array_merge([(string) ($m['addr'] ?? '')], (array) ($m['to'] ?? []), (array) ($m['cc'] ?? []));
        return array_values(array_unique(array_filter(array_map(fn($a) => strtolower(trim((string) $a)), $all), fn($a) => $a !== '')));
    }

    // Rule 1: a client's exact address on any of $addrs.
    public static function rule1(array $addrs, array $contacts): ?string
    {
        foreach ($addrs as $a) {
            foreach ($contacts as $c) {
                if ($c['email'] === strtolower($a)) {
                    return $c['name'];
                }
            }
        }
        return null;
    }

    // Rule 2: a client's own (not free-mail) domain on any of $addrs, system local parts excluded.
    public static function rule2(array $addrs, array $contacts, array $free): ?string
    {
        foreach ($addrs as $a) {
            $domain = self::domain($a);
            if ($domain === '' || self::system($a) || Relevance::freeMail($domain, $free)) {
                continue;
            }
            foreach ($contacts as $c) {
                $d = self::domain($c['email']);
                if (!Relevance::freeMail($d, $free) && self::under($domain, $d)) {
                    return $c['name'];
                }
            }
        }
        return null;
    }

    /** Rule 3: the clients Rob wrote to in this thread, by rule 1 or 2. @return list<string> */
    public static function rule3(array $sentTo, array $contacts, array $free): array
    {
        $out = [];
        foreach ($sentTo as $a) {
            $name = self::rule1([$a], $contacts) ?? self::rule2([$a], $contacts, $free);
            if ($name !== null) {
                $out[$name] = $name;
            }
        }
        return array_values($out);
    }

    /** Rule 4: the clients whose site domain or business name the subject or first lines name. @return list<string> */
    public static function rule4(array $m, array $terms): array
    {
        $hay = (string) ($m['subject'] ?? '') . "\n" . mb_substr((string) ($m['text'] ?? ''), 0, self::ABOUT_CHARS);
        $out = [];
        foreach ($terms as $client => $words) {
            foreach ((array) $words as $w) {
                $w = trim((string) $w);
                if (mb_strlen($w) >= 4 && preg_match('/(?<![\w.-])' . preg_quote($w, '/') . '(?![\w-]|\.\w)/iu', $hay)) {
                    $out[] = (string) $client;
                    break;
                }
            }
        }
        return $out;
    }

    // Rule 5: of the tied $candidates, the one whose contact's name is the From display name, only for free mail.
    public static function rule5(array $m, array $candidates, array $contacts, array $free): ?string
    {
        if (count($candidates) < 2 || !Relevance::freeMail(self::domain((string) ($m['addr'] ?? '')), $free)) {
            return null;
        }
        $shown = (string) ($m['from'] ?? '');
        $hit = [];
        foreach ($contacts as $c) {
            $person = trim((string) ($c['person'] ?? ''));
            if (in_array($c['name'], $candidates, true) && mb_strlen($person) >= 4
                && preg_match('/(?<!\w)' . preg_quote($person, '/') . '(?!\w)/iu', $shown)) {
                $hit[$c['name']] = $c['name'];
            }
        }
        return count($hit) === 1 ? reset($hit) : null;
    }

    // Rule 6: the studio site's own system sender relaying "New project inquiry from <Name>" for a pipeline lead.
    public static function rule6(array $m, array $pipeline, array $studio): ?string
    {
        $addr = (string) ($m['addr'] ?? '');
        $own = false;
        foreach ($studio as $d) {
            $own = $own || self::under(self::domain($addr), strtolower($d));
        }
        if (!$own || !self::system($addr) || !preg_match(self::INQUIRY, (string) ($m['subject'] ?? ''), $x)) {
            return null;
        }
        return $pipeline[mb_strtolower(trim($x[1]))] ?? null;
    }

    /** The client a message belongs to, in priority order, or null. @return ?array{project:string, rule:int, kind:string} */
    public static function place(array $m, array $ctx): ?array
    {
        $contacts = (array) ($ctx['contacts'] ?? []);
        $free = (array) ($ctx['free'] ?? Relevance::FREE_MAIL);
        $studio = (array) ($ctx['studio'] ?? []);
        $hit = fn(string $p, int $rule, string $kind): array => ['project' => $p, 'rule' => $rule, 'kind' => $kind];
        if (!self::never((string) ($m['addr'] ?? ''), $studio, (array) ($ctx['never'] ?? self::NEVER))) {
            $parties = self::parties($m);
            if (($p = self::rule1($parties, $contacts)) !== null) {
                return $hit($p, 1, 'from');
            }
            if (($p = self::rule2($parties, $contacts, $free)) !== null) {
                return $hit($p, 2, 'from');
            }
            $c = self::rule3((array) ($m['sent_to'] ?? []), $contacts, $free);
            if (count($c) === 1) {
                return $hit($c[0], 3, 'from');
            }
            if (($p = self::rule5($m, $c, $contacts, $free)) !== null) {
                return $hit($p, 5, 'from');
            }
        }
        $c = self::rule4($m, (array) ($ctx['terms'] ?? []));
        if (count($c) === 1) {
            return $hit($c[0], 4, 'about');
        }
        if (($p = self::rule5($m, $c, $contacts, $free)) !== null) {
            return $hit($p, 5, 'about');
        }
        if (($p = self::rule6($m, (array) ($ctx['pipeline'] ?? []), $studio)) !== null) {
            return $hit($p, 6, 'from');
        }
        return null;
    }

    // Lower case, Re:/Fwd: prefixes and runs of space gone: the key rule 3 joins a thread on.
    public static function thread(string $subject): string
    {
        $s = mb_strtolower(trim($subject));
        while (preg_match('/^(?:re|fwd?|fw|aw)\s*(?:\[\d+\])?\s*:\s*/u', $s, $x)) {
            $s = (string) substr($s, strlen($x[0]));
        }
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * The running context: contacts, Rob's studio domains from config, and client terms and pipeline names from the
     * client files config/clients.php points at (read-only; held in memory, never cached).
     */
    public static function context(array $contacts, ?string $dir = null, array $links = [], array $studio = []): array
    {
        if ($dir === null) {
            $clients = require __DIR__ . '/../../config/clients.php';
            $dir = (string) $clients['dir'];
            $links = (array) ($clients['links'] ?? []);
            $studio = (array) (((require __DIR__ . '/../../config/collectors.php')['mail'])['studio'] ?? []);
        }
        $dir = rtrim($dir, '\\/');
        return ['contacts' => $contacts, 'free' => Relevance::FREE_MAIL, 'studio' => $studio,
            'terms' => self::terms($dir, $links, $contacts, $studio), 'pipeline' => self::pipeline((string) @file_get_contents($dir . '/_PIPELINE.md', false, null, 0, ClientContacts::BYTES))];
    }

    /** client => site domains and business name, from each client's PROJECT.md and its site link. @return array<string,list<string>> */
    public static function terms(string $dir, array $links, array $contacts, array $studio = []): array
    {
        $out = [];
        foreach (glob($dir . '/*/PROJECT.md') ?: [] as $file) {
            $slug = basename(dirname($file));
            $md = (string) @file_get_contents($file, false, null, 0, 65536);
            $title = preg_match('/^#\s*(.+)$/m', $md, $x) ? $x[1] : $slug;
            $title = trim((string) preg_replace(['/^project:\s*/i', '/\s*(?:[,:\x{2014}-]\s*)?(?:internal\s+)?project\s*$/iu'], '', $title));
            $words = [];
            foreach (preg_split('/\R/', $md) as $line) {
                if (preg_match('/^\s*[-*]\s*\*\*(?:domain|live url|website|site):?\*\*/i', $line)) {
                    preg_match_all('/\b(?:[a-z0-9-]+\.)+[a-z]{2,}\b/i', $line, $d);
                    $words = array_merge($words, array_map('strtolower', $d[0]));
                }
            }
            $host = parse_url((string) ($links[$slug]['site'] ?? ''), PHP_URL_HOST);
            $words = array_merge($words, is_string($host) ? [strtolower((string) preg_replace('/^www\./', '', $host))] : []);
            $own = false;
            foreach ($words as $w) {
                foreach ($studio as $s) {
                    $own = $own || self::under($w, strtolower($s));
                }
            }
            if ($own) {
                continue;
            }
            $name = self::client($title, $contacts);
            $out[$name] = array_values(array_unique(array_merge($out[$name] ?? [], $words, [$title, $name])));
        }
        return $out;
    }

    // The contacts' name for a project title when one starts with the other, else the title.
    private static function client(string $title, array $contacts): string
    {
        $t = mb_strtolower($title);
        foreach ($contacts as $c) {
            $n = mb_strtolower($c['name']);
            if ($t !== '' && (str_starts_with($n, $t) || str_starts_with($t, $n))) {
                return $c['name'];
            }
        }
        return $title;
    }

    /** lower-case Name => Business if set, else Name, from _PIPELINE.md's first table with a Name column. @return array<string,string> */
    public static function pipeline(string $md): array
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
                $head = in_array('name', $lower, true) ? $lower : null;
                continue;
            }
            if (preg_match('/^[\s:|-]+$/', $line)) {
                continue;
            }
            $r = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
            $name = trim((string) ($r['name'] ?? ''));
            $business = trim((string) ($r['business'] ?? ''));
            if ($name !== '') {
                $out[mb_strtolower($name)] = mb_substr($business !== '' ? $business : $name, 0, Relevance::NAME_MAX);
            }
        }
        return $out;
    }
}
