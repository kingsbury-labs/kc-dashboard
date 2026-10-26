# KC Cockpit: the Agent OS spec

For Rob to approve. Written 2026-10-23 from `.claude/work/agentos/research.md`,
`data-spike.md`, `speedup.md`, `.claude/work/mission-control.md`, the images in
`design-refs/`, `docs/SPEC.md` and the global rules. Nothing in this file is built
yet. Where it says "checked", I ran it on this PC today.

## 1. What it is

One window where I run the whole business's agent work. Every Claude Code session
on this PC, every project's loop and every agent they start shows up in one place,
live, as a hierarchy: my PM agent at the top, a manager for each project under
it, and the workers under those. I can see what any of them is doing right now,
read what they said to each other, open one session full size, or step back and
watch all of it at once. No more four or five VS Code windows to keep track of.

In the morning it is already running. The PM has read the overnight results, the
briefing, kc-finance's numbers and the mail triage, and has the day's work lined
up as proposals I say go to. Important mail has replies drafted and waiting in the
mailbox's Drafts folder for me to send myself. Every project runs the same rules,
hooks and loop, and the cockpit tells me the moment one falls out of step. It
looks like the JARVIS sample I picked, and it moves like it.

## 2. Decisions this spec builds on

These were made before this spec and are not reopened here.

- Desktop is the working surface. A phone-width window gets a read-only stacked
  view. Base styles still work on a phone; panes and terminals layer on at
  desktop widths (global rule 5).
- Workers are real `claude` CLI sessions on Rob's Max login: background sessions
  (`claude --bg`), `claude agents --json`, `claude attach`, and `claude -p` with
  stream-json. The cockpit never calls Claude or any model API itself. No API key.
  The Max plan's API credit stays unclaimed.
- Parallel work is allowed (Max 20x through October). There is no fixed worker
  cap; a usage meter on the 5-hour and weekly windows throttles the scheduler. One
  loop per project at a time.
- Agents never send email. Drafting is a mail-triage card (`D:\Dev\mail-triage`).
  Drafts land in the mailbox's Drafts folder and the cockpit lists them in an
  approvals queue.
- Projects in scope: every folder under `D:\Dev\htdocs\` with
  `.claude/project.md`, plus `C:\Users\roban\kc-finance`. Checked today that is 15:
  `_kc-dashboard`, `ai-ta`, `bandpilot`, `besciworks`, `henike-contracting`,
  `kc-care`, `kc-cms`, `kc-cms-app`, `kc-test-site`, `kingsburycreative`, `sembr`,
  `sparrowbook`, `sparrowbook-hosted`, `thecupboard` and `kc-finance`.
- Same repo as the dashboard. The PHP app stays as it is. A small Node service
  lives in `cockpit/`: plain Node, no build step, node-pty and xterm.js for
  terminals, server-sent events for live updates. It binds to 127.0.0.1 and uses
  the same token, Origin and Host checks as SPEC.md's Security section.
- The PM (the orchestrator) has its own home, `D:\Dev\hq`, with its own CLAUDE.md,
  memory and standing brief, apart from every project.
- The data backbone is the transcript index from `data-spike.md`, made permanent:
  SQLite in the dashboard's `data/` folder, read incrementally. There is no global
  per-tool-call logging hook. Hooks that load files themselves (the SessionStart
  journal loader) append what they loaded to a small access log, and
  `claude-loop.cjs` records which session started each run. The index holds paths
  and metadata only; the one exception is briefs and reports between agents, cut
  to 500 characters and scrubbed. Persona files under `c:\personas\` appear in the
  graph as nodes with paths and access counts, nothing more.
- Agent teams stay off.

## 3. Who is who: the hierarchy

Rob's real setup maps onto three levels. Rob himself is the operator and is not a
node; he sits in the top bar.

**Orchestrator (the core of the globe).** The PM session: a background session
named `pm` whose working folder is `D:\Dev\hq`. Until phase 3 builds it, the core
shows an empty slot labelled "PM not running", and everything below hangs off the
core directly as "started by Rob".

**Managers (the inner orbit).** Anything that coordinates work in one project:

- A loop run. One `claude-loop.cjs` process per project, found from
  `.claude/work/loop/lock` plus a live PID check (a lock alone does not mean
  running). Its coordinator passes (work, gate, push, audit, ci, handoff) are
  the manager's turns. They are drawn as its timeline, not as separate managers,
  because gate and push passes resume the card's session.
- An interactive window. Each Claude Code session Rob has open in a project
  folder, in VS Code or a terminal. `claude agents --json` reports these today
  with `kind: "interactive"` and `status: "idle"` or `"busy"` (checked: three
  live, among them `sparrowbook-9d` and `sembr-41`).
- A background session the cockpit or the PM started (phase 2 onward).
- Jobs. Headless `claude -p` runs outside the loop: the dashboard's Ask Claude
  box, the 6 am daily read, mail-triage's Haiku sorting, kc-finance's scheduled
  runs. They group under one dim "jobs" manager per project. The spike found 234
  of these.

**Agents (the outer orbit, circling their manager).** Every subagent a manager
starts: `implementer`, `sweeper`, `planner`, `security-auditor`,
`general-purpose`, `Explore`, `Plan` and the rest, typed by the `agentType` in
each `agent-<id>.meta.json`. A subagent started by a subagent nests one level
deeper (the spike found 3). Workflow subagents under `subagents/workflows/wf_*`
group under one workflow node.

**How the links are known.**

| Link | Source | Resolved today |
|---|---|---|
| Agent to the session that started it | meta file `toolUseId` | 1,852 of 1,852 |
| Nested agent to parent agent | meta file `parentAgentId` | 3 of 3 |
| Loop run to the session that launched it | `launched_by` in the run's `status.json` (phase 2 loop change); a ten-minute heuristic until then | 107 of 181 by heuristic |
| Manager to the PM | the cockpit records every dispatch the PM makes (phase 3) | none yet |
| Agent-to-agent messages | `Agent` briefs, `SubagentHandback` reports, `SendMessage` calls | 1,842 reports, 266 of 302 messages to a known id |

## 4. The screens

One page app at `http://127.0.0.1:8788/`, opened as a Chrome app window
(`chrome.exe --app=http://127.0.0.1:8788/`). The left rail lists the screens.
Every screen has its own address (`#/overview`, `#/project/sembr`,
`#/session/<id>`), so the back button works and a view can be bookmarked.

**Moving around.** Double-click any node, row or tile to drill into it. Esc goes
up one level. A breadcrumb under the top bar shows the path (`Overview > sembr >
card 41 > implementer a54096b1`). Ctrl+K opens a palette that finds projects,
sessions by name or id, files and cards. `G` then a letter jumps to a screen
(`G O` overview, `G W` workforce, `G M` memory, `G A` approvals, `G U` usage,
`G R` rules).

**Panes.** On desktop the work area is a set of dockable panes (dockview-core).
Any session, project or graph opens as a tab; dragging a tab to an edge splits
the area, so two sessions can sit side by side with the graph under them. Rob can
save a layout under a name ("Morning", "Build", "Watch"). Saved layouts are a
per-browser convenience in localStorage, wrapped in try/catch; with none saved
the default layout loads. On a phone-width window there are no panes: one screen
at a time, stacked, read-only.

### 4.1 Overview

**Job:** everything at once, readable in five seconds. This is the approved
`1-jarvis-command-center.png` layout, with the workforce as its heart. Every
panel leads with a visualisation; section 5.8 names each one and its data.

- **Centre: Cas.** A WebGL voxel head, Cas, the agent Rob works with, made of
  the memory index and dissolving below the jaw into the workforce network
  (section 5.10). While the PM session runs, Cas is the PM. Under the jaw hangs
  each project with a manager active in the last 24 hours, as a ring in its
  manager colour; its agents are small satellites circling it; idle projects
  sit dim further down. A comet flies agent to manager to Cas on each tool
  call, and a radial histogram of tool calls per minute rings the head as a
  halo. With the camera on, Cas's gaze follows Rob; it speaks with moving lips
  and captions. Without WebGL2 the centre is the canvas globe from
  `public/assets/orb.js` grown into the hierarchy (the earlier design).
- **Left of Cas: Needs you.** Count and list of sessions waiting for input,
  parked loop cards, PM proposals, mail drafts and failed runs, newest first.
  One click opens the item. From `claude agents --json`, loop `status.json` and
  the approvals table.
- **Right of Cas: Live feed.** The event stream (section 7.4): agents
  spawned and finished, gates passed and failed, messages between agents, with
  a tag (LIVE, DONE, FAIL, MSG). Pauses when scrolled up and shows "12 new".
- **Middle row:** Workforce strip (one instrument per manager: name, kind,
  current card or task, an elapsed dial against its usual pass time, a sparkline
  of tool calls per minute in its colour),
  Timeline (loop runs today as bars, scheduled jobs with "in 42 min" countdowns),
  Usage meter (section 4.8, small form).
- **Bottom row:** Memory insights (a mini constellation of today's hottest
  files, top five as bars, counts), Rules (drift count per project, section 9), Index health (last
  index pass, files behind, records the parser did not understand).

### 4.2 Project

