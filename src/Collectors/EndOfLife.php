<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Net/HttpClient.php';

// Support end dates from endoflife.date for the products and versions in config. A fact when the end date is
// within 90 days or past. A version the API does not list is an error, so a stale config shows.
class EndOfLife extends Collector
{
    public const WINDOW_DAYS = 90;

    private HttpClient $http;
    /** @var array<int,array{product:string,cycle:string,label?:string}> */
    private array $products;

    public function __construct(HttpClient $http, array $products)
    {
        $this->http = $http;
        $this->products = $products;
    }

    public function name(): string
    {
        return 'eol';
    }

    public function collect(int $now): array
    {
        $facts = [];
        foreach ($this->products as $p) {
            $product = $p['product'] ?? '';
            $cycle = (string) ($p['cycle'] ?? '');
            if (!is_string($product) || preg_match('/^[a-z0-9._-]{1,64}$/', $product) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $cycle) !== 1) {
                throw new InvalidArgumentException('eol product or version is not plain text');
            }
            $res = $this->http->get('https://endoflife.date/api/' . $product . '.json');
            $list = $res['status'] === 200 ? json_decode($res['body'], true) : null;
            if (!is_array($list)) {
                throw new RuntimeException("endoflife.date gave no usable list for $product (status {$res['status']})");
            }
            $row = null;
            foreach ($list as $r) {
                if (is_array($r) && (string) ($r['cycle'] ?? '') === $cycle) {
                    $row = $r;
                    break;
                }
            }
            if ($row === null) {
                throw new RuntimeException("endoflife.date has no $product $cycle");
            }
            $eol = $row['eol'] ?? false;
            if (!is_string($eol) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $eol, $d) !== 1 || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
                continue;
            }
            $days = self::daysUntil($eol, $now);
            if ($days > self::WINDOW_DAYS) {
                continue;
            }
            $label = is_string($p['label'] ?? null) && $p['label'] !== '' ? $p['label'] : "$product $cycle";
            $severity = $days < 0 ? 'high' : ($days <= 30 ? 'med' : 'low');
            $facts[] = [
                'id' => "eol:$product:$cycle",
                'source' => 'eol',
                'kind' => 'end_of_life',
                'subject' => $days < 0 ? "$label is past end of support" : "$label support ends",
                'salient_hash' => sha1(implode('|', [$product, $cycle, $eol, $severity])),
                'due_date' => $eol,
                'severity' => $severity,
                'evidence' => "endoflife.date lists $eol",
                'url' => 'https://endoflife.date/' . $product,
                'parser_version' => 'eol/1',
            ];
        }
        return $facts;
    }
}
