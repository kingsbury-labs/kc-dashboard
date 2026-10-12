#!/usr/bin/env node
/**
 * Branch Protection Hook
 * Prevents destructive git operations on protected branches.
 *
 * Event: PreToolUse
 * Tool: Bash
 *
 * Destructive operations (force-push, reset --hard, git clean -f and friends) are
 * blocked on protected branches, and deleting a protected branch is refused from
 * anywhere. Direct pushes to a protected branch are blocked only where the project
 * has opted into a PR-only workflow: see `hooks.config.json` and loadConfig().
 *
 * IT READS THE COMMAND'S STRUCTURE, NOT ITS TEXT (#1676). The first version decided
 * which branch a delete targeted by splitting the WHOLE command string on whitespace
 * and asking whether any protected name appeared anywhere in it. It never checked that
 * the name was an argument to the delete, so it refused safe work and permitted unsafe
 * work at the same time - the combination that teaches a person to route around a
 * guard, at which point the protection is decorative.
 *
 * Refused wrongly: `git checkout main && git branch -D feature/x`, where `main` is a
 * token from the unrelated checkout half. Also any non-git command whose TEXT happened
 * to contain the words, which is how filing #1676 was itself blocked.
 *
 * Permitted wrongly: `git branch -D "main"`, because the token carried its quotes and
 * never equalled `main`. Also `git push origin :production`, the older colon-refspec
 * delete, which the remote arm never examined because it only looked for `--delete`.
 * Both were verified deleting real branches in a throwaway repository.
 *
 * #1676 also claimed a protected branch could be deleted through its full ref path.
 * It cannot - git refuses `git branch -D refs/heads/main` with "branch not found" -
 * so that symptom was not real. The ref path is still normalized below, cheaply,
 * rather than relying on that staying true.
 *
 * Tests: node --test .claude/hooks/branch-protection.test.cjs
 */

const fs = require('fs');
const path = require('path');
const { readStdin, allowAndExit, blockAndExit } = require('./hook-utils.cjs');

/**
 * Two projects can disagree about pushing to main and both be right.
 *
 * A site with a staging branch and a review step wants PR-only. A three-page
 * brochure build with one person on it does not, and turning that into a
 * blocked tool call on day one teaches whoever hits it that this hook is
 * noise - which is how the destructive-operation half, the part that actually
 * matters, ends up switched off with it.
 *
 * So the destructive patterns and the protected-branch deletes below are
 * unconditional, and only the direct-push rule reads config. A missing or
 * malformed file means the defaults: protect the usual branch names, allow
 * direct pushes.
 */
const DEFAULT_CONFIG = {
    protectedBranches: ['main', 'master', 'production'],
    prOnly: false,
    projectName: 'This project'
};

function loadConfig() {
    let parsed;
    try {
        parsed = JSON.parse(fs.readFileSync(path.join(__dirname, 'hooks.config.json'), 'utf8'));
    } catch {
        return DEFAULT_CONFIG;
    }
    const branches = Array.isArray(parsed.protectedBranches)
        ? parsed.protectedBranches.filter((b) => typeof b === 'string' && b.trim())
        : null;
    return {
        protectedBranches: branches && branches.length ? branches : DEFAULT_CONFIG.protectedBranches,
        prOnly: parsed.prOnly === true,
        projectName: typeof parsed.projectName === 'string' && parsed.projectName.trim()
            ? parsed.projectName.trim()
            : DEFAULT_CONFIG.projectName
    };
}

const CONFIG = loadConfig();
const PROTECTED_BRANCHES = CONFIG.protectedBranches;

const DANGEROUS_PATTERNS = [
    /git\s+push\s+.*--force(?!-with-lease)/i,
    /git\s+push\s+-f\b/i,
    /git\s+reset\s+--hard/i,
    /git\s+checkout\s+--?\s*\./i,
    /git\s+clean\s+-f/i,
    /git\s+restore\s+\./i,
];

// Any plain `git push` initiated while currently on a protected branch.
// Catches: `git push`, `git push origin`, `git push origin main`, `git push -u origin main`.
// Does NOT catch pushes from a feature branch — those are fine.
const PROTECTED_BRANCH_PUSH = /^\s*git\s+push(?!\s+.*--delete)/i;

const LOCAL_DELETE_FLAGS = new Set(['-d', '-D', '--delete']);
const REMOTE_DELETE_FLAGS = new Set(['-d', '--delete']);

