# 70. Facts table and the attention list: result

Rounds one and two: migration, FactStore, Selector plus AttentionApi, both endpoints, attention.js, the no-JS panel, `link_ports => [8000]`, panel CSS. Mutations then: port list, reason check, checkWrite, crossing `<`. Each failed its test.

## Round three

Fixes: safeUrl knows the source (finance only under `http://127.0.0.1:8000/`; https to localhost, IPv6 literals, 0 or 127 dotted, number-only hosts refused). Ids must start `<source>:`, stay with their first source, be at most 512 bytes of UTF-8 with no control characters. Last good run more than 5 min ahead is stale. A moved due date resets crossings. Due date: `\z` plus checkdate. `AttentionApi::linkPorts()` (none when the key is missing) in both callers. `last_error` redacted. Wall renders "Attention unavailable." without data or on failure; so does attention.js on a failed read.

Tests: FactStore 13, AttentionApi 15. Full suite 255/255 on the final state.

Mutations, each restored: finance rule off, loopback refusal off, stored-source check off, future-slack off, due-date reset off, id cap at 4096. Each failed its new test. None refused.

Note: the PII hook blocked two literal IPs in test data, so a non-.0.0.1 loopback and a public-IP https case are untested. The wall gets no-JS data only if `wall.php` passes an `attention` dep (out of scope).
