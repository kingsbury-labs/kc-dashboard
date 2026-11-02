<?php
// Uses cy_world and cy_snapshot from CockpitDayTest, which the runner loads first (files run in name order).
require_once __DIR__ . '/../src/Collectors/Graph.php';
require_once __DIR__ . '/../src/Collectors/Mail.php';
require_once __DIR__ . '/../src/Graph/Classifier.php';

const GR_FIX = __DIR__ . '/fixtures/graph';
const GR_NOW = 1793534400; // 2026-11-01 12:00 UTC
const GR_VENDORS = ['WHC' => ['hosting.example.com']];

// A scripted Gmail with an inbox and a sent folder, recording every command (tag stripped).
function gr_wire(array &$sent): ImapWire
{
    $fix = json_decode((string) file_get_contents(GR_FIX . '/imap.json'), true);
    return new class ($fix, $sent) implements ImapWire {
        private string $out;
        private string $box = 'inbox';
        public function __construct(private array $fix, private array &$sent)
        {
            $this->out = $fix['greeting'] . "\r\n";
        }
        public function send(string $line): void
        {
            [$tag, $cmd] = explode(' ', rtrim($line, "\r\n"), 2);
            $this->sent[] = $cmd;
            $by = array_column($this->fix[$this->box], null, 'uid');
            if (str_starts_with($cmd, 'LOGIN ')) {
                $this->out .= "$tag OK fixture authenticated\r\n";
            } elseif (str_starts_with($cmd, 'EXAMINE ')) {
                $this->box = str_contains($cmd, 'Sent') ? 'sent' : 'inbox';
                $this->out .= "* FLAGS (\\Seen)\r\n$tag OK [READ-ONLY] opened\r\n";
            } elseif (str_starts_with($cmd, 'UID SEARCH ')) {
                $this->out .= '* SEARCH ' . implode(' ', array_keys($by)) . "\r\n$tag OK done\r\n";
            } elseif (preg_match('/^UID FETCH ([\d,]+) /', $cmd, $m)) {
                foreach (explode(',', $m[1]) as $seq => $uid) {
                    $msg = $by[(int) $uid];
                    $this->out .= isset($msg['body'])
                        ? '* ' . ($seq + 1) . " FETCH (UID $uid ENVELOPE {$msg['envelope']} BODY[1]<0> {" . strlen($msg['body']) . "}\r\n{$msg['body']})\r\n"
                        : '* ' . ($seq + 1) . " FETCH (UID $uid ENVELOPE {$msg['envelope']})\r\n";
                }
                $this->out .= "$tag OK done\r\n";
            } elseif ($cmd === 'LOGOUT') {
                $this->out .= "* BYE\r\n$tag OK bye\r\n";
            } else {
                $this->out .= "$tag BAD unknown\r\n";
            }
        }
        public function line(): ?string
        {
            $at = strpos($this->out, "\r\n");
            if ($at === false) {
                return null;
            }
            $l = substr($this->out, 0, $at + 2);
            $this->out = substr($this->out, $at + 2);
            return $l;
        }
        public function bytes(int $n): string
        {
            $b = substr($this->out, 0, $n);
            $this->out = substr($this->out, $n);
            return $b;
        }
        public function close(): void
        {
        }
    };
}

// The fixture world in a temp folder, plus what a repo must never hand the graph: .env, .credentials, a git remote.
function gr_world(): array
{
    [$t, , $log] = cy_world();
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
            }
        }
    };
    $copy(GR_FIX . '/projects', $t . '/projects');
    $acme = $t . '/projects/acme-site';
    file_put_contents($acme . '/.env', "DB_HOST=leakedsentinel.example.com\nDB_PASSWORD=leakedpw99\n");
    mkdir($acme . '/.credentials');
    file_put_contents($acme . '/.credentials/token.md', "token at credsentinel.example.com\n");
    mkdir($acme . '/.git');
    file_put_contents($acme . '/.git/config', "[remote \"origin\"]\n\turl = https://github.com/example/acme-site.git\n");
    $g = new GraphStore(Db::open($t . '/dashboard.sqlite'));
    return [$t, $log, $g];
}

