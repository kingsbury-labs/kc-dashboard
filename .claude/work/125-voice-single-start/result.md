# Result 125

Files: `bin/cas-voice-start.cjs` (run() with port probe, pid-file age check, exclusive `data/cas-voice.lock`, stale lock 60 s; pid written only by the spawning run; exports run/takeLock; main still spawns pythonw with windowsHide), `tests/AutostartTest.php` (3 new tests, one existing string test updated for renamed variables).

Tests added: listener on 8766 spawns nothing; young live pid file spawns nothing (old or dead pid starts); two concurrent runs spawn once, pid only from spawner, pythonw + windowsHide, no lock left, fresh lock refuses, stale lock replaced. `--filter Autostart`: 9 passed.

Mutation: lock open `wx` changed to `w`: failed "two voice launcher runs started together spawn once..." (spawned twice: expected 1, got 2). Restored, 9 passed.

Live check NOT done: when I went to run it, an engine was already up (LISTENING on 127.0.0.1:8766, pythonw pids 27388 and 59772). Per the brief I did not touch it. It was not started by me. Run the double start live check after that engine is stopped.
- Live check (coordinator, 2026-11-02 01:45Z): engine up, two starts together logged two 'already running', pythonw pids 59772/27388 unchanged, 8766 answers 200.