**Job:** one project's whole state. Header: name, branch, dirty file count, last
commit age (from the dashboard's existing project scanner), loop state.

- Managers in this project and their agents, as a tree with live status.
- The loop: current card, pass, gate result, the last ten cards with time per
  pass as a waterfall (from `loop_passes`), cost per card using the largest
  `total_cost_usd` per session (summing passes double counts; the spike found
  $3,863 summed against $1,639 real).
- Board: the cards waiting, from the project's board file.
- Memory: this project's auto-memory files, CLAUDE.md, `project.md`, with access
  counts and who touched them last.
- Rules: this project's drift row (section 9).

### 4.3 Session

**Job:** one session or one card, full size.

- **Transcript, live.** Read straight from the session's `.jsonl` while it is
  open, not from the index, so it shows full text. Rob's prompts and the
  assistant's replies as blocks. Tool calls as one-line rows (`Read
  src/Http/RequestGuard.php 0.2 s`), expandable to the scrubbed input. An `Agent`
  call row expands into that agent's own transcript, nested, with the brief at
  the top and the report at the bottom. New lines arrive within 2 seconds of
  being written. Every string passes through the secret scrubber
  (`C:\Users\roban\.claude\hooks\secret-patterns.cjs`) before it reaches the
  page, and is written with `textContent` only.
- **Trace.** A waterfall of timed bars after OpenAI's and Agno's trace views:
  for a card, each pass is a bar, the sessions inside it under that, the
  subagents under those, the tool calls inside each. Hover gives the numbers.
- **Files touched.** Every path read, written or searched, with the op and time.
  Shell commands that touched files show as "shell, paths unknown"; the cockpit
  never guesses at them (data-spike gap 1).
- **Terminal (phase 2).** An xterm.js pane running `claude attach <id>`, so Rob
  can type into the real session. Closing the pane detaches; it never stops the
  session.
- **Chat (phase 3).** For the PM: a chat pane over `claude -p --input-format
  stream-json --output-format stream-json --verbose`.

### 4.4 Workforce (the hierarchy)

**Job:** who is working for whom, right now and over a chosen window (now, today,
7 days).

Two views of the same data, side by side on desktop, the tree alone on a phone:

- **Orbit view.** The hierarchy as tilted orbits at full size with labels: Cas
  (the PM) at the centre, managers on the inner ring, agents circling their
  manager, nested agents circling their parent agent, and messages between
  them drawn as arcs (section 5.8). The overview shows the same hierarchy as
  the network under Cas's head.
- **Tree view.** An indented list grouped the way `claude agents` groups:
  Needs you, Working, Ready for review, Done. Each row: name, kind, project,
  model, elapsed, current tool and target. Arrow keys move, Enter drills in. This
  is also the no-JS and phone rendering.
- **Messages tab.** Every brief, report and `SendMessage` between agents and
  sessions, newest first, filterable by project and agent type (2,163 in the
  index today). A row opens the full text from the transcript.

### 4.5 Memory (the access graph)

**Job:** the galaxy of everything agents read and write, and who touched what.
Built to `7-memory-galaxy.png`.

- **Nodes.** Files: persona files (`c:\personas\`), auto-memory under
  `~/.claude/projects/*/memory/`, instruction files (each CLAUDE.md and
  `project.md`), vault notes, project docs. Projects. Optionally sessions and
  agents (a toggle). Spike numbers: 492 distinct memory paths (355 auto-memory,
  135 persona, 2 vault), 15 projects, 1,260 main sessions, 1,852 agents. About
  3,700 nodes with agents shown.
- **Edges.** One per agent and file, weighted by how often and how (load, read,
  write, search), aggregated from 86,400 access rows. Vault `[[links]]` as faint
  static edges.
- **Shape.** The layout is the meaning. The files every session loads
  (`corrections.md` at 3,125 touches, `design-slop.md`, `soul.md`,
  `voice-samples.md`) pull to the bright centre. Each project's memory forms an
  arm around its project node. Star brightness is access count on a log scale.
  Written-to files get a warm rim.
- **Live.** An access arriving draws a comet from the agent to the file that
  fades over 30 seconds, and the file's glow rises (Agent Flow's heat map idea).
- **Time scrubber.** Last 24 hours, 7 days, 30 days, all. Play replays a day at
  60x so Rob can watch the morning happen.
- **Click a file:** who accessed it, when, how, from which project, with a link
  to each session. Search box filters the galaxy to matches.
- **What it never shows:** file contents, anything under `.credentials`, `.env`,
  `*.key`, or any path the credential-read-blocker refuses. One list, shared with
  the indexer.

### 4.6 Morning (phase 4)

**Job:** what Rob reads with coffee. Opens by default between 05:00 and 11:00.

- Overnight: loop runs finished, cards done, failures with one line why, cost.
- Needs you: the approvals queue's top items.
- The PM's plan: proposed dispatches for today, each with one Go.
- Money in and out: from `C:\Users\roban\kc-finance\alerts\alerts.json` only,
  the display-only numbers the dashboard spec already allows (open receivables,
  payments due, projected tax, tax reserve). Read-only.
- Mail: important messages from mail-triage's output and the drafts waiting.
- Today's briefing from the vault (`D:\Dev\vault\kc\Briefings\YYYY-MM-DD.md`)
  and the attention list's top five, from the dashboard's API.

### 4.7 Approvals (phase 3)

**Job:** one queue for everything waiting on Rob's yes.

Item kinds: a PM proposal to start work, a parked loop card, a session waiting
for input, a merge or push waiting, a mail draft. Each item shows what, which
project, who asked, when, and one primary action (Go, Open, Review in mail). Decline
and snooze live in the item's detail drawer, so no spot ever has two buttons
side by side. A mail draft's action opens Rob's mail client; the cockpit cannot
send, and marks the item done when the draft leaves the Drafts folder or Rob
clears it.

### 4.8 Usage

**Job:** how much of the Max plan's windows is spent, and whether the scheduler
is holding work back.

- Two nested tick dials: 5-hour window outside, weekly inside, with a pace
  marker, the 85% and 80% limits marked, reset times, and a 7-day burn chart
  per window (section 5.8).
- Source: the `rate_limit_event` records (`five_hour` and `seven_day`
  utilization with `resetsAt`) in stream-json output: every loop pass file, and
  every `-p` session the cockpit runs. Checked: plain session transcripts do not
  carry them (0 in the three newest here), so each reading shows its age ("47 min
  ago"), muted once over 30 minutes.
- Spend by project and by model today and this week, from loop pass costs.
- Scheduler state: Go, Waiting until 14:10 (5-hour at 87%), or Stopped (weekly
  at 81%), with the queue it is holding.

### 4.9 Rules

**Job:** one set of rules, hooks and loop files everywhere, and where a project
has drifted. Section 9 has the detail.

## 5. Look and motion

Rewritten 2026-10-23 after Rob asked for a much slicker look: data visualisation
and modern animation first, an animated boot in place of skeleton frames, and a
head as the overview's centrepiece. The references are in
`design-refs/agentos/` (gitignored, with `SOURCES.md` and a local
`board.html` grouping them by technique), cited below by filename. They came
from a hunt through film interface reels (Cantina Creative, Territory Studio,
GMUNK, Prologue, Ash Thorp), HUD motion packs, GitHub's live globe, the
cosmos.gl demo and face-tracking and talking-head demos, 22 videos watched frame
by frame. Subsection numbers 5.2 to 5.6 keep the numbers the plan's cards
already cite.

### 5.1 Direction

Two of Rob's picks set the bar. `robs-pick-1-jarvis-command-center.png` (the
same design as `design-refs/1-jarvis-command-center.png`) stays the layout: a
centrepiece with panels left and right and rows below, navy-black glass, cyan
light. `robs-pick-2-lmntrix-dense-instrument-hud.png` is where the panels go
next: near-black ground, 1px line work, concentric dials with tick scales,
histograms, a map and tabular readouts. The approved sample's panels are mostly
icon rows and status words; the new ones are instruments.

Rules taken from the references:

- **Every panel leads with a visualisation.** A list may sit under it, never
  instead of it. Section 5.8 names each one and its data.
- **Instruments, not cards.** Hairline frames with corner brackets and one
  notched corner (`panels-ginsu-chart-table-bracket-frames.jpg`), tick scales
  on every dial (`graph-concentric-tick-dial.jpg`), tiny labels that are real
  values (`graph-thin-line-meters-and-sparkline.jpg`). Density is fine when
  every mark shares a scale (`graph-gauge-rows-instrument-panel.jpg`).
  `panels-stock-dense-hud-gauge-grid.jpg` shows the density to aim for and
  the fake numbers to avoid: every label here comes from the index.
- **Patterns at a glance, numbers on zoom.** Hansen's rule from the research
  holds. Big light numerals for the one number a panel exists for
  (`type-huge-light-numerals-over-area-charts.jpg`), small tabular values
  beside it.
- **Red is a mode, not a colour scheme.** It appears where something failed and
  nowhere else (`graph-segmented-status-ring-one-red-arc.jpg`,
  `panels-warning-band-over-schematic.jpg`). Amber means Rob is needed.
- **Data you can follow.** Things arrive along a path: a leader line draws, the
  frame follows, the value lands (`arrival-02-panels-wired-in-by-leader-lines.jpg`,
  `arrival-data-strands-land-in-record-rows.jpg`). Live events are arcs between
  real endpoints, as on GitHub's globe
  (`dataviz-github-globe-live-arcs-and-spikes.png`).
- **Depth by layering and focus.** Unfocused panes dim; nothing uses blur or
  drop shadows for depth (`panels-dense-instrument-wall-depth-of-field.jpg`).

Always dark. A light theme and a list-style HUD were tried and rejected.

### 5.2 Type

No downloaded fonts; all three faces ship with Windows (the CSP's `font-src
'self'` would allow self-hosted files, but nothing here needs one).

| Use | Face | Size and weight |
|---|---|---|
| Hero numerals (the one number a panel exists for) | Bahnschrift, light 300 | 64px, tabular figures |
| Clock | Bahnschrift, condensed width (`font-stretch: 75%`) | 40px light 300, tabular figures |
| The CAS wordmark | Bahnschrift condensed, semibold 600 | 28px, tracking 0.02em |
| Screen titles | Bahnschrift | 28px semibold 600, sentence case |
| Panel titles | Bahnschrift semicondensed | 12px 600, capitals, tracking 0.08em (approved: a label inside a panel, never above a headline) |
| Instrument labels (axis ends, units, tick values) | Bahnschrift | 11px 400, tracking 0.04em, `--muted` |
| Values in tables and readouts | Bahnschrift | 14px 600, tabular figures |
| Captions under the head | Bahnschrift | 20px 400, at most two lines of 42 characters |
| Body | Segoe UI Variable Text | 14px/1.5, small text 12px |
| Transcripts, paths, terminals, the boot log | Consolas | 13px/1.45 |

Scale: 11, 12, 14, 16, 20, 28, 40, 64.

Every changing number reserves its width (`min-width` in `ch`, tabular
figures), so a value counting up never moves the layout
(`arrival-03-values-count-up-in-place.jpg`). Type moves by revealing: a
highlight block sweeping a word (`type-scan-highlight-sweeps-a-word.jpg`) or
characters typing into fixed cells (`type-dot-matrix-cells-type-on.jpg`),
never by bouncing or scaling. A state change can take over a tile for a moment
and hand back (`type-state-word-replaces-the-readouts.jpg`): a failed gate
shows "Failed" over its workforce tile for 1.2 s, then the readouts return.

### 5.3 Colour tokens

The dashboard's existing tokens are the source. They move out of `app.css` and
`hud.css` into one `public/assets/tokens.css` that both apps load, so they cannot
drift apart. The motion tokens of 5.4 live in the same file.

```css
:root {
  color-scheme: dark;
  --bg: #06142b;            /* page */
  --bg-deep: #030712;       /* behind the centrepiece */
  --bg-void: #02060f;       /* wall and ultrawide margins, Rob's second pick */
  --glass: rgba(9, 26, 54, 0.74);
  --line: rgba(56, 189, 248, 0.55);
  --line-soft: rgba(56, 189, 248, 0.2);
  --line-hair: rgba(56, 189, 248, 0.28);  /* 1px instrument rules and frames */
  --tick: rgba(122, 166, 194, 0.55);      /* scales and tick marks */
  --glow: rgba(56, 189, 248, 0.16);
  --text: #e2f3ff;
  --muted: #7aa6c2;
  --accent: #38bdf8;
  --accent-2: #22d3ee;      /* Cas, focus rings */
  --c-window: #f5b544;      /* interactive windows */
  --c-loop: #a78bfa;        /* loop runs */
  --c-agent: #60a5fa;       /* subagents */
  --c-job: #94a3b8;         /* headless jobs */
  --ok: #34d399;
  --warn: #fb923c;
  --need: #f5b544;          /* waiting on Rob: the lock-on ring, age bars */
  --fault: #f87171;
  /* sequential ramp for heatmaps and densities, zero to hottest */
  --v-0: rgba(56, 189, 248, 0.05);
  --v-1: rgba(56, 189, 248, 0.16);
  --v-2: rgba(56, 189, 248, 0.32);
  --v-3: rgba(56, 189, 248, 0.56);
  --v-4: #7dd3fc;
  --v-5: #e0f2fe;
  /* graph and head only */
  --m-persona: #fde68a;     /* the galaxy's bright centre */
  --m-automem: #38bdf8;
  --m-instructions: #a78bfa;
  --m-vault: #34d399;
  --m-docs: #7aa6c2;
  --voxel-deep: #0b3a6e;    /* the head's shadowed voxels */
  --voxel-lit: #9bdcff;     /* the head's key-lit voxels */
}
```

One colour means one thing on every screen: a violet node is a loop in the
network, the tree and the timeline alike. `--need` and `--c-window` share a
value on purpose; an interactive window is the thing that usually needs Rob.

**Glow.** Static only: hero numerals carry `text-shadow: 0 0 12px var(--glow)`
and panel frames a fixed outer glow. Nothing animates `box-shadow`, `filter`
or `text-shadow`; where a glow must change (Needs you when it holds items), a
pseudo-element's opacity changes instead. Inside WebGL, glow is a bloom pass
(5.5).

**Grain.** One still 128px noise tile (`cockpit/public/grain.png`, made once by
a script in `cockpit/bin/` and committed) on the page background at 3.5%
opacity, no blend mode. It takes the banding out of the dark gradients at
3440px wide.

**Scanlines.** None across the page; they cost legibility. The head's shader
carries a faint horizontal line pattern at 4%, and the boot (5.7) lays a 6%
scanline layer over the screen that fades with the hand-over.

**Panel frames.** 1px `--line-hair` border, 4px radius, 8px corner brackets in
`--line` at the four corners, a 10px notch cut from the top-right corner with
`clip-path`, fill `--glass`. No `backdrop-filter`: blurring a WebGL canvas
behind every panel at 3440 by 1440 costs more than it shows.

### 5.4 Motion

The page is alive at rest and complete at rest. Nothing waits at opacity zero
except inside the boot (5.7), which never holds a ready value back longer than
its own entrance. One ambient loop per instrument at most. Events get one pulse,
never a loop.

**Tokens.** In `tokens.css`, mirrored in `cockpit/public/motion.mjs` (read once
from the computed style, so CSS, the Web Animations API, canvas and WebGL share
one source):

| Token | Value | Used for |
|---|---|---|
| `--t-quick` | 160ms | hover, press, focus |
| `--t-enter` | 240ms | a row or mark arriving, a frame tracing on |
| `--t-panel` | 420ms | a panel arriving, the drill-in morph, a lock-on |
| `--t-draw` | 600ms | a line drawing on, a comet's flight, a camera move |
| `--t-count` | 400ms | a number counting to its new value |
| `--t-flash` | 300ms | the one red flash of a failure |
| `--t-settle` | 1200ms | an agent finishing, the boot's hand-over |
| `--t-boot` | 1200ms | the boot's ceiling |
| `--ease-out` | `cubic-bezier(0.16, 1, 0.3, 1)` | everything that arrives |
| `--ease-move` | `cubic-bezier(0.65, 0, 0.35, 1)` | moves and morphs between two places |
| `--ease-exit` | `cubic-bezier(0.7, 0, 0.84, 0)` | leaving |
| `--ease-spring` | `linear(0, 0.25 8%, 0.74 20%, 1.05 34%, 1.1 42%, 1.02 60%, 0.99 74%, 1)` | a satellite springing out, about 10% overshoot |
| linear | | ambient rotation and scans only |

Exits run at 0.6 times the matching entrance. A count-up runs `--t-count` for
any change; it never scales with the size of the jump.

**Choreography.** Every arrival runs in the same order: frame, then path, then
content, then value, then decoration.

1. The frame traces on from its top-left corner (`clip-path`, `--t-enter`).
2. On the overview, a leader line draws from the centrepiece to the panel,
   starting 80 ms later (`--t-enter`).
3. Marks and rows enter, staggered 30 ms apart, at most eight staggered, the
   rest together, so no stagger runs past 240 ms.
4. Values count from their previous value (or zero on first arrival).
5. Glow and highlights come last.

Siblings stagger in reading order; on the overview the order runs centre-out:
the head first, then the two panels beside it, then the rows below. Two things
never animate into the same spot at once. Bars grow from their baseline, rings
and arcs sweep clockwise from twelve o'clock, lines draw from their start.

**Live updates.** Events arrive over one server-sent event stream and are
applied in batches once per animation frame, so a burst of 200 tool calls draws
once. A value that changes counts to its new value; a chart that gains a point
shifts by one bin over `--t-enter`; nothing replays from zero.

**Panels that load later** (a transcript, a trace, the galaxy, a terminal, any
panel waiting on the API) never sit blank. The frame draws at once, a single
scan line sweeps it every 1.4 s, and one line of text names what it waits for
with a real number ("reading transcript, 2.1 MB", "laying out 3,712 nodes").
When the data lands it runs the arrival order above. After 10 seconds the
waiting line turns `--need` and says why if the server knows. During a real
index pass the Index health panel shows the real count ("indexing 1,204 of
4,292 files").

**Ambient budget.** Per screen, at most these run while nothing is happening:
the head's idle (breathing, blinks, gaze, shedding particles), the halo's slow
turn, one scan sweep across the head every 8 s, the satellites in the network,
the clock, and the Needs you frame's glow while it holds items (opacity 0.10 to
0.18 over 6 s). Status dots pulse only when busy or waiting. Nothing ambient
touches text, and no ambient change moves luminance more than 10%. Satellites
orbit at 20 s a turn while their agent is busy and 40 s when idle, so motion
itself carries state.

**Transitions between views.** A drill-in is a camera move first and a page
change second (`depth-helmet-hud-platter-hologram.jpg`): the WebGL camera
eases toward the clicked node over `--t-draw` with `--ease-move`, then the View
Transitions API morphs the node's label into the new screen's header
(`--t-panel`) while the rest fades (180 ms). Drilling into a project draws its
manager's branch of the network forward and away from the head
(`depth-exploded-rings-callouts.jpg`). Esc reverses the same path. A pane opens
by growing from its tab (220 ms). A focused pane is the only one at full
brightness; the others dim to 60% over `--t-enter`
(`panels-layered-panels-with-progress-band.jpg`).

**Event motion.** Section 5.8 gives each visualisation's arrival, update and
idle. The events every screen shares:

| What | Motion | Timing | Reduced motion |
|---|---|---|---|
| Tool call | a comet flies agent to manager to Cas along the network's lines, a tail of 120 ms, in the agent's kind colour; at most one per link per 400 ms and 64 in flight, the rest add to the manager's spike | `--t-draw` per hop | the manager's tool-rate value updates, no comet |
| Agent starts | its satellite springs out of the manager | `--t-panel`, `--ease-spring` | appears in place |
| Agent finishes | its ring closes and it dims to 40%; it moves to Done after 10 minutes | `--t-settle` | dims in place |
| Needs input | an amber selector ring locks onto the node, scaling 1.6 to 1 and then turning once every 12 s (`orbit-segmented-rings-with-lock-on-selector.jpg`); a leader line runs to its Needs you row; Cas looks toward it | `--t-panel` | a still amber ring and the row |
| Failure | one red flash, then a still red segment on the manager's ring until a later pass passes | `--t-flash` | the still red segment only |
| Brief or report between agents | a thin arc between the two nodes, cyan for a brief going down, green for a report coming up, fading | 4 s | the Messages count updates |
| Memory access | a comet from the agent to the file's voxels in the head (5.10) or to the file's star in the galaxy, fading | 30 s fade | the file's access count updates |
| Feed row arrives | slides 12px from the centrepiece's side and fades in | `--t-enter`, `--ease-out` | appears in place |
| Number changes | counts to the new value | `--t-count` | jumps |

While the tab is hidden every animation frame stops and the camera (5.10) is
released. On return the screen jumps to the current state without replaying
missed pulses.

**Reduced motion.** Under `prefers-reduced-motion: reduce` every row in every
table of section 5 uses its reduced column, or is still where it has none. In
short: no boot, no rotation, no comets or particles, no scan lines, no
count-ups, no camera moves, drill-downs switch instantly, waiting panels show
their text without the scan line, the galaxy lays out once and stays still, and
the head holds a still portrait whose mouth alone moves while it speaks
(5.10). Opacity fades of `--t-quick` or less stay; they carry no motion. The
data is all still there. `cockpit/bin/check-motion.mjs` enforces it (card 94).

**No JS.** The PHP-style rule holds: the Node service renders every screen's
current values as HTML on first load, every chart included as a static inline
SVG of its current data where the chart is small (dials, sparklines, bars) and
as a table where it is not. The centrepiece falls back to a static CSS ring with
the live counts, the network to the tree, the galaxy to a list of the 50 hottest
files.

### 5.5 Libraries and the GPU

All vendored into `cockpit/public/vendor/` with version, licence, source URL and
sha256 in `cockpit/public/vendor/VENDOR.txt`. Versions, licences and publish
dates checked on the npm registry on 2026-10-23. Every version is at least two
weeks old.

| Library | Version | Licence | Used for | Size |
|---|---|---|---|---|
| `three` | 0.186.1 (2026-09-24) | MIT | the head, the network, the halo, the Workforce orbit view; bloom and wide lines from its addons | `build/three.module.js` 663 KB plus `build/three.core.js` 1.46 MB, unminified (the package ships no minified build) |
| `@cosmos.gl/graph` | 3.5.1 (2026-10-08) | MIT | the memory galaxy | `dist/index.min.js` 744 KB; checked: a self-contained UMD with luma.gl bundled, no `require`, no `Worker`, no `eval`, no `new Function`, no `createObjectURL` |
| `@mediapipe/tasks-vision` | 1.1.0 (2026-10-06) | Apache-2.0 | face tracking for the head's gaze (5.10); 1.1.1 is under two weeks old | `vision_bundle.mjs` 156 KB, `wasm/vision_wasm_internal.js` and `.wasm` 13.5 MB, plus the `face_landmarker.task` model (float16, 3.76 MB, from `storage.googleapis.com/mediapipe-models/`; its licence is confirmed from the model card when vendored) |
| `@met4citizen/talkinghead` | 1.8.0 (2026-10-22, so the card takes the newest release at least two weeks old) | MIT | its English lip-sync module only (`lipsync-en.mjs`), words to Oculus visemes | one small file |
| `dockview-core` | 8.6.1 | MIT | tabs, splits, saved layouts | `dist/dockview-core.min.js` 550 KB |
| `@xterm/xterm` | 6.0.0 | MIT | terminal panes (phase 2) | `lib/xterm.mjs` 345 KB |
| `@xterm/addon-fit` | 0.11.0 | MIT | terminal sizing (phase 2) | small |

Plus one asset: the head model, from Microsoft Rocketbox
(`github.com/microsoft/Microsoft-Rocketbox`, MIT since December 2020), whose
2022 releases added ARKit-compatible blendshapes and 15 viseme blendshapes. The
separate HeadBox tool repository states no licence, so nothing is taken from
it. 5.10 says how the head is prepared.

**What draws what.**

- **WebGL2 through three.js** for the head, the network below it, the halo and
  the Workforce orbit view. `WebGLRenderer` on WebGL2, not `WebGPURenderer`:
  cosmos.gl is WebGL2 too, so the cockpit runs one GPU API. The context asks
  for `powerPreference: 'high-performance'`, because this PC has two GPUs
  (checked: an NVIDIA GeForce RTX 5060 and the AMD Radeon graphics in the Ryzen
  7 7700) and Chrome must not land on the integrated one.
- **Bloom** with three's `UnrealBloomPass` from its addons at half resolution,
  starting at threshold 0.6, strength 0.9, radius 0.4, tuned on Rob's screen.
  **Wide lines** with the addons' `LineSegments2` and `LineMaterial`. One
  `EffectComposer`.
- **Labels over WebGL are DOM**, positioned each frame from projected
  coordinates with `transform`, at most 40 at once, lower-priority labels
  hidden on overlap. Text stays `textContent`, selectable and crisp.
- **cosmos.gl** for the galaxy, its own canvas, mounted only while the Memory
  screen or a galaxy pane is visible.
- **SVG** for dials, rings, gauges and anything with under about 200 marks,
  animated with the Web Animations API (`stroke-dashoffset`, `transform`).
- **Canvas 2D** for denser panel charts (event-rate strips, heatmaps,
  timelines, the mini constellation), drawn only when their data or their own
  transition changes, never every frame.
- **No animation or chart library.** CSS, the Web Animations API, View
  Transitions and the `motion.mjs` scheduler (one `requestAnimationFrame` loop
  for the whole page that canvas and WebGL tweens join and leave) cover every
  row of 5.4. Scales and paths are small hand-written functions in
  `cockpit/public/viz/`.

**Vendoring under the CSP.** three's addons import from the bare name
`'three'`. An import map would be an inline script, which `script-src 'self'`
blocks, so the vendoring card rewrites `from 'three'` in each copied addon file
to the relative path of `three.module.js`, and `VENDOR.txt` records both the
upstream and the edited sha256 of each such file. The addons needed are
`EffectComposer`, `RenderPass`, `ShaderPass`, `OutputPass`, `UnrealBloomPass`,
`Pass`, `CopyShader`, `LuminosityHighPassShader`, `LineSegments2`,
`LineSegmentsGeometry`, `LineMaterial` and `GLTFLoader`; the card follows each
file's own imports and lists the final set.

**Face tracking needs one CSP change.** `@mediapipe/tasks-vision` compiles its
WebAssembly with `WebAssembly.instantiate` (checked in 1.1.0's bundle and its
WASM loader; neither uses `eval`, `new Function` or `new Worker`). Chrome
refuses that under 7.7's CSP unless `script-src` adds `'wasm-unsafe-eval'`,
which allows compiling WebAssembly and nothing else. The page also needs a
`Permissions-Policy: camera=(self), microphone=(self)` header. Both are changes
to 7.7, which this rewrite does not edit: they ride on Rob's approval of this
spec (card 87). Until they are approved the head runs without the camera.

**Frame budget.** Rob's screen is 3440 by 1440 at 144 Hz on the RTX 5060
(checked with `Win32_VideoController`). The cockpit must hold 60 frames a second
there with the galaxy running: p95 frame time at or under 16.7 ms and no long
task over 50 ms, on the overview with a galaxy pane open and laying out and 50
tool calls a second arriving.

| Share of a frame | Ceiling |
|---|---|
| Main-thread JS (event batch, label positions, tweens) | 4 ms |
| The head, the network and the halo on the GPU | 3 ms |
| Bloom at half resolution | 1 ms |
| The galaxy on the GPU while laying out, frozen | 6 ms, 2 ms |
| Style, layout and compositing | 3 ms |
| Face tracking on the main thread (it runs in a worker, 5.10) | 0.5 ms |

Ways the budget holds: DOM animations touch only `transform`, `opacity` and
`clip-path`; canvas pixel ratio is capped at 2 and the bloom target at half
size; when only ambient motion is running the WebGL scene renders at 60 frames
a second by timestamp, and at the display's 144 only during events and
transitions; panes in hidden tabs and charts scrolled out of view
(`IntersectionObserver`) stop drawing; the galaxy freezes its layout after 3
seconds.

`cockpit/bin/check-frames.mjs` measures it: it starts Chrome with remote
debugging, talks to it over Node 24's built-in `WebSocket`, loads
`/?perf=1#/overview` against a fixture event stream, opens a galaxy pane,
records `requestAnimationFrame` intervals and long tasks for 10 seconds, prints
p50, p95 and the worst frame, and exits 1 over budget. Unchecked: whether
headless Chrome on this PC renders on the RTX GPU. The card reads
`chrome://gpu` first and runs Chrome headed in an app window if headless falls
back to software.

