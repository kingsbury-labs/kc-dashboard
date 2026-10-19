<?php

// Every response is a [status, headers, body] triple; emit() is the only place that sends one.
class Response
{
    public const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'Content-Security-Policy' => "default-src 'self'; form-action 'self' https://www.google.com; frame-ancestors 'none'; "
            . "require-trusted-types-for 'script'; trusted-types 'none'; base-uri 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ];

    public static function html(int $status, string $body): array
    {
        return [$status, ['Content-Type' => 'text/html; charset=utf-8'] + self::SECURITY_HEADERS, $body];
    }

    public static function json(int $status, array $data): array
    {
        return [$status, ['Content-Type' => 'application/json'] + self::SECURITY_HEADERS,
            json_encode($data, JSON_UNESCAPED_SLASHES)];
    }

    // The throwable is deliberately unused: no message, no trace reaches the client.
    public static function error(Throwable $e): array
    {
        return self::json(500, ['error' => 'internal']);
    }

    public static function emit(array $triple): void
    {
        [$status, $headers, $body] = $triple;
        // Output already went out under other headers: a body appended now would land inside it.
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
    }
}
