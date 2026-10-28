<?php
// Gol POS — demo history seed (STANDALONE demo stage only).
//
// The filming/demo register must carry a believable sales body, not an
// empty log: this seeds roughly a month of past days ending yesterday —
// baskets built from THIS tree's own catalog (names, SKUs, prices, tax
// rules exactly as the register computes them), mixed cash/card tenders
// with tendered/change on cash, each day's card segment sealed as one
// batch and each day closed, in the same record shapes the live paths
// write (golpos_append_sale / golpos_seal_batch / golpos_close_day).
// Today is never touched: it stays open at $0 for filming and testing,
// and the catalog's on-hand counts are left exactly as they stand — the
// seed writes the sales log directly, like a month that already happened.
//
// Every seeded sale carries 'source' => 'demo-seed': simulation is
// labelled, never disguised as organic data.
//
// Usage (CLI, from the deployed demo tree):
//   php scripts/seed-demo-history.php [days]
// Refuses to run in link mode, and refuses when the sales log already
// holds sales (a re-seed is a deliberate act: clear the log first).

declare(strict_types=1);

// Shell-only: a real web request always carries REQUEST_METHOD and is
// refused. (The host's shell php is php-cgi — SAPI cgi-fcgi with no
// request — so a SAPI test cannot tell the two apart; this one can.)
if (isset($_SERVER['REQUEST_METHOD'])) { fwrite(STDERR, "Shell only\n"); exit(2); }
require __DIR__ . '/../server/store.php';

if (golpos_link_on()) { fwrite(STDERR, "link mode is ON — refusing to seed a linked stage\n"); exit(2); }
if (golpos_sales() !== []) { fwrite(STDERR, "sales log is not empty — refusing to seed over existing sales\n"); exit(2); }

$tz = new DateTimeZone('America/New_York');
$daysWanted = max(7, min(62, (int)($argv[1] ?? 29)));
$end = new DateTime('yesterday', $tz);
$start = (clone $end)->modify('-' . ($daysWanted - 1) . ' days');

$catalog = golpos_catalog();
$taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);
$surchargePct = (float)(golpos_settings()['card_surcharge_pct'] ?? 0);

// Basket weights: a c-store shape — drinks heavy, snacks and beer strong,
// grocery/household/health a long tail. SKU 1061 (Hot Dog) sits at 0 on
// the shelf and stays out of the baskets.
$weights = [];
foreach ($catalog['products'] as $p) {
    $sku = (string)$p['sku'];
    if ($sku === '1061') { continue; }
    $cat = (string)($p['category'] ?? '');
    $w = 12;
    if ($cat === 'Drinks') { $w = 60; }
    elseif ($cat === 'Snacks') { $w = 42; }
    elseif ($cat === 'Beer and Wine') { $w = 30; }
    elseif ($cat === 'Counter') { $w = 26; }
    elseif ($cat === 'Grocery') { $w = 14; }
    $weights[$sku] = $w;
}
$pool = [];
foreach ($catalog['products'] as $p) {
    $sku = (string)$p['sku'];
    if (isset($weights[$sku])) { $pool[$sku] = $p; }
}
$totalW = array_sum($weights);
$pickSku = static function () use ($weights, $totalW): string {
    $r = mt_rand(1, $totalW);
    foreach ($weights as $sku => $w) { $r -= $w; if ($r <= 0) { return (string)$sku; } }
    return (string)array_key_last($weights);
};
$hourPick = static function (): int {
    // Morning, lunch and evening humps across a 7 AM – 9:40 PM day.
    $bag = [7 => 3, 8 => 4, 9 => 3, 10 => 3, 11 => 4, 12 => 5, 13 => 4, 14 => 3,
            15 => 3, 16 => 3, 17 => 5, 18 => 5, 19 => 4, 20 => 3, 21 => 1];
    $sum = array_sum($bag); $r = mt_rand(1, $sum);
    foreach ($bag as $h => $w) { $r -= $w; if ($r <= 0) { return $h; } }
    return 12;
};

