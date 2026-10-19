<?php
require_once __DIR__ . '/../src/Clients/DashboardParser.php';
require_once __DIR__ . '/../src/Clients/PipelineParser.php';

const CLIENTS_FIXTURES = __DIR__ . '/fixtures/clients';

function clients_dashboard(): array
{
    return DashboardParser::parse((string) file_get_contents(CLIENTS_FIXTURES . '/_DASHBOARD.md'));
}

test('only the Active Projects table gives rows, not the outreach table', function () {
    $names = array_column(clients_dashboard()['clients'], 'client');
    assert_same(5, count($names), 'rows');
    foreach (['Quarry Hill Golf', 'Old Mill Society', 'Prospect'] as $outreach) {
        assert_true(!in_array($outreach, $names, true), $outreach . ' leaked in');
    }
});

test('five active rows carry phase, next milestone and blocker, cleaned', function () {
    $c = clients_dashboard()['clients'];
    assert_same(['Launch', 'Development', 'Discovery', 'Maintenance', 'Design'], array_column($c, 'phase'));
    assert_same('Post-launch checks analytics, real phone', $c[0]['milestone']);
    assert_same('Home page around the signed-off hero', $c[1]['milestone']);
    assert_same('SEO stage 2 Lighthouse 90+', $c[3]['milestone']);
    assert_same('Township has not published the voting page', $c[0]['blocker']);
    assert_same('Board decision', $c[4]['blocker']);
    assert_same('Live, paid', $c[0]['status']);
    assert_same('Scaffolded', $c[2]['status']);
    assert_same('pat-example', $c[0]['slug']);
});

test('due dates: an ISO date anywhere in the cell, else null', function () {
    $c = clients_dashboard()['clients'];
    assert_same('2026-10-26', $c[0]['due'], 'Election 2026-10-26');
    assert_same('Election 2026-10-26', $c[0]['due_text']);
    assert_same(null, $c[1]['due'], 'TBD');
    assert_same('2026-11-05', $c[2]['due']);
    assert_same(null, $c[3]['due'], 'Ongoing');
    $bad = DashboardParser::parse("## Active Projects\n| Client | Slug | Due |\n|---|---|---|\n| A | a | 2026-02-30 |\n");
    assert_same(null, $bad['clients'][0]['due'], 'impossible date');
});

test('leads come from Active Leads only, with bold stripped', function () {
    $leads = PipelineParser::parse((string) file_get_contents(CLIENTS_FIXTURES . '/_PIPELINE.md'));
    assert_same(['Sample Steakhouse', 'Placeholder Dental Group', 'Test Pattern Tattoos'], array_column($leads, 'business'));
    assert_same('Free site builder. KVP: events calendar', $leads[0]['notes']);
    assert_same('Sitewide noindex tag, nav broken', $leads[2]['notes']);
    assert_same(['name' => 'Alex Sample', 'business' => 'Sample Steakhouse', 'town' => 'Northtown', 'stage' => 'Lead',
        'price' => '$2,500', 'next_action' => 'In-person pitch', 'notes' => 'Free site builder. KVP: events calendar'], $leads[0]);
});

test('a client with no slug gets no links, even with a blank-keyed entry', function () {
    $md = "## Active Projects\n| Client | Slug | Phase |\n|---|---|---|\n| Nameless |  | Design |\n";
    $c = DashboardParser::withLinks(DashboardParser::parse($md)['clients'], ['' => ['site' => 'https://x.example']]);
    assert_same([], $c[0]['links']);
});

test('links keep only http and https URLs of known kinds', function () {
    $md = "## Active Projects\n| Client | Slug |\n|---|---|\n| One | one |\n";
    $c = DashboardParser::withLinks(DashboardParser::parse($md)['clients'], ['one' => [
        'site' => 'https://one.example', 'repo' => 'http://git.example/one', 'billing' => 'javascript:alert(1)',
        'ga4' => 'JavaScript:alert(1)', 'folder' => 'file:///D:/clients/one', 'search_console' => 'data:text/html,x',
        'other' => 'https://unknown-kind.example',
    ]]);
    assert_same(['site' => 'https://one.example', 'repo' => 'http://git.example/one'], $c[0]['links']);
});

test('a duplicate slug is reported once', function () {
    $md = "## Active Projects\n| Client | Slug |\n|---|---|\n| A | same |\n| B | same |\n| C | same |\n| D | other |\n";
    $r = DashboardParser::parse($md);
    assert_same(['same'], $r['duplicates']);
    assert_same(4, count($r['clients']));
});