/**
 * Split a command into shell segments and each segment into tokens, respecting quotes.
 *
 * Quote awareness is what separates this from a `.split()`, and it is load-bearing in
 * both directions. Splitting blind would strip nothing, so `"main"` stays unequal to
 * `main` and the delete sails through. Splitting on separators blind would find a
 * fragment beginning `git branch -D main` inside the quoted body of a `gh issue`
 * command and refuse work that touches no branch at all.
 *
 * A HEREDOC BODY IS DATA, NOT COMMANDS, and skipping it is not a nicety. The first
 * version of this fix blocked the `gh pr create` that was opening the pull request for
 * the fix itself: the body documented the bug, so it contained the words, and a line
 * of it began with `git branch -D`. That is the same defect the whole rewrite exists
 * to remove, one level further down - deciding from text that merely looks like a
 * command - so it is fixed the same way rather than worked around.
 *
 * A token is marked unresolvable when it contains a construct whose value cannot be
 * known without running it. Single quotes suppress expansion in the shell, so a `$`
 * inside them is literal text and does not set the flag.
 *
 * @returns {{value: string, unresolvable: boolean}[][]} segments of tokens
 */
function lex(command) {
    const segments = [];
    const pendingHeredocs = [];
    let tokens = [];
    let cur = null;
    let quote = null;
    let i = 0;

    const begin = () => (cur = cur || { value: '', unresolvable: false });
    const endToken = () => {
        if (cur) tokens.push(cur);
        cur = null;
    };
    const endSegment = () => {
        endToken();
        if (tokens.length) segments.push(tokens);
        tokens = [];
    };

    while (i < command.length) {
        const c = command[i];

        if (quote) {
            if (c === quote) {
                quote = null;
                i++;
                continue;
            }
            begin();
            // Inside double quotes the shell still expands; inside single quotes it does not.
            if (quote === '"' && (c === '$' || c === '`')) cur.unresolvable = true;
            cur.value += c;
            i++;
            continue;
        }

        if (c === "'" || c === '"') {
            quote = c;
            begin();
            i++;
            continue;
        }

        if (/\s/.test(c) && c !== '\n') {
            endToken();
            i++;
            continue;
        }

        const two = command.substr(i, 2);

        // A heredoc redirection: remember the delimiter so its body can be skipped
        // when the line ends. `<<<` is a herestring and takes no body.
        if (two === '<<' && command[i + 2] !== '<') {
            let j = i + 2;
            if (command[j] === '-') j++;
            while (/[ \t]/.test(command[j])) j++;
            const delimQuote = command[j] === "'" || command[j] === '"' ? command[j] : null;
            if (delimQuote) j++;
            let delim = '';
            while (
                j < command.length &&
                (delimQuote ? command[j] !== delimQuote : /[A-Za-z0-9_]/.test(command[j]))
            ) {
                delim += command[j];
                j++;
            }
            if (delimQuote) j++;
            if (delim) pendingHeredocs.push(delim);
            endToken();
            i = j;
            continue;
        }

        if (two === '&&' || two === '||') {
            endSegment();
            i += 2;
            continue;
        }
        if (c === ';' || c === '|' || c === '\n') {
            endSegment();
            i++;

            // Everything up to each pending delimiter is the heredoc's payload.
            while (pendingHeredocs.length) {
                const delim = pendingHeredocs.shift();
                while (i < command.length) {
                    let lineEnd = command.indexOf('\n', i);
                    if (lineEnd === -1) lineEnd = command.length;
                    const line = command.slice(i, lineEnd);
                    i = lineEnd + 1;
                    if (line.trim() === delim) break;
                }
            }
            continue;
        }

        begin();
        if (c === '$' || c === '`') cur.unresolvable = true;
        cur.value += c;
        i++;
    }

    endSegment();
    return segments;
}

