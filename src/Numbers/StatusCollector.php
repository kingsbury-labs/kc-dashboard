<?php
require_once __DIR__ . '/GaCollector.php';
require_once __DIR__ . '/../Net/HttpClient.php';

// Sembr's status endpoint, one source 'status'. Its exact shape is not pinned anywhere, so this reads the parts a
// status API usually has: a top-level status word ('status', 'overall' or 'indicator'), and in a top-level list of
// components, services, monitors or checks, how many report anything other than ok, up or operational.
class StatusCollector extends NumbersCollector
{
    private const OK = ['ok', 'up', 'operational', 'healthy', 'pass'];
    private const LISTS = ['components', 'services', 'monitors', 'checks'];

    public function __construct(private HttpClient $http, private string $url)
    {
    }

    public function sources(): array
    {
        return ['status'];
    }

    public function collect(): array
    {
        $res = $this->http->get($this->url);
        if ($res['status'] !== 200) {
            throw new RuntimeException('status endpoint answered ' . $res['status']);
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('status endpoint gave no readable JSON');
        }
        $word = null;
        foreach (['status', 'overall', 'indicator'] as $k) {
            $v = $data[$k] ?? null;
            if (is_array($v)) {
                $v = $v['status'] ?? $v['indicator'] ?? null;
            }
            if (is_string($v) || is_bool($v)) {
                $word = is_bool($v) ? ($v ? 'ok' : 'down') : $v;
                break;
            }
        }
        $total = 0;
        $down = 0;
        foreach (self::LISTS as $k) {
            foreach (is_array($data[$k] ?? null) ? $data[$k] : [] as $item) {
                $s = is_array($item) ? ($item['status'] ?? null) : $item;
                $total++;
                if (!is_string($s) || !in_array(strtolower($s), self::OK, true)) {
                    $down++;
                }
            }
        }
        return ['status' => ['rows' => [
            ['metric' => 'status', 'value' => null, 'label' => $word ?? 'unknown'],
            ['metric' => 'checks', 'value' => $total],
            ['metric' => 'down', 'value' => $down],
        ]]];
    }
}
