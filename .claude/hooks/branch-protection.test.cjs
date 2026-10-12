/**
 * Tests for branch-protection.cjs.
 *
 * WHY THESE EXIST. The hook decided which branch a delete targeted by splitting the
 * WHOLE command string on whitespace and asking whether any protected name appeared
 * anywhere in it. That single line produced a guard that refused safe work and
 * permitted unsafe work at the same time, which is the combination that trains a
 * person to route around it. At that point the protection is decorative.
 *
 * EVERY CASE HERE WAS PROBED AGAINST REAL GIT FIRST, in a throwaway repository, and
 * the probes corrected the issue that prompted the fix (#1676). The issue claimed a
 * protected branch could be deleted by naming it through its full ref path. It cannot:
 * `git branch -D refs/heads/main` is refused by git itself with "branch not found".
 * The real bypasses are more ordinary than that, which is worse - quoting the branch
 * name is something a person does without thinking about it.
 *
 * Hermetic: every test runs against a throwaway repository in the OS temp directory.
 * Nothing here touches the Sembr working tree or its branches.
 *
 * Run: node --test .claude/hooks/branch-protection.test.cjs
 */

const { test, describe, before, after } = require('node:test');
const assert = require('node:assert');
const { execFileSync, execSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const HOOK = path.join(__dirname, 'branch-protection.cjs');

let tmp;
let onFeature;
let onMain;
let remoteRepo;
let prOnlyHook;
let brokenConfigHook;

/**
 * A copy of the hook in a throwaway directory, with its own `hooks.config.json`.
 *
 * The config is deliberately read from the hook's OWN directory rather than from
 * an environment variable, for the same reason the current branch is not
 * injectable: an env var that could shrink `protectedBranches` would be a
 * documented bypass of the force-push arm. Staging a copy is the only way to
 * exercise a second configuration without opening that door.
 */
function stageHook(name, config) {
    const dir = path.join(tmp, name);
    fs.mkdirSync(dir, { recursive: true });
    for (const f of ['branch-protection.cjs', 'hook-utils.cjs']) {
        fs.copyFileSync(path.join(__dirname, f), path.join(dir, f));
    }
    if (config !== undefined) {
        fs.writeFileSync(path.join(dir, 'hooks.config.json'), config, 'utf8');
    }
    return path.join(dir, 'branch-protection.cjs');
}

/**
 * A throwaway repository checked out on `branch`, with `main` and `production` also
 * present so a delete has something real to aim at.
 *
 * The hook reads the current branch with `git branch --show-current` in its own cwd,
 * so controlling the current branch means controlling the cwd. That is deliberately
 * NOT an injectable override: an env var saying "pretend I am on a feature branch"
 * would be a documented bypass of the arm that blocks force-pushes to main.
 */
function makeRepo(name, branch) {
    const dir = path.join(tmp, name);
    fs.mkdirSync(dir, { recursive: true });
    const git = (args) => execSync(`git ${args}`, { cwd: dir, stdio: 'pipe' });
    git('init -q');
    git('config user.email t@t.t');
    git('config user.name t');
    git('commit -q --allow-empty -m init');
    git('branch -M main');
    git('branch production');
    git('checkout -q -b feature/x');
    if (branch !== 'feature/x') git(`checkout -q ${branch}`);
    return dir;
}

/**
 * A repository with a real bare remote holding `production`, so a remote-delete test
 * asserts against something that can actually be deleted rather than against a string.
 */
function makeRepoWithRemote(name) {
    const root = path.join(tmp, name);
    fs.mkdirSync(root, { recursive: true });
    execSync('git init -q --bare origin.git', { cwd: root, stdio: 'pipe' });
    const dir = path.join(root, 'work');
    fs.mkdirSync(dir, { recursive: true });
    const git = (args) => execSync(`git ${args}`, { cwd: dir, stdio: 'pipe' });
    git('init -q');
    git('config user.email t@t.t');
    git('config user.name t');
    git('remote add origin ../origin.git');
    git('commit -q --allow-empty -m init');
    git('branch -M main');
    git('checkout -q -b production');
    git('push -q origin production');
    git('checkout -q -b feature/x');
    return dir;
}

/**
 * Allowed is exit 0. Blocked is exit 2 with the reason on stderr, which is the
 * blockAndExit contract in hook-utils.cjs.
 */
function run(command, cwd, hook = HOOK) {
    const event = JSON.stringify({
        hook_event_name: 'PreToolUse',
        tool_name: 'Bash',
        tool_input: { command },
    });
    try {
        execFileSync('node', [hook], { input: event, encoding: 'utf8', cwd, stdio: 'pipe' });
        return { allowed: true, reason: '' };
    } catch (err) {
        return { allowed: false, reason: (err.stderr || '').toString() };
    }
}

before(() => {
    tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'branch-protection-'));
    onFeature = makeRepo('on-feature', 'feature/x');
    onMain = makeRepo('on-main', 'main');
    remoteRepo = makeRepoWithRemote('with-remote');
    prOnlyHook = stageHook('cfg-pr-only', JSON.stringify({ prOnly: true, projectName: 'Acme Co' }));
    brokenConfigHook = stageHook('cfg-broken', '{ this is not json');
});

