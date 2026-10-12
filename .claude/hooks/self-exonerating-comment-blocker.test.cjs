/**
 * Tests: node --test .claude/hooks/self-exonerating-comment-blocker.test.cjs
 *
 * The first two cases are the real s336 comments, quoted verbatim from the
 * commits that carried them. Both sat beside live defects. Both were written
 * after reading trap 237, which is the argument for a control.
 *
 * The third real instance from that session, "The cast is the whole guard", is
 * here as a case this hook catches only by its phrasing - see the note on it.
 */

const test = require('node:test');
const assert = require('node:assert');

const { inspect, isCommentLine, isSourceFile } = require('./self-exonerating-comment-blocker.cjs');

const PHP = 'app/Services/AskSembr/Tools/UnpaidDuesMembersTool.php';

function verdict(content) {
    return inspect(content);
}

function blocked(content) {
    return inspect(content).blocked;
}

// ---------------------------------------------------------------- real cases

test('s336 incident 1: "the safe direction", beside a check that refused every tenant with an opted-out debtor', () => {
    const real = `    /**
     * How many currencies the whole matched set spans.
     *
     * Summing each half's \`count(DISTINCT ...)\` would over-count a currency both
     * halves share, and that is the safe direction: it can only cause a refusal
     * where a total would have been correct, never a total where a refusal was
     * needed. Anything sharper needs a second query for a case no tenant has yet.
     */
    private function distinctCurrencies(array $totals): int
    {
        return array_sum(array_map(static fn (array $r): int => (int) $r['currency_count'], $totals));
    }`;

    assert.strictEqual(blocked(real), true);
    assert.match(verdict(real).phrase, /safe direction/i);
});

test('s336 incident 2: "it cannot know", which was untrue - the tool had not asked', () => {
    const real = `    /**
     * The currency to render in.
     *
     * A mixed-currency tenant would be mis-stated here rather than refused, unlike
     * \`dues_collection_summary\` which can see the whole set in one aggregate. This
     * one pages at 200 rows, so it cannot know. Left as the known limit the
     * conformance suite records rather than papered over.
     */
    private function currencyOf(array $rows): ?string
    {
        return is_string($rows[0]['currency'] ?? null) ? $rows[0]['currency'] : null;
    }`;

    assert.strictEqual(blocked(real), true);
    assert.match(verdict(real).phrase, /cannot know/i);
});

test('s336 incident 3: "is the whole guard" is caught by phrasing, though the general case needs Infection', () => {
    // Honest scope note: this fires because the exact phrase is on the list.
    // A DIFFERENT wording of the same false claim - "the cast keeps this an
    // int", say - would pass, because deciding whether a line is load-bearing
    // needs the escaped-mutant report, not a word list. The hook's docblock
    // says so rather than implying coverage it does not have.
    const real = `        // The cast is the whole guard: Postgres hands a bigint arithmetic
        // result back as a string.
        $cents = (int) $invoice->getAttribute('outstanding_cents');`;

    assert.strictEqual(blocked(real), true);
});

// -------------------------------------------------------------- the way out

test('naming a test method clears it, because a reader can go and re-run that', () => {
    const withEvidence = `    /**
     * Summing each half's count would over-count a shared currency, so this
     * compares min and max instead. That is the safe direction here, and it is
     * asserted by test_two_currencies_are_refused_and_one_currency_still_answers.
     */`;

    assert.strictEqual(blocked(withEvidence), false);
});

test('a claim of measurement clears it', () => {
    const withEvidence = `        // The join is a LEFT JOIN rather than a per-row ledger call: measured at
        // 4,200 invoices on load01, the correlated form cost 3,900 ms and this
        // one 41 ms. Worst case is one extra sequential scan.`;

    assert.strictEqual(blocked(withEvidence), false);
});

test('a bare quantity is NOT evidence, because the number can be part of the excuse', () => {
    // This is s336 incident 2 with its own figure, which is exactly how it
    // slipped past the first version of this hook: "200 rows" read as a
    // measurement while being the premise of the rationalization.
    const laundered = `        // This one pages at 200 rows, so it cannot know.`;

    assert.strictEqual(blocked(laundered), true);
});

