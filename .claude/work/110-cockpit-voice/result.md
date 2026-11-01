# 110 result

Files: `src/Cockpit/CockpitPage.php` (headers() adds only `connect-src 'self' ws://127.0.0.1:8766`; voiceToken() reads the token read-only, registers it for redaction; meta `kc-voice-token` or a "Voice engine is off" line), `config/running.php` (`voice_token` path), `public/cockpit/js/voice.js` (socket, hello first, modes, playback with stop_playback turn drop, speechSynthesis announce), `public/cockpit/js/mic-worklet.js` (new, so no blob: and no media-src needed), `main.js` (mode toggle, Mic open, thread fading after 20 s), `views.js` (care incidents spoken once while voice is on), `cockpit.css`, `bin/cas-voice-start.cjs` (new, hidden, detached).

Tests: new `tests/CockpitVoiceTest.php` (5 tests); `CockpitTest.php` header test now asserts the one-directive difference, stub assertion dropped. `--filter Cockpit`: 54 passed.

Mutation: connect-src widened to `ws:` failed CockpitTest "page is checkRead...voice connect-src" and CockpitVoiceTest "CSP adds only connect-src"; mode sent before hello failed CockpitVoiceTest "connects only to ws://...hello first". Restored, 54 green.

Calls: CockpitPage takes an optional voice token path (tests pass a temp one) so `public/cockpit.php` needed no edit.

Not done: real mic, wake phrases and playback need Rob with cas-voice running; no screenshot taken.
