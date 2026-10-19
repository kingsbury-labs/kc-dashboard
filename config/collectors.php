<?php
// What bin/collect-facts.php collects. ssl_hosts: certificates to watch. eol_products: product and installed
// version for endoflife.date. finance_path: kc-finance's alerts file, read only. finance_aliases: Bigcapital
// customer display name => client slug, for names that differ from clients/_DASHBOARD.md.
return [
    'ssl_hosts' => ['kingsburycreative.com'],
    'eol_products' => [
        ['product' => 'php', 'cycle' => '8.2', 'label' => 'PHP 8.2 (XAMPP)'],
    ],
    'finance_path' => 'C:\\Users\\roban\\kc-finance\\alerts\\alerts.json',
    'finance_aliases' => [],
];
