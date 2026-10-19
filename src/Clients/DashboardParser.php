<?php

// Reads the table under `## Active Projects` in clients/_DASHBOARD.md. Cell text is data: markdown
// emphasis, arrows and emoji are stripped, and only http(s) links from config/clients.php are attached.
class DashboardParser
{
    public const LINK_KINDS = ['site', 'ga4', 'search_console', 'billing', 'repo', 'folder'];

    /** @return array{clients:array<int,array<string,?string>>, duplicates:string[]} */
    public static function parse(string $md): array
    {
        $clients = [];
        $seen = [];
        $duplicates = [];
        foreach (self::table($md, 'Active Projects') as $row) {
            $slug = $row['slug'] ?? '';
            $due = $row['due'] ?? '';
            $clients[] = [
                'client' => $row['client'] ?? '',
                'slug' => $slug,
                'phase' => $row['phase'] ?? '',
                'milestone' => $row['next milestone'] ?? '',
                'due' => self::isoDate($due),
                'due_text' => $due,
                'status' => $row['status'] ?? '',
                'blocker' => $row['blocker'] ?? '',
            ];
            if ($slug !== '') {
                if (isset($seen[$slug]) && !in_array($slug, $duplicates, true)) {
                    $duplicates[] = $slug;
                }
                $seen[$slug] = true;
            }
        }
        return ['clients' => $clients, 'duplicates' => $duplicates];
    }

    // Attaches each client's links by slug. A client with no slug gets none; a link that is not a plain
    // http(s) URL, or is of an unknown kind, is dropped.
    public static function withLinks(array $clients, array $links): array
    {
        foreach ($clients as &$c) {
            $c['links'] = [];
            $mine = $c['slug'] !== '' && is_array($links[$c['slug']] ?? null) ? $links[$c['slug']] : [];
            foreach (self::LINK_KINDS as $kind) {
                $url = $mine[$kind] ?? null;
                if (is_string($url) && self::safeUrl($url)) {
                    $c['links'][$kind] = $url;
                }
            }
        }
        unset($c);
        return $clients;
    }

    public static function safeUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s<>"\'\\\\]+$#i', $url) === 1
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * Rows of the first table under the `## <heading>` section, keyed by lower-case header text.
     * @return array<int,array<string,string>>
     */
    public static function table(string $md, string $heading): array
    {
        $rows = [];
        $in = false;
        $header = null;
        $started = false;
        foreach (preg_split('/\r\n|\n|\r/', $md) as $line) {
            $t = trim($line);
            if (preg_match('/^##\s+(.*)$/', $t, $m)) {
                if ($in) {
                    break;
                }
                $in = trim($m[1]) === $heading;
                continue;
            }
            if (!$in) {
                continue;
            }
            if (!str_starts_with($t, '|')) {
                if ($started) {
                    break;
                }
                continue;
            }
            $started = true;
            $cells = self::cells($t);
            if ($header === null) {
                $header = array_map('strtolower', $cells);
                continue;
            }
            if (preg_match('/^\|[\s:|-]+\|$/', $t)) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return string[] */
    private static function cells(string $line): array
    {
        $line = str_replace('\\|', "\x01", substr($line, 1, str_ends_with($line, '|') ? -1 : null));
        return array_map(fn($c) => self::clean(str_replace("\x01", '|', $c)), explode('|', $line));
    }

    public static function clean(string $text): string
    {
        $text = str_replace(['**', '__', '~~', '`'], '', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '$1', $text);
        $text = preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/u', '$1', $text);
        $text = str_replace(['->', '<-', '=>'], ' ', $text);
        // Arrows, then emoji and pictographs, the variation selector and the zero-width joiner.
        $text = preg_replace('/[\x{2190}-\x{21FF}\x{27F0}-\x{27FF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}'
            . '\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private static function isoDate(string $text): ?string
    {
        if (!preg_match('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $text, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[0];
    }
}
