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
    // Open-Meteo for Arnprior ON; cache is a file name under data/, which the cockpit reads.
    'weather' => ['latitude' => 45.43, 'longitude' => -76.35, 'cache' => 'weather-cache.json'],
    // Google Calendar's secret iCal address and Gmail's app password, by path only: values are read at run time.
    // The Gmail address sits in its own file beside them (the PII hook keeps it out of the repo).
    // Caches and the follow-up list are file names under data/.
    'calendar' => ['url_file' => 'D:\\Dev\\htdocs\\.credentials\\kc-dashboard-gcal-ical-url', 'cache' => 'calendar-cache.json',
        'tz' => 'America/Toronto'],
    'mail' => ['gmail_user' => trim((string) @file_get_contents('D:\\Dev\\htdocs\\.credentials\\kc-dashboard-gmail-user')),
        'password_file' => 'D:\\Dev\\htdocs\\.credentials\\kc-dashboard-gmail-app-password',
        'cache' => 'mail-cache.json', 'ollama' => 'http://127.0.0.1:11434', 'model' => 'qwen3.5:9b-q4_K_M'],
    'followups' => 'followups.json',
];
