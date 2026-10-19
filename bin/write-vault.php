<?php
// Vault writer. Usage: php bin/write-vault.php [--db=PATH] [--log=PATH]
// Runs after collect-projects.php: a note per projects row, the projects canvas, and the board if it is missing.

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Vault/Vault.php';
require_once __DIR__ . '/../src/Vault/ProjectNotes.php';
require_once __DIR__ . '/../src/Vault/CanvasExport.php';
require_once __DIR__ . '/../src/Vault/Board.php';

$root = dirname(__DIR__);
$opts = ['db' => $root . '/data/dashboard.sqlite', 'log' => $root . '/data/write-vault.log'];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--(db|log)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$log = new Log($opts['log']);
try {
    $pdo = Db::open($opts['db']);
    Db::migrate($pdo);
    $rows = $pdo->query('SELECT * FROM projects ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC);
    $config = require $root . '/config/vault.php';
    $vault = new Vault($config['root']);
    $writer = new ProjectNotes($vault, $config['clients'] ?? []);
    $notes = $writer->write($rows);
    foreach ($writer->errors() as $slug => $error) {
        $log->info('vault note not written', ['project' => $slug, 'error' => $error]);
    }
    CanvasExport::write($vault, $notes);
    Board::ensure($vault);
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'write-vault']);
    fwrite(STDERR, "write-vault: failed\n");
    exit(1);
}
echo count($notes) . " project notes\n";
exit(0);
