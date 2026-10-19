<?php
require_once __DIR__ . '/../src/Claude/ClaudeRunner.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const CR_EXE = 'C:\\fake\\claude.exe';

// Records what the cwd folder held at the moment the process would have started.
class ClaudeRunnerProbe extends FakeProcessRunner
{
    public ?string $root = null;
    /** @var string[]|null */
    public ?array $seen = null;

    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        if ($this->root !== null && $cwdName !== null) {
            $dir = $this->root . '\\' . $cwdName;
            $this->seen = is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : null;
        }
        return parent::run($argv, $cwdName, $timeoutSeconds, $stdin);
    }
}

function cr_runner(FakeProcessRunner $fake, array &$made): ClaudeRunner
{
    return new ClaudeRunner(function (?string $root, array $env) use ($fake, &$made): ProcessRunner {
        $made[] = ['root' => $root, 'env' => $env];
        if ($fake instanceof ClaudeRunnerProbe) {
            $fake->root = $root;
        }
        return $fake;
    }, CR_EXE);
}

function cr_json(string $result = 'the answer'): string
{
    return json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => $result,
        'usage' => ['input_tokens' => 12, 'output_tokens' => 34]]);
}

test('argv is claude -p --model sonnet json, with no bypass flag and no prompt', function () {
    $fake = new FakeProcessRunner();
    $fake->willReturn(0, cr_json());
    $made = [];
    $prompt = 'what does --dangerously-skip-permissions do in this repo?';
    cr_runner($fake, $made)->run($prompt, 'D:\\Dev\\htdocs', 'alpha');
    $argv = $fake->calls[0]['argv'];
    assert_same(CR_EXE, $argv[0]);
    assert_true(in_array('-p', $argv, true), '-p');
    $model = array_search('--model', $argv, true);
    assert_true($model !== false && ($argv[$model + 1] ?? null) === 'sonnet', '--model sonnet');
    $format = array_search('--output-format', $argv, true);
    assert_true($format !== false && ($argv[$format + 1] ?? null) === 'json', '--output-format json');
    foreach ($argv as $arg) {
        assert_true(!str_contains($arg, '--dangerously-skip-permissions'), 'bypass flag');
        assert_true(!str_contains($arg, '--permission-mode'), 'permission mode');
        assert_true(!str_contains($arg, 'what does'), 'prompt in argv');
    }
    assert_same($prompt, $fake->calls[0]['stdin'], 'prompt on stdin');
    assert_same('alpha', $fake->calls[0]['cwd']);
    assert_same('D:\\Dev\\htdocs', $made[0]['root']);
    foreach (['--disallowedTools', '--tools', '--restricted', '--strict-mcp-config'] as $flag) {
        assert_true(!in_array($flag, $argv, true), 'trusted mode has no ' . $flag);
    }
});

test('the child env always sets MSYS_NO_PATHCONV=1', function () {
    foreach (['plain text', new Prompt('Task', 'data')] as $prompt) {
        $fake = new FakeProcessRunner();
        $fake->willReturn(0, cr_json());
        $made = [];
        cr_runner($fake, $made)->run($prompt, 'D:\\Dev\\htdocs', 'alpha');
        assert_same('1', $made[0]['env']['MSYS_NO_PATHCONV'] ?? null);
    }
    // And the real runner puts it in the environment it hands the child.
    $real = new ProcessRunner([], null, ProcessRunner::DEFAULT_ENV, 'C:\\Windows\\System32\\taskkill.exe', ClaudeRunner::ENV);
    assert_same('1', $real->environment()['MSYS_NO_PATHCONV'] ?? null, 'real environment');
});