after(() => {
    fs.rmSync(tmp, { recursive: true, force: true });
});

describe('safe work must not be refused', () => {
    test('the ordinary post-merge cleanup passes', () => {
        // Symptom 1 of #1676. The only branch being deleted is the feature branch;
        // `main` is a token from the unrelated checkout half of the compound command.
        const r = run('git checkout main && git branch -D feature/x', onFeature);
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('a non-git command that merely quotes the words passes', () => {
        // Symptom 2, and the worst of the three, because it makes the hook fire on work
        // that has nothing to do with git. Filing #1676 was itself blocked by this, and
        // so was the throwaway-repo probe that verified the rest of this file.
        const r = run(
            `gh issue create --title "hook bug" --body "it blocks git branch -D main wrongly"`,
            onFeature,
        );
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('a non-git command passes even when its text contains shell separators', () => {
        // The quote-blind version of the fix trades symptom 2 for a subtler copy of it:
        // splitting on && first would find a fragment that begins with `git branch`
        // inside the quoted body and refuse the whole thing.
        const r = run(
            `gh issue comment 1676 --body "run git checkout main && git branch -D main to reproduce"`,
            onFeature,
        );
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('a heredoc body describing a delete is data, not a command', () => {
        // Found by the guard blocking the `gh pr create` that opened the pull request
        // for this very fix: the body documented the bug, so a line of it began with a
        // delete. The first version of the rewrite still read heredoc payload as
        // commands, which is the original defect one level down.
        const r = run(
            ['cat > body.md <<\'EOF\'', 'Refused wrongly:', 'git branch -D main', 'EOF', 'gh pr create --body-file body.md'].join('\n'),
            onFeature,
        );
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('an unquoted heredoc delimiter is handled the same way', () => {
        const r = run(
            ['cat > body.md <<EOF', 'git push origin :production', 'EOF', 'echo done'].join('\n'),
            onFeature,
        );
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('a real command AFTER a heredoc is still examined', () => {
        // The guard on the guard. Skipping a heredoc body must stop at its delimiter;
        // a skip that ran to the end of the command would silently disable the hook for
        // everything downstream, and would pass every other test in this file.
        const r = run(
            ['cat > body.md <<\'EOF\'', 'just some text', 'EOF', 'git branch -D main'].join('\n'),
            onFeature,
        );
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /protected/i);
    });

    test('deleting an ordinary feature branch passes', () => {
        assert.strictEqual(run('git branch -D feature/x', onFeature).allowed, true);
    });

    test('a branch whose NAME merely contains a protected word passes', () => {
        // `main` is a substring of `maintenance`. A guard that matched substrings
        // instead of whole names would refuse this, and the branch is not protected.
        assert.strictEqual(run('git branch -D feature/maintenance', onFeature).allowed, true);
    });

    test('pushing a feature branch passes', () => {
        assert.strictEqual(run('git push -u origin feature/x', onFeature).allowed, true);
    });
});

describe('local deletes of a protected branch must be refused', () => {
    test('the plain spelling', () => {
        const r = run('git branch -D main', onFeature);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /protected/i);
    });

    test('the double-quoted spelling', () => {
        // A REAL bypass, verified deleting the branch in a throwaway repo. The hook saw
        // the raw string, so the token was `"main"` with the quotes attached and never
        // equalled `main`. Quoting a branch name is completely ordinary.
        const r = run('git branch -D "main"', onFeature);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /protected/i);
    });

    test('the single-quoted spelling', () => {
        const r = run("git branch -D 'production'", onFeature);
        assert.strictEqual(r.allowed, false);
    });

    test('the long flag with force', () => {
        const r = run('git branch --delete --force main', onFeature);
        assert.strictEqual(r.allowed, false);
    });

    test('after an end-of-options marker', () => {
        const r = run('git branch -D -- main', onFeature);
        assert.strictEqual(r.allowed, false);
    });

    test('as the second half of a compound command', () => {
        const r = run('git checkout feature/x && git branch -D production', onFeature);
        assert.strictEqual(r.allowed, false);
    });

    test('when several branches are deleted at once and one is protected', () => {
        const r = run('git branch -D feature/x main', onFeature);
        assert.strictEqual(r.allowed, false);
    });
});

describe('a delete whose target cannot be read must fail closed', () => {
    test('a command substitution is refused rather than guessed at', () => {
        // The other real bypass: tokens were `$(echo` and `main)`, neither of which
        // equalled `main`, and the delete went through. Resolving it would mean running
        // it, so the honest answer is to refuse and say why - the same posture
        // infra-write-guard.cjs takes on an unexpanded URL.
        const r = run('git branch -D $(echo main)', onFeature);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /cannot tell which branch|shell/i);
    });

    test('a shell variable is refused rather than guessed at', () => {
        const r = run('git branch -D "$BRANCH"', onFeature);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /cannot tell which branch|shell/i);
    });

    test('backticks are refused rather than guessed at', () => {
        const r = run('git branch -D `echo main`', onFeature);
        assert.strictEqual(r.allowed, false);
    });
});

describe('remote deletes of a protected branch must be refused', () => {
    test('the --delete spelling', () => {
        const r = run('git push origin --delete main', remoteRepo);
        assert.strictEqual(r.allowed, false);
    });

    test('the quoted --delete spelling', () => {
        const r = run('git push origin --delete "main"', remoteRepo);
        assert.strictEqual(r.allowed, false);
    });

    test('the colon refspec spelling', () => {
        // Not in #1676 at all, and verified deleting a real remote branch: the arm only
        // tested for `--delete`, so the older colon form was never examined. GitHub
        // refuses to delete a repository's DEFAULT branch, which backstops `main` there
        // but covers neither `production` nor `master`.
        const r = run('git push origin :production', remoteRepo);
        assert.strictEqual(r.allowed, false);
    });

    test('the explicit-source colon refspec is not mistaken for a delete', () => {
        // `src:dst` with a source is an ordinary push, not a delete. Only an EMPTY
        // source means delete. A guard that saw any colon here would refuse normal work.
        const r = run('git push origin feature/x:main', remoteRepo);
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('deleting an ordinary remote branch passes', () => {
        assert.strictEqual(run('git push origin :feature/x', remoteRepo).allowed, true);
    });
});

describe('the arms that already worked must keep working', () => {
    test('force-push from a protected branch is refused', () => {
        const r = run('git push --force origin main', onMain);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /Dangerous operation/);
    });

    test('reset --hard on a protected branch is refused', () => {
        assert.strictEqual(run('git reset --hard HEAD~1', onMain).allowed, false);
    });

    test('a plain push from a protected branch is allowed by default', () => {
        // The default suits a one-person brochure build committing to main. Blocking
        // that on day one is how someone learns the hook is noise, and the arms that
        // matter get switched off along with it.
        const r = run('git push', onMain);
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('the same force-push from a feature branch is allowed', () => {
        // The positive control. Without it, a hook that denied everything would pass
        // every other test in this group while permitting no legitimate work at all.
        assert.strictEqual(run('git push --force origin feature/x', onFeature).allowed, true);
    });

    test('an unrelated command is untouched', () => {
        assert.strictEqual(run('ls -la', onFeature).allowed, true);
    });
});

describe('the workflow is configurable, the destructive arms are not', () => {
    test('prOnly turns the direct-push block on, and the message names the project', () => {
        const r = run('git push', onMain, prOnlyHook);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /PR-only/);
        assert.match(r.reason, /Acme Co/);
    });

    test('prOnly does not block a push from a feature branch', () => {
        // The positive control for the arm above. Without it, a config that refused
        // every push would satisfy the previous test while permitting no work.
        assert.strictEqual(run('git push -u origin feature/x', onFeature, prOnlyHook).allowed, true);
    });

    test('a malformed config falls back to the defaults rather than to no protection', () => {
        // The failure that would matter: a typo in the JSON must not quietly turn the
        // force-push and delete arms off. It costs a default, never a bypass.
        const r = run('git push --force origin main', onMain, brokenConfigHook);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /Dangerous operation/);
    });

    test('a malformed config still refuses a protected-branch delete', () => {
        assert.strictEqual(run('git branch -D main', onFeature, brokenConfigHook).allowed, false);
    });

    test('prOnly does not disable the destructive arms', () => {
        assert.strictEqual(run('git reset --hard HEAD~1', onMain, prOnlyHook).allowed, false);
    });
});
