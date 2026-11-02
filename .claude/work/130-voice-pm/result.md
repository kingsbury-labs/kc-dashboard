# 130 result

plan.md holds 16 cards, plus a paragraph on what is unsafe and flags on the existing rules. In order: Rob approves the go list (waiting on him), windows-hide covers Python, PM identity and write fence, two hooks adapted to speech, the go-gate hook, the spoken go in cas-voice, the brain gets PM tools with every hook, live PM state, a 12-minute heartbeat, the conch moves to the PM, headless dispatch, the loop-start grant, HQ retirement, a live-check script, the live check with Rob, and a security audit.

Unsafe items flagged: a mic-driven brain with Bash (go gate); a forged approval (HMAC key plus fence); outward commands missing from the list (unreadable or network-writing commands count as gated, the audit hunts misses); direct project edits skipping that project's hooks (fence, then dispatch or loops); answer-only and voice-guard assuming typed text (Rob decides). Also: today's brain runs with `--setting-sources project`, so no global hooks fire. PROCESS.md claims a loop-start grant check that does not exist.

Not verified: the done-test command itself (this agent had no shell; checked by reading). Also unverified: the contents of the settings files (blocked, only probed by counts); whether `--permission-mode auto` denies any PM verb; whether SendMessage, Skill and Agent's `run_in_background` are there in `-p` stream mode. Card 7's probe checks the last two.