test('a Prompt runs in a fresh empty temp folder with the disallowed list', function () {
    $fake = new ClaudeRunnerProbe();
    $fake->willReturn(0, cr_json());
    $made = [];
    $prompt = new Prompt('Summarise.', 'untrusted feed text');
    $r = cr_runner($fake, $made)->run($prompt, 'D:\\Dev\\htdocs', 'alpha');
    assert_true($r['ok'], 'ok');
    $call = $fake->calls[0];
    assert_same(sys_get_temp_dir(), $made[0]['root'], 'temp root, not the project');
    assert_true($call['cwd'] !== 'alpha' && str_starts_with($call['cwd'], 'kcclaude_'), 'temp cwd ' . $call['cwd']);
    assert_same([], $fake->seen, 'folder existed and was empty at launch');
    assert_true(!file_exists(sys_get_temp_dir() . '\\' . $call['cwd']), 'temp folder removed after');
    $argv = $call['argv'];
    $t = array_search('--tools', $argv, true);
    assert_true($t !== false, '--tools present');
    assert_same('', $argv[$t + 1] ?? null, '--tools takes an empty list');
    assert_true(in_array('--restricted', $argv, true), '--restricted');
    assert_true(in_array('--strict-mcp-config', $argv, true), '--strict-mcp-config');
    assert_true(!in_array('--mcp-config', $argv, true), 'no MCP config handed in');
    assert_true(!in_array('--allowedTools', $argv, true), 'no allow rules added');
    assert_true(in_array('--disallowedTools', $argv, true), 'deny list kept as a second layer');
    assert_same($prompt->text(), $call['stdin']);
    // Two runs never share a folder.
    $fake->willReturn(0, cr_json());
    cr_runner($fake, $made)->run($prompt);
    assert_true($fake->calls[1]['cwd'] !== $call['cwd'], 'fresh folder each run');
});

test('a timeout returns a timeout error', function () {
    $fake = new FakeProcessRunner();
    $fake->willReturn(-1, '', '', true);
    $made = [];
    $r = cr_runner($fake, $made)->run('q', 'D:\\Dev\\htdocs', 'alpha', 5);
    assert_same(false, $r['ok']);
    assert_same('timeout', $r['error']);
    assert_same(5, $fake->calls[0]['timeout']);
});

test('result and usage are parsed; an error reply or bad JSON is a failure', function () {
    $fake = new FakeProcessRunner();
    $fake->willReturn(0, cr_json('forty-two'));
    $fake->willReturn(1, json_encode(['type' => 'result', 'subtype' => 'error_max_turns', 'is_error' => true]));
    $fake->willReturn(0, 'not json');
    $made = [];
    $runner = cr_runner($fake, $made);
    $ok = $runner->run('q', 'D:\\Dev\\htdocs', 'alpha');
    assert_same(['ok' => true, 'result' => 'forty-two', 'usage' => ['input_tokens' => 12, 'output_tokens' => 34], 'error' => null], $ok);
    $err = $runner->run('q', 'D:\\Dev\\htdocs', 'alpha');
    assert_same(false, $err['ok']);
    assert_true(str_contains($err['error'], 'error_max_turns'), 'subtype in error');
    $bad = $runner->run('q', 'D:\\Dev\\htdocs', 'alpha');
    assert_same(false, $bad['ok']);
    assert_same(null, $bad['result']);
});

test('the untrusted argv, empty --tools item included, reaches a real child unchanged', function () {
    // php.exe stands in for claude: it echoes back what it was given after `--`.
    $php = 'C:\\xampp\\php\\php.exe';
    $fake = new FakeProcessRunner();
    $fake->willReturn(0, cr_json());
    $made = [];
    cr_runner($fake, $made)->run(new Prompt('t', 'd'));
    $sent = array_slice($fake->calls[0]['argv'], 1);
    $real = new ProcessRunner(['php' => $php]);
    $r = $real->run(array_merge([$php, '-r', 'echo json_encode(array_slice($argv, 1));', '--'], $sent), null, 20);
    assert_same($sent, json_decode($r['stdout'], true), 'stderr ' . $r['stderr']);
});

test('the configured claude is an absolute .exe', function () {
    $exe = (require __DIR__ . '/../config/executables.php')['claude'];
    assert_true((bool) preg_match('/^[A-Za-z]:\\\\.+\\.exe$/i', $exe), $exe);
});
