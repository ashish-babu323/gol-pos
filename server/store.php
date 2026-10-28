<?php
// Gol POS — tiny JSON store. One shop, one data dir, boring on purpose.
// Live files live in data/ and are created from the seed on first use.

declare(strict_types=1);

require_once __DIR__ . '/mrstore.php';

function golpos_data_dir(): string {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    return $dir;
}

function golpos_read(string $name, $fallback) {
    $path = golpos_data_dir() . '/' . $name;
    if (!file_exists($path)) { return $fallback; }
    $raw = file_get_contents($path);
    $data = json_decode($raw === false ? '' : $raw, true);
    return $data === null ? $fallback : $data;
}

function golpos_write(string $name, $data): void {
    $path = golpos_data_dir() . '/' . $name;
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

// The live catalog starts as a copy of the seed so staged writes never touch the seed.
// LINK MODE (MR.STORE connected): the catalog IS the linked store's live
// catalog — names, prices and on-hand counts from MR.STORE itself. The
// local seed/live files are not consulted at all; if the link is down the
// register falls back to them so it never stops ringing.
function golpos_catalog(): array {
    if (golpos_link_on()) {
        $linked = mrstore_catalog_with_stock();
        if ($linked !== null) { return $linked; }
    }
    $live = golpos_read('catalog.live.json', null);
    if ($live === null) {
        $seed = golpos_read('catalog.seed.json', ['store' => [], 'products' => []]);
        golpos_write('catalog.live.json', $seed);
        return $seed;
    }
    return $live;
}

function golpos_save_catalog(array $catalog): void {
    if (golpos_link_on()) {
        // In link mode the catalog lives in MR.STORE: owner price changes
        // and new items sync there; the local file is never written.
        mrstore_sync_catalog($catalog);
        return;
    }
    golpos_write('catalog.live.json', $catalog);
}

function golpos_sales(): array {
    return golpos_read('sales.json', []);
}

// $at lets the simulation runner record a sale at its simulated moment;
// the register always passes null and stamps the real time.
function golpos_append_sale(array $sale, ?string $at = null): array {
    $sales = golpos_sales();
    $sale['id'] = 'txn_' . substr(sha1(uniqid('', true)), 0, 10);
    $sale['at'] = $at ?? date('c');
    $sales[] = $sale;
    golpos_write('sales.json', $sales);
    return $sale;
}

// One staged card at a time — the register shows it, the confirm word executes it.
function golpos_pending(): ?array {
    return golpos_read('pending.json', null);
}

function golpos_stage(array $card): array {
    $card['id'] = 'card_' . substr(sha1(uniqid('', true)), 0, 8);
    $card['staged_at'] = date('c');
    golpos_write('pending.json', $card);
    return $card;
}

function golpos_clear_pending(): void {
    $path = golpos_data_dir() . '/pending.json';
    if (file_exists($path)) { unlink($path); }
}

// --- item resolution: exact name > alias > distinctive token > tie -> clarify
// A person hears "Coke" and knows the item: the catalog carries the nicknames
// people actually use, and resolution checks name, aliases, and name tokens.
// Shared by the brain and the photo lane.
function resolve_item(array $products, string $text): array {
    $t = mb_strtolower($text);
    $hits = [];
    foreach ($products as $p) {
        $name = mb_strtolower($p['name']);
        if (mb_strpos($t, $name) !== false) { return ['one' => $p]; }
    }
    // A nickname shared by several products (chips, beer) is a tie, not a first-match win.
    $aliasHits = [];
    foreach ($products as $p) {
        foreach (($p['aliases'] ?? []) as $a) {
            $a = (string)$a;
            if ($a === '') { continue; }
            // Latin aliases match at a word start, so "pani" never fires
            // inside "company" and "dew" never inside "dewalt"; a trailing
            // plural s still counts ("bananas"). Devanagari aliases stay
            // substring matches — postpositions attach to the word
            // (कोकको) and must still resolve.
            $hit = preg_match('/[\x{0900}-\x{097F}]/u', $a)
                ? (mb_strpos($t, mb_strtolower($a)) !== false)
                : (preg_match('/\b' . preg_quote(mb_strtolower($a), '/') . '(?:s|es)?\b/u', $t) === 1);
            if ($hit) { $aliasHits[] = $p; break; }
        }
    }
    if (count($aliasHits) === 1) { return ['one' => $aliasHits[0]]; }
    if (count($aliasHits) > 1) { return ['many' => $aliasHits]; }
    foreach ($products as $p) {
        $score = 0;
        $tokens = preg_split('/[^a-z0-9]+/u', mb_strtolower($p['name'] . ' ' . implode(' ', $p['aliases'] ?? [])));
        foreach ($tokens as $tok) {
            $tok = trim((string)$tok);
            if (mb_strlen($tok) >= 3 && preg_match('/\b' . preg_quote($tok, '/') . '\b/u', $t)) { $score++; }
        }
        if ($score > 0) { $hits[] = ['p' => $p, 's' => $score]; }
    }
    if (!$hits) { return ['none' => true]; }
    usort($hits, fn($a, $b) => $b['s'] <=> $a['s']);
    $top = $hits[0]['s'];
    $best = array_values(array_filter($hits, fn($h) => $h['s'] === $top));
    if (count($best) === 1) { return ['one' => $best[0]['p']]; }
    return ['many' => array_map(fn($h) => $h['p'], $best)];
}

// The day-close record: closing the day freezes the summary into ONE saved
// record per date (a re-close refreshes it) that the owner view reads back.
// The record composes WITH batch state: the batches sealed that day and any
// card sales still unbatched, so a close can never silently skip a batch.
function golpos_close_day(string $ymd): array {
    $rec = golpos_day_summary($ymd);
    $rec['closed_at'] = date('c');
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
    return $rec;
}

function golpos_day_summary(string $ymd): array {
    $gross = 0.0; $cash = 0.0; $card = 0.0; $count = 0;
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $total = (float)($s['total'] ?? 0);
        $gross += $total; $count++;
        if (($s['tender'] ?? '') === 'cash') { $cash += $total; } else { $card += $total; }
    }
    return ['date' => $ymd, 'gross' => round($gross, 2), 'cash' => round($cash, 2),
            'card' => round($card, 2), 'txns' => $count];
}

