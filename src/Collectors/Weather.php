<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Net/HttpClient.php';

// Open-Meteo's forecast for one place (no key), saved to a cache file the cockpit reads. Records no facts. Fetches at
// most every 15 minutes; a failed fetch throws and leaves the last good cache in place, so the page shows that
// reading with its age.
class Weather extends Collector
{
    public const EVERY = 900;
    public const MAX_BYTES = 262144;
    public const HOURS = 5;
    // WMO weather codes, as Open-Meteo documents them.
    public const CODES = [0 => 'Clear', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast', 45 => 'Fog', 48 => 'Fog',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle', 56 => 'Freezing drizzle', 57 => 'Freezing drizzle',
        61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain', 66 => 'Freezing rain', 67 => 'Freezing rain',
        71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow', 77 => 'Snow grains', 80 => 'Rain showers', 81 => 'Rain showers',
        82 => 'Heavy showers', 85 => 'Snow showers', 86 => 'Heavy snow showers', 95 => 'Thunderstorm', 96 => 'Thunderstorm', 99 => 'Thunderstorm'];

    private HttpClient $http;
    private string $cache;
    private float $lat;
    private float $lon;

    public function __construct(HttpClient $http, string $cache, float $lat, float $lon)
    {
        $this->http = $http;
        $this->cache = $cache;
        $this->lat = $lat;
        $this->lon = $lon;
    }

    public function name(): string
    {
        return 'weather';
    }

    public function lastOkAt(int $now): int
    {
        return self::cached($this->cache, $now)['at'] ?? $now;
    }

    public function url(): string
    {
        return 'https://api.open-meteo.com/v1/forecast?latitude=' . $this->lat . '&longitude=' . $this->lon
            . '&current=temperature_2m,weather_code&hourly=temperature_2m,weather_code'
            . '&daily=temperature_2m_max,temperature_2m_min&timezone=America%2FToronto&forecast_days=2';
    }

    public function collect(int $now): array
    {
        $old = self::cached($this->cache, $now);
        if ($old !== null && $old['age'] < self::EVERY) {
            return [];
        }
        $res = $this->http->get($this->url());
        if ($res['status'] !== 200 || strlen($res['body']) > self::MAX_BYTES) {
            throw new RuntimeException('weather answered ' . $res['status']);
        }
        $reading = self::parse((string) $res['body']) + ['at' => $now];
        $tmp = $this->cache . '.tmp';
        if (file_put_contents($tmp, json_encode($reading)) === false || !rename($tmp, $this->cache)) {
            throw new RuntimeException('weather cache could not be written');
        }
        return [];
    }

    // The saved reading with its age in seconds, or null when there is none.
    public static function cached(string $path, int $now): ?array
    {
        $data = is_file($path) ? json_decode((string) @file_get_contents($path, false, null, 0, self::MAX_BYTES), true) : null;
        if (!is_array($data) || !is_int($data['at'] ?? null)) {
            return null;
        }
        return $data + ['age' => max(0, $now - $data['at'])];
    }

    public static function parse(string $body): array
    {
        $d = json_decode($body, true, 16);
        $cur = $d['current'] ?? null;
        if (!is_array($cur) || !is_numeric($cur['temperature_2m'] ?? null) || !is_string($cur['time'] ?? null)) {
            throw new RuntimeException('weather has no current reading');
        }
        $num = fn($v) => is_numeric($v) ? round((float) $v, 1) : null;
        $hours = [];
        $h = (array) ($d['hourly'] ?? []);
        foreach ((array) ($h['time'] ?? []) as $i => $t) {
            if (is_string($t) && $t > $cur['time'] && count($hours) < self::HOURS) {
                $hours[] = ['time' => substr($t, 11, 5), 'temp' => $num($h['temperature_2m'][$i] ?? null),
                    'condition' => self::CODES[$h['weather_code'][$i] ?? -1] ?? ''];
            }
        }
        return [
            'temp' => $num($cur['temperature_2m']),
            'condition' => self::CODES[$cur['weather_code'] ?? -1] ?? '',
            'high' => $num($d['daily']['temperature_2m_max'][0] ?? null),
            'low' => $num($d['daily']['temperature_2m_min'][0] ?? null),
            'hours' => $hours,
        ];
    }
}
