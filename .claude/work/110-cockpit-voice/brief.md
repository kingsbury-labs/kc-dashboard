# 110. Cockpit voice: the Cas bar talks through cas-voice (Risky)

## Objective
The Cas bar in the cockpit is a working voice line to Cas through the local
cas-voice engine: an Always on / Click to talk / Off toggle, always listening
for "Hey Cas" or "Time to work" when on, Rob's and Cas's words in the bar's
thread, Cas's voice played back, and a plain "Voice engine is off" state when
cas-voice is not running. The care banner (card 115) is also read aloud once.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 110
- `D:\Dev\cas-voice\proto\RESULT-110.md` (the socket protocol, the spec for this card) and `D:\Dev\cas-voice\proto\index.html` (a working client to copy the audio code from)
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\CockpitPage.php`, `public\cockpit\js\voice.js` (the stub), `main.js`, `views.js`, `src\Http\Response.php`

## Context
- Rob, 2026-11-01: "It should operate like hermes - always on / click to talk
  toggle, while always listening for 'Hey Cas' or 'Time to work'." No orb, no
  hold screen, the page stays on ("just leave it on. I'll be using it all the time").
- Protocol (from RESULT-110.md): `ws://127.0.0.1:8766/ws`; only Origin
  `http://127.0.0.1:8787` is accepted from the cockpit; first message
  `{"type":"hello","token":"<64 hex>"}` within 5 s; then
  `{"type":"mode","mode":"wake"|"talk"|"off"}`; audio in as binary 16 kHz mono
  int16 LE; events state, mode, wake, sleep, transcript, stop_playback, open,
  metrics, error; audio out binary: byte 0 kind (0 speech, 1 filler,
  2 greeting), bytes 1-4 turn id uint32 LE, then 24 kHz mono int16 LE; drop
  chunks whose turn is below the last stop_playback turn.
- Token: `D:\Dev\cas-voice\proto\voice-token`, read server side by
  `CockpitPage` (path in config, never logged) into a meta tag the JS reads.
  Missing file: voice shows "Voice engine is off".
- CSP: the cockpit page alone gets `Response::SECURITY_HEADERS` plus
  `connect-src 'self' ws://127.0.0.1:8766` (and `media-src 'self' blob:` only
  if playback needs it). Every other page keeps the headers unchanged. Update
  the cockpit header test to assert exactly that difference.
- UI: the toggle (three text states) beside the mic button; a "Mic open" text
  in the top bar whenever the mic is live; bar states idle, listening,
  thinking, speaking; the thread above the bar shows both sides and fades
  after 20 s of quiet. Mode persists in localStorage (try/catch). Mic
  permission is asked only when Rob first turns voice on.
- Care incidents (card 115): when a new incident appears and voice is on, send
  nothing to the brain; play it with the browser's speechSynthesis using a
  local voice ("brucemechanical.com is down").
- Starting cas-voice: add `bin/cas-voice-start.cjs`, a Node launcher that runs
  `D:\Dev\cas-voice\proto\run.cmd --no-browser` with `windowsHide: true`
  and detached, for Rob or a later logon task; the page never starts it.

## Files you may touch
- `src/Cockpit/CockpitPage.php`, `src/Cockpit/` (voice config), `config/running.php`
- `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `bin/cas-voice-start.cjs` (new)
- `tests/CockpitVoiceTest.php` (new), `tests/CockpitTest.php`
- `.claude/work/110-cockpit-voice/progress.md`, `result.md`

## Done-test
From plan.md card 110, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitVoice tests proving: the cockpit page's CSP differs from
`Response::SECURITY_HEADERS` only by `connect-src 'self' ws://127.0.0.1:8766`
(plus `media-src 'self' blob:` if used) and other pages are unchanged;
`voice.js` connects to no URL but `ws://127.0.0.1:8766/ws` and sends hello
with the token first; the token value never appears in app.log; no innerHTML;
the mode is read and written in localStorage inside try/catch; with no token
file the page renders "Voice engine is off".

Then the mutation check (widen connect-src to ws:, then send mode before
hello), name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push. Do not edit `D:\Dev\cas-voice`. The page never starts a
process. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result. Say what was not
done (a real-mic test needs Rob).
