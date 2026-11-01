<?php
require_once __DIR__ . '/GraphStore.php';
require_once __DIR__ . '/../Mail/ImapReader.php';

/**
 * The graph's sources, each read-only and each returning edges for GraphStore::replace. Project folders give only
 * names, hostnames and vendor names from a fixed list of docs, plus .env.example's keys' hostnames; .env, anything
 * under .credentials and every other file are never opened. No mail body is read: sent mail is envelopes only.
 */
final class GraphReaders
{
    public const MAX_BYTES = 262144;
    public const DOCS = ['CLAUDE.md', 'README.md', '.claude/context.md', '.claude/project.md'];
    private const HOST = '/\b((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|ca|org|net|io|dev|app|co|uk))\b/i';
    private const EMAIL = '/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i';

    // Lowercase hostnames, www. off.
    public static function hosts(string $text): array
    {
        preg_match_all(self::HOST, $text, $m);
        return array_values(array_unique(array_map(fn($h) => GraphStore::norm('domain', $h), $m[1])));
    }

    private static function read(string $path): string
    {
        return is_file($path) && !is_link($path) ? (string) @file_get_contents($path, false, null, 0, self::MAX_BYTES) : '';
    }

    // The files a project reader may open, and nothing else.
    public static function docs(string $dir): array
    {
        $dir = rtrim($dir, '\\/');
        $out = array_map(fn($f) => $dir . '/' . $f, self::DOCS);
        return array_merge($out, glob($dir . '/docs/*.md') ?: []);
    }

