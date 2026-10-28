<?php
// Gol POS x MR.STORE — month simulation runner (QA store ONLY).
//
// Drives the SAME code paths the web register uses — golpos_append_sale()
// for the till record and mrstore_record_sale() / mrstore_adjust_stock() /
// mrstore_set_stock() for MR.STORE stock — in a loop, to prove the bridge
// carries a full month of live-POS-shaped traffic: 100 transactions a day
// for 30 days, with believable baskets, receives, and a day close per day.
//
// Every simulated sale is marked 'source' => 'sim-month' in the POS log —
// simulation is labelled, never disguised as organic data.
//
// Usage (on the server, from the deployed sim tree):
//   php scripts/sim-month.php seed
//   php scripts/sim-month.php run 2026-09-29 2026-10-27 100
//   php scripts/sim-month.php run 2026-10-28 2026-10-28 95   (last day leaves
//        room for spot-check sales rung through the public HTTPS path)
//   php scripts/sim-month.php report

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../server/store.php';

if (!golpos_link_on()) { fwrite(STDERR, "link is OFF — refusing to run\n"); exit(2); }
if (!mrstore_write_allowed()) { fwrite(STDERR, "store is not the QA store — refusing to run\n"); exit(2); }

$mode = $argv[1] ?? '';
$tz = new DateTimeZone('America/New_York');

function sim_log(array $entry): void {
    $path = golpos_data_dir() . '/sim-month-log.jsonl';
    $entry['logged_at'] = date('c');
    file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// Basket weights: a c-store shape — drinks heavy, beer and snacks strong,
// cigars a long tail, household thin. Keyed by barcode, then name words.
function sim_weight(array $p): int {
    $byBc = ['049000050110' => 100, '049000000000' => 85, '012000008103' => 70,
             '018200001476' => 75, '071279001234' => 55, '037000123456' => 18];
    $bc = (string)($p['barcode'] ?? '');
    if (isset($byBc[$bc])) { return $byBc[$bc]; }
    $nm = strtolower((string)($p['name'] ?? ''));
    if (strpos($nm, 'coke 20oz') !== false) { return 90; }
    if (strpos($nm, 'lays') !== false) { return 65; }
    if (strpos($nm, 'marlboro gold') !== false) { return 40; }
    if (strpos($nm, 'marlboro red') !== false) { return 38; }
    if (strpos($nm, 'mystery') !== false) { return 6; }
    return 15;
}

function sim_pick(array $pool, array $weights, int $totalW): int {
    $r = mt_rand(1, $totalW);
    foreach ($pool as $i) {
        $r -= $weights[$i];
        if ($r <= 0) { return $i; }
    }
    return $pool[count($pool) - 1];
}

function sim_opening(array $p): int {
    $w = sim_weight($p);
    return max(60, (int)round($w * 13));
}

if ($mode === 'seed') {
    $cat = mrstore_catalog_with_stock();
    if ($cat === null) { fwrite(STDERR, "catalog unavailable\n"); exit(1); }
    foreach ($cat['products'] as $p) {
        $target = sim_opening($p);
        $res = mrstore_set_stock((string)$p['name'], (float)$target);
        if ($res === null) { fwrite(STDERR, "seed failed for {$p['name']}\n"); exit(1); }
        sim_log(['kind' => 'seed_set', 'name' => $res['name'], 'before' => $res['before'], 'after' => $res['after']]);
        golpos_link_log(['kind' => 'set', 'name' => $res['name'], 'before' => $res['before'], 'after' => $res['after'], 'via' => 'sim-seed']);
        echo "seed {$res['name']}: {$res['before']} -> {$res['after']}\n";
    }
    echo "seed done\n";
    exit(0);
}

if ($mode === 'run') {
    $from = $argv[2] ?? '';
    $to = $argv[3] ?? '';
    $perDay = (int)($argv[4] ?? 100);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $perDay < 1) {
        fwrite(STDERR, "usage: run YYYY-MM-DD YYYY-MM-DD N\n");
        exit(2);
    }
    // Refuse to double-run a day that already carries sim sales.
    $existing = [];
    foreach (golpos_sales() as $s) {
        if (($s['source'] ?? '') === 'sim-month') { $existing[substr((string)$s['at'], 0, 10)] = true; }
    }
    $cat = golpos_catalog();
    $products = $cat['products'];
    $taxRate = (float)($cat['store']['tax_rate'] ?? 0.08);
    $weights = [];
    $totalW = 0;
    foreach ($products as $i => $p) { $weights[$i] = sim_weight($p); $totalW += $weights[$i]; }
    $pool = array_keys($products);

    // In-memory stock mirror for the negative guard; refreshed each day
    // from MR.STORE itself so the guard reads the same truth.
    $mirror = [];
    $refresh = function () use (&$mirror) {
        $fresh = mrstore_catalog_with_stock();
        $mirror = [];
        if ($fresh) {
            foreach ($fresh['products'] as $p) { $mirror[(string)$p['name']] = $p['onHand']; }
        }
    };
    $refresh();

    $receive = function (string $name, float $qty, string $why) use (&$mirror) {
        $res = mrstore_adjust_stock($name, $qty);
        if ($res !== null) {
            $mirror[$name] = $res['after'];
            sim_log(['kind' => 'receive', 'name' => $name, 'qty' => $qty, 'why' => $why,
                     'before' => $res['before'], 'after' => $res['after']]);
            golpos_link_log(['kind' => 'receive', 'name' => $name, 'qty' => $qty,
                             'before' => $res['before'], 'after' => $res['after'], 'via' => 'sim-' . $why]);
            echo "  receive {$name} +{$qty} ({$why}) -> {$res['after']}\n";
        }
        return $res;
    };

    $day = new DateTime($from, $tz);
    $last = new DateTime($to, $tz);
    while ($day <= $last) {
        $ymd = $day->format('Y-m-d');
        if (isset($existing[$ymd])) { fwrite(STDERR, "day {$ymd} already has sim sales — skipping refused, aborting\n"); exit(1); }
        mt_srand((int)$day->format('Ymd'));
        $started = microtime(true);
        // Scheduled delivery: Mondays and Thursdays, main SKUs restock.
        $dow = (int)$day->format('N'); // 1 Mon .. 7 Sun
        if ($dow === 1 || $dow === 4) {
            foreach ($products as $p) {
                if (sim_weight($p) >= 50) {
                    $receive((string)$p['name'], (float)max(24, (int)round(sim_opening($p) * 0.18)), 'scheduled');
                }
            }
        }
        // Transaction timestamps spread across store hours 07:00–22:59.
        $stamps = [];
        for ($i = 0; $i < $perDay; $i++) { $stamps[] = mt_rand(7 * 60, 22 * 60 + 59); }
        sort($stamps);
        $dayGross = 0.0;
        foreach ($stamps as $mins) {
            $at = DateTime::createFromFormat('Y-m-d H:i', $ymd . ' ' . sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60), $tz);
            $atIso = $at->format('c');
            $nLines = sim_pick([1, 2, 3, 4, 5], [1 => 35, 2 => 30, 3 => 20, 4 => 10, 5 => 5], 100);
            $chosen = [];
            $guard = 0;
            while (count($chosen) < $nLines && $guard++ < 40) {
                $idx = sim_pick($pool, $weights, $totalW);
                if (!in_array($idx, $chosen, true)) { $chosen[] = $idx; }
            }
            $lines = [];
            $subtotal = 0.0;
            foreach ($chosen as $idx) {
                $p = $products[$idx];
                $qty = sim_pick([1, 2, 3], [1 => 60, 2 => 30, 3 => 10], 100);
                // Negative guard: a delivery lands before the sale that
                // would take the shelf below zero — and is logged.
                $have = $mirror[(string)$p['name']] ?? null;
                if ($have !== null && $have - $qty < 0) {
                    $lot = sim_weight($p) >= 70 ? 48.0 : 24.0;
                    $receive((string)$p['name'], $lot, 'guard');
                }
                $lineTotal = round((float)$p['price'] * $qty, 2);
                $subtotal += $lineTotal;
                $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'mr_name' => $p['mr_name'] ?? $p['name'],
                            'price' => $p['price'], 'qty' => $qty, 'line_total' => $lineTotal];
                if (($mirror[(string)$p['name']] ?? null) !== null) { $mirror[(string)$p['name']] -= $qty; }
            }
            $tax = round($subtotal * $taxRate, 2);
            $total = round($subtotal + $tax, 2);
            $tender = mt_rand(1, 100) <= 55 ? 'card' : 'cash';
            $rec = ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
                    'total' => $total, 'tender' => $tender, 'source' => 'sim-month'];
            if ($tender === 'cash') { $rec['tendered'] = $total; $rec['change_due'] = 0.0; }
            $saved = golpos_append_sale($rec, $atIso);
            $notes = mrstore_record_sale($lines);
            mrstore_append_ticket($saved, (string)($cat['store']['name'] ?? ''), $ymd, $atIso);
            golpos_link_log(['kind' => 'sale', 'txn' => $saved['id'], 'total' => $total,
                             'tender' => $tender, 'stock' => $notes, 'via' => 'sim-month', 'at_sim' => $atIso]);
            $dayGross += $total;
        }
        $close = golpos_close_day($ymd);
        sim_log(['kind' => 'day', 'date' => $ymd, 'txns' => $perDay, 'gross' => round($dayGross, 2),
                 'close_gross' => $close['gross'], 'close_txns' => $close['txns']]);
        echo "{$ymd}: {$perDay} txns, gross " . number_format($dayGross, 2) .
             ", close record gross {$close['gross']} txns {$close['txns']}, " .
             round(microtime(true) - $started, 1) . "s\n";
        $refresh();
        $day->modify('+1 day');
    }
    echo "run done\n";
    exit(0);
}

