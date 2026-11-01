# 113 result

Files changed: `src/Cockpit/CockpitDaily.php` (`codex()`, `limits()`), `src/Cockpit/CockpitApi.php` (working needs a live process), `src/Cockpit/CockpitDeps.php`, `config/running.php` (`codex_sessions_dir`), `public/cockpit/js/views.js` (Claude and Codex groups), `public/cockpit/cockpit.css`.

Added: `tests/CockpitCodexTest.php` (4 tests), fixtures in `tests/fixtures/cockpit-codex/` (two rollout files with marker text).

Filtered run `--filter Cockpit`: 37 passed.

Mutation: read oldest Codex file first, and skip the dead-loop demotion. Failed: "plan limits carry the newest Codex file last reading..." and "a loop whose lock pid is dead...". Restored, 37 green.

Calls made:
- The usage block keeps Claude's fields at the top and adds `codex`. With no Claude reading but a Codex one, Claude fields are null.
- Stale rules use the saved now.php snapshot (no process started). A loop pass is working only if its project's loop is running or stopped. Other entries need a registered session for their project. Only applied when the snapshot carries `sessions` / `loops` lists.

Not done: no screenshot of the panel. The "2 to 5 hour old sessions shown as working" cause was not reproduced; the registry rule is my best fit.
