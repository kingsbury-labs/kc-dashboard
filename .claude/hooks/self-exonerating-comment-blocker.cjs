#!/usr/bin/env node
/**
 * Self-Exonerating Comment Blocker
 * Refuses a NEW code comment that excuses an imprecision instead of fixing it,
 * unless the same edit names the evidence.
 *
 * Event: PreToolUse
 * Tool: Edit | Write
 *
 * ## Why this is a control and not a fourth paragraph of prose
 *
 * Trap 237 says a docblock can be the false thing, and the more carefully it
 * argues the more it is believed. It is written down. It is quoted in
 * `OverdueAgingReport`. It did not stop three more instances in ONE session
 * (s336), all of them mine, all written after I had read it:
 *
 *   1. `OverdueAgingReport` - "The cast is the whole guard". Infection then
 *      showed the cast was an equivalent mutant: removing it changes nothing,
 *      and PHPStan passes without it. The comment asserted load-bearingness
 *      that measurement contradicted.
 *   2. `UnpaidDuesMembersTool` - the mixed-currency check summed each group's
 *      `count(DISTINCT currency)` and the comment called over-counting "the
 *      safe direction: it can only cause a refusal where a total would have
 *      been correct". One CAD group plus one CAD group is two, so it refused
 *      every organization with a single opted-out debtor. The excuse was
 *      written in the same commit as the bug.
 *   3. `UnpaidDuesMembersTool` - "This one pages at 200 rows, so it cannot
 *      know" whether a tenant is mixed-currency. It could know. It had not
 *      asked. The paragraph called it a known limit, which made unfinished
 *      work read as considered.
 *
 * CLAUDE.md: a mistake that happens twice in one session becomes a hook before
 * that session ends. This is that hook, one session late.
 *
 * ## What it actually catches, and what it cannot
 *
 * It is a LEXICAL detector, not a verifier. Nothing can mechanically decide
 * whether a comment matches the code beside it. What it can decide is whether
 * a comment is doing a specific rhetorical job: pre-emptively arguing that a
 * known imprecision is acceptable, or that a line is load-bearing.
 *
 * That is the shape all three instances share, and it is the shape the author
 * writes when they can feel a gap and are talking themselves past it.
 *
 * It would have caught (2) and (3) outright. It would NOT have caught (1):
 * "the cast is the whole guard" reads as a factual claim, not an excuse, and
 * catching that needs Infection's escaped-mutant list cross-referenced against
 * load-bearing claims. That is a bigger build and is not attempted here. This
 * hook is honest about covering two thirds of one session's instances.
 *
 * ## The escape hatch is evidence, not a flag
 *
 * A blocked phrase is allowed when the same edit names something checkable
 * within a few lines: a test method, a test file, an issue number, a measured
 * figure, or an explicit "measured"/"verified" claim. That is the whole point.
 * "The safe direction" with nothing behind it is an assertion; the same
 * sentence beside `asserted by test_two_currencies_are_refused` is a pointer.
 *
 * There is deliberately NO bypass parameter. If the phrase is genuinely right
 * and genuinely unverifiable, rewrite it to say what is unknown rather than
 * why it is fine - which is the behaviour this exists to produce.
 *
 * Tests: node --test .claude/hooks/self-exonerating-comment-blocker.test.cjs
 */

const { readStdin, parseHookEvent, allowAndExit, blockAndExit } = require('./hook-utils.cjs');

/** Only source files carry the comments this is about. */
const SOURCE_EXTENSIONS = ['.php', '.js', '.mjs', '.cjs', '.ts', '.tsx', '.jsx'];

/**
 * The phrases that do the exonerating.
 *
 * Every one is drawn from a real incident or is the direct paraphrase a writer
 * reaches for next. They are matched only inside comment lines, so ordinary
 * prose in a Markdown file or a string literal in code is not this hook's
 * business.
 *
 * KEPT NARROW ON PURPOSE. A broad list would fire on honest writing - "this is
 * safe because X" is often exactly right - and a hook that cries wolf gets
 * routed around, which is worse than no hook. Each entry here is a phrase whose
 * job is to make a gap feel considered.
 */
