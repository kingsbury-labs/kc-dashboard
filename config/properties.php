<?php
// What bin/collect-kc.php collects. PLACEHOLDER VALUES: every id, site, url and file name below is invented until
// Rob fills them in (card 08). Credentials are paths only: the files under .credentials are read at run time by
// the collector that needs them, never copied into this repo, the database, a log or the page.
//
// service_account_path: the GA4 service account's JSON key, which Rob adds as a Viewer on every property below.
// properties: per client slug (the Slug column of _DASHBOARD.md), a GA4 property id and a Search Console site
//   (`https://.../` for a URL-prefix property, `sc-domain:...` for a domain property).
// status_url: Sembr's status endpoint. bugsink: the Bugsink server, the token file and project name => project id.
return [
    'service_account_path' => 'D:\\Dev\\htdocs\\.credentials\\ga4-service-account.PLACEHOLDER.json',
    'properties' => [
        'kingsbury-creative' => ['ga4' => '100000001', 'search_console' => 'sc-domain:kc.example.test'],
        'example-client' => ['ga4' => '100000002', 'search_console' => 'https://client.example.test/'],
    ],
    'status_url' => 'https://status.sembr.co/api/status',
    'bugsink' => [
        'base_url' => 'https://bugsink.example.test',
        'token_path' => 'D:\\Dev\\htdocs\\.credentials\\bugsink-token.PLACEHOLDER.txt',
        'projects' => ['sembr' => 1],
    ],
];
