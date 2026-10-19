# 78. Vault: result

Files (all new): `src/Vault/Vault.php`, `ProjectNotes.php`, `CanvasExport.php`, `Board.php`, `bin/write-vault.php`, `config/vault.php`, three test files.

Tests: VaultTest 7, CanvasExportTest 3, BoardTest 3. All three filters pass, BinCli passes with the new script, full suite 345/345.

Mutations, each restored, then green:
- Bracket swap dropped from `Vault::neutralise`: "commit subject holding [[evil]]" and "neutralise leaves no link..." failed.
- Unchanged-content check removed: "unchanged content leaves the mtime unchanged" failed.
- Root SafeDir check removed: "a junction root is refused" failed. Swapping it for `is_dir` went uncaught, because PHP's `is_dir` is already false for a junction (as card 73 found).
- Folder SafeDir check removed: same test, "junction folder refused", failed.
- Board create-once swapped for write: "an existing board's bytes are unchanged" failed.

Calls:
- The neutraliser swaps syntax characters for lookalikes (`[`, `<`, `#`, `%`, the scheme colon, `www.`, `//`, `\\`) and flattens newlines and bidi controls. That does not depend on how Obsidian treats backslash escapes.
- The board is `Boards/rob.md`, not `Rob.md`: the card's own `[a-z0-9-]` name rule wins.
- `updated` is the commit day, not the scan time, so an unchanged project never gets rewritten.
- The canvas has no edges yet. The edge test passes with nothing to check.

Not done: the script has not been run against the real vault, and nothing is scheduled.

## Round two

1. The neutraliser now swaps `$ { } ~ \` and `::` for lookalikes. Any word-colon is broken, space after it or not. `www.` loses its `\b`. Invisible characters are stripped by category (Cc, Cf, Zl, Zp, plus tag characters). A leading `- + * = _` or `1.`/`2)` is swapped as well.
2. NAME refuses con, nul, aux, prn, com1-9 and lpt1-9 with any extension. ProjectNotes catches errors per row and exposes `errors()`, which `write-vault.php` logs.
3. The board uses `kanban-plugin: basic`, with a comment saying why it differs from the plan.

Tests: VaultTest is now 10. The neutralise test carries every payload from the audit. New tests: line-start block syntax, reserved names plus a bad row, and a hard link at the target (replaced, not written through). There is no file symlink test: `symlink()` returns "Permission denied" because this account lacks the privilege. Full suite 348/348.

Mutations, both restored: without the `$ { } \` swaps, the neutralise test and the line-start test (`$$x$$`) failed. With the colon rule back to `(?=\S)`, the neutralise test failed on `javascript: alert`.
