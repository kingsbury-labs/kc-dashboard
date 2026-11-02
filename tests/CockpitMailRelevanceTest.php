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
        ['name' => "Jo's Garden", 'email' => 'jo.client@example.net', 'person' => "Jo's Garden"],
        ['name' => 'Ownco', 'email' => 'bea@example.com', 'person' => 'Bea'],
        ['name' => 'Free Fan', 'email' => 'fan@example.net', 'person' => 'G'],
        ['name' => 'Web Fan', 'email' => 'fan@example.org', 'person' => 'O'],
        ['name' => 'Two Desks', 'email' => 'duo.one@example.net', 'person' => 'Duo'],
        ['name' => 'Two Desks', 'email' => 'duo.two@example.org', 'person' => 'Duo'],
    ], cm_contacts($log));
    assert_same('Two Desks', Relevance::byContact('Duo.Two@example.org', cm_contacts($log), CM_FREE), 'the second address in a cell maps too');
});

test('no file under src/ or config/ that card 131 touches names Daybook, MySQL, PDO or a credentials path', function () {
    foreach (['src/Mail/ClientContacts.php', 'src/Mail/Relevance.php', 'src/Mail/MailRules.php', 'src/Cockpit/CockpitDay.php', 'config/clients.php'] as $f) {
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
    // Card 133: billing@ on Ownco's domain is a system sender, not Ownco; the entity domain list still places it.
    assert_same(['Ownco Billing' => 'acme', 'Jo Client' => "Jo's Garden"], array_column($d['mail']['rows'], 'project', 'from'));
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

// Card 133: Rob's client pattern (MailRules). Invented clients: Ownco on example.com (own domain, site
// ownco.example.com), Jo's Garden on free mail (site garden.example.org), Twin Co on free mail. example.net and
// example.org play free mail, example.studio Rob's studio, example.pay a payment sender, example.io a platform.
function cm_ctx(): array
{
    $contacts = [
        ['name' => 'Ownco', 'email' => 'bea@example.com', 'person' => 'Bea Owner'],
        ['name' => "Jo's Garden", 'email' => 'jo.client@example.net', 'person' => 'Jo Client'],
        ['name' => 'Twin Co', 'email' => 'twin@example.org', 'person' => 'Tam Twin'],
    ];
    $dir = CM_FIX . '/clients';
    return ['contacts' => $contacts, 'free' => CM_FREE, 'studio' => ['example.studio'], 'never' => ['example.pay'],
        'terms' => MailRules::terms($dir, [], $contacts, ['example.studio']),
        'pipeline' => MailRules::pipeline((string) file_get_contents($dir . '/_PIPELINE.md'))];
}

function cm_place(array $m): ?array
{
    return MailRules::place($m + ['addr' => '', 'from' => '', 'subject' => '', 'text' => ''], cm_ctx());
}

test('the client files give site domains and names per client, the studio left out, and pipeline names', function () {
    $c = cm_ctx();
    assert_same(["Jo's Garden", 'Ownco'], array_keys($c['terms']));
    assert_true(in_array('ownco.example.com', $c['terms']['Ownco'], true) && in_array('garden.example.org', $c['terms']["Jo's Garden"], true));
    assert_same(['pat lead' => 'Lead Bakery', 'sol solo' => 'Sol Solo'], $c['pipeline']);
    assert_true(in_array('stripe.com', MailRules::NEVER, true) && in_array('billing', MailRules::SYSTEM_LOCAL, true));
});

test('rule 1 matches an exact contact address on To and on Cc, not only From', function () {
    assert_same(['project' => "Jo's Garden", 'rule' => 1, 'kind' => 'from'], cm_place(['addr' => 'stranger@example.net', 'to' => ['jo.client@example.net']]));
    assert_same(['project' => "Jo's Garden", 'rule' => 1, 'kind' => 'from'],
        cm_place(['addr' => 'stranger@example.net', 'to' => ['hello@example.studio'], 'cc' => ['Jo.Client@example.net']]));
    assert_same(null, cm_place(['addr' => 'stranger@example.net', 'to' => ['hello@example.studio']]));
});

test('rule 2 matches a client domain, refuses wordpress@, noreply@ and billing@ on it, and never a free-mail domain', function () {
    assert_same(['project' => 'Ownco', 'rule' => 2, 'kind' => 'from'], cm_place(['addr' => 'dana@example.com']));
    assert_same(['project' => 'Ownco', 'rule' => 2, 'kind' => 'from'], cm_place(['addr' => 'x@example.net', 'cc' => ['new.staff@example.com']]));
    foreach (['wordpress@example.com', 'noreply@example.com', 'billing@example.com', 'no-reply+x@example.com'] as $a) {
        assert_same(null, cm_place(['addr' => $a]), "$a placed");
    }
    assert_same(null, cm_place(['addr' => 'someone.else@example.net']), 'a free-mail domain matched a free-mail client');
});

test('rule 3 puts every message of a thread Rob wrote to a client under that client, an unknown sender included', function () {
    assert_same(['project' => 'Ownco', 'rule' => 3, 'kind' => 'from'], cm_place(['addr' => 'owl@example.org', 'sent_to' => ['bea@example.com']]));
    assert_same(['project' => "Jo's Garden", 'rule' => 3, 'kind' => 'from'], cm_place(['addr' => 'owl@example.org', 'sent_to' => ['jo.client@example.net']]));
    assert_same(null, cm_place(['addr' => 'owl@example.org', 'sent_to' => ['random@example.net']]));
    assert_same('kitchen plan', MailRules::thread('RE: Fwd:  Kitchen   Plan'));
});

test('rule 4 tags a platform notice naming a client site as about, and a payment sender is never from', function () {
    assert_same(['project' => "Jo's Garden", 'rule' => 4, 'kind' => 'about'],
        cm_place(['addr' => 'alerts@example.io', 'subject' => 'Zone active', 'text' => 'Your zone garden.example.org is now active.']));
    assert_same(['project' => 'Ownco', 'rule' => 4, 'kind' => 'about'], cm_place(['addr' => 'robot@example.io', 'subject' => 'Invite to Ownco repo']));
    assert_same(null, cm_place(['addr' => 'receipt@example.pay', 'cc' => ['bea@example.com'], 'sent_to' => ['bea@example.com']]),
        'a payment sender with a client on Cc was placed');
    assert_same(['project' => 'Ownco', 'rule' => 4, 'kind' => 'about'],
        cm_place(['addr' => 'receipt@example.pay', 'cc' => ['bea@example.com'], 'subject' => 'Payout for ownco.example.com']));
    assert_same(null, cm_place(['addr' => 'robot@example.io', 'subject' => 'ownco.example.community and notownco.example.com']), 'a look-alike matched');
});

test('rule 6 matches a studio-site inquiry relay to the pipeline name, and only from the studio system sender', function () {
    assert_same(['project' => 'Lead Bakery', 'rule' => 6, 'kind' => 'from'],
        cm_place(['addr' => 'noreply@example.studio', 'subject' => 'New project inquiry from Pat Lead']));
    assert_same(['project' => 'Sol Solo', 'rule' => 6, 'kind' => 'from'], cm_place(['addr' => 'noreply@example.studio', 'subject' => 'new project inquiry from sol solo']));
    assert_same(null, cm_place(['addr' => 'noreply@example.studio', 'subject' => 'New project inquiry from Nobody Known']));
    assert_same(null, cm_place(['addr' => 'rob@example.studio', 'subject' => 'New project inquiry from Pat Lead']));
    assert_same(null, cm_place(['addr' => 'noreply@example.io', 'subject' => 'New project inquiry from Pat Lead']));
});

test('rule 5 alone never places a row and only breaks a rule 3 or 4 tie for a free-mail sender', function () {
    assert_same(null, cm_place(['addr' => 'bo@example.net', 'from' => 'Bea Owner']), 'the display name alone placed a row');
    $both = ['subject' => 'DNS for ownco.example.com and garden.example.org'];
    assert_same(null, cm_place(['addr' => 'bo@example.net', 'from' => 'Someone'] + $both), 'an unbroken tie was placed');
    assert_same(['project' => 'Ownco', 'rule' => 5, 'kind' => 'about'], cm_place(['addr' => 'bo@example.net', 'from' => 'Bea Owner'] + $both));
    assert_same(null, cm_place(['addr' => 'bo@example.io', 'from' => 'Bea Owner'] + $both), 'a display name on a non-free address broke a tie');
    assert_same(['project' => 'Twin Co', 'rule' => 5, 'kind' => 'from'],
        cm_place(['addr' => 'tt@example.net', 'from' => 'Tam Twin', 'sent_to' => ['bea@example.com', 'twin@example.org']]));
});

test('the priority order holds when two adjacent rules disagree', function () {
    // 1 over 2: a free-mail client on From, another client's domain on To.
    assert_same(['project' => "Jo's Garden", 'rule' => 1, 'kind' => 'from'], cm_place(['addr' => 'jo.client@example.net', 'to' => ['dana@example.com']]));
    assert_same(['project' => "Jo's Garden", 'rule' => 1, 'kind' => 'from'], cm_place(['addr' => 'dana@example.com', 'cc' => ['jo.client@example.net']]));
    // 2 over 3: Ownco's own domain, in a thread Rob wrote to Jo.
    assert_same(['project' => 'Ownco', 'rule' => 2, 'kind' => 'from'], cm_place(['addr' => 'dana@example.com', 'sent_to' => ['jo.client@example.net']]));
    // 3 over 4: a thread Rob wrote to Ownco that names Jo's site.
    assert_same(['project' => 'Ownco', 'rule' => 3, 'kind' => 'from'],
        cm_place(['addr' => 'owl@example.org', 'sent_to' => ['bea@example.com'], 'subject' => 'Re: garden.example.org']));
    // 4 over 6: an inquiry relay whose first lines name a client's site.
    assert_same(['project' => "Jo's Garden", 'rule' => 4, 'kind' => 'about'],
        cm_place(['addr' => 'noreply@example.studio', 'subject' => 'New project inquiry from Pat Lead', 'text' => 'Saw garden.example.org']));
});

test('the collector reads To, Cc and Sent Mail read-only, places a thread and a Cc row with rule and kind, and caches no body or contacts', function () {
    [$t, , $log] = cy_world();
    $sent = [];
    $ctx = cm_ctx();
    $tag = fn(array $m): array => MailRules::place($m, $ctx) ?? ['project' => null, 'reason' => 'no rule', 'unsure' => true];
    cy_mail($t, $log, $sent, fn(array $m): ?array => ['summary' => 'About: ' . $m['subject'], 'reply' => false], false, $tag, CM_FIX . '/thread')
        ->collect(CY_NOW);
    assert_true(array_filter($sent, fn($c) => !ImapReader::allowed($c)) === [], 'a command off the read-only list');
    $rows = array_column(json_decode((string) file_get_contents($t . '/mail-cache.json'), true)['rows'], null, 'uid');
    assert_same(['Ownco', 3, 'from'], [$rows[302]['project'], $rows[302]['rule'], $rows[302]['kind']], 'the thread reply from an unknown sender');
    assert_same(['Ownco', 1, 'from'], [$rows[303]['project'], $rows[303]['rule'], $rows[303]['kind']], 'the client on Cc');
    assert_same([null, null], [$rows[301]['project'], $rows[301]['rule']], "Rob's own studio mail is never a client's");
    $cache = (string) file_get_contents($t . '/mail-cache.json');
    foreach (['walrus', 'penguin', 'Plan attached', 'bea@', 'jo.client@', 'twin@', 'Bea Owner', 'Lead Bakery'] as $word) {
        assert_true(!str_contains($cache, $word), "\"$word\" was written to the cache");
    }
    [, $d] = cy_snapshot($t, $log);
    assert_same([['Ownco', 1, 'from'], ['Ownco', 3, 'from']], array_map(fn($r) => [$r['project'], $r['rule'], $r['kind']], $d['mail']['rows']));
});

test('the mail card puts its Unsorted pile above the rows, so it shows without scrolling the card at portrait size', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/views.js');
    assert_true(preg_match('/function emailCard\(\) \{(.*?)\r?\n\}\r?\n/s', $src, $f) === 1, 'emailCard found');
    $pile = strpos($f[1], 'c.append(pile)');
    $list = strpos($f[1], 'c.append(list)');
    assert_true($pile !== false && $list !== false && $pile < $list, 'Unsorted is appended after the rows');
    assert_true(str_contains($f[1], '`Unsorted (${unsure.length})`'), 'the pile shows its count');
    assert_true(str_contains($f[1], "x.kind === 'about'"), 'about-client mail is labelled');
});
