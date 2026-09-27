<?php
declare(strict_types=1);
require __DIR__ . '/../lib/settlement.php';

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "OK   " : "FAIL ") . $name . "\n";
    if (!$ok) $fails++;
}

// 1) Ungleiche Nächte
$p = [
    ['id' => 1, 'name' => 'Anna', 'nights' => 7],
    ['id' => 2, 'name' => 'Ben', 'nights' => 7],
    ['id' => 3, 'name' => 'Carla', 'nights' => 3],
    ['id' => 4, 'name' => 'Dirk', 'nights' => 0],
];
$e = [
    ['participant_id' => 1, 'amount_cents' => 120000],
    ['participant_id' => 2, 'amount_cents' => 25050],
    ['participant_id' => 3, 'amount_cents' => 4000],
];
$r = settle($p, $e);
check('Gesamtkosten', $r['total_cents'] === 149050);
check('Gesamtnächte', $r['total_nights'] === 17);
check('Summe Anteile = Gesamt', array_sum(array_column($r['people'], 'share_cents')) === 149050);
check('Summe Salden = 0', array_sum(array_column($r['people'], 'balance_cents')) === 0);
check('Dirk Anteil 0', $r['people'][3]['share_cents'] === 0);
$bal = array_column($r['people'], 'balance_cents', 'id');
foreach ($r['transfers'] as $t) {
    $bal[$t['from']] += $t['amount_cents'];
    $bal[$t['to']] -= $t['amount_cents'];
}
check('Überweisungen gleichen aus', count(array_filter($bal)) === 0);
check('Max n-1 Überweisungen', count($r['transfers']) <= 3);

// 2) Rundung: 100 Cent auf 3 gleiche Personen
$r = settle(
    [['id' => 1, 'name' => 'A', 'nights' => 1], ['id' => 2, 'name' => 'B', 'nights' => 1], ['id' => 3, 'name' => 'C', 'nights' => 1]],
    [['participant_id' => 1, 'amount_cents' => 100]]
);
check('Rundung 34/33/33', array_column($r['people'], 'share_cents') === [34, 33, 33]);
check('Transfers B,C -> A', $r['transfers'] === [
    ['from' => 2, 'to' => 1, 'amount_cents' => 33],
    ['from' => 3, 'to' => 1, 'amount_cents' => 33],
]);

// 3) Keine Nächte
$r = settle([['id' => 1, 'name' => 'A', 'nights' => 0]], [['participant_id' => 1, 'amount_cents' => 500]]);
check('0 Nächte: nicht berechenbar', $r['computable'] === false && $r['transfers'] === []);

// 4) Leere Reise
$r = settle([['id' => 1, 'name' => 'A', 'nights' => 0]], []);
check('Leere Reise ok', $r['computable'] === true && $r['total_cents'] === 0);

exit($fails ? 1 : 0);