// The saved close record for one date, if the day was closed (chat or panel).
function golpos_close_record(string $ymd): ?array {
    foreach (golpos_read('closes.json', []) as $c) {
        if (($c['date'] ?? '') === $ymd) { return $c; }
    }
    return null;
}

// --- receipt print settings: ONE store, TWO doors ----------------------------
// The Gol chat stages changes as cards (executed on "gol"); the owner panel
// edits the same fields in a form. Both read and write THIS one settings
// store, so the two doors can never disagree — there is no settings menu
// anywhere else. The receipt view renders from these values.
function golpos_settings_defaults(): array {
    return [
        'store_name'   => 'Sunrise Mart (demo)',
        'address'      => '123 Demo Street, Cleveland, OH 44113',
        'show_address' => true,
        'phone'        => '(216) 555-0100',
        'show_phone'   => true,
        'logo'         => true,
        'footer'       => 'Thank you — visit again!',
        'copies'       => 1,
        'paper_width'  => '80mm',
        'show_tax'     => true,
        'auto_open'    => true,
        // Store hours + card-batch mode (owner directive, Oct 28): the batch
        // close is manual by default; in auto mode the day's card batch seals
        // itself at the store's closing time (see golpos_batch_auto_sweep).
        'store_open'   => '08:00',
        'store_close'  => '21:00',
        'batch_mode'   => 'manual',
        // Register open/closed (owner directive, Oct 28): the Settings
        // dropdown's Store toggle. Closed pauses NEW sales — the register
        // blocks the tap and the sale endpoint refuses the POST — while
        // chat, browsing and the owner panel keep working.
        'register_open' => true,
    ];
}

function golpos_settings(): array {
    $saved = golpos_read('settings.json', []);
    return array_merge(golpos_settings_defaults(), is_array($saved) ? $saved : []);
}

// Whitelist + validate, then persist. Unknown keys and out-of-range values
// are ignored rather than stored — a settings file is never a junk drawer.
function golpos_save_settings(array $in): array {
    $cur = golpos_settings();
    foreach (['show_address', 'show_phone', 'logo', 'show_tax', 'auto_open', 'register_open'] as $k) {
        if (array_key_exists($k, $in)) { $cur[$k] = (bool)$in[$k]; }
    }
    foreach (['store_name' => 60, 'address' => 120, 'phone' => 40, 'footer' => 120] as $k => $max) {
        if (isset($in[$k]) && is_string($in[$k])) { $cur[$k] = mb_substr(trim($in[$k]), 0, $max); }
    }
    if (isset($in['copies'])) {
        $c = (int)$in['copies'];
        if ($c >= 1 && $c <= 3) { $cur['copies'] = $c; }
    }
    if (isset($in['paper_width']) && in_array($in['paper_width'], ['58mm', '80mm'], true)) {
        $cur['paper_width'] = $in['paper_width'];
    }
    if (isset($in['batch_mode']) && in_array($in['batch_mode'], ['manual', 'auto'], true)) {
        $cur['batch_mode'] = $in['batch_mode'];
    }
    foreach (['store_open', 'store_close'] as $k) {
        if (isset($in[$k]) && is_string($in[$k])
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($in[$k]))) {
            $cur[$k] = trim($in[$k]);
        }
    }
    golpos_write('settings.json', $cur);
    return $cur;
}

