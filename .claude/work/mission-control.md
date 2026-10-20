# Command center: the look Rob wants

Rob, 2026-10-19, after seeing the card 75-77 pages: "That looks NOTHING like the
black JARVIS style dashboard with the animations." Then, with samples: "I want all
my agents connected in an Iron Man JARVIS style interface."

The samples are in `design-refs/` at the project root (gitignored, third-party
images, never commit or publish them). Open them before any UI work.

- `1-jarvis-command-center.png` is THE target. Build to it.
- `3-core-with-panels.png`: panels wired to a glowing core with light lines.
- `2-privacy-flow.png`: glowing connector lines between panels, colored by meaning.
- `5-system-info.png`: tile and gauge treatment (icon disc, big value, sparkline).
- `7-memory-galaxy.png`: the vault and memory view as a particle galaxy.
- `4-glass-planner.png`: frosted dark glass panels, softer; secondary reference.

## Sample 1, what it is

Deep navy to black page with a faint dot grid. Every panel is dark glass with a
thin cyan border that glows, rounded corners, a small all-caps panel title in cyan.

- **Left rail:** "JARVIS Command Center" mark with a ring icon, nav list with
  icons and count badges, a voice panel at the bottom (live waveform, a big round
  glowing mic button, "Tap to speak"), a Focus mode button.
- **Top bar:** "System status: Optimal" pill with a green dot, a large live clock
  and date in the centre, search with a shortcut, icon buttons, the operator.
- **Centre:** the AI core. A big wireframe globe (lat/long lines, glowing nodes
  on it), two tilted orbit rings, a ripple floor under it, "JARVIS / AI CORE /
  v3.0.0" across it. This is the heart of the page.
- **Left of core:** "AI core overview": rows for core, memory, voice, agents,
  LLMs, system, each with an icon disc and a colored status word.
- **Right of core:** "Live intelligence feed": rows with icon, title, one line,
  and a tag (INFO, WARN, TIP, LIVE), the last with an action button.
- **Middle row:** "Active agents" grid (each agent a tile with icon, name, status
  dot, and its own live waveform or sparkline in its color), "Mission timeline"
  (times, items, progress bars, "in 42 min"), "Quick commands" (four buttons).
- **Bottom row:** "System monitor" (three gauge rings: CPU, RAM, disk),
  "Memory insights" (a small constellation graph plus counts), "LLM status"
  (provider tiles, connected or not).
- **Footer bar:** location, weather, network on the left, a glowing "Talk to
  Jarvis" capsule with a waveform in the centre, "Executive briefing" on the right.
- **Motion everywhere:** the globe turns, orbit rings rotate, nodes twinkle,
  waveforms move, status dots pulse, borders breathe, the clock ticks.

## Ours, panel by panel

Same layout, real data only. Every number and status comes from something this
dashboard already collects; a panel with no source shows "not connected", never a
made-up value. The page is named "KC Command Center".

