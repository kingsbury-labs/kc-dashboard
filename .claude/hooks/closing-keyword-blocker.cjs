#!/usr/bin/env node
/**
 * Closing Keyword Blocker
 * Refuses a GitHub closing keyword sitting next to an issue number.
 *
 * Event: PreToolUse
 * Tool: Bash
 *
 * CLAUDE.md says never use a closing keyword plus an issue number, so that a
 * partial pull request cannot close a live issue. That rule has now been broken
 * four times, and every fix was another sentence telling the next session to
 * remember harder:
 *
 *   s151  three PRs titled `Fix #948 (partial)` closed three cluster issues
 *         that still had deferred sub-items.
 *   s238  a body ending "Does not close #1012 with a keyword" closed #1012.
 *   s260  the title and body were both checked and clean; a squash merge
 *         concatenates every COMMIT message into the merge commit, and one of
 *         those ended "Does not close #1629".
 *   s286  a marketing PR body ending "Does not close #190" closed #190.
 *
 * The parser does not read negation, and it does not care that the sentence
 * around the keyword says the opposite. Three memories describe this trap
 * accurately and it happened again anyway, which is the argument for a control
 * rather than a fourth paragraph of prose.
 *
 * WHAT IT INSPECTS. Only the commands that can produce merge-commit text:
 * `gh pr create`, `gh pr edit`, and `git commit`. An issue body cannot close
 * anything, so `gh issue create` is left alone. It reads `--title`, `--body`
 * and `-m` values, and it OPENS `--body-file` / `-F`, because CLAUDE.md
 * mandates a body file for anything containing backticks, which is exactly
 * where a long body ends up.
 *
 * IT BLOCKS THE ADJACENCY, NOT THE INTENT, including inside a quotation of the
 * rule itself. That is deliberate. Every one of the four incidents was written
 * by someone who believed the surrounding words made it safe, so a hook that
 * tried to tell a real keyword from a discussed one would fail on the only
 * cases that have ever mattered. Breaking the adjacency costs one word.
 *
 * Tests: node --test .claude/hooks/closing-keyword-blocker.test.cjs
 */

const fs = require('fs');
const path = require('path');
const { readStdin, allowAndExit, blockAndExit } = require('./hook-utils.cjs');

// GitHub's documented keywords, all tenses. The reference may be `#12`,
// `GH-12`, `owner/repo#12`, or a full issue URL.
//
// THE GAP IS DELIBERATE, AND IT IS WHY THIS MATCHES MORE THAN GitHub DOCUMENTS.
// The documented syntax is the keyword immediately before the reference, and an
// adjacency-only pattern let "Closes out item 1 of #1737" through - which is the
// exact body that closed #1737 on merge. Confusingly, the same phrasing on
// another PR the same night did NOT close its issue, so the real behaviour is
// not something to predict. It is something to stay away from.
//
// So: a keyword and a reference in the SAME SENTENCE is refused. The window
// stops at sentence-ending punctuation and at a line break, so a keyword in one
// sentence and a reference two sentences later is left alone.
const CLOSING_KEYWORD = /\b(close[sd]?|fix(?:e[sd])?|resolve[sd]?)\b[^.!?\n]{0,60}?(?:(?:[\w.-]+\/[\w.-]+)?#\d+|GH-\d+|https?:\/\/github\.com\/[\w.-]+\/[\w.-]+\/issues\/\d+)/i;

/** Commands whose text can reach a merge commit. */
const INSPECTED = [
    /\bgh\s+pr\s+(create|edit)\b/i,
    /\bgit\s+commit\b/i,
];

/**
 * Split a command on shell separators so a `gh pr create` in one segment is
 * not judged by the text of an unrelated segment beside it.
 *
 * @param {string} command
 * @returns {string[]}
 */
function segments(command) {
    // NOT on newlines. A newline is a shell separator, but it is also what sits
    // inside every heredoc and every multi-line quoted body, and splitting there
    // tore the body away from the `gh pr create` that carried it - so the one
    // shape CLAUDE.md mandates for a body containing backticks was the one shape
    // this could not see. The cost is that an unrelated command on the NEXT LINE
    // is judged alongside the PR command, which is a false positive worth taking.
    return command.split(/(?:&&|\|\||;)/g);
}

/**
 * Pull the values of the flags that carry prose, plus the paths of any file
 * those flags point at.
 *
 * Quoting is handled loosely on purpose: a value that this misses is a value
 * the whole-segment fallback below still sees.
 *
 * @param {string} segment
 * @returns {{ inline: string[], files: string[] }}
 */
function extractText(segment) {
    const inline = [];
    const files = [];

    const flagValue = /(--title|--body|-m|--message|--body-file|-F|--file)[\s=]+("([^"]*)"|'([^']*)'|(\S+))/g;
    let match;

    while ((match = flagValue.exec(segment)) !== null) {
        const flag = match[1];
        const value = match[3] ?? match[4] ?? match[5] ?? '';

        if (flag === '--body-file' || flag === '-F' || flag === '--file') {
            if (value && value !== '-') files.push(value);
        } else {
            inline.push(value);
        }
    }

    return { inline, files };
}

