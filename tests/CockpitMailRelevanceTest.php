<?php
// Uses cy_world, cy_mail and cy_snapshot from CockpitDayTest, which the runner loads first (files run in name order).
require_once __DIR__ . '/../src/Mail/Relevance.php';

const CM_FIX = __DIR__ . '/fixtures/cockpit-mail';
const CM_ENTITIES = ['acme' => ['example.com'], 'kc-portal' => [], 'DigitalOcean' => ['digitalocean.com']];

// A Relevance over the fixture entities with a stubbed model: the portal mail is kc-portal, anything else null.
function cm_relevance(string $t, array &$asked): Closure
{
    $r = new Relevance(CM_ENTITIES, $t . '/mail-senders.json', function (string $prompt) use (&$asked): string {
        $asked[] = $prompt;
        return str_contains($prompt, 'quokka') ? '{"project": "KC-Portal"}' : '{"project": null}';
    });
    return fn(array $m): ?string => $r->tag($m);
}

function cm_collect(string $t, Log $log, array &$asked, array &$summed): void
{
    $sent = [];
    @unlink($t . '/mail-cache.json');
    $model = function (array $m) use (&$summed): ?array {
        $summed[] = $m['uid'];
        return ['summary' => 'About: ' . $m['subject'], 'reply' => true];
    };
    cy_mail($t, $log, $sent, $model, false, cm_relevance($t, $asked), CM_FIX)->collect(CY_NOW);
}

function cm_post(string $t, array $body, bool $token = true): array
{
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://127.0.0.1:8787']
        + ($token ? [RequestGuard::TOKEN_HEADER => CY_TOKEN] : []);
    return CockpitDay::write($server, fn(): string => CY_TOKEN, fn(): string => json_encode($body), $t . '/followups.json', CY_NOW, null,
        $t . '/mail-senders.json');
}

test('a client-domain sender is tagged without a model call and a promo the model calls null is hidden', function () {
    [$t, , $log] = cy_world();
    $asked = [];
    $summed = [];
    cm_collect($t, $log, $asked, $summed);
    assert_same(2, count($asked), 'the model is asked about the two senders off the list only');
    assert_true(!str_contains(implode("\n", $asked), 'zebrafinch'), 'the client-domain sender reached the model');
    assert_true(str_contains($asked[0], 'acme, kc-portal, DigitalOcean'), 'the model sees the entity names');
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Dev Helper', 'Ann Acme'], array_column($d['mail']['rows'], 'from'));
    assert_same(['kc-portal', 'acme'], array_column($d['mail']['rows'], 'project'));
    assert_same(['Staging login for the portal', 'Logo files'], array_column($d['follow']['threads'], 'subject'), 'follow-ups only from tagged mail');
    assert_same([203, 201], $summed, 'hidden mail is not summed up');
});

test('a correction marking a sender not mine hides its later mail and a correction to a project tags it', function () {
    [$t, , $log] = cy_world();
    $asked = [];
    $summed = [];
    cm_collect($t, $log, $asked, $summed);
    [$status, , $body] = cm_post($t, ['sender' => 'Ann@Example.com', 'project' => null]);
    assert_same(200, $status, $body);
    [$status, , $body] = cm_post($t, ['sender' => 'deals@example.net', 'project' => 'kc-portal']);
    assert_same(200, $status, $body);
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Dev Helper', 'Klarna'], array_column($d['mail']['rows'], 'from'), 'corrections apply to rows already read');
    assert_same(['kc-portal', 'kc-portal'], array_column($d['mail']['rows'], 'project'));
    $asked = [];
    cm_collect($t, $log, $asked, $summed);
    assert_same(1, count($asked), 'corrected senders need no model call');
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Dev Helper', 'Klarna'], array_column($d['mail']['rows'], 'from'), 'and their later mail follows the correction');
    assert_same(400, cm_post($t, ['sender' => 'not an address', 'project' => null])[0]);
});

