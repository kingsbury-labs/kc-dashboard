<?php
require_once __DIR__ . '/HttpClient.php';

// https:// only, with connect and total timeouts and a cap on the body. A redirect may only go to https too.
class CurlHttpClient implements HttpClient
{
    private int $connectTimeout;
    private int $timeout;
    private int $maxBytes;

    public function __construct(int $connectTimeout = 5, int $timeout = 15, int $maxBytes = 1048576)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->maxBytes = $maxBytes;
    }

    public function get(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || (string) ($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException('only https:// urls are fetched');
        }
        $body = '';
        $max = $this->maxBytes;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Returning less than the chunk's length aborts the transfer.
            CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$body, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException('http get failed: ' . ($err !== '' ? $err : 'unknown error'));
        }
        return ['status' => $status, 'body' => $body];
    }
}
