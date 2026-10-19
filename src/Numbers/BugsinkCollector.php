<?php
require_once __DIR__ . '/GaCollector.php';

// Open issue counts from Bugsink, one source 'bugsink:<project>' per configured project, so one failing project
// does not hide the others. The token is read from the file at token_path at run time, registered with the log
// so no error line carries it, and sent only as a Bearer header; it is never stored.
// The endpoint is Bugsink's REST API, /api/canonical/0/issues/?project=<id>, read as a page of `results`.
class BugsinkCollector extends NumbersCollector
{
    private const PAGE_CAP = 10;

    /** @var Closure(string $url, string $token): array{status:int,body:string} */
    private Closure $fetch;

    /** @param array{base_url:string,token_path:string,projects:array<string,int>} $config */
    public function __construct(private array $config, private Log $log, ?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? Closure::fromCallable([self::class, 'curlGet']);
    }

    public function sources(): array
    {
        return array_map(fn($p) => 'bugsink:' . $p, array_keys($this->config['projects'] ?? []));
    }

    public function collect(): array
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if (!str_starts_with($base, 'https://')) {
            throw new RuntimeException('bugsink base_url must be https://');
        }
        $raw = @file_get_contents((string) ($this->config['token_path'] ?? ''), false, null, 0, 4096);
        $token = is_string($raw) ? trim($raw) : '';
        if ($token === '') {
            throw new RuntimeException('bugsink token file missing or empty');
        }
        $this->log->addSecret($token);
        $out = [];
        foreach ($this->config['projects'] ?? [] as $name => $id) {
            try {
                [$open, $partial] = $this->openIssues($base, (int) $id, $token);
                $out['bugsink:' . $name] = ['rows' => [['metric' => 'open_issues', 'value' => $open, 'label' => $partial ? 'partial' : null]]];
            } catch (Throwable $e) {
                $out['bugsink:' . $name] = ['error' => $this->log->redact($e->getMessage())];
            }
        }
        return $out;
    }

    /**
     * Follows `next` only while it stays on the same server, at most PAGE_CAP pages. Partial is true when the cap
     * stopped it with pages left, so the count is a floor: stored with the label 'partial' and shown as "N+".
     * @return array{0:int,1:bool} open count, partial
     */
    private function openIssues(string $base, int $project, #[\SensitiveParameter] string $token): array
    {
        $url = $base . '/api/canonical/0/issues/?project=' . $project;
        $open = 0;
        for ($page = 0; $url !== null && $page < self::PAGE_CAP; $page++) {
            $res = ($this->fetch)($url, $token);
            if ($res['status'] !== 200) {
                throw new RuntimeException('bugsink answered ' . $res['status']);
            }
            $data = json_decode($res['body'], true);
            if (!is_array($data) || !is_array($data['results'] ?? null)) {
                throw new RuntimeException('bugsink gave no readable issue list');
            }
            foreach ($data['results'] as $issue) {
                if (is_array($issue) && ($issue['is_resolved'] ?? false) !== true && ($issue['is_muted'] ?? false) !== true) {
                    $open++;
                }
            }
            $next = $data['next'] ?? null;
            $url = is_string($next) && str_starts_with($next, $base . '/') ? $next : null;
        }
        return [$open, $url !== null];
    }

    /** @return array{status:int,body:string} https only, timeouts and a size cap, as CurlHttpClient, plus the header */
    private static function curlGet(string $url, #[\SensitiveParameter] string $token): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('only https:// urls are fetched');
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            // No redirects: the token header would follow them to wherever they point.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => function ($c, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
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
            throw new RuntimeException('bugsink get failed: ' . ($err !== '' ? $err : 'unknown error'));
        }
        return ['status' => $status, 'body' => $body];
    }
}
