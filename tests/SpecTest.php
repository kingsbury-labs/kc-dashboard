<?php
function spec_text(): string
{
    return (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'SPEC.md');
}

foreach ([
    '127.0.0.1:8787',
    'never stored',
    '8.2.18',
    'realpath',
    '--cache-type-k q8_0',
    'enable_thinking',
    'collect_ga.py',
    'S4U',
    // revision 4/5 facts
    'claude -p --model sonnet',
    'D:\Dev\htdocs',
    'facts',
    'alerts.json',
    'also seen',
    'coverage',
    'http://127.0.0.1:8000/',
    '26h',
] as $needle) {
    test("spec contains $needle", function () use ($needle) {
        assert_true(str_contains(spec_text(), $needle), "SPEC.md is missing: $needle");
    });
}

test('spec no longer says .htaccess denies', function () {
    assert_true(!str_contains(spec_text(), '.htaccess denies'), 'SPEC.md still has the .htaccess bullet');
});

test('spec has no old xampp htdocs path', function () {
    assert_true(stripos(spec_text(), 'c:\\xampp\\htdocs') === false, 'SPEC.md still names c:\\xampp\\htdocs');
});

test('spec has no gpt-oss', function () {
    assert_true(stripos(spec_text(), 'gpt-oss') === false, 'SPEC.md still names gpt-oss');
});

test('spec has no IMAP', function () {
    assert_true(!str_contains(spec_text(), 'IMAP'), 'SPEC.md still names IMAP');
});