test('a correction POST without the token is refused and no mail body text is written to disk', function () {
    [$t, , $log] = cy_world();
    $asked = [];
    $summed = [];
    [$status] = cm_post($t, ['sender' => 'ann@example.com', 'project' => null], false);
    assert_same(403, $status);
    assert_true(!is_file($t . '/mail-senders.json'), 'nothing written without the token');
    cm_collect($t, $log, $asked, $summed);
    cm_post($t, ['sender' => 'dev@example.org', 'project' => 'acme']);
    [$body] = cy_snapshot($t, $log);
    $disk = $body;
    foreach (glob($t . '/*') as $f) {
        $disk .= is_file($f) ? (string) file_get_contents($f) : '';
    }
    assert_true(is_file($t . '/mail-senders.json'));
    foreach (['zebrafinch', 'marmalade', 'quokka'] as $word) {
        assert_true(!str_contains($disk, $word), "mail text \"$word\" was written");
    }
});

// Card 131: client contacts from an invented _CONTACTS.md. example.net and example.org play gmail.com and outlook.com
// (the PII hook allows only example domains in tests); example.com is Ownco's own domain. Freeloader claims both free domains.
require_once __DIR__ . '/../src/Mail/ClientContacts.php';

const CM_FREE = ['example.net', 'example.org'];

function cm_free(string $t, Log $log, array $contacts, array &$asked): void
{
    $r = new Relevance(CM_ENTITIES + ['Freeloader' => CM_FREE], $t . '/mail-senders.json',
        function (string $prompt) use (&$asked): string {
            $asked[] = $prompt;
            return '{"project": "Not A Name"}';
        }, $contacts, CM_FREE);
    $sent = [];
    @unlink($t . '/mail-cache.json');
    cy_mail($t, $log, $sent, fn(array $m): ?array => ['summary' => 'About: ' . $m['subject'], 'reply' => false], false,
        fn(array $m): ?string => $r->tag($m), CM_FIX . '/freemail')->collect(CY_NOW);
}

function cm_contacts(Log $log): array
{
    return ClientContacts::read($log, CM_FIX . '/' . ClientContacts::FILE);
}

test('_CONTACTS.md gives one entry per address, named by Business else Name, from the first email table only', function () {
    [, , $log] = cy_world();
    assert_same([
        ['name' => "Jo's Garden", 'email' => 'jo.client@example.net'],
        ['name' => 'Ownco', 'email' => 'bea@example.com'],
        ['name' => 'Free Fan', 'email' => 'fan@example.net'],
        ['name' => 'Web Fan', 'email' => 'fan@example.org'],
        ['name' => 'Two Desks', 'email' => 'duo.one@example.net'],
        ['name' => 'Two Desks', 'email' => 'duo.two@example.org'],
    ], cm_contacts($log));
    assert_same('Two Desks', Relevance::byContact('Duo.Two@example.org', cm_contacts($log), CM_FREE), 'the second address in a cell maps too');
});

test('no file under src/ or config/ that card 131 touches names Daybook, MySQL, PDO or a credentials path', function () {
    foreach (['src/Mail/ClientContacts.php', 'src/Mail/Relevance.php', 'src/Cockpit/CockpitDay.php', 'config/clients.php'] as $f) {
        assert_true(!preg_match('/daybook|mysql|\bPDO\b|\.credentials|kc_dashboard_ro/i', (string) file_get_contents(__DIR__ . '/../' . $f), $m),
            "$f names " . ($m[0] ?? ''));
    }
    // These three already use the dashboard's own SQLite or Gmail credential files; only Daybook is barred there.
    foreach (['src/Graph/Classifier.php', 'src/Collectors/Mail.php', 'config/collectors.php'] as $f) {
        assert_true(!preg_match('/daybook|mysql|kc_dashboard_ro/i', (string) file_get_contents(__DIR__ . '/../' . $f), $m), "$f names " . ($m[0] ?? ''));
    }
});

