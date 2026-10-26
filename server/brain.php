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
//
// Language law (owner, Oct 26): Gol's default language is ENGLISH — intros and
// every default reply. Gol switches to Nepali / Hindi / any other language ONLY
// after the user speaks it, and mirrors that user's language from then on in the
// reply. Detection is per-message: Devanagari script -> Devanagari Nepali;
// Romanized Nepali markers -> Romanized Nepali; Romanized Hindi markers ->
// Romanized Hindi; anything else -> English.

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$msg = trim((string)($in['message'] ?? ''));
$catalog = golpos_catalog();
$products = $catalog['products'] ?? [];

function out(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }
function money(float $n): string { return '$' . number_format($n, 2); }

// --- language: English by default; mirror the user only when they switch -----
function golpos_lang(string $msg): string {
    if (preg_match('/[\x{0900}-\x{097F}]/u', $msg)) { return 'dev'; }
    $t = ' ' . mb_strtolower($msg) . ' ';
    $np = [' kati ', ' thap ', ' thapnus ', ' bhannus ', ' mulya ', ' hisab ', ' aaja ', ' saman ',
           ' chha ', ' badala ', ' badal ', ' rakha ', ' kun ', ' tyo ', ' naya ', ' bikri ',
           ' chahinchha ', ' gara ', ' dinus ', ' lekha '];
    $hi = [' kitna ', ' kitne ', ' daam ', ' jodo ', ' jod ', ' lagao ', ' aaj ', ' saaman ',
           ' hai ', ' karo ', ' badlo ', ' bikri ', ' hisaab ', ' ka ', ' ki ', ' mein '];
    $n = 0; foreach ($np as $w) { if (mb_strpos($t, $w) !== false) { $n++; } }
    $h = 0; foreach ($hi as $w) { if (mb_strpos($t, $w) !== false) { $h++; } }
    if ($n === 0 && $h === 0) { return 'en'; }
    return $n >= $h ? 'np' : 'hi';
}

