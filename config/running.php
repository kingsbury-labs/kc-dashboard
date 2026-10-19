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
];