test('the real free-mail list holds gmail.com and outlook.com and covers their subdomains', function () {
    foreach (['gmail.com', 'x.gmail.com', 'outlook.com', 'mail.outlook.com'] as $d) {
        assert_true(Relevance::freeMail($d), "$d is not free mail");
    }
    assert_true(!Relevance::freeMail('notgmail.com') && !Relevance::freeMail('acme.ca'), 'a look-alike domain counted as free mail');
});

test('a free-mail client maps by exact address, its own-domain client by domain, and free-mail strangers land in Unsorted', function () {
    [$t, , $log] = cy_world();
    $asked = [];
    cm_free($t, $log, cm_contacts($log), $asked);
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Ownco Billing' => 'Ownco', 'Jo Client' => "Jo's Garden"], array_column($d['mail']['rows'], 'project', 'from'));
    assert_same(['Ola Webmail', 'Sam Sub', 'Stranger'], array_column($d['mail']['unsure'], 'from'), 'whole free-mail domains and subdomains never map');
    assert_same(3, count($asked), 'only the unsorted senders reach the model');
    $prompts = implode("\n", $asked);
    foreach (['jo.client@', 'bea@', 'fan@'] as $a) {
        assert_true(!str_contains($prompts, $a), "client address $a reached the model");
    }
    assert_true(str_contains($prompts, "Jo's Garden"), 'the model sees client names');
});

test('corrections beat a client address, and a correction to null hides mail from Unsorted too', function () {
    [$t, , $log] = cy_world();
    Relevance::correct($t . '/mail-senders.json', 'jo.client@example.net', 'kc-portal');
    Relevance::correct($t . '/mail-senders.json', 'stranger@example.net', null);
    $asked = [];
    cm_free($t, $log, cm_contacts($log), $asked);
    [, $d] = cy_snapshot($t, $log);
    assert_same('kc-portal', array_column($d['mail']['rows'], 'project', 'from')['Jo Client']);
    assert_same(['Ola Webmail', 'Sam Sub'], array_column($d['mail']['unsure'], 'from'));
});

test('a missing _CONTACTS.md gives no contacts, logs the file but no values, and mail still works', function () {
    [$t, , $log] = cy_world();
    assert_same([], ClientContacts::read($log, $t . '/nowhere/' . ClientContacts::FILE));
    $logged = (string) @file_get_contents($t . '/app.log');
    assert_true(str_contains($logged, ClientContacts::FILE), 'the log names the file: ' . $logged);
    assert_true(!str_contains($logged, '@') && !str_contains($logged, 'nowhere'), $logged);
    $asked = [];
    cm_free($t, $log, [], $asked);
    [, $d] = cy_snapshot($t, $log);
    assert_same(['acme'], array_column($d['mail']['rows'], 'project'), 'with no contacts the own-domain mail falls back to the entity domain');
    assert_same(4, count($d['mail']['unsure']), 'the rest shows as Unsorted');
});

test('old cached rows with no project show in Unsorted, and the cache holds no body or client address list', function () {
    [$t, , $log] = cy_world();
    $asked = [];
    cm_free($t, $log, cm_contacts($log), $asked);
    $cache = (string) file_get_contents($t . '/mail-cache.json');
    foreach (['pangolin', 'narwhal', 'bea@', 'fan@', 'Free Fan'] as $word) {
        assert_true(!str_contains($cache, $word), "\"$word\" was written to the cache");
    }
    file_put_contents($t . '/mail-cache.json', json_encode(['at' => CY_NOW, 'error' => null, 'rows' => [
        ['uid' => 1, 'from' => 'Old Row', 'addr' => 'old@example.com', 'project' => null, 'subject' => 'Before', 'date' => CY_NOW - 60]]]));
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Old Row'], array_column($d['mail']['unsure'], 'from'));
});
