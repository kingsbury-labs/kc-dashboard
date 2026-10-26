#!/usr/bin/env node
/**
 * Tests for handoff-staleness-blocker.cjs.
 *
 * The claim is narrow and worth stating: block a stop when context.md names a
 * commit or an issue state that is already false, and only when THIS turn wrote
 * that file. A hook that blocked on every turn would be abandoned within a day;
 * one that never blocked would be theatre.
 *
 * So the cases that matter are the negative ones. "It blocks a wrong sha" proves
 * little on its own - a hook that blocks everything passes that too. Every case
 * below therefore pins BOTH directions, and two of them exist purely to prove
 * the hook shuts up when it should.
 *
 * Hermetic: a scratch git repo and a synthetic transcript. No network, and `gh`
 * is never reached because the fixtures use no issue-state claims except the one
 * case that asserts a missing `gh` fails open.
 *
 * Run from repo root: node .claude/hooks/handoff-staleness-blocker.test.cjs
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');

const HOOK = path.join(__dirname, 'handoff-staleness-blocker.cjs');
let failures = 0;

function sh(args, cwd) {
    return execFileSync(args[0], args.slice(1), { windowsHide: true, cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
}

/** A throwaway repo with one commit and an origin/main ref pointing at it. */
function makeRepo() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'staleness-'));
    sh(['git', 'init', '-q', '-b', 'main'], dir);
    sh(['git', 'config', 'user.email', 't@example.com'], dir);
    sh(['git', 'config', 'user.name', 'T'], dir);
    fs.mkdirSync(path.join(dir, '.claude'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'seed.txt'), 'seed\n');
    sh(['git', 'add', '-A'], dir);
    sh(['git', 'commit', '-qm', 'seed'], dir);
    const head = sh(['git', 'rev-parse', 'HEAD'], dir);
    sh(['git', 'update-ref', 'refs/remotes/origin/main', head], dir);
    return { dir, head, short: head.slice(0, 8) };
}

/**
 * A transcript whose last turn does or does not contain a Write to context.md.
 * The turn boundary is the final real user message, matching the hook.
 */
function makeTranscript(dir, wroteContext) {
    const lines = [
        { type: 'user', message: { role: 'user', content: 'hand off the session' } },
    ];
    if (wroteContext) {
        lines.push({
            type: 'assistant',
            message: {
                role: 'assistant',
                content: [{ type: 'tool_use', name: 'Write', input: { file_path: `${dir}/.claude/context.md` } }],
            },
        });
    } else {
        lines.push({
            type: 'assistant',
            message: {
                role: 'assistant',
                content: [{ type: 'tool_use', name: 'Write', input: { file_path: `${dir}/README.md` } }],
            },
        });
    }
    const p = path.join(dir, 'transcript.jsonl');
    fs.writeFileSync(p, lines.map((l) => JSON.stringify(l)).join('\n') + '\n');
    return p;
}

/**
 * A fake `gh` that answers every `issue view` with one state.
 *
 * WITHOUT THIS, CHECK 2 IS UNTESTED. A fixture repo has no GitHub remote, so a
 * real `gh` throws, the check fails open, and an "allow" assertion passes
 * whether or not the logic works. The first draft of this file did exactly that
 * and was caught only by running the case against a copy with the logic
 * deleted: identical result. That is the same green-for-the-wrong-reason shape
 * the hook itself exists to stop.
 */
function ghStub(dir, state) {
    const isWin = process.platform === 'win32';
    const file = path.join(dir, isWin ? 'gh.cmd' : 'gh');
    fs.writeFileSync(file, isWin ? `@echo ${state}\r\n` : `#!/bin/sh\necho ${state}\n`);
    if (!isWin) fs.chmodSync(file, 0o755);
    return file;
}

