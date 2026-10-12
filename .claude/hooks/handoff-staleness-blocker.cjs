#!/usr/bin/env node
/**
 * Stop hook: the handoff may not name state that is already false.
 *
 * WHY THIS IS A CONTROL AND NOT A RULE. `.claude/context.md` naming a commit or
 * an issue state that was true when written and false by the time the session
 * ended was the single most repeated defect in the project this hook was written
 * for: eighteen commits over seven weeks existed only to correct it, and the
 * handoff writing its own hash immediately before a merge that moved it was the
 * most common shape.
 *
 * IT HAD ALREADY BEEN FIXED IN PROSE THREE TIMES before this file existed. The
 * rule was in the handoff skill, in CLAUDE.md and in the standing orders, and
 * the rate did not move. That is the whole argument for making it mechanical: a
 * written instruction is read alongside everything else and can lose, every
 * time, to whatever else is in context.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not ask the model to review its own
 * work against a list of remembered mistakes. Self-review is the thing that
 * already fails here - the model that wrote the wrong number is the one being
 * asked whether the number is wrong, and it will say no. Every check below is
 * arithmetic or a `gh` lookup. There is nothing to rubber-stamp.
 *
 * SCOPE. Fires only when the turn actually wrote to `.claude/context.md`. A Stop
 * hook that blocks every turn burns a model turn on "hello", and this failure
 * only exists on turns that touch the handoff.
 *
 * FAIL-OPEN, DELIBERATELY, AND ONLY HERE. If git or gh is unavailable, or the
 * transcript cannot be read, this allows the turn. A Stop hook that wedged on a
 * missing binary would make the session unfinishable, and the cost of a missed
 * check is a wrong number in a file - recoverable - while the cost of a wedge is
 * a session that cannot end. That trade is the opposite of the one
 * `credential-read-blocker` makes, and the difference is that a leaked
 * credential is not recoverable.
 *
 * Tests: node .claude/hooks/handoff-staleness-blocker.test.cjs
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const CONTEXT_REL = '.claude/context.md';

/** Read and parse the hook's JSON payload from stdin. */
function readInput() {
    let raw = '';
    try {
        raw = fs.readFileSync(0, 'utf8');
    } catch {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function allow() {
    process.exit(0);
}

/**
 * Block the stop, handing back the specific disagreements.
 *
 * The reason names the file AND the live value, so the fix is a substitution
 * rather than an investigation.
 */
function block(problems) {
    const body = problems.map((p) => `  - ${p}`).join('\n');
    process.stdout.write(JSON.stringify({
        decision: 'block',
        reason:
            `${CONTEXT_REL} names state that is already false. A handoff that asserts something `
            + `untrue is worse than no handoff: the next session acts on it and burns a window `
            + `redoing finished work, so it is checked mechanically rather than promised.`
            + `\n\n${body}\n\n`
            + `Fix the file, then finish. Do not re-verify by reasoning: the values above were `
            + `read from git and gh just now.`,
    }));
    process.exit(0);
}

function git(args, cwd) {
    return execFileSync('git', args, { cwd, encoding: 'utf8', timeout: 15000 }).trim();
}

/**
 * Overridable so the tests can stand a stub in front of it.
 *
 * Without this the issue-state check has NO coverage: a hermetic fixture repo
 * has no GitHub remote, so every `gh` call throws, the check fails open, and a
 * test asserting "allow" passes whether or not the logic under it works. That
 * happened on the first draft of the test beside this file and was caught only
 * by running the case against a copy with the logic deleted, which behaved
 * identically. A stub is the difference between testing this and not.
 */
const GH_BIN = process.env.HANDOFF_GH_BIN || 'gh';

/**
 * Did this turn write to context.md?
 *
 * Walks back to the most recent real user prompt, the same turn boundary
 * `infra-write-guard.cjs` uses, and looks for a Write/Edit naming the file. A
 * read does not count: reading the handoff is not what makes it stale.
 */
function turnWroteContext(transcriptPath) {
    if (!transcriptPath || !fs.existsSync(transcriptPath)) return false;

    const entries = [];
    for (const line of fs.readFileSync(transcriptPath, 'utf8').split('\n')) {
        if (!line.trim()) continue;
        try {
            entries.push(JSON.parse(line));
        } catch {
            // A malformed line is not evidence either way.
        }
    }

    let turnStart = -1;
    for (let i = entries.length - 1; i >= 0; i--) {
        const e = entries[i];
        if (e.type !== 'user' || !e.message || e.message.role !== 'user') continue;
        const content = e.message.content;
        const isToolResult = Array.isArray(content) && content.length > 0 && content[0].type === 'tool_result';
        if (!isToolResult) {
            turnStart = i;
            break;
        }
    }
    if (turnStart === -1) return false;

    for (let i = turnStart; i < entries.length; i++) {
        const e = entries[i];
        if (e.type !== 'assistant' || !e.message || !Array.isArray(e.message.content)) continue;
        for (const item of e.message.content) {
            if (item.type !== 'tool_use') continue;
            if (!['Write', 'Edit', 'NotebookEdit'].includes(item.name)) continue;
            const p = String(item.input?.file_path ?? '').replace(/\\/g, '/');
            if (p.endsWith(CONTEXT_REL) || p.endsWith('.claude/context.md')) return true;
        }
    }
    return false;
}

/**
 * Every 7-to-40 char hex string the file presents as a commit.
 *
 * Anchored to the words that make it a CLAIM about where a branch is, rather
 * than any hex that happens to appear.
 */
function claimedShas(text) {
    const out = [];
    const re = /(origin\/main|`?main`?|production|prod)[^\n.]{0,60}?\b([0-9a-f]{7,40})\b/gi;
    let m;
    while ((m = re.exec(text)) !== null) {
        out.push({ subject: m[1].toLowerCase().replace(/`/g, ''), sha: m[2] });
    }
    return out;
}

/**
 * Issue and PR numbers the file asserts a STATE for.
 *
 * "#1832 is merged" and "#1010 is a merged PR, not an open blocker" are the
 * shapes that cost sessions. A bare `#1234` with no state word is not checked:
 * naming an issue is not a claim about it.
 */
function claimedIssueStates(text) {
    const out = new Map();
    const re = /#(\d{2,5})\b([^\n.]{0,50}?)\b(merged|closed|open|shipped|done|landed)\b/gi;
    let m;
    while ((m = re.exec(text)) !== null) {
        const n = m[1];
        const between = m[2];
        const word = m[3].toLowerCase();
        const preceding = text.slice(Math.max(0, m.index - 60), m.index);

        // AN INSTRUCTION IS NOT A CLAIM. "Mark item 3 of #1669 done by hand" is
        // a thing to do, not an assertion that it is done. Caught on this hook's
        // FIRST live run against the real handoff, which is why the carve-out is
        // here rather than found later by somebody switching the hook off. A
        // gate that cries wolf gets disabled, and a disabled gate is worse than
        // no gate, because everyone still believes it is watching.
        if (/\b(mark|close|closes|file|resolve|check|verify|update|leave)\b[^.]{0,40}$/i.test(preceding)) continue;

        // "#1669 item 3 is done" is a claim about a PART. gh only knows the
        // whole issue, so it cannot adjudicate that and must not pretend to.
        if (/\bitem\s+\d/i.test(between) || /\bitem\s+\d/i.test(preceding)) continue;

        if (!out.has(n)) out.set(n, word);
    }
    return out;
}

function main() {
    const event = readInput();
    if (!event) allow();

    // The re-entry guard. Without it a block would loop forever.
    if (event.stop_hook_active === true) allow();

    const cwd = event.cwd || process.env.CLAUDE_PROJECT_DIR || process.cwd();
    const contextPath = path.join(cwd, CONTEXT_REL);
    if (!fs.existsSync(contextPath)) allow();

    if (!turnWroteContext(event.transcript_path)) allow();

    let text;
    try {
        text = fs.readFileSync(contextPath, 'utf8');
    } catch {
        allow();
    }

    const problems = [];

    // ---- Check 1: does the file name a commit that is not where main is? ----
    let head = null;
    try {
        head = git(['rev-parse', 'origin/main'], cwd);
    } catch {
        head = null; // No git, or no remote ref. Fail open.
    }

    if (head) {
        const shortHead = head.slice(0, 8);
        for (const { subject, sha } of claimedShas(text)) {
            // Production is not knowable from here without an ssh, which is far
            // too slow for a Stop hook. The handoff's own rule covers it.
            if (subject === 'production' || subject === 'prod') continue;
            if (head.startsWith(sha) || sha.startsWith(shortHead)) continue;

            let known = false;
            try {
                git(['cat-file', '-e', `${sha}^{commit}`], cwd);
                known = true;
            } catch {
                known = false;
            }
            problems.push(
                known
                    ? `it says ${subject} is at ${sha}; origin/main is at ${shortHead}`
                    : `it names ${sha} for ${subject}, which is not a commit in this repository`
            );
        }
    }

    // ---- Check 2: do the issue-state claims match gh? ----
    const claims = claimedIssueStates(text);
    if (claims.size > 0 && claims.size <= 25) {
        for (const [num, word] of claims) {
            let state = null;
            try {
                // Windows cannot exec a .cmd or .bat directly, and on this
                // machine `gh` may be either. `num` is `\d{2,5}` straight out of
                // the regex above, so there is nothing here a shell could
                // reinterpret; the guard is narrowed to those extensions anyway
                // rather than left on for every platform.
                const needsShell = process.platform === 'win32' && /\.(cmd|bat)$/i.test(GH_BIN);
                state = execFileSync(GH_BIN, ['issue', 'view', num, '--json', 'state', '--jq', '.state'], {
                    cwd, encoding: 'utf8', timeout: 20000, stdio: ['ignore', 'pipe', 'ignore'],
                    shell: needsShell,
                }).trim().toUpperCase();
            } catch {
                continue; // gh missing, offline, or not an issue. Fail open.
            }
            if (!state) continue;

            const saysClosed = ['merged', 'closed', 'shipped', 'done', 'landed'].includes(word);
            if (saysClosed && state === 'OPEN') {
                problems.push(`it calls #${num} "${word}"; gh says OPEN`);
            } else if (!saysClosed && state === 'CLOSED') {
                problems.push(`it calls #${num} "${word}"; gh says CLOSED`);
            }
        }
    }

    if (problems.length > 0) block(problems);
    allow();
}

try {
    main();
} catch {
    // Never wedge a session on this hook's own bug.
    allow();
}