function gr_build(string $t, Log $log, GraphStore $g, array &$sent): void
{
    $src = ['projects' => [], 'vendors' => GR_VENDORS, 'clients' => GR_FIX . '/clients', 'care' => GR_FIX . '/care.json',
        'senders' => $t . '/mail-senders.json'];
    foreach (glob($t . '/projects/*', GLOB_ONLYDIR) as $d) {
        $src['projects'][basename($d)] = $d;
    }
    (new Graph($g, $src, function () use (&$sent): ImapWire {
        return gr_wire($sent);
    }, 'rob@example.org', $t . '/app-password', $log))->collect(GR_NOW);
}

// The stubbed model: quokka mail is kc-portal and sure, marmalade mail a weak kc-portal guess.
function gr_classify(string $t, Log $log, GraphStore $g, array &$asked, array &$sent): void
{
    $ask = function (string $prompt) use (&$asked): string {
        $asked[] = $prompt;
        if (str_contains($prompt, 'quokka')) {
            return '{"project": "kc-portal", "confidence": 0.9, "reason": "staging login for the portal"}';
        }
        return '{"project": "kc-portal", "confidence": 0.3, "reason": "maybe"}';
    };
    $c = new Classifier($g, $t . '/mail-senders.json', $ask);
    @unlink($t . '/mail-cache.json');
    (new Mail(function () use (&$sent): ImapWire {
        return gr_wire($sent);
    }, 'rob@example.org', $t . '/app-password', fn(array $m): ?array => ['summary' => 'About: ' . $m['subject'], 'reply' => false],
        $t . '/mail-cache.json', $log, fn(array $m): array => $c->tag($m)))->collect(GR_NOW);
}

function gr_rows(string $t): array
{
    return array_column(json_decode((string) file_get_contents($t . '/mail-cache.json'), true)['rows'], null, 'uid');
}

test('the graph links a contact to a client and its project, and her mail is tagged by the walk with no model call', function () {
    [$t, $log, $g] = gr_world();
    $sent = [];
    $asked = [];
    gr_build($t, $log, $g, $sent);
    gr_classify($t, $log, $g, $asked, $sent);
    $r = gr_rows($t);
    assert_same('acme-site', $r[301]['project']);
    assert_same('Ann Acme works for acme, which owns acme-site', $r[301]['reason']);
    assert_true(!str_contains(implode("\n", $asked), 'zebrafinch'), 'the walked message reached the model');
    assert_same('WHC, several projects', $r[304]['project'], 'a shared vendor is not a guess');
    assert_true(!str_contains(implode("\n", $asked), 'hosting invoice'), 'the vendor message reached the model');
    $repo = $g->pdo()->query("SELECT COUNT(*) FROM graph_node WHERE type = 'repo' AND key = 'github.com/example/acme-site'")->fetchColumn();
    assert_same(1, (int) $repo, 'git remote becomes a repo node');
});

test('a message with no path goes to the model with the project neighbourhood and a weak answer lands in Unsure', function () {
    [$t, $log, $g] = gr_world();
    $sent = [];
    $asked = [];
    gr_build($t, $log, $g, $sent);
    gr_classify($t, $log, $g, $asked, $sent);
    assert_same(2, count($asked), 'the model is asked only about the two senders with no path');
    $quokka = array_values(array_filter($asked, fn($p) => str_contains($p, 'quokka')))[0];
    assert_true(str_contains($quokka, '- kc-portal; people: bob@example.net; vendors: WHC; recent threads: kc-portal staging access'), $quokka);
    assert_true(str_contains($quokka, '- acme-site; client: acme'), 'acme-site neighbourhood');
    assert_true(!str_contains($quokka, 'Lunch Friday'), 'a sent subject naming no project is not a thread');
    $r = gr_rows($t);
    assert_same('kc-portal', $r[302]['project']);
    assert_same(null, $r[303]['project']);
    assert_same(true, $r[303]['unsure']);
    [, $d] = cy_snapshot($t, $log);
    assert_same(['Deals'], array_column($d['mail']['unsure'], 'from'), 'the Unsure pile');
    $m = $g->pdo()->query("SELECT weight, confirmed FROM graph_edge WHERE source = 'model'")->fetchAll(PDO::FETCH_ASSOC);
    assert_same(1, count($m), 'the sure answer is kept as one edge');
    assert_true((float) $m[0]['weight'] < GraphStore::MIN_WEIGHT && (int) $m[0]['confirmed'] === 0, 'unconfirmed and low weight');
});

