<?php
function spec_text(): string
{
    return (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'SPEC.md');
}

foreach ([
    '127.0.0.1:8787',
    'never stored',
    'UIDVALIDITY',
    '8.2.18',
    'realpath',
    '--cache-type-k q8_0',
    'enable_thinking',
    'collect_ga.py',
    'S4U',
] as $needle) {
    test("spec contains $needle", function () use ($needle) {
        assert_true(str_contains(spec_text(), $needle), "SPEC.md is missing: $needle");
    });
}

test('spec no longer says .htaccess denies', function () {
    assert_true(!str_contains(spec_text(), '.htaccess denies'), 'SPEC.md still has the .htaccess bullet');
});
