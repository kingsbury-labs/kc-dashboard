<?php
require_once __DIR__ . '/../src/Attention/Selector.php';

function sel_fact(string $id, string $severity, ?string $due, string $state = 'new'): array
{
    return ['id' => $id, 'severity' => $severity, 'due_date' => $due, 'state' => $state];
}

test('seven facts give five shown and two in also seen, none dropped', function () {
    $facts = [];
    for ($i = 1; $i <= 7; $i++) {
        $facts[] = sel_fact("f$i", 'med', sprintf('2026-11-%02d', $i));
    }
    $out = Selector::select(array_reverse($facts));
    assert_same(['f1', 'f2', 'f3', 'f4', 'f5'], array_column($out['shown'], 'id'));
    assert_same(['f6', 'f7'], array_column($out['also'], 'id'));
});

test('high beats med beats low, then the sooner due date, with no date last', function () {
    $out = Selector::select([
        sel_fact('low-soon', 'low', '2026-10-02'),
        sel_fact('med-late', 'med', '2026-12-01'),
        sel_fact('high-none', 'high', null),
        sel_fact('med-soon', 'med', '2026-10-05'),
        sel_fact('high-late', 'high', '2026-11-30'),
        sel_fact('med-none', 'med', null),
    ]);
    assert_same(['high-late', 'high-none', 'med-soon', 'med-late', 'med-none'], array_column($out['shown'], 'id'));
    assert_same(['low-soon'], array_column($out['also'], 'id'));
});

test('rank breaks a tie of severity and due date, ranked before unranked', function () {
    $f = fn(string $id, ?int $rank) => sel_fact($id, 'med', '2026-11-01') + ['rank' => $rank];
    $out = Selector::select([$f('a', null), $f('b', 2), $f('c', 1), $f('d', null)]);
    assert_same(['c', 'b', 'a', 'd'], array_column($out['shown'], 'id'));
});

test('a reply that ranks a low fact first and leaves out a high one cannot push the high one out', function () {
    $facts = [sel_fact('high', 'high', '2026-12-31')];
    for ($i = 1; $i <= 6; $i++) {
        $facts[] = sel_fact("low$i", 'low', '2026-10-02') + ['rank' => $i];
    }
    $facts[] = sel_fact('med-late', 'med', '2026-12-31') + ['rank' => 99];
    $out = Selector::select($facts);
    assert_same(['high', 'med-late', 'low1', 'low2', 'low3'], array_column($out['shown'], 'id'));
    assert_same(['low4', 'low5', 'low6'], array_column($out['also'], 'id'));
});

test('a held fact goes to the fold even with room at the top', function () {
    $out = Selector::select([sel_fact('held', 'high', '2026-10-02', 'held'), sel_fact('other', 'low', null)]);
    assert_same(['other'], array_column($out['shown'], 'id'));
    assert_same(['held'], array_column($out['also'], 'id'));
});
