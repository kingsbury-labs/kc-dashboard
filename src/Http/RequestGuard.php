<?php

// Decides whether a request may read or write. Exact byte comparisons only;
// SERVER_NAME and forwarded headers are never consulted.
class RequestGuard
{
    public const TOKEN_HEADER = 'HTTP_X_KC_TOKEN';
    private const ADDRS = ['127.0.0.1', '::1'];
    private const HOSTS = ['127.0.0.1:8787', 'localhost:8787'];

    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function checkRead(array $server): bool
    {
        $addr = $server['REMOTE_ADDR'] ?? null;
        $host = $server['HTTP_HOST'] ?? null;
        return is_string($addr) && in_array($addr, self::ADDRS, true)
            && is_string($host) && in_array($host, self::HOSTS, true);
    }

    public function checkWrite(array $server): bool
    {
        if (!$this->checkRead($server)) {
            return false;
        }
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') {
            return false;
        }
        $sent = $server[self::TOKEN_HEADER] ?? null;
        if (!is_string($sent) || $this->token === '' || !hash_equals($this->token, $sent)) {
            return false;
        }
        return ($server['HTTP_ORIGIN'] ?? null) === 'http://' . $server['HTTP_HOST'];
    }
}
