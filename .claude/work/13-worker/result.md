# 13 result

Files added: src/Jobs/Registry.php, bin/worker.php, tests/WorkerTest.php, tests/fixtures/worker/registry.php, tests/fixtures/worker/EchoHandler.php (echo and throw handlers).

Tests added (3, in WorkerTest): job ends done with handler output; unknown type and throwing handler end failed, not re-queued; budget=0 leaves the job queued. Each runs bin/worker.php as a real process (PHP_BINARY, array proc_open) against a temp database.

Mutation:
- Dropped `complete`: "worker runs a job to done" failed (status running).
- Re-queue instead of fail: "worker fails an unknown type..." failed (status queued).
Both restored; Worker filter 3/3 green, full suite 40/40 green.

Calls: worker takes --db, --registry (PHP file returning a type=>class map), --budget (seconds, default 50), --log. Handler contract: class with handle(array): string|JSON-encodable. Worker does not load src/bootstrap.php, whose exception handler is HTTP-specific. Default log is data/worker.log.

Not done: stale-job recovery is not called by the worker (not in brief); no real handlers registered (DEFAULT_MAP empty).