Considered and not used: force-graph 1.52.0 (MIT, CPU layout, fine to a few
thousand nodes, and we are already at 3,700), sigma.js 4.0.3 (MIT, good, but
its look is a network diagram, not a galaxy), elkjs (EPL-2.0 or GPL-3.0), GSAP
3.15.0 (its "standard no-charge" licence is not an open-source licence, and
nothing here needs it), `postprocessing` 6.39.5 (Zlib, good, but three's own
bloom pass avoids a second dependency), and three's `WebGPURenderer` (one GPU
API is simpler while cosmos.gl is WebGL2).

### 5.6 Design-slop check

Every screen is checked against `c:\personas\cas\memory\design-slop.md` before
Rob sees it, and a script enforces what it can (`cockpit/bin/check-slop.mjs`,
card 94):

1. No eyebrow. Capital, tracked text appears only as panel titles inside
   panels, tracked 0.08em. Instrument labels track at 0.04em. The CAS wordmark
   is a name set tight at 0.02em, never a small label above a headline.
2. No filled button next to an outlined one. One action per spot; secondary
   actions live in drawers or menus.
3. No beige or cream anywhere. The palette above has none; `--v-5` and
   `--voxel-lit` are cool blues.
4. No comma in any heading or title. Headings are one plain clause.
5. No mirrored sentences in any copy on the page, the boot log and captions
   included.

