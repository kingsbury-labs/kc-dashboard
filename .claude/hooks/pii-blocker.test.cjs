/**
 * Tests for pii-blocker.cjs, and specifically for the project allowlist.
 *
 * WHY THESE EXIST. A client website is the one project type where real contact
 * details are the deliverable: the business's phone number and inbox belong on
 * the contact page. The detector therefore has to be told which real values are
 * public, and that is a hole by construction. An untested hole is the worst
 * kind, because the two ways it fails look nothing alike:
 *
 *   - too narrow, and the hook refuses ordinary work until somebody disables it;
 *   - too wide, and a customer's phone number commits silently because the
 *     pattern written for the business covered it too.
 *
 * So the cases below assert both directions, and they assert that a typo in the
 * allowlist file costs a false BLOCK rather than a silent pass. A config parse
 * error must never be a reason to skip a scan.
 *
 * Hermetic: each case stages its own copy of the hook and its config in the OS
 * temp directory. Nothing here reads the allowlist of a real project.
 *
 * Run: node --test .claude/hooks/pii-blocker.test.cjs
 */

const { test, describe, before, after } = require('node:test');
const assert = require('node:assert');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

let tmp;
let staged = 0;

/** A copy of the hook with its own `pii-allowlist.json`, or none at all. */
function stage(allowlist) {
    const dir = path.join(tmp, `hook-${staged++}`);
    fs.mkdirSync(dir, { recursive: true });
    for (const f of ['pii-blocker.cjs', 'hook-utils.cjs']) {
        fs.copyFileSync(path.join(__dirname, f), path.join(dir, f));
    }
    if (allowlist !== undefined) {
        fs.writeFileSync(path.join(dir, 'pii-allowlist.json'), allowlist, 'utf8');
    }
    return path.join(dir, 'pii-blocker.cjs');
}

/** Allowed is exit 0. Blocked is exit 2 with the reason on stderr. */
function run(hook, content, filePath = 'pages/contact.php') {
    const event = JSON.stringify({
        hook_event_name: 'PreToolUse',
        tool_name: 'Write',
        tool_input: { file_path: filePath, content },
    });
    try {
        execFileSync('node', [hook], { input: event, encoding: 'utf8', stdio: 'pipe' });
        return { allowed: true, reason: '' };
    } catch (err) {
        return { allowed: false, reason: (err.stderr || '').toString() };
    }
}

before(() => {
    tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pii-blocker-'));
});

after(() => {
    fs.rmSync(tmp, { recursive: true, force: true });
});

describe('with no project allowlist, the detector is unchanged', () => {
    let hook;
    before(() => { hook = stage(undefined); });

    test('a real-looking email is refused', () => {
        const r = run(hook, `<a href="mailto:brenda@example-optical.test">Email us</a>`);
        assert.strictEqual(r.allowed, false);
        assert.match(r.reason, /Email/i);
    });

    test('the base example-data patterns still pass', () => {
        assert.strictEqual(run(hook, `contact: jane.doe@example.com`).allowed, true);
    });

    test('a file with no PII passes', () => {
        assert.strictEqual(run(hook, `<h1>Opening hours</h1>`).allowed, true);
    });
});

describe('a project allowlist opens exactly what it names', () => {
    let hook;
    before(() => {
        hook = stage(JSON.stringify({
            literals: ['hello@example-optical.test', '(613) 555-0143'],
            patterns: ['@example-optical\\.test$'],
        }));
    });

    test('a literal the client publishes is allowed', () => {
        assert.strictEqual(run(hook, `mailto:hello@example-optical.test`).allowed, true);
    });

    test('a pattern covers the rest of the client domain', () => {
        assert.strictEqual(run(hook, `mailto:accounts@example-optical.test`).allowed, true);
    });

    test('an address at another domain is still refused', () => {
        // The case that matters. An allowlist that let this through would be a
        // hook that has stopped doing anything on the file type it exists for.
        const r = run(hook, `Customer contact: r.okafor@fakeco-mail.ca`);
        assert.strictEqual(r.allowed, false);
    });

    test('a literal is matched whole, not as a substring', () => {
        // `hello@example-optical.test.attacker.test` contains the allowed literal.
        // Anchoring is what stops a permitted value from vouching for a longer one.
        const r = run(hook, `mailto:hello@example-optical.test.attacker.test`);
        assert.strictEqual(r.allowed, false);
    });

    test('an unrelated phone number is still refused', () => {
        const r = run(hook, `Reach the supplier at (416) 555-0188`);
        assert.strictEqual(r.allowed, false);
    });

    test('a phone literal is matched despite the detector dropping the leading paren', () => {
        // Found on the first real scaffold, not in review. The phone regex starts
        // at the first digit, so `(613) 555-0143` on the page reaches the
        // allowlist as `613) 555-0143` and an exact literal never fired. The
        // symptom is an allowlist that looks broken, and the move after that is
        // switching the hook off.
        const r = run(hook, `<a href="tel:+16135550143">(613) 555-0143</a>`);
        assert.strictEqual(r.allowed, true, r.reason);
    });

    test('the same number written differently is still the same number', () => {
        assert.strictEqual(run(hook, `Call 613-555-0143 today`).allowed, true);
    });

    test('a different number with a similar shape is still refused', () => {
        // The control on the digit comparison. If it compared loosely rather than
        // on the whole digit string, this would pass and the allowlist would be a
        // wildcard for every local number.
        assert.strictEqual(run(hook, `Call (613) 555-0144 today`).allowed, false);
    });
});

describe('a broken allowlist costs a false block, never a silent pass', () => {
    test('malformed JSON is treated as an empty allowlist and the scan still runs', () => {
        const hook = stage('{ "literals": [ this is not json');
        const r = run(hook, `mailto:hello@example-optical.test`);
        assert.strictEqual(r.allowed, false, 'a parse error must not skip the scan');
    });

    test('an unparseable regex is skipped without taking the others with it', () => {
        const hook = stage(JSON.stringify({
            literals: ['hello@example-optical.test'],
            patterns: ['*** not a regex'],
        }));
        assert.strictEqual(run(hook, `mailto:hello@example-optical.test`).allowed, true);
    });

    test('a non-string entry is ignored rather than crashing the hook', () => {
        const hook = stage(JSON.stringify({ literals: [null, 42, 'hello@example-optical.test'] }));
        assert.strictEqual(run(hook, `mailto:hello@example-optical.test`).allowed, true);
    });
});