// --- Receipt Loyalty (owner addition, Oct 26) -------------------------------
// The customer joins once (name / phone / email), then sends PHOTOS of their
// receipts; Gol vision-reads each one, saves it under the customer (phone =
// master key), dedupes by receipt number per store, and the owner sees a
// live leaderboard built from the saved receipts — loyalty with no app, no
// card, no counter sheet. Loyalty lives in its OWN store file, seeded empty,
// and never touches the catalog seed or the sales data formats.
function golpos_loyalty(): array {
    $d = golpos_read('loyalty.json', null);
    if (!is_array($d) || !isset($d['customers'], $d['receipts'])
        || !is_array($d['customers']) || !is_array($d['receipts'])) {
        return ['customers' => [], 'receipts' => []];
    }
    return $d;
}

// Phone is the master key: digits only, and a leading US country code folds
// away so "+1 (216) 555-0142" and "2165550142" are the same customer.
function golpos_loyalty_phone_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 11 && $d[0] === '1') { $d = substr($d, 1); }
    return $d;
}

function golpos_loyalty_customer(string $phone): ?array {
    $key = golpos_loyalty_phone_key($phone);
    if ($key === '') { return null; }
    foreach (golpos_loyalty()['customers'] as $c) {
        if (($c['phone'] ?? '') === $key) { return $c; }
    }
    return null;
}

// Join once; joining again with the same phone refreshes name/email and
// keeps every receipt already counted.
function golpos_loyalty_register(string $name, string $phone, string $email): array {
    $key = golpos_loyalty_phone_key($phone);
    $d = golpos_loyalty();
    foreach ($d['customers'] as $i => $c) {
        if (($c['phone'] ?? '') === $key) {
            $d['customers'][$i]['name'] = $name;
            $d['customers'][$i]['email'] = $email;
            golpos_write('loyalty.json', $d);
            return $d['customers'][$i];
        }
    }
    $c = ['name' => $name, 'phone' => $key, 'email' => $email, 'joined' => date('c')];
    $d['customers'][] = $c;
    golpos_write('loyalty.json', $d);
    return $c;
}

// A receipt counts once: the same receipt number at the same store is the
// same receipt, whoever sends the photo. Numbers compare without spacing
// or punctuation ("SM-10482" and "SM 10482" are one receipt).
function golpos_loyalty_receipt_key(string $store, string $receiptNo): string {
    $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
    return $norm($store) . '|' . $norm($receiptNo);
}

function golpos_loyalty_receipt_seen(string $store, string $receiptNo): ?array {
    $k = golpos_loyalty_receipt_key($store, $receiptNo);
    foreach (golpos_loyalty()['receipts'] as $r) {
        if (golpos_loyalty_receipt_key((string)($r['store'] ?? ''), (string)($r['receipt_no'] ?? '')) === $k) {
            return $r;
        }
    }
    return null;
}

function golpos_loyalty_add_receipt(string $phone, array $receipt): array {
    $d = golpos_loyalty();
    $receipt['id'] = 'rcpt_' . substr(sha1(uniqid('', true)), 0, 10);
    $receipt['phone'] = golpos_loyalty_phone_key($phone);
    $receipt['at'] = date('c');
    $d['receipts'][] = $receipt;
    golpos_write('loyalty.json', $d);
    return $receipt;
}

