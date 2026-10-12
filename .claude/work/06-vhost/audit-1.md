# Security audit, round 1: fix all of these, same three files only

1. Port-80 deny block (ops/apache-kc-dashboard.conf:7-9) inherits AllowOverride All from htdocs, so a .htaccess under the project could re-grant on port 80. Add `AllowOverride None` and `Options None` inside the deny block; loosen tests/VhostConfTest.php:30 so the block may hold those lines, and assert them.
2. The 8787 vhost inherits main-server Alias/ScriptAlias/ProxyPass/Location (/phpmyadmin, /cgi-bin/, /php-cgi/, /server-status, /server-info, /licenses, /webalizer, /icons/, /examples). They are same-origin with the dashboard. Inside the VirtualHost add:
   <If "%{REQUEST_FILENAME} !~ m#^C:/xampp/htdocs/_kc-dashboard/public(/|$)#i || ! -e %{REQUEST_FILENAME}">
       Require all denied
   </If>
   and a test asserting it is present inside the VirtualHost. Also add those inherited paths to the 8787 probes in http-check.php, expecting 403/404.
3. public/ on 8787 inherits FollowSymLinks Includes ExecCGI. Use `Options None` there; update the regex at VhostConfTest.php:23 (assert Options None, still no Indexes).
4. http-check.php port-80 probes send Host 127.0.0.1, which lands on the wrong vhost: send `Host: localhost`, add `/_kc-dashboard/public/` to the probe list, and repeat on http://[::1] and https://127.0.0.1 (verify peer off for the local cert only).
5. http-check.php lines 80-82 forbid the token in the body of `/`, but card 11 puts it in a <meta> tag there. Allow the token only inside that meta tag on that single request.
6. Missing data/token must be a FAIL, not a silently tokenless run.
7. Set CURLOPT_PROXY => '' on every request.
8. Test gap: assert that outside the VirtualHost no `Require all granted` applies to any path under the project.
