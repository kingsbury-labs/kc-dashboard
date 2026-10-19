<?php
require_once __DIR__ . '/../src/Llm/StructuredOutput.php';

const SO_SCHEMA = [
    'verdict' => ['type' => 'string', 'required' => true, 'enum' => ['raise', 'hold', 'drop']],
    'reason' => ['type' => 'string', 'required' => true, 'max' => 10],
    'score' => ['type' => 'int'],
];

test('extra fields are dropped', function () {
    $r = StructuredOutput::parse('{"verdict":"hold","reason":"ok","url":"https://evil.example","cmd":"x"}', SO_SCHEMA);
    assert_same(['verdict' => 'hold', 'reason' => 'ok'], $r);
});

test('an out-of-enum value gives null', function () {
    assert_same(null, StructuredOutput::parse('{"verdict":"run","reason":"ok"}', SO_SCHEMA));
});

test('invalid JSON, a list, a wrong type or a missing required field gives null', function () {
    foreach (['{"verdict":"hold",', 'not json', '["hold","ok"]', '{"verdict":"hold","reason":3}',
              '{"verdict":"hold"}', '{"verdict":"hold","reason":"ok","score":"9"}', ''] as $json) {
        assert_same(null, StructuredOutput::parse($json, SO_SCHEMA), $json);
    }
});

test('an over-length reason is truncated', function () {
    $r = StructuredOutput::parse('{"verdict":"raise","reason":"' . str_repeat('é', 25) . '","score":4}', SO_SCHEMA);
    assert_same(['verdict' => 'raise', 'reason' => str_repeat('é', 10), 'score' => 4], $r);
});

test('a fenced reply is unwrapped', function () {
    $r = StructuredOutput::parse("```json\n{\"verdict\":\"drop\",\"reason\":\"stale\"}\n```", SO_SCHEMA);
    assert_same(['verdict' => 'drop', 'reason' => 'stale'], $r);
});
