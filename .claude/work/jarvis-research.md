# JARVIS features for the KC Dashboard: research

Written 2026-10-19 for Rob and the next planning session. Nothing here is built.
Every tool claim has a link. Repo facts (licence, stars, last push, last release)
come from the GitHub API on 2026-10-19 unless a line says otherwise. Anything I
checked on this PC says "checked locally".

## 1. Verdict

Build it into the existing PHP dashboard. Keep the vault as a plain folder of
Markdown that the dashboard reads and writes, with Obsidian as an optional viewer.
Claude Code stays the agent that does real work, through `claude -p` jobs and the
interactive sessions Rob already runs, and a small local model does the cheap
talking: intent routing, briefing narration, chat. Do not install Hermes Agent.
Four reasons. First, Hermes cannot use Rob's Max plan within the rules: its own
docs say the Anthropic OAuth path "only works if you're on a Claude Max plan and
have purchased extra usage credits"
([providers](https://hermes-agent.nousresearch.com/docs/integrations/providers)),
and Anthropic's terms say developers may not "route requests through Free, Pro, or
Max plan credentials" in their own apps
([legal and compliance](https://code.claude.com/docs/en/legal-and-compliance)).
That leaves an API key (forbidden here) or a local model as its main brain.
Second, it would be a second agent with shell access, its own ports and its own
dashboard sitting next to the one whose Security section was written to close
exactly that door. Third, its web chat pane needs WSL2 on Windows
([web dashboard](https://hermes-agent.nousresearch.com/docs/user-guide/features/web-dashboard)).
Fourth, nothing in the video needs it: the orb, the voice, the briefing, the
graph and the wall view are each a few hundred lines on top of the guard, queue,
ProcessRunner and ClaudeRunner already planned. The video's "Agent OS" is not
open source anyway. It is a zip inside a paid community ($69/month on the exit
popup) ([Goldie's blog](https://aisuccesslabjuliangoldie.com/blog/hermes-agent-command-center/)).

## 2. What the video's pieces actually are

- **Hermes Agent** is Nous Research's agent, MIT, 255k stars, release v0.22.2 on
  2026-10-15 ([repo](https://github.com/NousResearch/hermes-agent)). It runs
  natively on Windows: "Native Windows runs Hermes without WSL" (README). It has
  a local web dashboard at `127.0.0.1:9119` with Status, Chat, Sessions, Cron,
  Skills, MCP and more; loopback needs no login
  ([web dashboard](https://hermes-agent.nousresearch.com/docs/user-guide/features/web-dashboard)).
  It has a built-in multi-agent Kanban board stored in `~/.hermes/kanban.db`
  ([kanban.md](https://github.com/NousResearch/hermes-agent/blob/main/website/docs/user-guide/features/kanban.md)),
  voice mode with local Faster-Whisper STT, and TTS through Edge (Microsoft's
  online service), Piper, NeuTTS or KittenTTS
  ([voice-mode.md](https://github.com/NousResearch/hermes-agent/blob/main/website/docs/user-guide/features/voice-mode.md),
  [tts.md](https://github.com/NousResearch/hermes-agent/blob/main/website/docs/user-guide/features/tts.md)).
  Its wake word tries openWakeWord, then sherpa, then Porcupine, and Porcupine's
  default keyword is "jarvis"
  ([wake-word.md](https://github.com/NousResearch/hermes-agent/blob/main/website/docs/user-guide/features/wake-word.md)).
  I found no Obsidian integration in its docs; the video's Obsidian layer is
  Goldie's own glue.
- **Claude through Hermes.** `--provider claude-code` is only an alias for the
  Anthropic API provider. A feature request for a provider that shells out to
  `claude -p` was closed 13 minutes after it was opened
  ([#48320](https://github.com/NousResearch/hermes-agent/issues/48320)); a
  request for an Agent SDK provider is still open
  ([#25267](https://github.com/NousResearch/hermes-agent/issues/25267)). One user
  reports Max OAuth through Hermes billed the empty extra-usage pool
  ([#12905](https://github.com/NousResearch/hermes-agent/issues/12905)).
- **Local models through Hermes: yes.** Custom Endpoint takes any
  OpenAI-compatible `/v1/chat/completions` server; for Ollama and llama-server
  the docs say to skip the API key
  ([providers](https://hermes-agent.nousresearch.com/docs/integrations/providers)).
- **Open-source Hermes web UIs** exist if Rob ever wants to look:
  [nesquena/hermes-webui](https://github.com/nesquena/hermes-webui) (MIT, 19k
  stars, release 2026-10-14) and
  [outsourc-e/hermes-workspace](https://github.com/outsourc-e/hermes-workspace)
  (MIT, 6.7k stars, has Kanban). "Mission control" dashboards for OpenClaw are
  common and MIT: [builderz-labs/mission-control](https://github.com/builderz-labs/mission-control)
  (6.4k stars), [abhi1693/openclaw-mission-control](https://github.com/abhi1693/openclaw-mission-control)
  (4.1k), [crshdn/mission-control](https://github.com/crshdn/mission-control)
  (2.2k). All TypeScript with a build step. Useful to look at, not to install.
- **OpenClaw** is a MIT personal assistant gateway (392k stars, active)
  ([repo](https://github.com/openclaw/openclaw)). Anthropic cut third-party
  harnesses like it off subscription limits in 2026
  ([cybersecuritynews](https://cybersecuritynews.com/claude-ends-openclaw-subscriptions/),
  secondary source; the primary rule is the legal page above).
- **The "hermes voice assistant" Obsidian plugin** is unrelated to Hermes Agent.
  It runs on Google's Gemini Live API and needs a Google API key, GPL-3.0,
  last updated about 8 months ago, about 1k downloads
  ([plugin page](https://community.obsidian.md/plugins/hermes-voice-assistant),
  [repo](https://github.com/symunona/obsidian-hermes)). Cloud, so out.

## 3. The seven features

### 3.1 JARVIS HUD: orb, voice in and out, wall mode

**Orb.** Recommended: our own canvas 2D drawing (or one WebGL fragment shader)
in a vendored ES module, no library. A glowing orb is a radial gradient, a few
noise-displaced rings and a level value to drive them. Fallback:
[three.js](https://github.com/mrdoob/three.js) (MIT, `three.module.min.js` is
339 KB raw, 79 KB gzipped, measured from jsDelivr) if Rob wants a real 3D sphere.
Skip [Arwes](https://github.com/arwes/arwes) (MIT): its README says "The ARWES
project is no longer maintained and currently outdated", last release is
`v1.0.0-alpha.23` from 2023-08, and the useful parts are React. The HUD look
(thin frames, cut corners, monospace numbers, scan lines) is plain CSS.
Gotchas: honour `prefers-reduced-motion` with a still orb; check every panel
against Rob's design-slop list (no eyebrow labels above headings, no
filled-plus-ghost button pairs, no comma headlines).

**Audio-reactive.** Web Audio's `AnalyserNode` gives a level from the mic stream
and from any `<audio>` element
([MDN AnalyserNode](https://developer.mozilla.org/en-US/docs/Web/API/AnalyserNode)).
Gotcha: `speechSynthesis` output cannot be routed into Web Audio, so when the
browser voice is speaking the orb has to pulse from the utterance's `boundary`
events instead of the real waveform
([MDN SpeechSynthesisUtterance](https://developer.mozilla.org/en-US/docs/Web/API/SpeechSynthesisUtterance)).
Piper or Kokoro audio played through an `<audio>` element can be analysed.

**Voice out.** Recommended first: the browser's `speechSynthesis`, picking a voice
whose `localService` is true so nothing leaves the PC
([MDN SpeechSynthesisVoice](https://developer.mozilla.org/en-US/docs/Web/API/SpeechSynthesisVoice/localService)).
Zero install, works on a phone. Upgrade: Piper, now
[OHF-Voice/piper1-gpl](https://github.com/OHF-Voice/piper1-gpl) (GPL-3.0,
v1.8.0 on 2026-09-04, `pip install piper-tts` with a `win_amd64` wheel on
[PyPI](https://pypi.org/project/piper-tts/)). The old
[rhasspy/piper](https://github.com/rhasspy/piper) is archived. Running Piper as a
separate program is fine under GPL; we do not link it into our code. Second
upgrade: [Kokoro-82M](https://github.com/hexgrad/kokoro) (Apache-2.0 weights),
either in the browser through `kokoro-js` (Apache-2.0, depends on
`@huggingface/transformers`, per the npm registry) or as a local server through
[Kokoro-FastAPI](https://github.com/remsky/Kokoro-FastAPI) (Apache-2.0, active).
Kokoro sounds better than Piper and costs more CPU or VRAM. The video's "Daniel"
voice is very likely the macOS British voice of that name, so Rob will not have
it on Windows (not verified).

**Voice in.** Recommended: push-to-talk records with `MediaRecorder`, posts the
clip to a guarded PHP endpoint, and a worker sends it to
[whisper.cpp](https://github.com/ggml-org/whisper.cpp)'s `whisper-server` on
`127.0.0.1` (MIT, 54k stars). whisper.cpp ships Windows x64 CPU, BLAS and CUDA
zips on its build-tagged releases (for example `b5454` has
`whisper-bin-x64.zip` and `whisper-bin-win-cuda-12.4.0-x64.zip`). The server
takes `multipart/form-data` on `/inference`, binds `127.0.0.1:8080` by default,
and its README warns against running it as admin
([server README](https://github.com/ggml-org/whisper.cpp/tree/master/examples/server)).
Run a `base.en` or `small.en` model on the CPU so the GPU stays free for the
language model. Fallback: Chrome's Web Speech API `SpeechRecognition`. Plainly:
in Chrome it is cloud by default, "your audio is sent to a web service for
recognition processing"
([MDN](https://developer.mozilla.org/docs/Web/API/SpeechRecognition)). There is
an experimental `processLocally` flag with `available()` and `install()` for
language packs ([MDN](https://developer.mozilla.org/docs/Web/API/SpeechRecognition/processLocally)),
and a Chromium intent to ship it on desktop Chrome 135
([blink-dev](https://groups.google.com/a/chromium.org/g/blink-dev/c/VNOok2dbmHM/m/TQpe9shjCgAJ)).
If `processLocally = true` works in Rob's Chrome it is the zero-install option;
I could not confirm it shipped to stable. Gotchas: the mic needs a secure
context, and `http://127.0.0.1` counts as one
([MDN secure contexts](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts)).
Audio uploads are untrusted input: cap the size, accept one MIME type, and never
pass a client file name to the server. Silence makes Whisper invent phrases like
"Thank you for watching"; Hermes filters 26 known ones (voice-mode.md above), and
we should do the same.

**Wake word.** Optional, wall mode only, last. Licences decide this one:
- [openWakeWord](https://github.com/dscripka/openWakeWord): code Apache-2.0, but
  the pre-trained models, including "hey jarvis", are CC BY-NC-SA 4.0
  (non-commercial). Rob's dashboard is used in his business, so the stock models
  are not clean. A custom model trained with the project's own training notebook
  would be ours. Last release v0.6.0 from 2024-02; on Windows only ONNX models
  work.
- [Porcupine](https://github.com/Picovoice/porcupine): needs an AccessKey, and
  Home Assistant users relay a Picovoice email saying free-tier keys stopped
  working after 2026-06-30
  ([forum](https://community.home-assistant.io/t/fyi-picovoice-confirmed-free-tier-accesskeys-will-stop-working-after-june-30-2026/1012744);
  I did not find Picovoice's own notice). Out.
- [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx) (Apache-2.0, 15k stars,
  v1.13.8 on 2026-09-10) does open-vocabulary keyword spotting with no key; Hermes
  uses it as its free fallback. Recommended if we do this at all.

**Wall mode.** A `/wall` page: full-screen HUD with the orb, what's running, the
attention list, the project map and headlines. Use the Fullscreen API, the Screen
Wake Lock API so the second monitor does not sleep
([MDN](https://developer.mozilla.org/en-US/docs/Web/API/Screen_Wake_Lock_API)),
and optionally the Window Management API to open it on the second screen
([MDN](https://developer.mozilla.org/en-US/docs/Web/API/Window_Management_API)).
This is the one page that keeps a live connection open (see 3.3).

### 3.2 Project map

Recommended: a PHP collector on Task Scheduler every few minutes writes one row
per project to SQLite, and the page shows a grid of tiles (works with no JS and
on a phone). The same data feeds the graph in 3.5.

What it reads, all from disk or allowlisted `.exe` files through ProcessRunner:
- **Where:** `D:\Dev\htdocs` only. Checked locally: 66 entries in
  `C:\xampp\htdocs` are junctions into `D:\Dev\htdocs` (ai-ta, 4-muddy-paws and
  the rest), so scanning both roots double-counts, and SafeDir already rejects
  junctions. Plus the `extra` folders card 60 adds (kc-finance).
- **Git:** `git -C <dir> status --porcelain=v2 --branch` gives branch,
  ahead/behind and dirty count in one call; `git log -1 --format=%H%x00%cI%x00%s`
  gives the last commit.
- **Issues:** `gh issue list --json number,title,labels,updatedAt` per repo with a
  GitHub remote, every 30 to 60 minutes (the token stays in gh's own store).
- **Claude and the loop:** `.claude/` present; `.claude/work/loop/lock` (holds the
  loop's PID), `STOP`, `status.json` (`status`, `card`, `note`), the last line of
  `loop.log` (cost and the 5-hour and weekly usage percentages), and
  `.claude/work/checklist.json` (an array of cards with `id`, `title`, `passes`).
  Checked locally: `henike-contracting` has a lock whose PID is dead and a STOP
  file from 2026-10-15, so a lock alone does not mean "running". Liveness is
  "PID alive and it is `node.exe`".
- **How to draw it:** tiles first (name, branch, dirty, last commit age, open
  issues, loop badge with current card). Fallback: the force graph from 3.5 with
  projects, clients and vault notes as nodes. Export a
  [JSON Canvas](https://jsoncanvas.org/spec/1.0/) file (MIT spec,
  [repo](https://github.com/obsidianmd/jsoncanvas)) into the vault so Obsidian
  can show the same map as a Canvas; the spec is nodes (`id, type, x, y, width,
  height`) and edges (`id, fromNode, toNode`), small enough to write by hand.

Gotchas: run git with `GIT_OPTIONAL_LOCKS=0` so the collector never takes
`index.lock` while Rob or a loop is committing (git documents this variable;
I did not test it here). Never write into any project; the global rule forbids
touching a project whose loop is running, and the dashboard has no reason to.

### 3.3 Real-time status: what is running now

Recommended sources, in order of value:

1. **Claude Code's session registry.** Checked locally: Claude Code 2.1.301 and
   2.1.303 write `C:\Users\roban\.claude\sessions\<pid>.json` with `pid`,
   `sessionId`, `cwd`, `startedAt`, `procStart`, `kind`, `entrypoint`, `name`,
   `status` (`busy`, `idle`, `waiting`), `waitingFor` and `updatedAt`. Nine files
   were there; two were for dead sessions (last updated 2026-10-16 and 10-18), so
   check the PID is alive and its start time matches `procStart`. The live ai-ta
   loop's current pass (node 38576, then cmd.exe, then claude.exe 50000) has its
   own entry, so loop passes show up too. This is undocumented; third-party tools
   read the same files ([cmon](https://github.com/knowald/cmon)) and the SDK docs
   describe a different layout
   ([sessions](https://docs.anthropic.com/en/docs/claude-code/sdk/sdk-sessions)).
   Treat it as version-dependent and make the parser fail soft. The same folder
   holds `<pid>.<hash>.key` files: never read those.
2. **Loop state** from each project's loop folder (3.2), with the PID check.
3. **Scheduled jobs:** `schtasks.exe /query /tn "\KC Dashboard\" /fo csv /v` for
   last run, result and next run.
4. **Dev servers:** `netstat.exe -ano -p tcp` for listening ports plus
   `tasklist.exe /fo csv` for the program name. Show port, program and, when a
   project path appears in its command line, the project. Never store or show a
   full command line; they carry tokens and keys.
5. **Hooks (later, optional).** Claude Code hooks can POST their JSON to a URL
   (`"type": "http"`, with headers from allowed env vars) or run async commands,
   and every event carries `session_id`, `transcript_path` and `cwd`
   ([hooks](https://code.claude.com/docs/en/hooks)). That would give "which tool,
   which file" detail. It means editing Rob's global `~/.claude/settings.json`,
   which is his call, and an HTTP hook would need a write endpoint. A command hook
   that appends one line to a file the collector reads is the safer shape. The
   model for this is
   [disler/claude-code-hooks-multi-agent-observability](https://github.com/disler/claude-code-hooks-multi-agent-observability)
   (1.5k stars, last push 2026-02-08): Python hooks under `uv`, a Bun server,
   SQLite, WebSocket, Vue. It has no licence file, so read it, do not copy it, and
   its `--summarize` option needs `ANTHROPIC_API_KEY`.

Other Claude Code UIs, for reference only:
[claudecodeui / CloudCLI](https://github.com/siteboon/claudecodeui) (AGPL-3.0,
14k stars, active, Node 22, discovers existing sessions from Claude's project
folder, pushes a paid cloud tier);
[opcode](https://github.com/winfunc/opcode) (AGPL-3.0, last release 2025-08,
Tauri desktop); [crystal](https://github.com/stravu/crystal) (MIT, renamed
Nimbalyst, repo quiet since 2026-02); [vibe-kanban](https://github.com/BloopAI/vibe-kanban)
(Apache-2.0, README: "Vibe Kanban is sunsetting"). None fits a PHP page; the
registry file is the useful finding.

**Live transport.** Recommended: polling a small `api/now.php` every 5 seconds
while the tab is visible, paused on `visibilitychange`. That is the
mobile-first baseline and it costs nothing. Server-Sent Events only on the wall
page. Gotchas, most of them checked locally:
- Chrome allows 6 connections per host over HTTP/1.1 across all tabs, and our
  vhost is plain HTTP/1.1; MDN notes it is marked "Won't fix"
  ([MDN EventSource](https://developer.mozilla.org/en-US/docs/Web/API/EventSource),
  [textslashplain](https://textslashplain.com/2019/12/04/the-pitfalls-of-eventsource-over-http-1-1/)).
  The dashboard is the new tab page, so SSE on every tab would starve the API
  after six tabs. This alone rules out SSE as the default.
- PHP on Windows counts wall-clock time against `max_execution_time` ("This is
  not true on Windows where the measured time is real",
  [php.net](https://www.php.net/manual/en/function.set-time-limit.php)), and
  XAMPP's `php.ini` has `max_execution_time=120`. An SSE script must end itself
  before that (say every 55 seconds) and let `EventSource` reconnect.
- `output_buffering=4096` in the local `php.ini`; the script must
  `ob_end_flush()` and `flush()` after each event. `mod_deflate` is commented out
  in the local `httpd.conf` (good; it would buffer the stream).
- Apache here is `mpm_winnt` with `ThreadsPerChild 150` (local
  `httpd-mpm.conf`); each open stream holds one thread, which is fine for one wall
  page.
- The dashboard uses a header token, not PHP sessions. If sessions are ever
  added, `session_write_close()` before streaming, or every other request from the
  browser waits on the session lock.

### 3.4 News feed

Card 63 already plans this. Recommended: keep card 63's own parser (it is specced
with XXE and URL tests). Fallback if real feeds break it: vendor SimplePie's
single-file `SimplePie.compiled.php`, which is a release asset of 1.9.0
(2025-09-12), BSD-3-Clause, PHP 7.2+ with `ext-xml`
([repo](https://github.com/simplepie/simplepie)). Skip a separate reader:
[FreshRSS](https://github.com/FreshRSS/FreshRSS) is AGPL-3.0 and another PHP app
to run, [Miniflux](https://github.com/miniflux/v2) is Go plus PostgreSQL. Both
good, both more than this needs.

Feeds I fetched on 2026-10-19 (HTTP 200, newest item date in brackets where I
checked):
- AI: Simon Willison `https://simonwillison.net/atom/everything/` (2026-10-18);
  OpenAI `https://openai.com/news/rss.xml` (2026-10-16); Google AI
  `https://blog.google/technology/ai/rss/`; DeepMind
  `https://deepmind.google/blog/rss.xml`; Hugging Face
  `https://huggingface.co/blog/feed.xml`; Latent Space
  `https://www.latent.space/feed`; Ollama `https://ollama.com/blog/rss.xml`.
- Anthropic has no official feed: `anthropic.com/news/rss.xml` and
  `claude.com/blog/rss.xml` both return 404. A community scrape works,
  [Olshansk/rss-feeds](https://github.com/Olshansk/rss-feeds) (MIT, updated daily,
  newest item 2026-10-18), at
  `https://raw.githubusercontent.com/Olshansk/rss-feeds/main/feeds/feed_anthropic_news.xml`.
  Third party, so it can stop without notice; coverage health (card 70) will show
  that.
- Web dev: web.dev `https://web.dev/static/blog/feed.xml` (2026-10-02); Chrome
  for Developers `https://developer.chrome.com/static/blog/feed.xml`; Smashing
  `https://www.smashingmagazine.com/feed/` (2026-10-16); CSS-Tricks
  `https://css-tricks.com/feed/` (still alive, but newest post 2026-08-31);
  Frontend Masters `https://frontendmasters.com/blog/feed/`; Josh Comeau
  `https://www.joshwcomeau.com/rss.xml`; Mozilla Hacks; WebKit
  `https://webkit.org/feed/`; Frontend Focus and JavaScript Weekly
  (`frontendfoc.us/rss`, `javascriptweekly.com/rss`).
- PHP and WordPress: `https://www.php.net/feed.atom`, `https://stitcher.io/rss`,
  `https://laravel-news.com/feed`, `https://make.wordpress.org/core/feed/`.
- Platform and security: GitHub blog and changelog, Cloudflare blog, CISA
  advisories `https://www.cisa.gov/cybersecurity-advisories/all.xml`,
  BleepingComputer.
- Aggregators: Hacker News Algolia
  `https://hn.algolia.com/api/v1/search?tags=front_page` (JSON, gives points),
  `https://lobste.rs/rss`.
- Dead: AINews at `buttondown.com/ainews/rss` stopped in April 2025.

### 3.5 Knowledge graph

Recommended: [force-graph](https://github.com/vasturiano/force-graph) (MIT,
v1.52.0 on npm, last push 2026-09-28). It is a canvas force layout that looks
like Obsidian's graph, 173 KB raw and 56 KB gzipped as one UMD file from jsDelivr,
so it vendors into `public/assets/vendor/` with no build. PHP builds the nodes and
edges: vault notes and their `[[wikilinks]]` and tags, projects, clients, and
facts that name a project. Fallback for big graphs: [sigma.js](https://github.com/jacomyal/sigma.js)
(MIT, v4.0.3 on 2026-10-15, WebGL) with [graphology](https://github.com/graphology/graphology)
(MIT); sigma 3's UMD file measured 47 KB gzipped plus graphology 14 KB. Others:
[Cytoscape.js](https://github.com/cytoscape/cytoscape.js) (MIT, v3.34.4, 137 KB
gzipped, best for graph analysis), [vis-network](https://github.com/visjs/vis-network)
(Apache-2.0 or MIT, 154 KB gzipped), [3d-force-graph](https://github.com/vasturiano/3d-force-graph)
(MIT, 365 KB gzipped, three.js inside; nice on the wall, heavy on a phone). With
no JS, the page shows the same data as a list of nodes and their links.

Obsidian's own graph cannot be put in a web page: it lives in the desktop app.
Obsidian Publish is hosted and costs $8 to $10 per site per month
([pricing](https://obsidian.md/pricing)). [Quartz](https://github.com/jackyzha0/quartz)
(MIT) turns a vault into a static site with a graph, but needs a Node build. The
Obsidian graph plugins are weak: [Juggl](https://github.com/HEmile/juggl) (GPL-3.0,
quiet since 2025-02), [obsidian-3d-graph](https://github.com/AlexW00/obsidian-3d-graph)
(MIT, quiet since 2023-10), [ExcaliBrain](https://github.com/zsviczian/excalibrain)
(MIT, active).

### 3.6 Kanban cards

Two boards, because there are two kinds of cards.

1. **Loop cards, read-only.** Each project's `plan.md` cards and
   `checklist.json` already are a kanban: planned, passing, parked, current (from
   `status.json`). The dashboard shows them per project and never writes them,
   because the loop owns them and the global rule forbids changing a project
   whose loop is running.
2. **Rob's own board** as a Markdown file in the vault, in the Obsidian Kanban
   plugin's format so Obsidian can show it too: `## Column` headings and
   `- [ ] card` items, with the plugin's front matter. The plugin
   ([repo](https://github.com/community-archive/obsidian-kanban), GPL-3.0, 4.5k
   stars) last released 2.0.51 on 2024-05-31 and its README says it "is looking
   for new maintainers", so treat the format as frozen and parse only that
   subset. With no JS each card has a small form (column select plus Move); with
   JS, [SortableJS](https://github.com/SortableJS/Sortable) (MIT, v1.15.7,
   15 KB gzipped, touch support) adds drag. Fallback: plain HTML drag-and-drop,
   which does not work on phones.

Hermes's Kanban is a dispatcher for agent workers in its own SQLite, so it only
matters if Hermes is installed.

### 3.7 Daily briefing, "remember that", briefing history

Recommended: a vault folder (say `D:\Dev\vault\kc\`) that the dashboard owns:
`Briefings/2026-10-19.md`, `Memory/2026-10.md`, `Boards/Rob.md`, `Maps/projects.canvas`.
- **Briefing.** A 6 am job builds the facts in code (attention list, loops and
  what they finished overnight, projects changed since yesterday, top headlines,
  KC numbers) and writes them to the day's file. The words around them come from
  the daily read card 72 already plans (one `claude -p` call a day), or from the
  local model (section 4), never both. The code-built list is always shown, so a
  model that fails or leaves something out cannot hide it, which is the
  attention-engine rule. "Brief me" reads it aloud.
- **Remember that.** Appends a dated line to the month's memory file and to an
  SQLite FTS5 index for recall. Checked locally: XAMPP's PHP has SQLite 3.39.2
  with FTS5 working.
- **History.** A list or clock of past briefing files, newest first; each is plain
  Markdown, so it also reads in Obsidian.

Obsidian's part: optional. It is free for work use since 2025-02-20
([free for work](https://obsidian.md/blog/free-for-work/)). The vault is plain
files, so the dashboard reads and writes them directly and Obsidian, when open,
picks up the changes. The page links into Obsidian with
`obsidian://open?vault=<name>&file=<path>` ([Obsidian URI](https://obsidian.md/help/Extending+Obsidian/Obsidian+URI)).
Not needed: the [Local REST API plugin](https://github.com/coddingtonbear/obsidian-local-rest-api)
(MIT, v5.5.2 on 2026-10-17; HTTPS on 27124 with its own CA, bearer key, an MCP
server, and it only works while Obsidian is running), the
[Obsidian CLI](https://obsidian.md/help/cli) (needs installer 1.12.7+ and the app
running), [Dataview](https://github.com/blacksmithgu/obsidian-dataview) (MIT, quiet
since 2025-11) or [Datacore](https://github.com/blacksmithgu/datacore) (MIT,
0.1.29), [Homepage](https://github.com/mirnovov/obsidian-homepage) (MIT). Archived,
so out: [obsidian-rss](https://github.com/joethei/obsidian-rss) and
[Projects](https://github.com/obsmd-projects/obsidian-projects). MCP servers that
read a vault as files exist if Rob wants Claude Code sessions to use it:
[mcpvault](https://github.com/bitbonsai/mcpvault) (MIT, active),
[mcp-obsidian](https://github.com/MarkusPfundstein/mcp-obsidian) (MIT, needs the
REST plugin).

Gotchas: write atomically (temp file, then rename); code makes every path and
file name, never a model or a request; refuse anything outside the vault root
using the SafeDir checks; model text going into a note is data, so a note can
never contain a `obsidian://` or `file:` link the dashboard then renders as a
link. Rob's own memory system already lives in `c:\personas\` (a journal and an
HDC memory CLI). Keep the dashboard vault separate; linking the two is a later
question for Rob.

## 4. The local assistant brain

Rob's ask: a local model for voice, intent routing, briefings and chat, handing
coding and tool jobs to Claude Code or Codex CLI. Primary candidate Qwen 3.6
35B-A3B, fallback gpt-oss-20b. Machine: RTX 5060 8 GB, 32 GB RAM, Ryzen 7 7700.

**The candidates exist.** [Qwen/Qwen3.6-35B-A3B](https://huggingface.co/Qwen/Qwen3.6-35B-A3B)
(Apache-2.0, released 2026-04-15): 35B total, 3B active, 256 experts with 8
routed plus 1 shared, 262k native context, thinking on by default and turned off
with `chat_template_kwargs: {"enable_thinking": false}`. GGUF sizes from
[unsloth/Qwen3.6-35B-A3B-GGUF](https://huggingface.co/unsloth/Qwen3.6-35B-A3B-GGUF):
UD-Q4_K_M 22.1 GB, UD-Q4_K_XL 22.4 GB, UD-IQ4_XS 17.7 GB, UD-Q3_K_M 16.6 GB.
[openai/gpt-oss-20b](https://huggingface.co/openai/gpt-oss-20b) (Apache-2.0),
Q4_K_M 11.6 GB. (Nemotron 3.5 Lightning 30B-A3B also exists, from 2026-08,
licence OpenMDW-1.1, Q4 GGUF 25.3 GB
([card](https://huggingface.co/nvidia/NVIDIA-Nemotron-3.5-Lightning-30B-A3B-NVFP4));
dropped from this report as asked.)

**The finding that decides it: memory.** Checked locally while Rob was working:
31.1 GB of RAM visible, 3.4 GB free, with the open VS Code sessions and their
`claude.exe` processes taking about 3 GB between them. The GPU showed 1.7 GB of
8 GB in use. Unsloth puts the 4-bit 35B-A3B at 23 GB of RAM plus VRAM
([Unsloth Qwen3.6 guide](https://unsloth.ai/docs/models/qwen3.6)). With about
6.5 GB of the model on the GPU, roughly 15 GB has to sit in system RAM. On this
PC during a normal working day that means paging, and an always-on assistant
that pages is unusable. Even the 16.6 GB Q3 file needs about 10 GB of RAM.

**Speed, if it fits.** No benchmark exists for this exact card. Nearest numbers,
all single-user reports: about 24 tok/s for Qwen 3.6 35B-A3B Q4_K_M on an 8 GB
GTX 1080 with `--n-cpu-moe 30`
([r/LocalLLaMA snapshot](https://reddit.sentinel-team.org/posts/1tcc7h5/snapshots/2026-05-15T23%3A40%3A01.25497Z));
3 to 17 tok/s on a 6 GB GTX 1060 after five flags, `--n-cpu-moe` alone going
3 to 10 ([guide](https://jpmrblood.codeberg.page/2026/06/02-llama-cpp-35b-model-6gb-vram-guide/));
8.7 to 40+ tok/s on an 8 GB 3070 Ti, but with a smaller IQ3_XXS quant
([Medium](https://medium.com/coding-nexus/one-flag-tripled-my-tokens-per-second-on-an-8gb-gpu-238f55d1e3bd)).
Checked locally: the RAM is DDR5-6000, two sticks. My estimate for this PC is
15 to 30 tok/s generating, with prompt reading noticeably slower than on a
GPU-only model because the experts run on the CPU. For voice the wait before the
first word depends on prompt length, so keep the system prompt short and let
llama-server's prompt cache reuse it. One more catch from an independent test:
when Qwen 3.6 fits fully in VRAM, expert offload is pure overhead (107.8 vs
21.7 tok/s on two 16 GB cards) ([LLMKube](https://llmkube.com/blog/hybrid-moe-offloading-qwen-36-deltanet)),
which is the case for 3B-active models only when the whole file fits.

**Tool calling.** Qwen's own card reports TAU3-Bench 67.2, MCP-Atlas 62.8 and
Terminal-Bench 2.0 51.5 for 35B-A3B (vendor numbers, not independent), and
Unsloth says its template fixes "improved parsing nested objects to make tool
calling succeed more" ([guide](https://unsloth.ai/docs/models/qwen3.6)).
llama.cpp needs `--jinja` for tool calls, warns that a generic fallback format
"may consume more tokens and be less efficient", and warns that extreme KV-cache
quantization such as `-ctk q4_0` "can substantially degrade the model's tool
calling" ([function-calling.md](https://github.com/ggml-org/llama.cpp/blob/master/docs/function-calling.md));
the current `q8_0` setting in the spec is fine. That doc lists Qwen 2.5 and
gpt-oss (Harmony) as native formats; I could not confirm from it whether Qwen
3.6's `qwen3_coder` XML tool format is native in llama.cpp or falls back to
generic. My recommendation sidesteps the question: the router does not need
tool calling. It needs one JSON object, `{intent, project, text}`, forced by a
`json_schema` response format, which this repo already measured at 20 of 20
valid with Bonsai.

**gpt-oss-20b as the fallback: no.** This repo already benched it: 65.7% right,
34.3% wrong, 57% of it on the CPU, slower than Claude, and 78 "fixes" to history
files, rejected (`.claude/work/attention-engine.md`). Outside reports add Harmony
format trouble with structured output in Ollama
([glukhov.org](https://www.glukhov.org/post/2025/10/ollama-gpt-oss-structured-output-issues/))
and Harmony tokens leaking through llama.cpp
([discussion 15341](https://github.com/ggml-org/llama.cpp/discussions/15341)).
Nothing new changes the local result.

**What I recommend instead.** Two tiers, run by the ModelManager the old card 61
describes (one model in VRAM, `-np 1`, canary prompt, load on demand):
- **Always-on: a small dense model that fits entirely in VRAM.**
  [Qwen3.5-9B](https://huggingface.co/Qwen/Qwen3.5-9B) (Apache-2.0, 2026-02-27) is
  5.7 GB at Q4_K_M ([unsloth GGUF](https://huggingface.co/unsloth/Qwen3.5-9B-GGUF)).
  Bonsai is the other choice and is already measured here at 46 tok/s and 1.4 s
  per headline (SPEC.md), with its known weakness of saying "fine" when it is not
  (81.6% on the bench). Intent routing and briefing narration are small, forced
  JSON jobs that suit either. A first card should bench both on a routing set
  before choosing.
- **On demand: Qwen 3.6 35B-A3B for chat**, loaded only when the ModelManager sees
  enough free RAM (say 18 GB) and unloaded after a few idle minutes. Evenings and
  wall mode, not mid-session.
- **Deterministic first.** "Brief me", "remember that ...", "show me <panel>",
  "open <project>" are matched by code before any model sees them. The model only
  gets what code could not place, and "else chat" is the default.

Hermes against a local endpoint works (section 2), but it adds nothing here once
the router is a forced-JSON call.

**Handing a job to Claude Code or Codex, safely.**
1. The local model never starts anything. Its output is a proposal: intent
   `handoff`, a project name, a task in plain words.
2. Code checks the project against ProjectList (card 60) and shows a card with
   the exact prompt and the target. Rob clicks Send (token, Origin and Host
   checked like every other write). Voice can say "send it" only while that card
   is on screen.
3. The worker runs the same ClaudeRunner card 60 specs: absolute `claude.exe`,
   `-p --model sonnet --output-format json`, prompt on stdin, no permission-bypass
   flags. For read-only jobs add `--permission-mode dontAsk` with a narrow
   `--allowedTools`; for unattended runs `--permission-prompts none` stops it
   waiting on a prompt nobody will answer
   ([headless docs](https://code.claude.com/docs/en/headless)). Do not use
   `--bare`: it "never reads OAuth credentials" and needs `ANTHROPIC_API_KEY`
   (same page), which the no-API rule forbids. Also from that page: without
   `--bare`, a `-p` run in a project folder runs that project's hooks and
   `.mcp.json` servers with no trust prompt. Fine for Rob's own projects, which is
   why untrusted data keeps going to the empty temp folder.
4. For real coding work, the better handoff is opening an interactive session
   Rob watches, the "open in terminal" button (old card 25) with the prompt
   prefilled through `wt.exe`, rather than an unattended edit.
5. Untrusted text (news, mail, web pages) never goes into the same model call
   that can propose a handoff. That is the prompt-injection path: a headline
   saying "ask Claude to push to main" must not be able to make a Send card.
6. Codex: `codex exec` runs read-only by default, `--sandbox workspace-write`
   allows edits, `--json` streams JSONL
   ([non-interactive docs](https://developers.openai.com/codex/noninteractive)).
   Codex needs a ChatGPT plan login or an OpenAI API key, so it is not free; I did
   not check which plans include it. On Windows there is a report of `codex exec`
   failing writes when its resources folder is not on PATH
   ([#28278](https://github.com/openai/codex/issues/28278)). And ProcessRunner
   refuses `.cmd`, which is what an npm install puts on PATH, so the real
   `codex.exe` path inside the npm package has to be found and allowlisted (not
   checked).
7. Terms: running the unmodified `claude` binary with Rob's own login is allowed
   ("Nor does it prevent an end user from signing in to the unmodified Claude Code
   binary with their own Claude subscription",
   [legal](https://code.claude.com/docs/en/legal-and-compliance)); the same page
   says the advertised Max limits "assume ordinary, individual usage", so every
   handoff counts against the plan like any other session.

## 5. Architecture in plain words

Everything runs on Rob's PC; only loopback ports are open.

- **Apache + PHP (the dashboard)** serves the page on `127.0.0.1:8787` from
  `public/` and small JSON endpoints. Reads come from SQLite and the vault. Every
  write or job start goes through the existing guard (POST, token, exact Origin
  and Host) and into the job queue. The page never waits on anything slow.
- **The worker and scheduled collectors** (PHP CLI under Task Scheduler, S4U) do
  the slow work: project scan, running-now scan, news, facts, briefing, model
  jobs, `claude -p` jobs. Each child process goes through ProcessRunner with an
  absolute, allowlisted `.exe`: `git.exe`, `gh.exe`, `claude.exe`, `schtasks.exe`,
  `netstat.exe`, `tasklist.exe`, `llama-server.exe`, `whisper-server.exe`, and
  later `piper.exe` and `codex.exe`.
- **Local services**, started on demand by the worker and bound to `127.0.0.1`:
  llama-server on 8081 (one model at a time), whisper-server on its own port, and
  optionally Piper or Kokoro. Only the worker talks to them; the browser never
  does, so no new port is reachable from a web page.
- **Read-only sources on disk:** `~/.claude/sessions/*.json` (never the `.key`
  files), each project's git and `.claude/work/` files, kc-finance's
  `alerts.json`.
- **The vault** is a folder the dashboard writes (briefings, memory, Rob's board,
  the project canvas). Obsidian may open it; nothing depends on that.
- **The browser** shows the normal page (polls every 5 s while visible, works with
  no JS) and `/wall` (full screen, one SSE stream, orb, wake lock). Voice is in the
  page: push-to-talk records, uploads the clip, and the reply is spoken with
  `speechSynthesis` or a Piper or Kokoro clip.
- **Claude Code** stays where it is: Rob's VS Code sessions and the loops, which
  the dashboard only watches, plus the `claude -p` jobs it starts after a click.

## 6. Build estimate in cards

Each card is a few hours of loop work with its own done-test, after card 62 (the
page shell) lands. Twenty cards. Ordered so something visible ships within the
first four.

**A. See everything (no new installs)**
- J1 Project scanner: `D:\Dev\htdocs` plus extras, git status and last commit,
  `.claude` and loop files, checklist counts, into a `projects` table.
- J2 Running-now collector: session registry with PID and start-time check, loop
  liveness, scheduled tasks, listening ports, into a `running` table;
  `api/now.php`. **Risky:** undocumented registry format; command lines must
  never be stored.
- J3 Project map tiles and the running strip on the main page, no-JS first, 5 s
  polling with visibility pause.
- J4 HUD theme and canvas orb (reduced-motion still frame), design-slop check.
- J5 Wall page: full screen, wake lock, one SSE stream with the 55-second cycle.
  **Risky:** PHP wall-clock limit and buffering on Windows.

**B. Cards and graph**
- J6 Loop kanban, read-only, from each project's `plan.md` and `checklist.json`.
- J7 Vault module: config path, SafeDir-checked atomic writes, code-made file
  names. **Risky path** (file writes).
- J8 Rob's board in Obsidian Kanban format, no-JS move form, SortableJS drag.
- J9 Graph data endpoint (projects, clients, notes, wikilinks, facts) and
  force-graph view with the no-JS list.
- J10 JSON Canvas export of the project map into the vault.
- J11 `gh` issue counts per project in the scanner (rate-limited schedule).

**C. Briefing and memory**
- J12 Briefing composer and the day file, code-built list first, words from the
  card 72 read.
- J13 "Remember that" endpoint, monthly memory file and FTS5 recall.
- J14 Briefing history page.

**D. Voice and the local brain**
- J15 Voice out with `speechSynthesis`, local voice picker, orb pulse on
  boundary events.
- J16 Push-to-talk: upload endpoint, whisper-server through ProcessRunner, text
  back. **Risky:** a new process and an upload endpoint; size caps, one MIME type.
  Needs Rob to place the whisper.cpp zip and model (an install, so the card parks
  until he does).
- J17 ModelManager (old card 61 revived: `-np 1`, canary, load on demand, free-RAM
  check) and a bench of Qwen3.5-9B against Bonsai on a routing set. Needs the 9B
  file downloaded (Rob).
- J18 Intent router: code rules first, then the local model with a forced JSON
  schema; unknown goes to chat.
- J19 Handoff cards: proposal, click to Send, ClaudeRunner job; Codex later.
  **Highest risk in the plan**, since this is the path from voice to code
  execution. Security-auditor pass required.

**E. Optional, last**
- J20 Wake word in wall mode with sherpa-onnx keyword spotting, or a custom
  openWakeWord model. **Risky:** always-on mic, licence of any stock model.
- Later, if Rob agrees to edit global settings: Claude Code hooks feeding
  tool-level detail into the running strip.
- Piper or Kokoro voice, and Qwen 3.6 35B-A3B chat on demand, each one card once
  the 9B tier works.

The existing security audit card (69) should widen to cover J2, J7, J16, J18 and
J19.

## 7. What I could not verify

- Whether Chrome stable on Rob's PC supports `SpeechRecognition.processLocally`
  for English, and whether its language-pack install works.
- Real speeds on this PC: Qwen 3.6 35B-A3B with `--n-cpu-moe`, Qwen3.5-9B,
  whisper.cpp `base.en` latency on the Ryzen 7700. All figures above are other
  people's hardware; `llama-bench` here is the only real answer.
- Whether llama.cpp parses Qwen 3.6's tool-call format natively or uses the
  generic fallback.
- Whether `claude -p` passes always appear in `~/.claude/sessions/`. One loop pass
  did (entry 50000, recorded as `kind: interactive`, `entrypoint: claude-vscode`,
  probably inherited from the VS Code terminal the loop was started in), so the
  `kind` and `entrypoint` fields cannot tell a loop pass from a person. The
  process tree can.
- Picovoice's own notice ending the free tier (only forum relays found).
- Which Windows voices `speechSynthesis` lists as local on this PC, and whether
  Chrome's "Google" voices are network voices here.
- Kokoro's download size in the browser and its speed on this CPU or GPU.
- Whether sherpa-onnx's prebuilt WebAssembly keyword-spotting files run from a
  vendored folder with no build.
- Where the real `codex.exe` lives inside the npm install, and which ChatGPT plans
  include Codex.
- That `GIT_OPTIONAL_LOCKS=0` fully avoids index-lock contention with a running
  loop on Windows.
- How Obsidian behaves when the dashboard rewrites a note that is open in it.