- **Left rail:** mark, nav (Command center, Projects, Attention with count, News,
  Numbers, Vault, Wall), voice panel (card 80's speak control and the orb level as
  the waveform; mic waits for voice in, which is Later, so the button reads "Brief
  me" until then).
- **Top bar:** systems pill from coverage health (Optimal green, Degraded amber,
  Fault red), live clock and date, the search and Ask Claude box as the search
  field (Ctrl+K), a settings icon.
- **Centre, the core:** the orb becomes the wireframe globe from sample 1, canvas
  2D, no library: rotating lat/long sphere, glowing nodes, two orbit rings, ripple
  floor. Label "KC / AI CORE" and the Claude Code version. Idle, thinking and
  speaking states change spin speed and glow. Each agent is a node on the globe
  or orbit, and a light line runs from its panel to the core while it is busy
  (sample 3).
- **AI core overview (left of core):** Claude Code (version, connected or not),
  daily read (last run, ok or failed), worker queue (jobs waiting), vault (notes
  written, last write), memory (entries), system (coverage health).
- **Live intelligence feed (right of core):** the attention list. Tag by
  severity (WARN for high, INFO, TIP for a daily-read reason, LIVE for running
  state), "also seen" behind a View all link.
- **Active agents:** the agents are Rob's Claude Code sessions and each project's
  loop, then the collectors. One tile each: name (project slug or collector),
  status (busy, waiting, idle, stale), the current card or task, and a live
  sparkline or waveform in the agent's color while busy. Sessions amber, loops
  violet, collectors blue, failed red.
- **Mission timeline:** today's due dates and follow-ups from the attention facts
  and the clients file, plus scheduled jobs (daily read 6:00, briefing 6:15) with
  "in 42 min" countdowns.
- **Quick commands:** Ask Claude, Brief me, Open wall, Run collectors now (queues
  jobs through the token-checked POST; nothing runs from a GET).
- **System monitor:** CPU, RAM and disk gauge rings from Windows (one PowerShell
  CIM query through ProcessRunner, cached 10 s, part of `now.php`).
- **Memory insights:** a small constellation of vault notes and links (sample 7
  in miniature), counts of notes, memories and briefings. The full galaxy is a
  later card.
- **LLM status:** Claude Code (connected when `claude.exe` is present), local
  model (llama-server: running or off), each as a provider tile.
- **Footer bar:** numbers summary (sessions and users today across properties),
  network, the "Talk to Jarvis" capsule (Brief me now, voice in later),
  "Executive briefing" opens today's briefing.
- **Projects page:** the full map as a dense table in the same skin, not tiles.
- **Running ports:** only node, php, python and httpd on ports from 1024 up, plus
  8787 and 8000. AVG, Steam, system services and the like are dropped. The
  allowed program list lives in `config/running.php`.

## Skin

Always dark, no light theme. Background `#030712` to `#061226` radial, dot grid
at 4% opacity. Panel fill `rgba(8, 20, 40, 0.72)` with a 1px border
`rgba(56, 189, 248, 0.35)` and an outer glow `0 0 24px rgba(56, 189, 248, 0.12)`.
Primary glow cyan `#38bdf8` / `#22d3ee`; agent colors amber `#f5b544`, violet
`#a78bfa`, blue `#60a5fa`, green ok `#34d399`, red fault `#f87171`. Text
`#e2f3ff`, muted `#7aa6c2`.

Type with no web fonts (CSP is `default-src 'self'`): headings and panel titles in
Bahnschrift (ships with Windows, the DIN look in sample 1), clock and numbers in
Bahnschrift with tabular figures, body in "Segoe UI Variable Text", system-ui.
Panel titles are short all-caps with modest tracking (0.08em). That is the
sample's look and Rob asked for it; it is a panel title inside a panel, not an
eyebrow above a page headline (design-slop rule 1 still bans that).

## Motion

The page is alive at rest and complete at rest: nothing waits at opacity 0.

- Globe turns (about 40 s a turn), orbit rings counter-rotate, nodes twinkle.
- Busy agents: waveform or sparkline moves, a light line pulses to the core.
- Status dots pulse 1.6 s; borders breathe slowly (glow 0.10 to 0.18, 6 s).
- Clock ticks each second; numbers count up when they change.
- `prefers-reduced-motion`: one still frame of the globe, no pulses, no count-up.
- Animation pauses while the tab is hidden.

## Layout

Mobile first. On a phone: top bar, core (smaller), then panels stacked in reading
order (feed, agents, timeline, commands, monitor, memory, LLM), the Talk capsule
fixed at the bottom. At 1024px and up: the left rail appears and the 3-column
grid of sample 1 forms. At 1600px and up: the wall layout, everything on one
screen. `/wall.php` is this page at full screen with no rail.

## Rules that still hold

Works with no JS (the server renders every panel with its current values; the
globe falls back to a static CSS ring). DomSinks clean, `textContent` only, token
on every API read, no `max-width` media query, every repo, feed or model string
escaped. No filled-plus-outlined button pair, no beige, no comma in a heading.
