<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

function voice_src(string $file = 'voice.js'): string
{
    return (string) file_get_contents(__DIR__ . '/../public/assets/' . $file);
}

function voice_page(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcvoice_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('5d', 32));
    try {
        [$status, , $body] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), [
                'answers' => fn() => [['status' => 'done', 'project' => '', 'question' => 'q', 'answer' => 'a done answer']],
            ], strtotime('2026-10-19T12:00:00+00:00'));
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_same(200, $status);
    return $body;
}

test('voice.js offers only local voices and keeps the choice in localStorage inside try/catch', function () {
    $src = voice_src();
    assert_true(str_contains($src, 'localService === true'), 'filters on localService');
    assert_true(preg_match('/getVoices\(\)\.filter\(\(v\) => v\.localService === true\)/', $src) === 1, 'the only voice list is filtered');
    assert_same(1, substr_count($src, 'getVoices('), 'getVoices is read in one place');
    preg_match_all('/localStorage/', $src, $m, PREG_OFFSET_CAPTURE);
    assert_same(2, count($m[0]), 'storage read and write');
    foreach ($m[0] as [, $at]) {
        $before = substr($src, max(0, $at - 60), 60);
        assert_true(preg_match('/try \{\s*return window\.$|try \{\s*window\.$/', $before) === 1, 'localStorage outside try at ' . $at);
    }
    assert_true(substr_count($src, 'catch {') >= 2, 'both storage calls have a catch');
});

test('voice.js listens for boundary and end, drives the orb, and speaks with a local voice only', function () {
    $src = voice_src();
    assert_true(str_contains($src, "addEventListener('boundary'"), 'boundary');
    assert_true(str_contains($src, "addEventListener('end'"), 'end');
    assert_true(str_contains($src, "from './orb.js'") && str_contains($src, "setState('speaking')") && str_contains($src, "setState('idle')"), 'orb state');
    assert_true(str_contains($src, 'setLevel(1)'), 'pulses the level on a boundary');
    assert_true(str_contains($src, 'u.voice = voice') && str_contains($src, '!voice'), 'no local voice, no speech');
    assert_true(str_contains($src, 'synth.cancel()'), 'stop');
});

test('voice.js holds no fetch or XMLHttpRequest, so no text leaves the browser', function () {
    $src = voice_src();
    foreach (['fetch', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource'] as $api) {
        assert_true(!str_contains($src, $api), "voice.js mentions $api");
    }
});

test('the speak control is absent from the no-JS page, and voice.js is wired in', function () {
    $body = voice_page();
    assert_true(str_contains($body, '<script type="module" src="/assets/voice.js"></script>'), 'voice.js loaded');
    assert_true(str_contains($body, 'a done answer'), 'the answer shows with JS off');
    foreach (['data-speak', 'class="speak"', 'voice-pick', 'voice-stop', '>Speak<', '>Stop<'] as $needle) {
        assert_true(!str_contains($body, $needle), "no-JS HTML holds $needle");
    }
    assert_true(preg_match('#<button[^>]*id="talk-button"[^>]*\bdisabled\b#', $body) === 1, 'Talk to Jarvis is disabled until voice.js enables it');
    assert_true(!str_contains($body, 'voice in later') && !str_contains($body, 'Voice in not connected'), 'old placeholder text gone');
    $app = voice_src('app.js');
    assert_true(str_contains($app, "import { speakButton } from './voice.js'"), 'app.js builds the answer speak button');
    assert_true(str_contains($app, "item.dataset.status !== 'done'") || str_contains($app, "li.dataset.status !== 'done'"), 'only finished answers get one');
});

test('voice.js reads the briefing only after Brief me and only for a dated briefing', function () {
    $src = voice_src();
    assert_true(str_contains($src, "closest('[data-brief]')"), 'armed by Brief me');
    assert_true(str_contains($src, '/^Briefing \d/'), 'a dated heading only, so recall and errors stay quiet');
    assert_true(str_contains($src, 'if (!armed ||'), 'unarmed mutations are ignored');
});

test('voice.js and app.js leave no DOM sinks', function () {
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
});
