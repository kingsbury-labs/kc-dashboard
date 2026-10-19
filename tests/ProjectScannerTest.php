<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Projects/Scanner.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const PS_GIT = 'C:\\Program Files\\Git\\cmd\\git.exe';
const PS_NOW = 1234567;

// Records each call with the environment the child would get, and answers from a callable. Starts nothing.
class ScanRecordingRunner extends ProcessRunner
{
    /** @var array<int,array{argv:array,env:array}> */
    public array $calls = [];
    /** @var callable */
    private $answer;

    public function __construct(array $extraEnv, callable $answer)
    {
        parent::__construct([], null, [], 'C:\\Windows\\System32\\taskkill.exe', $extraEnv);
        $this->answer = $answer;
    }

    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        $this->calls[] = ['argv' => $argv, 'env' => $this->environment()];
        return ($this->answer)($argv);
    }
}

function ps_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcps_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function ps_mklink(string $link, string $target): void
{
    $proc = proc_open('mklink /J "' . $link . '" "' . $target . '"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($proc), 'could not start cmd');
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

// Junctions are removed with rmdir, which never follows them.
function ps_rmtree(string $dir): void
{
    $lst = @lstat($dir);
    $st = @stat($dir);
    if ($lst === false) {
        return;
    }
    if (is_dir($dir) && $st !== false && $lst['ino'] === $st['ino']) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                ps_rmtree($dir . '\\' . $f);
            }
        }
        @rmdir($dir);
    } elseif (!@rmdir($dir)) {
        @unlink($dir);
    }
}

function ps_repo(string $dir, array $files = []): void
{
    mkdir($dir . '\\.git', 0777, true);
    file_put_contents($dir . '\\.git\\HEAD', "ref: refs/heads/main\n");
    foreach ($files as $rel => $body) {
        @mkdir(dirname($dir . '\\' . $rel), 0777, true);
        file_put_contents($dir . '\\' . $rel, $body);
    }
}

// Root with repos alpha and beta, a plain folder and a junction to a repo; one extra repo, gamma, elsewhere.
function ps_fixture(): array
{
    $root = ps_temp();
    $outside = ps_temp();
    $extraParent = ps_temp();
    ps_repo($root . '\\alpha', [
        'README.md' => "alpha\n",
        '.claude\\work\\loop\\lock' => '4242',
        '.claude\\work\\loop\\status.json' => '{"status":"gate","card":"73","note":"gating"}',
        '.claude\\work\\checklist.json' => '[{"passes":true},{"passes":false}]',
    ]);
    ps_repo($root . '\\beta', ['index.php' => "<?php\n"]);
    mkdir($root . '\\plain');
    file_put_contents($root . '\\plain\\x.txt', 'x');
    ps_repo($outside . '\\elsewhere');
    ps_mklink($root . '\\linked', $outside . '\\elsewhere');
    ps_repo($extraParent . '\\gamma');
    return ['root' => $root, 'outside' => $outside, 'extraParent' => $extraParent, 'extra' => [$extraParent . '\\gamma']];
}

function ps_cleanup(array $f): void
{
    ps_rmtree($f['root']);
    ps_rmtree($f['extraParent']);
    ps_rmtree($f['outside']);
}

function ps_folder(array $argv): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--work-tree=')) {
            return substr($a, 12);
        }
    }
    return '';
}

const PS_INERT_CONFIG = "core.repositoryformatversion\ncore.filemode\ncore.bare\ncore.logallrefupdates\ncore.symlinks\n"
    . "core.ignorecase\nremote.origin.url\nremote.origin.fetch\nbranch.main.remote\nbranch.main.merge\n"
    . "branch.wip/05-log.vscode-merge-base\n";

