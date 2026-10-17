# KC Dashboard

Rob's local Chrome new-tab dashboard for Kingsbury Creative. Spec: `docs/SPEC.md`.
Agent rules and risky paths: `.claude/project.md`.

- Vanilla PHP on XAMPP's PHP 8.2, SQLite, no Composer, no build step.
- Tests: `c:\xampp\php\php.exe tests/run.php` (add `--filter <name>`).
- Local-only. Anything that can start a process checks the token, Origin and
  Host first; see the Security section of the spec.
- Credentials live in `D:\Dev\htdocs\.credentials\`, referenced by path only.
- `llm/` holds the downloaded llama.cpp fork and model weights; never committed.

## Decisions

Rubric, in order: consensus > security > correct long-term > best practice >
dashboard health > UX. Apply it and act; do not hand a rubric-decidable call back
to Rob. No time or budget framing unless he raises it. (From Sembr, 2026-10-17.)
