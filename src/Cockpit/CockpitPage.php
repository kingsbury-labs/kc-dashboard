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
    public static function handle(array $server, string $tokenPath, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $t = htmlspecialchars(Token::load($tokenPath, $log), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return Response::html(200, <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0c0e">
<meta name="kc-token" content="$t">
<title>KC Cockpit</title>
<link rel="preload" href="/cockpit/fonts/manrope-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/cockpit/fonts/dm-sans-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/cockpit/cockpit.css">
<script type="module" src="/cockpit/js/main.js"></script>
</head>
<body class="cockpit"><div id="app" class="app"></div><div id="tip" class="tooltip"></div>
<noscript><p class="noscript">The cockpit needs JavaScript. The <a href="/wall.php">wall</a> works without it.</p></noscript>
</body>
</html>
HTML);
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }
}
