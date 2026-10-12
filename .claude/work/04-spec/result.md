# 04 result

Files changed: `docs/SPEC.md` (edited in place), `tests/SpecTest.php` (new).

Test added: 10 cases, one per required string plus one asserting `.htaccess denies` is absent. Filtered run: 10 passed. Full suite via the runner: 14 passed.

Mutation: changed `S4U` to `S4X` in the spec. Failed: `SpecTest: spec contains S4U`. Restored, full suite green.

Calls made:
- The `.htaccess` bullet was replaced by the DocumentRoot `public/` statement. The old Host rule (`localhost` or `127.0.0.1`) was replaced by the exact-port rule.
- The 30-day body retention line was replaced with "never stored".
- The old `dashboard-collect.py` references (two) now say `bin/collect_ga.py`.
- The mutation restore briefly added a UTF-8 BOM to SPEC.md. I stripped it and reran green.

Not done / unknown: plan gives no measurement source beyond the figures quoted, so the spec states them as measured without date or model detail. The S4U wording ("no stored password, runs whether or not Rob is logged on") is my gloss of what S4U means, not from the plan. `bin/collect_ga.py` does not exist yet (a later card). I wrote "reads only the metrics" from the plan's review note about the old script.
