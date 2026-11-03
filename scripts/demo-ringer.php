<?php
// Gol POS — demo daily-sales ringer (STANDALONE demo stage ONLY).
//
// Owner order, Nov 3 2026: "data bhara, daily sales dummy engine nai
// chalau, 5-10 min ma euta ring gara" — keep the demo register ALIVE:
// one plausible sale every 5–10 minutes so "today's sales so far" moves
// like a real day during the demo shoots and Nov 4 judging, and each
// demo day rolls into a proper close at night so "yesterday" always
// has a real rung day behind it.
//
// A ring goes through the register's OWN standalone sale path — the
// same two calls api.php makes for a web sale: golpos_append_sale()
// then golpos_decrement_stock() on the live catalog. Baskets are drawn
// from catalog.live.json, weighted like a convenience store (drinks and
// snacks heavy), tenders split ~58 cash / ~42 card like the October
// closes. Every rung sale carries 'source' => 'demo-ringer': fixture
// traffic, labelled, never disguised as organic.
//
// The nightly close (after the 21:00 store close) seals the day's card
// segment as one batch and composes the close from golpos_day_summary,
// in golpos_close_day's shape — the seed-demo-history composition.
//
// Usage (CLI, from the deployed demo tree):
//   php scripts/demo-ringer.php tick      (cron: ring if due + close if due)
//   php scripts/demo-ringer.php ring-now  (one ring immediately; proof/manual)
//   php scripts/demo-ringer.php status
//
// Auto-stop: after Nov 4 2026, 21:00 ET (the submission deadline) the
// ringer rings no more; a final close still lands that night, then the
// tick goes silent. Refuses in link mode — it must never reach a real
// store's stock or sales.

declare(strict_types=1);

if (isset($_SERVER['REQUEST_METHOD'])) { fwrite(STDERR, "Shell only\n"); exit(2); }
require __DIR__ . '/../server/store.php';

if (golpos_link_on()) { fwrite(STDERR, "link mode is ON — refusing to ring a linked stage\n"); exit(2); }

$tz = new DateTimeZone('America/New_York');
$STOP = new DateTime('2026-11-04 21:00:00', $tz);
$statePath = golpos_data_dir() . '/demo-ringer-state.json';
$logPath = golpos_data_dir() . '/demo-ringer-log.jsonl';

function ringer_log(string $path, array $entry): void {
    $entry['logged_at'] = date('c');
    file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function ringer_state_load(string $path): array {
    if (!file_exists($path)) { return []; }
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : [];
}
function ringer_state_save(string $path, array $state): void {
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

// Seal + close one day, the seed-demo-history composition of
// golpos_close_day's record. Returns the close record.
function ringer_close_day(string $ymd, DateTimeZone $tz): array {
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
    return $rec;
}

// Ring ONE sale through the register's own standalone path.
function ringer_ring(DateTime $now): ?array {
    $catalog = golpos_catalog();
    $taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);
    // Pool: tracked stock with a safe shelf left (>= 6). The ringer never
    // guts a shelf — a line's qty also leaves at least 3 behind — so hero
    // items (e.g. Coca-Cola 20oz at 3) stay untouched for the filming.
    $weights = []; $pool = [];
    foreach ($catalog['products'] as $p) {
        $sku = (string)$p['sku'];
        if ($sku === '1061') { continue; }
        // Sellable c-store stock only — the live catalog also carries a
        // $1,000 "purchase 1000 newport 100s" test artifact; a ring of it
        // would blow up the day's total on camera.
        if ((float)($p['price'] ?? 0) > 40.0) { continue; }
        if (!isset($p['stock']) || !is_numeric($p['stock']) || (float)$p['stock'] < 6) { continue; }
        $cat = (string)($p['category'] ?? '');
        $w = 12;
        if ($cat === 'Drinks') { $w = 60; }
        elseif ($cat === 'Snacks') { $w = 42; }
        elseif ($cat === 'Beer and Wine') { $w = 30; }
        elseif ($cat === 'Counter') { $w = 26; }
        elseif ($cat === 'Grocery') { $w = 14; }
        $weights[$sku] = $w; $pool[$sku] = $p;
    }
    if (!$pool) { return null; }
    $totalW = array_sum($weights);
    $pickSku = static function () use ($weights, $totalW): string {
        $r = mt_rand(1, $totalW);
        foreach ($weights as $sku => $w) { $r -= $w; if ($r <= 0) { return (string)$sku; } }
        return (string)array_key_last($weights);
    };
    $nLines = mt_rand(1, 100) <= 35 ? 1 : (mt_rand(1, 100) <= 46 ? 2 : (mt_rand(1, 100) <= 62 ? 3 : (mt_rand(1, 100) <= 80 ? 4 : 5)));
    $lines = []; $used = []; $subtotal = 0.0; $taxable = 0.0;
    for ($j = 0; $j < $nLines; $j++) {
        $sku = $pickSku();
        if (isset($used[$sku])) { continue; }
        $used[$sku] = true;
        $p = $pool[$sku];
        $qty = mt_rand(1, 100) <= 70 ? 1 : (mt_rand(1, 100) <= 78 ? 2 : 3);
        $qty = max(1, min($qty, (int)$p['stock'] - 3));
        $lt = round((float)$p['price'] * $qty, 2);
        $subtotal += $lt;
        if (($p['taxable'] ?? true) !== false) { $taxable += $lt; }
        $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'],
                    'qty' => $qty, 'line_total' => $lt];
    }
    if (!$lines) { return null; }
    $tax = round($taxable * $taxRate, 2);
    $tender = mt_rand(1, 100) <= 42 ? 'card' : 'cash';
    $total = round($subtotal + $tax, 2);
    $rec = ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => $total, 'tender' => $tender, 'source' => 'demo-ringer'];
    if ($tender === 'cash') {
        $roll = mt_rand(1, 100);
        $unit = $roll <= 20 ? $total : ($roll <= 50 ? 1 : ($roll <= 75 ? 5 : ($roll <= 90 ? 10 : 20)));
        $tendered = $unit === $total ? $total : ceil($total / $unit) * $unit;
        $rec['tendered'] = round($tendered, 2);
        $rec['change_due'] = round($tendered - $total, 2);
    }
    $saved = golpos_append_sale($rec, $now->format('Y-m-d\TH:i:sP'));
    golpos_decrement_stock($lines);
    return $saved;
}

