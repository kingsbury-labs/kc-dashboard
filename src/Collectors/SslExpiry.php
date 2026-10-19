<?php
require_once __DIR__ . '/Collector.php';

// Certificate expiry for the configured hosts. The fetcher takes a host and returns the certificate's validTo as a
// Unix time; the default one connects to port 443 and reads the peer certificate.
class SslExpiry extends Collector
{
    /** @var string[] */
    private array $hosts;
    /** @var callable */
    private $fetcher;

    public function __construct(array $hosts, ?callable $fetcher = null)
    {
        $this->hosts = $hosts;
        $this->fetcher = $fetcher ?? [self::class, 'fetchValidTo'];
    }

    public function name(): string
    {
        return 'ssl';
    }

    public function collect(int $now): array
    {
        $facts = [];
        foreach ($this->hosts as $host) {
            if (!is_string($host) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $host) !== 1) {
                throw new InvalidArgumentException('ssl host is not a plain host name');
            }
            $host = strtolower($host);
            $validTo = ($this->fetcher)($host);
            if (!is_int($validTo)) {
                throw new RuntimeException('no certificate expiry for ' . $host);
            }
            $due = date('Y-m-d', $validTo);
            $days = self::daysUntil($due, $now);
            if ($days > 30) {
                continue;
            }
            // Expired or a day out is high, a week out med, a month out low.
            $severity = $days <= 1 ? 'high' : ($days <= 7 ? 'med' : 'low');
            $facts[] = [
                'id' => 'ssl:' . $host,
                'source' => 'ssl',
                'kind' => 'cert_expiry',
                'subject' => $days < 0 ? "Certificate for $host has expired" : "Certificate for $host expires",
                'salient_hash' => sha1(implode('|', [$host, $due, $severity])),
                'due_date' => $due,
                'severity' => $severity,
                'evidence' => $days < 0 ? "expired $due" : "valid to $due, $days days left",
                'url' => 'https://' . $host,
                'parser_version' => 'ssl/1',
            ];
        }
        return $facts;
    }

    // Verification is off on purpose: an expired or mismatched certificate is what this reads, not a reason to refuse.
    public static function fetchValidTo(string $host): ?int
    {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host,
            'verify_peer' => false, 'verify_peer_name' => false]]);
        $s = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new RuntimeException('cannot connect to ' . $host . ': ' . $errstr);
        }
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert === null ? false : openssl_x509_parse($cert);
        return is_array($info) && isset($info['validTo_time_t']) ? (int) $info['validTo_time_t'] : null;
    }
}
