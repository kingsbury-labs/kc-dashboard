<?php
require_once __DIR__ . '/../src/Collectors/Care.php';
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

// Uses CockpitTest.php's helpers (ck_world, ck_deps, ck_get). No network and no real process: UptimeRobot is a stub
// closure, gh a FakeProcessRunner, SSL a fixed fetcher.
const CC_FIX = __DIR__ . '/fixtures/cockpit-care';
const CC_GH = 'C:\\Program Files\\GitHub CLI\\gh.exe';

// Runs the collector into a temp cache; returns [cache path, log path, runner, posts seen].
function cc_collect(int $now, ?string $cache = null, ?Closure $post = null): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccc_' . bin2hex(random_bytes(6));
    mkdir($t);
    $cache ??= $t . '/care-cache.json';
    $logPath = $t . '/app.log';
    $log = new Log($logPath);
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, (string) file_get_contents(CC_FIX . '/issues.json'));
    $posts = new ArrayObject();
    $post ??= function (string $url, array $fields) use ($posts): string {
        $posts[] = [$url, $fields];
        return (string) file_get_contents(CC_FIX . '/getMonitors.json');
    };
    // kingsburycreative 90 days, brucemechanical 60, alderdental 10 (under 21, amber on SSL alone too).
    $ssl = fn(string $host): int => $now + ['kingsburycreative.com' => 90, 'brucemechanical.com' => 60, 'alderdental.ca' => 10][$host] * 86400;
    $care = new Care(CC_FIX . '/clients.json', CC_FIX . '/uptimerobot-key', $cache, $runner, CC_GH, $log, $post, $ssl);
    assert_same([], $care->collect($now));
    return [$cache, $logPath, $runner, $posts];
}

function cc_key(): string
{
    return trim((string) file_get_contents(CC_FIX . '/uptimerobot-key'));
}

test('the care block lists each client with status uptime response alerts and SSL days', function () {
    [$cache, , $runner, $posts] = cc_collect(CK_NOW);
    [, $db, $dir] = ck_world();
    $deps = ck_deps($db, $dir);
    $deps['care'] = $cache;
    [$s, $body] = ck_get($deps);
    $care = $s['care'];
    $by = array_column($care['clients'], null, 'key');
    assert_same(['bruce-mechanical', 'alder-dental', 'kingsburycreative'], array_column($care['clients'], 'key'), 'red, amber, green');
    $k = $by['kingsburycreative'];
    assert_same(['green', 'up', 312, 100, 99.95, 0, 90], [$k['status'], $k['monitor'], $k['response_ms'], $k['uptime_7'], $k['uptime_30'], $k['alert_count'], $k['ssl_days']]);
    $a = $by['alder-dental'];
    assert_same(['amber', 540, 100, 1, 10, 12], [$a['status'], $a['response_ms'], $a['uptime_30'], $a['alert_count'], $a['ssl_days'], $a['alerts'][0]['number']]);
    assert_same('https://api.uptimerobot.com/v2/getMonitors', $posts[0][0]);
    assert_same('7-30', $posts[0][1]['custom_uptime_ratios']);
    assert_true(!str_contains($body, cc_key()), 'API output carries the UptimeRobot key');
    assert_true(!str_contains((string) file_get_contents($cache), cc_key()), 'cache carries the key');
});

test('a down monitor makes that client red and raises one incident that keeps its id across runs', function () {
    [$cache] = cc_collect(CK_NOW);
    $care = CockpitCare::build($cache, CK_NOW);
    $b = array_column($care['clients'], null, 'key')['bruce-mechanical'];
    assert_same(['red', 'down', CK_NOW, 99.1], [$b['status'], $b['monitor'], $b['down_since'], $b['uptime_30']]);
    assert_same(1, count($care['incidents']));
    assert_same(['down:bruce-mechanical:' . CK_NOW, 'brucemechanical.com is down'], [$care['incidents'][0]['id'], $care['incidents'][0]['text']]);
    // Four minutes later, still down: the same incident, so the page does not notify again.
    cc_collect(CK_NOW + 240, $cache);
    $later = CockpitCare::build($cache, CK_NOW + 240);
    assert_same([$care['incidents'][0]['id']], array_column($later['incidents'], 'id'));
});

test('a critical alert makes a client red and an up client stays green with no incident', function () {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccc_' . bin2hex(random_bytes(6));
    mkdir($t);
    file_put_contents($t . '/c.json', json_encode(['at' => CK_NOW, 'clients' => [
        ['key' => 'a', 'domain' => 'a.com', 'monitor' => 'up', 'alerts' => [['number' => 3, 'title' => 'a.com SSL expired', 'critical' => true]], 'ssl_days' => 80],
        ['key' => 'b', 'domain' => 'b.com', 'monitor' => 'up', 'alerts' => [], 'ssl_days' => 80],
    ]]));
    $care = CockpitCare::build($t . '/c.json', CK_NOW);
    assert_same(['red', 'green'], array_column($care['clients'], 'status'));
    assert_same(['alert:a:3'], array_column($care['incidents'], 'id'));
    assert_same(null, CockpitCare::build($t . '/missing.json', CK_NOW));
});

test('the UptimeRobot key never reaches the log even when the request fails with it in the message', function () {
    [$cache, $logPath] = cc_collect(CK_NOW, null, function (string $url, array $fields): string {
        throw new RuntimeException('refused for key ' . $fields['api_key']);
    });
    $log = (string) file_get_contents($logPath);
    assert_true(str_contains($log, '[redacted]'), 'the failure was logged: ' . $log);
    assert_true(!str_contains($log, cc_key()), 'log carries the key');
    $b = array_column(CockpitCare::build($cache, CK_NOW)['clients'], null, 'key')['bruce-mechanical'];
    assert_same([null, 'green'], [$b['monitor'], $b['status']], 'no monitor data is not down');
});

test('the care collector runs only gh issue list against rob-kingsbury/kc-care', function () {
    [, , $runner] = cc_collect(CK_NOW);
    assert_same(1, count($runner->calls));
    assert_same([CC_GH, 'issue', 'list', '-R', 'rob-kingsbury/kc-care', '--state', 'open', '--json', 'number,title,createdAt,url,labels'],
        $runner->calls[0]['argv']);
    $other = [['issue', 'close', '12', '-R', 'rob-kingsbury/kc-care'], ['repo', 'delete', 'rob-kingsbury/kc-care'],
        ['issue', 'list', '-R', 'rob-kingsbury/other', '--state', 'open', '--json', 'number,title,createdAt,url,labels'],
        array_merge(Care::GH_ARGS, ['--web']), array_merge(['pr'], array_slice(Care::GH_ARGS, 1)),
        array_merge(['run'], array_slice(Care::GH_ARGS, 1))];
    foreach ($other as $args) {
        $fake = new FakeProcessRunner();
        try {
            Care::gh($fake, CC_GH, $args);
            throw new LogicException('accepted');
        } catch (ProcessRefused $e) {
        } catch (LogicException $e) {
            throw new RuntimeException('gh ' . implode(' ', $args) . ' was accepted');
        }
        assert_same([], $fake->calls, 'runner saw ' . implode(' ', $args));
    }
    $exes = require __DIR__ . '/../config/executables.php';
    assert_same(CC_GH, $exes['gh']);
});
