<?php
// Gol POS — register API.
// GET  ?products            -> catalog
// POST {sale:{lines,tender,tendered?}} -> records the sale, returns totals
// Cash sales may carry the amount tendered; the server calculates change
// and refuses an under-tendered sale instead of recording a false total.
// GET  ?summary             -> today's summary
// GET  ?pending             -> the staged card, if any
// GET  ?settings            -> receipt print settings (one store, two doors)
// POST {settings:{...}}     -> owner panel form save, same store
// GET  ?report&date= | ?report&week -> Reports card data (+ close record)
// POST {close_day:true}     -> manual close, same path as the chat

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['products'])) {
    echo json_encode(golpos_catalog(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['bind'])) {
    // Launch binding state — see POST {bind} below.
    echo json_encode(golpos_bind_state(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['summary'])) {
    echo json_encode(golpos_day_summary(date('Y-m-d')), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['closes'])) {
    // Day-close records — one per date, written when the owner closes the
    // day in the Gol chat; the owner view reads them back here.
    echo json_encode(golpos_read('closes.json', []), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['pending'])) {
    echo json_encode(golpos_pending() ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['feed'])) {
    // The demo store's social feed — posters Gol staged and the owner
    // confirmed with "gol". Fictional demo content only.
    echo json_encode(golpos_read('feed.json', []), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['settings'])) {
    // Receipt print settings — the ONE store both doors (Gol chat and the
    // owner panel form) read and write.
    echo json_encode(golpos_settings(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['report'])) {
    // Manual Reports card data — the same day-summary figures the Gol chat
    // speaks, for a picked date or the last 7 days, plus the saved close
    // record for a date when one exists (the frozen truth for that day).
    if (isset($_GET['week'])) {
        $days = []; $tot = ['gross' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'txns' => 0];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $s = golpos_day_summary($d);
            $s['closed'] = golpos_close_record($d) !== null;
            $days[] = $s;
            $tot['gross'] += $s['gross']; $tot['cash'] += $s['cash'];
            $tot['card'] += $s['card']; $tot['txns'] += $s['txns'];
        }
        $tot = ['gross' => round($tot['gross'], 2), 'cash' => round($tot['cash'], 2),
                'card' => round($tot['card'], 2), 'txns' => $tot['txns']];
        echo json_encode(['days' => $days, 'totals' => $tot], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $d = (string)($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $d = date('Y-m-d'); }
    echo json_encode(['date' => $d, 'summary' => golpos_day_summary($d),
                      'close' => golpos_close_record($d)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    if (isset($in['bind']) && is_array($in['bind'])) {
        // Launch contract, identical to MR.STORE's settings link for its
        // own register: ?store=<id>&name=<name>&unlock=1. The register
        // records the launch context; the link engages only when the
        // store it names is the configured (fenced) store.
        golpos_bind_save(['store' => trim((string)($in['bind']['store'] ?? '')),
                          'name' => trim((string)($in['bind']['name'] ?? '')),
                          'unlock' => !empty($in['bind']['unlock'])]);
        echo json_encode(golpos_bind_state(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($in['settings']) && is_array($in['settings'])) {
        // Owner panel form save — same store the Gol chat stages into.
        echo json_encode(golpos_save_settings($in['settings']), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($in['close_day'])) {
        // The panel's manual Close-the-day button — the exact close path
        // the Gol chat's close branch runs (the panel confirms by tap first).
        echo json_encode(golpos_close_day(date('Y-m-d')), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sale = $in['sale'] ?? null;
    if (is_array($sale) && !empty($sale['lines'])) {
        $catalog = golpos_catalog();
        $bySku = [];
        foreach ($catalog['products'] as $p) { $bySku[$p['sku']] = $p; }
        $subtotal = 0.0; $lines = [];
        foreach ($sale['lines'] as $l) {
            $p = $bySku[$l['sku']] ?? null;
            if (!$p) { continue; }
            $qty = max(1, (int)($l['qty'] ?? 1));
            $lineTotal = round((float)$p['price'] * $qty, 2);
            $subtotal += $lineTotal;
            $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty, 'line_total' => $lineTotal];
        }
        if (!$lines) {
            http_response_code(422);
            echo json_encode(['error' => 'sale has no valid catalog lines']);
            exit;
        }
        $taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);
        $tax = round($subtotal * $taxRate, 2);
        $total = round($subtotal + $tax, 2);
        $tender = ($sale['tender'] ?? 'cash') === 'card' ? 'card' : 'cash';
        $recordData = [
            'lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => $total, 'tender' => $tender,
        ];
        if ($tender === 'cash') {
            // No tendered amount from an older client means exact cash; an
            // explicit amount must cover the total before anything records.
            $tendered = isset($sale['tendered']) ? round((float)$sale['tendered'], 2) : $total;
            if ($tendered < $total) {
                http_response_code(422);
                echo json_encode(['error' => 'cash tendered is less than the total', 'total' => $total, 'tendered' => $tendered]);
                exit;
            }
            $recordData['tendered'] = $tendered;
            $recordData['change_due'] = round($tendered - $total, 2);
        }
        $record = golpos_append_sale($recordData);
        if (golpos_link_on()) {
            // LINK MODE: the same sale moves the linked MR.STORE store's
            // real stock, the way a live POS feed would. The note rides in
            // the response and in the link log; the till record stands
            // even if the link is down (stock note is then simply absent).
            $notes = mrstore_record_sale($record['lines']);
            if ($notes !== null) {
                $record['mrstore_stock'] = $notes;
                golpos_link_log(['kind' => 'sale', 'txn' => $record['id'],
                                 'total' => $record['total'], 'tender' => $record['tender'],
                                 'stock' => $notes]);
            }
            // The same sale also lands in MR.STORE's register journal —
            // the ticket its card reports and batches are built from.
            $dayEt = (new DateTime((string)$record['at']))
                ->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
            $ticket = mrstore_append_ticket($record, (string)($catalog['store']['name'] ?? ''), $dayEt);
            if ($ticket !== null) { $record['mrstore_ticket'] = $ticket['receiptId']; }
        }
        echo json_encode($record, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
http_response_code(400);
echo json_encode(['error' => 'bad request']);
