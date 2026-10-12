<?php

class Db
{
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
        return $pdo;
    }

    public static function migrate(PDO $pdo, ?string $dir = null): void
    {
        $dir = $dir ?? __DIR__ . '/migrations';
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $seen = $pdo->prepare('SELECT 1 FROM migrations WHERE name = ?');
            $seen->execute([$name]);
            if ($seen->fetchColumn()) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec(file_get_contents($file));
                $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)')
                    ->execute([$name, gmdate('c')]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}
