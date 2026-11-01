<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Http/Token.php';
require_once __DIR__ . '/../Http/RequestGuard.php';
require_once __DIR__ . '/../Http/Response.php';

// GET /cockpit.php: the v4 cockpit shell. A page like the wall, so checkRead, then the token goes into a <meta> the
// scripts read. Everything else is drawn by public/cockpit/js from api/cockpit.php and the wall stream; until the
// first snapshot lands the panels hold their shape as skeletons. No source is read here and nothing is started.
class CockpitPage
{
    // Card 110: the only thing this page may connect to beyond itself is the local cas-voice socket.
    public const VOICE_CSP = "connect-src 'self' ws://127.0.0.1:8766";

    // The cockpit's headers: the shared ones with VOICE_CSP appended to the policy, nothing else changed.
    public static function headers(): array
    {
        $h = ['Content-Type' => 'text/html; charset=utf-8'] + Response::SECURITY_HEADERS;
        $h['Content-Security-Policy'] .= '; ' . self::VOICE_CSP;
        return $h;
    }

    // cas-voice's token, read only, never created here. Missing or malformed means the engine is off: ''.
    public static function voiceToken(string $path, Log $log): string
    {
        $v = $path === '' ? '' : trim((string) @file_get_contents($path));
        if (!preg_match('/^[0-9a-f]{64}$/', $v)) {
            return '';
        }
        $log->addSecret($v);
        return $v;
    }

    public static function handle(array $server, string $tokenPath, Log $log, ?string $voiceTokenPath = null): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $t = htmlspecialchars(Token::load($tokenPath, $log), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $voiceTokenPath ??= (string) ((require __DIR__ . '/../../config/running.php')['voice_token'] ?? '');
            $v = self::voiceToken($voiceTokenPath, $log);
            $voiceMeta = $v === '' ? '' : "\n" . '<meta name="kc-voice-token" content="' . $v . '">';
            $voiceOff = $v === '' ? '<p id="voice-off" class="voice-off" hidden>Voice engine is off</p>' : '';
            [, , $body] = Response::html(200, <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0c0e">
<meta name="kc-token" content="$t">$voiceMeta
<title>KC Cockpit</title>
<link rel="preload" href="/cockpit/fonts/manrope-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/cockpit/fonts/dm-sans-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/cockpit/cockpit.css">
<script type="module" src="/cockpit/js/main.js"></script>
</head>
<body class="cockpit"><div id="app" class="app"></div><div id="tip" class="tooltip"></div>$voiceOff
<noscript><p class="noscript">The cockpit needs JavaScript. The <a href="/wall.php">wall</a> works without it.</p></noscript>
</body>
</html>
HTML);
            return [200, self::headers(), $body];
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }
}