### 5.7 The boot sequence

The cockpit boots instead of showing skeleton frames. This is an exception to
global rule 8 (skeleton frames), approved by Rob on 2026-10-23. It applies to
the cockpit only; the dashboard keeps rule 8. Panels that load later inside a
screen animate in as 5.4 says and never sit blank.

**What it shows: Cas assembling from the real system.** The page under the
boot is the server-rendered first paint, so every value is already real. The
boot is the head (5.10) building itself from the sources the cockpit reads,
each share of the head arriving when its source answers:

| Share of the head's voxels | Arrives when | Boot log line (real values) |
|---|---|---|
| 40%, the memory voxels, flying up out of the network | the first paint's snapshot is parsed (at once) | `index 4,292 files, last pass 12 s ago, 0 unknown records` |
| 30%, the face's front | the event stream is open and its replay has started; this share fills in proportion to replayed events received of the total the server announces first | `stream open, replaying 212 events` |
| 20%, the network below the jaw | the first `claude agents` result no older than 15 s, from the snapshot or the stream | `15 projects, 14 sessions, 3 busy, 2 loops running` |
| 10%, the eyes open | the newest usage reading is known, with its age | `5-hour 47%, weekly 22%, read 3 min ago` |

The replayed events (the last five minutes from the `events` table, at most 40
of them) fire across the network as comets in fast-forward while the head
forms, so Rob sees what just happened. The log lines type into fixed cells on
the left of the head (`type-dot-matrix-cells-type-on.jpg`), each when its fact
is known, at most five lines. The mark lands last: CAS resolves out of a tile
grid under the head (`arrival-pixel-tiles-resolve-a-name.jpg`), with
"Coordinator of Agents and Sessions" beneath it once, small, in sentence case.
That expansion appears nowhere else. Panels arrive centre-out by 5.4's order
while the head forms, their frames tracing on and values counting from zero.
References: `boot-01-first-ring-draws-on.jpg` to
`boot-03-rings-complete-name-lands.jpg` for one-part-at-a-time assembly,
`boot-wireframe-assembles-from-boxes.jpg` for parts converging into a figure,
and `boot-00-fake-progress-bar-not-this.jpg` for what it never does.

**How long.** At most `--t-boot` (1.2 s) from first paint, and at least 700 ms
so it never flickers. It never waits on a slow source: at 1.2 s it ends, a
share whose source has not answered stays as loose particles in the network,
and the matching panel arrives later on its own. No panel value is held back
past its own entrance (at most 600 ms after first paint). Clicks, keys and the
wheel pass through to the page throughout; the first one ends the boot at its
final frame. `?boot=0` turns it off and `?boot=1` forces it, for tests.

**Hand-over.** When the boot ends, the boot log lines fly into the Live feed as
its newest rows (a FLIP move over `--t-panel`), the scanline layer fades over
`--t-settle`, the halo starts its slow turn and the head drops into its idle
state. Nothing Rob might be reading is removed.

**Return visits.** The boot plays once per browser session. A flag in
`sessionStorage` (with the values last seen) records it, read and written
inside try/catch; if storage fails the boot plays once per page load. Moving
between screens never boots. A reload or a second window in the same session
gets a 400 ms resync instead: the head is already whole, the network re-forms,
changed numbers count from their last-seen values, and the events missed while
away become one feed line ("37 events while away") instead of replayed comets.
Returning to a hidden tab follows 5.4: the current state, no replay.

**Reduced motion and no JS.** Under reduced motion there is no boot: the final
frame shows at once and the boot log's facts go straight into the feed as rows.
Without JS there is nothing to boot; the server's HTML is the screen. On a
phone-width window there is no head, so no boot; panels use the 5.4 arrival.

### 5.8 Visualisation by screen

Each screen's visualisations, the data behind each and how each moves. Arrival
is the first time it draws, update is a live change, idle is what it does when
nothing changes. Reduced motion follows 5.4: arrivals and updates land in
place, idle is still.

**Overview** (4.1)

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| Cas, the head (5.10) | memory nodes from the index, the hierarchy from `claude agents --json` and loop state, tool calls and accesses from `/events` | the boot (5.7) | comets up the network, voxels lighting on access, gaze to what needs Rob | breathing, blinks, gaze, particles shedding |
| The network below the head | managers and agents with links (section 3) | grows down from the jaw with the boot | satellites spring out, rings close, lock-on rings | satellites orbit, slower when idle |
| The halo, a radial histogram behind the head (`graph-radial-histogram-around-a-ring.jpg`) | tool calls per minute across all sessions, the last 60 minutes, from `tool_calls` | 60 bars sweep on clockwise from twelve | the current minute's bar grows live; each minute the ring turns one step (6°) over `--t-panel` | a turn every 140 s |
| Needs you: hero count and age bars | approvals and waiting sessions, each row's time waiting on a log scale | count lands, bars grow from the left | a new row slides in, bars extend every 10 s, colour deepens with age | the frame glows while it holds items |
| Live feed: event-rate strip over the rows (`graph-waveform-under-a-video-feed.jpg`) | events per 10 s bin for 10 minutes, stacked by type in their colours | bins grow from the baseline left to right | the newest bin grows, the strip shifts one bin per 10 s | still |
| Workforce strip: one instrument per manager (`graph-row-of-donut-gauges.jpg`) | a tick dial of elapsed time against the median of that project's last 20 passes of the same kind from `loop_passes` (a 60-minute scale for windows and jobs), a 30-minute sparkline of tool calls per minute, the current tool and target | dials sweep, sparklines draw left to right, the tool name types on | the dial's needle advances each second, the sparkline gains a point a minute, a new tool name sweeps in with a highlight block | still |
| Timeline: today as a Gantt strip | loop runs as bars in their kind colour, scheduled jobs as ticks with countdowns, a "now" line | bars grow from their start times | a running bar extends, countdowns tick | the now line moves 1px a minute |
| Usage: two nested tick dials (`graph-concentric-tick-dial.jpg`) | 5-hour window outer, weekly inner, from `usage_readings`; a pace marker for where a steady burn would sit; the 85% and 80% limits as red ticks; reset times and reading age | arcs sweep to the value | arcs move to the new reading, the age ticks, the dial dims past 30 minutes old | still |
| Memory insights: mini constellation and top five | the 40 hottest files today as stars in their kind colours, positions from the galaxy layout; the top five as bars | stars fade in brightest first, bars grow | a star brightens on access, a bar grows | still |
| Rules: drift matrix in miniature (`graph-heatmap-matrix-with-values.jpg`) | 15 projects by 13 loop files coloured by drift state (section 9) | cells fill row by row | a cell changes colour over `--t-enter` | still |
| Index health: pass sparkline and a threshold line | files read per pass for the last 60 passes, unknown records against a red threshold | the line draws on | a point per pass; the panel turns red when unknown records rise | still |

**Project** (4.2)

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| Header readouts | branch, dirty count, last commit age, loop state, from the dashboard's project scanner | values count in | count to new values | still |
| Managers and agents as a tree with live status | the project's slice of the hierarchy | rows enter staggered | status dots change, rows move between groups | busy dots pulse |
| Loop waterfall | the last ten cards, one row each, a segment per pass coloured by pass kind and red for a failed gate, from `loop_passes` | segments grow from their start times | the running segment extends | still |
| Cost per card | bars for the last ten cards, the largest `total_cost_usd` per session | bars grow | a new bar shifts the set left | still |
| Activity heatmap | tool calls by hour of day for 7 days, 7 rows by 24 columns on the `--v-0` to `--v-5` ramp | cells fill by row | the current hour's cell deepens | still |
| Memory files with access bars | this project's memory and instruction files, access counts as bars, last toucher | rows enter, bars grow | a bar grows, a comet tick flashes once on the row | still |

**Session** (4.3)

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| Transcript | the live `.jsonl` (4.3) | the last screenful enters bottom-up, staggered | new blocks slide in at the bottom | still |
| Trace waterfall (`panels-subject-ringed-by-dense-readouts.jpg`) | passes, sessions, subagents, tool calls with `ms` | bars grow from their start times, level by level | a running bar extends | still |
| Tool mix ring | tool calls by tool name for this session, top six plus other | the ring sweeps on | segments resize over `--t-enter` | still |
| Files touched with a time strip | each path, its op glyph and a tick on a strip where it was touched | rows enter, ticks drop onto the strip | a tick drops in | still |

**Workforce** (4.4)

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| Orbit view: the hierarchy as tilted orbits with labels (`orbit-tilted-ellipses-nodes-and-labels.jpg`, `orbit-satellites-around-a-hub.jpg`) | the hierarchy at the chosen window | rings draw on outside-in, nodes land, labels type on | as the network in 5.4's event table | rings counter-rotate at 90 s and 140 s a turn, satellites orbit |
| Message arcs on the orbit view | briefs, reports and `SendMessage` in the window, arc weight by count | arcs draw from sender to receiver | a new arc draws and fades to its weighted rest | still |
| Side readouts (`orbit-telemetry-orbits-with-value-columns.jpg`) | for the hovered or selected node: kind, card, model, elapsed, tool rate | values count in | live values count | still |
| Tree with inline sparklines | the same nodes, tool calls per minute over 30 minutes per row | rows enter staggered | sparklines gain points | busy dots pulse |
| Messages volume strip | messages per hour for 24 hours, stacked by kind | bins grow | the newest bin grows | still |