test('an issue number clears it', () => {
    const withEvidence = `        // Currency is collapsed across a mixed-currency tenant. Harmless for
        // every tenant today, all of which bill in one currency; #2161 records
        // the decision and what would change it.`;

    assert.strictEqual(blocked(withEvidence), false);
});

test('evidence six lines away still counts as the same thought', () => {
    const withEvidence = `        // This is the safe direction.
        //
        //
        //
        //
        //
        // Verified against the fixture.`;

    assert.strictEqual(blocked(withEvidence), false);
});

test('evidence far away does NOT count', () => {
    const tooFar = `        // This is the safe direction.
        //
        //
        //
        //
        //
        //
        //
        // Verified against the fixture.`;

    assert.strictEqual(blocked(tooFar), true);
});

// ------------------------------------------------------------- no false fire
//
// Every case below is a real comment the whole-tree sweep flagged before the
// hook was tightened. 566 source files produced 17 hits, of which 16 were
// wrong. A control that fires wrongly gets routed around, so the list was cut
// back to phrases with a demonstrated incident behind them.

test('a negated excuse is a warning, and warnings are the point', () => {
    const warning = `        // Newlines here read as harmless source formatting and are not: HTML
        // collapses them, so the disclosure would render as one run-on line.`;

    assert.strictEqual(blocked(warning), false);
});

test('a negation leading the phrase also clears it', () => {
    const warning = `        // A negative total is not a harmless oddity: it means the composition
        // disagrees with its own line items.`;

    assert.strictEqual(blocked(warning), false);
});

test('"fail-safe direction" is a design statement, not a defence', () => {
    const design = `        // The fail-safe direction is "no AI", never "AI with missing context".`;

    assert.strictEqual(blocked(design), false);
});

test('"cannot tell" describes a real limit and is not on the list', () => {
    const honest = `        // A total cap cannot tell an attacker's traffic from a real member's,
        // which is why the limit is per address.`;

    assert.strictEqual(blocked(honest), false);
});


test('ordinary code with no comment is untouched', () => {
    assert.strictEqual(blocked(`        $owed = $outstanding->perInvoice('i', 'r');`), false);
});

test('a comment that states the limitation rather than defending it passes', () => {
    const honest = `        // Cents are added across currencies. A tenant billing in two is
        // mis-stated by this figure; the tool refuses that case upstream.`;

    assert.strictEqual(blocked(honest), false);
});

test('the phrase in a string literal on a non-comment line is not a comment', () => {
    const inCode = `        $summary = 'This organization bills in more than one currency.';`;

    assert.strictEqual(blocked(inCode), false);
});

test('a PHP 8 attribute is not a comment, despite the hash', () => {
    assert.strictEqual(isCommentLine('    #[PersonalData]'), false);
    assert.strictEqual(isCommentLine('    # a real shell-style comment'), true);
});

// ------------------------------------------------------------------- scoping

test('markdown is out of scope - prose is allowed to reason about safety', () => {
    assert.strictEqual(isSourceFile('.claude/context.md'), false);
    assert.strictEqual(isSourceFile('docs/planning/asksembr-tool-catalogue-plan.md'), false);
});

test('the hooks directory is exempt, or this file could not describe itself', () => {
    assert.strictEqual(isSourceFile('.claude/hooks/self-exonerating-comment-blocker.cjs'), false);
    assert.strictEqual(isSourceFile('.claude\\hooks\\self-exonerating-comment-blocker.test.cjs'), false);
});

test('php and js source files are in scope', () => {
    assert.strictEqual(isSourceFile(PHP), true);
    assert.strictEqual(isSourceFile('resources/js/portal/api.js'), true);
});

test('the blocked verdict names the phrase and the line, so the message is actionable', () => {
    const result = verdict(`        // Worst case is a double count.`);

    assert.strictEqual(result.blocked, true);
    assert.match(result.phrase, /worst case is/i);
    assert.match(result.line, /double count/);
    assert.ok(result.why.length > 0);
});
