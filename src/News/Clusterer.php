<?php

// Groups stored items into stories, taking items in the order they were first seen. An item joins the first story
// that has an item with its canonical url, or whose first item's title shares at least MIN_SHARED words with it and
// at least MIN_OVERLAP of the two titles' words together (after stopwords and a crude suffix strip). Comparing with
// the first item only, never a chain, and against the union of both titles, means one feed's long title cannot glue
// two stories together. A source gives a story at most one item; its others for that story are dropped.
class Clusterer
{
    public const MIN_SHARED = 3;
    public const MIN_OVERLAP = 0.5;
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'its', 'are', 'was', 'has', 'have', 'new', 'now',
        'how', 'why', 'what', 'you', 'your', 'our', 'this', 'that', 'out', 'can', 'not', 'but', 'all', 'via', 'after'];

    /**
     * @param array<int,array{source:string, topic:string, title:string, url:?string, canon:?string, date:?int, points:?int, first_seen?:int}> $items
     * @return array<int,array{title:string, url:?string, topics:string[], sources:string[], points:int, date:?int, items:array}>
     */
    public static function cluster(array $items): array
    {
        $items = array_values($items);
        // Stable: equal first_seen keeps the input order.
        $order = array_keys($items);
        usort($order, fn($a, $b) => [$items[$a]['first_seen'] ?? 0, $a] <=> [$items[$b]['first_seen'] ?? 0, $b]);
        $groups = [];
        $heads = [];
        $byCanon = [];
        foreach ($order as $i) {
            $item = $items[$i];
            $words = self::words((string) $item['title']);
            $c = is_string($item['canon'] ?? null) && $item['canon'] !== '' ? $item['canon'] : null;
            $into = $c !== null ? ($byCanon[$c] ?? null) : null;
            if ($into === null) {
                foreach ($heads as $g => $head) {
                    if (self::similar($words, $head)) {
                        $into = $g;
                        break;
                    }
                }
            }
            if ($into === null) {
                $into = count($groups);
                $groups[$into] = [];
                $heads[$into] = $words;
            } elseif (in_array($item['source'], array_column($groups[$into], 'source'), true)) {
                continue;
            }
            $groups[$into][] = $item;
            if ($c !== null) {
                $byCanon[$c] = $byCanon[$c] ?? $into;
            }
        }
        return array_values(array_map([self::class, 'story'], $groups));
    }

    // scheme and host lowercased, www. dropped, http and https alike, the fragment and utm_*, fbclid and gclid
    // query parameters removed, the rest sorted, a trailing slash dropped. Null for anything not http(s).
    public static function canonical(?string $url): ?string
    {
        $p = $url !== null ? parse_url($url) : false;
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || ($p['host'] ?? '') === '') {
            return null;
        }
        $host = strtolower($p['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $query = [];
        foreach (explode('&', $p['query'] ?? '') as $pair) {
            $key = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if ($pair !== '' && !str_starts_with($key, 'utm_') && !in_array($key, ['fbclid', 'gclid'], true)) {
                $query[] = $pair;
            }
        }
        sort($query);
        $path = rtrim($p['path'] ?? '', '/');
        return $host . (isset($p['port']) ? ':' . $p['port'] : '') . $path . ($query !== [] ? '?' . implode('&', $query) : '');
    }

    /** @return array<string,true> */
    public static function words(string $title): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title, 'UTF-8')) ?: [] as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true)) {
                continue;
            }
            foreach (['ing', 'ed', 'es', 's'] as $suffix) {
                if (str_ends_with($w, $suffix) && mb_strlen($w) - strlen($suffix) >= 4) {
                    $w = substr($w, 0, -strlen($suffix));
                    break;
                }
            }
            $out[$w] = true;
        }
        return $out;
    }

    private static function similar(array $a, array $b): bool
    {
        $shared = count(array_intersect_key($a, $b));
        $union = count($a + $b);
        return $shared >= self::MIN_SHARED && $union > 0 && $shared / $union >= self::MIN_OVERLAP;
    }

    // The group arrives in first_seen order. The story takes its title, link and date from the first item seen that
    // has a link: when this machine saw it is ours, while a feed's own date is whatever the feed says.
    private static function story(array $group): array
    {
        $front = $group[0];
        foreach ($group as $it) {
            if ($it['url'] !== null) {
                $front = $it;
                break;
            }
        }
        return [
            'title' => $front['title'],
            'url' => $front['url'],
            'topics' => array_values(array_unique(array_column($group, 'topic'))),
            'sources' => array_values(array_unique(array_column($group, 'source'))),
            'points' => (int) max(array_map(fn($it) => (int) ($it['points'] ?? 0), $group)),
            'date' => $front['date'] ?? null,
            'items' => $group,
        ];
    }
}
