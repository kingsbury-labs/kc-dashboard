# KC Dashboard

Rob's local Chrome new-tab dashboard for Kingsbury Creative. Spec: `docs/SPEC.md`.
Agent rules and risky paths: `.claude/project.md`.

- Vanilla PHP on XAMPP's PHP 8.2, SQLite, no Composer, no build step.
- Tests: `c:\xampp\php\php.exe tests/run.php` (add `--filter <name>`).
- Local-only. Anything that can start a process checks the token, Origin and
  Host first; see the Security section of the spec.
- Credentials live in `D:\Dev\htdocs\.credentials\`, referenced by path only.
- `llm/` holds the downloaded llama.cpp fork and model weights; never committed.
