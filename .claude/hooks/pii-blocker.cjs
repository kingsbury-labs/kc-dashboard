#!/usr/bin/env node
/**
 * PII Blocker Hook
 * Prevents accidental commit of personally identifiable information.
 *
 * Event: PreToolUse
 * Tool: Edit, Write
 *
 * Detects:
 * - Social Security Numbers (XXX-XX-XXXX)
 * - Credit card numbers (various formats, Luhn-validated)
 * - Phone numbers (US and international formats)
 * - Email addresses (real-looking, not @example.com)
 * - IBAN (International Bank Account Numbers)
 * - IP addresses (non-localhost, non-private)
 * - Date of birth patterns
 *
 * Public-safe values for THIS project (the client's own published phone number,
 * inbox and address) go in `pii-allowlist.json` beside this file, never in the
 * patterns below. See loadProjectAllowlist().
 */

const fs = require('fs');
const path = require('path');
const {
    readStdin,
    parseHookEvent,
    shouldSkipFile,
    isOutsideProject,
    hasNearbyContext,
    isAllowlisted,
    deduplicateFindings,
    formatFindings,
    allowAndExit,
    blockAndExit
} = require('./hook-utils.cjs');

const BASE_ALLOWLIST = [
    /@example\.(com|org|net)$/i,
    /@test\.(com|org|net)$/i,
    /^john\.doe@|^jane\.doe@|^test\.user@/i,
    /^555-\d{4}$/,
    /^123-45-6789$/,
    /^4111-?1111-?1111-?1111$/,
    /^4242-?4242-?4242-?4242$/,
    /^0000-?0000-?0000-?0000$/,
    // Cloudflare's own published edge IP ranges (cloudflare.com/ips-v4), used
    // as network-address literals in trusted_proxies-style infra config. These
    // name Cloudflare's infrastructure, never a person, and Cloudflare
    // publishes them specifically for others to hardcode.
    /^(?:173\.245\.48|103\.21\.244|103\.22\.200|103\.31\.4|141\.101\.64|108\.162\.192|190\.93\.240|188\.114\.96|197\.234\.240|198\.41\.128|162\.158\.0|104\.16\.0|104\.24\.0|172\.64\.0|131\.0\.72)\.0$/
];

/**
 * A client website is the one project type where real contact details are the
 * POINT. The business's phone number and public inbox belong on the contact
 * page, so a blanket refusal would be wrong here in a way it is not in an
 * application repository, and a hook that is wrong on ordinary work gets
 * switched off.
 *
 * The values therefore live in `pii-allowlist.json` beside this file rather
 * than in the detector. Editing data is a smaller act than editing a security
 * hook, it survives dropping in a newer copy of this file, and the allowlist
 * is reviewable on its own in a diff. Anything not in that file is still
 * refused, so a customer's address does not become writable because the
 * business's did.
 *
 * A malformed file is treated as an EMPTY allowlist, never as a reason to skip
 * the scan: a typo must cost a false block, never a silent pass.
 */
