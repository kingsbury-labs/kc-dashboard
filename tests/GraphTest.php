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

// Lessons (card 134): traps, fixes, asks and dream proposals on invented fixtures in tests/fixtures/graph/lessons.
const LS_FIX = GR_FIX . '/lessons';

function ls_projects(?string $root = null): array
{
    $out = [];
    foreach (glob(($root ?? LS_FIX . '/projects') . '/*', GLOB_ONLYDIR) as $d) {
        $out[basename($d)] = $d;
    }
    return $out;
}

function ls_store(): GraphStore
{
    return new GraphStore(Db::open(sys_get_temp_dir() . '/kcls_' . bin2hex(random_bytes(6)) . '.sqlite'));
}

// Every lesson source rebuilt on its own, from the fixture tree.
function ls_build(GraphStore $g, string $only = ''): void
{
    $p = ls_projects();
    $all = [
        'traps' => fn() => GraphReaders::traps((string) file_get_contents(LS_FIX . '/traps.md'), $p),
        'fixes' => fn() => GraphReaders::fixes(GraphReaders::cards($p), $p),
        'asks' => fn() => GraphReaders::asks((string) file_get_contents(LS_FIX . '/asks.md'), $p),
        'dreams' => fn() => GraphReaders::dreams(GraphReaders::proposals($p), $p),
    ];
    foreach ($all as $source => $edges) {
        if ($only === '' || $only === $source) {
            $g->replace($source, $edges());
        }
    }
}

