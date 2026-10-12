# KC Dashboard

A personal Chrome new-tab dashboard for a one-person web studio, served by
XAMPP on the same PC and reachable only from it.

It puts a Google search box, an "ask Claude" box that runs `claude -p` inside a
chosen local project, the client list, a ranked AI/dev/security news feed tagged
against your own projects' stacks, site analytics, and a read-only mail digest
on one page. Bulk work (news tagging, mail summaries) runs on a local model
through llama.cpp or Ollama; nothing is sent to a paid API.

Status: under construction. The build plan is `.claude/work/plan.md` and the
spec is `docs/SPEC.md`.

## Requirements

- Windows 11, XAMPP (Apache, PHP 8.2), no Composer or npm build
- Claude Code CLI signed in to your own account
- A local model server speaking the OpenAI chat API: the PrismML llama.cpp
  fork with Ternary-Bonsai-2-27B, or Ollama with `gpt-oss:20b`
- An 8 GB GPU for the Bonsai model

## Security model

Any web page open in the browser can send requests to localhost, and this app
can start processes. So it runs on its own loopback-only port (8787) with only
`public/` reachable, and every endpoint checks a per-install token, the exact
Origin and Host, and that the caller is on loopback. Mail access is read-only by
construction: the IMAP client only allows EXAMINE, SEARCH and BODY.PEEK. No
credential is stored in this repository; they are read at run time from a
folder outside it.

## Tests

```
c:\xampp\php\php.exe tests/run.php
```

## License

MIT
