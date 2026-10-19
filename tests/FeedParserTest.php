<?php
require_once __DIR__ . '/../src/News/FeedParser.php';

const FP_FIX = __DIR__ . '/fixtures/feeds';

function fp_parse(string $file, string $topic = 'dev', array $more = []): array
{
    return FeedParser::parse((string) file_get_contents(FP_FIX . '/' . $file), ['name' => 'Example', 'topic' => $topic] + $more);
}

// xxe.xml pointed at the marker file.
function fp_xxe(): string
{
    $target = 'file:///' . str_replace('\\', '/', realpath(FP_FIX . '/xxe-target.txt'));
    return str_replace('__TARGET__', $target, (string) file_get_contents(FP_FIX . '/xxe.xml'));
}

function fp_throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
}

test('an RSS 2.0 fixture gives title, url, date, source and topic; an untitled item is left out', function () {
    $items = fp_parse('rss.xml', 'php');
    assert_same(2, count($items));
    assert_same('PHP 8.5 released with the pipe operator', $items[0]['title']);
    assert_same('https://rss.example.test/php-85?utm_source=rss&utm_medium=feed', $items[0]['url']);
    assert_same(strtotime('2026-10-18 09:00:00 UTC'), $items[0]['date']);
    assert_same('Example', $items[0]['source']);
    assert_same('php', $items[0]['topic']);
    assert_same(null, $items[0]['points']);
    assert_same('Tips for faster builds & caching', $items[1]['title'], 'tags and entities out of the CDATA title');
});

test('an Atom fixture gives the alternate link and the published date, or updated when that is all it has', function () {
    $items = fp_parse('atom.xml', 'webdev');
    assert_same(2, count($items));
    assert_same('Notes on the new browser view transitions', $items[0]['title']);
    assert_same('https://atom.example.test/view-transitions', $items[0]['url']);
    assert_same(strtotime('2026-10-18T08:30:00Z'), $items[0]['date']);
    assert_same('https://atom.example.test/only-updated', $items[1]['url']);
    assert_same(strtotime('2026-10-16T08:00:00Z'), $items[1]['date']);
    assert_same('webdev', $items[1]['topic']);
});

test('a JSON Feed fixture gives its titled items with their dates', function () {
    $items = fp_parse('feed.json', 'wordpress');
    assert_same(1, count($items));
    assert_same('WordPress 7.1 beta ships a new block editor API', $items[0]['title']);
    assert_same('https://json.example.test/wp-71-beta', $items[0]['url']);
    assert_same(strtotime('2026-10-18T18:00:00Z'), $items[0]['date']);
    assert_same('wordpress', $items[0]['topic']);
});

test('the Algolia fixture gives points, and an Ask HN with no url links to its HN item', function () {
    $items = fp_parse('algolia.json', 'dev', ['format' => 'algolia']);
    assert_same(2, count($items));
    assert_same(412, $items[0]['points']);
    assert_same('https://www.php.example.test/releases/8.5', $items[0]['url']);
    assert_same('2026-10-18T10:00:00+00:00', gmdate('c', $items[0]['date']));
    assert_same(57, $items[1]['points']);
    assert_same('https://news.ycombinator.com/item?id=45100002', $items[1]['url']);
});

test('a malformed feed is refused, as is JSON that is neither feed shape', function () {
    assert_true(fp_throws(fn() => fp_parse('malformed.xml')), 'malformed XML parsed');
    assert_true(fp_throws(fn() => FeedParser::parse('{"items": []}', ['name' => 'x', 'topic' => 'dev'])), 'shapeless JSON parsed');
    assert_true(fp_throws(fn() => FeedParser::parse('<html><body>404</body></html>', ['name' => 'x', 'topic' => 'dev'])), 'HTML parsed');
});

