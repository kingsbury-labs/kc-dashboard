# Card 69: cross-card security audit

Opus security-auditor, session 10, 2026-10-19, over main at 742a3a8. Also
covers the per-card audits of 76b, 79, 80 and 68 that Rob deferred for speed.
Read-only; the coordinator ran the suite (395 passing) and http-check (exit 0).
The auditor mapped review files codex.md and fable.md directly rather than
revision 3's mapping at b0ed4b4; mail and Bonsai items are void.

## Coverage map

| Control | Test that pins it |
|---|---|
| Loopback listen, DocumentRoot public/ | VhostConfTest "listens on both loopback addresses", "vhost denies anything outside public/" |
| httpd.conf denies .credentials (Codex 2) | none: card 81 |
| Loopback REMOTE_ADDR and exact Host | RequestGuardTest "rejects bad address and hosts on read and write" |
| POST, token and Origin on writes | RequestGuardTest "write rejects method, token and origin cases"; ApiGuardSweepTest |
| Token created once, never on a 403 | TokenTest; PageTest "a 403 never creates the token file" |
| Only allowlisted .exe, no .bat or .cmd | ProcessRunnerTest ".bat in the allowlist is refused", ".cmd, relative, unlisted..." |
| One ProcessRunner starts every child | partial (sweep covers public/ only): card 82 |
| Junction check, repeated at launch | SafeDirTest "swapped for a junction"; AskApiTest "...after enqueue" |
| Untrusted claude: empty cwd, no tools | ClaudeRunnerTest "a Prompt runs in a fresh empty temp folder" (fake; real canary owed, Rob) |
| Project picker | ProjectListTest "names outside the listing resolve to null" |
| No bypass flags, prompt on stdin | ClaudeRunnerTest "argv is claude -p..." |
| Credentials out of DB, logs, page | LogTest; NumbersTest "Bugsink: ...never in the database". Out of the repo: commit hook only |
| Untrusted text escaped, ids and URLs checked | CommandCenterTest "...renders escaped"; BriefApiTest "the briefings page escapes"; PageUiTest DomSinks; DailyReadTest "invented id", "URL in a reason" |
| Finance: account numbers | FinanceCollectorTest "extra field (account_number)" (separate field only) |
| Finance: credentials, CSV lines, receipts, salary | none: card 83 |
| Finance: url prefix | AttentionApiTest "safeUrl: a finance url links only under..." |
| Finance: read-only | none: card 84 |
| Codex 9 (SSRF to private hosts), Codex 12 (token file ACLs) | none: card 85 |
| Other accepted Codex and Fable items | ProcessRunnerTest, PageTest, TasksScriptTest, ElevationTest, LogTest, PromptTest, StructuredOutputTest, CollectGaTest |

## Findings, cards 76b, 79, 80, 68

No concrete attack. Every page and endpoint runs its guard before opening
anything (pages checkRead with frame-ancestors 'none', API reads checkApiRead,
writes checkWrite, brief.php POST-only). View escapes every traced field; JS
writes with textContent only. Composer and MemoryStore neutralise every
untrusted field (Composer.php:65-149). SystemMonitor's argv is a constant
(SystemMonitor.php:52). register-tasks.ps1 takes no outside input; the
extension asks for no permissions.

Latent: powershell.exe, php.exe and python.exe are in the global allowlist
(config/executables.php:6,7,14), so safety rests on every caller passing a
constant argv. Card 82 binds each to its fixed argv.

## Gaps filed as cards (plan.md, 81 to 86)

81. .credentials on port 80: http-check probe plus a suite test.
82. proc_open and the exec family only in ProcessRunner.php (static test over src/ and bin/); powershell, php, python bound to fixed argv.
83. Finance evidence is free text (Finance.php:83), unscrubbed, and since card 79 copied into the vault's Briefings and the briefings table. Needs Rob's call plus a scrub test.
84. AskHandler.php:87 git status in kc-finance without GIT_OPTIONAL_LOCKS writes its .git/index (owed since card 73).
85. Codex 9 redirect-to-private-host test; token file ACL test.
86. Test hygiene: BriefingTest pins 5 of 14 neutralised fields (loop_card uncovered); ApiGuardSweepTest leaves config/vault.php on the real vault (a brief.php guard regression would write to D:\Dev\vault\kc); TasksScriptTest's shadow misses schtasks.exe and CIM.

Still owed: memory-neutralise and -WhatIf mutations (refused by the permission
check), the real claude untrusted-mode canary (Rob). Not verifiable here:
Obsidian rendering of neutralised text, the live CIM argv, D:\ ACLs on bin/
(the S4U tasks run those scripts as Rob).
