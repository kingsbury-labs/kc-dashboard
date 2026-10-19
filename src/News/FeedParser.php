<?php

// One feed body to a list of items: RSS 2.0, Atom, JSON Feed, or the Hacker News Algolia search (which gives points).
// Each item is {title, url, date, source, topic, points}; url is the feed's raw link, checked by safeUrl() before it
// is stored. A body that is none of these, or will not parse, throws. An item with no title is left out.
// Only a feed whose config says 'format' => 'algolia' is read as the Algolia search, so only it can carry points.
class FeedParser
{
    public const MAX_ITEMS = 100;
    public const MAX_TITLE = 300;
    // Points above this count as this many, so one story's points cannot outweigh everything else for days.
    public const MAX_POINTS = 2000;
    // The only flags the XML parse gets. Never LIBXML_NOENT, LIBXML_DTDLOAD or LIBXML_DTDATTR.
    public const XML_FLAGS = LIBXML_NONET | LIBXML_NOCDATA;
    private const ATOM = 'http://www.w3.org/2005/Atom';

    /**
     * @param array{name:string, topic:string, format?:string} $feed
     * @return array<int,array{title:string, url:?string, date:?int, source:string, topic:string, points:?int}>
     */
    public static function parse(string $body, array $feed): array
    {
        // UTF-8 only: a byte-level DOCTYPE check means nothing in UTF-16 or EBCDIC, so those never reach libxml.
        $body = str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new InvalidArgumentException('feed is not UTF-8');
        }
        $body = ltrim($body, " \t\r\n");
        if (($feed['format'] ?? null) === 'algolia') {
            $raw = self::algolia($body);
        } else {
            $raw = str_starts_with($body, '{') ? self::json($body) : self::xml($body);
        }
        $out = [];
        foreach ($raw as $r) {
            $title = self::text($r['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $url = is_string($r['url'] ?? null) && trim($r['url']) !== '' ? trim($r['url']) : null;
            $out[] = ['title' => $title, 'url' => $url, 'date' => $r['date'] ?? null, 'source' => (string) $feed['name'],
                'topic' => (string) $feed['topic'], 'points' => $r['points'] ?? null];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    // A story link worth storing: http:// or https:// with a host, and nothing a browser or an attribute would
    // read differently (control characters, spaces, quotes, angle brackets, backslashes). Anything else is null.
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\`]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $url : null;
    }

    // A DOCTYPE is refused outright: no feed needs one, and it is where entity tricks (XXE, billion laughs) live.
    // Four layers: a declared encoding other than UTF-8 is refused, the bytes are searched, the parse runs with an
    // entity loader that loads nothing and without NOENT or DTDLOAD, and the parsed document must have no doctype.
    private static function xml(string $body): array
    {
        if (preg_match('/^<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']*)["\']/i', $body, $m) === 1
            && !in_array(strtolower($m[1]), ['utf-8', 'utf8'], true)) {
            throw new InvalidArgumentException('feed declares a non-UTF-8 encoding');
        }
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('feed has a DOCTYPE');
        }
        $prev = libxml_use_internal_errors(true);
        $loader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(fn() => null);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, self::XML_FLAGS);
        } finally {
            libxml_set_external_entity_loader($loader);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($xml === false) {
            throw new InvalidArgumentException('feed is not well-formed XML');
        }
        $dom = dom_import_simplexml($xml);
        if ($dom->ownerDocument === null || $dom->ownerDocument->doctype !== null) {
            throw new InvalidArgumentException('feed has a DOCTYPE');
        }
        if ($xml->getName() === 'rss' && isset($xml->channel)) {
            $out = [];
            foreach ($xml->channel->item as $item) {
                $out[] = ['title' => (string) $item->title, 'url' => (string) $item->link,
                    'date' => self::date((string) $item->pubDate)];
            }
            return $out;
        }
        if ($xml->getName() === 'feed') {
            $out = [];
            foreach ($xml->children(self::ATOM)->entry as $entry) {
                $e = $entry->children(self::ATOM);
                $out[] = ['title' => (string) $e->title, 'url' => self::atomLink($e),
                    'date' => self::date((string) ($e->published ?? '') ?: (string) ($e->updated ?? ''))];
            }
            return $out;
        }
        throw new InvalidArgumentException('not an RSS 2.0 or Atom feed');
    }

    // The alternate link, or the first link with no rel.
    private static function atomLink(SimpleXMLElement $e): ?string
    {
        $first = null;
        foreach ($e->link as $link) {
            // attributes() with no namespace: the element was reached in the Atom namespace, its attributes have none.
            $attr = $link->attributes();
            $rel = (string) ($attr['rel'] ?? '');
            if ($rel === 'alternate') {
                return (string) $attr['href'];
            }
            if ($rel === '' && $first === null) {
                $first = (string) $attr['href'];
            }
        }
        return $first;
    }

    // The Hacker News Algolia search, for a feed configured as 'algolia' only. Points are capped at MAX_POINTS.
    private static function algolia(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['hits']) || !is_array($data['hits'])) {
            throw new InvalidArgumentException('not an Algolia search');
        }
        $out = [];
        foreach ($data['hits'] as $h) {
            if (!is_array($h)) {
                continue;
            }
            $id = is_scalar($h['objectID'] ?? null) && ctype_digit((string) $h['objectID']) ? (string) $h['objectID'] : null;
            $url = is_string($h['url'] ?? null) && $h['url'] !== '' ? $h['url']
                : ($id !== null ? 'https://news.ycombinator.com/item?id=' . $id : null);
            $out[] = ['title' => is_string($h['title'] ?? null) ? $h['title'] : '', 'url' => $url,
                'date' => is_int($h['created_at_i'] ?? null) ? $h['created_at_i'] : null,
                'points' => is_int($h['points'] ?? null) ? min(self::MAX_POINTS, max(0, $h['points'])) : null];
        }
        return $out;
    }

    private static function json(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('feed is not valid JSON');
        }
        if (is_string($data['version'] ?? null) && str_starts_with($data['version'], 'https://jsonfeed.org/version/')
            && is_array($data['items'] ?? null)) {
            $out = [];
            foreach ($data['items'] as $i) {
                if (!is_array($i)) {
                    continue;
                }
                $out[] = ['title' => is_string($i['title'] ?? null) ? $i['title'] : '',
                    'url' => is_string($i['url'] ?? null) ? $i['url'] : null,
                    'date' => self::date(is_string($i['date_published'] ?? null) ? $i['date_published'] : '')];
            }
            return $out;
        }
        throw new InvalidArgumentException('not a JSON Feed');
    }

    // A leading weekday is dropped: strtotime reads "Sat," as "the next Saturday", which moves a date whose
    // weekday the feed got wrong (the rss.xml fixture has one) by up to six days.
    private static function date(string $s): ?int
    {
        $s = trim((string) preg_replace('/^\s*[A-Za-z]{3,9},\s*/', '', $s));
        $t = $s !== '' ? strtotime($s) : false;
        return $t === false ? null : $t;
    }

    // Plain text: tags and entities out, whitespace collapsed, valid UTF-8, capped.
    private static function text($s): string
    {
        if (!is_string($s)) {
            return '';
        }
        $s = html_entity_decode(strip_tags(mb_scrub($s, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, self::MAX_TITLE, 'UTF-8');
    }
}
