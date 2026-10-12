# Hooks

Mechanical controls wired in `.claude/settings.json`. Each one exists because
the equivalent written rule was read, understood and broken anyway. A prose rule
is read alongside everything else and can lose to whatever else is in context. A
hook does not get a vote.

| File | Event | Refuses |
|---|---|---|
| `secrets-blocker.cjs` | PreToolUse Edit, Write | API keys, tokens, private keys, connection strings, hardcoded passwords |
| `pii-blocker.cjs` | PreToolUse Edit, Write | Emails, phones, cards, SSNs, IBANs not in the allowlist |
| `control-char-blocker.cjs` | PreToolUse Edit, Write | Raw control characters written into source |
| `self-exonerating-comment-blocker.cjs` | PreToolUse Edit, Write | A new comment excusing an imprecision instead of fixing it |
| `branch-protection.cjs` | PreToolUse Bash | Force-push, `reset --hard`, deleting a protected branch |
| `closing-keyword-blocker.cjs` | PreToolUse Bash | A GitHub closing keyword beside an issue number |
| `handoff-staleness-blocker.cjs` | Stop | A handoff naming a commit or issue state that is already false |

## Configuration

Two data files, deliberately separate from the hook code so that editing them is
a smaller act than editing a security control, and so a newer copy of a hook can
be dropped in without losing project settings.

**`hooks.config.json`** sets the git workflow.

```json
{
  "projectName": "Client Name",
  "protectedBranches": ["main", "master", "production"],
  "prOnly": false
}
```

`prOnly: true` blocks direct pushes from a protected branch. Leave it false for a
one-person build committing to `main`. The destructive-operation and
branch-delete arms ignore this setting and are always on.

**`pii-allowlist.json`** names the real contact details that belong on this site.

```json
{
  "literals": ["hello@client.ca", "(613) 555-0143"],
  "patterns": ["@client\\.ca$"]
}
```

Add the specific value. Never a pattern wide enough to cover a customer's
details as well as the business's; that turns the hook off for the file type it
exists for. A malformed file is treated as an empty allowlist, so a typo costs a
false block rather than a silent pass, and the hook says on stderr that it could
not read the file.

Two things that catch people out:

- **In `patterns`, a regex dot needs two backslashes.** JSON consumes one, so
  `@client\\.ca$` in the file becomes `@client\.ca$` as a regex. One backslash
  is a JSON parse error and the whole allowlist is ignored.
- **Write a phone number however it appears on the page.** It is matched on its
  digits, because the detector's match drops a leading bracket and an exact
  string comparison would never fire.

## Running the tests

```bash
node .claude/hooks/run-tests.cjs
```

Use that, not `node --test .claude/hooks/`: Node 24 resolves a bare directory as
a module and never runs a test, and a shell glob silently runs zero tests in
PowerShell while exiting 0. The runner computes the file list itself and fails
loudly if it finds none.

Six of the seven hooks have tests. If you change one, the test changes with it
in the same edit, and you watch the new case fail before you make it pass. A
test never seen to fail has not been verified.

## If a hook fires and it is wrong

Fix the hook and its test. Do not disable it, do not phrase around it, and do
not add an exception without a case proving the exception is narrow. A disabled
gate is worse than no gate, because everyone still believes it is watching.
