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

    public static function read(string $path): string
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

    /*
     * Lessons: traps, fixes, asks and dream proposals (card 134). Each becomes one node (type trap, fix, ask, dream)
     * with a label of at most 120 characters and these links, all by fixed rules and none by a model or a fuzzy match:
     * concerns -> project: the text names the project's folder (a leading _ ignored) or its project.md name as whole
     *   words, or a path under its folder; a lesson found inside a project's own folder always concerns that project.
     * names -> file: the text names a path that is a file in a project it concerns, key "project:path". With no
     *   project named, a path with a slash that is a file in exactly one project links that file and that project.
     * caused_by -> cause: a trap's section heading.
     * Only the path is stored, never a file's content; .credentials, .env, token, secret and settings paths are not.
     */
    private const DENY = '~(^|/)(\.credentials|\.env[^/]*|[^/]*(token|secret|credential|settings)[^/]*)(/|$)~i';

    // name => [dir, pattern] for each project: its folder name and its project.md name, as whole words.
    public static function scope(array $projects): array
    {
        $out = [];
        foreach ($projects as $name => $dir) {
            $name = (string) $name;
            $words = [];
            if (strlen(trim($name, '_')) >= 3) {
                $words[] = '_?' . preg_quote(trim($name, '_'), '/');
            }
            $md = self::read(rtrim((string) $dir, '\\/') . '/.claude/project.md');
            if (preg_match('/\A---\R(.*?)\R---/s', $md, $f) && preg_match('/^name:\s*["\']?(.+?)["\']?\s*$/m', $f[1], $n) && strlen(trim($n[1])) >= 3) {
                $words[] = str_replace(' ', '\s+', preg_quote(trim($n[1]), '/'));
            }
            $out[$name] = ['dir' => rtrim((string) $dir, '\\/'),
                'pattern' => $words ? '/(?<![\w-])(?:' . implode('|', $words) . ')(?![\w-])/i' : '/(?!)/'];
        }
        return $out;
    }

    // Candidate paths in a text: backticks, quotes and brackets split it; a trailing :line, dot or colon is dropped.
    private static function paths(string $text): array
    {
        preg_match_all('~[^\s`\'"()<>\[\]{},;|*=]+~', $text, $m);
        $out = [];
        foreach (array_unique($m[0]) as $t) {
            $t = str_replace('\\', '/', $t);
            $t = rtrim((string) preg_replace('/:\d+(?:-\d+)?$/', '', rtrim($t, '.:')), '.:');
            if (str_contains($t, '://') || str_starts_with($t, '/') || str_starts_with($t, '~') || !preg_match('~[\w-]\.[A-Za-z0-9]{1,8}$~', $t)) {
                continue;
            }
            $out[] = preg_replace('~^(\./)+~', '', $t);
            if (count($out) >= 80) {
                break;
            }
        }
        return $out;
    }

    private static function plain(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !preg_match('~(^|/)\.\.(/|$)~', $path) && !preg_match(self::DENY, $path);
    }

    /** @return array{projects:string[],files:array<int,array{0:string,1:string}>} */
    public static function linked(string $text, array $scope, ?string $owner): array
    {
        $named = [];
        if ($owner !== null && isset($scope[$owner])) {
            $named[$owner] = true;
        }
        foreach ($scope as $name => $s) {
            if (preg_match($s['pattern'], $text)) {
                $named[$name] = true;
            }
        }
        $files = [];
        $relative = [];
        foreach (self::paths($text) as $p) {
            if (preg_match('~^[A-Za-z]:/~', $p)) {
                foreach ($scope as $name => $s) {
                    $base = strtolower(str_replace('\\', '/', $s['dir'])) . '/';
                    if (str_starts_with(strtolower($p), $base)) {
                        $named[$name] = true;
                        $rel = substr($p, strlen($base));
                        if (self::plain($rel) && is_file($s['dir'] . '/' . $rel)) {
                            $files[$name . ':' . $rel] = [$name, $rel];
                        }
                    }
                }
            } elseif (self::plain($p)) {
                $relative[] = $p;
            }
        }
        foreach ($relative as $p) {
            foreach (array_keys($named) as $name) {
                if (is_file($scope[$name]['dir'] . '/' . $p)) {
                    $files[$name . ':' . $p] = [$name, $p];
                }
            }
        }
        if (!$named) {
            foreach ($relative as $p) {
                $hits = [];
                if (str_contains($p, '/')) {
                    foreach ($scope as $name => $s) {
                        if (is_file($s['dir'] . '/' . $p)) {
                            $hits[] = $name;
                        }
                    }
                }
                if (count($hits) === 1) {
                    $named[$hits[0]] = true;
                    $files[$hits[0] . ':' . $p] = [$hits[0], $p];
                }
            }
        }
        ksort($files);
        $names = array_map('strval', array_keys($named));
        sort($names);
        return ['projects' => $names, 'files' => array_values($files)];
    }

    // One lesson's edges: to each project and file its text names, and to its cause.
    private static function lesson(string $type, string $key, string $label, string $text, array $scope, ?string $owner, ?string $cause = null): array
    {
        $label = mb_substr($label, 0, 120);
        $found = self::linked($text, $scope, $owner);
        $edges = [];
        foreach ($found['projects'] as $p) {
            $edges[] = [$type, $key, $label, 'concerns', 'project', $p, $p];
        }
        foreach ($found['files'] as [$p, $rel]) {
            $edges[] = [$type, $key, $label, 'names', 'file', $p . ':' . $rel, $p . '/' . $rel];
        }
        if ($cause !== null && $cause !== '') {
            $edges[] = [$type, $key, $label, 'caused_by', 'cause', $cause, $cause];
        }
        return $edges;
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('**', '', $s)));
    }

    private static function slug(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    // traps.md: "## Section" is the cause, each "- " item (with its indented lines) is one trap.
    public static function traps(string $text, array $projects): array
    {
        $scope = self::scope($projects);
        $edges = [];
        $cause = '';
        $item = null;
        $flush = function () use (&$item, &$cause, &$edges, $scope): void {
            if ($item !== null) {
                $body = self::squash(implode(' ', $item));
                $title = preg_match('/^\*\*(.+?)\*\*/', $item[0], $t) ? $t[1] : $body;
                $key = self::slug($cause) . '/' . substr(self::slug($title), 0, 40) . '/' . substr(sha1($body), 0, 8);
                $edges = array_merge($edges, self::lesson('trap', $key, $body, $body, $scope, null, $cause));
            }
            $item = null;
        };
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*$/', $line, $h)) {
                $flush();
                $cause = $h[1] === '##' ? self::squash($h[2]) : $cause;
            } elseif (str_starts_with($line, '- ')) {
                $flush();
                $item = [substr($line, 2)];
            } elseif (trim($line) === '') {
                continue;
            } elseif ($item !== null && preg_match('/^\s/', $line)) {
                $item[] = trim($line);
            } else {
                $flush();
            }
        }
        $flush();
        return $edges;
    }

    // asks.md: one "- date, project: the ask" per line. The project word is the folder name, or the client slug
    // that begins exactly one project name (the clients reader's rule); "all" and "hq" name none.
    public static function asks(string $text, array $projects): array
    {
        $scope = self::scope($projects);
        $edges = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (!preg_match('/^- (\d{4}-\d{2}-\d{2}),\s*([^:]*?):\s*\S/', $line, $m)) {
                continue;
            }
            $word = strtolower(trim($m[2]));
            $owner = null;
            $starts = [];
            foreach (array_keys($scope) as $name) {
                $bare = strtolower(trim((string) $name, '_'));
                if ($bare === $word) {
                    $owner = (string) $name;
                    break;
                }
                if ($word !== '' && str_starts_with($bare, $word . '-')) {
                    $starts[] = (string) $name;
                }
            }
            $owner ??= count($starts) === 1 ? $starts[0] : null;
            $body = self::squash(substr($line, 2));
            $edges = array_merge($edges, self::lesson('ask', $m[1] . '/' . substr(self::slug($body), 0, 50) . '/' . substr(sha1($body), 0, 8), $body, $body, $scope, $owner));
        }
        return $edges;
    }

    /** Landed cards: [project, card folder, result.md text] for each .claude/work/<card>/result.md. */
    public static function cards(array $projects): array
    {
        $out = [];
        foreach ($projects as $name => $dir) {
            foreach (glob(rtrim((string) $dir, '\\/') . '/.claude/work/*/result.md') ?: [] as $f) {
                $text = self::read($f);
                if ($text !== '') {
                    $out[] = [(string) $name, basename(dirname($f)), $text];
                }
            }
        }
        return $out;
    }

    // Each result.md is one fix, labelled "project/card: first words"; it always concerns the project it sits in.
    public static function fixes(array $cards, array $projects): array
    {
        $scope = self::scope($projects);
        $edges = [];
        foreach ($cards as [$project, $card, $text]) {
            $body = self::squash((string) preg_replace('/\A\s*#[^\n]*\n/', '', $text));
            if ($body === '') {
                continue;
            }
            $edges = array_merge($edges, self::lesson('fix', $project . '/' . $card . '/' . substr(sha1($body), 0, 8),
                $project . '/' . $card . ': ' . $body, $text, $scope, $project));
        }
        return $edges;
    }

    /** Pending dream proposals: [project, file name, text] for each .claude/dream/pending/proposal-*.md; logs/ is never read. */
    public static function proposals(array $projects): array
    {
        $out = [];
        foreach ($projects as $name => $dir) {
            foreach (glob(rtrim((string) $dir, '\\/') . '/.claude/dream/pending/proposal-*.md') ?: [] as $f) {
                $text = self::read($f);
                if ($text !== '') {
                    $out[] = [(string) $name, basename($f), $text];
                }
            }
        }
        return $out;
    }

    // One node per "### item-id" section, labelled "project: item-id".
    public static function dreams(array $proposals, array $projects): array
    {
        $scope = self::scope($projects);
        $edges = [];
        foreach ($proposals as [$project, $file, $text]) {
            $parts = preg_split('/^###\s+(\S+)\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            for ($i = 1; $i + 1 < count($parts); $i += 2) {
                $body = (string) preg_replace('/^##\s.*\z/ms', '', $parts[$i + 1]);
                $edges = array_merge($edges, self::lesson('dream', $project . '/' . $file . '/' . $parts[$i],
                    $project . ': ' . $parts[$i], $body, $scope, $project));
            }
        }
        return $edges;
    }
}
