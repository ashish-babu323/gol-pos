<?php
// Gol POS — demo November backfill (STANDALONE demo stage ONLY).
//
// The six-month demo history ends at the Oct 31 close, which left Nov 1
// with only its 28 real filming-day sales and Nov 2 with nothing — so
// "yesterday's total" landed on an empty day during the demo shoots.
// This fills Nov 1 (top-up over the real sales already there) and Nov 2
// (a full fixture day) in the October band, in the exact record shapes
// the live paths write: sales via the golpos_append_sale record shape,
// one sealed card batch per day (golpos_seal_batch's shape, as
// seed-demo-history.php composes it), and a close composed from
// golpos_day_summary + batch state, like golpos_close_day produces.
//
// Every generated sale carries 'source' => 'demo-ringer-seed': fixture
// data, labelled, never disguised as organic. Stock is NOT decremented
// (demo-history law: the seed writes the sales log directly, like a
// month that already happened — the live ringer is what moves stock).
//
// Usage (CLI, from the deployed demo tree):
//   php scripts/demo-backfill-nov.php
// Refuses in link mode, refuses if either date is already closed, and
// refuses if Nov 2 already carries sales (a re-run is a deliberate act).

declare(strict_types=1);

if (isset($_SERVER['REQUEST_METHOD'])) { fwrite(STDERR, "Shell only\n"); exit(2); }
require __DIR__ . '/../server/store.php';

if (golpos_link_on()) { fwrite(STDERR, "link mode is ON — refusing to backfill a linked stage\n"); exit(2); }

$tz = new DateTimeZone('America/New_York');
$plan = [
    // Sunday — top-up over the day's real $274.78; Monday — full fixture day.
    // Counts sit in the October txn band (172–216/day); gross targets are
    // the October daily band ($2,240–$2,760).
    '2026-11-01' => ['target' => 2450.0, 'min_new' => 160, 'max_new' => 215],
    '2026-11-02' => ['target' => 2550.0, 'min_new' => 185, 'max_new' => 225],
];
foreach ($plan as $ymd => $_) {
    if (golpos_close_record($ymd) !== null) { fwrite(STDERR, "{$ymd} already closed — refusing\n"); exit(1); }
}
$nov2 = golpos_day_summary('2026-11-02');
if ($nov2['txns'] > 0) { fwrite(STDERR, "2026-11-02 already has {$nov2['txns']} sales — refusing\n"); exit(1); }

$catalog = golpos_catalog();
$taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);

// Basket weights: the demo-history c-store shape (drinks heavy, snacks
// and beer strong, long tail). SKU 1061 (Hot Dog) sits at 0 on the shelf
// and stays out of the baskets, as in seed-demo-history.php.
$weights = [];
foreach ($catalog['products'] as $p) {
    $sku = (string)$p['sku'];
    if ($sku === '1061') { continue; }
    // Basket pool is sellable c-store stock only: the live catalog also
    // carries a $1,000 "purchase 1000 newport 100s" test artifact that no
    // real basket contains — one draw of it wrecked a day's shape.
    if ((float)($p['price'] ?? 0) > 40.0) { continue; }
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
    $bag = [7 => 3, 8 => 4, 9 => 3, 10 => 3, 11 => 4, 12 => 5, 13 => 4, 14 => 3,
            15 => 3, 16 => 3, 17 => 5, 18 => 5, 19 => 4, 20 => 3];
    $sum = array_sum($bag); $r = mt_rand(1, $sum);
    foreach ($bag as $h => $w) { $r -= $w; if ($r <= 0) { return $h; } }
    return 12;
};

// One basket, register math. Returns [record, total].
$buildBasket = static function () use ($pool, $pickSku, $taxRate): array {
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
    $tax = round($taxable * $taxRate, 2);
    // October closes split ~58 cash / ~42 card.
    $tender = mt_rand(1, 100) <= 42 ? 'card' : 'cash';
    $total = round($subtotal + $tax, 2);
    $rec = ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => $total, 'tender' => $tender, 'source' => 'demo-ringer-seed'];
    if ($tender === 'cash') {
        $roll = mt_rand(1, 100);
        $unit = $roll <= 20 ? $total : ($roll <= 50 ? 1 : ($roll <= 75 ? 5 : ($roll <= 90 ? 10 : 20)));
        $tendered = $unit === $total ? $total : ceil($total / $unit) * $unit;
        $rec['tendered'] = round($tendered, 2);
        $rec['change_due'] = round($tendered - $total, 2);
    }
    return [$rec, $total];
};

$sales = golpos_sales();
$generated = [];
foreach ($plan as $ymd => $cfg) {
    mt_srand((int)str_replace('-', '', $ymd));
    $gross = (float)golpos_day_summary($ymd)['gross'];
    $made = 0;
    // Count-driven, gross-governed: October days carry 172–216 sales.
    // A basket over $75 is resampled (real c-store baskets are small);
    // without the cap a heavy right tail ends the day in a dozen sales.
    while ($made < $cfg['max_new']) {
        if ($made >= $cfg['min_new'] && $gross >= $cfg['target']) { break; }
        [$rec, $total] = $buildBasket();
        $tries = 0;
        while ($total > 75.0 && $tries++ < 3) { [$rec, $total] = $buildBasket(); }
        if (!$rec['lines']) { continue; }
        $at = new DateTime(sprintf('%s %02d:%02d:%02d', $ymd, $hourPick(), mt_rand(0, 59), mt_rand(0, 59)), $tz);
        $rec['id'] = 'txn_' . substr(sha1(uniqid('', true)), 0, 10);
        $rec['at'] = $at->format('Y-m-d\TH:i:sP');
        $generated[] = $rec;
        $gross += $total;
        $made++;
    }
    echo "{$ymd}: generated {$made} fixture sales, day gross now " . number_format($gross, 2) . " (target {$cfg['target']})\n";
}
// ONE write of the sales log for both days (the live log is ~20 MB; a
// per-sale append loop would rewrite it hundreds of times).
foreach ($generated as $rec) { $sales[] = $rec; }
golpos_write('sales.json', $sales);

// Seal each day's card segment as ONE batch, then compose the close —
// the seed-demo-history composition, in golpos_close_day's shape.
foreach ($plan as $ymd => $_) {
    $seg = golpos_unbatched_card_sales($ymd);
    if ($seg) {
        $batches = golpos_batches();
        $maxN = 0; foreach ($batches as $b) { $maxN = max($maxN, (int)($b['n'] ?? 0)); }
        $btotal = 0.0; foreach ($seg as $s) { $btotal += (float)($s['total'] ?? 0); }
        $sealAt = new DateTime($ymd . ' 21:0' . mt_rand(0, 9) . ':00', $tz);
        $batches[] = [
            'n' => max(count($batches) + 1, $maxN + 1),
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
    }
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
    usort($out, static fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
    golpos_write('closes.json', $out);
    echo "{$ymd}: CLOSE gross {$rec['gross']} cash {$rec['cash']} card {$rec['card']} txns {$rec['txns']}\n";
}
echo "BACKFILL DONE\n";
