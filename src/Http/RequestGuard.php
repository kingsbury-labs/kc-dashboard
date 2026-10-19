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
            && is_string($host) && in_array($host, self::HOSTS, true)
            && self::fetchAllowed($server);
    }

    // A browser marks where a request came from. Another site (or another port on this machine, which is
    // same-site) may only navigate here, so an <img> or fetch from any other page reaches nothing.
    // No header at all is a non-browser client such as curl, which the address and Host checks cover.
    private static function fetchAllowed(array $server): bool
    {
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site === null || $site === 'same-origin' || $site === 'none') {
            return true;
        }
        return ($server['HTTP_SEC_FETCH_MODE'] ?? null) === 'navigate';
    }

    // A read that starts processes. checkRead lets another site navigate here, and a navigation (an iframe on a
    // timer, an auto-submitted GET form) would start them, so this also needs the token header, and a browser
    // request must be same-origin and not a navigation. No Sec-Fetch headers at all is curl; the token covers it.
    public function checkApiRead(array $server): bool
    {
        return $this->checkFetchSite($server) && $this->tokenSent($server);
    }

    // checkApiRead without the token: the address and Host, and a browser request must be a same-origin fetch,
    // not a navigation. Only for a caller that proves itself another way (the wall stream's one-use ticket).
    public function checkFetchSite(array $server): bool
    {
        if (!$this->checkRead($server)) {
            return false;
        }
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        $mode = $server['HTTP_SEC_FETCH_MODE'] ?? null;
        return ($site === null && $mode === null) || ($site === 'same-origin' && $mode !== null && $mode !== 'navigate');
    }

    private function tokenSent(array $server): bool
    {
        $sent = $server[self::TOKEN_HEADER] ?? null;
        return is_string($sent) && $this->token !== '' && hash_equals($this->token, $sent);
    }

    public function checkWrite(array $server): bool
    {
        if (!$this->checkRead($server)) {
            return false;
        }
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') {
            return false;
        }
        if (!$this->tokenSent($server)) {
            return false;
        }
        return ($server['HTTP_ORIGIN'] ?? null) === 'http://' . $server['HTTP_HOST'];
    }
}
