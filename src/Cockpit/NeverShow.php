<?php
require_once __DIR__ . '/../Log.php';

// What the cockpit never stores or returns (docs/AGENTOS.md 4.5): anything under .credentials, .env files, *.key,
// and the paths ~/.claude/hooks/credential-read-blocker.cjs refuses. One list, used by the indexer before a row is
// written and by the API before a row leaves. File contents never get this far: the indexer keeps paths only.
class NeverShow
{
    public const MAX_TEXT = 300;

    // The credential-read-blocker's PROTECTED list, as paths (2026-11-01).
    private const PATHS = [
        '~(^|[\\\\/.\\s"\'=])\\.credentials([\\\\/]|\\b)~i',
        '~credentials\\.(md|json|ya?ml)\\b~i',
        '~(^|[\\\\/\\s"\'=<(])\\.env(?!\\.example|\\.sample|\\.dist)(\\.[a-z]+)?\\b~i',
        '~\\.(pem|p12|pfx|jks|keystore|kdbx)\\b~i',
        '~[\\\\/]\\.ssh[\\\\/]~i',
        '~(^|[\\\\/])\\.(htpasswd|pgpass|netrc)\\b~i',
        '~\\.key\\b(?!\\.pub)~i',
        '~(^|[\\\\/\\s"\'=])secrets\\.php\\b~i',
        '~[\\\\/]\\.claude[\\\\/]settings(\\.local)?\\.json\\b~i',
        '~[\\\\/]\\.mcp\\.json\\b~i',
        '~(^|[\\\\/\\s"\'=])\\.git-credentials\\b~i',
        '~[\\\\/]\\.config[\\\\/]gh[\\\\/]hosts\\.ya?ml\\b~i',
        '~(^|[\\\\/\\s"\'=])\\.npmrc\\b~i',
        '~[\\\\/]\\.docker[\\\\/]config\\.json\\b~i',
        '~[\\\\/]\\.aws([\\\\/]|\\b)~i',
        '~[\\\\/]\\.infisical([\\\\/]|\\b)~i',
    ];

    // Strings that look like a key or token wherever they appear in message text.
    private const SECRETS = [
        '~\\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}~',
        '~\\b(ghp|gho|ghs|ghu|github_pat)_[A-Za-z0-9_]{16,}~',
        '~\\bxox[abpr]-[A-Za-z0-9-]{10,}~',
        '~\\bAKIA[0-9A-Z]{16}\\b~',
        '~\\beyJ[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}~',
        '~\\b[0-9a-f]{40,}\\b~i',
        '~\\b(password|passwd|secret|token|api[_-]?key)\\s*[:=]\\s*\\S+~i',
    ];

    public static function blocked(string $path): bool
    {
        foreach (self::PATHS as $re) {
            if (preg_match($re, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    // Message text as the cockpit may show it: one line, the Log's own secrets redacted, anything that looks like a
    // key masked, any never-show path named in it masked, and at most 300 characters.
    public static function text(string $s, ?Log $log = null, int $max = self::MAX_TEXT): string
    {
        $s = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s) ?? '';
        $s = $log !== null ? $log->redact($s) : $s;
        $s = preg_replace(self::SECRETS, '[redacted]', $s) ?? '';
        $s = preg_replace_callback('~[^\s"\'`<>|]+~', fn(array $m): string => self::blocked($m[0]) ? '[hidden path]' : $m[0], $s) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }
}
