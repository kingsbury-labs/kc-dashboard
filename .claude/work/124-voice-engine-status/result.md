# 124 result

Files changed: `bin/cas-voice-start.cjs` (it does run.cmd's drive mapping itself, then runs `venv\Scripts\pythonw.exe` detached with windowsHide, so no cmd and no console python; stdout and stderr appended to `data/cas-voice.log` after a start line; `data/cas-voice.pid` gets the pid and start time), `public/api/voice.php` (new, guard and token first, up/starting/down), `public/cockpit/js/voice.js` (mic and Listening only from onopen via `ready()`; after 3 s without it the mic closes and `/api/voice.php` is polled every 10 s; reconnects on up), `main.js` (two labels). `autostart.cjs` needed no change: it already calls the launcher.

Tests added: 2 in CockpitVoiceTest, 1 in AutostartTest, VoiceApiTest (3). All three filters pass.

Mutation: put `stdio: 'ignore'` back. "cas-voice-start.cjs logs to data/cas-voice.log..." failed. Restored, green.

Live check, partial: with the engine down, the first call came back 500 because `Log` was never loaded. Fixed; after that it said up, and without a token it is refused (403). Then my launch failed with "port 8766 in use": someone else had started the engine (python.exe pid 43588) between my down check and my launch. The bind error landed in `data/cas-voice.log`, so the logging works. The permission check refused stopping the engine, so "starting within 5 s" and window titles for a launcher-started engine are not checked. The engine is still running.

Not done: the launcher does not skip when 8766 is already up.