**Memory** (4.5): the galaxy

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| The galaxy (cosmos.gl), restyled from its stock look (`counter-cosmos-raw-look-before-styling.jpg`) to `--bg-void`, kind colours, size and brightness by log access count, written-to files with a warm rim (`graph-weighted-node-cluster-with-labels.jpg`) | 4.5's nodes and edges | stars burst from the centre and settle, the layout runs up to 3 s then freezes | a comet from the agent to the file fades over 30 s and the file's glow rises | still |
| Scrubber over an access histogram | accesses per hour across the window | bars grow | play replays a day at 60x, the playhead moves | still |

**Usage** (4.8)

| Visualisation | Data | Arrival | Update | Idle |
|---|---|---|---|---|
| The two nested dials at full size | as on the overview, plus projected time to the 85% limit at the current rate | arcs sweep | arcs move, projection recounts | still |
| Burn chart per window | the last 7 days of readings as a line, limits as red rules, resets as ticks | lines draw on left to right | a point per reading | still |
| Spend by project and model | stacked bars, today and this week | bars grow | segments resize | still |

**Approvals** (4.7): rows with age bars and a kind glyph, as Needs you. **Rules**
(4.9): the full drift matrix, projects by files, and the hook matrix beside it,
cells as on the overview. **Morning** (4.6): an overnight Gantt from 18:00 to
06:00 of loop runs and jobs, failures as red segments; spend as one bar against
the week's average; the money numbers counting in; the briefing as text.

### 5.9 Iconography and sound

**Icons.** A small set of 1.5px stroke icons at 20px in one SVG sprite, for the
rail and the top bar only. The source is a vendored subset of Lucide (ISC),
version and licence checked when vendored. Panels use few icons; state is
carried by colour and by node shape, which is the same on every screen: Cas is
the head, a manager is a ring, an agent a dot, a nested agent a small diamond,
a fault a small triangle. Busy nodes are filled, idle ones hollow. No emoji, no
filled icon discs inside panels.

**Sound: yes, off by default.** A toggle in the top bar's settings, kept per
browser in localStorage inside try/catch. Three sounds only, made with Web Audio
oscillators (no files): the boot's end (two rising tones, 400 ms), something new
in Needs you (two short blips), a failure (one low 180 Hz tone, 250 ms). Never
on a tool call. Everything is mixed at -24 dB, and nothing plays while the tab
is hidden. Cas's speech (5.10) is separate and has its own control.

### 5.10 Cas at the centre

The overview's centrepiece is a head: Cas, the agent Rob works with. It
replaces the globe of the approved sample. Rob's picks for it are
`robs-pick-3-voxel-head-dissolving-into-network.png` (a voxel head that turns
into a node-and-line network below the jaw), `robs-pick-4-voxel-face-scattering-to-particles.png`
(a pale voxel face scattering into particles at its edges) and
`robs-pick-5-glass-tile-profile-dissolving-crown.png` (a profile built from
blue glass tiles, dissolving at the crown). Two of them carry stock
watermarks; they are references only.

#### The character

Decided by Rob, 2026-10-23.

- **Who it is.** Cas, they/them. The face is androgynous, gendered neither
  way. Rob's picks mix a man's face and a woman's; the head takes their
  material, not their gender.
- **The name.** The wordmark in the HUD is CAS: in the boot and as the head's
  label. Cas is a name, not an acronym, and stays one. The only expansion is
  "Coordinator of Agents and Sessions", shown once, small, during the boot
  (5.7), because that is what it does.
- **Made of its memory.** Every voxel and particle of the head belongs to a real
  node in the memory index: journal entries and persona files, each project's
  memory, instruction files, vault notes. When Cas reads or recalls a file, that
  file's voxels light and its particles travel. The network the head dissolves
  into below the jaw is the workforce it coordinates. Nothing is decorative.
- **Temperament.** Direct, specific, dry. Calm attention, not a performer. No
  smile and no warmth animation: the smile blendshapes are never driven.
  Expression is small and earned: brows and eyes for attention and thinking, a
  slight head tilt when listening. When it has something for Rob it looks
  toward the pane or agent that needs him.
- **Voice.** Measured pace, lower register, plain delivery. Captions run under
  the head in the cockpit's type, short lines.

#### The head

- **Model.** One adult head from Microsoft Rocketbox (MIT), with its
  ARKit-compatible blendshapes and 15 visemes. The library's heads are
  gendered, so the prep card makes it androgynous: where a male and a female
  head share topology it blends the two, otherwise it softens the jaw and brow
  by hand. Rob signs it off from a still before anything is built on it. The
  head is cut at the neck, decimated to about 16,000 vertices with the eyes as
  separate meshes, and exported as `cockpit/public/models/cas-head.glb` with
  its morph targets, listed in `VENDOR.txt`. Blender is not installed on this
  PC (checked), so the card either converts with three's `FBXLoader` and
  `GLTFExporter` in a local tool page under `cockpit/tools/`, which needs no
  install, or installs Blender, which parks for Rob.
- **Voxels.** An `InstancedMesh` of about 16,000 small cubes, one per vertex. A
  custom shader built on three's morph-target chunks computes each vertex's
  morphed position on the GPU from the blendshape weights, snaps it to a grid of
  1/90 of the head's height, and places the cube there, axis-aligned, so the
  face reads as voxels and the grid hides fine features. Colour runs from
  `--voxel-deep` to `--voxel-lit` under one key light from the upper left, with
  bloom and the 4% line pattern (`head-contour-sliced-hologram.jpg`). Pose
  (yaw, pitch, roll) is one uniform.
- **Each voxel is a memory node.** The voxels are shared out among the index's
  memory nodes (4.5) in proportion to each node's log access count, every node
  getting at least one. Regions follow kind: persona and journal files across
  the brow, eyes and cheeks; instruction files at the temples; each project's
  memory as a band around the skull in the same order as the galaxy's arms;
  vault notes at the crown. A file read draws a comet from the reading agent in
  the network up into that file's voxels, which brighten and fade over 30 s. A
  write leaves the warm rim of 4.5 on them. Files not touched in 30 days are
  the ones that shed: they drift off the crown and edges as particles
  (`robs-pick-5-glass-tile-profile-dissolving-crown.png`), and a file touched
  again flies back into place. Total voxels stay fixed, so a bigger memory
  makes each file's share smaller, never the head bigger. The head reads the
  galaxy's own API (card 102): node ids, kinds, paths and counts after the
  never-show filter, never file contents.
- **The network below the jaw.** The lowest rows of voxels loosen into nodes
  and lines (`robs-pick-3-voxel-head-dissolving-into-network.png`): managers as
  rings in their kind colour hanging below the jaw, placed by project key so
  positions are stable across loads, linked up to the jaw; agents as dots
  circling their manager; nested agents below their parent; idle projects as
  faint nodes further down. Tool calls travel up these lines into the head.
  This network is the overview's hierarchy view; the full orbit view lives on
  the Workforce screen.
- **When the PM is not running.** The head is still Cas, but the eyes are
  half-lidded, the voxels sit at 40% brightness and the label reads "CAS · PM
  not running". The network hangs off the jaw as "started by Rob".

#### States

| State | When | Face and head | Network and halo | Reduced motion |
|---|---|---|---|---|
| Idle | nothing below applies | breathing (0.4% scale over 5 s), blinks every 3 to 7 s (150 ms), the eyes resting on a point and shifting every 2 to 6 s in 80 ms saccades, a drift of about 2° over 9 s; when tracking, the gaze rests on Rob | particles shed slowly; comets as events arrive | still portrait, eyes open, facing forward |
| Attending | something new lands in Needs you, or a failure | eyes go first (120 ms), then the head turns toward the pane or node (`--t-panel`), holds 1.5 s, comes back; brows lift slightly (`browInnerUp` 0.2) | the item's lock-on ring and leader line | eyes and head still; the lock-on ring shows |
| Listening | Rob is speaking (voice in, phase 4 or later) or typing in the Ask box or the PM chat | faces Rob or the screen's centre, head tilts about 4°, mouth closed, brows level | the network's lines pulse with Rob's voice level (the mic through a Web Audio `AnalyserNode`, never recorded), or with each keystroke | still; a small "Listening" caption |
| Thinking | the PM session is busy on a request from Rob, or an Ask is in flight | eyes defocus up and to one side, a slight squint (`eyeSquint` 0.15), head still | a band of light rises through the voxels from jaw to crown every 1.2 s | still; a "Thinking" caption |
| Speaking | Cas is reading out an answer or the briefing | mouth by visemes (below), small nods on stressed words, eyes on Rob | the halo's current bar follows the speech level where audio is analysable | the mouth alone moves; captions run as normal |

#### Following Rob with the camera

- **Off by default and opt-in.** A camera toggle in the top bar. Nothing starts
  the camera at page load unless Rob has switched on "Start the camera with the
  cockpit" in settings, which is also off by default and kept per browser in
  localStorage inside try/catch.
- **Visibly on.** While the camera runs, the top bar shows a lit dot and the
  words "Camera on", and a thin ring shows around the head's eyes. Turning the
  toggle off stops the `MediaStream` tracks, so the PC's own camera light goes
  out too.
- **Local only.** Frames go from `getUserMedia` to the face landmarker inside the
  page and nowhere else: never drawn to a visible canvas, never stored, never
  sent. The cockpit service has no route that accepts images or audio, and the
  CSP's `connect-src 'self'` stops the page sending anything off the PC. The page
  keeps two smoothed numbers, yaw and pitch, and drops each frame once read.
- **How.** `@mediapipe/tasks-vision` 1.1.0's `FaceLandmarker` with the float16
  model, in a dedicated module worker (a same-origin file, allowed by
  `'self'`), fed `ImageBitmap` frames scaled to 320 by 240 at 15 a second. The
  worker returns the face centre (the nose-bridge landmark) and the facial
  transformation matrix (`head-mediapipe-canonical-face-follows-tracking.jpg`).
  The head's target yaw is clamped to ±25° and pitch to ±15°, because past
  about 30° a particle face stops reading
  (`head-particle-face-turned-scattering-at-edges.jpg`), eased by a critically
  damped spring: the eyes reach the target in about 120 ms and the head in
  about 400 ms, as a person's do. Only gaze and head turn follow Rob; the face
  never mirrors his expression, which reads as a toy
  (`counter-avatar-mirrors-the-users-face.jpg`). With no face for 3
  seconds the head eases back to centre over `--t-settle` and idles; with two
  faces it follows the larger. Unchecked: the GPU delegate inside a worker on
  this PC; if it fails, the worker uses the CPU delegate at 10 frames a second.
- **Needs the CSP change in 5.5.** Without `'wasm-unsafe-eval'` approved, the
  toggle does not appear and the head idles.
- **Hidden tab.** The tracks stop after the tab has been hidden 10 seconds and
  restart on return if the toggle is on. Chrome keeps the permission.

#### Lip sync

- **Now: word-timed visemes over `speechSynthesis`.** The dashboard speaks
  with the browser's `speechSynthesis` and local voices only (card 80), and the
  cockpit does the same. That engine gives word boundaries and no audio: its
  output cannot be routed into Web Audio, so there is no level to read. On each
  `boundary` event the word is turned into an Oculus viseme sequence by the
  vendored `lipsync-en.mjs` from `@met4citizen/talkinghead`, spread across the
  word's expected length (its letters times the voice's milliseconds per
  letter, measured from the gaps between the first sentence's boundaries), and
  blended onto the head's 15 viseme blendshapes (`head-oculus-viseme-chart.jpg`)
  with a 60 ms attack and 80 ms release. `jawOpen` follows each viseme's opening. Between words the mouth
  closes. Default voice settings follow the character: rate 0.95, pitch 0.85,
  with Rob's local voice choice kept as card 80 keeps it.
