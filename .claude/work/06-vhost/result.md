# 06 result (round 2, audit-1 fixes)

Files: `ops/apache-kc-dashboard.conf`, `ops/http-check.php`, `tests/VhostConfTest.php` (now 7 tests).

Audit items:
1. Port-80 deny block now has `AllowOverride None`, `Options None`, `Require all denied`; test asserts all three and that the block holds nothing else.
2. `<If ...> Require all denied </If>` (audit's exact text) inside the VirtualHost; new test asserts it is inside the vhost and denies. Inherited paths (/phpmyadmin/, /cgi-bin/, /php-cgi/, /server-status, /server-info, /licenses/, /webalizer/, /icons/, /examples/) added to the 8787 403/404 probes.
3. public/ uses `Options None`; test asserts it and that Indexes is never enabled.
4. Port-80 probes send `Host: localhost`, include `/_kc-dashboard/public/`, run on http://127.0.0.1, http://[::1], https://127.0.0.1 (peer and host verify off for the https one only).
5. On `/` GET, one `<meta>` tag containing the token is removed before the token search.
6. Missing or empty data/token prints FAIL and exits 1.
7. `CURLOPT_PROXY => ''` on every request.
8. New test: no `Require all granted` outside the VirtualHost.

Done-test: `--filter VhostConf` 7 passed; `php -l ops/http-check.php` clean.

Mutation: deleted the `<If>` block; "vhost denies anything outside public/" failed. Restored, 7 green.