const EXCUSES = [
    // `(?<!fail-)` because `the fail-safe direction is "no AI"` is the OPPOSITE
    // rhetorical move: it names the direction a failure should take, which is a
    // design statement, not a defence. It was the first false positive the
    // whole-tree sweep found.
    { pattern: /(?<!fail-)\bsafe direction\b/i, why: 'calls a known imprecision acceptable' },
    { pattern: /\bcan only cause\b/i, why: 'bounds a defect by assertion' },
    { pattern: /\bworst case is\b/i, why: 'bounds a defect by assertion' },
    { pattern: /\bcannot know\b/i, why: 'claims an unimplemented capability is impossible' },
    { pattern: /\bno way to know\b/i, why: 'claims an unimplemented capability is impossible' },
    { pattern: /\bis the whole guard\b/i, why: 'asserts a line is load-bearing' },
    { pattern: /\bgood enough\b/i, why: 'calls a known imprecision acceptable' },
    { pattern: /\bshould never happen\b/i, why: 'asserts a state cannot occur' },
    { pattern: /\bin practice this (?:never|always)\b/i, why: 'asserts behaviour from expectation' },
    { pattern: /\bnot worth (?:testing|checking|verifying)\b/i, why: 'declines the check that would settle it' },
];

/**
 * A NEGATED excuse is a warning, and warnings are what this hook wants more of.
 *
 * "A negative total is not a harmless oddity" and "newlines here read as
 * harmless source formatting and are not" both carry a blocked phrase while
 * doing the opposite job: naming a hazard somebody might otherwise wave past.
 * Blocking those would train the author to delete the warning, which is the
 * precise inversion of the point.
 *
 * Checked in a small window each side of the phrase, because the negation can
 * lead ("not a harmless oddity") or trail ("read as harmless ... and are not").
 */
