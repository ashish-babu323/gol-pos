<?php
// --- THE PRICE-GAP ENGINE (owner design, Oct 28, 15:20) -------------------------
// "Try to get the price, cost price — the items that are empty. Make an
// engine that will slowly enter one or two prices of an item. Pranisha
// is just sitting in front of the POS, the Gol chat right in front of
// her, and it reminds her: let's enter the price of Coca-Cola, because
// it wasn't there and nobody was caring about it."
//
// The catalog carries items nobody finished: no shelf price, or no
// cost. This engine heals them from idle moments at the till, riding
// the employee lane's reflex loop like its sibling generators
// (report_due, bill_waiting, whatsout_aging):
//   SENSE — a deterministic scan of the catalog finds the gaps; one
//           task per product (kind price_gap, subject gap:<sku>), one
//           item per missing field, routed to whoever's checked duties
//           carry the register; unheld -> the owner's inbox.
//   GIVE  — the ask surfaces in HER session only: one line in the
//           unlock brief, and an idle poll (api.php ?gap) that fires
//           only when the client reports an empty cart and a real idle
//           stretch — never mid-sale, never a flood (<= 2 prompts per
//           person per day, >= 20 minutes apart).
//   STAGE — her number stages a fill card (price_fill rides the price
//           lane's write path and gates; cost_fill writes the cost
//           record). "Thaha chhaina" is a complete answer: the gap
//           stays open, unpunished, with a 7-day backoff before the
//           item is offered to anyone again. A dismiss snoozes the
//           item for her until her next unlock.
//   VERIFY — a gap closes only by evidence: the next scan sees the
//           value on the product record (a fill, a bill line, an owner
//           edit — the record is the truth, the route doesn't matter).
//
// NOTHING here ever invents a price or a cost. State lives in
// data/price_gaps.json: per-gap first-seen / fill stamps, the prompt
// log, per-person snoozes. The tasks themselves live in tasks.json
// like every other duty — one system, no parallel list.

// --- state ----------------------------------------------------------------------
function golpos_gap_state(): array {
    $st = golpos_read('price_gaps.json', null);
    if (!is_array($st)) { $st = []; }
    if (!isset($st['gaps']) || !is_array($st['gaps'])) { $st['gaps'] = []; }
    if (!isset($st['prompts']) || !is_array($st['prompts'])) { $st['prompts'] = []; }
    if (!isset($st['snooze']) || !is_array($st['snooze'])) { $st['snooze'] = []; }
    return $st;
}

function golpos_gap_save_state(array $st): void {
    golpos_write('price_gaps.json', $st);
}

// --- SENSE ----------------------------------------------------------------------
// The fields one product is missing, by the mode's own truth. Price:
// standalone — unset or <= 0; link mode — MR.STORE carries no price
// for it, so the register is showing a sim-demo fallback
// (price_source says so). Cost: no numeric cost on the record, in
// either mode (the link projection carries MR.STORE's cost when set).
function golpos_gap_product_fields(array $p): array {
    $fields = [];
    if (golpos_link_on()) {
        if (($p['price_source'] ?? 'mrstore') === 'sim-demo'
            || !isset($p['price']) || !is_numeric($p['price']) || (float)$p['price'] <= 0) {
            $fields[] = 'price';
        }
    } else {
        if (!isset($p['price']) || !is_numeric($p['price']) || (float)$p['price'] <= 0) {
            $fields[] = 'price';
        }
    }
    if (!isset($p['cost']) || !is_numeric($p['cost'])) { $fields[] = 'cost'; }
    return $fields;
}