/**
 * Read a body file if it is there. A missing file is not this hook's problem;
 * the command itself will fail on it and say so more clearly than we would.
 *
 * @param {string} file
 * @param {string} cwd
 * @returns {string}
 */
function readIfPresent(file, cwd) {
    try {
        const resolved = path.isAbsolute(file) ? file : path.join(cwd, file);
        if (!fs.existsSync(resolved)) return '';
        if (fs.statSync(resolved).size > 512 * 1024) return '';

        return fs.readFileSync(resolved, 'utf8');
    } catch {
        return '';
    }
}

/**
 * @param {string} command
 * @param {string} cwd
 * @returns {{ blocked: boolean, sample: string, where: string }}
 */
function inspect(command, cwd) {
    for (const segment of segments(command)) {
        if (!INSPECTED.some(pattern => pattern.test(segment))) continue;

        const { inline, files } = extractText(segment);

        for (const value of inline) {
            const found = value.match(CLOSING_KEYWORD);
            if (found) return { blocked: true, sample: found[0], where: 'the command' };
        }

        for (const file of files) {
            const found = readIfPresent(file, cwd).match(CLOSING_KEYWORD);
            if (found) return { blocked: true, sample: found[0], where: file };
        }

        // Whole-segment fallback, for a heredoc or a quoting shape the flag
        // parser above did not recognise. It is why a miss here is unlikely
        // rather than merely uncommon.
        const found = segment.match(CLOSING_KEYWORD);
        if (found) return { blocked: true, sample: found[0], where: 'the command' };
    }

    return { blocked: false, sample: '', where: '' };
}

async function main() {
    const event = await readStdin();
    const command = event.tool_input?.command || '';
    if (!command) allowAndExit();

    const cwd = event.cwd || process.cwd();
    const result = inspect(command, cwd);

    if (!result.blocked) allowAndExit();

    blockAndExit(
        `Blocked: a GitHub closing keyword is sitting next to an issue number in ${result.where}.\n\n` +
        `Found: "${result.sample}"\n\n` +
        `GitHub closes the issue on merge from the PR title, the PR body, AND every\n` +
        `commit message a squash merge concatenates. The parser does not read the\n` +
        `sentence around it, so "Does not close #12" closes #12. That has happened\n` +
        `four times on this project.\n\n` +
        `A keyword and an issue reference in the SAME SENTENCE is refused, which is\n` +
        `broader than GitHub documents, because "Closes out item 1 of #1737" closed\n` +
        `#1737 while the identical phrasing elsewhere did not. That is not behaviour\n` +
        `worth predicting.\n\n` +
        `What to do instead:\n` +
        `  - "Part of #12" or "Addresses item 1 of #12" for a PR that advances one.\n` +
        `  - Break the adjacency if you need to name the rule: "no closing keyword\n` +
        `    is used for issue 12".\n` +
        `  - Close it by hand after merge, with gh issue close, which is where the\n` +
        `    rationale gets written down anyway.\n\n` +
        `This blocks the adjacency even inside a quotation of the rule, because every\n` +
        `one of the four incidents was written by someone who thought the surrounding\n` +
        `words made it safe.`
    );
}

// Guarded so the test file can require this module without main() parking on
// stdin forever. branch-protection.cjs calls main() unconditionally and gets
// away with it because its tests spawn the hook as a subprocess; the first
// version of these tests required it instead and hung until the runner was
// killed. The guard makes both styles safe.
if (require.main === module) {
    main().catch(err => {
        console.error(`closing-keyword-blocker error: ${err.message}`);
        process.exit(2);
    });
}

module.exports = { inspect, CLOSING_KEYWORD };