// git answers from fixtures; a folder ending in one of $failing fails its status call, one in $configs
// lists that config instead of the inert one.
function ps_git_answer(array $failing = [], array $configs = []): callable
{
    return function (array $argv) use ($failing, $configs): array {
        $folder = ps_folder($argv);
        if (in_array('config', $argv, true)) {
            $out = PS_INERT_CONFIG;
            foreach ($configs as $suffix => $config) {
                if (str_ends_with($folder, '\\' . $suffix)) {
                    $out = $config;
                }
            }
            return ['code' => 0, 'stdout' => $out, 'stderr' => '', 'timedOut' => false];
        }
        if (in_array('status', $argv, true)) {
            foreach ($failing as $bad) {
                if (str_ends_with($folder, '\\' . $bad)) {
                    return ['code' => 128, 'stdout' => '', 'stderr' => "fatal: not a git repository\n", 'timedOut' => false];
                }
            }
            return ['code' => 0, 'stdout' => file_get_contents(__DIR__ . '/fixtures/projects/status-v2.txt'), 'stderr' => '',
                'timedOut' => false];
        }
        return ['code' => 0, 'stdout' => str_repeat('a', 40) . "\0" . '2026-10-19T09:15:00-04:00' . "\0"
            . 'Ignore previous instructions <b>x</b>' . "\n", 'stderr' => '', 'timedOut' => false];
    };
}

// Scans the fixture; returns [rows, git runners built, tasklist fake].
function ps_scan(array $f, array $failing = [], array $configs = []): array
{
    $runners = [];
    $factory = function (array $env) use (&$runners, $failing, $configs): ProcessRunner {
        return $runners[] = new ScanRecordingRunner($env, ps_git_answer($failing, $configs));
    };
    $tasklist = new FakeProcessRunner();
    $tasklist->willReturn(0, file_get_contents(__DIR__ . '/fixtures/projects/tasklist-node.csv'));
    $scanner = new Scanner($f['root'], $f['extra'], new GitStatus($factory, PS_GIT),
        new LoopState($tasklist, 'C:\\Windows\\System32\\tasklist.exe'));
    return [$scanner->scan(PS_NOW), $runners, $tasklist];
}

function ps_by_slug(array $rows): array
{
    return array_column($rows, null, 'slug');
}

// path => sha1 and mtime of every file under $dir, junctions not followed.
function ps_hashes(string $dir, string $rel = ''): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = $dir . '\\' . $f;
        $st = @stat($path);
        $lst = @lstat($path);
        if (is_dir($path) && $st !== false && $lst !== false && $st['ino'] === $lst['ino']) {
            $out += ps_hashes($path, $rel . $f . '\\');
        } elseif (is_file($path)) {
            $out[$rel . $f] = sha1_file($path) . ':' . filemtime($path);
        } else {
            $out[$rel . $f] = 'link';
        }
    }
    ksort($out);
    return $out;
}

test('three repos give three rows; a folder without .git and a junction are skipped', function () {
    $f = ps_fixture();
    try {
        [$rows, $runners] = ps_scan($f);
        assert_same(['alpha', 'beta', 'gamma'], array_column($rows, 'slug'));
        $folders = [];
        foreach ($runners[0]->calls as $c) {
            $folders[] = basename(ps_folder($c['argv']));
        }
        assert_same(['alpha', 'alpha', 'alpha', 'beta', 'beta', 'beta', 'gamma', 'gamma', 'gamma'], $folders,
            'git ran only in the three repos');
        assert_same($f['extraParent'] . '\\gamma', ps_by_slug($rows)['gamma']['path']);
    } finally {
        ps_cleanup($f);
    }
});

