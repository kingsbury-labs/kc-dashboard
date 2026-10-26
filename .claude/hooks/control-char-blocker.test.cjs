#!/usr/bin/env node
/**
 * Tests for control-char-blocker.cjs.
 *
 * The claim is narrow: refuse a control character in source, and refuse nothing
 * else. The second half is the part that keeps the hook alive - a guard that
 * also blocks tabs and newlines gets switched off within a day, and a switched-
 * off guard is worse than none because everyone believes it is watching.
 *
 * So five of the eight cases below assert ALLOW. The one that matters most is
 * the real byte from the real defect: the 0x08 that sat inside
 * `Request\s+\$request` and killed half a predicate for months.
 *
 * Run from repo root: node .claude/hooks/control-char-blocker.test.cjs
 */

const path = require('path');
const { spawnSync } = require('child_process');

const HOOK = path.join(__dirname, 'control-char-blocker.cjs');
let failures = 0;

function run(toolInput, toolName = 'Write') {
    const payload = JSON.stringify({
        hook_event_name: 'PreToolUse',
        tool_name: toolName,
        tool_input: toolInput,
    });
    const res = spawnSync(process.execPath, [HOOK], { windowsHide: true, input: payload, encoding: 'utf8' });
    if (!res.stdout || !res.stdout.trim()) return { decision: 'allow', reason: '' };
    try {
        const parsed = JSON.parse(res.stdout);
        const d = parsed.hookSpecificOutput?.permissionDecision || 'allow';
        return { decision: d === 'deny' ? 'block' : 'allow', reason: parsed.hookSpecificOutput?.permissionDecisionReason || '' };
    } catch {
        return { decision: 'unparseable', reason: res.stdout };
    }
}

function check(label, got, want, extra = '') {
    process.stdout.write(`${label.padEnd(58)} ${got.padEnd(6)} (want ${want})`);
    if (got === want) {
        console.log('  OK');
    } else {
        console.log('  <== WRONG');
        if (extra) console.log(`      ${String(extra).split('\n')[0]}`);
        failures++;
    }
}

// THE REAL DEFECT, byte for byte. This is what sat in
// StaticAnalysisCoversRequestReadingCodeTest and matched nothing for months.
const REAL = "    if (preg_match('/\\$request->|Request\\s+\\$request/', $code) === 1) {";
let r = run({ file_path: 'app/Foo.php', content: REAL });
check('the real 0x08 from trap 209 BLOCKS', r.decision, 'block', r.reason);

// The same line, spelled correctly. A hook that cannot tell these apart is
// useless, and they differ by exactly one byte.
const FIXED = "    if (preg_match('/\\$request->|Request\\s+\\$request\\b/', $code) === 1) {";
r = run({ file_path: 'app/Foo.php', content: FIXED });
check('the same line with a literal backslash-b is allowed', r.decision, 'allow', r.reason);

// Tabs, newlines and carriage returns are normal source. Blocking them would
// end this hook's life immediately.
r = run({ file_path: 'app/Foo.php', content: 'line one\n\tindented\r\nline two\n' });
check('tab, LF and CRLF are untouched', r.decision, 'allow', r.reason);

// Edit carries new_string rather than content.
r = run({ file_path: 'app/Foo.php', old_string: 'a', new_string: "badvalue" }, 'Edit');
check('a vertical tab in an Edit new_string BLOCKS', r.decision, 'block', r.reason);

// REMOVING a stray byte requires matching it. old_string must never be judged,
// or the hook would prevent its own cleanup.
r = run({ file_path: 'app/Foo.php', old_string: "wasbroken", new_string: 'was fine' }, 'Edit');
check('a control char in old_string is allowed (that is a removal)', r.decision, 'allow', r.reason);

// Lock files and binaries are somebody else's bytes.
r = run({ file_path: 'package-lock.json', content: "xy" });
check('a lock file is skipped', r.decision, 'allow', r.reason);

r = run({ file_path: 'node_modules/pkg/index.js', content: "xy" });
check('node_modules is skipped', r.decision, 'allow', r.reason);

// Ordinary prose with an em-dash and accents must not trip a byte-range check.
r = run({ file_path: 'docs/notes.md', content: 'Café, naïve, 90% — and a résumé.\n' });
check('non-ASCII prose is not a control character', r.decision, 'allow', r.reason);

console.log();
if (failures === 0) {
    console.log('ALL CASES CORRECT');
} else {
    console.log(`${failures} CASE(S) WRONG`);
    process.exit(1);
}
