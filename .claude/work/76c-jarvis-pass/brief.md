# 76c. JARVIS visual pass

## Objective
Make the live page at http://127.0.0.1:8787/ read as Rob's sample
`design-refs/1-jarvis-command-center.png` at a glance. Card 76b got the layout
right and the look wrong. Rob, 2026-10-20: "it is not visually close and needs
to be worked on." This card is visual: CSS, canvas and the markup the panels
need. No new endpoints.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\mission-control.md`
- Every image in `D:\Dev\htdocs\_kc-dashboard\design-refs\` (Read tool). Sample 1 is the target; 3 for light lines into a core; 5 for gauges.
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\76b-command-center\result.md`
- `c:\personas\cas\memory\design-slop.md`
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `.claude\project.md`

## What is wrong now (coordinator's screenshot at 1432x1073, 2026-10-20)

Broken, fix first:
1. Top bar: the Ask box, the project select, the Ask button and a "Search Google" field collide; the Ask button is half under the search field. Sample: one search field with its shortcut chip, then small square icon buttons. Keep Ask Claude as the search field; the project picker and Google search go somewhere that does not collide (inside the Ask field's row as a compact chip, or a second row on phone).
2. Rail voice panel overflows: the waveform, the voice `<select>`, the Stop button and the helper text run about 120px past the rail into the grid; the round mic button straddles the rail edge. Everything stays inside the rail. Sample: waveform, "Listening..." line, a big round glowing mic button centred with a double ring, "Tap to speak". The voice picker belongs in a small settings affordance, not a full-width select.
3. The agent-to-core light lines are drawn on top of other panels (through the core overview and across agent tiles). They read as stray wires. Draw them behind the panels (z-index below), curved, faint, with a moving pulse, converging on the core; if they cannot avoid crossing text, drop them in favour of a glowing node per busy agent on the globe.
4. Footer: the session chips are clipped mid-word and a second row is cut off. Sample footer: three small tiles left (location, weather, network; ours: traffic, network, dev servers), the capsule centred with waveform lines extending out both sides, Executive briefing as a bordered button right. No clipped text.

Look, in order of how much it matters:
5. The core. Sample: the globe fills about 70% of its panel's height, sits on a bright bloom of light, dense lat/long mesh, bright nodes with small lens flares, two wide tilted orbit rings, a large glowing ripple platform beneath. The name is big, widely spaced, glowing text across the middle of the globe ("KC" large, "AI CORE" under it, version under that), not small at the top. Ours: smaller, dimmer, label small at the top, floor faint. Add the JARVIS genre's segmented HUD rings (arc-reactor style tick rings, rotating at different speeds) around the globe.
6. Light. The sample is lit: navy page (about #06142b to #0b1f3f) with a soft bloom behind the core, dot grid visible, panels glowing. Ours is near-black and flat. Brighter cyan borders (alpha 0.5 to 0.6), a soft outer glow, a lighter top edge inside each panel (inner gradient), each row inside a panel as its own bordered glass sub-card.
7. Agent tiles. Sample: each agent has its own colour (green, blue, violet, amber, teal): coloured icon disc, coloured status word, and a smooth animated waveform in that colour. Ours are identical violet boxes with blocky bars that look like a progress bar. Use the mission-control colours by type (sessions amber, loops violet, collectors blue, failed red) and vary hue within a type so three sessions do not look like one.
8. Density. The sample has no empty panels. Ours: the feed says "Nothing needs you" and leaves 80% blank; the timeline has one row; memory insights is one dot; LLM status is two tiles in a wide panel. Fill each from data the dashboard already has, nothing invented:
   - Feed: when attention is empty, show the latest news headlines (INFO), loop and session events (LIVE), and system monitor notes (TIP, e.g. disk 96%), with "View all intelligence" at the bottom.
   - Timeline: the scheduled jobs from `bin/register-tasks.ps1` (daily read, briefing, collectors, news) with their next run time, a progress bar and an "in 42 min" countdown, plus the attention due dates. "View full schedule" link.
   - Memory insights: a constellation of the 52 projects and vault notes as twinkling stars with a few links (sample 7 in miniature), counts beside it, "View memory map" link.
   - LLM status: one tile per model reachable through `claude -p` (Opus, Sonnet, Haiku, from the configured CLI) plus the local llama-server, each connected or not, "N connected" in the title row.
9. Gauges: thinner rings with a dark track and a glow, value and label inside (sample 1 and 5).
10. Panel title rows: title left, a small "View all >" or count chip right, as in the sample.

Motion (the page must visibly move at rest): globe turning, HUD rings counter-rotating, nodes twinkling, ripple platform pulsing outward, agent waveforms moving, light-line pulses travelling to the core, status dots pulsing, the capsule waveform moving, numbers counting up on change. Reduced motion and hidden-tab rules from mission-control.md still apply.

Type: CSP is `default-src 'self'`, so no CDN fonts. Bahnschrift stays unless Rob OKs vendoring a font file; do not download anything.

## How to work
Look, change, look again. Use the Chrome browser tools if you have them (ToolSearch "select:mcp__claude-in-chrome__tabs_context_mcp,mcp__claude-in-chrome__navigate,mcp__claude-in-chrome__computer,mcp__claude-in-chrome__javascript_tool", open your own new tab). Screenshot the live page next to sample 1 after each round of changes and keep going until it reads as the sample, at least three rounds. Check a phone width too (Chrome's window may not shrink below about 500px; check `innerWidth`, and if it will not go narrow, test the layout with a narrow iframe-free method such as temporarily inspecting `getBoundingClientRect` overflow at the smallest width you can get). No horizontal scroll at any width.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\public\assets\hud.css`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.css`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\wall.css`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\orb.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\core.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\now.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\voice.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\attention.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.js`
- `D:\Dev\htdocs\_kc-dashboard\src\View.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Running\NowService.php` (only to expose data already collected, such as the schedule)
- `D:\Dev\htdocs\_kc-dashboard\tests\CommandCenterTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\HudTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\PageUiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\WallTest.php`

## Done-test
`php tests/run.php --filter CommandCenter`, `Hud`, `PageUi`, `Wall` pass; full suite passes through the runner; `php ops/http-check.php` exits 0. CommandCenterTest adds: the feed renders news items when attention is empty; the timeline renders the scheduled jobs; LLM status renders a tile per configured model; nothing in the voice panel is wider than the rail (checked in markup by class, since the suite has no browser). HudTest keeps all its 76b checks. Mutation: at least two, through the runner.

Visual done: result.md holds the final 1432-wide screenshot path and an honest list of what still differs from sample 1.

## Boundaries
Same as 76b: every security property holds (token on API reads, POST for work, `textContent` only, CSP unchanged, no inline style or script, no web fonts, no CDN, DomSinks clean, every untrusted string escaped). Mobile first, `min-width` only. No commit, no push, no install, no download. If the permission check refuses a mutation run, restore at once and list it as owed. Edit PHP only with Edit/Write. A file not listed is out of scope; stop and say so in result.md.

## Report
`result.md` beside this file, under 250 words.
