<?php
// Gol POS — register API.
// GET  ?products            -> catalog
// POST {sale:{lines,tender,tendered?}} -> records the sale, returns totals
// A line is a catalog line {sku,qty} or a manual line {manual:true,price,
// qty:1,taxable} keyed on the register's Keypad tab. Tax is per-line: the
// rate falls on taxable lines only (catalog lines are taxable unless the
// catalog flags one taxable:false; the whole-sale tax_exempt flag from
// the Settings menu still zeroes the sale's tax).
// Cash sales may carry the amount tendered; the server calculates change
// and refuses an under-tendered sale instead of recording a false total.
// Card sales carry the settings' card surcharge, computed here from the
// saved percent (never from the client) and recorded as `surcharge`.
// GET  ?summary             -> today's summary
// GET  ?batch               -> unbatched card segment + today's batches
// GET  ?last_sale           -> today's most recent sale (reprint), or null
// GET  ?history             -> the sales log grouped by day (newest first,
//                              capped per day) for the Transactions panel
// GET  ?pending             -> the staged card, if any
// GET  ?settings            -> receipt print settings (one store, two doors)
// POST {settings:{...}}     -> owner panel form save, same store
// POST {stock_add:{sku,qty}, session} -> the tile's "+ Stock" receive:
//   golpos_receive_stock (the chat stock_receive path), admin-gated by
//   the device session exactly as the chat gates its execution
// GET  ?report&date= | ?report&week -> Reports card data (+ close record)
// POST {close_day:true}     -> manual close, same path as the chat
// POST {product_create:{…}, session, pin?} -> Add Product sheet save:
//        admin-gated by the sheet PIN gate (store.php), created by the
//        one product constructor the chat's new-item path also builds

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Lazy AUTO batch pass: any API call can be the one that finds the store
// past closing with an unsealed card segment (see golpos_batch_auto_sweep).
golpos_batch_auto_sweep();

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
if ($method === 'GET' && isset($_GET['batch'])) {
    // The Settings dropdown's batch row: the unbatched card segment exactly
    // as the Gol chat stages it (ALL unbatched card sales, any day) plus
    // today's sealed batches.
    $seg = golpos_unbatched_card_sales();
    $segTotal = 0.0; foreach ($seg as $s) { $segTotal += (float)($s['total'] ?? 0); }
    echo json_encode(['unbatched' => ['count' => count($seg), 'total' => round($segTotal, 2)],
                      'batches_today' => golpos_batches_on(date('Y-m-d'))],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['last_sale'])) {
    // The most recent sale rung TODAY (the reprint source), or null.
    $today = date('Y-m-d'); $last = null;
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) === $today) { $last = $s; }
    }
    echo json_encode($last, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['history'])) {
    // Transaction history — the register's OWN sales log, read-only.
    // Days newest first, sales newest first inside each day, each day
    // capped at the latest 100 so a month of log stays fast (a capped
    // day says so, with its true count). Records ride back exactly as
    // recorded — lines with names, manual flags, tax_exempt, tendered
    // and change — because the panel renders and reprints from this
    // one read; there is no second copy of a sale. Standalone and link
    // mode read the same till log (MR.STORE's journal is the platform
    // mirror of a link sale, not this panel's source).
    $cap = 100;
    $byDay = [];
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { continue; }
        $byDay[$d][] = $s;
    }
    krsort($byDay);
    $days = [];
    foreach ($byDay as $d => $list) {
        $list = array_reverse($list); // the log appends oldest-first
        $day = ['date' => $d, 'count' => count($list),
                'sales' => array_slice($list, 0, $cap)];
        if (count($list) > $cap) { $day['truncated'] = true; }
        $days[] = $day;
    }
    echo json_encode(['days' => $days], JSON_UNESCAPED_UNICODE);
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
    // Reports compose WITH batches, exactly like the chat day close: the
    // day's sealed batches plus any card sales not yet inside one.
    $unb = golpos_unbatched_card_sales($d);
    $unbTotal = 0.0; foreach ($unb as $s) { $unbTotal += (float)($s['total'] ?? 0); }
    echo json_encode(['date' => $d, 'summary' => golpos_day_summary($d),
                      'close' => golpos_close_record($d),
                      'batches' => golpos_batches_on($d),
                      'unbatched_card' => ['count' => count($unb), 'total' => round($unbTotal, 2)]],
                     JSON_UNESCAPED_UNICODE);
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
    if (isset($in['stock_add']) && is_array($in['stock_add'])) {
        // The tile's "+ Stock" sheet — the screen door to the ONE receive
        // path (golpos_receive_stock: the live catalog increment standing
        // alone, the MR.STORE bridge receive when linked — the same
        // function the Gol chat's stock_receive card executes).
        // Receiving is an ADMIN-domain execution, one rule everywhere:
        // an unelevated device session writes nothing and hears which
        // PIN step stands in the way. The register then runs the PIN
        // through brain.php itself — the same door the chat uses — so
        // the create flow, attempts and the 3-strike lockout stay the
        // brain's own; this endpoint only reads the shared session.
        $qtyRaw = $in['stock_add']['qty'] ?? null;
        $qtyF = is_numeric($qtyRaw) ? (float)$qtyRaw : NAN;
        $qty = (is_finite($qtyF) && floor($qtyF) === $qtyF) ? (int)$qtyF : 0;
        if ($qty < 1 || $qty > 9999) {
            http_response_code(422);
            echo json_encode(['error' => 'quantity must be a whole number from 1 to 9999']);
            exit;
        }
        $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
        if ($sid === '') { $sid = 'anon'; }
        $sess = golpos_session($sid);
        if (empty($sess['admin'])) {
            $mode = 'verify';
            if (golpos_admin_pin_hash() === null) { $mode = 'create'; }
            elseif ((int)($sess['locked_until'] ?? 0) > time()) { $mode = 'locked'; }
            $resp = ['error' => 'pin_required', 'pin' => $mode];
            if ($mode === 'locked') {
                $resp['locked_minutes'] = (int)ceil(((int)$sess['locked_until'] - time()) / 60);
            }
            http_response_code(403);
            echo json_encode($resp, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sku = (string)($in['stock_add']['sku'] ?? '');
        $known = false;
        foreach (golpos_catalog()['products'] as $p) {
            if ((string)($p['sku'] ?? '') === $sku) { $known = true; break; }
        }
        if (!$known) {
            http_response_code(422);
            echo json_encode(['error' => 'unknown product']);
            exit;
        }
        $res = golpos_receive_stock($sku, $qty);
        if ($res === null) {
            http_response_code(422);
            echo json_encode(['error' => 'the receive could not be written — stock is unchanged']);
            exit;
        }
        echo json_encode(['ok' => true, 'sku' => $res['sku'], 'name' => $res['name'],
                          'added' => $qty, 'stock' => $res['after'], 'before' => $res['before']],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($in['close_day'])) {
        // The panel's manual Close-the-day button — the exact close path
        // the Gol chat's close branch runs (the panel confirms by tap first).
        echo json_encode(golpos_close_day(date('Y-m-d')), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($in['product_create']) && is_array($in['product_create'])) {
        // The Add Product sheet. Creation is an admin execution — the
        // brain PIN-gates new-item confirms, and the sheet runs the same
        // gate on the same session store before the constructor runs.
        // A gate step (pin_required / error) returns as-is; the sheet
        // keeps its form values and re-posts with the PIN.
        $gate = golpos_sheet_pin($in);
        if (empty($gate['ok'])) { echo json_encode($gate, JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(golpos_create_product($in['product_create']), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sale = $in['sale'] ?? null;
    if (is_array($sale) && !empty($sale['lines'])) {
        // A closed store refuses new sales at the door — the register's
        // own message, so any client hears the same plain words.
        if ((golpos_settings()['register_open'] ?? true) === false) {
            http_response_code(422);
            echo json_encode(['error' => 'Store is closed — open it from Settings']);
            exit;
        }
        $catalog = golpos_catalog();
        $bySku = [];
        foreach ($catalog['products'] as $p) { $bySku[$p['sku']] = $p; }
        $subtotal = 0.0; $taxableSubtotal = 0.0; $lines = [];
        foreach ($sale['lines'] as $l) {
            if (!empty($l['manual'])) {
                // A manual line has no SKU: the amount the cashier keyed
                // IS the price, qty is fixed at 1, and the taxable choice
                // made on the keypad rides along. An amount outside
                // $0.01–$9,999.99 refuses the whole sale, plainly, and
                // nothing records.
                $price = round((float)($l['price'] ?? 0), 2);
                if (!($price > 0) || $price > 9999.99) {
                    http_response_code(422);
                    echo json_encode(['error' => 'manual amount must be between $0.01 and $9,999.99']);
                    exit;
                }
                $taxable = !isset($l['taxable']) || (bool)$l['taxable'];
                $subtotal += $price;
                if ($taxable) { $taxableSubtotal += $price; }
                $lines[] = ['manual' => true, 'name' => 'Manual amount', 'price' => $price,
                            'qty' => 1, 'taxable' => $taxable, 'line_total' => $price];
                continue;
            }
            $p = $bySku[$l['sku']] ?? null;
            if (!$p) { continue; }
            $qty = max(1, (int)($l['qty'] ?? 1));
            $lineTotal = round((float)$p['price'] * $qty, 2);
            $subtotal += $lineTotal;
            if (($p['taxable'] ?? true) !== false) { $taxableSubtotal += $lineTotal; }
            $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty, 'line_total' => $lineTotal];
        }
        if (!$lines) {
            http_response_code(422);
            echo json_encode(['error' => 'sale has no valid catalog lines']);
            exit;
        }
        $taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);
        // Tax exempt is a per-sale flag from the register's Settings menu:
        // this sale records no tax; nothing about it persists to the next.
        $taxExempt = !empty($sale['tax_exempt']);
        $tax = $taxExempt ? 0.0 : round($taxableSubtotal * $taxRate, 2);
        $tender = ($sale['tender'] ?? 'cash') === 'card' ? 'card' : 'cash';
        // Card surcharge (owner setting): computed HERE from the saved
        // setting for card sales only — a client-sent figure is never
        // trusted — and folded into the total the customer pays, so the
        // day summary's card figure is the paid figure, surcharge
        // included, exactly as the journal's card field expects. Cash
        // never carries it; at 0% the record is untouched.
        $surcharge = 0.0; $surchargePct = 0.0;
        if ($tender === 'card') {
            $surchargePct = (float)(golpos_settings()['card_surcharge_pct'] ?? 0);
            if ($surchargePct > 0) {
                $surcharge = round((round($subtotal, 2) + $tax) * $surchargePct / 100, 2);
            }
        }
        $total = round($subtotal + $tax + $surcharge, 2);
        $recordData = [
            'lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => $total, 'tender' => $tender,
        ];
        if ($surcharge > 0) {
            $recordData['surcharge'] = $surcharge;
            $recordData['surcharge_pct'] = $surchargePct;
        }
        if ($taxExempt) { $recordData['tax_exempt'] = true; }
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
        if (!golpos_link_on()) {
            // STANDALONE: the sale takes its units off this POS's own
            // shelf, so the grid's live counts and fill bars move with
            // every ring. (Link mode moves MR.STORE stock instead, in
            // the branch below — never both.)
            golpos_decrement_stock($record['lines']);
        }
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
