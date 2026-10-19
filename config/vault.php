<?php
// The Obsidian vault the dashboard writes, created if missing. `clients` maps a project note's slug (the folder
// name, lower case, `[a-z0-9-]`) to the client slug from _DASHBOARD.md, which the note links as [[client-slug]].
return [
    'root' => 'D:\\Dev\\vault\\kc',
    'clients' => [
        'kingsburycreative' => 'kingsbury-creative',
        'kc-dashboard' => 'kingsbury-creative',
    ],
];