// The leaderboard is COMPUTED from the saved receipts on every read, so the
// owner view can never drift from what customers were told.
function golpos_loyalty_board(): array {
    $d = golpos_loyalty();
    $rows = [];
    foreach ($d['customers'] as $c) {
        $total = 0.0; $count = 0;
        foreach ($d['receipts'] as $r) {
            if (($r['phone'] ?? '') === ($c['phone'] ?? '')) {
                $total += (float)($r['total'] ?? 0);
                $count++;
            }
        }
        $rows[] = ['name' => (string)($c['name'] ?? ''), 'phone' => (string)($c['phone'] ?? ''),
                   'total' => round($total, 2), 'receipts' => $count];
    }
    usort($rows, static fn($a, $b) => ($b['total'] <=> $a['total']) ?: ($b['receipts'] <=> $a['receipts']));
    foreach ($rows as $i => &$row) { $row['rank'] = $i + 1; }
    unset($row);
    return $rows;
}

// One customer's standing: running total, receipt count, rank, field size.
function golpos_loyalty_standing(string $phone): ?array {
    $key = golpos_loyalty_phone_key($phone);
    $board = golpos_loyalty_board();
    foreach ($board as $row) {
        if ($row['phone'] === $key) { $row['of'] = count($board); return $row; }
    }
    return null;
}

// --- People & access (owner directive, Oct 28) -------------------------------
// Two roles live in data/people.json: the ADMIN (one PIN hash — the PIN is
// the key to every admin-domain answer in the Gol chat) and EMPLOYEES, who
// are invited by email, set their own PIN from a one-time link, and hold the
// cashier domain only. PINs are never stored in plain text and never leave
// the server: password_hash / password_verify throughout.
function golpos_people(): array {
    $d = golpos_read('people.json', null);
    if (!is_array($d)) { return ['admin' => null, 'employees' => []]; }
    if (!array_key_exists('admin', $d)) { $d['admin'] = null; }
    if (!isset($d['employees']) || !is_array($d['employees'])) { $d['employees'] = []; }
    return $d;
}

function golpos_save_people(array $d): void {
    golpos_write('people.json', $d);
}

function golpos_admin_pin_hash(): ?string {
    $h = golpos_people()['admin']['pin_hash'] ?? null;
    return is_string($h) && $h !== '' ? $h : null;
}

function golpos_set_admin_pin(string $pinHash): void {
    $d = golpos_people();
    $d['admin'] = ['pin_hash' => $pinHash, 'set_at' => date('c')];
    golpos_save_people($d);
}

function golpos_norm_email(string $email): string {
    return mb_strtolower(trim($email));
}