// One source's edges as sorted "type:key -edge-> type:key" lines.
function ls_edges(GraphStore $g, string $source): array
{
    $q = $g->pdo()->prepare("SELECT s.type || ':' || s.key || ' -' || e.type || '-> ' || d.type || ':' || d.key FROM graph_edge e
        JOIN graph_node s ON s.id = e.src JOIN graph_node d ON d.id = e.dst WHERE e.source = ?");
    $q->execute([$source]);
    $rows = $q->fetchAll(PDO::FETCH_COLUMN);
    sort($rows);
    return $rows;
}

function ls_match(array $rows, string $needle): array
{
    return array_values(array_filter($rows, fn($r) => str_contains($r, $needle)));
}

test('a trap that names a file in a project tree links to that project, that file and its section as the cause', function () {
    $g = ls_store();
    ls_build($g);
    $rows = ls_match(ls_edges($g, 'traps'), 'the-css-minifier-changes-units');
    assert_same(3, count($rows), implode("\n", $rows));
    assert_true(str_contains(implode("\n", $rows), '-concerns-> project:henike-contracting'), 'project link');
    assert_true(str_contains(implode("\n", $rows), '-names-> file:henike-contracting:src/scripts/menu-morph.ts'), 'file link');
    assert_true(str_contains(implode("\n", $rows), '-caused_by-> cause:builds rewrite what you wrote'), 'cause link');
    $label = $g->pdo()->query("SELECT label FROM graph_node WHERE type = 'trap' AND label LIKE 'The CSS minifier%'")->fetchColumn();
    assert_same(120, strlen($label), 'the label is the first 120 characters');
    $other = implode("\n", ls_match(ls_edges($g, 'traps'), 'screenshots-freeze-animation'));
    assert_true(str_contains($other, '-concerns-> project:kc-portal') && !str_contains($other, 'names'), 'a trap naming kc-portal and no file');
});

test('brief-context returns the trap, fix and dream item for a file before the project-only ones', function () {
    $g = ls_store();
    ls_build($g);
    $got = array_map(fn($l) => $l['kind'] . ' ' . $l['match'], $g->lessons('henike-contracting', ['src/scripts/menu-morph.ts']));
    assert_same(['trap file', 'fix file', 'dream file', 'trap project', 'dream project'], $got);
    $first = $g->lessons('henike-contracting', ['src\\scripts\\menu-morph.ts'])[0];
    assert_true(str_starts_with($first['label'], 'The CSS minifier changes units.'), 'the file trap is first: ' . $first['label']);
    assert_same(['trap project', 'trap project', 'fix project', 'dream project', 'dream project'],
        array_map(fn($l) => $l['kind'] . ' ' . $l['match'], $g->lessons('henike-contracting', ['src/nothing.ts'])), 'an unlinked file adds no match');
    assert_same([], $g->lessons('no-such-project', ['src/scripts/menu-morph.ts']));
});

test('a fix from a result.md links to its project and the files it names, and not to another project', function () {
    $g = ls_store();
    ls_build($g);
    $rows = ls_edges($g, 'fixes');
    $h = implode("\n", ls_match($rows, 'henike-contracting/172-menu'));
    assert_true(str_contains($h, '-concerns-> project:henike-contracting'), $h);
    assert_true(str_contains($h, '-names-> file:henike-contracting:src/scripts/menu-morph.ts'), $h);
    $k = implode("\n", ls_match($rows, 'kc-portal/9-login'));
    assert_true(str_contains($k, '-names-> file:kc-portal:src/login.ts') && !str_contains($k, 'henike'), $k);
    $label = $g->pdo()->query("SELECT label FROM graph_node WHERE type = 'fix' AND label LIKE 'henike-contracting/172-menu%'")->fetchColumn();
    assert_same('henike-contracting/172-menu: Files: `src/scripts/menu-morph.ts` now reads times through cssTime. Mutation caught.', $label);
});

test('an ask links to its project by folder, by client word or by a path, and "all" links to none', function () {
    $g = ls_store();
    ls_build($g);
    $rows = ls_edges($g, 'asks');
    $h = implode("\n", ls_match($rows, 'homepage-fly-in'));
    assert_true(str_contains($h, '-concerns-> project:henike-contracting'), 'henike is the start of henike-contracting: ' . $h);
    assert_true(str_contains($h, '-names-> file:henike-contracting:src/scripts/menu-morph.ts'), $h);
    assert_true(str_contains(implode("\n", ls_match($rows, 'whc-deploy')), '-concerns-> project:kc-portal'), 'kc-portal by folder');
    assert_same([], ls_match($rows, 'one-pm-over-every-session'), 'an ask for "all" has no links, so no node');
});

test('each dream item is a node linked to its project, and a proposal under dream/logs gives none', function () {
    $g = ls_store();
    ls_build($g);
    $rows = ls_edges($g, 'dreams');
    assert_same(['dream:henike-contracting/proposal-20261102-000001.md/first-item -concerns-> project:henike-contracting',
        'dream:henike-contracting/proposal-20261102-000001.md/first-item -names-> file:henike-contracting:src/scripts/menu-morph.ts',
        'dream:henike-contracting/proposal-20261102-000001.md/second-item -concerns-> project:henike-contracting'], $rows);
    $labels = $g->pdo()->query("SELECT label FROM graph_node WHERE type = 'dream' ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['henike-contracting: first-item', 'henike-contracting: second-item'], $labels);
    assert_same([], ls_match($rows, 'logged-item'));
});

test('rebuilding traps leaves the other sources alone, and the same input twice gives the same edges', function () {
    $g = ls_store();
    ls_build($g);
    $before = ['fixes' => ls_edges($g, 'fixes'), 'asks' => ls_edges($g, 'asks'), 'dreams' => ls_edges($g, 'dreams'), 'traps' => ls_edges($g, 'traps')];
    assert_true(count($before['fixes']) > 0 && count($before['traps']) > 0, 'something to compare');
    ls_build($g, 'traps');
    assert_same($before, ['fixes' => ls_edges($g, 'fixes'), 'asks' => ls_edges($g, 'asks'), 'dreams' => ls_edges($g, 'dreams'), 'traps' => ls_edges($g, 'traps')]);
    $g->replace('traps', []);
    assert_same([], ls_edges($g, 'traps'), 'traps emptied');
    assert_same($before['fixes'], ls_edges($g, 'fixes'), 'fixes untouched');
    assert_same($before['dreams'], ls_edges($g, 'dreams'), 'dreams untouched');
    $p = ls_projects();
    $a = GraphReaders::traps((string) file_get_contents(LS_FIX . '/traps.md'), $p);
    assert_same($a, GraphReaders::traps((string) file_get_contents(LS_FIX . '/traps.md'), $p), 'same input, same edges');
});

test('a path in a secret place is never linked, only a plain file in the project is', function () {
    $t = sys_get_temp_dir() . '/kcls_' . bin2hex(random_bytes(6));
    mkdir($t . '/shop/src', 0777, true);
    mkdir($t . '/shop/.credentials', 0777, true);
    foreach (['src/app.ts', '.env', '.credentials/token.md', 'src/settings.json', 'src/auth-token.ts'] as $f) {
        file_put_contents($t . '/shop/' . $f, 'x');
    }
    $text = "## Leaks\n\n- **Keys.** In shop: see `src/app.ts`, `.env`, `.credentials/token.md`, `src/settings.json`, src/auth-token.ts and ../shop/src/app.ts.\n";
    $edges = GraphReaders::traps($text, ls_projects($t));
    assert_same(3, count($edges), 'project, one file, cause: ' . json_encode($edges));
    assert_same(['file', 'shop:src/app.ts'], [$edges[1][4], $edges[1][5]], json_encode($edges));
});

test('the Graph collector rebuilds the four lesson sources from its config paths', function () {
    [$t, $log, $g] = gr_world();
    $src = ['projects' => ls_projects(), 'vendors' => [], 'clients' => GR_FIX . '/clients', 'care' => GR_FIX . '/care.json',
        'senders' => $t . '/mail-senders.json', 'traps' => LS_FIX . '/traps.md', 'asks' => LS_FIX . '/asks.md'];
    (new Graph($g, $src, null, '', $t . '/app-password', $log))->collect(GR_NOW);
    foreach (['traps', 'fixes', 'asks', 'dreams'] as $source) {
        assert_true(count(ls_edges($g, $source)) > 0, "$source has edges");
    }
    $cfg = Graph::sources($t);
    assert_true(str_ends_with($cfg['traps'], 'traps.md') && str_ends_with($cfg['asks'], 'asks.md'), 'sources() names both files');
});

test('bin/brief-context.php prints one plain line per lesson, file matches first', function () {
    $g = ls_store();
    ls_build($g);
    $db = $g->pdo()->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
    $run = function (array $args): array {
        $proc = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/brief-context.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert_true(is_resource($proc), 'could not start php');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    };
    [$code, $out, $err] = $run(['--db=' . $db, '--project', 'henike-contracting', '--files', 'src/scripts/menu-morph.ts,src/none.ts']);
    assert_same(0, $code, $err);
    $lines = explode("\n", trim($out));
    assert_same(5, count($lines), $out);
    assert_true(str_starts_with($lines[0], 'trap [file] The CSS minifier changes units.'), $lines[0]);
    assert_true(str_starts_with($lines[1], 'fix [file] henike-contracting/172-menu: '), $lines[1]);
    assert_same('dream [file] henike-contracting: first-item', $lines[2]);
    assert_true(str_starts_with($lines[3], 'trap [project] '), $lines[3]);
    assert_same('dream [project] henike-contracting: second-item', $lines[4]);
    [$code, $out] = $run(['--db=' . $db, '--project=henike-contracting', '--limit=1']);
    assert_same(3, count(explode("\n", trim($out))), 'one of each kind with --limit=1: ' . $out);
    [$code] = $run(['--db=' . $db]);
    assert_same(2, $code, 'project is required');
});
