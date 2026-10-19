<?php
// Where Ask Claude may run. Folders directly under `root`, plus each exact `extra` folder, listed by basename.
// `home` is used when no project is picked. `busy_if_dirty` names a path inside the folder: uncommitted
// changes there mean the project's own loop is mid-card, so the ask is answered "busy" without running.
return [
    'root' => 'D:\\Dev\\htdocs',
    'home' => 'C:\\Users\\roban',
    'extra' => [
        ['path' => 'C:\\Users\\roban\\kc-finance', 'busy_if_dirty' => 'scripts/'],
    ],
];
