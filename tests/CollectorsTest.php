<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Attention/FactStore.php';
require_once __DIR__ . '/../src/Net/CurlHttpClient.php';
require_once __DIR__ . '/../src/Collectors/SslExpiry.php';
require_once __DIR__ . '/../src/Collectors/EndOfLife.php';
require_once __DIR__ . '/../src/Collectors/ClientDates.php';
require_once __DIR__ . '/Fakes/FakeHttpClient.php';

// 2026-10-17 noon, local time.
define('CO_NOW', strtotime('2026-10-17 12:00:00'));
const CO_FIX = __DIR__ . '/fixtures/collectors';

function co_store(): FactStore
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    return new FactStore($pdo);
}

function co_log(): Log
{
    return new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccollect_unused.log');
}

function co_ssl(int $validTo): array
{
    return (new SslExpiry(['mail.example.test'], fn(string $h) => $validTo))->collect(CO_NOW);
}

test('a cert 6 days from expiry gives one med fact', function () {
    $facts = co_ssl(strtotime('2026-10-23 12:00:00'));
    assert_same(1, count($facts));
    assert_same('med', $facts[0]['severity']);
    assert_same('ssl:mail.example.test', $facts[0]['id']);
    assert_same('2026-10-23', $facts[0]['due_date']);
});

test('an expired cert gives a high fact, and a cert 40 days out gives none', function () {
    $facts = co_ssl(strtotime('2026-10-10 12:00:00'));
    assert_same('high', $facts[0]['severity']);
    assert_same([], co_ssl(strtotime('2026-11-26 12:00:00')));
});

test('a host that is not a plain host name is refused before any fetch', function () {
    $called = false;
    $c = new SslExpiry(['example.test:443/../x'], function () use (&$called) {
        $called = true;
        return 0;
    });
    try {
        $c->collect(CO_NOW);
        assert_true(false, 'expected a refusal');
    } catch (InvalidArgumentException $e) {
        assert_true(!$called, 'fetcher must not run');
    }
});

function co_eol(string $cycle = '8.2'): EndOfLife
{
    $http = new FakeHttpClient();
    $http->willReturn('https://endoflife.date/api/php.json', file_get_contents(CO_FIX . '/endoflife-php.json'));
    return new EndOfLife($http, [['product' => 'php', 'cycle' => $cycle, 'label' => 'PHP ' . $cycle]]);
}

test('an endoflife fixture with PHP 8.2 ending 2026-12-31 gives a fact on 2026-10-17', function () {
    $facts = co_eol('8.2')->collect(CO_NOW);
    assert_same(1, count($facts));
    assert_same('eol:php:8.2', $facts[0]['id']);
    assert_same('2026-12-31', $facts[0]['due_date']);
    assert_same('low', $facts[0]['severity']);
});

test('a version ending 2028 gives none, and one already ended gives high', function () {
    assert_same([], co_eol('8.4')->collect(CO_NOW));
    assert_same('high', co_eol('7.4')->collect(CO_NOW)[0]['severity']);
});

test('a version the list does not have is an error, not silence', function () {
    try {
        co_eol('9.9')->collect(CO_NOW);
        assert_true(false, 'expected an error');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), '9.9'));
    }
});

test('a _DASHBOARD.md fixture row overdue gives a high fact; a far or open date gives none', function () {
    $facts = (new ClientDates(CO_FIX))->collect(CO_NOW);
    $by = [];
    foreach ($facts as $f) {
        $by[$f['id']] = $f;
    }
    assert_same('high', $by['clients:project:late-example']['severity']);
    assert_same('2026-10-10', $by['clients:project:late-example']['due_date']);
    assert_true(!isset($by['clients:project:later-example']) && !isset($by['clients:project:open-ended']));
    assert_same('high', $by['clients:followup:' . substr(sha1('Quarry Example Golf'), 0, 16)]['severity']);
    assert_same(2, count($facts));
});

test('collector facts record cleanly in FactStore', function () {
    $s = co_store();
    $r = Collector::run([new ClientDates(CO_FIX), co_eol()], $s, co_log(), CO_NOW);
    assert_same(['clients' => 2, 'eol' => 1], $r);
    assert_same('high', $s->get('clients:project:late-example')['severity']);
});

function co_throwing(string $name, string $message): Collector
{
    return new class($name, $message) extends Collector {
        public function __construct(private string $n, private string $m)
        {
        }

        public function name(): string
        {
            return $this->n;
        }

        public function collect(int $now): array
        {
            throw new RuntimeException($this->m);
        }
    };
}

test('a throwing collector records its error and the next still runs', function () {
    $s = co_store();
    $log = co_log();
    $log->addSecret('hunter2-canary');
    $r = Collector::run([co_throwing('broken', 'cannot reach it with hunter2-canary'), co_eol()], $s, $log, CO_NOW);
    assert_same(1, $r['eol']);
    assert_true(is_string($r['broken']));
    $rows = [];
    foreach ($s->sources(CO_NOW) as $row) {
        $rows[$row['name']] = $row;
    }
    assert_same('error', $rows['broken']['status']);
    assert_true(!str_contains((string) $rows['broken']['last_error'], 'hunter2-canary'), 'error is redacted');
    assert_true(!str_contains($r['broken'], 'hunter2-canary'), 'returned error is redacted');
    assert_same('ok', $rows['eol']['status']);
    assert_true($s->get('eol:php:8.2') !== null);
});

test('CurlHttpClient refuses http:// and file:// before any connection', function () {
    $c = new CurlHttpClient();
    foreach (['http://example.test/x', 'file:///c:/windows/win.ini', 'HTTP://example.test/', 'ftp://example.test/', '//example.test/x'] as $url) {
        try {
            $c->get($url);
            assert_true(false, "expected a refusal for $url");
        } catch (InvalidArgumentException $e) {
            assert_true(true);
        }
    }
});