    /**
     * @param array<string,string> $projects name => folder
     * @param array<string,string[]> $vendors name => domains
     */
    public static function projects(array $projects, array $vendors): array
    {
        $vendorOf = [];
        foreach ($vendors as $name => $domains) {
            foreach ((array) $domains as $d) {
                $vendorOf[GraphStore::norm('vendor', $d)] = (string) $name;
            }
        }
        $edges = [];
        foreach ($projects as $name => $dir) {
            $name = (string) $name;
            $text = '';
            foreach (self::docs($dir) as $f) {
                $text .= "\n" . self::read($f);
            }
            // .env.example: keys and the hostnames in values, never a value itself.
            foreach (preg_split('/\R/', self::read(rtrim($dir, '\\/') . '/.env.example')) as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $m)) {
                    $text .= "\n" . $m[1] . ' ' . implode(' ', self::hosts($m[2]));
                }
            }
            $used = [];
            foreach (self::hosts($text) as $h) {
                $v = self::vendor($h, $vendorOf);
                if ($v !== null) {
                    $used[$v[0]] = $v[1];
                } else {
                    $edges[] = ['project', $name, $name, 'has_domain', 'domain', $h, $h];
                }
            }
            foreach ($vendors as $vn => $domains) {
                if (preg_match('/\b' . preg_quote((string) $vn, '/') . '\b/i', $text)) {
                    $used[GraphStore::norm('vendor', (string) ((array) $domains)[0])] ??= (string) $vn;
                }
            }
            foreach ($used as $vd => $vn) {
                $edges[] = ['project', $name, $name, 'uses', 'vendor', $vd, $vn];
            }
            if (preg_match('/^\s*url\s*=\s*(\S+)/m', self::read(rtrim($dir, '\\/') . '/.git/config'), $m)) {
                $repo = preg_replace('~^(?:https?://|git@)([^/:]+)[/:]~', '$1/', preg_replace('/\.git$/', '', $m[1]));
                $edges[] = ['project', $name, $name, 'has_repo', 'repo', (string) $repo, (string) $repo];
            }
        }
        return $edges;
    }

    // [vendor key, vendor name] for a host on a vendor's domain, or null.
    private static function vendor(string $host, array $vendorOf): ?array
    {
        foreach ($vendorOf as $d => $name) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return [$d, $name];
            }
        }
        return null;
    }

    /**
     * Client records: one folder per client (a leading _ is skipped), contact addresses and domains from its
     * markdown. A client owns each project whose name is its slug or starts with slug-.
     */
    public static function clients(string $dir, array $projectNames): array
    {
        $edges = [];
        foreach (glob(rtrim($dir, '\\/') . '/*', GLOB_ONLYDIR) ?: [] as $c) {
            $slug = basename($c);
            if (str_starts_with($slug, '_')) {
                continue;
            }
            $text = '';
            foreach (glob($c . '/*.md') ?: [] as $f) {
                $text .= "\n" . self::read($f);
            }
            // "Ann Acme <ann@...>" or "Ann Acme (ann@...)" names the address.
            preg_match_all('/([A-Z][\w\'-]+(?: [A-Z][\w\'-]+){0,3})\s*[<(]\s*([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $text, $named, PREG_SET_ORDER);
            $names = [];
            foreach ($named as $n) {
                $names[strtolower($n[2])] = $n[1];
            }
            preg_match_all(self::EMAIL, $text, $mails);
            foreach (array_unique(array_map('strtolower', $mails[0])) as $addr) {
                $edges[] = ['person', $addr, $names[$addr] ?? $addr, 'works_for', 'client', $slug, $slug];
            }
            foreach (self::hosts((string) preg_replace(self::EMAIL, ' ', $text)) as $h) {
                $edges[] = ['client', $slug, $slug, 'has_domain', 'domain', $h, $h];
            }
            $edges = array_merge($edges, self::owns($slug, $projectNames));
        }
        return $edges;
    }

    private static function owns(string $slug, array $projectNames): array
    {
        $out = [];
        foreach ($projectNames as $p) {
            if (strcasecmp($p, $slug) === 0 || stripos($p, $slug . '-') === 0) {
                $out[] = ['client', $slug, $slug, 'owns', 'project', $p, $p];
            }
        }
        return $out;
    }

    // kc-care's clients.json: client key and domain.
    public static function care(string $path, array $projectNames): array
    {
        $d = json_decode(self::read($path), true);
        $edges = [];
        foreach (is_array($d) ? $d : [] as $k => $c) {
            $key = is_string($k) ? $k : (string) ($c['key'] ?? '');
            $domain = GraphStore::norm('domain', (string) ($c['domain'] ?? ''));
            if ($key === '') {
                continue;
            }
            if ($domain !== '') {
                $edges[] = ['client', $key, $key, 'has_domain', 'domain', $domain, $domain];
            }
            $edges = array_merge($edges, self::owns($key, $projectNames));
        }
        return $edges;
    }

    // Rob's corrections (data/mail-senders.json): sender => project as confirmed edges; null (not mine) is no edge.
    public static function corrections(array $senders): array
    {
        $edges = [];
        foreach ($senders as $addr => $p) {
            if (is_string($p) && $p !== '') {
                $edges[] = ['person', (string) $addr, (string) $addr, 'emailed_about', 'project', $p, $p, 1, 1];
            }
        }
        return $edges;
    }

    /**
     * Sent mail envelopes as [to => address[], subject]: a recipient of a message whose subject names a project
     * emailed about it, and the subject becomes a topic of that project.
     */
    public static function sent(array $envelopes, array $projectNames): array
    {
        $edges = [];
        foreach ($envelopes as $e) {
            $subject = (string) ($e['subject'] ?? '');
            foreach ($projectNames as $p) {
                $words = array_map(fn($w) => preg_quote($w, '/'), preg_split('/[\s-]+/', strtolower($p), -1, PREG_SPLIT_NO_EMPTY));
                $pattern = '/(?<![\w-])' . implode('[\s-]', $words) . '(?![\w-])/i';
                if (!preg_match($pattern, $subject)) {
                    continue;
                }
                foreach ((array) ($e['to'] ?? []) as $addr) {
                    $edges[] = ['person', (string) $addr, (string) $addr, 'emailed_about', 'project', $p, $p];
                }
                $edges[] = ['topic', $subject, mb_substr($subject, 0, 120), 'topic_of', 'project', $p, $p];
            }
        }
        return $edges;
    }
}