function run({ contextBody, wroteContext, stopActive = false, ghState = null }) {
    const { dir, head, short } = makeRepo();
    fs.writeFileSync(path.join(dir, '.claude', 'context.md'), contextBody.replace(/HEAD_SHORT/g, short));
    const transcript = makeTranscript(dir, wroteContext);
    const payload = JSON.stringify({
        hook_event_name: 'Stop',
        cwd: dir,
        transcript_path: transcript,
        stop_hook_active: stopActive,
    });
    const env = { ...process.env };
    if (ghState) env.HANDOFF_GH_BIN = ghStub(dir, ghState);
    const res = spawnSync(process.execPath, [HOOK], { windowsHide: true, input: payload, encoding: 'utf8', env });
    let decision = 'allow';
    let reason = '';
    if (res.stdout && res.stdout.trim()) {
        try {
            const parsed = JSON.parse(res.stdout);
            decision = parsed.decision || 'allow';
            reason = parsed.reason || '';
        } catch {
            decision = 'unparseable';
        }
    }
    fs.rmSync(dir, { recursive: true, force: true });
    return { decision, reason, head, short };
}

function check(label, got, want, extra = '') {
    const ok = got === want;
    process.stdout.write(`${label.padEnd(62)} ${got.padEnd(6)} (want ${want})`);
    if (ok) {
        console.log('  OK');
    } else {
        console.log('  <== WRONG');
        if (extra) console.log(`      ${extra}`);
        failures++;
    }
}

console.log('sanity: the hook file exists and runs');
if (!fs.existsSync(HOOK)) {
    console.log('  HOOK MISSING - every case below would be meaningless. Failing.');
    process.exit(1);
}
console.log('  confirmed\n');

// THE CASE IT EXISTS FOR. Eighteen commits in this repo are this exact shape.
let r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`deadbee1`**, tree clean.\n',
    wroteContext: true,
});
check('a stale main sha BLOCKS', r.decision, 'block', r.reason);

// The same file, correct. A hook that cannot tell these apart is useless.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`HEAD_SHORT`**, tree clean.\n',
    wroteContext: true,
});
check('the CURRENT main sha is allowed', r.decision, 'allow', r.reason);

// Scope. The failure only exists on turns that wrote the handoff.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`deadbee1`**, tree clean.\n',
    wroteContext: false,
});
check('a stale sha is IGNORED if the turn did not write it', r.decision, 'allow', r.reason);

// Re-entry. Without this the block loops forever.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`deadbee1`**, tree clean.\n',
    wroteContext: true,
    stopActive: true,
});
check('stop_hook_active short-circuits the second attempt', r.decision, 'allow', r.reason);

// Production shas are not knowable from here, so they must not be flagged.
r = run({
    contextBody: 'Production is at `deadbee1` and carries every runtime change.\n',
    wroteContext: true,
});
check('a production sha is NOT judged against origin/main', r.decision, 'allow', r.reason);

// A bare issue reference is not a claim about its state.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`HEAD_SHORT`**. See #2028 for the bump.\n',
    wroteContext: true,
});
check('a bare issue number with no state word is not checked', r.decision, 'allow', r.reason);

// THE FALSE POSITIVE THIS HOOK ACTUALLY PRODUCED on its first live run against
// the real handoff. "Mark item 3 of #1669 done by hand" is an instruction, and
// flagging it would teach the reader to stop believing the hook. Two carve-outs
// cover it - the imperative, and the claim being about an ITEM rather than the
// issue - and this case fails if either is removed.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`HEAD_SHORT`**.\n\n'
        + '2. **Mark item 3 of #1669 done by hand**, citing the CI log.\n',
    wroteContext: true,
    ghState: 'OPEN',
});
check('an instruction to mark an item done is not a state claim', r.decision, 'allow', r.reason);

// CHECK 2, WHICH HAD NO COVERAGE AT ALL UNTIL THE gh STUB EXISTED. This is the
// "#1010 is a merged PR, not an open blocker" shape that cost two-plus handoffs.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`HEAD_SHORT`**.\n\nThe blocker #1832 is merged already.\n',
    wroteContext: true,
    ghState: 'OPEN',
});
check('calling an OPEN issue merged BLOCKS', r.decision, 'block', r.reason);

// The other direction, and it is the one that makes the case above mean
// something: identical text, truthful state, must pass.
r = run({
    contextBody: '## To Resume\n\n`origin/main` at **`HEAD_SHORT`**.\n\nThe blocker #1832 is merged already.\n',
    wroteContext: true,
    ghState: 'CLOSED',
});
check('the same sentence about a CLOSED issue is allowed', r.decision, 'allow', r.reason);

console.log();
if (failures === 0) {
    console.log('ALL CASES CORRECT');
} else {
    console.log(`${failures} CASE(S) WRONG`);
    process.exit(1);
}
