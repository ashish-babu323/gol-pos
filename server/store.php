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
