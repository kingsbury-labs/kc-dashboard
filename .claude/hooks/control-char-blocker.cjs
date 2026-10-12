#!/usr/bin/env node
/**
 * PreToolUse (Write|Edit): refuse to write a control character into source.
 *
 * WHAT THIS CATCHES, AND WHY IT IS A CONTROL. An escape sequence that passes
 * through a shell and then a scripting layer on the way into a file can arrive
 * as the character it denotes rather than as the two characters that spell it.
 * `\b` becomes 0x08. `\n` becomes a real line break. The file still parses, the
 * tests still pass, and the regex silently means something else.
 *
 * It has cost this repository real coverage:
 *
 *   - `StaticAnalysisCoversRequestReadingCodeTest`'s predicate ended
 *     `Request\s+\$request` followed by a literal 0x08. PCRE read it as a
 *     required backspace, so that alternative matched NOTHING from the day the
 *     file was written until 2026-09-03. Half a guard, dead, invisible outside
 *     a hex dump, and only found because a scripted edit kept failing to match
 *     the line it was trying to patch.
 *   - The same session then did it twice more within the hour: a literal
 *     newline where `printf '%s\n'` was meant, and a regex in this very hook's
 *     sibling mangled the same way.
 *
 * TRAP 209 WAS WRITTEN DOWN AFTER THE FIRST ONE AND DID NOT PREVENT THE OTHER
 * TWO. That is the entire argument for making it mechanical. A note in a traps
 * list is read alongside everything else and loses to whatever else is in
 * context; a PreToolUse hook does not get a vote.
 *
 * SCOPE. Text source files only, and only the characters that are never
 * legitimate in one: backspace, vertical tab, form feed, NUL, escape, and the
 * C1 range. TAB (0x09), LF (0x0A) and CR (0x0D) are untouched. Binary and
 * generated paths are skipped outright.
 *
 * FAILS CLOSED. Unlike the Stop hook beside it, there is no cost to being
 * strict: a legitimate control character in source is close to unheard of, and
 * a false block costs one retry while a false pass costs months of dead guard.
 *
 * Tests: node .claude/hooks/control-char-blocker.test.cjs
 */

const path = require('path');

/**
 * Characters that are never meant in a text source file.
 *
 * 0x08 backspace is the one that actually bit us. 0x0B, 0x0C, 0x00, 0x1B and
 * the C1 range are here because they arrive by the same accident and are just
 * as invisible. TAB, LF and CR are deliberately absent.
 */
const FORBIDDEN = /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/;

const NAMES = {
    0: 'NUL', 8: 'BACKSPACE (0x08)', 11: 'VERTICAL TAB', 12: 'FORM FEED',
    27: 'ESCAPE', 127: 'DELETE',
};

/** Paths where a stray byte is somebody else's problem, or expected. */
const SKIP = [
    /node_modules[\\/]/i, /vendor[\\/]/i, /\.git[\\/]/i,
    /\.(png|jpe?g|gif|ico|webp|mp[34]|wav|pdf|zip|gz|woff2?|ttf|eot|exe|dll|so)$/i,
    /-lock\.(json|yaml)$/i, /composer\.lock$/i,
];

function main(event) {
    if (event.hook_event_name !== 'PreToolUse') return allow();
    if (!['Write', 'Edit', 'NotebookEdit'].includes(event.tool_name)) return allow();

    const input = event.tool_input || {};
    const file = String(input.file_path || '');
    if (!file) return allow();
    if (SKIP.some((re) => re.test(file))) return allow();

    // Write carries `content`; Edit carries `new_string`. Both are checked, and
    // `old_string` deliberately is NOT: matching text that already contains a
    // stray byte is how you REMOVE one.
    const payloads = [input.content, input.new_string].filter((s) => typeof s === 'string');
    if (payloads.length === 0) return allow();

    for (const body of payloads) {
        const m = FORBIDDEN.exec(body);
        if (!m) continue;

        const code = m[0].charCodeAt(0);
        const name = NAMES[code] || `0x${code.toString(16).padStart(2, '0')}`;
        const line = body.slice(0, m.index).split('\n').length;
        const context = body.slice(Math.max(0, m.index - 45), m.index)
            .split('\n').pop()
            .replace(/[\x00-\x1F\x7F-\x9F]/g, '?');

        return block(
            `${name} at line ${line} of ${path.basename(file)}.\n\n`
            + `  ...${context}[HERE]\n\n`
            + `A control character in source is almost always an escape sequence that got\n`
            + `interpreted on the way in: \\b became 0x08, or \\n became a real line break.\n`
            + `The file will still parse and the tests will still pass, and the regex will\n`
            + `quietly mean something else. That exact byte silently disabled half of a\n`
            + `regex guard for months in the project this hook came from, and nothing but\n`
            + `a hex dump showed it.\n\n`
            + `Write this file with the editor rather than through a shell or a script, so\n`
            + `nothing gets a chance to reinterpret the backslash.`
        );
    }

    return allow();
}

function allow() {
    process.exit(0);
}

function block(reason) {
    process.stdout.write(JSON.stringify({
        hookSpecificOutput: {
            hookEventName: 'PreToolUse',
            permissionDecision: 'deny',
            permissionDecisionReason: reason,
        },
    }));
    process.exit(0);
}

let raw = '';
try {
    raw = require('fs').readFileSync(0, 'utf8');
} catch {
    allow();
}
try {
    main(JSON.parse(raw));
} catch {
    // A hook that cannot parse its own input must not wedge the session.
    allow();
}