function loadProjectAllowlist() {
    const patterns = [];
    const digitPatterns = [];
    let raw;
    try {
        raw = fs.readFileSync(path.join(__dirname, 'pii-allowlist.json'), 'utf8');
    } catch {
        return { patterns, digitPatterns }; // No file is the normal case for a fresh project.
    }

    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (err) {
        console.error(`pii-blocker: pii-allowlist.json is not valid JSON (${err.message}). Treating it as empty.`);
        return { patterns, digitPatterns };
    }

    for (const literal of parsed.literals || []) {
        if (typeof literal !== 'string' || !literal.trim()) continue;
        patterns.push(new RegExp(`^${literal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'i'));

        // A phone number is never matched in the shape a person writes it. The
        // detector's regex starts at the first digit, so `(613) 555-0100` on the
        // page is compared as `613) 555-0100` and an exact literal never fires.
        // Whoever hit that would conclude the allowlist does not work, and the
        // next move after that is switching the hook off.
        //
        // So a literal carrying a phone-length run of digits also allows its
        // digits. That is not a widening: two strings with the same ten digits
        // are the same telephone number.
        const digits = literal.replace(/\D/g, '');
        if (digits.length >= 7 && digits.length <= 15) {
            digitPatterns.push(new RegExp(`^${digits}$`));
        }
    }

    for (const source of parsed.patterns || []) {
        if (typeof source !== 'string' || !source.trim()) continue;
        try {
            patterns.push(new RegExp(source, 'i'));
        } catch (err) {
            console.error(`pii-blocker: skipping unparseable allowlist pattern ${JSON.stringify(source)} (${err.message}).`);
        }
    }

    return { patterns, digitPatterns };
}

const PROJECT_ALLOWLIST = loadProjectAllowlist();
const ALLOWLIST_PATTERNS = [...BASE_ALLOWLIST, ...PROJECT_ALLOWLIST.patterns];
const ALLOWLIST_DIGIT_PATTERNS = PROJECT_ALLOWLIST.digitPatterns;

/**
 * Allowed either as written, or as the same digits written differently.
 *
 * The digit comparison is deliberately narrow: it applies only to values an
 * allowlist literal already named, never to the base patterns, so it cannot
 * turn a general rule into a wildcard.
 */
function matchIsAllowlisted(match) {
    if (isAllowlisted(match, ALLOWLIST_PATTERNS)) return true;
    if (ALLOWLIST_DIGIT_PATTERNS.length === 0) return false;
    const digits = match.replace(/\D/g, '');
    if (!digits) return false;
    return isAllowlisted(digits, ALLOWLIST_DIGIT_PATTERNS);
}

function luhnCheck(number) {
    const digits = number.replace(/\D/g, '');
    if (digits.length < 13 || digits.length > 19) return false;

    let sum = 0;
    let isEven = false;
    for (let i = digits.length - 1; i >= 0; i--) {
        let digit = parseInt(digits[i], 10);
        if (isEven) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
        isEven = !isEven;
    }
    return sum % 10 === 0;
}

const PII_PATTERNS = [
    { name: 'Social Security Number', pattern: /\b\d{3}-\d{2}-\d{4}\b/g, description: 'Format: XXX-XX-XXXX' },
    {
        name: 'Social Security Number (no dashes)', pattern: /\b(?<!\d)\d{9}(?!\d)\b/g,
        context: /\bssn\b|social.?security|tax.?id/i,
        validator: (match) => {
            const firstThree = match.substring(0, 3);
            return firstThree !== '000' && firstThree !== '666';
        },
        description: 'Format: XXXXXXXXX (requires nearby SSN context)'
    },
    { name: 'Credit Card Number', pattern: /\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})\b/g, validator: (match) => luhnCheck(match), description: 'Visa, MasterCard, Amex, or Discover' },
    { name: 'Credit Card Number (with separators)', pattern: /\b(?:4[0-9]{3}|5[1-5][0-9]{2}|3[47][0-9]{2}|6(?:011|5[0-9]{2}))[-\s]?[0-9]{4}[-\s]?[0-9]{4}[-\s]?[0-9]{4}\b/g, validator: (match) => luhnCheck(match), description: 'Card number with dashes or spaces' },
    {
        name: 'US Phone Number', pattern: /\b(?:\+1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/g,
        validator: (match) => /[().\s-]/.test(match) || /^\+1/.test(match),
        description: 'Format: (XXX) XXX-XXXX or variants'
    },
    { name: 'International Phone Number', pattern: /\b\+(?!1[-.\s]?\d)(\d{1,3})[-.\s]?\(?\d{1,4}\)?[-.\s]?\d{2,4}[-.\s]?\d{2,4}\b/g, description: 'International phone with country code' },
    {
        name: 'IBAN', pattern: /\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7,30}\b/g,
        validator: (match) => {
            const cc = match.substring(0, 2);
            const valid = ['GB','DE','FR','ES','IT','NL','BE','AT','CH','IE','PT','SE','NO','DK','FI','PL','CZ','SK','HU','RO','BG','HR','SI','LT','LV','EE','LU','MT','CY','GR','SA','AE','QA','BH','KW','IL'];
            return valid.includes(cc);
        },
        description: 'International Bank Account Number'
    },
    { name: 'Email Address (potential PII)', pattern: /\b[a-zA-Z][a-zA-Z0-9._%+-]*[a-zA-Z0-9]@(?!example\.|test\.|localhost|invalid\.)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/gi, description: 'Real-looking email address' },
    { name: 'IP Address (non-local)', pattern: /\b(?!127\.0\.0\.1|0\.0\.0\.0|localhost|10\.\d|172\.(?:1[6-9]|2\d|3[01])\.|192\.168\.)(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/g, description: 'Non-localhost, non-private IP address' },
    { name: 'Date of Birth Pattern', pattern: /\b(?:dob|date.?of.?birth|birth.?date)\s*[:=]\s*\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/gi, description: 'Date of birth field' }
];

function detectPII(content) {
    const findings = [];

    for (const pii of PII_PATTERNS) {
        pii.pattern.lastIndex = 0;

        for (const m of content.matchAll(pii.pattern)) {
            const match = m[0];

            if (pii.context && !hasNearbyContext(content, m.index, pii.context)) continue;
            if (matchIsAllowlisted(match)) continue;
            if (pii.validator && !pii.validator(match)) continue;

            const masked = match.length > 6
                ? match.substring(0, 3) + '*'.repeat(match.length - 6) + match.substring(match.length - 3)
                : '***';

            findings.push({ type: pii.name, masked, description: pii.description });
        }
    }

    return deduplicateFindings(findings);
}

async function main() {
    const event = await readStdin({ failClosed: true });
    const { filePath, content } = parseHookEvent(event);

    // The allowlist cannot be written while the detector is scanning it.
    //
    // Found the first time anyone tried to use it: adding the client's phone
    // number to `pii-allowlist.json` is refused, because the number is not yet
    // in the allowlist that the edit is creating. The allowlist could never be
    // bootstrapped at all, which makes the escape hatch decorative and hands
    // whoever hits it a reason to switch the hook off.
    //
    // This is not a hole. That file IS the declaration of which real values are
    // public, it holds nothing else, and it is reviewed as a diff like any other
    // change. Scanning it for the values it exists to name is the bug.
    if (path.basename(filePath || '') === 'pii-allowlist.json') allowAndExit();

    if (isOutsideProject(filePath)) allowAndExit();

    if (shouldSkipFile(filePath)) allowAndExit();
    if (!content || content.trim() === '') allowAndExit();

    const findings = detectPII(content);

    if (findings.length > 0) {
        const { list, more } = formatFindings(findings);
        blockAndExit(
            `Blocked: Potential PII detected in ${filePath}\n\nFindings:\n${list}${more}\n\n` +
            `If this is test/example data, use:\n` +
            `- @example.com for emails\n` +
            `- 555-xxxx for phone numbers\n` +
            `- 123-45-6789 for SSNs\n` +
            `- 4242424242424242 for credit cards\n\n` +
            `If this is the client's OWN published contact detail and belongs on the site, ` +
            `add it to .claude/hooks/pii-allowlist.json. Add the specific value, never a ` +
            `pattern wide enough to cover a customer's.`
        );
    }

    allowAndExit();
}

main().catch(err => {
    console.error(`pii-blocker error: ${err.message}`);
    process.exit(2);
});