// A PIN as typed at a prompt: Devanagari digits normalized, and valid only
// as exactly 4–6 digits. Returns the normalized digits or null.
function golpos_norm_pin(string $pin): ?string {
    $d = strtr(trim($pin), ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
                            '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    return preg_match('/^\d{4,6}$/', $d) ? $d : null;
}

function golpos_employee(string $email): ?array {
    $key = golpos_norm_email($email);
    foreach (golpos_people()['employees'] as $e) {
        if (($e['email'] ?? '') === $key) { return $e; }
    }
    return null;
}

// The one-time invite link carries the raw token; the store keeps only its
// SHA-256, so a leaked data file never hands out a working invite.
function golpos_employee_by_invite_token(string $token): ?array {
    if ($token === '') { return null; }
    $hash = hash('sha256', $token);
    foreach (golpos_people()['employees'] as $e) {
        $h = (string)($e['invite_token_hash'] ?? '');
        if ($h !== '' && hash_equals($h, $hash)) { return $e; }
    }
    return null;
}

// After the invite, the employee's device holds a device token (again only
// its SHA-256 is stored). Presenting it with chat calls identifies the
// session as that employee — cashier domain; it never elevates to admin.
function golpos_employee_by_device(string $email, string $token): ?array {
    if ($token === '') { return null; }
    $e = golpos_employee($email);
    if ($e === null || ($e['status'] ?? '') !== 'active') { return null; }
    $h = (string)($e['device_token_hash'] ?? '');
    return ($h !== '' && hash_equals($h, hash('sha256', $token))) ? $e : null;
}

function golpos_save_employee(array $emp): void {
    $d = golpos_people();
    foreach ($d['employees'] as $i => $e) {
        if (($e['email'] ?? '') === ($emp['email'] ?? '')) {
            $d['employees'][$i] = $emp;
            golpos_save_people($d);
            return;
        }
    }
    $d['employees'][] = $emp;
    golpos_save_people($d);
}

function golpos_remove_employee(string $email): bool {
    $key = golpos_norm_email($email);
    $d = golpos_people();
    $before = count($d['employees']);
    $d['employees'] = array_values(array_filter(
        $d['employees'], static fn($e) => ($e['email'] ?? '') !== $key));
    if (count($d['employees']) === $before) { return false; }
    golpos_save_people($d);
    return true;
}

// --- Chat sessions -----------------------------------------------------------
// The register client holds a session id (localStorage) and sends it with
// every chat call. Elevation, the PIN-flow stage, failed attempts and the
// intent waiting behind a PIN demand all live here, server-side, per
// session — the browser never decides who is admin.
function golpos_session(string $id): array {
    $all = golpos_read('sessions.json', []);
    $s = (is_array($all) && isset($all[$id]) && is_array($all[$id])) ? $all[$id] : [];
    return array_merge([
        'admin' => false, 'pin_stage' => null, 'pin_tmp' => null,
        'attempts' => 0, 'locked_until' => 0, 'pending' => null, 'employee' => null,
    ], $s);
}

function golpos_save_session(string $id, array $s): void {
    $all = golpos_read('sessions.json', []);
    if (!is_array($all)) { $all = []; }
    $all[$id] = $s;
    golpos_write('sessions.json', $all);
}

// --- Card batches (owner directive, Oct 28) ----------------------------------
// A batch close seals the card sales rung since the last batch into ONE
// numbered record (data/batches.json) — the POS twin of the card terminal's
// batch-out. Cash never batches. A sale belongs to a batch when its id is in
// the batch's sale_ids, so "unbatched" is always computed, never flagged by
// hand, and a segment can never be sealed twice.
function golpos_batches(): array {
    $b = golpos_read('batches.json', []);
    return is_array($b) ? $b : [];
}

function golpos_unbatched_card_sales(?string $ymd = null): array {
    $sealed = [];
    foreach (golpos_batches() as $b) {
        foreach (($b['sale_ids'] ?? []) as $id) { $sealed[(string)$id] = true; }
    }
    $out = [];
    foreach (golpos_sales() as $s) {
        if (($s['tender'] ?? '') !== 'card') { continue; }
        if (isset($sealed[(string)($s['id'] ?? '')])) { continue; }
        if ($ymd !== null && substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $out[] = $s;
    }
    return $out;
}

function golpos_seal_batch(array $sales, string $closedBy, ?string $closeTime = null): array {
    $batches = golpos_batches();
    $total = 0.0;
    foreach ($sales as $s) { $total += (float)($s['total'] ?? 0); }
    $rec = [
        'n' => count($batches) + 1,
        'date' => date('Y-m-d'),
        'sealed_at' => date('c'),
        'closed_by' => $closedBy,
        'close_time' => $closeTime,
        'count' => count($sales),
        'total' => round($total, 2),
        'from' => $sales ? (string)($sales[0]['at'] ?? '') : '',
        'to' => $sales ? (string)($sales[count($sales) - 1]['at'] ?? '') : '',
        'sale_ids' => array_map(static fn($s) => (string)($s['id'] ?? ''), $sales),
    ];
    $batches[] = $rec;
    golpos_write('batches.json', $batches);
    return $rec;
}

function golpos_batches_on(string $ymd): array {
    return array_values(array_filter(
        golpos_batches(), static fn($b) => ($b['date'] ?? '') === $ymd));
}

// AUTO batch mode, evaluated lazily: the POS is request-driven PHP with no
// daemon, so every API/chat call first asks this — if the store is in auto
// mode, today's close time has passed, and an unsealed card segment exists
// for today, the segment seals itself as the next batch (closed_by "auto",
// the close time recorded). At most ONE auto batch per day; a manual close
// earlier that day leaves nothing for the pass; cash sales never batch.
function golpos_batch_auto_sweep(): ?array {
    $st = golpos_settings();
    if (($st['batch_mode'] ?? 'manual') !== 'auto') { return null; }
    $today = date('Y-m-d');
    foreach (golpos_batches() as $b) {
        if (($b['date'] ?? '') === $today && ($b['closed_by'] ?? '') === 'auto') { return null; }
    }
    $close = (string)($st['store_close'] ?? '21:00');
    // Past means past: compare against today's close as a full timestamp,
    // so 9:00:30 PM is already past a 9:00 PM close (a minute-granularity
    // string compare would hold the seal until 9:01).
    $closeTs = strtotime($today . ' ' . $close);
    if ($closeTs === false || time() <= $closeTs) { return null; }
    $seg = golpos_unbatched_card_sales($today);
    if (!$seg) { return null; }
    return golpos_seal_batch($seg, 'auto', $close);
}
