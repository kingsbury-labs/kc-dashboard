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
