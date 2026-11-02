# 134 result

Files: `src/Graph/Readers.php` (traps, asks, cards, fixes, proposals, dreams, shared linker), `src/Graph/GraphStore.php` (`lessons`), `src/Collectors/Graph.php` (four `replace` calls), `config/collectors.php` (`lessons` paths), new `bin/brief-context.php`, `tests/TasksScriptTest.php` (on-demand), `tests/GraphTest.php`, fixtures in `tests/fixtures/graph/lessons/`.

Tests: 8 new in GraphTest; Graph filter 14/14 green.

Mutation: file link off where a project is named failed 6 (brief-context order, fix, ask, dream, secret-path, CLI). File link off in the one-project-only rule failed 3 (trap, brief-context order, CLI). Restored, green.

Calls: a relative path with a slash that exists in exactly one project links that project when none is named (how Henike's trap links). An ask's project word links when it is the folder or begins exactly one folder name. Paths under `.credentials`, `.env`, token, secret, credential or settings are never linked. Keys carry a text hash, so edited text makes a new node.

Real data, read only: 77 projects, 1067 result.md, 264 pending proposals, about 1 s warm.

Command: `c:\xampp\php\php.exe bin\brief-context.php --project henike-contracting --files src/a.ts,src/b.ts [--limit 8]`
Lines: `trap|fix|dream [file|project] <label>`, file matches first, no output when none.

Not done: ask lines are stored but not printed; the dream `**File:**` memory file is not linked. No live collector run.