if ($mode === 'report') {
    $sales = golpos_sales();
    $byDay = [];
    $soldQty = [];
    foreach ($sales as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        $byDay[$d] = $byDay[$d] ?? ['txns' => 0, 'gross' => 0.0, 'sim_txns' => 0];
        $byDay[$d]['txns']++;
        $byDay[$d]['gross'] += (float)($s['total'] ?? 0);
        if (($s['source'] ?? '') === 'sim-month') { $byDay[$d]['sim_txns']++; }
        foreach (($s['lines'] ?? []) as $l) {
            $n = (string)($l['name'] ?? '');
            $soldQty[$n] = ($soldQty[$n] ?? 0) + (float)($l['qty'] ?? 0);
        }
    }
    ksort($byDay);
    foreach ($byDay as $d => $v) {
        echo "{$d}: txns {$v['txns']} (sim {$v['sim_txns']}), gross " . number_format($v['gross'], 2) . "\n";
    }
    $cat = mrstore_catalog_with_stock();
    echo "\nper-SKU: sold (all POS-log sales in window) vs MR.STORE onHand now\n";
    if ($cat) {
        foreach ($cat['products'] as $p) {
            $n = (string)$p['name'];
            echo "  {$n}: sold " . ($soldQty[$n] ?? 0) . ", onHand " . var_export($p['onHand'], true) . "\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "usage: sim-month.php seed|run FROM TO N|report\n");
exit(2);
