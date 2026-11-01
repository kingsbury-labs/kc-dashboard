<?php
// Card 121: the portrait hero built to the approved concept, Find in the rail, finance hiding empty figures, Agenda in one column.

function ch_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/' . $rel);
}

function ch_fn(string $src, string $head): string
{
    $at = strpos($src, $head);
    assert_true($at !== false, "$head found");
    assert_true(preg_match('/\r?\n\}\r?\n/', $src, $m, PREG_OFFSET_CAPTURE, $at) === 1, "$head ends");
    return substr($src, $at, $m[0][1] - $at);
}

function ch_portrait(): string
{
    $css = ch_file('cockpit.css');
    return substr($css, (int) strpos($css, '/* Card 119'));
}

test('the hero holds the clock, the weather, a limits band of four tiles and a row with the Cas bar and Google search', function () {
    $js = ch_file('js/views.js');
    $fit = ch_fn($js, 'function renderFit(page) {');
    assert_true(str_contains($fit, "el('div', 'hero-clock')") && str_contains($fit, "el('span', 'hero-time'"), 'the clock');
    assert_true(str_contains($fit, 'add(hero, lead, heroWeather(), heroLimits(), asks);'), 'weather, limits and the asks row in the hero');
    assert_true(str_contains($fit, "asks.append(cmd)") && str_contains($fit, 'asks.append(searchForm())'), 'Cas bar then Google search in the row');
    $limits = ch_fn($js, 'function heroLimits() {');
    assert_true(str_contains($limits, "[['Claude', u?.at ? u : null], ['Codex', u?.codex]]"), 'two tools');
    assert_true(str_contains($limits, "[['5-hour', g?.five_hour], ['week', g?.seven_day]]"), 'two windows each, four tiles');
    $css = ch_portrait();
    assert_true(str_contains($css, '.fit-hero .limits{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr))'), 'four equal columns');
    assert_true(str_contains($css, '.hero-asks{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr)'), 'Cas bar 1.5fr, search 1fr');
    assert_true(str_contains($css, '.wx-hours{display:grid;grid-template-columns:repeat(5,minmax(0,1fr))'), 'five hourly cells that cannot wrap');
    assert_true(str_contains($css, 'grid-template-columns:minmax(0,3fr) minmax(0,2fr)'), 'the 3fr / 2fr hero grid');
});

test('the greeting has no need-you text', function () {
    $fit = ch_fn(ch_file('js/views.js'), 'function renderFit(page) {');
    assert_true(str_contains($fit, "el('h1', 'hero-greeting', `Good \${part} Rob`)"), 'greeting is the plain line');
    assert_true(!str_contains($fit, 'needsLine') && !str_contains($fit, 'need you') && !str_contains($fit, 'needs.length'), 'no needs text in the portrait hero');
});

test('an unknown limit renders No reading yet and no bar fill, an old reading shows its age', function () {
    $limits = ch_fn(ch_file('js/views.js'), 'function heroLimits() {');
    assert_true(str_contains($limits, "el('div', 'limit-val none', 'No reading yet')"), 'the words');
    assert_true(!str_contains($limits, "'--'"), 'no dashes');
    assert_true(preg_match('/if \(w\) \{\s*const fill = el\(\'i\'\);.*?bar\.append\(fill\);\s*\}/s', $limits) === 1, 'the fill exists only when there is a reading');
    assert_true(str_contains($limits, '> LIMIT_OLD_S') && str_contains(ch_file('js/views.js'), 'const LIMIT_OLD_S = 3600;'), 'age shown past one hour');
    assert_true(str_contains($limits, '`${ago(g.at)} ago`'), 'the age text');
    $css = ch_portrait();
    assert_true(str_contains($css, '.fit-hero .limit-val.none+.bar{height:0;') && str_contains($css, 'dashed'), 'a dashed line, no bar');
});

test('no voice-line chip is drawn outside the Cas bar and the voice button carries the off label', function () {
    $css = ch_portrait();
    assert_true(str_contains($css, '.fit-hero .command .voice-line,.fit-hero .command.active .voice-line{display:none}'), 'chip hidden in the hero');
    assert_true(!preg_match('/\.voice-line\{[^}]*position:absolute/', $css), 'no floating chip over the search edge');
    $main = ch_file('js/main.js');
    assert_true(str_contains($main, 'add(c, thread, main, v);'), 'the line belongs to the Cas bar');
    assert_true(str_contains($main, 'toggle.title = text;'), 'its message moves into the button title');
    assert_true(str_contains(ch_file('js/voice.js'), "off: 'Voice off'"), 'the button reads Voice off');
});