test('points come only from a feed configured as algolia, and are capped', function () {
    assert_true(fp_throws(fn() => fp_parse('algolia.json')), 'an Algolia-shaped body from an ordinary feed was read');
    $body = '{"hits": [{"title": "Huge", "url": "https://a.example.test/", "points": 999999999, "objectID": "1"}]}';
    $items = FeedParser::parse($body, ['name' => 'HN', 'topic' => 'dev', 'format' => 'algolia']);
    assert_same(FeedParser::MAX_POINTS, $items[0]['points']);
    assert_true(fp_throws(fn() => FeedParser::parse('{"version": "https://jsonfeed.org/version/1.1", "items": []}',
        ['name' => 'HN', 'topic' => 'dev', 'format' => 'algolia'])), 'an algolia feed read a JSON Feed');
});

test('the XML parse runs with exactly NONET and NOCDATA, under a null entity loader that is restored after', function () {
    assert_same(LIBXML_NONET | LIBXML_NOCDATA, FeedParser::XML_FLAGS);
    foreach (['LIBXML_NOENT' => LIBXML_NOENT, 'LIBXML_DTDLOAD' => LIBXML_DTDLOAD, 'LIBXML_DTDATTR' => LIBXML_DTDATTR,
        'LIBXML_DTDVALID' => LIBXML_DTDVALID] as $name => $bit) {
        assert_same(0, FeedParser::XML_FLAGS & $bit, $name);
    }
    $src = (string) file_get_contents(__DIR__ . '/../src/News/FeedParser.php');
    assert_same(1, substr_count($src, 'simplexml_load_string('), 'one XML parse');
    assert_true(str_contains($src, 'simplexml_load_string($body, SimpleXMLElement::class, self::XML_FLAGS)'), 'parse uses XML_FLAGS');
    assert_true(str_contains($src, 'libxml_set_external_entity_loader(fn() => null);'), 'null loader');
    $mine = fn() => null;
    libxml_set_external_entity_loader($mine);
    try {
        fp_parse('rss.xml');
        assert_same($mine, libxml_get_external_entity_loader(), 'loader not restored');
    } finally {
        libxml_set_external_entity_loader(null);
    }
});

test('a UTF-16 feed with a DOCTYPE, invalid UTF-8 and a declared non-UTF-8 encoding are each refused', function () {
    $utf16 = "\xFF\xFE" . mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', fp_xxe()), 'UTF-16LE', 'UTF-8');
    assert_true(!str_contains($utf16, '<!DOCTYPE'), 'the byte check alone would miss this body');
    assert_true(fp_throws(fn() => FeedParser::parse($utf16, ['name' => 'x', 'topic' => 'dev'])), 'UTF-16 parsed');
    $rss = (string) file_get_contents(FP_FIX . '/rss.xml');
    assert_true(fp_throws(fn() => FeedParser::parse(str_replace('PHP 8.5', "PHP \xff 8.5", $rss), ['name' => 'x', 'topic' => 'dev'])),
        'invalid UTF-8 parsed');
    assert_true(fp_throws(fn() => FeedParser::parse(str_replace('encoding="UTF-8"', 'encoding="ISO-8859-1"', $rss),
        ['name' => 'x', 'topic' => 'dev'])), 'a declared ISO-8859-1 feed parsed');
    assert_same(2, count(FeedParser::parse("\xEF\xBB\xBF" . $rss, ['name' => 'x', 'topic' => 'dev'])), 'a UTF-8 BOM is fine');
});

test('an XXE fixture yields no file content: the feed is refused', function () {
    $body = fp_xxe();
    $items = null;
    $refused = false;
    try {
        $items = FeedParser::parse($body, ['name' => 'x', 'topic' => 'dev']);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    assert_true(!str_contains((string) json_encode($items), 'XXE-FIXTURE-MARKER'), 'file content reached an item');
    assert_true($refused, 'a feed with a DOCTYPE was parsed');
});

test('safeUrl keeps http and https links and drops javascript:, data:, file: and odd characters', function () {
    assert_same('https://a.example.test/x', FeedParser::safeUrl('https://a.example.test/x'));
    assert_same('http://a.example.test/x', FeedParser::safeUrl('http://a.example.test/x'));
    foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,hi', 'file:///C:/x', 'ftp://a.example.test/',
        'https://', 'https://a.example.test/"onmouseover', "https://a.example.test/\nx", '//a.example.test/x', null] as $bad) {
        assert_same(null, FeedParser::safeUrl($bad), var_export($bad, true));
    }
});
