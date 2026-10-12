# 06. Apache vhost snippet and HTTP check script

## Objective
An Apache vhost snippet that serves only `public/` on loopback port 8787 and
denies the project folder on port 80, a test that parses the snippet, and a
standalone HTTP check script for use after install.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 06 (the full text is the spec for this card)
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the Security section
- `C:\xampp\htdocs\_kc-dashboard\tests\run.php`, `tests\DbTest.php` for house style and test discovery

## Context
Snippet: `Listen 127.0.0.1:8787`, `Listen [::1]:8787`, one vhost on both,
`DocumentRoot` = `C:/xampp/htdocs/_kc-dashboard/public`, `Options -Indexes`,
`php_admin_flag display_errors off`, and a `Directory` deny so port 80 serves
nothing from `_kc-dashboard`. `ops/http-check.php` makes real HTTP requests
with curl and is NOT part of the unit suite: it must not be discovered or run
by `tests/run.php`. Its checks are listed in plan.md card 06. The unit test
only parses the snippet file; no network, no Apache. Do not install the
snippet into XAMPP (that is card 07, Rob's). PHP 8.2, no Composer. Edit PHP
with the Edit/Write tools, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\ops\apache-kc-dashboard.conf`
- `C:\xampp\htdocs\_kc-dashboard\ops\http-check.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\VhostConfTest.php`

## Done-test
From plan.md card 06, word for word:

`php tests/run.php --filter VhostConf` passes; it parses the snippet and
asserts both Listen lines, the DocumentRoot ending `/public`, `-Indexes`,
`display_errors off`, the port-80 deny on the project folder, and no
`Listen 80` or `0.0.0.0`. `php -l ops/http-check.php` exits 0.

Run tests through the shared runner (`node %USERPROFILE%\.claude\bin\project-test.cjs --filter VhostConf`)
and do the mutation check through it too: temporarily edit a copy-safe value in
the snippet (for example drop the `-Indexes` line), rerun, record which test
failed, restore the file. If any permission check refuses a step, do not route
around it: stop and write what was refused in result.md. Write `result.md`
beside this brief. Do not commit.
