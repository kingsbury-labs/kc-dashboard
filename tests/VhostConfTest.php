<?php
function vhost_conf(): string
{
    $text = file_get_contents(__DIR__ . '/../ops/apache-kc-dashboard.conf');
    assert_true(is_string($text), 'snippet missing');
    // Drop comment lines so a commented-out directive does not count.
    return preg_replace('/^\s*#.*$/m', '', $text);
}

test('listens on both loopback addresses at 8787', function () {
    $c = vhost_conf();
    assert_true((bool) preg_match('/^\s*Listen\s+127\.0\.0\.1:8787\s*$/m', $c), 'Listen 127.0.0.1:8787');
    assert_true((bool) preg_match('/^\s*Listen\s+\[::1\]:8787\s*$/m', $c), 'Listen [::1]:8787');
    assert_true((bool) preg_match('/<VirtualHost\s+127\.0\.0\.1:8787\s+\[::1\]:8787\s*>/', $c), 'one vhost on both');
});

test('DocumentRoot ends in /public', function () {
    assert_true((bool) preg_match('#^\s*DocumentRoot\s+"?C:/xampp/htdocs/_kc-dashboard/public"?\s*$#mi', vhost_conf()));
});

test('Indexes off and display_errors off', function () {
    $c = vhost_conf();
    assert_true((bool) preg_match('#<Directory\s+"?C:/xampp/htdocs/_kc-dashboard/public/?"?\s*>(?:(?!</Directory>).)*^\s*Options\s+None\s*$#msi', $c), 'Options None on public/');
    assert_true(!preg_match('/^\s*Options\b.*[^-]\bIndexes\b/mi', $c), 'Indexes enabled somewhere');
    assert_true((bool) preg_match('/^\s*php_admin_flag\s+display_errors\s+off\s*$/mi', $c), 'display_errors off');
});

test('project folder denied outside the vhost', function () {
    $c = vhost_conf();
    $outside = preg_replace('#<VirtualHost.*?</VirtualHost>#s', '', $c);
    assert_true((bool) preg_match('#<Directory\s+"?C:/xampp/htdocs/_kc-dashboard/?"?\s*>(.*?)</Directory>#si', $outside, $m),
        'port-80 deny on the project folder');
    $block = $m[1];
    assert_true((bool) preg_match('/^\s*Require\s+all\s+denied\s*$/mi', $block), 'Require all denied');
    assert_true((bool) preg_match('/^\s*AllowOverride\s+None\s*$/mi', $block), 'AllowOverride None');
    assert_true((bool) preg_match('/^\s*Options\s+None\s*$/mi', $block), 'Options None');
    $lines = array_filter(array_map('trim', explode("\n", $block)));
    assert_true(count($lines) === 3, 'deny block holds only those three lines');
});

test('nothing outside the vhost grants access', function () {
    $outside = preg_replace('#<VirtualHost.*?</VirtualHost>#s', '', vhost_conf());
    assert_true(!preg_match('/Require\s+all\s+granted/i', $outside), 'Require all granted outside the vhost');
});

test('vhost denies anything outside public/', function () {
    assert_true((bool) preg_match('#<VirtualHost[^>]*>(.*?)</VirtualHost>#s', vhost_conf(), $m), 'vhost missing');
    $want = '<If "%{REQUEST_FILENAME} !~ m#^C:/xampp/htdocs/_kc-dashboard/public(/|$)#i || ! -e %{REQUEST_FILENAME}">';
    $pos = strpos($m[1], $want);
    assert_true($pos !== false, 'public-only <If> missing from the vhost');
    assert_true((bool) preg_match('#^\s*Require\s+all\s+denied\s*</If>#i', substr($m[1], $pos + strlen($want))), '<If> must deny');
});

test('no Listen 80 and no 0.0.0.0', function () {
    $c = vhost_conf();
    assert_true(!preg_match('/^\s*Listen\s+(\S*:)?80\s*$/m', $c), 'Listen 80 present');
    assert_true(strpos($c, '0.0.0.0') === false, '0.0.0.0 present');
});