$L = golpos_lang($msg);
$S = [
  'empty' => [
    'en' => 'Type something — add an item, ask a price, or ask for today\'s total.',
    'np' => 'Kehi lekhnus — saman thapna, mulya sodhna, wa din ko hisab.',
    'hi' => 'Kuchh likhiye — saaman jodna, daam poochhna, ya aaj ka hisaab.',
    'dev' => 'केही लेख्नुस् — सामान थप्न, मूल्य सोध्न, वा दिनको हिसाब।'],
  'no_pending' => [
    'en' => 'Nothing is staged right now — first let me make a card, then say "gol".',
    'np' => 'Ahile stage ma kehi chhaina — pahile euta card banauchhu, ani "gol" bhannus.',
    'hi' => 'Abhi kuchh stage nahi hai — pehle ek card banata hoon, phir "gol" kahiye.',
    'dev' => 'अहिले स्टेजमा केही छैन — पहिले एउटा कार्ड बनाउँछु, अनि "गोल" भन्नुस्।'],
  'price_done' => [
    'en' => '%s price changed from %s to %s.',
    'np' => '%s ko mulya %s bata %s bhayo.',
    'hi' => '%s ka daam %s se %s ho gaya.',
    'dev' => '%s को मूल्य %s बाट %s भयो।'],
  'item_added' => [
    'en' => 'New item added: %s — %s.',
    'np' => 'Naya saman thapiyo: %s — %s.',
    'hi' => 'Naya saaman joda gaya: %s — %s.',
    'dev' => 'नयाँ सामान थपियो: %s — %s।'],
  'card_fail' => [
    'en' => 'That card could not be executed.',
    'np' => 'Yo card execute garna milena.',
    'hi' => 'Yeh card execute nahi ho paya.',
    'dev' => 'यो कार्ड कार्यान्वयन गर्न मिलेन।'],
  'day_none' => [
    'en' => 'No sales recorded yet today. Ring the first sale on the register, then I\'ll give you the total.',
    'np' => 'Aaja ahile samma bikri record chhaina. Pahilo sale register bata garnus, ani hisab dinchhu.',
    'hi' => 'Aaj abhi tak koi bikri record nahi hai. Pehli sale register se kijiye, phir hisaab dunga.',
    'dev' => 'आज अहिलेसम्म बिक्री रेकर्ड छैन। पहिलो बिक्री रजिस्टरबाट गर्नुस्, अनि हिसाब दिन्छु।'],
  'day_total' => [
    'en' => 'Today so far: %s total (%d sales) — cash %s, card %s.',
    'np' => 'Aaja ko hisab: jamma %s (%d ota sale) — cash %s, card %s.',
    'hi' => 'Aaj ka hisaab: kul %s (%d sale) — cash %s, card %s.',
    'dev' => 'आजको हिसाब: जम्मा %s (%d वटा बिक्री) — नगद %s, कार्ड %s।'],
  'price_same' => [
    'en' => '%s is already priced at %s.',
    'np' => '%s ko mulya pahile nai %s chha.',
    'hi' => '%s ka daam pehle se hi %s hai.',
    'dev' => '%s को मूल्य पहिल्यै %s छ।'],
  'card_staged' => [
    'en' => 'Card ready — say "gol" to confirm.',
    'np' => 'Card baneko chhu — pakka garna "gol" bhannus.',
    'hi' => 'Card taiyaar hai — pakka karne ke liye "gol" kahiye.',
    'dev' => 'कार्ड बनेको छ — पक्का गर्न "गोल" भन्नुस्।'],
  'which_price' => [
    'en' => 'Which one should I change? %s',
    'np' => 'Kun ko mulya badalne? %s',
    'hi' => 'Kis ka daam badloon? %s',
    'dev' => 'कुनको मूल्य बदल्ने? %s'],
  'not_found_price' => [
    'en' => 'I couldn\'t find that item — say the name again, or I\'ll make a new-item card.',
    'np' => 'Tyo saman bhettina — naam feri bhannus, wa naya saman ko card banauchhu.',
    'hi' => 'Woh saaman nahi mila — naam phir se kahiye, ya naya card banata hoon.',
    'dev' => 'त्यो सामान भेटिन — नाम फेरि भन्नुस्, वा नयाँ सामानको कार्ड बनाउँछु।'],
  'which_one' => [
    'en' => 'Which one do you mean? %s',
    'np' => 'Kun khojeko? %s',
    'hi' => 'Kaun sa chahiye? %s',
    'dev' => 'कुन खोजेको? %s'],
  'not_in_list' => [
    'en' => 'That item isn\'t in our list. Shall I make a new-item card? Give me the name and price.',
    'np' => 'Tyo naam ko saman hamro list ma chhaina. Naya saman ko card banaidim? Naam ra mulya bhannus.',
    'hi' => 'Us naam ka saaman hamari list mein nahi hai. Naya card bana doon? Naam aur daam bataiye.',
    'dev' => 'त्यो नामको सामान हाम्रो सूचीमा छैन। नयाँ सामानको कार्ड बनाइदिऊँ? नाम र मूल्य भन्नुस्।'],
  'added_cart' => [
    'en' => '%s (%s) added to the cart.',
    'np' => '%s (%s) cart ma thapiyo.',
    'hi' => '%s (%s) cart mein jod diya.',
    'dev' => '%s (%s) कार्टमा थपियो।'],
  'which_add' => [
    'en' => 'Which one should I add? %s',
    'np' => 'Kun thapne? %s',
    'hi' => 'Kaun sa jodoon? %s',
    'dev' => 'कुन थप्ने? %s'],
  'new_card_offer' => [
    'en' => 'That item isn\'t in the list. Shall I make a new-item card at %s? Give me the exact name.',
    'np' => 'Yo saman list ma chhaina. Mulya %s sath naya card banaidim? Naam spashta bhannus.',
    'hi' => 'Yeh saaman list mein nahi hai. %s par naya card bana doon? Sahi naam bataiye.',
    'dev' => 'यो सामान सूचीमा छैन। मूल्य %s सहित नयाँ कार्ड बनाइदिऊँ? नाम स्पष्ट भन्नुस्।'],
  'not_found_add' => [
    'en' => 'I couldn\'t find that item — try saying the name another way.',
    'np' => 'Tyo saman bhettina — naam arkai gari bhannus hernus.',
    'hi' => 'Woh saaman nahi mila — naam doosre tareeke se kahiye.',
    'dev' => 'त्यो सामान भेटिन — नाम अर्कै गरी भन्नुस् हेर्नुस्।'],
  'fallback' => [
    'en' => 'I can add items, quote or change prices (staged as a card), and give today\'s total. Try: "How much is Coke?"',
    'np' => 'Ma saman thapna, mulya sodhna/badalna (card stage garera), ra din ko hisab dina sakchhu. Ek wata prayas garnus: "Coke ko mulya kati?"',
    'hi' => 'Main saaman jod sakta hoon, daam bata ya badal sakta hoon (card stage karke), aur aaj ka hisaab de sakta hoon. Koshish kijiye: "Coke ka daam kitna hai?"',
    'dev' => 'म सामान थप्न, मूल्य सोध्न/बदल्न (कार्ड स्टेज गरेर), र दिनको हिसाब दिन सक्छु। एउटा प्रयास गर्नुस्: "कोकको मूल्य कति?"'],
];
function t(string $key, ...$args): string {
    global $S, $L;
    $s = $S[$key][$L] ?? $S[$key]['en'];
    return $args ? vsprintf($s, $args) : $s;
}

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