- **Later, recommended: a local voice the page can hear.** A neural TTS run by
  the cockpit service on this PC, its audio played through Web Audio so an
  `AnalyserNode` reads the level. Kokoro is the candidate (`kokoro-js` 1.2.1,
  Apache-2.0, an 82-million-parameter model that runs in Node). With audio,
  visemes come from the engine's phonemes where it gives their timing, and from
  the audio where it does not: RMS over 25 ms drives `jawOpen`, and the
  spectral centroid moves the mouth between wide and round. `wawa-lipsync`
  0.0.2 (MIT) does that audio-to-viseme step in the browser and is the
  candidate for it. Unchecked: whether `kokoro-js` exposes per-phoneme timing. This is its own card after the head,
  and it would change the voice Rob hears.
- **Captions.** Under the head, the current sentence in two lines at most,
  words appearing on their boundary events, fading 4 s after the sentence ends.
  Captions show even with speech muted.

#### Fallbacks

- **No camera** (off, denied or unavailable): idle gaze as in the state table;
  the toggle says "Camera off" or "Camera blocked".
- **No WebGL2:** the overview's centre falls back to the dashboard's canvas 2D
  globe from `public/assets/orb.js` grown into the hierarchy (the previous
  design), and without JS to the static CSS ring.
- **Reduced motion:** a still voxel portrait, facing forward, eyes open; no
  breathing, blinks, gaze, particles, comets or head turns, and no camera
  following (the toggle hides). The mouth still moves while it speaks, because
  that is the signal that it is speaking, and captions run.
- **Phone width:** no head; 6 says what shows instead.

**Its share of the frame.** The head, the network and the halo together get the
3 ms GPU line in 5.5's table and bloom its 1 ms; face tracking costs the main
thread under 0.5 ms because the worker does the work. Card 97's done-test runs
`check-frames.mjs` with the camera fixture on.

## 6. Layout by width

Mobile first, `min-width` queries only.

- **Base (phone width).** Top bar with clock and the usage pill; Needs you; the
  workforce tree; the live feed; usage; a top-files list in place of the galaxy.
  Small charts still draw (dials, sparklines, age bars), because they cost
  little and carry the data. No head, no WebGL, no boot. Read-only: no
  terminals, no panes, no Go buttons. A 16px gutter, no sideways scroll at
  280px.
- **1024px and up.** The left rail appears and the overview forms the
  3-column grid of the approved sample, Cas in the centre column. Panes and
  drag-to-split turn on. The head and the galaxy turn on when WebGL2 is present
  (the orb and the list stay as fallbacks). The boot plays here and up.
- **1600px and up.** The wall layout: everything on one screen with no
  scrolling. The head's column takes at least 640px.
- **2400px and up (Rob's 3440 by 1440).** Five columns: Cas in the centre at
  `minmax(880px, 1fr)`, two panel columns each side. Inner left: Needs you over
  the Workforce strip, stacked vertically as dials. Inner right: Live feed over
  Usage. Outer left: Timeline over Memory insights. Outer right: Rules over Index
  health. No panel grows past 520px wide, because a stretched instrument reads
  worse; extra width goes to the head's column and to `--bg-void` margins. At
  1440px tall everything fits without scrolling.

Focus dims everything but the focused pane to 60% (5.4), at every width with
panes.

### 6.1 What this changes in the plan

Cards 87 to 106 in `.claude/work/plan.md`, one line each. Cards not listed
(88 to 92, 99, 102, 104, 105) are unchanged.

- **87** Rob's approval now also covers the boot as the exception to rule 8,
  the head, three.js and MediaPipe in 5.5, and the CSP and Permissions-Policy
  additions 5.5 asks of section 7.7 (`'wasm-unsafe-eval'`, camera and
  microphone for self).
- **88** Unchanged until 87 approves the CSP addition; then its exact-CSP test
  gains `'wasm-unsafe-eval'` and a `Permissions-Policy` check.
- **93** `tokens.css` also carries the new colour tokens of 5.3 and the motion
  tokens of 5.4; HudTest's "every property named in 5.3" grows with them, with
  no visible change to the dashboard.
- **94** check-motion also requires every duration and easing to use a token,
  every Web Animations call to honour reduced motion, at most the ambient set
  of 5.4 per screen, and no `@keyframes` animating `box-shadow`, `filter` or
  `text-shadow`; check-slop accepts the CAS wordmark and the 0.04em instrument
  labels.
- **95** The shell drops skeletons for the boot frame and the waiting-panel
  treatment of 5.4, adds `motion.mjs`, the session flag, `?boot=0` and
  `?boot=1`, the grain tile and the 2400px layout; its test asserts no
  skeleton class remains.
- **96** Every overview panel gains its visualisation from 5.8, drawn with a
  new `cockpit/public/viz/` (dial, sparkline, strip, Gantt, heatmap, bars) and
  rendered as static SVG for the no-JS page. Too big for one card: split it
  into the viz kit and the panels.
- **97** Becomes Cas, the head, and splits in four: the head model prepared
  and signed off by Rob (an install parks it if Blender is chosen); three.js
  vendored with its addons rewritten, the voxel head, memory mapping, network,
  halo, states and boot (waits on 87, like 103) with `check-frames.mjs`; camera
  following (Risky: it touches the camera; waits on 87's CSP change); lip sync
  over `speechSynthesis` with captions. The canvas 2D globe becomes the fallback
  only.
- **98** The orbit view draws with three.js instead of canvas 2D and gains
  message arcs, side readouts and inline sparklines.
- **100** The trace gains the tool mix ring and the files-touched time strip.
- **101** The project screen gains cost-per-card bars and the 7-day activity
  heatmap, and the waterfall colours segments by pass kind.
- **103** The galaxy is restyled per 5.8 and its frame cost is checked with
  `check-frames.mjs`; the CSP question it was parked on is answered (the UMD
  bundle uses no worker, blob or eval).
- **106** The audit also covers the vendored three.js addons with rewritten
  imports, the MediaPipe worker, and the camera's local-only promise (no route
  accepts images or audio, the tracks stop when the toggle goes off).
- **New, after 97:** Kokoro or another local neural voice for Cas, if Rob wants
  it (5.10). Section 10's card 1.4 still mentions skeletons; this section
  supersedes it.

## 7. Architecture

### 7.1 Processes

All on Rob's PC, all bound to 127.0.0.1.

1. **Apache and the PHP dashboard** (`:8787`, exists). Unchanged, except that its
   Active agents panel links into the cockpit and, later, reads the cockpit's
   API instead of keeping its own session list.
2. **The cockpit service** (`:8788`, new). `cockpit/server.mjs` on Node 24.12. It
   holds the index, tails transcripts, serves the page and its API, streams
   events, and (from phase 2) owns every process the cockpit starts.
3. **Claude Code's own supervisor** (Anthropic's, exists). Keeps background
   sessions alive. The cockpit asks it questions through the CLI and never
   replaces it.
4. **The PM session** (phase 3). A background session named `pm` in `D:\Dev\hq`.
5. **Workers.** Rob's windows, background sessions, each project's loop, jobs and
   every subagent under them.

### 7.2 Folder layout

```
cockpit/
  server.mjs          HTTP, guard, static files, API routes, SSE
  lib/guard.mjs       the request checks (7.7)
  lib/index/          the indexer: transcripts, loop streams, access log
  lib/live.mjs        session registry watch, claude agents poll, loop state
  lib/runner.mjs      the one place a child process starts (phase 2)
  lib/drift.mjs       rules and loop copy comparison
  public/             the page: index.html, cockpit.css, *.mjs, vendor/
  bin/index.mjs       run the indexer by hand (--full, --stats)
  bin/check-*.mjs     the page checks (slop, motion)
  test/               node --test suites with fixture transcripts
  package.json        exact versions, phase 2 onward
data/agentos.sqlite   the index (gitignored with the rest of data/)
data/token            the existing install token, shared
```

The project's gate learns the Node tests by adding `node --test cockpit/test` to
`extra_checks` in `.claude/project.md`, and `cockpit/` joins the risky paths
there.

### 7.3 The index

The spike's schema and logic (data-spike.md) ported from its throwaway PHP to
`cockpit/lib/index/`, writing `data/agentos.sqlite` in WAL mode so the page can
read while it writes. The cockpit service is the only writer. The PHP dashboard
may read it.

- **Sources.** `~/.claude/projects/**/*.jsonl` and each session's
  `subagents/*.jsonl` and `*.meta.json`; each in-scope project's
  `.claude/work/loop/*.jsonl` and `status.json`; the hook access log (7.5).
- **Incremental.** A byte offset per file; only complete lines past it are read;
  a half-written last line waits. Every row is keyed on its source id, so reading
  a line twice is harmless. The 1,179 duplicate files under both
  `c--xampp-htdocs-*` and `d--Dev-htdocs-*` are read once.
- **When it runs.** On a file change (`fs.watch` on the projects folder and the
  loop folders, recursive, debounced to 1 s per file), plus a sweep every 30
  seconds. Spike timing: 44 s for the whole history, 1.0 s for an incremental
  pass.
- **Schema.** The spike's tables (`projects`, `files`, `sessions`, `tool_calls`,
  `agent_messages`, `file_access`, `loop_passes`) plus:
  - `tool_calls.ended` and `ms`, from the matching tool result, for the trace;
  - `loop_runs (run_id, project, started, ended, launched_by, launched_by_src)`
    where the source is `status` (exact) or `heuristic`;
  - `usage_readings (ts, source_file, five_hour, five_resets, seven_day, seven_resets)`;
  - `events (id, ts, type, payload)` keeping 24 hours for stream replay;
  - `drift (project, file, state, checked_at)`;
  - `approvals (id, kind, project, title, origin, created, state, ref)` from
    phase 3;
  - `index_health (pass_ts, files_read, bytes, unknown_records, parser_version)`.
- **Never stored.** Command text, tool output, file contents, anything on the
  never-show list in 4.5. Briefs and reports are cut to 500 characters after the
  scrubber runs, the same scrubber the hooks use.
- **Permanent.** Claude Code deletes old transcripts (almost nothing here
  predates late September), so this database is the archive. It is not pruned.
  It was 74.7 MB for four months of history.
- **Format drift.** The transcript format is internal to Claude Code and changes
  between versions (2.1.304 here). The indexer parses softly, records the parser
  version on each row, and counts records it did not understand. A rise in that
  count shows red in Index health, which is how a Claude Code update that
  breaks parsing gets noticed the same day.

### 7.4 Live state and events

- **Who is running.** `~/.claude/sessions/*.json` is watched for instant changes
  (it is undocumented, so it only triggers a refresh), and `claude agents --json`
  is the truth, polled every 15 seconds. Checked: one call takes 1.5 s and
  returns `pid`, `cwd`, `kind`, `startedAt`, `sessionId`, `name` and `status`
  (`idle` or `busy`). Anthropic's docs describe `state` and `waitingFor` for
  background sessions; the parser takes both shapes.
- **Loops.** Each in-scope project's `lock` (with a PID check), `STOP` and
  `status.json`.
- **Agent running or done.** From whether a report has arrived and the file's
  last write, since the meta `finished` field is missing from most meta files.
- **The stream.** `GET /events` is a server-sent event stream. Event types:
  `session`, `tool`, `agent`, `message`, `loop`, `usage`, `index`, `drift`,
  `approval`. Each has a rising id; on reconnect the browser sends
  `Last-Event-ID` and gets what it missed from the 24-hour `events` table. Target:
  a tool call on screen within 2 seconds of the transcript line being written.
  The page keeps the newest 700 feed rows.

### 7.5 The two small changes outside this repo (phase 2)

