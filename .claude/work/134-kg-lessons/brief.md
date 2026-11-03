# 134. Traps, fixes, asks and dream proposals join the knowledge graph (Normal)

## Objective
The kc-dashboard knowledge graph (card 117) gains four read-only sources: the traps in `C:\Users\roban\.claude\pm\traps.md`, the fixes in landed cards' `result.md` files, Rob's asks in `C:\Users\roban\.claude\pm\asks.md`, and dream proposals. Each becomes a node linked, by deterministic rules only, to its project, the files it names and its cause. A CLI answers "which traps and past fixes match this project and these files", so the brief skill can pull them into a brief's Read first (that skill edit is a separate ~/.claude card). Item 3 of the HQ orchestrator plan.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `.claude/work/agentos/kg-research.md` (38 lines: the approach)
- `src/Graph/GraphStore.php` (`node`, `find`, `replace($source, $edges)` per source, `add`, `walk`, `neighbourhood`), `src/Graph/Readers.php` (the existing readers' shape: pure static functions returning edge lists), `src/Collectors/Graph.php:40-55` (how each source is rebuilt with `replace`)
- `tests/GraphTest.php` (fixtures and style)
- The real inputs' shapes, read only: `C:\Users\roban\.claude\pm\traps.md` (`## Section`, then `- **Title.** text ... Check: ...` items), `C:\Users\roban\.claude\pm\asks.md` (one ask per line with project, date and reference), one landed `result.md` such as `D:\Dev\htdocs\_kc-dashboard\.claude\work\133-mail-sorter\result.md`, and the dream proposals waiting on review: `<project>\.claude\dream\pending\proposal-*.md` (295 files across 15 projects on 2026-11-02; `logs\` holds raw run output and is NOT a source). Real example: `D:\Dev\htdocs\henike-contracting\.claude\dream\pending\proposal-20261102-045002.md`. Shape: an intro paragraph, then one `### item-id` section per proposed change, each with a `**File:**` line naming the memory file it would edit. One node per `### item`, labelled `<project>: <item-id>`.

## Context
- Read-only readers; deterministic links (a project is linked when the text names its folder, its project.md `name`, or a path under it; a file is linked when the text names a path that exists in that project; a cause is the trap's section heading). Never a model merge, never a fuzzy match.
- Each source is rebuildable on its own: one `replace('<source>', ...)` per source (`traps`, `fixes`, `asks`, `dreams`), so rebuilding one never touches another.
- Projects to scan for `result.md` and dream proposals come from the existing project list (ProjectList / the `projects` source).
- No secret or PII leaves its file: asks.md and result.md may hold names; store only the node label (first 120 characters of the item) and the links, never a whole file, and never anything from a `.credentials`, `.env`, token or settings file.

## Start here
- Add readers to `src/Graph/Readers.php` (`traps`, `fixes`, `asks`, `dreams`), each pure (text in, edges out), and wire them in `src/Collectors/Graph.php`.
- Add a query and a CLI: `php bin/brief-context.php --project <name> --files <a,b,...>` printing, as plain lines, the traps, past fixes and pending dream proposal items linked to that project or any of those files, most specific first (file matches before project-only matches). This is what the brief skill will call.
- Done-test command: `c:\xampp\php\php.exe tests/run.php --filter Graph`

## Files you may touch
- `src/Graph/Readers.php`
- `src/Graph/GraphStore.php` (only a query method for the brief context, if needed)
- `src/Collectors/Graph.php`
- `bin/brief-context.php` (new)
- `config/collectors.php` (source paths only)
- `tests/GraphTest.php`
- `tests/TasksScriptTest.php` (only to list `brief-context.php` as on-demand, as card 130-09 did for pm-state.php)
- `tests/fixtures/graph/` (invented fixtures only)

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter Graph` passes, with new tests on fixtures proving: a trap that mentions `src/scripts/menu-morph.ts` in a fixture henike-contracting tree links to the `henike-contracting` project and to that file; `brief-context` for project `henike-contracting` and file `src/scripts/menu-morph.ts` returns that trap, before a project-only match; a fix from a fixture `result.md` links the same way; an ask links to its project; a fixture `.claude/dream/pending/proposal-*.md` with two `### item` sections gives two dream nodes linked to that project, and a proposal under `dream/logs/` gives none; rebuilding `traps` leaves `fixes` edges untouched; the same input twice gives the same edges. Mutation check through the runner (`node C:\Users\roban\.claude\bin\project-test.cjs --filter Graph`): break the file link, name the failing tests, restore, green.

## Boundaries
No commit, no push, no install. Read-only on every source. No model calls. PHP 8.2 only. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work: done, files, next, anything half-written. Already there? You are resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result, and the exact `brief-context.php` command and its output shape for the brief skill. Say what was not done.