if ($msg === '') { out(['reply' => t('empty')]); }

// --- the confirm word --------------------------------------------------------
if (in_array(mb_strtolower($msg), ['gol', 'गोल'], true) || !empty($in['confirm'])) {
    $card = golpos_pending();
    if (!$card) { out(['reply' => t('no_pending')]); }
    if ($card['type'] === 'price_change') {
        foreach ($products as &$p) {
            if ($p['sku'] === $card['sku']) { $p['price'] = $card['new_price']; }
        }
        unset($p);
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => t('price_done', $card['name'], money((float)$card['old_price']), money((float)$card['new_price'])), 'done' => $card]);
    }
    if ($card['type'] === 'new_item') {
        $sku = (string)(1000 + count($products) + 1);
        $products[] = ['sku' => $sku, 'barcode' => $card['barcode'] ?? '', 'name' => $card['name'],
                       'category' => $card['category'] ?? 'Counter', 'price' => $card['price']];
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => t('item_added', $card['name'], money((float)$card['price'])), 'done' => $card]);
    }
    out(['reply' => t('card_fail')]);
}

// --- day total / close -------------------------------------------------------
if (preg_match('/(kati bikri|kitni bikri|aaja ko|aaj ka|आज|today|close|banda|hisab|hisaab|sales|total)/iu', $msg)) {
    $s = golpos_day_summary(date('Y-m-d'));
    if ($s['txns'] === 0) {
        out(['reply' => t('day_none')]);
    }
    out(['reply' => t('day_total', money($s['gross']), $s['txns'], money($s['cash']), money($s['card']))]);
}

// --- price change (staged) ---------------------------------------------------
if (preg_match('/(\d+(?:\.\d{1,2})?)/', $msg, $m) &&
    preg_match('/(mulya|price|dam|daam).*(gara|set|badha|rakha|change|badlo|karo)|(gara|set|change|badlo).*(mulya|price|dam|daam)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        $new = (float)$m[1];
        if ($new === (float)$p['price']) { out(['reply' => t('price_same', $p['name'], money($new))]); }
        $card = golpos_stage(['type' => 'price_change', 'sku' => $p['sku'], 'name' => $p['name'],
                              'old_price' => $p['price'], 'new_price' => $new]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => t('which_price', $list)]);
    }
    out(['reply' => t('not_found_price')]);
}

// --- price query --------------------------------------------------------------
if (preg_match('/(kati|kitna|kitne|price|mulya|dam|daam|how much|मूल्य|कति)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) { out(['reply' => $r['one']['name'] . ' — ' . money((float)$r['one']['price']) . '.']); }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => t('which_one', $list)]);
    }
    out(['reply' => t('not_in_list')]);
}

// --- add to cart ---------------------------------------------------------------
if (preg_match('/(thap|add|ek |one |chahiyo|give|jodo|jod |lagao|थप)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        out(['reply' => t('added_cart', $r['one']['name'], money((float)$r['one']['price'])),
             'action' => ['type' => 'add', 'sku' => $r['one']['sku']]]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => t('which_add', $list)]);
    }
    // unknown item -> offer a staged new-item card when a price is present
    if (preg_match('/(\d+(?:\.\d{1,2})?)/', $msg, $m)) {
        out(['reply' => t('new_card_offer', money((float)$m[1]))]);
    }
    out(['reply' => t('not_found_add')]);
}

out(['reply' => t('fallback')]);
