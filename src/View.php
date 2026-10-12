<?php
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Http/Token.php';
require_once __DIR__ . '/Http/RequestGuard.php';
require_once __DIR__ . '/Http/Response.php';

class View
{
    // The guard runs before the token is read, so a refused request never touches it.
    public static function page(array $server, string $tokenPath, Log $log): array
    {
        try {
            if (!(new RequestGuard(''))->checkRead($server)) {
                return Response::json(403, ['error' => 'forbidden']);
            }
            $token = Token::load($tokenPath, $log);
            return Response::html(200, self::render($token));
        } catch (Throwable $e) {
            return Response::error($e);
        }
    }

    private static function render(string $token): string
    {
        $t = htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="kc-token" content="$t">
<title>KC Dashboard</title>
</head>
<body>
<form action="https://www.google.com/search" method="get">
<input type="search" name="q" autofocus>
</form>
</body>
</html>
HTML;
    }
}
