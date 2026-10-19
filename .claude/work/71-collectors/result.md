# 71. Rule collectors: result

Files (all in the brief's list): `src/Net/HttpClient.php`, `src/Net/CurlHttpClient.php`, `src/Collectors/{Collector,SslExpiry,EndOfLife,ClientDates,Finance}.php`, `bin/collect-facts.php`, `config/collectors.php`, `tests/Fakes/FakeHttpClient.php`, `tests/fixtures/collectors/` (7 files), `tests/CollectorsTest.php`, `tests/FinanceCollectorTest.php`.

Tests added: 16 (10 Collectors, 6 FinanceCollector). Full suite 271/271.

Mutations, each restored, then green:
- Finance schema check off: failed "schema 2, truncated JSON and a missing file...".
- Collector::run rethrows instead of recording: failed "a throwing collector records its error..." and the same Finance test.
- CurlHttpClient https check loosened: failed "CurlHttpClient refuses http:// and file://...".
None refused by the permission check.

Calls made:
- EndOfLife reads the API's `eol` date as "support ends". A version the list lacks is an error.
- Finance validates every fact before returning any, so a bad one records an error and no partial facts. Aliases match the subject exactly.
- SslExpiry's default fetcher skips peer verification, on purpose, so expired certs can be read. It is untested (no network).
- ClientDates also reads the dashboard's Pipeline table ("overdue" status gives a high follow-up fact).

Not done: `collect-facts.php` is not scheduled (no Task Scheduler change). `config/collectors.php` ssl host is a placeholder for Rob to edit.

Incident: one shell command mis-ran and left fixture files in the project root. I moved them into `tests/fixtures/collectors/`; root is clean.