Both are global, so per rule 7 they go into `C:\Users\roban\.claude\` first and
are then carried into each project's copy, merging that copy's own changes and
skipping any project whose loop is running (Sembr's was, today).

- **`claude-loop.cjs` records its launcher.** At run start it writes
  `launched_by` (a session id) into `status.json`: from the environment if
  Claude Code exports the session id to Bash commands, otherwise by walking the
  parent processes to a `claude.exe` whose `~/.claude/sessions/<pid>.json` names
  it. Turns the 107-of-181 guess into an exact link.
- **Hooks that load files log the paths.** The SessionStart hook that loads the
  journal (and any other hook that reads files into a session) appends one line
  per file to `C:\Users\roban\.claude\state\access-log.jsonl`:
  `{ts, session_id, hook, path, op: "load"}`. Paths only. The journal then shows
  its real reads instead of 100 writes and one read. The hook's command lives in
  the user settings file, which the credential guard rightly blocks agents from
  reading, so the card finds the script by searching `c:\personas\` and
  `~/.claude\hooks\` or asks Rob for the path.

### 7.6 Driving sessions (phase 2 onward)

Every process the cockpit starts goes through `cockpit/lib/runner.mjs`:

- **Start a background session.** `claude.exe --bg -n <project>-<slug> --model
  <m> --effort <e> "<prompt>"` with the working folder set to the project. Model
  and effort follow global rule 9 and the routing table in `speedup.md` (small
  edits Sonnet or Haiku at low, research Sonnet at medium, risky paths Opus).
  All four flags checked in `claude --help` today.
- **Attach.** A terminal pane spawns `claude.exe attach <id>` inside node-pty and
  streams it to xterm.js over a WebSocket. Closing the pane ends only the attach.
- **Stop.** `claude.exe stop <id>`, only after a confirm in the page that names
  the session. Never on a timer.
- **Start a loop.** `node <project>\.claude\bin\claude-loop.cjs` from that
  project's own copy, refused while its lock is live (one loop per project).
- **Stop a loop.** Write the loop's own `STOP` file. The cockpit never kills a
  loop process.
- **Chat with the PM (phase 3).** One long-lived `claude.exe -p --resume <pm-id>
  --input-format stream-json --output-format stream-json --verbose` process.
- **Never.** `--dangerously-skip-permissions` or any permission bypass. Workers
  run under the project's own settings and hooks, exactly as if Rob had started
  them.

**The scheduler and the usage governor.** Work the cockpit or the PM wants to
start goes into a queue. The scheduler starts the next item when the newest
usage reading says go, using the loop's own thresholds so both agree: wait while
the 5-hour window is at 85% or more, until its reset plus two minutes; stop
starting anything while the weekly window is at 80% or more (`pauseAt` and
`weeklyCeiling` in `claude-loop.cjs`). Both numbers live in
`cockpit/config.json`. No fixed worker count. A reading older than 30 minutes
counts as unknown, and with no fresh reading the scheduler starts one item, takes
the reading from its stream, then decides on the rest. Loops keep throttling
themselves as they do today.

**Morning schedule (phase 4).** Windows Task Scheduler, tasks under `\KC
Dashboard\`:

| Time | What | Exists |
|---|---|---|
| 05:30 | dashboard collectors, kc-finance runs, mail-triage sort and drafts | collectors and sorting yes, drafts no |
| 06:00 | daily read | yes |
| 06:15 | briefing into the vault | yes |
| 06:30 | cockpit respawns `pm` and pinned sessions (`claude respawn`), then gives the PM its morning prompt | no |

Background sessions stop when the PC shuts down. Whether `claude --bg` and
`claude respawn` work from a scheduled task is unchecked; phase 4's first card
tests it before anything relies on it.

### 7.7 Security

A local service that can start `claude` and type into a terminal is code
execution on this PC if any web page can reach it. Any site open in Chrome can
send requests to localhost, and another port on 127.0.0.1 counts as the same
site to the browser. So the cockpit copies the dashboard's guard
(`src/Http/RequestGuard.php`) rule for rule, and the guard is phase 1's first
card, before any feature.

- **Bind.** `127.0.0.1:8788` only. Never `0.0.0.0`, never `::`.
- **Every request.** Remote address is loopback. `Host` is exactly
  `127.0.0.1:8788` or `localhost:8788` (blocks DNS rebinding). A browser request
  from another site may only be a top-level navigation (`Sec-Fetch-Site` and
  `Sec-Fetch-Mode`, as `checkRead` does).
- **API reads.** Transcripts and briefs are sensitive, so every `/api/` read also
  needs the token in the `X-KC-Token` header and a same-origin fetch that is not
  a navigation (as `checkApiRead`).
- **Writes.** POST only, token header, and `Origin` exactly
  `http://<Host>` (as `checkWrite`).
- **The token.** The existing `data/token`, read at start. The page gets it
  written into its HTML on a same-origin navigation. The page is never framed:
  CSP `frame-ancestors 'none'`.
- **The event stream and the terminal socket** cannot send headers from the
  browser, so they use a one-use ticket like the wall stream: a token-checked
  POST to `/api/ticket` returns a ticket good for 30 seconds and one connection.
  The WebSocket upgrade also checks `Origin` exactly, and caps message size.
- **CSP.** `default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src
  'self'; img-src 'self' data:; connect-src 'self'; font-src 'self';
  frame-ancestors 'none'; base-uri 'none'; form-action 'self'`. The
  `'wasm-unsafe-eval'` source lets the vendored MediaPipe WebAssembly compile for
  face tracking (5.5); it permits compiling WebAssembly only, never JavaScript
  `eval`. Every page also sends `Permissions-Policy: camera=(self),
  microphone=(self), geolocation=()`. Approved 2026-10-23 with the Cas head.
