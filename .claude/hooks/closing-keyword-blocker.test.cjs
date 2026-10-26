/**
 * Tests: node --test .claude/hooks/closing-keyword-blocker.test.cjs
 *
 * The four real incidents are the first four cases. Each one shipped, each one
 * closed an issue nobody meant to close, and each one was written by someone
 * who had read the rule.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { inspect } = require('./closing-keyword-blocker.cjs');

const CWD = process.cwd();

function blocked(command, cwd = CWD) {
    return inspect(command, cwd).blocked;
}

test('s151: a closing keyword in the PR title, even marked partial', () => {
    assert.strictEqual(blocked('gh pr create --title "Fix #948 (partial): tenant scope" --body "Part of #948"'), true);
});

test('s238: a body that says it is not using a keyword, while using one', () => {
    assert.strictEqual(blocked('gh pr create --body "Does not close #1012 with a keyword."'), true);
});

test('s260: a commit message, which a squash merge concatenates', () => {
    assert.strictEqual(blocked('git commit -m "Does not close #1629, which also asks for the control"'), true);
});

test('s286: the same denial one repo over', () => {
    assert.strictEqual(blocked('gh pr create --title "Fix the storage claim" --body "Does not close #190."'), true);
});

test('"Closes out item 1 of #1737" is a keyword next to a number', () => {
    assert.strictEqual(blocked('gh pr create --body "Closes out item 1 of #1737."'), true);
});

test('the owner/repo form is caught', () => {
    assert.strictEqual(blocked('gh pr create --body "closes kingsburycreative/sembr#12"'), true);
});

test('the GH-nnn form is caught', () => {
    assert.strictEqual(blocked('gh pr create --body "resolves GH-77"'), true);
});

test('the full issue URL form is caught', () => {
    assert.strictEqual(blocked('gh pr create --body "fixes https://github.com/kingsburycreative/sembr/issues/5"'), true);
});

test('a keyword and a number separated by a colon is caught', () => {
    assert.strictEqual(blocked('gh pr create --body "Closes: #14"'), true);
});

test('a body file is opened and read, not just the command text', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ckb-'));
    const file = path.join(dir, 'body.md');
    fs.writeFileSync(file, 'Part of the storage work.\n\nDoes not close #1851.\n');

    try {
        assert.strictEqual(blocked(`gh pr create --title "x" --body-file ${file}`), true);
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
});

test('a clean body file passes', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ckb-'));
    const file = path.join(dir, 'body.md');
    fs.writeFileSync(file, 'Part of #1851. Closing by hand once checks are green.\n');

    try {
        assert.strictEqual(blocked(`gh pr create --title "x" --body-file ${file}`), false);
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
});

test('a body file that is not there is not this hook\'s problem', () => {
    assert.strictEqual(blocked('gh pr create --title "x" --body-file /no/such/body.md'), false);
});

test('"Part of #948" is the phrasing the rule asks for, and passes', () => {
    assert.strictEqual(blocked('gh pr create --title "Advance #948: tenant scope" --body "Part of #948"'), false);
});

test('"Addresses item 1 of #1737" passes', () => {
    assert.strictEqual(blocked('gh pr create --body "Addresses item 1 of #1737."'), false);
});

test('breaking the adjacency is the documented escape and passes', () => {
    assert.strictEqual(blocked('gh pr create --body "no closing keyword is used for issue 1012"'), false);
});

/*
 * The deliberate over-refusal, kept as a test so it stays deliberate.
 *
 * "fix the #1 problem" almost certainly does not close anything on GitHub. It
 * is blocked anyway, because the rule the hook enforces is same-sentence rather
 * than adjacent, and the only reason it is same-sentence is that "Closes out
 * item 1 of #1737" DID close #1737 while identical phrasing elsewhere did not.
 * Predicting that parser is the losing game. The escape is one word, and the
 * thing on the other side of a miss is a live issue closed by accident.
 */
test('a keyword loosely near a number is refused too, and that is the trade', () => {
    assert.strictEqual(blocked('git commit -m "fix the #1 problem in the parser"'), true);
});

test('a keyword and a reference in DIFFERENT sentences is left alone', () => {
    assert.strictEqual(blocked('gh pr create --body "This does not close anything. Part of #1737."'), false);
});

test('an ISSUE body cannot close anything, so it is left alone', () => {
    assert.strictEqual(blocked('gh issue create --title "x" --body "fixes #12"'), false);
});

test('an unrelated command is not inspected', () => {
    assert.strictEqual(blocked('echo "closes #5" > notes.txt'), false);
});

test('a clean pr create beside an unrelated segment is judged on its own segment', () => {
    assert.strictEqual(blocked('echo "closes #5"; gh pr create --body "Part of #5"'), false);
});

test('a dirty pr create beside a clean segment is still caught', () => {
    assert.strictEqual(blocked('git checkout -b feature/x && gh pr create --body "closes #5"'), true);
});

test('a heredoc body the flag parser misses is caught by the segment fallback', () => {
    assert.strictEqual(blocked('gh pr create --title "x" --body "$(cat <<EOF\nCloses #77\nEOF\n)"'), true);
});

test('an empty command is not blocked', () => {
    assert.strictEqual(blocked(''), false);
});

/*
 * The cases above call inspect() directly, which is fast and covers the
 * matching. These last three drive the REAL entry point instead - a JSON event
 * on stdin, an exit code out - because a matcher that is right inside a hook
 * that never wires itself up is a guard that reports as installed and does
 * nothing.
 */

const { execFileSync } = require('child_process');

const HOOK = path.join(__dirname, 'closing-keyword-blocker.cjs');

/**
 * @param {string} command
 * @returns {number} the hook's exit code: 0 allows, 2 blocks.
 */
function run(command) {
    try {
        execFileSync(process.execPath, [HOOK], {
            windowsHide: true,
            input: JSON.stringify({ tool_input: { command }, cwd: CWD }),
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        return 0;
    } catch (err) {
        return err.status;
    }
}

test('the hook itself exits 2 on a closing keyword', () => {
    assert.strictEqual(run('gh pr create --body "Does not close #190."'), 2);
});

test('the hook itself exits 0 on the safe phrasing', () => {
    assert.strictEqual(run('gh pr create --body "Part of #190."'), 0);
});

test('the hook itself exits 0 on an unrelated command', () => {
    assert.strictEqual(run('git status --short'), 0);
});
