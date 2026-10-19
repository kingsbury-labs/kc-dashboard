<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// Is this PHP (so Apache) running elevated? Read from `whoami /groups`: the mandatory label is High
// (S-1-16-12288) for an admin token and System (S-1-16-16384) for a service account. The SID is matched,
// so a non-English Windows still counts. Any failure to ask counts as not elevated.
class Elevation
{
    public const REFRESH_SECONDS = 60;

    public static function isElevated(ProcessRunner $runner, string $whoami): bool
    {
        try {
            $r = $runner->run([$whoami, '/groups'], null, 10);
        } catch (Throwable $e) {
            return false;
        }
        return $r['code'] === 0 && !$r['timedOut']
            && preg_match('/(?<![\d-])S-1-16-(12288|16384)(?!\d)/', $r['stdout']) === 1;
    }

    // Page loads read a cached answer, so a page can start whoami at most once a minute however often
    // something requests it. $check runs only when the cache is missing, unreadable or older than that.
    public static function cached(string $cachePath, callable $check, ?callable $now = null): bool
    {
        $t = $now ? (int) $now() : time();
        $c = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($c) && is_int($c['at'] ?? null) && is_bool($c['elevated'] ?? null)
            && $t >= $c['at'] && $t - $c['at'] < self::REFRESH_SECONDS) {
            return $c['elevated'];
        }
        $elevated = $check() === true;
        @file_put_contents($cachePath, json_encode(['at' => $t, 'elevated' => $elevated]), LOCK_EX);
        return $elevated;
    }

    public static function fromConfig(string $cachePath): bool
    {
        return self::cached($cachePath, function (): bool {
            $exe = (require __DIR__ . '/../../config/executables.php')['whoami'] ?? '';
            return self::isElevated(ProcessRunner::fromConfig(), (string) $exe);
        });
    }
}