- **Starting processes.** Only `runner.mjs` starts a child process. It takes an
  absolute path from an allowlist (the real `claude.exe` under
  `%APPDATA%\npm\node_modules\@anthropic-ai\claude-code\bin\`, `node.exe`,
  `php.exe`), an argument array, and `shell: false`. It refuses `.bat` and
  `.cmd`.
- **Folders.** The client sends a project key, never a path. The server maps the
  key to a folder from a fresh listing of `D:\Dev\htdocs\*\.claude\project.md`
  plus the configured extras (`C:\Users\roban\kc-finance`, `D:\Dev\hq`), and
  checks `realpath` against the allowed root at launch, so a junction cannot
  redirect it. (`C:\xampp\htdocs\*` are junctions to `D:\Dev\htdocs\*`; the
  check resolves them to D: and accepts.)
- **Ids.** A session id from the client must appear in the current `claude
  agents --json` list.
- **Prompts** go to `claude` as one argument in the array or on stdin, never
  through a shell string.
- **Untrusted text.** Transcripts, briefs, reports, file names and mail subjects
  are untrusted. The page writes them with `textContent` only; the existing
  DomSinks check runs over `cockpit/public/` too. Any URL in them is shown as
  text, never as a link.
- **Rate limit.** At most 6 process starts a minute, so a runaway PM cannot flood
  the PC.
- **Audit.** Every write route and every process start appends a line to
  `data/cockpit-actions.log`: time, route, who (Rob's page or the PM), what, and
  the first 200 characters of any prompt after scrubbing.
- **The PM's key (phase 3).** The PM reaches the cockpit through a stdio MCP
  server with its own token, `data/pm-token`, which only opens the read routes
  and the propose, queue-for-Rob and start-a-ready-card routes. It cannot attach
  a terminal, stop anything or change rules.
- **Secrets.** The cockpit never reads `.credentials`, `.env` files or the
  values in any `settings.json`. To list a project's hooks it parses
  `settings.json` in code and keeps only the hook command names; the values never
  leave that function, never reach the page, a log or the database.

## 8. The PM's home (phase 3)

`D:\Dev\hq`, a folder of its own with:

- `CLAUDE.md`: its job. Read the cockpit's state through its tools, read the
  morning briefing, keep a list of what each project needs next, and propose
  work. It does not write code in projects itself; it starts managers who do. It
  never sends mail. Opus at low effort, per the loop's own measurement that this
  is the cheapest coordinator.
- `brief.md`: the standing brief Rob edits (priorities, which projects are hot,
  what may start without asking).
- `.claude/memory/` and its own auto-memory, apart from every project.
- `.mcp.json` registering the cockpit's stdio MCP server (`cockpit/mcp.mjs`)
  with tools: `workforce`, `project(key)`, `usage`, `briefing`,
  `propose(project, task, size)`, `queue_for_rob(item)`,
  `start_ready_card(project)`.
- The global rules and persona load as in every other folder.

What the PM may start without asking: only a card already on a project's board
marked ready, through that project's loop, under the governor. Anything else is a
proposal in the approvals queue. It talks to running workers with Claude Code's
own `SendMessage`; the cockpit watches those messages and never sends any itself.

## 9. One set of rules everywhere

Three tiers, and each is kept in step differently.

1. **Global, one copy.** `C:\Users\roban\.claude\CLAUDE.md`, the user-scope hooks
   in `~/.claude/hooks/`, the persona files. These apply to every session
   automatically, so they cannot drift. The cockpit shows them as the top row
   of the Rules screen with their last change time.
2. **Copied, one copy per project.** The 13 loop files `loop-copy.cjs` copies
   (`claude-loop.cjs`, `project-test.cjs`, `runner-watchdog.cjs`,
   `loop-cost.cjs`, their three tests, the `coordinate`, `brief` and `afk`
   skills, the `implementer`, `sweeper` and `planner` agents), with the global
   hash of each at copy time in `.claude/bin/SOURCE.txt`.
3. **Local, the project's own.** CLAUDE.md, `project.md`, project hooks, project
   agents. Hooks already drift by hand: Sembr has nine hook scripts in
   `.claude/hooks/`, this repo carries seven of them, without
   `infra-write-guard` and `shell-source-edit-blocker`.

**Drift states for each copied file in each project.**

- *In step:* the project file matches the current global after repointing paths.
- *Behind:* the global moved since the copy and the project made no changes of
  its own. Safe to bring up to date.
- *Own changes:* the project changed its copy; the global has not moved.
- *Diverged:* both moved. Needs a three-way merge.
- *Missing.*

`SOURCE.txt` alone cannot tell "behind" from "diverged", because it holds only
the hash of the old global, not its text. Phase 2 fixes that in the global
`loop-copy.cjs`: it also saves a snapshot of each global file as copied into
`.claude/bin/source/`. With that base, `git merge-file` gives a clean three-way
merge, and a new global `loop-sync.cjs <project>` brings "behind" files up to
date and writes "diverged" ones as merge results for a card to finish. It
refuses while that project's loop is running, exactly as `loop-copy.cjs` does.
The first run per project (no snapshot yet) treats every file whose global hash
changed as "diverged" and takes its base from git history where it exists.

**What the Rules screen shows.** A matrix: projects down the side; across the
top, the 13 loop files (coloured by state), loop copy date, the `project.md`
fields that matter (`test_command`, `gate_narrow`, `extra_checks`, `ci`), project
hooks, agent overrides, last test run result, loop state. A row for each project
hook name shows which projects have it and which lack it, which is how a project
hook worth promoting to global gets noticed. Promoting is a card, never
automatic.

It also shows one known gap from rule 7: personal skills win over project ones,
so the project copies of `coordinate`, `brief` and `afk` are inactive while the
global three still sit in `~/.claude/skills/`. The cockpit marks those cells
"inactive copy" rather than "in step".

**From the cockpit (phase 2).** A project row's "Bring up to date" runs
`loop-sync.cjs` through the runner, after a confirm. Drift is rechecked whenever
a global file or a project copy changes.

## 10. Phased build

Each card below is a candidate for the loop, sized per rule 9, with a done-test a
command can confirm. Paths are relative to `D:\Dev\htdocs\_kc-dashboard`.

### Phase 1: a read-only cockpit on real data

Rob opens `http://127.0.0.1:8788/` and sees every session across the 15
projects, the tree under each, any transcript live and the memory galaxy.
Nothing in phase 1 starts a process except `claude agents --json`, and nothing
outside this repo changes.

| # | Card | Size | Done-test |
|---|---|---|---|
| 1.1 | Service skeleton and guard: `server.mjs` on 127.0.0.1:8788, `lib/guard.mjs` ported from `RequestGuard.php`, CSP, ticket route, `extra_checks` and risky paths in `project.md` | Risky | `node --test cockpit/test/guard.test.mjs` passes, covering wrong Host, cross-site fetch, missing token, POST with no Origin, framed page; and `curl -s -o NUL -w "%{http_code}" -H "Host: evil.test:8788" http://127.0.0.1:8788/` prints `403` |
| 1.2 | The permanent index: port the spike to `lib/index/`, `node:sqlite`, WAL, incremental offsets, duplicate folders, scrubber, never-store list, `index_health` | Normal | `node --test cockpit/test/index.test.mjs` passes on fixture transcripts (including a half-written last line, a duplicate folder and a fake secret that must not reach the database); `node cockpit/bin/index.mjs --stats` prints agent-to-parent links resolved at 99% or better and a second run under 5 s |
| 1.3 | Live state and the event stream: sessions folder watch, `claude agents --json` poll, loop lock and PID, usage readings, `/events` with replay | Normal | `node --test cockpit/test/live.test.mjs` passes with a fake agents command and a fake loop folder, including reconnect with `Last-Event-ID` |
| 1.4 | Shell and skin: `tokens.css` shared with the dashboard, rail, top bar, breadcrumbs, routes, Ctrl+K, the boot frame and waiting-panel states (5, not skeletons), server-rendered first paint, phone stack; `bin/check-slop.mjs` and `bin/check-motion.mjs` | Normal | `node cockpit/bin/check-slop.mjs` and `node cockpit/bin/check-motion.mjs` exit 0 (every keyframe is off under reduced motion); `node C:\Users\roban\.claude\skills\mobile-first-responsive\scripts\responsive-sweep.cjs http://127.0.0.1:8788/` exits 0; `php tests/run.php` still passes with the dashboard on `tokens.css` |
| 1.5 | Overview: the core with orbits, Needs you, Live feed, workforce strip, timeline, usage, memory insights, index health | Normal | `node --test cockpit/test/overview.test.mjs`: the first-paint HTML holds every panel with a real value or "not connected", and the core lists every live session from a fixture agents list |
| 1.6 | Workforce: orbit view, tree view grouped by state, Messages tab | Normal | `node --test cockpit/test/workforce.test.mjs`: a fixture loop run renders as run, passes, sessions and agents in the right nesting, and the no-JS tree matches the orbit's nodes |
| 1.7 | Session: live transcript with nested agents, trace waterfall, files touched, scrubbed text | Normal | `node --test cockpit/test/session.test.mjs`: a line appended to a fixture transcript reaches an `/events` client within 2 s, and a planted fake key in the fixture never appears in any response |
| 1.8 | Project screen | Small | `node --test cockpit/test/project.test.mjs`: a fixture project shows its managers, loop cards with the max-per-session cost, and its memory files |
| 1.9 | Memory galaxy: cosmos.gl vendored with `VENDOR.txt`, node and edge API, centre and arms layout, comets, scrubber, top-files fallback | Normal | `node --test cockpit/test/graph.test.mjs`: the graph API returns no path on the never-show list and every persona file as path and counts only; `node cockpit/bin/check-vendor.mjs` matches each vendored file's sha256 to `VENDOR.txt` |
| 1.10 | Start at logon: Task Scheduler task `\KC Dashboard\Cockpit` (runs as Rob, restarts on failure), single-instance PID file, a Chrome app shortcut | Small | `powershell -c "(Get-ScheduledTask -TaskPath '\KC Dashboard\' -TaskName Cockpit).State"` prints `Ready` or `Running`, and starting a second copy of `server.mjs` exits with "already running" |

### Phase 2: driving sessions and keeping rules in step

| # | Card | Size | Done-test |
|---|---|---|---|
| 2.1 | `runner.mjs`: allowlisted executables, argument arrays, no shell, folder keys with the junction check, rate limit, audit log | Risky | `node --test cockpit/test/runner.test.mjs` passes, refusing a `.cmd`, a path from the client, a junction out of `D:\Dev\htdocs`, a seventh start in a minute |
| 2.2 | Terminal panes: node-pty, ws, xterm.js, ticketed socket, detach on close | Risky | `node --test cockpit/test/terminal.test.mjs`: a socket with a wrong Origin or a used ticket is refused; a fake `claude` echo program round-trips a keystroke |
| 2.3 | Start, stop and name background sessions, model and effort by size | Risky | `node --test cockpit/test/sessions.test.mjs` with a fake `claude.exe` records the exact argument array for each size, and stop refuses without the confirm field |
| 2.4 | Loops from the cockpit: start from the project copy, stop by `STOP` file, refuse a second loop | Normal | `node --test cockpit/test/loops.test.mjs` with a fake project folder |
| 2.5 | Scheduler and usage governor | Normal | `node --test cockpit/test/governor.test.mjs`: readings at 0.86 five-hour hold the queue until reset plus 2 min, 0.81 weekly stops it, a stale reading starts exactly one item |
| 2.6 | Panes and saved layouts (dockview) | Small | `node cockpit/bin/check-vendor.mjs` passes and the responsive sweep still exits 0 |
| 2.7 | Global: `claude-loop.cjs` writes `launched_by`, carried into each project copy | Risky | `node C:\Users\roban\.claude\bin\claude-loop.test.cjs` passes with a new launcher test; after a real run, `status.json` holds a `launched_by` that the index marks `status` |
| 2.8 | Global: the journal loader and other file-loading hooks write the access log | Normal | a new session start adds a line to `C:\Users\roban\.claude\state\access-log.jsonl` naming the journal file, and the index shows it as a `load` |
| 2.9 | Global: `loop-copy.cjs` saves a source snapshot, new `loop-sync.cjs` with three-way merge | Risky | `node --test` on a new `loop-sync.test.cjs`: fixtures for in step, behind, own changes, diverged and a running loop all give the right result |
| 2.10 | Rules screen: the drift matrix, the hook matrix, "inactive copy", Bring up to date | Normal | `node --test cockpit/test/drift.test.mjs` on fixture projects, and against the real projects `node cockpit/bin/drift.mjs --json` lists all 15 |

### Phase 3: the PM's home and the approvals queue

| # | Card | Size | Done-test |
|---|---|---|---|
| 3.1 | `D:\Dev\hq`: CLAUDE.md, `brief.md`, memory folder, `.mcp.json` | Small | `claude agents --json` lists a session named `pm` with cwd `D:\Dev\hq` after the cockpit starts it |
| 3.2 | `cockpit/mcp.mjs` and `data/pm-token` with the narrow route list | Risky | `node --test cockpit/test/mcp.test.mjs`: the PM token is refused on attach, stop and rules routes and accepted on propose |
| 3.3 | Approvals queue: proposals, parked cards, sessions needing input, merges waiting | Normal | `node --test cockpit/test/approvals.test.mjs`: a parked card in a fixture `status.json` and a `propose` call both appear, and Go on a proposal queues exactly one start |
| 3.4 | Chat pane with the PM over stream-json | Normal | `node --test cockpit/test/chat.test.mjs` with a fake stream-json `claude` |
| 3.5 | Dispatch records: PM to manager links in the hierarchy | Small | a fixture dispatch shows the started manager under the PM in `/api/workforce` |

### Phase 4: the morning

| # | Card | Size | Done-test |
|---|---|---|---|
| 4.1 | Prove `claude --bg` and `claude respawn` from a scheduled task | Small | a one-off task starts a background session named `respawn-probe` and `claude agents --json` lists it |
| 4.2 | mail-triage gets `.claude/project.md` and its loop copy (it has neither, so the loop cannot run there yet) | Small | `node C:\Users\roban\.claude\bin\loop-copy.cjs D:\Dev\mail-triage` prints `copied 13` |
| 4.3 | mail-triage drafting (in that repo): drafts saved to the mailbox's Drafts folder, never sent, plus a manifest file of draft metadata | Risky | mail-triage's own tests show a draft written by IMAP APPEND to Drafts and no SMTP code path; the manifest holds subject, mailbox and time, no body |
| 4.4 | Drafts in the approvals queue, read from the manifest only | Small | `node --test cockpit/test/drafts.test.mjs` |
| 4.5 | 06:30 task: respawn `pm` and pinned sessions, send the morning prompt | Normal | `powershell -c "Get-ScheduledTaskInfo -TaskPath '\KC Dashboard\' -TaskName 'Cockpit morning'"` shows a last result of 0 the morning after |
| 4.6 | Morning screen: overnight, needs you, the PM's plan, money, mail, briefing | Normal | `node --test cockpit/test/morning.test.mjs`: a fixture `alerts.json` shows only the display-only numbers the dashboard spec allows |

### Later: Cas knows who is there (Rob, 2026-10-23)

For the fun of it, not for security. Cas recognises Rob when he comes into the
camera's view and wakes: turns to him, says hello. It also recognises his
partner, and notices a stranger, and tries to talk with each of them. Cards
get written when this phase starts; the shape is fixed now:

- **All on this PC.** A small face-recognition model in the page turns a face
  into a fingerprint of numbers; only that fingerprint is stored, locally, never
  a photo, never sent anywhere. Check the model's licence first: several of the
  best are licensed for research only.
- **Enrolment needs the person there and agreeing.** Rob enrols himself; his
  partner enrols in person, in front of the camera, by choice, and can delete
  their fingerprint from the cockpit. A stranger is never enrolled and never stored:
  Cas greets an unknown face as unknown and forgets it.
- **Never a lock.** A photo can fool these models. Recognition only changes how
  Cas greets; it unlocks nothing. The token, Origin and Host checks stay the
  only guard.
- **Light on the machine.** While idle, detection runs at about 2 frames a
  second; the camera indicator shows whenever the camera is on, and an away
  toggle turns it off.
- **Memory.** Talking with people well may need Cas's memory to grow into a
  vector or graph store. That is its own later decision, not part of this.

## 11. Risks

- **Usage.** A workforce running in parallel spends the 5-hour and weekly windows
  fast; four lanes died at the cap on 2026-10-01. The governor and the loops' own
  quota checks are the guard, and the readings can be up to 30 minutes old when
  only interactive windows are working. The meter shows the age so a stale
  number never passes for a fresh one.
- **An undocumented format.** Transcripts and the sessions folder can change with
  any Claude Code update. Soft parsing, a parser version on every row and the
  unknown-record count in Index health catch it the same day.
- **Security.** The cockpit starts processes and hosts terminals. The guard is
  the first card, the runner is the only door, and every risky card gets the
  `security-auditor` pass.
- **Losing history.** Transcripts get deleted; the index is the only record. It
  goes into whatever backs up `data/`.
- **The graphics cost.** A GPU galaxy and animated canvases run while Rob works.
  Animation stops on hidden tabs, the canvas pixel ratio is capped at 2, and the
  galaxy stops its layout after 3 seconds.
- **`node:sqlite` is marked experimental** in Node 24 and prints a warning
  (checked). The service starts with that one warning silenced and refuses to
  start on a Node major it was not tested on.
- **Background sessions stop at shutdown**, and respawning from a scheduled task
  is unproven (card 4.1 proves it first).
- **Overlap with Anthropic's tools.** The Claude desktop app already runs several
  sessions in one window with panes. The cockpit's value is what it lacks:
  sessions from every source, the hierarchy, the galaxy, the morning, and the
  rules. Terminal panes come in phase 2, after Rob has the read-only cockpit.

## 12. Out

- Any model API call or API key, and the Max plan's API credit.
- Sending, moving or deleting mail. The cockpit reads mail-triage's draft
  manifest, never a mailbox.
- Agent teams.
- A global hook logging every tool call; OpenTelemetry.
- Indexing file contents, tool output or command text.
- Editing memory files from the cockpit (Agno allows it; here memory stays with
  the agents and Rob's editor).
- The cockpit sending messages into sessions. The PM does that with `SendMessage`.
- Writes to kc-finance or Bigcapital.
- Electron, Tauri or any build step.
- Reaching the cockpit from another device, and anything multi-user.

When this spec is approved, SPEC.md's "Out of scope" line about mail needs one
sentence pointing here, since the cockpit lists mail drafts. This spec leaves
SPEC.md untouched until then.

## 13. Open questions

1. **Seeing it on the phone.** The service listens on 127.0.0.1 only, so Rob's
   phone cannot reach it, and the read-only phone view only ever shows in a
   narrow window on this PC. *Recommendation:* keep it loopback through phase 4.
   For the phone, use Claude's Remote Control and the Telegram plugin for alerts.
   If Rob wants the cockpit itself on the phone later, that is one card: a
   read-only mirror with no write routes at all, behind Tailscale, never an open
   port.
2. **Does the new tab become the cockpit?** Both pages wear the JARVIS skin.
   *Recommendation:* no. The new tab stays the dashboard (attention, news,
   numbers); its Active agents panel links into the cockpit. Two jobs, two
   windows, one look.
