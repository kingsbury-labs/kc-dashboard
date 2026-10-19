<?php
require_once __DIR__ . '/../Process/ProcessRunner.php';

// Reads one repo's branch, ahead/behind, dirty count and last commit through git. Never writes:
// the runner is built here with GIT_OPTIONAL_LOCKS=0, so status never refreshes the index.
class GitStatus
{
    public const ENV = ['GIT_OPTIONAL_LOCKS' => '0'];
    public const MAX_SUBJECT = 500;

    private ProcessRunner $runner;
    private string $gitExe;
    private int $timeout;

    /** @param callable(array<string,string>):ProcessRunner $runnerFactory given the fixed environment for every git call */
    public function __construct(callable $runnerFactory, string $gitExe, int $timeoutSeconds = 30)
    {
        $this->runner = $runnerFactory(self::ENV);
        $this->gitExe = $gitExe;
        $this->timeout = $timeoutSeconds;
    }

    // Local config keys that start nothing and point nowhere. Any other key (a filter, core.worktree,
    // include.path, extensions.*) means status could run a command or read elsewhere, so the repo is not read.
    private const INERT = [
        '/^core\.(repositoryformatversion|filemode|bare|logallrefupdates|symlinks|ignorecase|autocrlf)$/',
        '/^remote\.[^\x00-\x1f]+\.(url|pushurl|fetch|gh-resolved)$/',
        '/^branch\.[^\x00-\x1f]+\.(remote|merge|vscode-merge-base)$/',
        '/^user\.(name|email)$/',
    ];

    /** @return array{branch:?string,ahead:?int,behind:?int,dirty:int,last_sha:?string,last_date:?string,last_subject:?string} */
    public function read(string $folder): array
    {
        $folder = rtrim($folder, '\\');
        // Both make git read objects from another folder, which may be a network path.
        foreach (['commondir', 'objects\\info\\alternates'] as $redirect) {
            if (@lstat($folder . '\\.git\\' . $redirect) !== false) {
                throw new RuntimeException('repo not trusted: .git\\' . $redirect);
            }
        }
        // --no-includes: an include.path is listed as a key and refused, never followed.
        $keys = $this->git($folder, ['config', '--local', '--no-includes', '--list', '--name-only']);
        foreach (preg_split('/\r?\n/', $keys) as $key) {
            if ($key !== '' && !self::inert($key)) {
                throw new RuntimeException('repo config not trusted: ' . mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $key), 0, 200, 'UTF-8'));
            }
        }
        $status = self::parseStatus($this->git($folder, ['status', '--porcelain=v2', '--branch', '--ignore-submodules=all']));
        $oid = $status['oid'];
        unset($status['oid']);
        $out = $status + ['last_sha' => null, 'last_date' => null, 'last_subject' => null];
        // A repo with no commit yet has nothing to log; git log would fail on it.
        if ($oid !== '(initial)') {
            $out = array_merge($out, self::parseLog($this->git($folder, ['log', '-1', '--format=%H%x00%cI%x00%s'])));
        }
        return $out;
    }

    private function git(string $folder, array $args): string
    {
        // fsmonitor off: a repo's own config can name a command for status to run.
        // An explicit git dir and work tree: a .git file or GIT_DIR discovery could point anywhere.
        $argv = array_merge([$this->gitExe, '-c', 'core.fsmonitor=false', '--git-dir=' . $folder . '\\.git',
            '--work-tree=' . $folder], $args);
        $r = $this->runner->run($argv, null, $this->timeout);
        if ($r['timedOut']) {
            throw new RuntimeException('git ' . $args[0] . ' timed out');
        }
        if ($r['code'] !== 0) {
            $err = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $r['stderr']));
            throw new RuntimeException('git ' . $args[0] . ' failed (' . $r['code'] . ')'
                . ($err !== '' ? ': ' . mb_substr($err, 0, 300, 'UTF-8') : ''));
        }
        return $r['stdout'];
    }

    public static function inert(string $key): bool
    {
        foreach (self::INERT as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    // `git status --porcelain=v2 --branch`: header lines start with "# ", every other line is one changed path.
    public static function parseStatus(string $out): array
    {
        $s = ['branch' => null, 'ahead' => null, 'behind' => null, 'dirty' => 0, 'oid' => null];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^# branch\.head (.+)$/', $line, $m)) {
                $s['branch'] = $m[1];
            } elseif (preg_match('/^# branch\.ab \+(\d+) -(\d+)$/', $line, $m)) {
                $s['ahead'] = (int) $m[1];
                $s['behind'] = (int) $m[2];
            } elseif (preg_match('/^# branch\.oid (\S+)$/', $line, $m)) {
                $s['oid'] = $m[1];
            } elseif (preg_match('/^[12u?] /', $line)) {
                $s['dirty']++;
            }
        }
        return $s;
    }

    // `git log -1 --format=%H%x00%cI%x00%s`. The subject is data from the repo, kept as text and capped.
    public static function parseLog(string $out): array
    {
        $parts = explode("\0", rtrim($out, "\r\n"), 3);
        if (count($parts) !== 3 || !preg_match('/^[0-9a-f]{40,64}$/', $parts[0])) {
            throw new RuntimeException('git log output not understood');
        }
        $subject = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $parts[2]);
        return [
            'last_sha' => $parts[0],
            'last_date' => $parts[1],
            'last_subject' => mb_substr($subject, 0, self::MAX_SUBJECT, 'UTF-8'),
        ];
    }
}
