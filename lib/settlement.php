<?php
declare(strict_types=1);

/**
 * Berechnet die Abrechnung einer Reise.
 *
 * @param array $participants Liste von ['id' => int, 'name' => string, 'nights' => int]
 * @param array $expenses     Liste von ['participant_id' => int, 'amount_cents' => int]
 */
function settle(array $participants, array $expenses): array
{
    $total = 0;
    $paid = [];
    foreach ($participants as $p) {
        $paid[(int)$p['id']] = 0;
    }
    foreach ($expenses as $e) {
        $pid = (int)$e['participant_id'];
        $amount = (int)$e['amount_cents'];
        $total += $amount;
        if (isset($paid[$pid])) {
            $paid[$pid] += $amount;
        }
    }

    $totalNights = 0;
    foreach ($participants as $p) {
        $totalNights += max(0, (int)$p['nights']);
    }

    // Anteile nach Übernachtungen, Largest-Remainder-Rundung auf Cent.
    $shares = [];
    $computable = $totalNights > 0;
    if ($computable) {
        $remainders = [];
        $assigned = 0;
        foreach ($participants as $p) {
            $id = (int)$p['id'];
            $raw = $total * max(0, (int)$p['nights']);
            $shares[$id] = intdiv($raw, $totalNights);
            $remainders[$id] = $raw % $totalNights;
            $assigned += $shares[$id];
        }
        $left = $total - $assigned;
        uksort($remainders, fn($a, $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_keys($remainders) as $id) {
            if ($left <= 0) break;
            $shares[$id]++;
            $left--;
        }
    }

    $people = [];
    $balances = [];
    foreach ($participants as $p) {
        $id = (int)$p['id'];
        $share = $computable ? $shares[$id] : null;
        $balance = $computable ? $paid[$id] - $share : null;
        $people[] = [
            'id' => $id,
            'name' => $p['name'],
            'nights' => (int)$p['nights'],
            'paid_cents' => $paid[$id],
            'share_cents' => $share,
            'balance_cents' => $balance,
        ];
        if ($computable) $balances[$id] = $balance;
    }

    return [
        'total_cents' => $total,
        'total_nights' => $totalNights,
        'cost_per_night_cents' => $computable ? $total / $totalNights : null,
        'computable' => $computable || $total === 0,
        'people' => $people,
        'transfers' => $computable ? transfers($balances) : [],
    ];
}

/**
 * Greedy-Ausgleich: größter Schuldner zahlt an größten Gläubiger.
 * @param array<int,int> $balances participant_id => Saldo in Cent (Summe 0)
 */
function transfers(array $balances): array
{
    $debtors = [];
    $creditors = [];
    foreach ($balances as $id => $b) {
        if ($b < 0) $debtors[] = [$id, -$b];
        elseif ($b > 0) $creditors[] = [$id, $b];
    }
    $sort = fn($x, $y) => [$y[1], $x[0]] <=> [$x[1], $y[0]];
    usort($debtors, $sort);
    usort($creditors, $sort);

    $result = [];
    $i = $j = 0;
    while ($i < count($debtors) && $j < count($creditors)) {
        $amount = min($debtors[$i][1], $creditors[$j][1]);
        $result[] = ['from' => $debtors[$i][0], 'to' => $creditors[$j][0], 'amount_cents' => $amount];
        $debtors[$i][1] -= $amount;
        $creditors[$j][1] -= $amount;
        if ($debtors[$i][1] === 0) $i++;
        if ($creditors[$j][1] === 0) $j++;
    }
    return $result;
}
