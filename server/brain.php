<?php
// Gol POS — the Gol brain endpoint.
// POST {message} -> {reply, card?, action?}
// POST {confirm:true} executes the staged card (the confirm word is checked client-side
// AND here: a bare "gol" / "गोल" message confirms; this endpoint also accepts the
// register's explicit confirm call for the same card).
//
// Design law: the brain reasons over THIS shop's own live data and composes the
// answer. An exact name wins; a distinctive token wins; a true tie earns ONE
// clarifying question carrying the real prices. Price changes and new items are
// staged as cards and execute only on the confirm word "gol".

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$msg = trim((string)($in['message'] ?? ''));
$catalog = golpos_catalog();
$products = $catalog['products'] ?? [];

function out(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }
function money(float $n): string { return '$' . number_format($n, 2); }

// --- item resolution: exact name > alias > distinctive token > tie -> clarify
// A person hears "Coke" and knows the item: the catalog carries the nicknames
// people actually use, and resolution checks name, aliases, and name tokens.
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
            if (mb_strpos($t, mb_strtolower((string)$a)) !== false) { $aliasHits[] = $p; break; }
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

if ($msg === '') { out(['reply' => 'Kehi lekhnus — saman thapna, mulya sodhna, wa din ko hisab.']); }

// --- the confirm word --------------------------------------------------------
if (in_array(mb_strtolower($msg), ['gol', 'गोल'], true) || !empty($in['confirm'])) {
    $card = golpos_pending();
    if (!$card) { out(['reply' => 'Ahile stage ma kehi chhaina — pahile euta card banauchhu, ani "gol" bhannus.']); }
    if ($card['type'] === 'price_change') {
        foreach ($products as &$p) {
            if ($p['sku'] === $card['sku']) { $p['price'] = $card['new_price']; }
        }
        unset($p);
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => $card['name'] . ' ko mulya ' . money((float)$card['old_price']) . ' bata ' . money((float)$card['new_price']) . ' bhayo.', 'done' => $card]);
    }
    if ($card['type'] === 'new_item') {
        $sku = (string)(1000 + count($products) + 1);
        $products[] = ['sku' => $sku, 'barcode' => $card['barcode'] ?? '', 'name' => $card['name'],
                       'category' => $card['category'] ?? 'Counter', 'price' => $card['price']];
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => 'Naya saman thapiyo: ' . $card['name'] . ' — ' . money((float)$card['price']) . '.', 'done' => $card]);
    }
    out(['reply' => 'Yo card execute garna milena.']);
}

// --- day total / close -------------------------------------------------------
if (preg_match('/(kati bikri|aaja ko|today|close|banda|hisab|sales)/iu', $msg)) {
    $s = golpos_day_summary(date('Y-m-d'));
    if ($s['txns'] === 0) {
        out(['reply' => 'Aaja ahile samma bikri record chhaina. Pahilo sale register bata garnus, ani hisab dinchhu.']);
    }
    out(['reply' => 'Aaja ko hisab: jamma ' . money($s['gross']) . ' (' . $s['txns'] . ' ota sale) — cash ' .
                    money($s['cash']) . ', card ' . money($s['card']) . '.']);
}

// --- price change (staged) ---------------------------------------------------
if (preg_match('/(\d+(?:\.\d{1,2})?)/', $msg, $m) &&
    preg_match('/(mulya|price|dam).*(gara|set|badha|rakha|change)|(gara|set|change).*(mulya|price)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        $new = (float)$m[1];
        if ($new === (float)$p['price']) { out(['reply' => $p['name'] . ' ko mulya pahile nai ' . money($new) . ' chha.']); }
        $card = golpos_stage(['type' => 'price_change', 'sku' => $p['sku'], 'name' => $p['name'],
                              'old_price' => $p['price'], 'new_price' => $new]);
        out(['reply' => 'Card baneko chhu — pakka garna "gol" bhannus.', 'card' => $card]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => 'Kun ko mulya badalne? ' . $list]);
    }
    out(['reply' => 'Tyo saman bhettina — naam feri bhannus, wa naya saman ko card banauchhu.']);
}

// --- price query --------------------------------------------------------------
if (preg_match('/(kati|price|mulya|dam|how much)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) { out(['reply' => $r['one']['name'] . ' — ' . money((float)$r['one']['price']) . '.']); }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => 'Kun khojeko? ' . $list]);
    }
    out(['reply' => 'Tyo naam ko saman hamro list ma chhaina. Naya saman ko card banaidim? Naam ra mulya bhannus.']);
}

// --- add to cart ---------------------------------------------------------------
if (preg_match('/(thap|add|ek |one |chahiyo|give)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        out(['reply' => $r['one']['name'] . ' (' . money((float)$r['one']['price']) . ') cart ma thapiyo.',
             'action' => ['type' => 'add', 'sku' => $r['one']['sku']]]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => 'Kun thapne? ' . $list]);
    }
    // unknown item -> offer a staged new-item card when a price is present
    if (preg_match('/(\d+(?:\.\d{1,2})?)/', $msg, $m)) {
        out(['reply' => 'Yo saman list ma chhaina. Mulya ' . money((float)$m[1]) . ' sath naya card banaidim? Naam spashta bhannus.']);
    }
    out(['reply' => 'Tyo saman bhettina — naam arkai gari bhannus hernus.']);
}

out(['reply' => 'Ma saman thapna, mulya sodhna/badalna (card stage garera), ra din ko hisab dina sakchhu. Ek wata prayas garnus: "Coke ko mulya kati?"']);
