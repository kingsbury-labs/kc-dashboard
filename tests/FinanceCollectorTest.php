<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Attention/FactStore.php';
require_once __DIR__ . '/../src/Collectors/Finance.php';

// Two hours after the schema 1 fixture's generated_at.
define('FC_NOW', strtotime('2026-10-17T10:00:00Z'));
const FC_FIX = __DIR__ . '/fixtures/collectors';

function fc_run(string $file, array $aliases = [], ?string $db = null): array
{
    $pdo = Db::open($db ?? ':memory:');
    Db::migrate($pdo);
    $store = new FactStore($pdo);
    $result = Collector::run([new Finance(FC_FIX . '/' . $file, $aliases)], $store,
        new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcfinance_unused.log'), FC_NOW);
    return [$store, $result, $pdo];
}

function fc_source(FactStore $s): array
{
    foreach ($s->sources(FC_NOW, FactStore::MAX_AGES) as $row) {
        if ($row['name'] === 'finance') {
            return $row;
        }
    }
    throw new RuntimeException('no finance source row');
}

test('a schema 1 file gives its facts with finance: ids, field for field', function () {
    [$s, $r] = fc_run('alerts-schema1.json');
    assert_same(['finance' => 2], $r);
    $f = $s->get('finance:invoice:INV-0042:overdue-14');
    assert_same('invoice_overdue', $f['kind']);
    assert_same('Birch Lane Roofing Ltd', $f['subject']);
    assert_same('2026-10-03', $f['due_date']);
    assert_same('high', $f['severity']);
    assert_same(125000, (int) $f['amount_cents']);
    assert_same('INV-0042 is 14 days overdue', $f['evidence']);
    assert_same('http://127.0.0.1:8000/invoices/42', $f['url']);
    assert_same('finance', $f['source']);
    assert_same(null, $s->get('finance:todo:file-hst')['url'], 'a url outside the Bigcapital prefix is dropped');
});

test('an extra field (account_number) never reaches the database file', function () {
    $db = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcfc_' . bin2hex(random_bytes(6)) . '.sqlite';
    [, , $pdo] = fc_run('alerts-schema1.json', [], $db);
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo = null;
    $bytes = '';
    foreach ([$db, $db . '-wal'] as $file) {
        $bytes .= is_file($file) ? file_get_contents($file) : '';
    }
    assert_true(str_contains($bytes, 'Birch Lane Roofing Ltd'), 'the file holds the facts it should');
    assert_true(!str_contains($bytes, 'ACCT-CANARY-XQZ-7781'), 'account_number leaked');
    assert_true(!str_contains($bytes, 'CSVLINE-CANARY-XQZ'), 'raw_csv_line leaked');
    foreach ([$db, $db . '-wal', $db . '-shm'] as $file) {
        @unlink($file);
    }
});

test('schema 2, truncated JSON and a missing file each record an error and no fact', function () {
    foreach (['alerts-schema2.json', 'alerts-truncated.json', 'alerts-missing.json'] as $file) {
        [$s, $r] = fc_run($file);
        assert_true(is_string($r['finance']), "$file should be an error");
        $row = fc_source($s);
        assert_same('error', $row['status'], $file);
        assert_same(null, $row['last_ok_at'], $file);
        assert_same([], $s->visible(FC_NOW), $file);
    }
});

test('a generated_at 27h old marks the source stale; a 2h old one does not', function () {
    [$s] = fc_run('alerts-old.json');
    $row = fc_source($s);
    assert_same(strtotime('2026-10-16T07:00:00Z'), $row['last_ok_at'], 'last_ok_at is generated_at, not the run time');
    assert_true($row['stale'], 'stale');
    [$s] = fc_run('alerts-schema1.json');
    assert_true(!fc_source($s)['stale'], 'fresh');
});

test('an aliased customer name maps to its slug', function () {
    [$s] = fc_run('alerts-schema1.json', ['Birch Lane Roofing Ltd' => 'birch-lane']);
    assert_same('birch-lane', $s->get('finance:invoice:INV-0042:overdue-14')['subject']);
    assert_same('File the HST return', $s->get('finance:todo:file-hst')['subject'], 'an unlisted name is unchanged');
});

test('the salient hash moves with the due date and the severity', function () {
    $c = new Finance(FC_FIX . '/alerts-schema1.json');
    $h = $c->collect(FC_NOW)[0]['salient_hash'];
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcfc_' . bin2hex(random_bytes(6)) . '.json';
    foreach ([['due_date', '2026-10-04'], ['severity', 'med']] as [$field, $value]) {
        $data = json_decode(file_get_contents(FC_FIX . '/alerts-schema1.json'), true);
        $data['facts'][0][$field] = $value;
        file_put_contents($tmp, json_encode($data));
        assert_true((new Finance($tmp))->collect(FC_NOW)[0]['salient_hash'] !== $h, "$field is in the hash");
    }
    @unlink($tmp);
});