test('a correction becomes a confirmed edge that wins over the walk on the next run', function () {
    [$t, $log, $g] = gr_world();
    $sent = [];
    $asked = [];
    gr_build($t, $log, $g, $sent);
    Relevance::correct($t . '/mail-senders.json', 'Ann@Example.com', 'kc-portal');
    Relevance::correct($t . '/mail-senders.json', 'deals@example.net', null);
    gr_build($t, $log, $g, $sent);
    gr_classify($t, $log, $g, $asked, $sent);
    $r = gr_rows($t);
    assert_same('kc-portal', $r[301]['project']);
    assert_same('Ann Acme was tagged by Rob as kc-portal', $r[301]['reason']);
    assert_same(null, $r[303]['project']);
    assert_same(false, $r[303]['unsure'], 'not mine is not unsure');
    assert_same(1, count($asked), 'only the quokka sender still needs the model');
});

test('the live Classifier tags a client contact by exact address before the walk, and not mine still wins', function () {
    [$t, , $g] = gr_world();
    // Other Co shares the domain and comes first, so only the exact address picks Jo.
    $c = new Classifier($g, $t . '/mail-senders.json', null, [['name' => 'Other Co', 'email' => 'someone@example.net'],
        ['name' => "Jo's Garden", 'email' => 'jo.client@example.net']]);
    assert_same("Jo's Garden", $c->tag(['addr' => 'Jo.Client@example.net'])['project']);
    Relevance::correct($t . '/mail-senders.json', 'jo.client@example.net', null);
    assert_same(null, $c->tag(['addr' => 'jo.client@example.net'])['project']);
});

test('no .env value, credential file or mail body reaches the graph or the API, and IMAP stays read-only', function () {
    [$t, $log, $g] = gr_world();
    $sent = [];
    $asked = [];
    gr_build($t, $log, $g, $sent);
    gr_classify($t, $log, $g, $asked, $sent);
    // The mail collector reads Sent Mail envelopes for its own thread claim (card 133); only the graph's rerun counts.
    $rerun = count($sent);
    gr_build($t, $log, $g, $sent);
    $dump = json_encode($g->pdo()->query('SELECT type, key, label FROM graph_node')->fetchAll(PDO::FETCH_ASSOC));
    [$body] = cy_snapshot($t, $log);
    foreach (['leakedsentinel', 'leakedpw99', 'walrusvalue77', 'credsentinel', 'zebrafinch', 'quokka', 'marmalade'] as $word) {
        assert_true(!str_contains($dump, $word), "\"$word\" reached the graph");
        assert_true(!str_contains($body, $word), "\"$word\" reached the API");
    }
    assert_true(str_contains($dump, 'staging.acme-site.example.com'), 'hostnames from .env.example and docs are kept');
    foreach ($sent as $cmd) {
        assert_true(ImapReader::allowed($cmd), 'refused command was sent: ' . substr($cmd, 0, 40));
    }
    assert_true(in_array('EXAMINE "[Gmail]/Sent Mail"', $sent, true), 'sent mail is examined');
    assert_true(in_array('UID FETCH 51,52 (UID ENVELOPE)', $sent, true), 'sent mail is envelopes only');
    assert_true(in_array('EXAMINE "[Gmail]/Sent Mail"', array_slice($sent, $rerun), true), 'the rerun opens sent mail');
    assert_true(!in_array('UID FETCH 51,52 (UID ENVELOPE)', array_slice($sent, $rerun), true),
        'the second run reads no sent mail it has seen');
    foreach (['SELECT INBOX', 'STORE 1 +FLAGS (\\Seen)', 'UID FETCH 1 (BODY[1])', 'EXAMINE "[Gmail]/Trash"', 'UID FETCH 1 (UID ENVELOPE) STORE'] as $bad) {
        assert_true(!ImapReader::allowed($bad), "allowed: $bad");
    }
});