const NEGATED = /\b(?:not|never|isn'?t|aren'?t|wasn'?t|no)\b/i;
const NEGATION_WINDOW = 40;

/**
 * `harmless` is deliberately NOT on the excuse list either, and it was.
 *
 * It came from extrapolation rather than from an incident, and the whole-tree
 * sweep found five live uses against one true positive. Four of the five
 * described a hazard - "whichever default LOOKED harmless", "would LOOK
 * harmless, and would reintroduce the bug" - which is the writing this hook
 * exists to encourage. A word list built from what an excuse might sound like,
 * rather than from what excuses have actually said in this repo, is how a
 * control earns the reputation that gets it routed around.
 */

/**
 * `cannot tell` is deliberately NOT on the excuse list.
 *
 * The sweep found six live uses and every one was honest: an operator who
 * cannot tell two states apart, a rate limiter that cannot tell an attacker
 * from a user, a pseudonymizer that cannot tell a collision from its own
 * token. They describe real limits, which is behaviour to keep. The s336
 * incident said "cannot KNOW", which is the narrower claim about the code's own
 * capability, and that is the one on the list.
 */


/**
 * What lets an excuse through: a pointer to something somebody else can re-run.
 *
 * A test name, a test file, an issue reference, or an explicit claim of having
 * checked. The bar is "a reader can go and check", not "the author sounded
 * confident".
 *
 * A BARE QUANTITY IS NOT EVIDENCE, and that is not an oversight - it was in
 * this list until the test for s336 incident 2 failed. That comment read "this
 * one pages at 200 rows, so it cannot know", and `200 rows` cleared the block.
 * The number was part of the excuse, not support for it: a specific figure
 * inside a rationalization is what makes the rationalization persuasive, which
 * is the exact mechanism trap 237 describes. Numbers stay out; a number
 * alongside the word `measured` still passes, because that is a claim somebody
 * can dispute.
 */
const EVIDENCE = [
    /\btest_[a-z0-9_]+/i,                    // a PHPUnit method name
    /\b[A-Z][A-Za-z0-9]*Test\b/,             // a test class
    /\.test\.[cm]?js\b/i,                    // a JS test file
    /#\d{2,}/,                               // an issue number
    /\bmeasured\b/i,
    /\bverified\b/i,
    /\bcounted\b/i,
    /\bproven\b/i,
    /\breproduced\b/i,
];

/** How many lines around the match count as "the same thought". */
const EVIDENCE_RADIUS = 6;

/**
 * Is this line inside a comment?
 *
 * Deliberately crude: a `//`, `#`, `*` or `/*` lead-in after optional
 * whitespace. It does not parse strings, so a string literal CONTAINING `//`
 * followed by an excuse phrase would be inspected. That direction is fine - a
 * false positive costs one rewrite, and the phrases are ones nobody puts in a
 * user-facing string by accident.
 *
 * It does NOT try to track block-comment state across lines, because a docblock
 * body line starts with `*` anyway, which is the case that matters.
 *
 * @param {string} line
 * @returns {boolean}
 */
function isCommentLine(line) {
    return /^\s*(\/\/|\/\*|\*|#(?!\[))/.test(line);
}

/**
 * Find an excuse in the added text, with the surrounding lines checked for
 * evidence.
 *
 * @param {string} content - the new file content, or the new_string of an edit
 * @returns {{ blocked: boolean, phrase: string, why: string, line: string }}
 */
function inspect(content) {
    const lines = content.split('\n');

    for (let i = 0; i < lines.length; i++) {
        if (!isCommentLine(lines[i])) continue;

        for (const { pattern, why } of EXCUSES) {
            const found = lines[i].match(pattern);
            if (!found) continue;

            // A negation nearby turns the excuse into a warning. Checked
            // against the joined neighbouring lines, because a docblock wraps
            // and "and are not" often lands on the line after the phrase.
            const context = [lines[i - 1] ?? '', lines[i], lines[i + 1] ?? ''].join(' ');
            const at = context.indexOf(found[0]);
            const around = context.slice(
                Math.max(0, at - NEGATION_WINDOW),
                at + found[0].length + NEGATION_WINDOW,
            );
            if (NEGATED.test(around)) continue;

            // Evidence may sit a few lines either side: the claim and its
            // citation are usually adjacent sentences in one paragraph, not
            // the same line.
            const from = Math.max(0, i - EVIDENCE_RADIUS);
            const to = Math.min(lines.length, i + EVIDENCE_RADIUS + 1);
            const nearby = lines.slice(from, to).join('\n');

            if (EVIDENCE.some(e => e.test(nearby))) continue;

            return {
                blocked: true,
                phrase: found[0],
                why,
                line: lines[i].trim(),
            };
        }
    }

    return { blocked: false, phrase: '', why: '', line: '' };
}

/**
 * @param {string} filePath
 * @returns {boolean}
 */
function isSourceFile(filePath) {
    if (!filePath) return false;

    const lower = filePath.toLowerCase();

    // The hooks' own files are exempt. This one has to quote every phrase it
    // blocks, and a guard that cannot describe itself is a guard nobody can
    // maintain. Tests are exempt for the same reason: a test asserting the
    // block has to contain the phrase it asserts on.
    if (lower.includes('.claude\\hooks\\') || lower.includes('.claude/hooks/')) return false;

    return SOURCE_EXTENSIONS.some(ext => lower.endsWith(ext));
}

async function main() {
    const event = await readStdin();
    const { filePath, content } = parseHookEvent(event);

    if (!isSourceFile(filePath) || !content) allowAndExit();

    const result = inspect(content);
    if (!result.blocked) allowAndExit();

    blockAndExit(
        `Blocked: a new comment excuses an imprecision instead of resolving it.\n\n` +
        `Found: "${result.phrase}" - ${result.why}\n` +
        `  ${result.line}\n\n` +
        `Trap 237: a comment can be the false thing, and the more carefully it argues\n` +
        `the more it is believed. That trap is written down, is quoted in the aging\n` +
        `report, and did not stop three more instances in one session - every one\n` +
        `written by somebody who had just read it. Two of them were live defects the\n` +
        `comment made look considered:\n\n` +
        `  - "the safe direction: it can only cause a needless refusal" sat beside a\n` +
        `    check that refused every organization with an opted-out debtor.\n` +
        `  - "it cannot know whether a tenant is mixed-currency" was untrue; the tool\n` +
        `    could know, it had not asked.\n\n` +
        `What to do, in order of preference:\n\n` +
        `  1. FIX IT. An excuse is usually cheaper to write than the fix and more\n` +
        `     expensive to keep. Both incidents above were a few lines of SQL.\n` +
        `  2. MEASURE IT, and point at the measurement. A test method, a test class,\n` +
        `     an issue number, or the word measured/verified/counted/proven within\n` +
        `     ${EVIDENCE_RADIUS} lines clears this, because a reader can then go and check rather\n` +
        `     than take your word. A bare number does NOT clear it: "pages at 200\n` +
        `     rows, so it cannot know" is a figure supporting the excuse, not the\n` +
        `     claim, and that exact sentence is why.\n` +
        `  3. SAY WHAT IS UNKNOWN, not why it is fine. "Currency is collapsed across\n` +
        `     a mixed-currency tenant" is a fact somebody can act on. "This is the\n` +
        `     safe direction" is a conclusion that hides one.\n\n` +
        `There is no bypass parameter, deliberately. If the claim is right and truly\n` +
        `uncheckable, rewrite it as the limitation rather than as its defence.`
    );
}

// Guarded so the test file can require this module without main() parking on
// stdin forever.
if (require.main === module) {
    main().catch(err => {
        console.error(`self-exonerating-comment-blocker error: ${err.message}`);
        process.exit(2);
    });
}

module.exports = { inspect, isCommentLine, isSourceFile, EXCUSES, EVIDENCE };