$mode = $argv[1] ?? 'tick';
$state = ringer_state_load($statePath);
$now = new DateTime('now', $tz);
mt_srand((int)microtime(true) * 1000 % 2147483647 + getmypid());

if ($mode === 'status') {
    echo json_encode(['state' => $state, 'now_et' => $now->format('c'),
                      'today' => golpos_day_summary($now->format('Y-m-d'))], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($mode === 'ring-now') {
    $saved = ringer_ring($now);
    if ($saved === null) { fwrite(STDERR, "no ringable pool\n"); exit(1); }
    ringer_log($logPath, ['kind' => 'ring', 'manual' => true, 'txn' => $saved['id'],
                          'total' => $saved['total'], 'tender' => $saved['tender'],
                          'lines' => array_map(static fn($l) => $l['sku'] . 'x' . $l['qty'], $saved['lines'])]);
    $state['next_ring_at'] = $now->getTimestamp() + mt_rand(300, 600);
    ringer_state_save($statePath, $state);
    echo "RUNG {$saved['id']} total {$saved['total']} tender {$saved['tender']}\n";
    exit(0);
}

if ($mode !== 'tick') { fwrite(STDERR, "usage: demo-ringer.php tick|ring-now|status\n"); exit(2); }

// --- tick -----------------------------------------------------------------
// 1) Nightly close first: once the store day is over (21:12 ET) and the
//    day has sales but no close, seal + close it. This runs even past
//    the stop time, so Nov 4's rung day still lands its close that night.
$ymd = $now->format('Y-m-d');
$mins = (int)$now->format('H') * 60 + (int)$now->format('i');
if ($mins >= 21 * 60 + 12 && golpos_close_record($ymd) === null) {
    $sum = golpos_day_summary($ymd);
    if ($sum['txns'] > 0) {
        $rec = ringer_close_day($ymd, $tz);
        ringer_log($logPath, ['kind' => 'close', 'date' => $ymd, 'gross' => $rec['gross'],
                              'cash' => $rec['cash'], 'card' => $rec['card'], 'txns' => $rec['txns']]);
        echo "CLOSED {$ymd} gross {$rec['gross']} txns {$rec['txns']}\n";
    }
}

// 2) Auto-stop: no rings after the deadline.
if ($now > $STOP) {
    if (empty($state['stopped'])) {
        $state['stopped'] = true;
        ringer_state_save($statePath, $state);
        ringer_log($logPath, ['kind' => 'stopped', 'reason' => 'past Nov 4 21:00 ET deadline']);
    }
    exit(0);
}
if (!empty($state['stopped'])) { exit(0); }

// 3) Ring only inside store hours (08:00–21:00 ET), one sale per due tick.
if ($mins < 8 * 60 || $mins >= 21 * 60) {
    $next = (clone $now)->setTime(8, 0, 0);
    if ($next <= $now) { $next->modify('+1 day'); }
    $state['next_ring_at'] = $next->getTimestamp();
    ringer_state_save($statePath, $state);
    exit(0);
}
$due = (int)($state['next_ring_at'] ?? 0);
if ($now->getTimestamp() >= $due) {
    $saved = ringer_ring($now);
    if ($saved !== null) {
        ringer_log($logPath, ['kind' => 'ring', 'txn' => $saved['id'], 'total' => $saved['total'],
                              'tender' => $saved['tender'],
                              'lines' => array_map(static fn($l) => $l['sku'] . 'x' . $l['qty'], $saved['lines'])]);
        echo "RUNG {$saved['id']} total {$saved['total']} tender {$saved['tender']}\n";
    }
    $state['next_ring_at'] = $now->getTimestamp() + mt_rand(300, 600);
    ringer_state_save($statePath, $state);
}
exit(0);
