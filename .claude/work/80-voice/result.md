# 80 Voice out: result

Done. `--filter Voice` 6 passed; full suite 387 passed, 0 failed.

Files changed
- `public/assets/voice.js` (new): local-voice-only picker (`localService === true`), choice in `localStorage` inside try/catch, `speak`/`stop`, orb `setState('speaking')` and `setLevel` pulses on `boundary`, back to idle on `end`. No network API. Enables the "Talk to Jarvis" capsule; watches `#briefing-body` and reads a dated briefing aloud after any `[data-brief]` click (no `brief.js` change needed).
- `public/assets/app.js`: JS-built Speak button on done answers.
- `src/View.php`: loads `voice.js`; placeholder text updated ("Voice off", "Voice needs JavaScript", "Brief me now by voice").
- `tests/VoiceTest.php` (new, 6 tests).

Not touched: `public/index.php` (no wiring needed), `hud.css`/`app.css` (not in the list). The Speak, Stop and picker controls reuse `.cmd` and default button styling, so they may want CSS polish in a later card. No endpoint added, so `ops/http-check.php` is unchanged.

Mutation check (all restored, then green)
- localService filter loosened: VoiceTest "offers only local voices..." failed.
- `localStorage` read without try/catch: same test failed.
- `end` listener renamed: "listens for boundary and end..." failed.

Not done: no real browser speech test (no audio in tests).
