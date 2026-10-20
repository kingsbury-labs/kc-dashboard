<?php
// What's running (api/now.php). `sessions_dir` is Claude Code's session folder: only `<pid>.json` files in it
// are ever opened (it also holds `.key` files). `task_folder` is the Task Scheduler folder whose tasks are listed.
// `aliases` rewrite a session cwd prefix before it is matched to a project: C:\xampp\htdocs entries are junctions
// into D:\Dev\htdocs, so a session started there still maps to its project without following the junction.
return [
    'sessions_dir' => 'C:\\Users\\roban\\.claude\\sessions',
    'task_folder' => '\\KC Dashboard\\',
    'aliases' => ['C:\\xampp\\htdocs' => 'D:\\Dev\\htdocs'],
    'cache_seconds' => 3,
    // The only http://127.0.0.1 ports an attention fact may link to: 8000 is Bigcapital (the finance feed).
    'link_ports' => [8000],
    // Running ports keeps only these programs on ports from 1024 up, plus the always ports whatever owns them
    // (8787 is this dashboard, 8000 Bigcapital). AVG, Steam and system services are dropped.
    'port_programs' => ['node.exe', 'php.exe', 'python.exe', 'httpd.exe'],
    'port_always' => [8787, 8000],
    // CPU, RAM and disk from one PowerShell CIM query, cached this long.
    'system_cache_seconds' => 10,
];
