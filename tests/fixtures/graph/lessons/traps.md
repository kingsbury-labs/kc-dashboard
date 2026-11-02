# Traps, every project

Invented fixture.

## Builds rewrite what you wrote

- **The CSS minifier changes units.** `545ms` ships as `.545s`. Any script
  reading a CSS time variable with `parseFloat` gets 0.545. Henike's picker
  diorama, 2026-11-01. Read times with a unit-aware helper (`cssTime` in
  `src/scripts/menu-morph.ts`). Check: `grep -rn "parseFloat(" src`
- **A deploy is not live when the push lands.** Fetch the page and confirm.

## Checks that lie

- **Screenshots freeze animation.** Watch it run; kc-portal needs the same.
- **Quick fixes pile up.** On henike-contracting a day of small pushes left red
  tests. Run the full suite before a push.
