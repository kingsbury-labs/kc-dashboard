<?php
// Card 119: the cockpit fitted to monitor 2 (1080 x 1920 portrait): hero on top, one fixed grid, no page scroll.

function fit_css(): string
{
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/cockpit.css');
    $at = strpos($css, '/* Card 119');
    assert_true($at !== false, 'the card 119 block exists');
    $block = substr($css, $at);
    assert_true(str_contains($block, '@media(min-width:1000px) and (max-aspect-ratio:4/5){'), 'inside the portrait query');
    return $block;
}

function fit_rule(string $css, string $selector): string
{
    assert_true(preg_match('/(?:^|[\n}])' . preg_quote($selector, '/') . '\{([^}]*)\}/', $css, $m) === 1, "rule $selector");
    return $m[1];
}

test('at the portrait query the overview root is 100vh and hides overflow', function () {
    $rule = fit_rule(fit_css(), '.overview-page.fit');
    assert_true(str_contains($rule, 'height:100vh'), 'height 100vh');
    assert_true(str_contains($rule, 'overflow:hidden'), 'overflow hidden');
});

test('the hero is the first child of the overview and the care banner follows it', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/views.js');
    assert_true(preg_match('/function renderFit\(page\) \{(.*?)\r?\n\}\r?\n/s', $src, $m) === 1, 'renderFit found');
    $body = $m[1];
    assert_same(2, preg_match_all('/page\.append\(/', $body), 'the page is appended to in two places only');
    $hero = strpos($body, 'page.append(hero, grid);');
    $banner = strpos($body, 'page.append(banner);');
    assert_true($hero !== false && $banner !== false && $hero < $banner, 'hero first, banner after it');
    assert_true(str_contains(fit_css(), '.care-banner{position:fixed'), 'the banner overlays and never pushes the layout');
});

test('every overview card sits in a named grid area', function () {
    $css = fit_css();
    $areas = ['needs' => 'needs', 'talk' => 'talk', 'email' => 'email', 'agents' => 'working', 'agenda' => 'agenda',
        'finance' => 'finance', 'news' => 'news', 'follow' => 'follow', 'care' => 'care'];
    foreach ($areas as $card => $area) {
        assert_true(str_contains($css, ".fit-grid>.$card-card{grid-area:$area}"), "$card-card has area $area");
        assert_true(str_contains($css, "\"$area") || str_contains($css, " $area ") || str_contains($css, " $area\""), "$area is in grid-template-areas");
    }
});

test('the list cards scroll inside their own slot', function () {
    $css = fit_css();
    assert_true(preg_match('/((?:\.fit-grid>\.[a-z]+-card,?)+)\{[^}]*overflow-y:auto/', $css, $m) === 1, 'a list-card rule with overflow-y:auto');
    foreach (['needs', 'talk', 'email', 'agents'] as $card) {
        assert_true(str_contains($m[1], ".fit-grid>.$card-card"), "$card-card scrolls");
    }
});