// Walk the catalog once: open gaps are returned (price asks before
// cost asks, oldest first), and the state file is reconciled with the
// records — a gap whose value has landed is stamped filled (today's
// fills are the owner's trail line), a filled gap whose value was
// lost again reopens as a fresh instance, a product that left the
// catalog closes its gap as gone (never counted as a fill).
function golpos_gap_scan(): array {
    $st = golpos_gap_state();
    $now = date('c');
    $open = [];
    $seen = [];
    foreach ((array)(golpos_catalog()['products'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $sku = (string)($p['sku'] ?? '');
        if ($sku === '') { continue; }
        $name = (string)($p['name'] ?? $sku);
        foreach (golpos_gap_product_fields($p) as $field) {
            $key = $sku . '|' . $field;
            $seen[$key] = true;
            if (!isset($st['gaps'][$key]) || !is_array($st['gaps'][$key])) {
                $st['gaps'][$key] = ['sku' => $sku, 'name' => $name, 'field' => $field,
                    'first_seen' => $now, 'status' => 'open', 'filled_at' => null,
                    'last_prompt_at' => null, 'prompt_count' => 0, 'dontknow_until' => null];
            } elseif (($st['gaps'][$key]['status'] ?? '') !== 'open') {
                $st['gaps'][$key]['status'] = 'open';
                $st['gaps'][$key]['first_seen'] = $now;
                $st['gaps'][$key]['filled_at'] = null;
                $st['gaps'][$key]['dontknow_until'] = null;
            }
            $st['gaps'][$key]['name'] = $name;
            $open[] = ['sku' => $sku, 'name' => $name, 'field' => $field,
                       'first_seen' => (string)$st['gaps'][$key]['first_seen']];
        }
    }
    foreach ($st['gaps'] as $key => $g) {
        if (isset($seen[$key]) || ($g['status'] ?? '') !== 'open') { continue; }
        // The value landed (or the product left): decide by the record.
        $prod = null;
        foreach ((array)(golpos_catalog()['products'] ?? []) as $p) {
            if (is_array($p) && (string)($p['sku'] ?? '') === (string)($g['sku'] ?? '')) { $prod = $p; break; }
        }
        if ($prod === null) {
            $st['gaps'][$key]['status'] = 'gone';
        } else {
            $st['gaps'][$key]['status'] = 'filled';
            $st['gaps'][$key]['filled_at'] = $now;
        }
    }
    golpos_gap_save_state($st);
    usort($open, static function ($a, $b) {
        $ra = $a['field'] === 'price' ? 0 : 1;
        $rb = $b['field'] === 'price' ? 0 : 1;
        return [$ra, $a['first_seen']] <=> [$rb, $b['first_seen']];
    });
    return $open;
}

// The generator section, called from golpos_tasks_sync with its $spawn
// closure (dedupe + owner-yield + done-guard all live there, shared by
// every generator kind). One task per product; routing reads the
// checklist like the siblings — the register duty is the cashier's own.
function golpos_gap_tasks_sync(callable $spawn): void {
    $bySku = [];
    foreach (golpos_gap_scan() as $g) { $bySku[$g['sku']][] = $g; }
    if (!$bySku) { return; }
    $keeper = golpos_roster_with_duty('register kholne/banda garne');
    foreach ($bySku as $sku => $fields) {
        $name = (string)$fields[0]['name'];
        $items = [];
        $firstSeen = null;
        $fieldNames = [];
        foreach ($fields as $g) {
            $items[] = ['text' => $g['field'] === 'price'
                ? $name . ' — shelf price hal (record ma price chhaina)'
                : $name . ' — cost price hal (kunai bill ma pani cost aayena)',
                'field' => $g['field'], 'done' => false, 'done_at' => null];
            $fieldNames[] = $g['field'] === 'price' ? 'shelf price' : 'cost price';
            if ($firstSeen === null || (string)$g['first_seen'] < $firstSeen) {
                $firstSeen = (string)$g['first_seen'];
            }
        }
        if ($keeper === null) {
            foreach ($items as $i => $it) {
                $items[$i]['text'] = $it['text']
                    . ' (kunai employee ko checklist ma register duty checked chhaina — owner inbox ma chha)';
            }
        }
        $spawn(['employee_email' => $keeper !== null ? (string)$keeper['email'] : null,
                'employee_name' => $keeper !== null ? golpos_display_name($keeper) : 'Unassigned (owner inbox)',
                'role' => $keeper !== null ? null : 'team',
                'items' => $items,
                'source' => 'gol', 'kind' => 'price_gap', 'subject' => 'gap:' . $sku,
                'reason' => 'Catalog ma ' . $name . ' ko ' . implode(' ra ', $fieldNames)
                    . ' record chhaina — sodhne lai Gol le sahi jawaf dina sakdaina',
                'since' => $firstSeen ?? date('c')]);
    }
}

// Closure by evidence for one price_gap task, called from
// golpos_tasks_reconcile: an item closes when its field is on the
// record; the task closes when every field is. A product that left
// the catalog closes the task — there is nothing left to heal.
function golpos_gap_reconcile_task(array $t): void {
    $sku = substr((string)($t['subject'] ?? ''), 4);
    $prod = null;
    foreach ((array)(golpos_catalog()['products'] ?? []) as $p) {
        if (is_array($p) && (string)($p['sku'] ?? '') === $sku) { $prod = $p; break; }
    }
    if ($prod === null) { golpos_task_close_by_feed($t); return; }
    $missing = golpos_gap_product_fields($prod);
    if ($missing === []) { golpos_task_close_by_feed($t); return; }
    $changed = false;
    $allDone = true;
    foreach ((array)($t['items'] ?? []) as $i => $it) {
        $f = (string)($it['field'] ?? '');
        if ($f !== '' && !in_array($f, $missing, true) && empty($it['done'])) {
            $t['items'][$i]['done'] = true;
            $t['items'][$i]['done_at'] = date('c');
            $changed = true;
        }
        if (empty($t['items'][$i]['done'])) { $allDone = false; }
    }
    if ($allDone) { golpos_task_close_by_feed($t); return; }
    if ($changed) { golpos_task_put($t); }
}

// --- the cost write ---------------------------------------------------------------
// One door for a cost fill: standalone writes the local catalog's cost
// field (the key is ADDED when absent — the record grows the field the
// bill lane only refreshes); link mode writes the MR.STORE product
// record through the bridge's own load -> edit -> save, behind the
// same fence as every bridge write. False means nothing was written —
// the caller reports it and the gap stays open.
function golpos_gap_write_cost(array $card): bool {
    $sku = (string)($card['sku'] ?? '');
    $cost = round((float)($card['new_cost'] ?? 0), 2);
    if ($sku === '' || $cost <= 0) { return false; }
    if (golpos_link_on()) {
        $prod = null;
        foreach ((array)(golpos_catalog()['products'] ?? []) as $p) {
            if (is_array($p) && (string)($p['sku'] ?? '') === $sku) { $prod = $p; break; }
        }
        if ($prod === null) { return false; }
        return mrstore_set_product_cost($prod, $cost) === true;
    }
    $cat = golpos_catalog();
    foreach ((array)($cat['products'] ?? []) as $i => $p) {
        if ((string)($p['sku'] ?? '') === $sku) {
            $cat['products'][$i]['cost'] = $cost;
            golpos_save_catalog($cat);
            return true;
        }
    }
    return false;
}

// --- GIVE: the idle prompt ---------------------------------------------------------
function golpos_gap_snooze(string $key, string $email): void {
    if ($email === '') { return; }
    $st = golpos_gap_state();
    $st['snooze'][$key . '|' . $email] = date('c');
    golpos_gap_save_state($st);
}

// At most one prompt, or null. The client reports the till's truth —
// cart size and idle seconds — and the server enforces the calm:
// no prompt with a cart open, none inside the first idle minute, at
// most two per person per day, never two inside twenty minutes, never
// an item under a don't-know backoff, never an item she snoozed this
// shift (a snooze dies at her next unlock). Arming the wait
// (session gap_await) and logging the prompt happen together, so the
// bubble in her chat and the brain's ear are the same act.
function golpos_gap_prompt_for(string $sessionId, string $email, int $cartCount, int $idleSecs): ?array {
    if ($cartCount > 0 || $idleSecs < 60 || $email === '') { return null; }
    // Never arm over a staged card (the confirm word owns the till) and
    // never re-arm an unanswered wait — one ask in flight, always.
    if (golpos_pending() !== null) { return null; }
    $sessNow = golpos_session($sessionId);
    if (is_array($sessNow['gap_await'] ?? null)
        && (strtotime((string)($sessNow['gap_await']['at'] ?? '')) ?: 0) > time() - 1800) { return null; }
    $st = golpos_gap_state();
    $today = date('Y-m-d');
    $mineToday = 0;
    $lastMine = 0;
    foreach ($st['prompts'] as $pr) {
        if (($pr['email'] ?? '') !== $email) { continue; }
        if (substr((string)($pr['at'] ?? ''), 0, 10) === $today) { $mineToday++; }
        $ts = strtotime((string)($pr['at'] ?? '')) ?: 0;
        if ($ts > $lastMine) { $lastMine = $ts; }
    }
    if ($mineToday >= 2) { return null; }
    if ($lastMine > 0 && time() - $lastMine < 1200) { return null; }

    $cands = [];
    foreach (golpos_tasks_for($email) as $t) {
        if (($t['kind'] ?? '') !== 'price_gap' || golpos_task_status($t) === 'done'
            || ($t['status'] ?? '') === 'superseded') { continue; }
        foreach ((array)($t['items'] ?? []) as $it) {
            if (!empty($it['done']) || empty($it['field'])) { continue; }
            $cands[] = ['task_id' => (string)$t['id'], 'subject' => (string)($t['subject'] ?? ''),
                        'field' => (string)$it['field']];
        }
    }
    if (!$cands) { return null; }
    usort($cands, static fn($a, $b) =>
        ($a['field'] === 'price' ? 0 : 1) <=> ($b['field'] === 'price' ? 0 : 1));

    $emp = golpos_employee($email);
    $lastUnlockTs = 0;
    if ($emp !== null) {
        $lu = golpos_presence_for($emp)['last_unlock_at'] ?? null;
        $lastUnlockTs = $lu ? (strtotime((string)$lu) ?: 0) : 0;
    }
    $catalog = golpos_catalog()['products'] ?? [];
    foreach ($cands as $c) {
        $sku = substr($c['subject'], 4);
        $key = $sku . '|' . $c['field'];
        $g = $st['gaps'][$key] ?? null;
        if (is_array($g) && !empty($g['dontknow_until'])
            && (strtotime((string)$g['dontknow_until']) ?: 0) > time()) { continue; }
        $snz = $st['snooze'][$key . '|' . $email] ?? null;
        if ($snz !== null) {
            $snzTs = strtotime((string)$snz) ?: 0;
            if ($snzTs > $lastUnlockTs && time() - $snzTs < 12 * 3600) { continue; }
        }
        $name = $sku;
        foreach ((array)$catalog as $p) {
            if (is_array($p) && (string)($p['sku'] ?? '') === $sku) {
                $name = (string)($p['name'] ?? $sku);
                break;
            }
        }
        $s = golpos_session($sessionId);
        $s['gap_await'] = ['sku' => $sku, 'name' => $name, 'field' => $c['field'],
                           'task_id' => $c['task_id'], 'email' => $email, 'at' => date('c')];
        golpos_save_session($sessionId, $s);
        $st['prompts'][] = ['email' => $email, 'key' => $key, 'at' => date('c')];
        if (count($st['prompts']) > 200) { $st['prompts'] = array_slice($st['prompts'], -200); }
        if (isset($st['gaps'][$key]) && is_array($st['gaps'][$key])) {
            $st['gaps'][$key]['last_prompt_at'] = date('c');
            $st['gaps'][$key]['prompt_count'] = (int)($st['gaps'][$key]['prompt_count'] ?? 0) + 1;
        }
        golpos_gap_save_state($st);
        $text = $c['field'] === 'price'
            ? 'Free chau jasto chha — euta sano kam: ' . $name . ' ko shelf price record ma chhaina. '
              . 'Shelf tag ma kati lekheko chha? Thaha chhaina bhane "thaha chhaina" bhana — kei hudaina.'
            : 'Free chau jasto chha — euta sano kam: ' . $name . ' ko cost price kunai bill ma pani aayena. '
              . 'Hami teslai kati ma kinchhaun, thaha chha? Chhaina bhane "thaha chhaina" bhana — kei hudaina.';
        return ['reply' => $text, 'sku' => $sku, 'field' => $c['field'], 'name' => $name];
    }
    return null;
}

// Her answer, while a gap wait is armed — the price_await pattern: the
// wait owns the follow-up, and anything that is not an answer drops
// the wait and runs its normal course. Returns the out() payload, or
// null to fall through to the gate and dispatch untouched.
function golpos_gap_await_reply(array $session, string $sessionId, string $msg, array $products): ?array {
    $await = $session['gap_await'] ?? null;
    if (!is_array($await)) { return null; }
    $clear = static function () use ($sessionId, $session): void {
        $s = $session;
        $s['gap_await'] = null;
        golpos_save_session($sessionId, $s);
    };
    if ((strtotime((string)($await['at'] ?? '')) ?: 0) < time() - 1800) { $clear(); return null; }
    if (in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true)) { $clear(); return null; }
    $sku = (string)($await['sku'] ?? '');
    $field = (string)($await['field'] ?? 'price');
    $name = (string)($await['name'] ?? $sku);
    $email = (string)($await['email'] ?? '');
    $key = $sku . '|' . $field;

    if (golpos_is_cancel_intent($msg)) {
        golpos_gap_snooze($key, $email);
        $clear();
        return ['reply' => t('ask_cancelled')];
    }
    // "Thaha chhaina" — a complete answer, never a failure: the gap
    // stays open and the item is not offered to ANYONE for 7 days.
    if (preg_match("/(thaha\\s*(chhaina|chaina)|tha\\s*chhaina|don'?t know|dunno|no idea|थाहा\\s*छैन)/iu", $msg)) {
        $st = golpos_gap_state();
        if (isset($st['gaps'][$key]) && is_array($st['gaps'][$key])) {
            $st['gaps'][$key]['dontknow_until'] = date('c', time() + 7 * 86400);
            golpos_gap_save_state($st);
        }
        $clear();
        return ['reply' => $field === 'cost'
            ? 'Hunchha, kei chhaina — ' . $name . ' ko cost arko bill ma aayo bhane aafai bharinchha. Dhanyabad!'
            : 'Hunchha, kei chhaina — ' . $name . ' ko price owner le pachhi bharchha. Dhanyabad!'];
    }
    if (preg_match('/(pachi|pachhi|later|not now|skip|ahile\\s*hoina|पछि|भोलि)/iu', $msg)) {
        golpos_gap_snooze($key, $email);
        $clear();
        return ['reply' => 'Hunchha — ' . $name . ' ko kura yo shift ma feri sodhdina.'];
    }
    $target = null;
    foreach ($products as $p) {
        if ((string)($p['sku'] ?? '') === $sku) { $target = $p; break; }
    }
    if ($target === null) { $clear(); return null; }
    $ex = golpos_extract_price($msg, $target);
    if (($ex['status'] ?? '') === 'ambiguous') {
        return ['reply' => t('price_ask_which', $name,
                             money((float)($ex['candidates'][0] ?? 0)),
                             money((float)($ex['candidates'][1] ?? 0)))];
    }
    if (($ex['status'] ?? '') !== 'ok') { $clear(); return null; } // a topic change
    $v = round((float)$ex['value'], 2);
    if ($v <= 0 || $v > 9999.99) {
        return ['reply' => $name . ' ko ' . ($field === 'price' ? 'price' : 'cost') . ' '
            . money($v) . ' ali milena — feri bhana ta?'];
    }
    $clear();
    $by = ['by_name' => (string)($session['unlocked_name'] ?? ''), 'by_email' => $email];
    if ($field === 'price') {
        $card = golpos_stage(['type' => 'price_fill', 'sku' => $sku, 'name' => $name,
                              'new_price' => $v, 'gap_field' => 'price'] + $by);
    } else {
        $card = golpos_stage(['type' => 'cost_fill', 'sku' => $sku, 'name' => $name,
                              'new_cost' => $v, 'gap_field' => 'cost'] + $by);
    }
    return ['reply' => t('card_staged'), 'card' => $card];
}

// --- the owner's two windows ------------------------------------------------------
// His admin greeting line, from the records only: today's fills and
// the gaps still waiting. Nothing to report -> no line at all.
function golpos_gap_owner_line(): string {
    $open = golpos_gap_scan();
    $st = golpos_gap_state();
    $filledToday = 0;
    $today = date('Y-m-d');
    foreach ($st['gaps'] as $g) {
        if (($g['status'] ?? '') === 'filled'
            && substr((string)($g['filled_at'] ?? ''), 0, 10) === $today) { $filledToday++; }
    }
    if (!$open && $filledToday === 0) { return ''; }
    return 'Price gaps: ' . $filledToday . ' filled today · ' . count($open)
        . ' waiting (price/cost record missing).';
}

// One line in her unlock brief when a gap waits for her — the promise
// of the nudge, so the idle prompt never arrives as a surprise.
function golpos_gap_brief_line(string $email): string {
    $n = 0;
    foreach (golpos_tasks_for($email) as $t) {
        if (($t['kind'] ?? '') !== 'price_gap' || golpos_task_status($t) === 'done'
            || ($t['status'] ?? '') === 'superseded') { continue; }
        foreach ((array)($t['items'] ?? []) as $it) { if (empty($it['done'])) { $n++; } }
    }
    if ($n === 0) { return ''; }
    return '💲 ' . $n . ' item ko price/cost record khali chha — free bhako bela ma euta sodhchhu, bistarai bharam.';
}
