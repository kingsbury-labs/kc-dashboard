# 74. What's running: result

Files: new `src/Running/` SessionRegistry, Ports, Tasks, NowService; `public/api/now.php`; `config/running.php`; `executables.php` gains netstat, schtasks, powershell. Fixtures in `tests/fixtures/running/`.

Mutations (restored): procStart always matching failed "reused PID"; loosened filename filter failed ".key never opened"; cache `<=` failed "two calls within 3 seconds"; keeping Task To Run failed "command-line marker".

Calls: start times come from one fixed PowerShell CIM query (no PID in argv), matched to procStart (ticks) or procStartFt (FILETIME). The `.key` fixture is made in a temp copy (`*.key` is gitignored). Failing sources go in an `errors` key.

## Round two

1. `RequestGuard::checkApiRead`: checkRead, a same-origin non-navigate fetch when Sec-Fetch is present, and the token always. now.php and NowService use it; the token is read only after the Host check.
2. `at` stamped after the scan; LOCK_NB on a `.lock` file. A busy request gets the snapshot or an empty `stale: true`. Snapshot is written and renamed in.
3. Tasks: fgetcsv stream, header only `HostName,TaskName`, width must match.
4. `\z`.

Tests: NowApi 10, RequestGuard +1. Suite 184/184.

Mutations: stamp-before-scan, busy-request scan, any-TaskName header, line-split parsing each failed their test. Owed: allowing navigate in checkApiRead was refused (Security Weaken); token removal not attempted.