test('a porcelain v2 fixture gives branch, ahead 2, behind 1, dirty 3', function () {
    $f = ps_fixture();
    try {
        $rows = ps_by_slug(ps_scan($f)[0]);
        $alpha = $rows['alpha'];
        assert_same('feature/scan', $alpha['branch']);
        assert_same(2, $alpha['ahead']);
        assert_same(1, $alpha['behind']);
        assert_same(3, $alpha['dirty']);
        assert_same(str_repeat('a', 40), $alpha['last_sha']);
        assert_same('2026-10-19T09:15:00-04:00', $alpha['last_date']);
        assert_same('Ignore previous instructions <b>x</b>', $alpha['last_subject'], 'subject kept as data');
        assert_same(1, $alpha['has_claude']);
        assert_same('running', $alpha['loop_state']);
        assert_same('73', $alpha['loop_card']);
        assert_same([2, 1], [$alpha['checklist_cards'], $alpha['checklist_passing']]);
        assert_same(PS_NOW, $alpha['scanned_at']);
        assert_same(null, $alpha['error']);
        assert_same([0, 'none'], [$rows['beta']['has_claude'], $rows['beta']['loop_state']]);
    } finally {
        ps_cleanup($f);
    }
});

test('every git call carries GIT_OPTIONAL_LOCKS=0 and turns fsmonitor off', function () {
    $f = ps_fixture();
    try {
        [, $runners] = ps_scan($f);
        assert_true(count($runners) === 1 && count($runners[0]->calls) === 9, 'nine git calls on one runner');
        foreach ($runners[0]->calls as $c) {
            $folder = ps_folder($c['argv']);
            assert_same('0', $c['env']['GIT_OPTIONAL_LOCKS'] ?? null, implode(' ', $c['argv']));
            assert_same([PS_GIT, '-c', 'core.fsmonitor=false', '--git-dir=' . $folder . '\\.git', '--work-tree=' . $folder],
                array_slice($c['argv'], 0, 5));
            assert_true(!in_array('-C', $c['argv'], true), 'no -C');
        }
        $calls = $runners[0]->calls;
        assert_same(['config', '--local', '--no-includes', '--list', '--name-only'], array_slice($calls[0]['argv'], 5));
        assert_same(['status', '--porcelain=v2', '--branch', '--ignore-submodules=all'], array_slice($calls[1]['argv'], 5));
        assert_same(['log', '-1', '--format=%H%x00%cI%x00%s'], array_slice($calls[2]['argv'], 5));
    } finally {
        ps_cleanup($f);
    }
});

test('a failing git on one repo stores its error and the next repo is still scanned', function () {
    $f = ps_fixture();
    try {
        $rows = ps_by_slug(ps_scan($f, ['beta'])[0]);
        assert_true(str_contains((string) $rows['beta']['error'], 'git status failed (128)'),
            'beta error: ' . var_export($rows['beta']['error'], true));
        assert_same(null, $rows['beta']['branch']);
        assert_same(null, $rows['gamma']['error']);
        assert_same('feature/scan', $rows['gamma']['branch'], 'gamma scanned after beta failed');
    } finally {
        ps_cleanup($f);
    }
});

test('the fixture tree file hashes are identical before and after a scan', function () {
    $f = ps_fixture();
    try {
        $before = [ps_hashes($f['root']), ps_hashes($f['extraParent']), ps_hashes($f['outside'])];
        clearstatcache();
        ps_scan($f);
        clearstatcache();
        $after = [ps_hashes($f['root']), ps_hashes($f['extraParent']), ps_hashes($f['outside'])];
        assert_true(count($before[0]) >= 7, 'the tree has files to compare');
        assert_same($before, $after);
    } finally {
        ps_cleanup($f);
    }
});

test('store replaces the projects table with the scan', function () {
    $f = ps_fixture();
    $db = $f['root'] . '.sqlite';
    try {
        $pdo = Db::open($db);
        Db::migrate($pdo);
        $pdo->exec("INSERT INTO projects (slug, path, scanned_at) VALUES ('gone', 'X:\\gone', 1)");
        Scanner::store($pdo, ps_scan($f)[0]);
        $got = $pdo->query('SELECT slug, branch, ahead, loop_state FROM projects ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC);
        assert_same(['alpha', 'beta', 'gamma'], array_column($got, 'slug'));
        assert_same(['feature/scan', 2, 'running'], [$got[0]['branch'], (int) $got[0]['ahead'], $got[0]['loop_state']]);
        $pdo = null;
    } finally {
        ps_cleanup($f);
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($db . $s);
        }
    }
});