/** A full ref path is not accepted by `git branch -D` today, but normalizing is free. */
function shortName(value) {
    return value.replace(/^refs\/heads\//, '');
}

function isProtected(value) {
    return PROTECTED_BRANCHES.includes(shortName(value));
}

/** Segments that are actually a git invocation, so command TEXT is never matched. */
function gitSegments(command) {
    return lex(command).filter((tokens) => tokens.length >= 2 && tokens[0].value === 'git');
}

function segmentText(tokens) {
    return tokens.map((t) => t.value).join(' ');
}

/**
 * Split a git subcommand's arguments into the flags used and the positional operands,
 * honouring `--` as end-of-options so `git branch -D -- main` is read correctly.
 */
function parseArgs(tokens) {
    const flags = new Set();
    const operands = [];
    let endOfOptions = false;

    for (const token of tokens.slice(2)) {
        if (!endOfOptions && token.value === '--') {
            endOfOptions = true;
            continue;
        }
        if (!endOfOptions && token.value.startsWith('-') && token.value.length > 1) {
            flags.add(token.value);
            continue;
        }
        operands.push(token);
    }

    return { flags, operands };
}

/** `git branch -d/-D/--delete <names...>` */
function checkLocalDelete(tokens) {
    if (tokens[1].value !== 'branch') return;

    const { flags, operands } = parseArgs(tokens);
    if (![...flags].some((f) => LOCAL_DELETE_FLAGS.has(f))) return;

    for (const operand of operands) {
        if (operand.unresolvable) {
            blockAndExit(
                `Blocked: cannot tell which branch this delete targets.\n` +
                `Command: ${segmentText(tokens)}\n\n` +
                `The branch name is built by the shell, so its value is not knowable ` +
                `without running the command. Spell the branch out literally and re-run.`
            );
        }
        if (isProtected(operand.value)) {
            blockAndExit(
                `Blocked: cannot delete protected local branch '${shortName(operand.value)}'.`
            );
        }
    }
}

/**
 * `git push <remote> --delete <names...>` and the older `git push <remote> :<name>`.
 *
 * Only an EMPTY source side means delete: `feature/x:main` is an ordinary push and
 * refusing it would block real work.
 */
function checkRemoteDelete(tokens) {
    if (tokens[1].value !== 'push') return;

    const { flags, operands } = parseArgs(tokens);
    const viaFlag = [...flags].some((f) => REMOTE_DELETE_FLAGS.has(f));

    // The first operand is the remote; the rest are refspecs or branch names.
    const refspecs = operands.slice(1);

    for (const operand of refspecs) {
        const isColonDelete = operand.value.startsWith(':');
        if (!viaFlag && !isColonDelete) continue;

        if (operand.unresolvable) {
            blockAndExit(
                `Blocked: cannot tell which branch this remote delete targets.\n` +
                `Command: ${segmentText(tokens)}\n\n` +
                `Spell the branch out literally and re-run.`
            );
        }

        const target = isColonDelete ? operand.value.slice(1) : operand.value;
        if (isProtected(target)) {
            blockAndExit(
                `Blocked: cannot delete protected branch '${shortName(target)}' from remote.`
            );
        }
    }
}

async function main() {
    const event = await readStdin({ failClosed: true });
    const command = event.tool_input?.command || '';

    const { execSync } = require('child_process');
    let currentBranch;
    try {
        currentBranch = execSync('git branch --show-current', {
            encoding: 'utf8',
            timeout: 5000
        }).trim();
    } catch {
        allowAndExit();
    }

    const segments = gitSegments(command);
    const isProtectedBranch = PROTECTED_BRANCHES.includes(currentBranch);

    // Block destructive operations on protected branches.
    if (isProtectedBranch) {
        for (const tokens of segments) {
            const text = segmentText(tokens);

            for (const pattern of DANGEROUS_PATTERNS) {
                if (pattern.test(text)) {
                    blockAndExit(
                        `Blocked: Dangerous operation on protected branch '${currentBranch}'.\n` +
                        `Command: ${text}\n\n` +
                        `Force-push, reset --hard, and other destructive operations are not allowed on '${currentBranch}'.`
                    );
                }
            }

            // Block plain pushes from protected branches, where the project is PR-only.
            if (CONFIG.prOnly && PROTECTED_BRANCH_PUSH.test(text)) {
                blockAndExit(
                    `Blocked: Direct push from protected branch '${currentBranch}'.\n` +
                    `Command: ${text}\n\n` +
                    `${CONFIG.projectName} uses a PR-only workflow. Branch off, push the feature ` +
                    `branch, and merge via PR (gh pr merge).\n\n` +
                    `If that is no longer the workflow, set "prOnly": false in ` +
                    `.claude/hooks/hooks.config.json rather than routing around this.`
                );
            }
        }
    }

    // Deletes of a protected branch are refused from ANY current branch.
    for (const tokens of segments) {
        checkRemoteDelete(tokens);
        checkLocalDelete(tokens);
    }

    allowAndExit();
}

main().catch(err => {
    console.error(`branch-protection error: ${err.message}`);
    process.exit(2);
});
