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
// GET  ?inbox&session=      -> scan-by-phone results waiting for this
//                              register session (read-and-clear)
// GET  ?gap&session=&cart=&idle= -> the price-gap engine's idle prompt
//                              for the signed-in employee (at most one,
//                              only at a calm moment; pricegap.php)
// GET  ?settings            -> receipt print settings (one store, two doors)
// POST {settings:{...}}     -> settings save, same store; an admin
//                              write (sheet PIN gate) except a save of
//                              only the counter flips (auto_open,
//                              watch_frame), which stay ungated
// POST {seal_batch:true}    -> seal the unbatched card segment (admin)
// POST {stock_add:{sku,qty}, session} -> the tile's "+ Stock" receive:
//   golpos_receive_stock (the chat stock_receive path), admin-gated by
//   the device session exactly as the chat gates its execution
// GET  ?report&date= | ?report&week -> Reports card data (+ close record)
// POST {close_day:true}     -> manual close, same path as the chat (admin)
// POST {product_create:{…}, session, pin?} -> Add Product sheet save:
//        admin-gated by the sheet PIN gate (store.php), created by the
//        one product constructor the chat's new-item path also builds
// GET  ?credit&phone=       -> a customer's store-credit balance (the
//                              loyalty ledger), or the plain refusal
// POST {refund_quote:{sale_id, picks?}} -> the refund math for one
//                              recorded sale (read-only; nothing moves)
// POST {refund_execute:{sale_id, picks?, session, pin?}} -> execute a
//                              refund ONCE through golpos_execute_refund;
//                              PIN-gated by the sheet PIN gate when the
//                              store's refund_pin setting is on
// POST {sale:{…, credit:{phone, amount}}} -> a sale may carry a store-
//                              credit part: credit is a tender (tax math
//                              untouched), the rest splits to cash/card,
//                              and the spend records once per sale id

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
    $segTotal = 0.0; foreach ($seg as $s) { $segTotal += golpos_batch_amount($s); }
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
    // Refund state rides along, derived from the ONE refund record:
    // how much of this sale has gone back, per line and in total, so
    // the panel can badge it and offer Refund only for what is left.
    $refundsBySale = [];
    foreach (golpos_refunds() as $r) { $refundsBySale[(string)($r['sale_id'] ?? '')][] = $r; }
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { continue; }
        $refs = $refundsBySale[(string)($s['id'] ?? '')] ?? [];
        if ($refs) {
            $back = 0.0; $perLine = [];
            foreach ($refs as $r) {
                $back += (float)($r['total'] ?? 0);
                foreach ((array)($r['lines'] ?? []) as $rl) {
                    $k = (string)($rl['key'] ?? '');
                    $perLine[$k] = ($perLine[$k] ?? 0) + (int)($rl['qty'] ?? 0);
                }
            }
            $s['refunded_total'] = round($back, 2);
            $s['refunded_lines'] = $perLine;
            $left = 0;
            foreach ((array)($s['lines'] ?? []) as $li => $l) {
                if (!is_array($l)) { continue; }
                $k = golpos_sale_line_key($l, (int)$li);
                $left += max(0, (int)($l['qty'] ?? 1) - (int)($perLine[$k] ?? 0));
            }
            $s['refund_status'] = $left > 0 ? 'partial' : 'full';
        }
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
if ($method === 'GET' && isset($_GET['credit'])) {
    // A customer's store-credit balance, read from the ONE record
    // (Customer Identity Lane, Oct 28): standalone reads this register's
    // loyalty ledger; link mode reads MR.STORE's customer record
    // through the bridge — the same ledger the website gate and the
    // Festival receipt flow write. This GET is the Credit BUTTON's
    // door: tap Credit → type the number → the balance shows.
    // Name-first rides here too (?credit&name=): matches come back with
    // phone tails and the HUMAN picks — the pick re-enters as a phone
    // lookup, so money only ever moves against a chosen record.
    if (isset($_GET['name'])) {
        echo json_encode(['ok' => true,
                          'matches' => golpos_credit_find_by_name((string)$_GET['name'])],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    $acct = golpos_credit_account((string)($_GET['phone'] ?? ''));
    if ($acct === null) {
        http_response_code(422);
        echo json_encode(['error' => 'No credit on this number.']);
        exit;
    }
    echo json_encode(['ok' => true, 'name' => $acct['name'], 'phone' => $acct['phone'],
                      'balance' => $acct['balance']], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['settings'])) {
    // Receipt print settings — the ONE store both doors (Gol chat and the
    // owner panel form) read and write. The Register Camera effective
    // state rides along (Part B): in link mode it is computed from the
    // MR.STORE master's profile record; standalone it is the register's
    // own keys. The client obeys THIS block for every camera decision,
    // so the till and the facts can never drift apart.
    $out = golpos_settings();
    $out['regcam'] = golpos_regcam_state();
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['footage'])) {
    // The register's footage shelf: sales carrying a counter frame or an
    // event clip, newest first (ids + facts; frame.php serves bytes).
    echo json_encode(['footage' => golpos_footage_list()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['inbox'])) {
    // Scan-by-phone results waiting for THIS register session: a paired
    // phone upload's reply (and any staged card) lands here via
    // photo.php. Reading takes the entries — each renders exactly once.
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
    echo json_encode(['entries' => $sid === '' ? [] : golpos_inbox_take($sid)],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['gap'])) {
    // The price-gap engine's idle door (Oct 28): the client polls with
    // the till's truth — cart size and idle seconds — and the server
    // answers with at most ONE prompt, only for the signed-in
    // employee, only at a calm moment. Her identity comes from the
    // session exactly as ?mytasks resolves it — never from a query
    // parameter — and a locked or admin session gets silence. This
    // read is a task sync point, like the other employee reads.
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
    $sess = $sid === '' ? [] : golpos_session($sid);
    $email = null;
    if (($sess['unlocked_role'] ?? '') === 'employee' && !empty($sess['unlocked_by'])) {
        $email = (string)$sess['unlocked_by'];
    } elseif (!empty($sess['employee'])) {
        $email = (string)$sess['employee'];
    }
    if ($email === null) {
        echo json_encode(['prompt' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    golpos_tasks_sync();
    $prompt = golpos_gap_prompt_for($sid, $email,
        (int)($_GET['cart'] ?? 0), (int)($_GET['idle'] ?? 0));
    echo json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['lock'])) {
    // The register's lock state for THIS session (employee lane): the
    // UI raises the PIN screen from this read at boot and after any
    // reply that flips it. 'roster' says whether any PIN exists at all
    // (a virgin till never locks).
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
    $sess = $sid === '' ? [] : golpos_session($sid);
    $actor = null;
    if (!empty($sess['unlocked_by'])) {
        $actor = ['name' => (string)($sess['unlocked_name'] ?? ''),
                  'role' => (string)($sess['unlocked_role'] ?? ''),
                  'email' => $sess['unlocked_by'] === 'admin' ? '' : (string)$sess['unlocked_by']];
    }
    echo json_encode(['locked' => golpos_lock_state($sess), 'roster' => golpos_roster_has_pin(),
                      'actor' => $actor], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['team'])) {
    // The Owner panel's Team card + unlock feed + What's Out board in
    // one read. Owner-domain: when an admin PIN exists, the session
    // must be admin (the sheet gate's rule); a virgin setup till (no
    // PIN yet) reads openly, like the rest of setup mode. Reading the
    // inbox is a task sync point — Gol's own tasks generate here too.
    $adminPin = golpos_admin_pin_hash();
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
    $sess = $sid === '' ? [] : golpos_session($sid);
    if ($adminPin !== null && empty($sess['admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'pin_required']);
        exit;
    }
    golpos_tasks_sync();
    $people = [];
    foreach (golpos_people()['employees'] as $e) {
        $email = (string)$e['email'];
        $pres = golpos_presence_for($e);
        $open = 0; $done = 0; $unread = 0;
        foreach (golpos_tasks_for($email) as $t) {
            if (($t['employee_email'] ?? null) !== $email) { continue; }
            foreach ((array)($t['items'] ?? []) as $it) {
                if (!empty($it['done'])) { $done++; } else { $open++; }
            }
        }
        $notes = [];
        foreach (golpos_team_notes() as $n) {
            if (($n['from_email'] ?? '') === $email) {
                $notes[] = $n;
                if (empty($n['read'])) { $unread++; }
            }
        }
        $people[] = ['email' => $email, 'name' => golpos_display_name($e),
                     'role' => (string)($pres['role'] ?? 'cashier'),
                     'duties' => array_values((array)($pres['job_pack']['items'] ?? [])),
                     'first_unlock_at' => $pres['first_unlock_at'] ?? null,
                     'last_unlock_at' => $pres['last_unlock_at'] ?? null,
                     'last_unlock_photo' => $pres['last_unlock_photo'] ?? null,
                     'tasks_open' => $open, 'tasks_done' => $done,
                     'notes' => array_slice(array_reverse($notes), 0, 5), 'notes_unread' => $unread,
                     'trail' => golpos_presence_trail($email, date('Y-m-d'))];
    }
    $roleTasks = [];
    foreach (golpos_tasks() as $t) {
        if (!empty($t['employee_email']) || ($t['status'] ?? '') === 'superseded'
            || golpos_task_status($t) === 'done') { continue; }
        $roleTasks[] = $t;
    }
    // The unlock FEED shows opens and lockouts — 'lock' events are the
    // pay lane's hours evidence, not feed rows.
    $feedUnlocks = array_values(array_filter(golpos_unlocks(),
        static fn($u) => in_array((string)($u['kind'] ?? 'unlock'), ['unlock', 'lockout'], true)));
    $payReqs = [];
    foreach (golpos_pay_pending_all() as $r) {
        $payReqs[] = ['id' => (string)$r['id'], 'name' => (string)$r['employee_name'],
                      'email' => (string)$r['employee_email'],
                      'period' => golpos_pay_period_label((string)$r['period_from'], (string)$r['period_to']),
                      'hours' => (float)$r['hours'], 'amount' => (float)$r['amount'],
                      'segments' => (array)$r['segments'],
                      'corrections' => (array)($r['corrections'] ?? []),
                      'created' => (string)($r['created'] ?? '')];
    }
    echo json_encode(['people' => $people, 'tasks' => golpos_tasks(), 'role_tasks' => $roleTasks,
                      'unlocks' => array_slice(array_reverse($feedUnlocks), 0, 15),
                      'whatsout' => golpos_whatsout_open(),
                      'pay_requests' => $payReqs,
                      'counts' => array_slice(array_reverse(golpos_counts()), 0, 20)],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['mytasks'])) {
    // The signed-in employee's own task view (a sync point too). Her
    // identity comes from the session — unlock or device — never from
    // a query parameter, so nobody can read another person's list.
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
    $sess = $sid === '' ? [] : golpos_session($sid);
    $email = null;
    if (($sess['unlocked_role'] ?? '') === 'employee' && !empty($sess['unlocked_by'])) {
        $email = (string)$sess['unlocked_by'];
    } elseif (!empty($sess['employee'])) {
        $email = (string)$sess['employee'];
    }
    if ($email === null) {
        echo json_encode(['tasks' => [], 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    golpos_tasks_sync();
    $items = [];
    foreach (golpos_tasks_for($email) as $t) {
        foreach ((array)($t['items'] ?? []) as $idx => $it) {
            if (!empty($it['done'])) { continue; }
            $items[] = ['task_id' => (string)$t['id'], 'index' => $idx,
                        'text' => (string)($it['text'] ?? ''),
                        'source' => (string)($t['source'] ?? 'owner'),
                        'reason' => (string)($t['reason'] ?? ''),
                        'created' => (string)($t['created'] ?? '')];
        }
    }
    echo json_encode(['email' => $email, 'items' => $items, 'next' => golpos_next_due($email)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['whatsout'])) {
    echo json_encode(['open' => golpos_whatsout_open()], JSON_UNESCAPED_UNICODE);
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
if ($method === 'GET' && isset($_GET['orders'])) {
    // Register order prep — the Owner panel's "Today's rep visits" card:
    // every vendor due today with its prep status, plus the order
    // records (newest first) whose sheets the panel prints/shares. Read
    // only — orders are staged and confirmed in the Gol chat.
    $today = date('Y-m-d');
    $pend = golpos_pending();
    $visits = [];
    foreach (golpos_vendors() as $v) {
        $due = golpos_vendor_due_on($v, $today);
        $rec = golpos_order_for((string)($v['id'] ?? ''), $today);
        $status = $rec !== null ? (string)$rec['status']
            : (($pend !== null && ($pend['type'] ?? '') === 'order_prep'
                && ($pend['vendor_id'] ?? '') === (string)($v['id'] ?? '')) ? 'prepared' : 'none');
        $visits[] = ['id' => (string)($v['id'] ?? ''), 'name' => (string)($v['name'] ?? ''),
                     'rep_days' => (array)($v['rep_days'] ?? []),
                     'delivery_day' => $v['delivery_day'] ?? null,
                     'cadence' => (string)($v['cadence'] ?? 'weekly'),
                     'account' => (string)($v['account'] ?? ''),
                     'due_today' => $due, 'status' => $due ? $status : 'none',
                     'order_id' => $rec['id'] ?? null,
                     'est_total' => $rec !== null ? (float)$rec['est_total'] : null];
    }
    $orders = golpos_orders();
    usort($orders, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    echo json_encode(['date' => $today, 'visits' => $visits, 'orders' => $orders],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['order_sheet'])) {
    // One order record for the print/share sheet, or null.
    $id = (string)($_GET['order_sheet'] ?? '');
    $found = null;
    foreach (golpos_orders() as $o) {
        if ((string)($o['id'] ?? '') === $id) { $found = $o; break; }
    }
    echo json_encode($found, JSON_UNESCAPED_UNICODE);
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
        // A settings save — same store the Gol chat stages into.
        // Configuration writes are ADMIN executions (owner standing
        // rule, Oct 28: admin-domain actions demand the admin PIN from
        // anyone not in admin mode), so the sheet PIN gate —
        // golpos_sheet_pin, the Add Product gate on the chat's own
        // session store — runs first and a gate step returns as-is for
        // the PIN sheet. The cashier exceptions are the Settings
        // dropdown's two instant flips, Printer (auto_open) and Camera
        // frame (watch_frame): a save touching ONLY those keys is a
        // counter switch and stays ungated; a save touching anything
        // else (the Store toggle's register_open included) is gated.
        $gateKeys = array_diff(array_keys($in['settings']), ['auto_open', 'watch_frame']);
        if ($gateKeys) {
            $gate = golpos_sheet_pin($in);
            if (empty($gate['ok'])) { echo json_encode($gate, JSON_UNESCAPED_UNICODE); exit; }
        }
        $saved = golpos_save_settings($in['settings']);
        $saved['regcam'] = golpos_regcam_state();
        echo json_encode($saved, JSON_UNESCAPED_UNICODE);
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
    if (isset($in['task_done']) && is_array($in['task_done'])) {
        // An employee marks one of HER OWN items done from the tasks
        // view — a tap is the act (no staged card for a done mark).
        // Identity comes from the session; a task that isn't hers (or
        // an open role task) refuses.
        $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
        $sess = $sid === '' ? [] : golpos_session($sid);
        $email = null;
        if (($sess['unlocked_role'] ?? '') === 'employee' && !empty($sess['unlocked_by'])) {
            $email = (string)$sess['unlocked_by'];
        } elseif (!empty($sess['employee'])) {
            $email = (string)$sess['employee'];
        }
        $tid = (string)($in['task_done']['task_id'] ?? '');
        $idx = (int)($in['task_done']['index'] ?? -1);
        $task = null;
        foreach (golpos_tasks() as $t) { if ((string)($t['id'] ?? '') === $tid) { $task = $t; break; } }
        if ($email === null || $task === null || !isset($task['items'][$idx])
            || !(($task['employee_email'] ?? null) === $email
                 || (($task['employee_email'] ?? null) === null && !empty($task['role'])))) {
            http_response_code(403);
            echo json_encode(['error' => 'not your task']);
            exit;
        }
        $task['items'][$idx]['done'] = true;
        $task['items'][$idx]['done_at'] = date('c');
        golpos_task_put($task);
        echo json_encode(['ok' => true, 'status' => golpos_task_status($task)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($in['close_day'])) {
        // The panel's manual Close-the-day button — the exact close path
        // the Gol chat's close branch runs (the panel confirms by tap
        // first). Closing the day is an admin execution: the sheet PIN
        // gate runs first, so an unelevated session is challenged and
        // nothing closes until the PIN lands (owner rule, Oct 28).
        $gate = golpos_sheet_pin($in);
        if (empty($gate['ok'])) { echo json_encode($gate, JSON_UNESCAPED_UNICODE); exit; }
        $rec = golpos_close_day(date('Y-m-d'));
        // The day close ends the shift (employee lane): the acting
        // session returns to PIN-please, same as the chat close.
        $csid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
        if ($csid !== '') { golpos_lock_session($csid); }
        echo json_encode($rec, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($in['seal_batch'])) {
        // The Settings dropdown's Seal batch button — the direct door to
        // the ONE seal: golpos_seal_batch over the still-unbatched card
        // segment, the same set the Gol chat's batch_close card seals.
        // Sealing is an admin execution: the sheet PIN gate runs first,
        // and on success the session is in admin mode. A batch_close
        // card staged in chat for this segment is spent by the seal —
        // the segment it named no longer exists unbatched.
        $gate = golpos_sheet_pin($in);
        if (empty($gate['ok'])) { echo json_encode($gate, JSON_UNESCAPED_UNICODE); exit; }
        $seg = golpos_unbatched_card_sales();
        if (!$seg) {
            echo json_encode(['ok' => true, 'sealed' => false, 'count' => 0, 'total' => 0],
                             JSON_UNESCAPED_UNICODE);
            exit;
        }
        $b = golpos_seal_batch($seg, 'manual');
        $pend = golpos_pending();
        if ($pend !== null && ($pend['type'] ?? '') === 'batch_close') { golpos_clear_pending(); }
        echo json_encode(['ok' => true, 'sealed' => true, 'batch' => $b], JSON_UNESCAPED_UNICODE);
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
    if (isset($in['refund_quote']) && is_array($in['refund_quote'])) {
        // The Transactions sheet's math read: what this sale can still
        // give back, line by line, before anything is staged or moved.
        $target = golpos_sale_by_id((string)($in['refund_quote']['sale_id'] ?? ''));
        if ($target === null) {
            http_response_code(422);
            echo json_encode(['error' => 'That sale is not in the register\'s log.']);
            exit;
        }
        $picks = isset($in['refund_quote']['picks']) && is_array($in['refund_quote']['picks'])
            ? $in['refund_quote']['picks'] : null;
        $q = golpos_refund_quote($target, $picks);
        if (isset($q['error'])) {
            http_response_code(422);
            echo json_encode(['error' => $q['error']]);
            exit;
        }
        echo json_encode(['ok' => true, 'quote' => $q,
                          'sale' => ['id' => $target['id'], 'at' => $target['at'],
                                     'total' => $target['total'], 'tender' => $target['tender']]],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($in['refund_execute']) && is_array($in['refund_execute'])) {
        // The refund itself — one execution path (golpos_execute_refund,
        // the same one the Gol chat's refund card runs), so the sheet
        // and the chat can never disagree about what a refund did.
        // Policy and state refusals speak BEFORE the PIN: a refund that
        // cannot happen never asks for one. The PIN follows the store's
        // refund_pin setting — ON (the default) runs the sheet PIN gate;
        // OFF means the counter runs it on the open session alone.
        $target = golpos_sale_by_id((string)($in['refund_execute']['sale_id'] ?? ''));
        if ($target === null) {
            http_response_code(422);
            echo json_encode(['error' => 'That sale is not in the register\'s log.']);
            exit;
        }
        $picks = isset($in['refund_execute']['picks']) && is_array($in['refund_execute']['picks'])
            ? $in['refund_execute']['picks'] : null;
        $pre = golpos_refund_quote($target, $picks);
        if (isset($pre['error'])) {
            http_response_code(422);
            echo json_encode(['error' => $pre['error']]);
            exit;
        }
        if (empty(golpos_settings()['refunds_allowed'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Refunds are off for this store — turn them on in Gol chat (a settings card) first.']);
            exit;
        }
        if (!empty(golpos_settings()['refund_pin'])) {
            $gate = golpos_sheet_pin($in);
            if (empty($gate['ok'])) { echo json_encode($gate, JSON_UNESCAPED_UNICODE); exit; }
        }
        $rsid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
        $rsess = $rsid !== '' ? golpos_session($rsid) : [];
        $byName = (string)($rsess['unlocked_name'] ?? '');
        $byEmail = (!empty($rsess['unlocked_by']) && $rsess['unlocked_by'] !== 'admin')
            ? (string)$rsess['unlocked_by'] : '';
        $res = golpos_execute_refund($target, $picks, $byName, $byEmail);
        if (isset($res['error'])) {
            http_response_code(422);
            echo json_encode(['error' => $res['error']]);
            exit;
        }
        echo json_encode(['ok' => true, 'refund' => $res['refund'],
                          'summary' => golpos_day_summary(date('Y-m-d'))], JSON_UNESCAPED_UNICODE);
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
            $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty,
                        'taxable' => (($p['taxable'] ?? true) !== false), 'line_total' => $lineTotal];
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
        $base = round(round($subtotal, 2) + $tax, 2);
        // --- store credit: a tender, never a discount -------------------
        // The credit part is validated against the ONE ledger before
        // anything records: the setting, the account, the balance, the
        // sale's own total. Tax was computed above without any knowledge
        // of tenders — paying by credit changes no tax by a cent. The
        // account read goes through the facade, so in link mode the
        // balance being validated is MR.STORE's customer record — and
        // the spend below lands in that same ledger, keyed by this
        // sale's id (Customer Identity Lane, Oct 28).
        $creditAmt = 0.0; $creditPhone = ''; $creditName = '';
        if (isset($sale['credit']) && is_array($sale['credit'])) {
            if (empty(golpos_settings()['credit_redeem'])) {
                http_response_code(422);
                echo json_encode(['error' => 'Store credit redeem is off for this store — nothing was recorded.']);
                exit;
            }
            $acct = golpos_credit_account((string)($sale['credit']['phone'] ?? ''));
            if ($acct === null) {
                http_response_code(422);
                echo json_encode(['error' => 'No credit on this number — nothing was recorded.']);
                exit;
            }
            $want = isset($sale['credit']['amount']) && is_numeric($sale['credit']['amount'])
                ? round((float)$sale['credit']['amount'], 2) : 0.0;
            if ($want <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Say how much credit to apply — nothing was recorded.']);
                exit;
            }
            if ($want > (float)$acct['balance']) {
                http_response_code(422);
                echo json_encode(['error' => 'Credit balance is $' . number_format((float)$acct['balance'], 2)
                    . ' — apply that much or less. Nothing was recorded.']);
                exit;
            }
            if ($want > $base) {
                http_response_code(422);
                echo json_encode(['error' => 'Credit can cover at most the sale total of $'
                    . number_format($base, 2) . ' — nothing was recorded.']);
                exit;
            }
            $creditAmt = $want; $creditPhone = (string)$acct['phone']; $creditName = (string)$acct['name'];
        }
        $remainderBase = round($base - $creditAmt, 2);
        // The tender is the REMAINDER's kind; a sale fully covered by
        // credit has no remainder and its tender is credit itself.
        $tender = ($creditAmt > 0 && $remainderBase <= 0) ? 'credit'
            : ((($sale['tender'] ?? 'cash') === 'card') ? 'card' : 'cash');
        // Card surcharge (owner setting): computed HERE from the saved
        // setting for card sales only — a client-sent figure is never
        // trusted — and folded into the total the customer pays, so the
        // day summary's card figure is the paid figure, surcharge
        // included, exactly as the journal's card field expects. With a
        // credit part, the surcharge falls only on the card-paid share.
        // Cash never carries it; at 0% the record is untouched.
        $surcharge = 0.0; $surchargePct = 0.0;
        if ($tender === 'card') {
            $surchargePct = (float)(golpos_settings()['card_surcharge_pct'] ?? 0);
            if ($surchargePct > 0) {
                $surcharge = round($remainderBase * $surchargePct / 100, 2);
            }
        }
        $total = round($base + $surcharge, 2);
        $recordData = [
            'lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => $total, 'tender' => $tender,
        ];
        if ($surcharge > 0) {
            $recordData['surcharge'] = $surcharge;
            $recordData['surcharge_pct'] = $surchargePct;
        }
        if ($creditAmt > 0) {
            $tenders = ['credit' => $creditAmt];
            $rem = round($total - $creditAmt, 2);
            if ($rem > 0) { $tenders[$tender] = $rem; }
            $recordData['tenders'] = $tenders;
            $recordData['credit_phone'] = $creditPhone;
            if ($creditName !== '') { $recordData['credit_name'] = $creditName; }
        }
        if ($taxExempt) { $recordData['tax_exempt'] = true; }
        if ($tender === 'cash') {
            // No tendered amount from an older client means exact cash; an
            // explicit amount must cover the CASH DUE (the total, or the
            // cash remainder when credit covered a part) before anything
            // records.
            $cashDue = $creditAmt > 0 ? round($total - $creditAmt, 2) : $total;
            $tendered = isset($sale['tendered']) ? round((float)$sale['tendered'], 2) : $cashDue;
            if ($tendered < $cashDue) {
                http_response_code(422);
                echo json_encode(['error' => 'cash tendered is less than the total', 'total' => $cashDue, 'tendered' => $tendered]);
                exit;
            }
            $recordData['tendered'] = $tendered;
            $recordData['change_due'] = round($tendered - $cashDue, 2);
        }
        // Who rang it: the session's unlocked identity rides the sale
        // record (employee lane) — the owner's trail can say whose
        // hands were on the till. No identity, no stamp.
        $saleSid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
        if ($saleSid !== '') {
            $ssess = golpos_session($saleSid);
            if (!empty($ssess['unlocked_by'])) {
                $recordData['cashier'] = (string)($ssess['unlocked_name'] ?? '');
                if ($ssess['unlocked_by'] !== 'admin') {
                    $recordData['cashier_email'] = (string)$ssess['unlocked_by'];
                }
            } elseif (!empty($ssess['employee'])) {
                $ce = golpos_employee((string)$ssess['employee']);
                $recordData['cashier'] = $ce !== null ? golpos_display_name($ce) : '';
                $recordData['cashier_email'] = (string)$ssess['employee'];
            }
        }
        $record = golpos_append_sale($recordData);
        if ($creditAmt > 0) {
            // The spend lands ONCE, keyed by this sale's id — the
            // ledger itself refuses a second redeem under the same id,
            // so a retried tender can never double-spend the balance.
            golpos_credit_add($creditPhone, -$creditAmt, 'redeem', ['sale_id' => (string)$record['id']]);
            $record['credit_balance'] = golpos_credit_balance($creditPhone);
        }
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