test('Find is not in the top bar, a rail button opens the finder and Ctrl K still does', function () {
    $main = ch_file('js/main.js');
    assert_true(str_contains($main, "add(top, el('div', 'mobile-mark', 'KC Cockpit'), micOpen, time);"), 'top bar without Find');
    assert_true(!preg_match('/add\(top,[^;]*findBox/', $main), 'findBox is not added to the top bar');
    assert_true(str_contains($main, "btn('', 'nav-btn find-btn', openFind)"), 'rail button');
    assert_true(str_contains($main, "'Find a project session or file (Ctrl K)'"), 'labelled');
    assert_true(strpos($main, 'find-btn') < strpos($main, 'const navs'), 'under the KC mark, before the other buttons');
    assert_true(preg_match("/e\.key\.toLowerCase\(\) === 'k'\) \{\s*e\.preventDefault\(\);\s*openFind\(\);/", $main) === 1, 'Ctrl K opens it');
    assert_true(str_contains($main, "const label = el('label', 'finder');"), 'the same fuzzy finder');
});

test('the finance strip renders no row whose value is missing', function () {
    $fin = ch_fn(ch_file('js/views.js'), 'function financeCard() {');
    assert_true(str_contains($fin, '.filter(([, v]) => v);'), 'empty values filtered out');
    assert_true(str_contains($fin, "const due = (x) => (x ? `\${x.subject} · \${x.due} · \${money(x.amount_cents)}` : '');"), 'a missing due is empty, not a dash');
    assert_true(str_contains($fin, "typeof v === 'number' ? money(v) : ''"), 'a missing amount is empty, not a dash');
    assert_true(str_contains($fin, 'if (figures.length) {'), 'no strip when no figure');
    assert_true(str_contains($fin, "...(x.date ? [el('small', '', x.date)] : [])"), 'alert rows keep their dates, no cell when null');
});

test('a finance line has no kind cell and no dash when its date is null', function () {
    $fin = ch_fn(ch_file('js/views.js'), 'function financeCard() {');
    assert_true(!str_contains($fin, 'x.kind'), 'no kind cell');
    assert_true(!str_contains($fin, "'–'"), 'no dash');
    assert_true(str_contains(ch_file('cockpit.css'), '.finance-line{display:grid;grid-template-columns:1fr auto;'), 'two columns');
});

test('desktop sizes: nothing in the 1080 x 1920 block is above 72px, the clock is 72px, card text 14px, bars 44px', function () {
    $css = ch_portrait();
    preg_match_all('/font(?:-size)?:\s*(?:\d+\s+)?(\d+)px/', $css, $m);
    assert_true(count($m[1]) > 10, 'sizes found');
    assert_true(max(array_map('intval', $m[1])) <= 72, 'no font-size above 72px');
    assert_true(str_contains($css, '.hero-clock{display:flex;align-items:flex-start;gap:10px;font:300 72px/'), 'clock 72px');
    assert_true(str_contains($css, '.fit-grid .card *{font-size:14px}'), 'grid card text 14px');
    assert_true(str_contains($css, '.fit-hero .google-form{height:44px;'), 'Google search 44px');
    assert_true(str_contains($css, 'gap:10px;height:44px;padding:0 8px 0 14px'), 'Cas bar 44px');
});

test('the Agenda card takes one column at 1080 x 1920 and every grid slot is filled', function () {
    $css = ch_portrait();
    assert_true(str_contains($css, '"agenda news" "follow finance" "care care"'), 'Agenda sits in one column, beside News');
    assert_true(!str_contains($css, '"agenda agenda"'), 'not full width');
    assert_true(!str_contains($css, 'grid-template-columns:minmax(0,340px)'), 'no side-by-side month and list');
    assert_true(preg_match('/grid-template-areas:([^}]*?)\}/s', $css, $m) === 1, 'areas found');
    preg_match_all('/"([^"]+)"/', $m[1], $rows);
    $cells = [];
    foreach ($rows[1] as $row) {
        foreach (preg_split('/\s+/', trim($row)) as $name) {
            $cells[] = $name;
        }
    }
    $names = array_unique($cells);
    sort($names);
    assert_same(['agenda', 'care', 'email', 'finance', 'follow', 'needs', 'news', 'talk', 'working'], $names, 'nine cards, no empty slot');
    assert_same(10, count($cells), 'ten cells: only Care repeats');
});