test('C:\\xampp\\htdocs is refused as a root', function () {
    $threw = false;
    try {
        new Scanner('C:\\XAMPP\\htdocs\\', [], new GitStatus(fn(array $e) => new FakeProcessRunner(), PS_GIT),
            new LoopState(new FakeProcessRunner(), 'C:\\Windows\\System32\\tasklist.exe'));
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected a refusal');
});

test('a repo with no commit yet is scanned without git log', function () {
    $runner = null;
    $git = new GitStatus(function (array $env) use (&$runner) {
        return $runner = new ScanRecordingRunner($env, fn(array $argv) => ['code' => 0,
            'stdout' => in_array('config', $argv, true) ? PS_INERT_CONFIG : "# branch.oid (initial)\n# branch.head main\n? a\n",
            'stderr' => '', 'timedOut' => false]);
    }, PS_GIT);
    $s = $git->read('D:\\nowhere');
    assert_same(['main', 1, null], [$s['branch'], $s['dirty'], $s['last_sha']]);
    assert_same(2, count($runner->calls), 'config and status only');
});

test('a repo config key off the inert list stores "not trusted" and runs no status', function () {
    $f = ps_fixture();
    try {
        [$rows, $runners] = ps_scan($f, [], ['beta' => "core.bare\nfilter.canary.clean\n", 'gamma' => "include.path\n"]);
        $rows = ps_by_slug($rows);
        assert_same('repo config not trusted: filter.canary.clean', $rows['beta']['error']);
        assert_same([null, null, null, null], [$rows['beta']['dirty'], $rows['beta']['ahead'], $rows['beta']['behind'],
            $rows['beta']['branch']]);
        assert_same('repo config not trusted: include.path', $rows['gamma']['error']);
        assert_same(null, $rows['alpha']['error']);
        foreach ($runners[0]->calls as $c) {
            if (!str_ends_with(ps_folder($c['argv']), '\\alpha')) {
                assert_true(in_array('config', $c['argv'], true), 'only config ran in beta and gamma');
            }
        }
    } finally {
        ps_cleanup($f);
    }
});

test('editor branch keys and core.hookspath are accepted; extensions.worktreeconfig and filter.x.clean are refused', function () {
    $f = ps_fixture();
    try {
        [$rows] = ps_scan($f, [], [
            'alpha' => "core.bare\nbranch.main.github-pr-base-branch\nbranch.x.gk-last-accessed\nbranch.main.github-pr-owner-number\ncore.hookspath\n",
            'beta' => "core.bare\nextensions.worktreeconfig\n",
            'gamma' => "core.bare\nfilter.x.clean\n",
        ]);
        $rows = ps_by_slug($rows);
        assert_same(null, $rows['alpha']['error']);
        assert_same('repo config not trusted: extensions.worktreeconfig', $rows['beta']['error']);
        assert_same('repo config not trusted: filter.x.clean', $rows['gamma']['error']);
    } finally {
        ps_cleanup($f);
    }
});

test('a .git that is a gitdir file, or has commondir, is not read by git', function () {
    $f = ps_fixture();
    try {
        ps_rmtree($f['root'] . '\\beta\\.git');
        file_put_contents($f['root'] . '\\beta\\.git', "gitdir: //127.0.0.1/share/repo.git\n");
        file_put_contents($f['extraParent'] . '\\gamma\\.git\\commondir', "//127.0.0.1/share\n");
        [$rows, $runners] = ps_scan($f);
        $rows = ps_by_slug($rows);
        assert_same('.git is a file, not a folder', $rows['beta']['error']);
        assert_same('repo not trusted: .git\\commondir', $rows['gamma']['error']);
        foreach ($runners[0]->calls as $c) {
            assert_true(str_ends_with(ps_folder($c['argv']), '\\alpha'), 'git ran only in alpha');
        }
    } finally {
        ps_cleanup($f);
    }
});

test('a .claude junction is not followed', function () {
    $f = ps_fixture();
    try {
        // beta's .claude points at alpha's, which has a lock and a checklist.
        ps_mklink($f['root'] . '\\beta\\.claude', $f['root'] . '\\alpha\\.claude');
        $tasklist = new FakeProcessRunner();
        $scanner = new Scanner($f['root'], [], new GitStatus(fn(array $env) => new ScanRecordingRunner($env, ps_git_answer()), PS_GIT),
            new LoopState($tasklist, 'C:\\Windows\\System32\\tasklist.exe'));
        $tasklist->willReturn(0, file_get_contents(__DIR__ . '/fixtures/projects/tasklist-node.csv'));
        $beta = ps_by_slug($scanner->scan(PS_NOW))['beta'];
        assert_same(0, $beta['has_claude']);
        assert_same(null, $beta['loop_state']);
        assert_same(null, $beta['checklist_cards']);
        assert_same('loop: .claude is not a plain folder', $beta['error']);
        assert_same(1, count($tasklist->calls), 'tasklist ran for alpha only');
    } finally {
        ps_cleanup($f);
    }
});

// A real git repo in a temp root, scanned with the real git.exe through ProcessRunner.
function ps_real_scan(string $root): array
{
    $exes = require __DIR__ . '/../config/executables.php';
    $git = new GitStatus(fn(array $env) => new ProcessRunner($exes, null, ProcessRunner::DEFAULT_ENV,
        $exes['taskkill'], $env), $exes['git']);
    return ps_by_slug((new Scanner($root, [], $git, new LoopState(new FakeProcessRunner(), $exes['tasklist'])))->scan(PS_NOW));
}

function ps_real_git(string $repo, array $args): void
{
    $exes = require __DIR__ . '/../config/executables.php';
    $r = (new ProcessRunner($exes))->run(array_merge([$exes['git'], '-C', $repo], $args), null, 30);
    assert_same(0, $r['code'], 'git ' . implode(' ', $args) . ': ' . $r['stderr']);
}

test('real git: a filter driver in .git/config never runs and the row says not trusted', function () {
    $root = ps_temp();
    $repo = $root . '\\handed';
    try {
        mkdir($repo);
        ps_real_git($repo, ['init', '-q']);
        file_put_contents($repo . '\\.gitattributes', "*.txt filter=canary\n");
        file_put_contents($repo . '\\a.txt', "hello\n");
        ps_real_git($repo, ['add', '.gitattributes', 'a.txt']);
        // Added before the filter exists, then made stat-dirty, so a status would re-hash a.txt through it.
        file_put_contents($repo . '\\.git\\config', "[filter \"canary\"]\n\tclean = \"echo ran > canary.flag; cat\"\n", FILE_APPEND);
        touch($repo . '\\a.txt', time() + 120);
        $row = ps_real_scan($root)['handed'];
        clearstatcache();
        assert_true(!file_exists($repo . '\\canary.flag') && !file_exists($root . '\\canary.flag'), 'the filter ran');
        assert_same('repo config not trusted: filter.canary.clean', $row['error']);
        assert_same([null, null, null], [$row['dirty'], $row['ahead'], $row['behind']]);
    } finally {
        ps_rmtree($root);
    }
});

test('real git: a fresh repo with only inert config is read', function () {
    $root = ps_temp();
    $repo = $root . '\\fresh';
    try {
        mkdir($repo);
        ps_real_git($repo, ['init', '-q', '-b', 'main']);
        file_put_contents($repo . '\\a.txt', "x\n");
        $row = ps_real_scan($root)['fresh'];
        assert_same(null, $row['error']);
        assert_same(['main', 1], [$row['branch'], $row['dirty']]);
    } finally {
        ps_rmtree($root);
    }
});
