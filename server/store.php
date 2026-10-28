<?php
// Gol POS — tiny JSON store. One shop, one data dir, boring on purpose.
// Live files live in data/ and are created from the seed on first use.

declare(strict_types=1);

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
function golpos_catalog(): array {
    $live = golpos_read('catalog.live.json', null);
    if ($live === null) {
        $seed = golpos_read('catalog.seed.json', ['store' => [], 'products' => []]);
        golpos_write('catalog.live.json', $seed);
        return $seed;
    }
    return $live;
}

function golpos_save_catalog(array $catalog): void {
    golpos_write('catalog.live.json', $catalog);
}

function golpos_sales(): array {
    return golpos_read('sales.json', []);
}

function golpos_append_sale(array $sale): array {
    $sales = golpos_sales();
    $sale['id'] = 'txn_' . substr(sha1(uniqid('', true)), 0, 10);
    $sale['at'] = date('c');
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
function golpos_close_day(string $ymd): array {
    $rec = golpos_day_summary($ymd);
    $rec['closed_at'] = date('c');
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
    foreach (['show_address', 'show_phone', 'logo', 'show_tax', 'auto_open'] as $k) {
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
