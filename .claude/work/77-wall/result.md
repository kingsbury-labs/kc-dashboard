# 77. Wall page: result

Files: new `public/wall.php`, `api/wall-stream.php`, `wall.js`, `wall.css`, `WallTest` (8), `WallStreamTest` (5). `View.php` gains `View::wall` (checkRead, then the same strip and tiles as the new tab, so all escaping stays in View). Changed outside the list: `ApiGuardSweepTest.php`. It swept wall.php as an API and wanted 403 on a navigation, so wall.php joins index.php in `SWEEP_PAGES`, with its own test: foreign Host 403, two navigations render, no program starts. Full suite: 222/222.

Stream design: wall.js POSTs with the token (checkWrite) for a ticket. The ticket is 32 random bytes, good once, for 30 s, and only its hash is stored. It goes in `?ticket=`. The GET checks Host, same-origin and not a navigation, and spends the ticket only after that passes. I chose a query value over a cookie: a cookie also goes out from other localhost ports. Apache's combined access log does record the query string, ticket included. The line is written when the request ends, after the ticket is spent. A vhost CustomLog without the query string is a follow-up for Rob. The test uses php-cgi, not php.exe, so the 403 status shows.

Mutations, each restored: a ticket no longer removed failed "works once". No expiry check failed "expired ticket". Spending before the guard failed "does not spend the ticket".

Round two: the coordinator accepted the sweep change and gave me `ops/http-check.php`. It now treats `/wall.php` as a page: foreign Host 403, navigation 200. The stream is checked live: no ticket 403, a POST without the token 403, a POST with the token gives a ticket, the ticket's GET gives 200 with an `event: now` (reads 8 KB at most), and the same ticket again gives 403. Neither the token nor a ticket is printed. Live run exits 0.

## Round three

1. If the ticket file can't be opened or locked, both the mint and the stream now refuse with 503. The test makes the file a directory, so fopen fails. Making flock itself fail wasn't feasible on Windows.
2. New `RequestGuard::checkFetchSite` checks address, Host, and a same-origin non-navigation fetch, with no token. checkApiRead calls it, and the stream uses it in place of the ticket-as-token guard. RequestGuardTest +1.
3. wall.js closes the EventSource when the tab is hidden, and reopens it with a fresh ticket when visible (grep in WallTest).
4. The sweep's wall.php test gains the cross-site navigation GET and POST.

Mutations, each restored: drop the fopen check failed the 503 test (500 instead). Let checkFetchSite accept navigations failed both RequestGuard tests, NowApi, the sweep and WallStream. Accepting `same-site` failed nothing: checkRead already refuses it, so that line is backup only.

Full suite 224/224. http-check exits 0 live.

Not done: `/wall` routing (left at `/wall.php`, which the coordinator logs for Rob).