mt_srand(20261028);
$grandSales = 0; $grandGross = 0.0; $batchCount = 0;
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $ymd = $d->format('Y-m-d');
    $dow = (int)$d->format('w'); // 0 Sun .. 6 Sat
    $count = match (true) {
        $dow === 5 || $dow === 6 => mt_rand(38, 52),   // Fri / Sat rush
        $dow === 0 => mt_rand(16, 24),                 // quiet Sunday
        $dow === 1 => mt_rand(18, 27),                 // Monday
        default => mt_rand(24, 36),                    // Tue–Thu
    };
    $stamps = [];
    for ($i = 0; $i < $count; $i++) {
        $stamps[] = sprintf('%02d:%02d:%02d', $hourPick(), mt_rand(0, 59), mt_rand(0, 59));
    }
    sort($stamps);
    foreach ($stamps as $ts) {
        $at = new DateTime($ymd . ' ' . $ts, $tz);
        $nLines = mt_rand(1, 100) <= 35 ? 1 : (mt_rand(1, 100) <= 46 ? 2 : (mt_rand(1, 100) <= 62 ? 3 : (mt_rand(1, 100) <= 80 ? 4 : 5)));
        $lines = []; $used = []; $subtotal = 0.0; $taxable = 0.0;
        for ($j = 0; $j < $nLines; $j++) {
            $sku = $pickSku();
            if (isset($used[$sku])) { continue; }
            $used[$sku] = true;
            $p = $pool[$sku];
            $qty = mt_rand(1, 100) <= 62 ? 1 : (mt_rand(1, 100) <= 75 ? 2 : 3);
            $lt = round((float)$p['price'] * $qty, 2);
            $subtotal += $lt;
            if (($p['taxable'] ?? true) !== false) { $taxable += $lt; }
            $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'],
                        'qty' => $qty, 'line_total' => $lt];
        }
        if (!$lines) { continue; }
        // The register's own math (api.php sale branch): per-line tax on
        // the taxable subtotal; the card surcharge from the saved setting
        // (0 on the demo stage) folded into card totals only.
        $tax = round($taxable * $taxRate, 2);
        $tender = mt_rand(1, 100) <= 45 ? 'card' : 'cash';
        $surcharge = 0.0;
        if ($tender === 'card' && $surchargePct > 0) {
            $surcharge = round((round($subtotal, 2) + $tax) * $surchargePct / 100, 2);
        }
        $total = round($subtotal + $tax + $surcharge, 2);
        $rec = ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
                'total' => $total, 'tender' => $tender, 'source' => 'demo-seed'];
        if ($surcharge > 0) { $rec['surcharge'] = $surcharge; $rec['surcharge_pct'] = $surchargePct; }
        if ($tender === 'cash') {
            $roll = mt_rand(1, 100);
            $unit = $roll <= 20 ? $total : ($roll <= 50 ? 1 : ($roll <= 75 ? 5 : ($roll <= 90 ? 10 : 20)));
            $tendered = $unit === $total ? $total : ceil($total / $unit) * $unit;
            $rec['tendered'] = round($tendered, 2);
            $rec['change_due'] = round($tendered - $total, 2);
        }
        golpos_append_sale($rec, $at->format('Y-m-d\TH:i:sP'));
        $grandSales++; $grandGross += $total;
    }
    // Seal the day's card segment as ONE batch — golpos_seal_batch's own
    // record shape, stamped with the day itself (the live function stamps
    // "today"; a seeded month carries its own dates).
    $seg = golpos_unbatched_card_sales($ymd);
    if ($seg) {
        $batches = golpos_batches();
        $btotal = 0.0; foreach ($seg as $s) { $btotal += (float)($s['total'] ?? 0); }
        $sealAt = new DateTime($ymd . ' 21:0' . mt_rand(0, 9) . ':00', $tz);
        $batches[] = [
            'n' => count($batches) + 1,
            'date' => $ymd,
            'sealed_at' => $sealAt->format('Y-m-d\TH:i:sP'),
            'closed_by' => 'manual',
            'close_time' => null,
            'count' => count($seg),
            'total' => round($btotal, 2),
            'from' => (string)($seg[0]['at'] ?? ''),
            'to' => (string)($seg[count($seg) - 1]['at'] ?? ''),
            'sale_ids' => array_map(static fn($s) => (string)($s['id'] ?? ''), $seg),
        ];
        golpos_write('batches.json', $batches);
        $batchCount++;
    }
    // Close the day — golpos_close_day's composition (summary + the day's
    // batches + what is still unbatched), stamped that evening.
    $rec = golpos_day_summary($ymd);
    $closeAt = new DateTime($ymd . ' 21:1' . mt_rand(0, 9) . ':00', $tz);
    $rec['closed_at'] = $closeAt->format('Y-m-d\TH:i:sP');
    $rec['batches'] = array_map(static fn($b) => [
        'n' => $b['n'], 'count' => $b['count'], 'total' => $b['total'],
        'closed_by' => $b['closed_by'], 'close_time' => $b['close_time'] ?? null,
    ], golpos_batches_on($ymd));
    $unb = golpos_unbatched_card_sales($ymd);
    $ut = 0.0; foreach ($unb as $s) { $ut += (float)($s['total'] ?? 0); }
    $rec['unbatched_card'] = ['count' => count($unb), 'total' => round($ut, 2)];
    $closes = golpos_read('closes.json', []);
    $out = [];
    foreach ($closes as $c) { if (($c['date'] ?? '') !== $ymd) { $out[] = $c; } }
    $out[] = $rec;
    golpos_write('closes.json', $out);
    echo $ymd . '  sales=' . $count . '  gross-so-far=' . number_format($grandGross, 2) . "\n";
}
echo "SEEDED {$grandSales} sales over {$daysWanted} days ({$start->format('Y-m-d')} .. {$end->format('Y-m-d')}), "
   . "gross $" . number_format($grandGross, 2) . ", {$batchCount} batches, closes written per day.\n";
