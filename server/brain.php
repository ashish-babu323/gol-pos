<?php
// Gol POS — the Gol brain endpoint.
// POST {message} -> {reply, card?, action?}
// POST {confirm:true} executes the staged card (the confirm word is checked client-side
// AND here: a bare "gol" / "गोल" message confirms; this endpoint also accepts the
// register's explicit confirm call for the same card).
//
// Provider: the Meta Model API (Muse, muse-spark-1.3) is the PRIMARY brain —
// it composes Gol's replies. The deterministic engine below still resolves
// items, stages cards and executes writes, and it answers silently on its own
// whenever the API is unreachable, so the register never stops ringing.
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
require __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$msg = trim((string)($in['message'] ?? ''));
$catalog = golpos_catalog();
$products = $catalog['products'] ?? [];
$shop = (string)($catalog['store']['name'] ?? 'Sunrise Mart');

// Does this text CLAIM a card was staged? Past-tense claims only — the
// deterministic templates promise in the future tense ("card stage
// garchhu" = I WILL stage it) and must never trip this.
function golpos_claims_staging(string $reply): bool {
    return preg_match('/\bI(?:\'|\x{2019}| ha)ve staged\b'
        . '|\bstaged\s+(a|an|the)\b'
        . '|\bstage\s+(gareko|gare|gariyo|bhayo)\b'
        . '|\bcard\s+stage\s+(gareko|gare|gariyo|bhayo)\b'
        . '|स्टेज\s*गर(ेको|ियो)/iu', $reply) === 1;
}
// The sales form of the same bluff (owner law, Oct 28): a claim that a
// sale was added or recorded BY THE SPEAKER. Chat can NEVER satisfy this
// claim — a sale exists only by being rung and tendered at the register
// — so the claim is always false from this endpoint. But the register
// also REPORTS its log in the same verbs ("No sales recorded yet today",
// "sales recorded so far: …"), and status phrasing is the truth, never
// a bluff: the matcher below takes accomplishment shapes only —
// first-person completion, verb-first done claims, bare done-assertions
// — and reads "sales recorded …" as a claim only when no status
// continuation (so far / yet / today / at <time> / in the last …)
// follows it.
function golpos_claims_sale(string $reply): bool {
    if (preg_match('/\bbikri\s+hal|बिक्री\s*हाल/iu', $reply)) { return true; }
    if (preg_match('/\bI(?:\'|\x{2019}| ha)ve\s+(just\s+)?(added|recorded|entered)\b/iu', $reply)) { return true; }
    if (preg_match('/\b(added|recorded|entered)\s+(the\s+|a\s+|your\s+)?sales?\b/iu', $reply)) { return true; }
    if (preg_match('/\bsales?\s+(was\s+|were\s+)?(added|entered)\b/iu', $reply)) { return true; }
    if (preg_match('/\bsales?\s+has\s+been\s+(added|recorded|entered)\b/iu', $reply)) { return true; }
    if (preg_match('/\bno\s+sales?\s+recorded\b/iu', $reply)) { return false; }
    if (preg_match('/\bsales?\s+(?:was\s+|were\s+)?recorded\b(.{0,20})/iu', $reply, $m)) {
        return !preg_match('/^\s*(at|on|so\s+far|yet|today|this|in\s+the|for\s+the)/iu', $m[1]);
    }
    return false;
}
// --- Verify Loop tap: journal this reply's checkable claims -----------------
// Structured payloads only (audit v1 scope): graph series/totals, the
// close record, executed pay cards. The journal carries the inputs the
// lane claims — the dates, and the sales/refund counts as a PREFIX
// fingerprint: the data files are append-only, so the verifier
// recomputes exactly the world this reply saw, even as today grows.
function golpos_verify_out(array $r): void {
    $fp = ['sales_n' => count(golpos_sales()), 'refunds_n' => count(golpos_refunds())];
    $sid = (string)($GLOBALS['golpos_session_id'] ?? '');
    if (!empty($r['graph']) && is_array($r['graph']) && empty($r['graph']['empty'])) {
        $g = $r['graph'];
        $kind = (string)($g['kind'] ?? '');
        if ($kind === 'bars' || $kind === 'spark' || $kind === 'compare') {
            $dates = [];
            foreach ((array)$g['series'] as $p) {
                if (!isset($p['ymd'])) { continue; }
                $dates[] = (string)$p['ymd'];
                golpos_verify_journal(['class' => 'money_day_total', 'label' => (string)$p['ymd'],
                    'figure' => round((float)$p['value'], 2), 'unit' => 'money',
                    'inputs' => $fp, 'session' => $sid]);
            }
            if ($dates && $kind !== 'compare') {
                golpos_verify_journal(['class' => 'money_range_total',
                    'label' => $dates[0] . '..' . $dates[count($dates) - 1],
                    'figure' => round((float)($g['total'] ?? 0), 2), 'unit' => 'money',
                    'inputs' => $fp + ['from' => $dates[0], 'to' => $dates[count($dates) - 1]],
                    'session' => $sid]);
            }
        } elseif ($kind === 'hbars') {
            foreach ((array)$g['series'] as $p) {
                $qty = null;
                if (isset($p['sub']) && preg_match('/×(\d+)/u', (string)$p['sub'], $m)) { $qty = (int)$m[1]; }
                golpos_verify_journal(['class' => 'best_seller_rev', 'label' => (string)($p['label'] ?? ''),
                    'figure' => round((float)($p['value'] ?? 0), 2), 'unit' => 'money',
                    'inputs' => $fp + ['ymd' => (string)($p['ymd'] ?? ''), 'qty' => $qty],
                    'session' => $sid]);
            }
        }
    }
    if (!empty($r['close_record']) && is_array($r['close_record'])) {
        $rec = $r['close_record'];
        golpos_verify_journal(['class' => 'close_figure', 'label' => (string)($rec['date'] ?? ''),
            'figure' => round((float)($rec['gross'] ?? 0), 2), 'unit' => 'money',
            'inputs' => $fp + ['cash' => round((float)($rec['cash'] ?? 0), 2),
                               'card' => round((float)($rec['card'] ?? 0), 2),
                               'txns' => (int)($rec['txns'] ?? 0)], 'session' => $sid]);
    }
    if (!empty($r['done']) && is_array($r['done'])
        && in_array((string)($r['done']['type'] ?? ''), ['pay_send', 'pay_approve'], true)) {
        $c = $r['done'];
        golpos_verify_journal(['class' => 'pay_math',
            'label' => (string)($c['employee_email'] ?? '') . ' ' . (string)($c['period_from'] ?? '') . '..' . (string)($c['period_to'] ?? ''),
            'figure' => round((float)($c['amount'] ?? 0), 2), 'unit' => 'money',
            'inputs' => ['employee_email' => (string)($c['employee_email'] ?? ''),
                         'period_from' => (string)($c['period_from'] ?? ''),
                         'period_to' => (string)($c['period_to'] ?? ''),
                         'lines' => (array)($c['lines'] ?? []),
                         'segments' => (array)($c['segments'] ?? []),
                         'hours' => round((float)($c['hours'] ?? 0), 2),
                         'covered_until' => $c['covered_until'] ?? null,
                         'unlocks_n' => count(golpos_unlocks())],
            'session' => $sid]);
    }
}

function golpos_echo_confirm(string $msg, array $in): bool {
    if (!empty($in['confirm'])) return false;
    if (!in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true)) return false;
    if (empty($GLOBALS['golpos_session_id'])) return false;
    $ss = golpos_session((string)$GLOBALS['golpos_session_id']);
    $said = (string)($ss['last_spoken'] ?? '');
    $at = (int)($ss['last_spoken_at'] ?? 0);
    if ($said === '' || $at <= 0) return false;
    if (!preg_match('/\bgol\b|गोल/iu', $said)) return false;
    return (time() - $at) < 8;
}

function out(array $r): void {
    // ANTI-BLUFF GUARD (owner-caught, Oct 28): a reply may only say a card
    // was staged when a card is actually in this response or still pending
    // on the till. The free-form composer once answered "I've staged a
    // card" three times with nothing staged — the deterministic lanes had
    // never fired and the claim was pure invention. When a staging claim
    // shows up with no card behind it, the claim is replaced by the
    // truthful line: nothing is staged, and exactly what to say instead.
    if (isset($r['reply']) && is_string($r['reply'])
        && empty($r['card']) && empty($r['done'])
        && golpos_pending() === null) {
        if (golpos_claims_sale($r['reply'])) {
            $r['reply'] = t('sale_route');
        } elseif (golpos_claims_staging($r['reply'])) {
            $r['reply'] = t('bluff_guard');
        }
    }
    // Provider marker: which brain actually answered this turn. It rides in the
    // response's meta field for the team (and the hackathon judges) to verify;
    // the register UI ignores it.
    $r['meta'] = $GLOBALS['golpos_meta'] ?? ['provider' => 'deterministic', 'model' => null];
    // Employee lane state (Oct 28): every brain reply carries the
    // register's lock state and the session's actor, so the register UI
    // can raise/lower the lock screen and stamp unlock photos without a
    // second read. Read from the saved session — the server's truth.
    if (!empty($GLOBALS['golpos_session_id'])) {
        $ss = golpos_session((string)$GLOBALS['golpos_session_id']);
        $r['locked'] = golpos_lock_state($ss);
        if (!empty($GLOBALS['golpos_unlock_event'])) {
            $r['unlock_event'] = $GLOBALS['golpos_unlock_event'];
        }
        $r['actor'] = !empty($ss['unlocked_by']) ? [
            'name' => (string)($ss['unlocked_name'] ?? ''),
            'role' => (string)($ss['unlocked_role'] ?? ''),
            'email' => $ss['unlocked_by'] === 'admin' ? '' : (string)$ss['unlocked_by'],
        ] : null;
        // PIN state (Oct 30): is the server still waiting for a PIN
        // after this reply — the register locked, or a PIN stage armed
        // for a gated ask / PIN creation? The chat opens its masked PIN
        // pad on exactly this signal and closes it on its absence, so
        // the ask is a state of the chat, never a guessing game.
        $r['pin_ask'] = golpos_lock_state($ss) || !empty($ss['pin_stage']);
    }
    // When the access gate consumed this turn's message as a PIN (including
    // when it replayed the pending intent after a successful PIN), the
    // client masks the typed bubble — the digits never stand in the history.
    if (!empty($GLOBALS['golpos_pin_mask'])) { $r['pin_mask'] = true; }
    golpos_verify_out($r);
    if (!empty($GLOBALS['golpos_session_id']) && isset($r['reply']) && is_string($r['reply'])) {
        $spoken = golpos_session((string)$GLOBALS['golpos_session_id']);
        $spoken['last_spoken'] = $r['reply'];
        $spoken['last_spoken_at'] = time();
        golpos_save_session((string)$GLOBALS['golpos_session_id'], $spoken);
    }
    // JSON_INVALID_UTF8_SUBSTITUTE (Nov 2, brain-task fix): one bad byte
    // in a composed reply must never blank the whole response — the
    // task-list answer's em-dash split did exactly that before the
    // split itself was fixed; this flag is the fence behind the fix.
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function money(float $n): string { return '$' . number_format($n, 2); }

// --- language: English by default; mirror the user only when they switch -----
function golpos_lang(string $msg): string {
    if (preg_match('/[\x{0900}-\x{097F}]/u', $msg)) { return 'dev'; }
    $t = ' ' . mb_strtolower($msg) . ' ';
    // Stem matching, not whole-word: Nepali verbs carry suffixes (thap ->
    // thapdinu / thapnus, deu -> dinus), so a stem hit is the language
    // signal. Whole-word matching missed natural forms like "pani
    // thapdinu" and "dudh chahiyo" and answered them in English.
    $np = ['kati', 'thap', 'mulya', 'hisab', 'aaja', 'chha', 'gara', 'deu', 'dinus',
           'chahiyo', 'chahinchha', 'bikri', 'becheu', 'rakha', 'badha', 'badal',
           'bhannus', 'saman', 'naya', 'pasal', 'banda', ' kun ', ' tyo ', ' lekha ', ' din ',
           // Pay-lane vocabulary (Oct 28): without these, "mero talab
           // paincha?" scored zero and Gol answered her in English.
           'talab', 'paincha', 'jyala', 'ghanta', ' mero '];
    $hi = ['kitna', 'kitne', 'daam', 'jodo', 'lagao', 'saaman', 'karo', 'badlo',
           'hisaab', ' hai ', ' aaj ', ' mein '];
    // Whole-word (prefix) match. Substring matching turned English
    // ("purchase", "change") into Roman Nepali because "cha" sat inside them.
    $n = 0;
    foreach ($np as $w) {
        $w = trim($w);
        if ($w !== '' && preg_match('/\b' . preg_quote($w, '/') . '/iu', $msg)) { $n++; }
    }
    $h = 0;
    foreach ($hi as $w) {
        $w = trim($w);
        if ($w !== '' && preg_match('/\b' . preg_quote($w, '/') . '/iu', $msg)) { $h++; }
    }
    // Whole-word function tokens: the short words real Nepali turns
    // are built from (cha / ke / ko / baki / bhayo / kasto). They must
    // match as WHOLE words — as stems they would fire inside English
    // ("cha" in "change"), which is why they were never in the stem
    // list, and why short turns like "cart ma ke cha?" scored zero and
    // were answered in English.
    foreach (['cha', 'ke', 'ko', 'baki', 'bhayo', 'kasto'] as $w) {
        if (preg_match('/\b' . $w . '\b/iu', $msg)) { $n++; }
    }
    if ($n === 0 && $h === 0) { return 'en'; }
    return $n >= $h ? 'np' : 'hi';
}

// Devanagari digits -> Latin, for price parsing (२.५० means 2.50).
function golpos_norm_digits(string $s): string {
    return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
                       '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
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
  'barcode_taken' => [
    'en' => 'That barcode is already on %s.',
    'np' => 'Tyo barcode pahile nai %s ma chha.',
    'hi' => 'Woh barcode pehle se hi %s par hai.',
    'dev' => 'त्यो बारकोड पहिल्यै %s मा छ।'],
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
  'price_ask' => [
    'en' => '%s — what should the new price be? Tell me the price and I\'ll stage the card.',
    'np' => '%s ko naya mulya kati rakhne? Mulya bhannus, card stage garchhu.',
    'hi' => '%s ka naya daam kitna rakhoon? Daam bataiye, card stage karta hoon.',
    'dev' => '%s को नयाँ मूल्य कति राख्ने? मूल्य भन्नुस्, कार्ड स्टेज गर्छु।'],
  'price_ask_which' => [
    'en' => 'Which price for %s — %s or %s? I won\'t guess.',
    'np' => '%s ko mulya kun — %s ki %s? Ma anuman gardina.',
    'hi' => '%s ka daam kaun sa — %s ya %s? Main andaza nahi lagaunga.',
    'dev' => '%s को मूल्य कुन — %s कि %s? म अनुमान गर्दिनँ।'],
  'price_ask_which_plain' => [
    'en' => 'Which price — %s or %s? I won\'t guess.',
    'np' => 'Kun mulya — %s ki %s? Ma anuman gardina.',
    'hi' => 'Kaun sa daam — %s ya %s? Main andaza nahi lagaunga.',
    'dev' => 'कुन मूल्य — %s कि %s? म अनुमान गर्दिनँ।'],
  'price_ask_item' => [
    'en' => 'Which item should I change to %s? Name it and I\'ll stage the card — it runs only when you say "gol".',
    'np' => 'Kun saman ko mulya %s banaune? Naam bhannus, card stage garchhu — "gol" bhane pachhi matra chalchha.',
    'hi' => 'Kis saaman ka daam %s karoon? Naam bataiye, card stage karta hoon — "gol" kahne par hi chalega.',
    'dev' => 'कुन सामानको मूल्य %s बनाउने? नाम भन्नुस्, कार्ड स्टेज गर्छु — "गोल" भनेपछि मात्र चल्छ।'],
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
  'question_no_stage' => [
    'en' => 'That was a question, so I haven\'t staged or changed anything. If you want an item added or created, say it as an instruction — "add <item> at <price>".',
    'np' => 'Tyo prashna thiyo, tyasaile kehi stage gareko chhaina. Saman thapna wa banauna cha bhane nirdeshan jasari bhannus — "add <item> at <price>".',
    'hi' => 'Woh sawaal tha, isiliye kuchh stage nahi kiya. Saaman jodna ya banana ho to nirdesh ki tarah kahiye — "add <item> at <price>".',
    'dev' => 'त्यो प्रश्न थियो, त्यसैले केही स्टेज गरेको छैन। सामान थप्न वा बनाउनु छ भने निर्देशनजसरी भन्नुस् — "add <item> at <price>"।'],
  'week_none' => [
    'en' => 'No sales recorded in the last 7 days yet — once the register rings, I can analyse the week for you.',
    'np' => 'Pachhillo 7 din ma ahile samma bikri record chhaina — register ma bikri bhaye pachhi hapta ko hisab herna sakchhu.',
    'hi' => 'Pichhle 7 din mein abhi tak koi bikri record nahi hai — register par bikri hone ke baad hafte ka analysis dunga.',
    'dev' => 'पछिल्लो ७ दिनमा अहिलेसम्म बिक्री रेकर्ड छैन — रजिस्टरमा बिक्री भएपछि हप्ताको हिसाब हेर्न सक्छु।'],
  'week_line' => [
    'en' => 'This week: %s across %d sales (today %s).',
    'np' => 'Yo hapta: jamma %s, %d ota sale (aaja %s).',
    'hi' => 'Is hafte: kul %s, %d sale (aaj %s).',
    'dev' => 'यो हप्ता: जम्मा %s, %d वटा बिक्री (आज %s)।'],
  'month_line' => [
    'en' => 'This month so far: %s taken in across %d sales (cash %s, card %s). The POS records sales only, so this is money in — profit lives in the books.',
    'np' => 'Yo mahina ahile samma: jamma %s bhitra aayo, %d ota sale (cash %s, card %s). POS le bikri matra rakhchha — yo bhitra aako paisa ho, nafa books ma hunchha.',
    'hi' => 'Is mahine abhi tak: kul %s andar aaya, %d sale (cash %s, card %s). POS sirf bikri rakhta hai — yeh andar aaya paisa hai, munafa books mein hota hai.',
    'dev' => 'यो महिना अहिलेसम्म: जम्मा %s भित्र आयो, %d वटा बिक्री (नगद %s, कार्ड %s)। POS ले बिक्री मात्र राख्छ — यो भित्र आएको पैसा हो, नाफा बुक्समा हुन्छ।'],
  'poster_which' => [
    'en' => 'Which product should the poster feature? Say its name — e.g. "make an offer poster for" + the item\'s name.',
    'np' => 'Poster ma kun saman rakhne? Naam bhannus — jastai "offer poster bana" + saman ko naam.',
    'hi' => 'Poster mein kaun sa saaman ho? Naam bataiye — jaise "offer poster banao" + saaman ka naam.',
    'dev' => 'पोस्टरमा कुन सामान राख्ने? नाम भन्नुस् — जस्तै "अफर पोस्टर बनाऊ" + सामानको नाम।'],
  'poster_ready' => [
    'en' => 'Poster ready — preview below. Say "gol" and I\'ll post it to the %s demo feed.',
    'np' => 'Poster tayar chha — tala preview hernus. "gol" bhannus, ma %s ko demo feed ma haldinchhu.',
    'hi' => 'Poster taiyaar hai — neeche preview dekhiye. "gol" kahiye, main %s ke demo feed par daal dunga.',
    'dev' => 'पोस्टर तयार छ — तल प्रिभ्यु हेर्नुस्। "गोल" भन्नुस्, म %s को डेमो फिडमा हाल्दिन्छु।'],
  'poster_done' => [
    'en' => 'Posted to the %s demo feed: %s — %s.',
    'np' => '%s ko demo feed ma post bhayo: %s — %s.',
    'hi' => '%s ke demo feed par post ho gaya: %s — %s.',
    'dev' => '%s को डेमो फिडमा पोस्ट भयो: %s — %s।'],
  'poster_fail' => [
    'en' => 'I couldn\'t generate the poster just now — the image service didn\'t answer. Try again in a moment.',
    'np' => 'Ahile poster banauna milena — image service le jawaf diyena. Ekai chhin pachhi feri prayas garnus.',
    'hi' => 'Abhi poster nahi ban paya — image service ne jawaab nahi diya. Thodi der baad phir koshish kijiye.',
    'dev' => 'अहिले पोस्टर बनाउन मिलेन — इमेज सेवाले जवाफ दिएन। एकै छिनपछि फेरि प्रयास गर्नुस्।'],
  'close_done' => [
    'en' => 'Day closed and the record is saved — %s total (%d sales): cash %s, card %s.',
    'np' => 'Din banda bhayo, record save bhayo — jamma %s (%d sale): cash %s, card %s.',
    'hi' => 'Din band ho gaya, record save ho gaya — kul %s (%d sale): cash %s, card %s.',
    'dev' => 'दिन बन्द भयो, रेकर्ड सेभ भयो — जम्मा %s (%d वटा बिक्री): नगद %s, कार्ड %s।'],
  'new_ask_price' => [
    'en' => 'New item — %s. What price should I put on it? Tell me the price and I\'ll stage the card.',
    'np' => 'Naya saman — %s. Mulya kati rakhne ho? Mulya bhannus, card stage garchhu.',
    'hi' => 'Naya saaman — %s. Daam kitna rakhoon? Daam bataiye, card stage karta hoon.',
    'dev' => 'नयाँ सामान — %s। मूल्य कति राख्ने हो? मूल्य भन्नुस्, कार्ड स्टेज गर्छु।'],
  'photo_added' => [
    'en' => 'From your photo, I added %s (%s) to the cart.',
    'np' => 'Tapai ko photo bata %s (%s) cart ma thapiye.',
    'hi' => 'Aapki photo se %s (%s) cart mein jod diya.',
    'dev' => 'तपाईंको फोटोबाट %s (%s) कार्टमा थपियो।'],
  'photo_saved' => [
    'en' => 'Photo saved. SAM found %d region(s) for "%s" — nothing in the catalog matched that name, so nothing was added. Say the item name and price if it\'s a new product.',
    'np' => 'Photo save bhayo. SAM le "%s" ka lagi %d thau bhetyo — suchi ma tyo naam mildaina, kehi thapiyena. Naya saman ho bhane naam ra mulya bhannus.',
    'hi' => 'Photo save ho gaya. SAM ko "%s" ke liye %d jagah mili — list mein woh naam nahi mila, kuchh joda nahi gaya. Naya saaman hai to naam aur daam bataiye.',
    'dev' => 'फोटो सेभ भयो। SAM ले "%s" का लागि %d ठाउँ भेट्यो — सूचीमा त्यो नाम मिल्दैन, केही थपिएन। नयाँ सामान हो भने नाम र मूल्य भन्नुस्।'],
  'settings_head' => [
    'en' => 'Receipt print settings:',
    'np' => 'Receipt ko print settings yesto chha:',
    'hi' => 'Receipt print settings ye hain:',
    'dev' => 'रसिद प्रिन्ट सेटिङहरू:'],
  'setting_done' => [
    'en' => 'Done — %s is now %s.',
    'np' => 'Bhayo — %s aba %s chha.',
    'hi' => 'Ho gaya — %s ab %s hai.',
    'dev' => 'भयो — %s अब %s छ।'],
  'setting_same' => [
    'en' => '%s is already %s — nothing to change.',
    'np' => '%s pahile nai %s chha — badalna kehi chhaina.',
    'hi' => '%s pehle se hi %s hai — kuchh badalna nahi.',
    'dev' => '%s पहिल्यै %s छ — बदल्नु केही छैन।'],
  'setting_ask_footer' => [
    'en' => 'What should the receipt footer say? Give me the exact words — e.g. footer "Thank you, visit again".',
    'np' => 'Receipt footer ma ke lekhne? Thik shabda bhannus — jastai footer "Dhanyabad, feri aaunuhos".',
    'hi' => 'Receipt footer mein kya likhoon? Sahi shabd bataiye — jaise footer "Dhanyavaad, phir aaiye".',
    'dev' => 'रसिद फुटरमा के लेख्ने? ठ्याक्कै शब्द भन्नुस् — जस्तै footer "धन्यवाद, फेरि आउनुस्"।'],
  'setting_ask_copies' => [
    'en' => 'Copies can be 1, 2 or 3 — how many should I print?',
    'np' => 'Copy 1, 2 wa 3 matra milchha — kati ota print garne?',
    'hi' => 'Copies 1, 2 ya 3 hi ho sakti hain — kitni print karoon?',
    'dev' => 'प्रति १, २ वा ३ मात्र मिल्छ — कति वटा छाप्ने?'],
  'setting_ask_surcharge' => [
    'en' => 'What percent should the card surcharge be? Say a percent — "card surcharge 3%" — or 0 to turn it off.',
    'np' => 'Card surcharge kati percent rakhne? Percent bhannus — "card surcharge 3%" — wa banda garna 0.',
    'hi' => 'Card surcharge kitna percent rakhoon? Percent bataiye — "card surcharge 3%" — ya band karne ke liye 0.',
    'dev' => 'कार्ड सरचार्ज कति प्रतिशत राख्ने? प्रतिशत भन्नुस् — "card surcharge 3%" — वा बन्द गर्न ०।'],
  'setting_surcharge_range' => [
    'en' => 'The card surcharge can be 0 to 10 percent — that number is outside it, so nothing was staged.',
    'np' => 'Card surcharge 0 dekhi 10 percent samma matra huncha — tyo number bahira paryo, kehi stage bhaena.',
    'hi' => 'Card surcharge 0 se 10 percent tak hi ho sakta hai — woh number bahar hai, kuchh stage nahi hua.',
    'dev' => 'कार्ड सरचार्ज ० देखि १० प्रतिशत सम्म मात्र हुन्छ — त्यो नम्बर बाहिर पर्यो, केही स्टेज भएन।'],
  'setting_ask_watch' => [
    'en' => 'Should the camera frame on cash sales be on or off? On saves one counter photo with each cash sale.',
    'np' => 'Cash sale ma camera frame on garne ki off? On gare harek cash sale sanga counter ko photo save huncha.',
    'hi' => 'Cash sale par camera frame on rakhoon ya off? On karne se har cash sale ke saath counter ki photo save hoti hai.',
    'dev' => 'नगद बिक्रीमा क्यामेरा फ्रेम अन गर्ने कि अफ? अन गरे हरेक नगद बिक्रीसँग काउन्टरको फोटो सेभ हुन्छ।'],
  'cam_state_link_off' => [
    'en' => 'Register Camera is OFF for this store — the master switch lives in MR.STORE Settings, and while it is off no camera stream runs on this register, whatever the till flip says. It can be changed only in MR.STORE Settings, never from chat.',
    'np' => 'Register Camera yo pasal ko lagi bandha chha — master switch MR.STORE Settings ma chha, ra tyo bandha bhaye samma till ko flip j sukai bhaye pani yo register ma camera chaldaina. Yo chat bata hoina, MR.STORE Settings bata matra ferinchha.',
    'hi' => 'Register Camera is store ke liye band hai — master switch MR.STORE Settings mein hai, aur jab tak woh band hai, till flip kuchh bhi ho, is register par camera nahi chalega. Yeh chat se nahi, sirf MR.STORE Settings se badalta hai.',
    'dev' => 'रजिस्टर क्यामेरा यस पसलका लागि बन्द छ — मास्टर स्विच MR.STORE सेटिङमा छ, र त्यो बन्द भए सम्म टिलको फ्लिप जे भए पनि यो रजिस्टरमा क्यामेरा चल्दैन। यो च्याटबाट होइन, MR.STORE सेटिङबाट मात्र फेरिन्छ।'],
  'cam_state_link_on' => [
    'en' => 'Register Camera (MR.STORE master): on · audio %s · event footage %s · this till\'s flip %s — so the camera stream at this register is %s.',
    'np' => 'Register Camera (MR.STORE master): khula · audio %s · event footage %s · yo till ko flip %s — tyasaile yo register ma camera stream %s chha.',
    'hi' => 'Register Camera (MR.STORE master): chalu · audio %s · event footage %s · is till ka flip %s — isliye is register par camera stream %s hai.',
    'dev' => 'रजिस्टर क्यामेरा (MR.STORE मास्टर): खुला · अडियो %s · इभेन्ट फुटेज %s · यस टिलको फ्लिप %s — त्यसैले यस रजिस्टरमा क्यामेरा स्ट्रिम %s छ।'],
  'cam_state_local' => [
    'en' => 'This register runs standalone — its own flip is the master: camera frame %s · mic audio %s · event footage %s.',
    'np' => 'Yo register standalone chha — usko afnai flip nai master ho: camera frame %s · mic audio %s · event footage %s.',
    'hi' => 'Yeh register standalone hai — iska apna flip hi master hai: camera frame %s · mic audio %s · event footage %s.',
    'dev' => 'यो रजिस्टर standalone छ — उसको आफ्नै फ्लिप नै मास्टर हो: क्यामेरा फ्रेम %s · माइक अडियो %s · इभेन्ट फुटेज %s।'],
  'cam_master_hold' => [
    'en' => 'That switch belongs to the Register Camera master in MR.STORE Settings — chat cannot change it, and while the master is off the till flip is held with it.',
    'np' => 'Tyo switch MR.STORE Settings ko Register Camera master ko ho — chat le ferna mildaina, ra master bandha bhaye samma till ko flip pani tesai sanga rokine chha.',
    'hi' => 'Woh switch MR.STORE Settings ke Register Camera master ka hai — chat use nahi badal sakta, aur master band rehne tak till flip bhi usi ke saath ruka rahega.',
    'dev' => 'त्यो स्विच MR.STORE सेटिङको रजिस्टर क्यामेरा मास्टरको हो — च्याटले फेर्न मिल्दैन, र मास्टर बन्द रहे सम्म टिलको फ्लिप पनि त्यसैसँग रोकिन्छ।'],
  'cam_ask_audio' => [
    'en' => 'Should the register mic join the camera stream — audio on or off? On means event clips record sound too.',
    'np' => 'Register ko mic camera stream ma jodne — audio on ki off? On gare event clip ma awaj pani record hunchha.',
    'hi' => 'Register ka mic camera stream se jodein — audio on ya off? On karne se event clip mein awaaz bhi record hogi.',
    'dev' => 'रजिस्टरको माइक क्यामेरा स्ट्रिममा जोड्ने — अडियो अन कि अफ? अन गरे इभेन्ट क्लिपमा आवाज पनि रेकर्ड हुन्छ।'],
  'cam_ask_record' => [
    'en' => 'Should event footage be recorded — record on or off? On keeps a short clip with each cash sale, beside the counter photo.',
    'np' => 'Event footage record garne — record on ki off? On gare harek cash sale sanga chhoto clip pani baschha, counter photo ko satha ma.',
    'hi' => 'Event footage record karein — record on ya off? On karne se har cash sale ke saath ek chhota clip bhi rahega, counter photo ke saath.',
    'dev' => 'इभेन्ट फुटेज रेकर्ड गर्ने — रेकर्ड अन कि अफ? अन गरे हरेक नगद बिक्रीसँग छोटो क्लिप पनि बस्छ, काउन्टर फोटोको साथमा।'],
  'stock_one' => [
    'en' => '%s — %s on hand (MR.STORE live stock).',
    'np' => '%s — %s ota chha (MR.STORE live stock).',
    'hi' => '%s — %s maujud hai (MR.STORE live stock).',
    'dev' => '%s — %s वटा छ (MR.STORE लाइभ स्टक)।'],
  'stock_untracked' => [
    'en' => '%s is in the MR.STORE catalog, but its stock count is not tracked.',
    'np' => '%s MR.STORE ko list ma chha, tara stock count track bhayeko chhaina.',
    'hi' => '%s MR.STORE ki list mein hai, lekin stock count track nahi hota.',
    'dev' => '%s MR.STORE को सूचीमा छ, तर स्टक गन्ती ट्र्याक भएको छैन।'],
  'stock_notfound' => [
    'en' => 'I couldn\'t find that item in the MR.STORE catalog.',
    'np' => 'MR.STORE ko list ma tyo saman bhettina.',
    'hi' => 'MR.STORE ki list mein woh saaman nahi mila.',
    'dev' => 'MR.STORE को सूचीमा त्यो सामान भेटिन।'],
  'stock_low_head' => [
    'en' => 'Out or running low at %s (MR.STORE live stock):',
    'np' => '%s ma sakiyeko wa sakina lageko (MR.STORE live stock):',
    'hi' => '%s mein khatam ya kam (MR.STORE live stock):',
    'dev' => '%s मा सकिएको वा सकिन लागेको (MR.STORE लाइभ स्टक):'],
  'stock_low_none' => [
    'en' => 'Nothing is out or running low — every tracked item has more than 2 on hand (MR.STORE live stock).',
    'np' => 'Kehi sakiyeko wa sakina lageko chhaina — sabai tracked item 2 bhanda badhi chha (MR.STORE live stock).',
    'hi' => 'Kuchh khatam ya kam nahi hai — har tracked item 2 se zyada hai (MR.STORE live stock).',
    'dev' => 'केही सकिएको वा सकिन लागेको छैन — सबै ट्र्याक गरिएको सामान २ भन्दा बढी छ (MR.STORE लाइभ स्टक)।'],
  'stock_recv_done' => [
    'en' => '%s received — MR.STORE stock %s → %s.',
    'np' => '%s ayo — MR.STORE stock %s → %s bhayo.',
    'hi' => '%s aaya — MR.STORE stock %s → %s ho gaya.',
    'dev' => '%s आयो — MR.STORE स्टक %s → %s भयो।'],
  'stock_link_down' => [
    'en' => 'I can\'t reach MR.STORE right now, so I can\'t read stock.',
    'np' => 'Ahile MR.STORE sanga connect bhayena — stock herna milena.',
    'hi' => 'Abhi MR.STORE se connect nahi ho raha — stock nahi dekh sakta.',
    'dev' => 'अहिले MR.STORE सँग कनेक्ट भएन — स्टक हेर्न मिलेन।'],
  'src_till' => [
    'en' => '(Source: POS till — stock lives in MR.STORE.)',
    'np' => '(Source: POS till — stock MR.STORE ma chha.)',
    'hi' => '(Source: POS till — stock MR.STORE mein hai.)',
    'dev' => '(स्रोत: POS टिल — स्टक MR.STORE मा छ।)'],
  // --- vendor bills: scan -> catalog (owner directive, Oct 28) -------------
  'bill_reask' => [
    'en' => 'Still need a shelf price for: %s. A price is never guessed — reply with the prices, or one margin for all ("40% margin on all"). Say "cancel" to drop the bill.',
    'np' => 'Ajhai shelf mulya chahinchha: %s. Mulya kahile anuman garidaina — mulya haru bhannus, wa sabai ko lagi euta margin ("40% margin on all"). Bill chhodna "cancel" bhannus.',
    'hi' => 'Abhi bhi shelf daam chahiye: %s. Daam kabhi andaza nahi lagta — daam bataiye, ya sab ke liye ek margin ("40% margin on all"). Bill chhodna hai to "cancel" kahiye.',
    'dev' => 'अझै सेल्फ मूल्य चाहिन्छ: %s। मूल्य कहिल्यै अनुमान गरिँदैन — मूल्यहरू भन्नुस्, वा सबैका लागि एउटा मार्जिन ("40% margin on all")। बिल छोड्न "cancel" भन्नुस्।'],
  'bill_cancelled' => [
    'en' => 'Bill dropped — nothing was entered, added or received.',
    'np' => 'Bill chhodiyo — kehi entry bhayena, kehi thapiyena, kehi receive bhayena.',
    'hi' => 'Bill chhod diya — kuchh enter nahi hua, kuchh joda ya receive nahi hua.',
    'dev' => 'बिल छोडियो — केही प्रविष्टि भएन, केही थपिएन, केही प्राप्त भएन।'],
  'bill_done_head' => [
    'en' => 'Bill entered — purchase of %s recorded (%d lines).',
    'np' => 'Bill entry bhayo — %s ko kinmel record bhayo (%d line).',
    'hi' => 'Bill enter ho gaya — %s ki kharid record ho gayi (%d line).',
    'dev' => 'बिल प्रविष्टि भयो — %s को किनमेल रेकर्ड भयो (%d लाइन)।'],
  'bill_new_line' => [
    'en' => '• NEW %s — shelf price %s, cost %s, %d in (stock now %s).',
    'np' => '• NAYA %s — shelf mulya %s, cost %s, %d bhitra (stock aba %s).',
    'hi' => '• NAYA %s — shelf daam %s, cost %s, %d andar (stock ab %s).',
    'dev' => '• नयाँ %s — सेल्फ मूल्य %s, लागत %s, %d भित्र (स्टक अब %s)।'],
  'bill_recv_line' => [
    'en' => '• %s — received %d (stock %s → %s).',
    'np' => '• %s — %d receive bhayo (stock %s → %s).',
    'hi' => '• %s — %d receive hua (stock %s → %s).',
    'dev' => '• %s — %d प्राप्त भयो (स्टक %s → %s)।'],
  'bill_fail_line' => [
    'en' => '⚠️ %s did not land: %s',
    'np' => '⚠️ %s pugena: %s',
    'hi' => '⚠️ %s nahi ho paya: %s',
    'dev' => '⚠️ %s पुगेन: %s'],
  'fallback' => [
    'en' => 'I can add items, quote or change prices (staged as a card), give today\'s total, and run the receipt print settings. Try: "print settings", or ask any item\'s price by name.',
    'np' => 'Ma saman thapna, mulya sodhna/badalna (card stage garera), din ko hisab dina, ra receipt print settings chalauna sakchhu. Prayas garnus: "print settings ke chha?"',
    'hi' => 'Main saaman jod sakta hoon, daam bata ya badal sakta hoon (card stage karke), aaj ka hisaab de sakta hoon, aur receipt print settings chala sakta hoon. Koshish kijiye: "print settings".',
    'dev' => 'म सामान थप्न, मूल्य सोध्न/बदल्न (कार्ड स्टेज गरेर), दिनको हिसाब दिन, र रसिद प्रिन्ट सेटिङ चलाउन सक्छु। प्रयास गर्नुस्: "प्रिन्ट सेटिङ के छ?"'],
  // --- access: the admin PIN gate (owner directive, Oct 28) -----------------
  'pin_ask' => [
    'en' => 'That\'s an admin question — enter the admin PIN first, and I\'ll answer right after.',
    'np' => 'Yo admin ko prashna ho — pahile admin PIN dinus, ma turuntai jawaf dinchhu.',
    'hi' => 'Yeh admin ka sawaal hai — pehle admin PIN dijiye, main turant jawaab dunga.',
    'dev' => 'यो एडमिनको प्रश्न हो — पहिले एडमिन PIN दिनुस्, म तुरुन्तै जवाफ दिन्छु।'],
  'pin_create_ask' => [
    'en' => 'No admin PIN is set yet — let\'s create one. Type a 4–6 digit PIN for admin access.',
    'np' => 'Admin PIN ahile samma set chhaina — banaum. Admin access ko lagi 4–6 digit ko PIN lekhnus.',
    'hi' => 'Admin PIN abhi set nahi hai — banate hain. Admin access ke liye 4–6 digit ka PIN likhiye.',
    'dev' => 'एडमिन PIN अहिलेसम्म सेट छैन — बनाऔँ। एडमिन पहुँचका लागि ४–६ अंकको PIN लेख्नुस्।'],
  'pin_create_confirm' => [
    'en' => 'Once more — type that same PIN again to confirm it.',
    'np' => 'Ek patak feri — tyahi PIN feri lekhnus, pakka garna.',
    'hi' => 'Ek baar phir — wahi PIN dobara likhiye, pakka karne ke liye.',
    'dev' => 'एक पटक फेरि — त्यही PIN फेरि लेख्नुस्, पक्का गर्न।'],
  'pin_create_mismatch' => [
    'en' => 'Those two PINs didn\'t match — let\'s start again. Type a 4–6 digit PIN.',
    'np' => 'Ti dui PIN milena — suru bata garam. 4–6 digit ko PIN lekhnus.',
    'hi' => 'Woh dono PIN nahi mile — shuru se karte hain. 4–6 digit ka PIN likhiye.',
    'dev' => 'ती दुई PIN मिलेनन् — सुरुबाट गरौँ। ४–६ अंकको PIN लेख्नुस्।'],
  'pin_bad_format' => [
    'en' => 'A PIN is 4–6 digits — try again.',
    'np' => 'PIN 4–6 digit ko hunchha — feri prayas garnus.',
    'hi' => 'PIN 4–6 digit ka hota hai — phir koshish kijiye.',
    'dev' => 'PIN ४–६ अंकको हुन्छ — फेरि प्रयास गर्नुस्।'],
  'pin_wrong' => [
    'en' => 'That PIN isn\'t right — nothing was shown or changed. %d %s left before a 10-minute lock.',
    'np' => 'Tyo PIN milena — kehi dekhaiena, kehi badliena. 10 minute lock huna aghi %d %s baki chha.',
    'hi' => 'Woh PIN sahi nahi — kuchh dikhaya ya badla nahi gaya. 10 minute lock se pehle %d %s baki.',
    'dev' => 'त्यो PIN मिलेन — केही देखाइएन, केही बदलिएन। १० मिनेट लक हुनुअघि %d %s बाँकी छ।'],
  'pin_locked' => [
    'en' => 'Admin entry is locked after wrong PINs — try again in %d minute(s).',
    'np' => 'Galat PIN pachhi admin entry lock chha — %d minute pachhi feri prayas garnus.',
    'hi' => 'Galat PIN ke baad admin entry lock hai — %d minute baad phir koshish kijiye.',
    'dev' => 'गलत PIN पछि एडमिन प्रवेश लक छ — %d मिनेटपछि फेरि प्रयास गर्नुस्।'],
  'pin_lockout' => [
    'en' => 'Too many wrong PINs — admin entry is now locked for 10 minutes. Nothing was shown or changed.',
    'np' => 'Dherai galat PIN bhayo — admin entry aba 10 minute lock bhayo. Kehi dekhaiena, kehi badliena.',
    'hi' => 'Bahut galat PIN ho gaye — admin entry ab 10 minute lock hai. Kuchh dikhaya ya badla nahi gaya.',
    'dev' => 'धेरै गलत PIN भयो — एडमिन प्रवेश अब १० मिनेट लक भयो। केही देखाइएन, केही बदलिएन।'],
  'pin_set' => [
    'en' => 'Admin PIN is set — you are in admin mode now.',
    'np' => 'Admin PIN set bhayo — tapai aba admin mode ma hununchha.',
    'hi' => 'Admin PIN set ho gaya — aap ab admin mode mein hain.',
    'dev' => 'एडमिन PIN सेट भयो — तपाईं अब एडमिन मोडमा हुनुहुन्छ।'],
  'admin_on' => [
    'en' => 'Admin mode is on.',
    'np' => 'Admin mode on bhayo.',
    'hi' => 'Admin mode on ho gaya.',
    'dev' => 'एडमिन मोड खुल्यो।'],
  'admin_off' => [
    'en' => 'Admin mode is off — this register is a cashier again. Admin questions will ask for the PIN.',
    'np' => 'Admin mode off bhayo — register feri cashier bhayo. Admin prashna ma PIN magchha.',
    'hi' => 'Admin mode off ho gaya — register phir cashier hai. Admin sawaal par PIN mangega.',
    'dev' => 'एडमिन मोड बन्द भयो — रजिस्टर फेरि क्यासियर भयो। एडमिन प्रश्नमा PIN माग्छ।'],
  'pin_cancelled' => [
    'en' => 'Cancelled — nothing was shown or changed.',
    'np' => 'Radda bhayo — kehi dekhaiena, kehi badliena.',
    'hi' => 'Radd ho gaya — kuchh dikhaya ya badla nahi gaya.',
    'dev' => 'रद्द भयो — केही देखाइएन, केही बदलिएन।'],
  'pin_not_set' => [
    'en' => 'That\'s owner-only, and no admin PIN is set on this register yet — say "admin" when you want to create one, and I\'ll ask for it then. Nothing was shown.',
    'np' => 'Yo owner-only ho, ra yo register ma admin PIN set bhakai chhaina — banauna man lage "admin" bhannus, tyahi bela sodchhu. Kei dekhaiena.',
    'hi' => 'Yeh sirf owner ke liye hai, aur is register par abhi admin PIN set nahi hai — banana ho to "admin" kahen, tabhi poochhunga. Kuchh nahi dikhaya gaya.',
    'dev' => 'यो owner-only हो, र यो register मा admin PIN set भएकै छैन — बनाउन मन लागे "admin" भन्नुस्, त्यही बेला सोध्छु। केही देखाइएन।'],
  // PIN / lock guidance (owner trace, Oct 31): an already-elevated session
  // never meets the PIN gate, so admin/PIN words used to fall through to
  // the free-form composer ("I can't help with that…"). These lines carry
  // the REAL paths, truthfully: creation lives in the sheet/chat gate;
  // since Nov 2 (TASKS 230/231) the owner also SETS/CHANGES every PIN —
  // a staged pin_set card here in chat, or the Owner panel's Team card —
  // and hands them out; an employee never sets their own PIN anywhere.
  'pin_help_none' => [
    'en' => 'No admin PIN is set on this register yet. To create one, run any admin action — tap + Add Product or + Stock: the sheet will say "No admin PIN yet — choose one"; enter a new 4–6 digit PIN there and confirm it. PINs are set in that gate, never typed loose in chat. The Lock button starts working only once a PIN exists.',
    'np' => 'Yo register ma admin PIN ajhai set bhakai chhaina. PIN banauna kunai admin kaam garnus — jastai + Add Product wa + Stock thichnus; \'No admin PIN yet\' bhanne aauchha, tyaha naya PIN (4–6 digit) haalera confirm garnus. PIN chat ma khulla lekhera hoina, tyahi gate bata banchha. PIN banepachhi matra Lock button le kaam garchha.',
    'hi' => 'Is register par admin PIN abhi set nahi hai. PIN banane ke liye koi admin kaam kijiye — jaise + Add Product ya + Stock dabaiye; "No admin PIN yet" aayega, wahan naya PIN (4–6 digit) daalkar confirm kijiye. PIN chat mein khule mein nahi likha jata, usi gate se banta hai. PIN banne ke baad hi Lock button kaam karega.',
    'dev' => 'यो register मा admin PIN अझै set भएकै छैन। PIN बनाउन कुनै admin काम गर्नुस् — जस्तै + Add Product वा + Stock थिच्नुस्; \'No admin PIN yet\' भन्ने आउँछ, त्यहाँ नयाँ PIN (४–६ अंक) हालेर confirm गर्नुस्। PIN chat मा खुल्ला लेखेर होइन, त्यही gate बाट बन्छ। PIN बनेपछि मात्र Lock button ले काम गर्छ।'],
  'pin_help_have' => [
    'en' => 'This register has an admin PIN, and PINs belong to the owner: only the owner sets or changes them, and hands them out. To set an employee\'s PIN here, say "Sita ko PIN set gara" with the new 4–6 digits — I\'ll stage a card and it lands only on your "gol". Your own admin PIN changes the same way ("admin PIN set gara …"). On screen: Owner panel → Team → Set PIN. The Lock button at the top locks the register; it opens again with the PIN of whoever is on shift. A bare PIN typed loose in chat is never used.',
    'np' => 'Yo register ma admin PIN chha, ra PIN haru owner kai ho: owner le matra set/change garchha, ra aafai haat ma dinchha. Employee ko PIN yaha set garna "Sita ko PIN set gara" bhanera naya 4–6 digit bhannus — card stage garchhu, tapaiko "gol" pachhi matra lands hunchha. Aafno admin PIN pani testai badlinchha ("admin PIN set gara …"). Screen ma: Owner panel → Team → Set PIN. Mathi ko Lock button le register lock garchha; shift ma jo chha usko PIN le khulchha. Chat ma khulla type gareko PIN kahile prayog hundaina.',
    'hi' => 'Is register par admin PIN hai, aur PIN owner ke hi hain: sirf owner set/change karta hai aur khud deta hai. Kisi employee ka PIN yahan set karne ke liye "Sita ko PIN set gara" ke saath naye 4–6 digit kahiye — card stage karunga, aapke "gol" par hi lagega. Apna admin PIN bhi aise hi badalta hai ("admin PIN set gara …"). Screen par: Owner panel → Team → Set PIN. Upar ka Lock button register lock karta hai; shift par jo ho uske PIN se khulta hai. Chat mein khule mein type kiya PIN kabhi istemal nahi hota.',
    'dev' => 'यो register मा admin PIN छ, र PIN हरू owner कै हुन्: owner ले मात्र set/change गर्छ, र आफै हातमा दिन्छ। Employee को PIN यहाँ set गर्न "Sita ko PIN set gara" भनेर नयाँ ४–६ अंक भन्नुस् — card stage गर्छु, तपाईंको "गोल" पछि मात्र लाग्छ। आफ्नो admin PIN पनि त्यसै बदलिन्छ ("admin PIN set gara …")। Screen मा: Owner panel → Team → Set PIN। माथिको Lock button ले register lock गर्छ; shift मा जो छ उसको PIN ले खुल्छ। Chat मा खुल्ला type गरेको PIN कहिल्यै प्रयोग हुँदैन।'],
  'pin_set_who' => [
    'en' => 'Whose PIN should I set — yours (the admin PIN) or an employee\'s? Name them, with the new 4–6 digits, and I\'ll stage the card.',
    'np' => 'Kas ko PIN set garne — tapaiko (admin PIN) ki employee ko? Naam ra naya 4–6 digit bhannus, card stage garchhu.',
    'hi' => 'Kis ka PIN set karoon — aapka (admin PIN) ya kisi employee ka? Naam aur naye 4–6 digit bataiye, card stage karta hoon.',
    'dev' => 'कसको PIN set गर्ने — तपाईंको (admin PIN) कि employee को? नाम र नयाँ ४–६ अंक भन्नुस्, card stage गर्छु।'],
  'pin_set_ask' => [
    'en' => 'What 4–6 digit PIN for %s? Say it in the same breath — "PIN set gara 1234" — and I\'ll stage the card. It lands only on your "gol", and I never show it back.',
    'np' => '%s ko lagi kun 4–6 digit PIN? Ekai sas ma bhannus — "PIN set gara 1234" — card stage garchhu. Tapaiko "gol" pachhi matra lands hunchha, ra ma PIN feri dekhaudina.',
    'hi' => '%s ke liye kaun sa 4–6 digit PIN? Ek saath kahiye — "PIN set gara 1234" — card stage karta hoon. Sirf aapke "gol" par lagega, aur main PIN phir kabhi nahi dikhaunga.',
    'dev' => '%s को लागि कुन ४–६ अंक PIN? एकै सासमा भन्नुस् — "PIN set gara 1234" — card stage गर्छु। तपाईंको "गोल" पछि मात्र लाग्छ, र म PIN फेरि देखाउँदिन।'],
  'pin_set_done_emp' => [
    'en' => 'PIN set for %s. Hand it to them yourself — Gol never shows it again. Their old PIN (if any) no longer opens the register.',
    'np' => '%s ko PIN set bhayo. Uslai aafai dinus — Gol le feri dekhaudaina. Purano PIN (thiyo bhane) le aba register khuldaina.',
    'hi' => '%s ka PIN set ho gaya. Unhen khud dijiye — Gol phir kabhi nahi dikhayega. Purana PIN (agar tha) ab register nahi kholega.',
    'dev' => '%s को PIN set भयो। उसलाई आफै दिनुस् — Gol ले फेरि देखाउँदैन। पुरानो PIN (थियो भने) ले अब register खुल्दैन।'],
  'pin_set_done_admin' => [
    'en' => 'Your admin PIN is changed. The old one no longer opens the register or the admin gate — use the new one from now on, and don\'t share it.',
    'np' => 'Tapaiko admin PIN badliyo. Purano le aba register wa admin gate khuldaina — aba dekhi naya nai chalaunus, ra kasailai nadinus.',
    'hi' => 'Aapka admin PIN badal gaya. Purana ab register ya admin gate nahi kholega — ab se naya hi chalaiye, aur kisi ko mat dijiye.',
    'dev' => 'तपाईंको admin PIN बदलियो। पुरानोले अब register वा admin gate खुल्दैन — अबदेखि नयाँ नै चलाउनुस्, र कसैलाई नदिनुस्।'],
  'pin_set_gone' => [
    'en' => 'That PIN card couldn\'t run — %s is no longer on the team list, or the staged PIN was lost. Nothing was changed; stage it again.',
    'np' => 'Tyo PIN card chalna sakena — %s team list ma chhaina, wa stage bhayeko PIN harayo. Kei badliena; feri stage garnus.',
    'hi' => 'Woh PIN card nahi chal paya — %s team list mein nahi hai, ya staged PIN kho gaya. Kuchh nahi badla; phir stage kijiye.',
    'dev' => 'त्यो PIN card चल्न सकेन — %s team list मा छैन, वा stage भएको PIN हरायो। केही बदलिएन; फेरि stage गर्नुस्।'],

  'pin_never_chat' => [
    'en' => 'That looks like a PIN — PINs are never typed loose in chat, and I won\'t use, store or repeat it.',
    'np' => 'Tyo PIN jasto dekhiyo — PIN chat ma khulla kahile lekhinna, ma teslai prayog, store wa dohraudina.',
    'hi' => 'Woh PIN jaisa lagta hai — PIN chat mein khule mein kabhi nahi likha jata, main use istemal, store ya dohraunga nahi.',
    'dev' => 'त्यो PIN जस्तो देखियो — PIN chat मा खुल्ला कहिल्यै लेखिन्न, म त्यसलाई प्रयोग, store वा दोहोर्याउँदिनँ।'],
  'card_cancelled' => [
    'en' => 'Cancelled — the staged card is cleared; nothing was changed.',
    'np' => 'Radda bhayo — stage bhayeko card clear bhayo; kei change bhayena.',
    'hi' => 'Cancel ho gaya — staged card clear hai; kuchh change nahi hua.',
    'dev' => 'रद्द भयो — stage भएको card clear भयो; केही change भएन।'],
  'card_not_live' => [
    'en' => 'That card is no longer live — nothing was changed.',
    'np' => 'Tyo card aba live chhaina — kei change bhayena.',
    'hi' => 'Woh card ab live nahi hai — kuchh change nahi hua.',
    'dev' => 'त्यो card अब live छैन — केही change भएन।'],
  'hours_line' => [
    'en' => 'Store hours: opens %s, closes %s. Batch close mode: %s.',
    'np' => 'Pasal ko samaya: %s khulchha, %s banda hunchha. Batch close mode: %s.',
    'hi' => 'Dukaan ka samay: %s khulti hai, %s band hoti hai. Batch close mode: %s.',
    'dev' => 'पसलको समय: %s खुल्छ, %s बन्द हुन्छ। ब्याच क्लोज मोड: %s।'],
  'hours_ask' => [
    'en' => 'Tell me the time too — e.g. "store closes at 9 PM" or "store 9 baje banda huncha".',
    'np' => 'Samaya pani bhannus — jastai "store closes at 9 PM" wa "store 9 baje banda huncha".',
    'hi' => 'Samay bhi bataiye — jaise "store closes at 9 PM".',
    'dev' => 'समय पनि भन्नुस् — जस्तै "store closes at 9 PM" वा "store 9 baje banda huncha"।'],
  'batch_none' => [
    'en' => 'No unbatched card sales right now — every card sale is already inside a batch. Nothing was staged.',
    'np' => 'Ahile unbatched card sale chhaina — sabai card sale batch bhitra chha. Kehi stage bhaena.',
    'hi' => 'Abhi koi unbatched card sale nahi hai — har card sale batch ke andar hai. Kuchh stage nahi hua.',
    'dev' => 'अहिले अनब्याच गरिएको कार्ड बिक्री छैन — सबै कार्ड बिक्री ब्याचभित्र छ। केही स्टेज भएन।'],
  'batch_done' => [
    'en' => 'Batch #%d sealed — %d card sales, %s.',
    'np' => 'Batch #%d seal bhayo — %d ota card sale, %s.',
    'hi' => 'Batch #%d seal ho gaya — %d card sale, %s.',
    'dev' => 'ब्याच #%d सिल भयो — %d वटा कार्ड बिक्री, %s।'],
  'batch_line' => [
    'en' => 'Batch #%d — %d card sales, %s.',
    'np' => 'Batch #%d — %d ota card sale, %s.',
    'hi' => 'Batch #%d — %d card sale, %s.',
    'dev' => 'ब्याच #%d — %d वटा कार्ड बिक्री, %s।'],
  'batch_auto_line' => [
    'en' => 'Batch #%d auto-closed at %s — %d card sales, %s.',
    'np' => 'Batch #%d %s ma afai banda bhayo — %d ota card sale, %s.',
    'hi' => 'Batch #%d %s par apne aap band ho gaya — %d card sale, %s.',
    'dev' => 'ब्याच #%d %s मा आफैँ बन्द भयो — %d वटा कार्ड बिक्री, %s।'],
  'close_unbatched' => [
    'en' => '⚠️ %d card sales (%s) are not in a batch yet — say "batch close" to seal them.',
    'np' => '⚠️ %d ota card sale (%s) batch ma pareko chhaina — seal garna "batch close" bhannus.',
    'hi' => '⚠️ %d card sale (%s) abhi batch mein nahi hain — seal karne ke liye "batch close" kahiye.',
    'dev' => '⚠️ %d वटा कार्ड बिक्री (%s) ब्याचमा परेको छैन — सिल गर्न "batch close" भन्नुस्।'],
  'emp_none' => [
    'en' => 'No employees yet. Say "add name@email.com as employee" and Gol will send them an invite.',
    'np' => 'Ahile samma employee chhaina. "add name@email.com as employee" bhannus, Gol le invite pathauchha.',
    'hi' => 'Abhi tak koi employee nahi hai. "add name@email.com as employee" kahiye, Gol invite bhejega.',
    'dev' => 'अहिलेसम्म कर्मचारी छैन। "add name@email.com as employee" भन्नुस्, Gol ले निमन्त्रणा पठाउँछ।'],
  'emp_list_head' => [
    'en' => 'Employees:',
    'np' => 'Employee haru:',
    'hi' => 'Employees:',
    'dev' => 'कर्मचारीहरू:'],
  'emp_added_sent' => [
    'en' => 'Employee added: %s — an invite email is on its way. They set their own PIN from the link in the email.',
    'np' => 'Employee thapiyo: %s — invite email pathaiyo. Uni haru le email ko link bata afno PIN set garchhan.',
    'hi' => 'Employee joda gaya: %s — invite email bhej diya. Woh email ke link se apna PIN set karenge.',
    'dev' => 'कर्मचारी थपियो: %s — निमन्त्रणा इमेल पठाइयो। उनीहरूले इमेलको लिङ्कबाट आफ्नो PIN सेट गर्छन्।'],
  'emp_added_link' => [
    'en' => 'Employee added: %s — but the invite email could not be sent, so give them this link yourself: %s',
    'np' => 'Employee thapiyo: %s — tara invite email pathauna milena, yo link afai dinus: %s',
    'hi' => 'Employee joda gaya: %s — lekin invite email nahi bheja gaya, yeh link khud de dijiye: %s',
    'dev' => 'कर्मचारी थपियो: %s — तर निमन्त्रणा इमेल पठाउन मिलेन, यो लिङ्क आफैँ दिनुस्: %s'],
  'emp_exists' => [
    'en' => '%s is already on the employee list (%s).',
    'np' => '%s pahile nai employee list ma chha (%s).',
    'hi' => '%s pehle se hi employee list mein hai (%s).',
    'dev' => '%s पहिल्यै कर्मचारी सूचीमा छ (%s)।'],
  'emp_removed' => [
    'en' => 'Removed %s from the employees.',
    'np' => '%s lai employee bata hataiyo.',
    'hi' => '%s ko employees se hata diya.',
    'dev' => '%s लाई कर्मचारीबाट हटाइयो।'],
  'emp_not_found' => [
    'en' => 'I don\'t have %s on the employee list.',
    'np' => '%s employee list ma chhaina.',
    'hi' => '%s employee list mein nahi hai.',
    'dev' => '%s कर्मचारी सूचीमा छैन।'],
  'emp_help' => [
    'en' => 'Employees run from here: "add name@email.com as employee" (Gol sends the invite), "employees list", or "remove name@email.com".',
    'np' => 'Employee yahi bata chalaunchha: "add name@email.com as employee" (Gol le invite pathauchha), "employees list", wa "remove name@email.com".',
    'hi' => 'Employees yahin se chalte hain: "add name@email.com as employee" (Gol invite bhejega), "employees list", ya "remove name@email.com".',
    'dev' => 'कर्मचारी यहीँबाट चल्छ: "add name@email.com as employee" (Gol ले निमन्त्रणा पठाउँछ), "employees list", वा "remove name@email.com"।'],
  'capabilities' => [
    'en' => 'Here is what I can do at this register: • Sell: add items to the cart by name ("add two" + the item\'s name), quote prices, check stock. • Reports: today\'s sales, yesterday, this week, closes — ask "hijo ko hisab" or "sales this week". • Stock: what\'s out, low stock, receive deliveries, stock counts. • People: add or remove employees by email (invite goes out on your "gol"), assign tasks. • Settings: receipt/print settings, store hours, tax — changes stage as cards and run only on your "gol". • Store books (linked): bills, vendors (ask "vendor profile" + the vendor\'s name), purchases and expenses by day or week. Nothing is saved or sent until you confirm a staged card with "gol".',
    'np' => 'Ma yo register ma ke ke garna sakchhu: • Bechne: naam liyera saman cart ma thapne ("dui ota" + item ko naam thap), mulya bhanna, stock herne. • Report: aaja, hijo, yo hapta ko bikri ra close — "hijo ko hisab" wa "sales this week" sodhnus. • Stock: ke sakiyo, low stock, delivery receive, ginti. • Manche: email bata employee thapne/hataune (tapaiko "gol" pachhi invite janchha), kaam lagaune. • Settings: receipt/print, pasal khulne time, tax — change card ma stage hunchha, "gol" bhane pachhi matra lagu hunchha. • Pasal ko books (linked): bills, vendor (vendor ko naam + "vendor profile"), din/hapta ko kharid ra kharcha. Kunai pani kura tapaile card confirm ("gol") nagaresamma save hudaina.',
    'hi' => 'Main is register par yeh kar sakta hoon: • Bechna: naam lekar saaman cart mein jodna ("do" + saaman ka naam jodo), daam batana, stock dekhna. • Report: aaj, kal, is hafte ki bikri aur close — "kal ka hisab" ya "sales this week" poochhiye. • Stock: kya khatam hai, low stock, delivery receive, ginti. • Log: email se employee jodna/hatana (aapke "gol" par invite jata hai), kaam dena. • Settings: receipt/print, dukaan ka samay, tax — badlav card mein stage hota hai, "gol" kehne par hi lagu hota hai. • Dukaan ki books (linked): bills, vendor (vendor ka naam + "vendor profile"), din/hafte ki kharid aur kharcha. Jab tak aap card confirm ("gol") nahi karte, kuchh save nahi hota.',
    'dev' => 'म यो रजिस्टरमा यी गर्न सक्छु: • बेच्ने: नाम लिएर सामान कार्टमा थप्ने ("दुई वटा" + सामानको नाम थप), मूल्य भन्न, स्टक हेर्न। • रिपोर्ट: आज, हिजो, यो हप्ताको बिक्री र क्लोज — "हिजोको हिसाब" वा "sales this week" सोध्नुस्। • स्टक: के सकियो, low stock, डेलिभरी receive, गिन्ती। • मान्छे: इमेलबाट कर्मचारी थप्ने/हटाउने (तपाईंको "गोल" पछि निमन्त्रणा जान्छ), काम लगाउने। • सेटिङ: रसिद/प्रिन्ट, पसल खुल्ने समय, कर — परिवर्तन कार्डमा स्टेज हुन्छ, "गोल" भनेपछि मात्र लागू हुन्छ। • पसलका बुक्स (linked): बिल, भेन्डर (भेन्डरको नाम + "vendor profile"), दिन/हप्ताको खरिद र खर्च। तपाईंले कार्ड confirm ("गोल") नगरेसम्म केही save हुँदैन।'],
  'emp_negated' => [
    'en' => 'Understood — I have not added or saved any employee, and I won\'t from this message. When you want one, say "add name@email.com as employee" and I\'ll stage the invite card for your "gol".',
    'np' => 'Bujhe — maile kunai employee thapeko/save gareko chhaina, yo message bata gardina pani. Chahiyo bhane "add name@email.com as employee" bhannus, invite card stage garchhu — tapaiko "gol" pachhi matra janchha.',
    'hi' => 'Samajh gaya — maine koi employee joda/save nahi kiya hai, aur is message se karunga bhi nahi. Jab chahiye, "add name@email.com as employee" kahiye, invite card stage karunga — aapke "gol" par hi jayega.',
    'dev' => 'बुझेँ — मैले कुनै कर्मचारी थपेको/save गरेको छैन, यो message बाट गर्दिनँ पनि। चाहिए "add name@email.com as employee" भन्नुस्, निमन्त्रणा कार्ड स्टेज गर्छु — तपाईंको "गोल" पछि मात्र जान्छ।'],
  'board_none' => [
    'en' => 'The loyalty leaderboard is empty — no customer has sent a receipt photo yet.',
    'np' => 'Loyalty leaderboard khali chha — kunai customer le ahile samma receipt photo pathaeko chhaina.',
    'hi' => 'Loyalty leaderboard khaali hai — kisi customer ne abhi tak receipt photo nahi bheja.',
    'dev' => 'लोयाल्टी लिडरबोर्ड खाली छ — कुनै ग्राहकले अहिलेसम्म रसिद फोटो पठाएको छैन।'],
  'poster_route' => [
    'en' => 'Offer posters are made in MR.STORE\'s Gol — marketing lives there; this till only sells. Right here I can quote a price, add items to the cart, or stage a price change.',
    'np' => 'Offer poster MR.STORE ko Gol ma banchha — marketing uta chha, yo till le bechne matra ho. Yaha ma mulya bhanna, saman cart ma thapna, wa mulya badalne card stage garna sakchhu.',
    'hi' => 'Offer poster MR.STORE ke Gol mein banta hai — marketing wahin hai, yeh till sirf bechta hai. Yahan main daam bata sakta hoon, saaman cart mein jod sakta hoon, ya daam badalne ka card stage kar sakta hoon.',
    'dev' => 'अफर पोस्टर MR.STORE को Gol मा बन्छ — मार्केटिङ उतै छ, यो टिलले बेच्ने मात्र हो। यहाँ म मूल्य भन्न, सामान कार्टमा थप्न, वा मूल्य बदल्ने कार्ड स्टेज गर्न सक्छु।'],
  'sale_route' => [
    'en' => 'A sale is never added from chat — a sale exists only when it is rung at the register and tendered. I can put items in the cart by name right here; the sale records when the register takes the payment.',
    'np' => 'Sale chat bata kahile thapinna — sale register ma ring garera tender garepachi matra banchha. Ma yaha naam liyera saman cart ma halna sakchhu; bikri register le bhuktani liepachi record hunchha.',
    'hi' => 'Sale chat se kabhi nahi jodta — sale sirf register par ring karke tender karne par banta hai. Main yahan naam lekar saaman cart mein daal sakta hoon; bikri register par payment lene par record hoti hai.',
    'dev' => 'सेल च्याटबाट कहिल्यै थपिन्न — सेल रजिस्टरमा रिङ गरेर टेन्डर गरेपछि मात्र बन्छ। म यहाँ नाम लिएर सामान कार्टमा हाल्न सक्छु; बिक्री रजिस्टरले भुक्तानी लिएपछि रेकर्ड हुन्छ।'],
  'bluff_guard' => [
    'en' => 'I haven\'t staged anything — no card is waiting. Tell me the item and the exact new price (like "change [item name] to $3") and I\'ll stage the real card here.',
    'np' => 'Maile kunai card banaeko chhaina — kehi parkhi rako chhaina. Saman ra naya mulya spashta bhannus (jastai "[saman ko naam] to $3") — asal card yahi stage garchhu.',
    'hi' => 'Maine koi card nahi banaya hai — kuchh intezaar nahi kar raha. Saaman aur naya daam saaf bataiye (jaise "[saaman ka naam] to $3") — asal card yahin stage karunga.',
    'dev' => 'मैले कुनै कार्ड बनाएको छैन — केही पर्खिरहेको छैन। सामान र नयाँ मूल्य स्पष्ट भन्नुस् (जस्तै "[सामानको नाम] to $3") — असली कार्ड यहीँ स्टेज गर्छु।'],
  'already_staged' => [
    'en' => 'That card is already staged: %s — say "gol" to run it.',
    'np' => 'Tyo card pahile nai stage chha: %s — chalauna "gol" bhannus.',
    'hi' => 'Woh card pehle se stage hai: %s — chalane ke liye "gol" kahiye.',
    'dev' => 'त्यो कार्ड पहिल्यै स्टेज छ: %s — चलाउन "गोल" भन्नुस्।'],
  'ho_teach' => [
    'en' => 'To confirm, say "gol" — "ho" no longer confirms. Your card is still ready:',
    'np' => 'Confirm garna "gol" bhannus — "ho" le aba confirm hudaina. Card ready nai chha:',
    'hi' => 'Confirm karne ke liye "gol" kahiye — "ho" se ab confirm nahi hota. Aapka card abhi ready hai:',
    'dev' => 'Confirm गर्न "gol" भन्नुहोस् — "हो" ले अब confirm हुँदैन। Card तयारै छ:'],
  'stage_what' => [
    'en' => 'Stage what? Nothing is waiting right now — tell me the item and the new price (like "change [item name] to $3") and I\'ll stage the card.',
    'np' => 'Ke stage garne? Ahile kehi parkhi rako chhaina — saman ra naya mulya bhannus (jastai "[saman ko naam] to $3"), card stage garchhu.',
    'hi' => 'Kya stage karoon? Abhi kuchh intezaar nahi kar raha — saaman aur naya daam bataiye (jaise "[saaman ka naam] to $3"), card stage karunga.',
    'dev' => 'के स्टेज गर्ने? अहिले केही पर्खिरहेको छैन — सामान र नयाँ मूल्य भन्नुस् (जस्तै "[सामानको नाम] to $3"), कार्ड स्टेज गर्छु।'],
  'ask_cancelled' => [
    'en' => 'Okay — dropped that. Nothing is staged.',
    'np' => 'Huss — tyo chhodiye. Kehi stage chhaina.',
    'hi' => 'Theek hai — woh chhod diya. Kuchh stage nahi hai.',
    'dev' => 'हुन्छ — त्यो छोडियो। केही स्टेज छैन।'],
  'board_head' => [
    'en' => 'Receipt Loyalty — leaderboard:',
    'np' => 'Receipt Loyalty — leaderboard:',
    'hi' => 'Receipt Loyalty — leaderboard:',
    'dev' => 'रसिद लोयाल्टी — लिडरबोर्ड:'],
  // --- employee lane: lock, tasks, notes, presence (owner, Oct 28) -----------
  'pin_please' => [
    'en' => 'PIN please — the register is locked. Type your PIN and I\'ll open it in your name.',
    'np' => 'PIN dinus — register lock chha. PIN halnus, tapaiko naam ma khulchha.',
    'hi' => 'PIN dijiye — register lock hai. PIN daaliye, aapke naam par khulega.',
    'dev' => 'PIN दिनुस् — रजिस्टर लक छ। PIN हाल्नुस्, तपाईंको नाममा खुल्छ।'],
  'unlock_shared' => [
    'en' => 'That PIN matches more than one person on the roster — I can\'t tell who is opening the register. The owner needs to give each person their own PIN.',
    'np' => 'Tyo PIN roster ma ek bhanda badhi manche sanga milchha — kasle khulyo thaha hundaina. Owner le harek lai afnai PIN dinu parchha.',
    'hi' => 'Woh PIN roster mein ek se zyada logon se milta hai — kaun khol raha hai pata nahi. Owner ko har kisi ko apna PIN dena hoga.',
    'dev' => 'त्यो PIN roster मा एकभन्दा बढी मान्छेसँग मिल्छ — कसले खोल्यो थाहा हुँदैन। Owner ले हरेकलाई आफ्नै PIN दिनुपर्छ।'],
  'tasks_none' => [
    'en' => 'No tasks waiting for you today.',
    'np' => 'Aaja tapaiko lagi parkhiraheko kam chhaina.',
    'hi' => 'Aaj aapke liye koi kaam intezaar nahi kar raha.',
    'dev' => 'आज तपाईंका लागि पर्खिरहेको काम छैन।'],
  'yday_unfinished' => [
    'en' => '%d task item(s) from yesterday are still open (%s).',
    'np' => 'Hijo ka %d kam ajhai baki chha (%s).',
    'hi' => 'Kal ke %d kaam abhi baki hain (%s).',
    'dev' => 'हिजोका %d काम अझै बाँकी छ (%s)।'],
  'overnight_notes' => [
    'en' => '%d unread note(s) from the team overnight.',
    'np' => 'Raat bhari team bata %d note aayeko chha — padheko chhaina.',
    'hi' => 'Raat bhar team se %d note aaye hain — padhe nahi gaye.',
    'dev' => 'रातभरि team बाट %d note आएको छ — पढेको छैन।'],
  'morning_plain' => [
    'en' => 'Good morning.',
    'np' => 'Namaste, subha bhayo.',
    'hi' => 'Suprabhat.',
    'dev' => 'नमस्ते, शुभ प्रभात।'],
  'assign_roster_miss' => [
    'en' => '%s isn\'t on the roster — I can\'t assign work to a stranger. Add them first ("add name@email.com as employee"), then assign.',
    'np' => '%s roster ma chhaina — nachineko manche lai kam dina mildaina. Pahile add garnus ("add name@email.com as employee"), ani assign garnus.',
    'hi' => '%s roster mein nahi hai — ajnabi ko kaam nahi de sakta. Pehle add kijiye ("add name@email.com as employee"), phir assign kijiye.',
    'dev' => '%s roster मा छैन — नचिनेको मान्छेलाई काम दिन मिल्दैन। पहिले add गर्नुस् ("add name@email.com as employee"), अनि assign गर्नुस्।'],
  'assign_ask_items' => [
    'en' => 'What work should I give? Name the tasks and I\'ll stage the card.',
    'np' => 'Ke kam dine? Kam haru bhannus, card stage garchhu.',
    'hi' => 'Kya kaam doon? Kaam bataiye, card stage karta hoon.',
    'dev' => 'के काम दिने? कामहरू भन्नुस्, card stage गर्छु।'],
  'assign_edited' => [
    'en' => 'Task card updated — %s now has %d item(s). Say "gol" to record + email, or keep editing.',
    'np' => 'Task card update bhayo — %s ko aba %d kam chha. Record + email garna "gol" bhannus, wa edit gardai garnus.',
    'hi' => 'Task card update ho gaya — %s ke ab %d kaam hain. Record + email ke liye "gol" kahiye, ya edit jaari rakhen.',
    'dev' => 'Task card update भयो — %s को अब %d काम छ। Record + email गर्न "गोल" भन्नुस्, वा edit गर्दै गर्नुस्।'],
  'assign_done_sent' => [
    'en' => 'Tasks recorded for %s and emailed to %s: %s.',
    'np' => '%s ko kam record bhayo, %s ma email pathaiyo: %s.',
    'hi' => '%s ke kaam record ho gaye, %s par email bheja: %s.',
    'dev' => '%s को काम record भयो, %s मा email पठाइयो: %s।'],
  'assign_done_mailfail' => [
    'en' => 'Tasks recorded for %s, but the email to %s could not be sent — the list waits in their register tasks.',
    'np' => '%s ko kam record bhayo, tara %s ma email pathauna milena — list unko register tasks ma parkhi chha.',
    'hi' => '%s ke kaam record ho gaye, lekin %s par email nahi bheja gaya — list unke register tasks mein intezaar kar rahi hai.',
    'dev' => '%s को काम record भयो, तर %s मा email पठाउन मिलेन — list उनको register tasks मा पर्खिरहेको छ।'],
  'note_ask_identity' => [
    'en' => 'That runs from your own session — unlock the register with your PIN first, then tell me.',
    'np' => 'Tyo afnai session bata chhalchha — pahile afno PIN le register khola garnus, ani bhannus.',
    'hi' => 'Woh aapke apne session se chalta hai — pehle apne PIN se register kholiye, phir kahiye.',
    'dev' => 'त्यो आफ्नै session बाट चल्छ — पहिले आफ्नो PIN ले register खोल्नुस्, अनि भन्नुस्।'],
  'note_done' => [
    'en' => 'Note sent to the boss: "%s"',
    'np' => 'Boss lai note pugyo: "%s"',
    'hi' => 'Boss ko note bheja: "%s"',
    'dev' => 'Boss लाई note पुग्यो: "%s"'],
  'task_done_ok' => [
    'en' => 'Done — "%s" marked complete at %s.',
    'np' => 'Sakiyo — "%s" %s ma done bhayo.',
    'hi' => 'Ho gaya — "%s" %s par done ho gaya.',
    'dev' => 'सकियो — "%s" %s मा done भयो।'],
  'task_done_which' => [
    'en' => 'Which task is done? Your open items: %s',
    'np' => 'Kun kam sakiyo? Tapaiko khula kam: %s',
    'hi' => 'Kaun sa kaam ho gaya? Aapke khule kaam: %s',
    'dev' => 'कुन काम सकियो? तपाईंको खुला काम: %s'],
  'team_no_notes' => [
    'en' => '%s has sent no notes yet.',
    'np' => '%s le ahile samma note pathaeko chhaina.',
    'hi' => '%s ne abhi tak koi note nahi bheja.',
    'dev' => '%s ले अहिलेसम्म note पठाएको छैन।'],
  'roster_miss' => [
    'en' => '%s isn\'t on the roster — no presence to show. If they work here, add them first.',
    'np' => '%s roster ma chhaina — presence dekhaune kohi chhaina. Yaha kam garchan bhane pahile add garnus.',
    'hi' => '%s roster mein nahi hai — presence dikhane ko koi nahi. Agar yahan kaam karte hain to pehle add kijiye.',
    'dev' => '%s roster मा छैन — presence देखाउने कोही छैन। यहाँ काम गर्छन् भने पहिले add गर्नुस्।'],
  'refund_route' => [
    'en' => 'Refunds don\'t run from chat yet — the refund lane is being built to run from Transactions, so the sale stays exactly as recorded and no price is touched. Nothing changed.',
    'np' => 'Refund ahile chat bata chaldaina — refund lane Transactions bata chalne gari baniraheko chha, tyasaile sale record ustai chha ra mulya ma kehi chalaieena. Kehi badliena.',
    'hi' => 'Refund abhi chat se nahi chalta — refund lane Transactions se chalne ke liye ban raha hai, isliye sale record waisa hi hai aur daam nahi chhua gaya. Kuchh nahi badla.',
    'dev' => 'Refund अहिले chat बाट चल्दैन — refund lane Transactions बाट चल्ने गरी बनिरहेको छ, त्यसैले sale record उस्तै छ र मूल्यमा केही चलाइएन। केही बदलिएन।'],
  'refund_off' => [
    'en' => 'Refunds are OFF for this store right now — nothing was refunded. Turn them on with "refunds allowed on gara" (a settings card) first.',
    'np' => 'Refund ahile pasal ko lagi OFF chha — kehi farkaieena. Pahile "refunds allowed on gara" bhanera khola (setting card aauchha).',
    'hi' => 'Refund abhi is dukaan ke liye OFF hai — kuchh wapas nahi hua. Pehle "refunds allowed on" karke kholo (setting card aayega).',
    'dev' => 'फिर्ता अहिले यस पसलका लागि बन्द छ — केही फर्काइएन। पहिले "refunds allowed on" भनेर खोल्नुस् (setting card आउँछ)।'],
  'refund_none' => [
    'en' => 'No refundable sale matches that — nothing staged. Refunds attach to a recorded sale inside the window; name the item, the day ("hijo ko"), the tender, or the amount and I\'ll find it.',
    'np' => 'Tes sanga milne refund hune sale bhetiyena — kehi stage bhaena. Refund record bhayeko sale sanga matra jodinchha; item ko naam, din ("hijo ko"), cash/card, ki rakam bhana — ma khojchhu.',
    'hi' => 'Us se milti koi refundable sale nahi mili — kuchh stage nahi hua. Refund sirf record ki gayi sale se judta hai; item ka naam, din ("kal ki"), cash/card, ya rakam batao — main dhoondhta hoon.',
    'dev' => 'त्यससँग मिल्ने फिर्ता हुने बिक्री भेटिएन — केही stage भएन। फिर्ता record भएको बिक्रीसँग मात्र जोडिन्छ; item को नाम, दिन ("हिजोको"), cash/card, कि रकम भन — म खोज्छु।'],
  'refund_which' => [
    'en' => 'A few sales could be the one — which? Say "pahilo" / "dosro" or name the item:',
    'np' => 'Kehi sale milna sakchha — kun? "Pahilo" / "dosro" bhana, ki item ko naam bhana:',
    'hi' => 'Kuchh sales mil sakti hain — kaun si? "Pehli" / "dosri" bolo, ya item ka naam batao:',
    'dev' => 'केही बिक्री मिल्न सक्छ — कुन? "पहिलो" / "दोस्रो" भन, कि item को नाम भन:'],
  'refund_done' => [
    'en' => 'Refunded %s — back to %s. The returned items are on the shelf again.',
    'np' => '%s farkaiyo — %s ma gayo. Farkieka item shelf ma farkie.',
    'hi' => '%s wapas ho gaya — %s par gaya. Wapas aaye item shelf par laut gaye.',
    'dev' => '%s फर्काइयो — %s मा गयो। फर्केका item फेरि shelf मा छन्।'],
  'refund_credit_back' => [
    'en' => ' %s of it went back onto the customer\'s store credit.',
    'np' => ' Tesma bata %s customer ko credit ma farkiyo.',
    'hi' => ' Usmein se %s customer ke credit mein wapas gaya.',
    'dev' => ' त्यसबाट %s customer को credit मा फर्कियो।'],
  'credit_none' => [
    'en' => 'No credit on this number — that phone has no credit record at this store.',
    'np' => 'Yo number ma credit chhaina — yo phone ko credit record pasal ma chhaina.',
    'hi' => 'Is number par credit nahi hai — is phone ka credit record is dukaan mein nahi hai.',
    'dev' => 'यो नम्बरमा credit छैन — यो phone को credit record यस पसलमा छैन।'],
  'credit_balance' => [
    'en' => '%s — store credit balance %s. It spends at the till: ring the sale, tap Credit, and apply up to the balance.',
    'np' => '%s — store credit balance %s chha. Till ma kharcha hunchha: sale ring gara, Credit thicha, ra balance samma lagau.',
    'hi' => '%s — store credit balance %s hai. Till par kharch hota hai: sale ring karo, Credit dabao, aur balance tak lagao.',
    'dev' => '%s — store credit balance %s छ। Till मा खर्च हुन्छ: sale ring गर, Credit थिच, र balance सम्म लगाऊ।'],
  'credit_ask_phone' => [
    'en' => 'Whose credit? Give me the phone number — the balance reads from the store\'s own records, and the spend itself happens at the till tender.',
    'np' => 'Kasko credit? Phone number deu — balance pasal kai record bata padhinchha, ra kharcha till ko tender mai hunchha.',
    'hi' => 'Kiska credit? Phone number do — balance dukaan ke apne record se padha jata hai, aur kharch till ke tender par hi hota hai.',
    'dev' => 'कसको credit? Phone number देऊ — balance पसलकै record बाट पढिन्छ, र खर्च till को tender मै हुन्छ।'],
  'credit_link' => [
    'en' => 'This register is linked to MR.STORE — the Festival balance lives MR.STORE-side and the register\'s credit spend lane for linked stores is still being wired. Nothing was spent.',
    'np' => 'Yo register MR.STORE sanga jodieko chha — Festival balance MR.STORE tira chha, ra linked pasal ko lagi register bata credit kharcha garne lane ajhai baniraheko chha. Kehi kharcha bhaena.',
    'hi' => 'Yeh register MR.STORE se juda hai — Festival balance MR.STORE ki taraf hai, aur linked dukaan ke liye register se credit kharch ka lane abhi ban raha hai. Kuchh kharch nahi hua.',
    'dev' => 'यो register MR.STORE सँग जोडिएको छ — Festival balance MR.STORE तिर छ, र linked पसलका लागि register बाट credit खर्च गर्ने lane अझै बनिरहेको छ। केही खर्च भएन।'],
  'credit_attach_staged' => [
    'en' => '%s — store credit %s. Confirm ("gol") and the credit sits on this sale: it spends once, at the tender, for this sale only.',
    'np' => '%s — store credit %s chha. Confirm ("gol") gara, credit yo sale ma baschha: tender ma ek choti matra kharcha hunchha, yahi sale ko lagi.',
    'hi' => '%s — store credit %s hai. Confirm ("gol") karo, credit is sale par lag jayega: tender par ek hi baar kharch hoga, sirf is sale ke liye.',
    'dev' => '%s — store credit %s छ। Confirm ("गोल") गर, credit यो sale मा बस्छ: tender मा एक चोटि मात्र खर्च हुन्छ, यही sale का लागि।'],
  'credit_attach_done' => [
    'en' => '%s\'s credit (%s) is on this sale now — ring the rest and tender as usual; the credit spends once for this sale.',
    'np' => '%s ko credit (%s) yo sale ma lagyo — baki ring gara ra sadhai jasto tender gara; credit yo sale ko lagi ek choti kharcha hunchha.',
    'hi' => '%s ka credit (%s) is sale par lag gaya — baaki ring karo aur hamesha ki tarah tender karo; credit is sale ke liye ek baar kharch hoga.',
    'dev' => '%s को credit (%s) यो sale मा लाग्यो — बाँकी ring गर र सधैँ जस्तो tender गर; credit यो sale का लागि एक चोटि खर्च हुन्छ।'],
  'credit_attach_none' => [
    'en' => 'No customer record matches "%s" at this store — nothing was attached.',
    'np' => '"%s" naam ko customer record yo pasal ma chhaina — kehi attach bhaena.',
    'hi' => '"%s" naam ka customer record is dukaan mein nahi hai — kuchh attach nahi hua.',
    'dev' => '"%s" नामको customer record यस पसलमा छैन — केही attach भएन।'],
  'credit_attach_many' => [
    'en' => 'More than one "%s" on record — say the full name or the phone number: ',
    'np' => '"%s" naam ka ek bhanda badhi record chha — pura naam ki phone number bhana: ',
    'hi' => '"%s" naam ke ek se zyada record hain — poora naam ya phone number batao: ',
    'dev' => '"%s" नामका एकभन्दा बढी record छन् — पूरा नाम कि phone number भन: '],
  'credit_attach_off' => [
    'en' => 'Store credit redeem is off for this store — nothing was attached.',
    'np' => 'Yo pasal ma store credit redeem bandha chha — kehi attach bhaena.',
    'hi' => 'Is dukaan mein store credit redeem band hai — kuchh attach nahi hua.',
    'dev' => 'यस पसलमा store credit redeem बन्द छ — केही attach भएन।'],
  'credit_attach_gone' => [
    'en' => '%s has no spendable credit left — nothing was attached.',
    'np' => '%s sanga kharcha garna milne credit baki chhaina — kehi attach bhaena.',
    'hi' => '%s ke paas kharch karne layak credit baaki nahi hai — kuchh attach nahi hua.',
    'dev' => '%s सँग खर्च गर्न मिल्ने credit बाँकी छैन — केही attach भएन।'],
  'stock_one_local' => [
    'en' => '%s — %s on hand (this register\'s own shelf).',
    'np' => '%s — %s ota chha (yahi register ko shelf).',
    'hi' => '%s — %s maujud hai (is register ki apni shelf).',
    'dev' => '%s — %s वटा छ (यही register को shelf)।'],
  'stock_untracked_local' => [
    'en' => '%s is in the catalog, but its stock count is not tracked on this register.',
    'np' => '%s list ma chha, tara yo register ma stock count track bhayeko chhaina.',
    'hi' => '%s list mein hai, lekin is register par stock count track nahi hota.',
    'dev' => '%s सूचीमा छ, तर यो register मा stock count track भएको छैन।'],
  'stock_low_head_local' => [
    'en' => 'Out or running low at %s (this register\'s own shelf):',
    'np' => '%s ma sakiyeko wa sakina lageko (yahi register ko shelf):',
    'hi' => '%s mein khatam ya kam (is register ki apni shelf):',
    'dev' => '%s मा सकिएको वा सकिन लागेको (यही register को shelf):'],
  'stock_low_none_local' => [
    'en' => 'Nothing is out or running low — every tracked item has more than 2 on hand (this register\'s own shelf).',
    'np' => 'Kehi sakiyeko wa sakina lageko chhaina — sabai tracked item 2 bhanda badhi chha (yahi register ko shelf).',
    'hi' => 'Kuchh khatam ya kam nahi hai — har tracked item 2 se zyada hai (is register ki shelf).',
    'dev' => 'केही सकिएको वा सकिन लागेको छैन — सबै tracked item २ भन्दा बढी छ (यही register को shelf)।'],
  'stock_notfound_local' => [
    'en' => 'I couldn\'t find that item in this register\'s catalog.',
    'np' => 'Yo register ko list ma tyo saman bhettina.',
    'hi' => 'Is register ki list mein woh saaman nahi mila.',
    'dev' => 'यो register को सूचीमा त्यो सामान भेटिन।'],
  'stock_recv_done_local' => [
    'en' => '%s received — stock %s → %s.',
    'np' => '%s ayo — stock %s → %s bhayo.',
    'hi' => '%s aaya — stock %s → %s ho gaya.',
    'dev' => '%s आयो — stock %s → %s भयो।'],
  'whatsout_done' => [
    'en' => 'What\'s Out filed — %s, reported by %s. The stock count was not changed.',
    'np' => 'What\'s Out record bhayo — %s, %s le report garyo. Stock count badliena.',
    'hi' => 'What\'s Out record ho gaya — %s, %s ne report kiya. Stock count nahi badla.',
    'dev' => 'What\'s Out record भयो — %s, %s ले report गर्‍यो। Stock count बदलिएन।'],
  'whatsout_unknown' => [
    'en' => 'I couldn\'t match that to a catalog item, so no report was filed. Closest on the shelf: %s.',
    'np' => 'Tyo naam catalog ko saman sanga milena, report file bhayena. Shelf ma najik: %s.',
    'hi' => 'Woh naam catalog ke saaman se nahi mila, report file nahi hui. Shelf par sabse kareeb: %s.',
    'dev' => 'त्यो नाम catalog को सामानसँग मिलेन, report file भएन। Shelf मा नजिक: %s।'],
  'count_done' => [
    'en' => 'Count filed — %s: counted %s, system %s (variance %s). The shelf stock was not changed — the variance waits for the owner.',
    'np' => 'Count file bhayo — %s: ginti %s, system %s (farak %s). Shelf stock badliena — farak owner ko kura parkhi chha.',
    'hi' => 'Count file ho gaya — %s: ginti %s, system %s (antar %s). Shelf stock nahi badla — antar owner ke faisle ka intezaar karega.',
    'dev' => 'Count file भयो — %s: गिन्ती %s, system %s (फरक %s)। Shelf stock बदलिएन — फरक owner को कुरा पर्खिरहेको छ।'],
  'count_ask' => [
    'en' => 'Tell me the count the way you counted it — "[item name] ko ginti 42 chha" — the item and the number you counted on the shelf.',
    'np' => 'Ginti jastai gannu bhayo tyastai bhannus — "[saman ko naam] ko ginti 42 chha" — saman ra shelf ma ganeko number.',
    'hi' => 'Ginti jaise aapne ginni waisi bataiye — "[saaman ka naam] ko ginti 42 chha" — saaman aur shelf par gina number.',
    'dev' => 'गिन्ती जस्तै गन्नुभयो त्यस्तै भन्नुस् — "[सामानको नाम] को गिन्ती 42 छ" — सामान र shelf मा गनेको नम्बर।'],
  // --- register order prep (owner directive, Oct 28) -------------------------
  'ord_none_needed' => [
    'en' => '%s — nothing needs ordering today: every line on its list is covered (%d lines checked). No card staged.',
    'np' => '%s — aaja order chahinna: usko list ko sabai line covered chha (%d line heriyo). Card stage bhayena.',
    'hi' => '%s — aaj order ki zaroorat nahi: uski list ki har line covered hai (%d line dekhi). Card stage nahi hua.',
    'dev' => '%s — आज अर्डर चाहिन्न: उसको सूचीको सबै लाइन covered छ (%d लाइन हेरियो)। कार्ड स्टेज भएन।'],
  'ord_head' => [
    'en' => 'Order draft for %s (rep day %s) — Gol\'s draft from the shelf, sales since the last delivery, and the usual bill lines. Edit in chat ("[item] 12 hata" to strike a line, "[item] 20 thap" to set its quantity), then say "gol" to confirm the sheet.',
    'np' => 'Order draft — %s (rep din %s) — shelf, pachhillo delivery pachhi ko bikri, ra sadhain ko bill line bata Gol ko draft. Chat ma edit garnus ("[item] 12 hata" = line kataune, "[item] 20 thap" = sankhya rakhne), ani sheet pakka garna "gol" bhannus.',
    'hi' => 'Order draft — %s (rep din %s) — shelf, pichhli delivery ke baad ki bikri, aur aam bill lines se Gol ka draft. Chat mein edit kijiye ("[item] 12 hata" = line kaatna, "[item] 20 thap" = sankhya rakhna), phir sheet pakka karne ke liye "gol" kahiye.',
    'dev' => 'अर्डर ड्राफ्ट — %s (रेप दिन %s) — सेल्फ, पछिल्लो डेलिभरीपछिको बिक्री, र सधैंको बिल लाइनबाट Gol को ड्राफ्ट। च्याटमा edit गर्नुस् ("[item] 12 hata" = लाइन काट्ने, "[item] 20 thap" = संख्या राख्ने), अनि sheet पक्का गर्न "गोल" भन्नुस्।'],
  'ord_line' => [
    'en' => '• %s × %d%s',
    'np' => '• %s × %d%s',
    'hi' => '• %s × %d%s',
    'dev' => '• %s × %d%s'],
  'ord_est' => [
    'en' => 'Estimated total: %s%s.',
    'np' => 'Anumanit jamma: %s%s.',
    'hi' => 'Andaz kul: %s%s.',
    'dev' => 'अनुमानित जम्मा: %s%s।'],
  'ord_uncosted' => [
    'en' => ' (%d line(s) have no cost on record, so they are outside the estimate)',
    'np' => ' (%d line ko cost record chhaina, tyasaile anumaan bhitra chhaina)',
    'hi' => ' (%d line ki cost record nahi hai, isliye andaz mein nahi)',
    'dev' => ' (%d लाइनको लागत रेकर्ड छैन, त्यसैले अनुमानभित्र छैन)'],
  'ord_fine' => [
    'en' => '%d more line(s) on its list are covered.',
    'np' => 'Us ko list ka aru %d line covered chha.',
    'hi' => 'Uski list ki aur %d line covered hain.',
    'dev' => 'उसको सूचीका अरू %d लाइन covered छन्।'],
  'ord_edited' => [
    'en' => 'Order updated — %s now has %d line(s), est. %s. Say "gol" to confirm the sheet, or keep editing.',
    'np' => 'Order update bhayo — %s ma aba %d line, anumaan %s. Sheet pakka garna "gol" bhannus, wa edit gardai garnus.',
    'hi' => 'Order update ho gaya — %s mein ab %d line, andaz %s. Sheet pakka karne ke liye "gol" kahiye, ya edit jaari rakhen.',
    'dev' => 'अर्डर update भयो — %s मा अब %d लाइन, अनुमान %s। Sheet पक्का गर्न "गोल" भन्नुस्, वा edit गर्दै गर्नुस्।'],
  'ord_edit_none' => [
    'en' => 'I couldn\'t read that edit — say it like "[item] 12 hata" (strike) or "[item] 20 thap" (set 20). The order card is unchanged.',
    'np' => 'Tyo edit bujhina — "[item] 12 hata" (kataune) wa "[item] 20 thap" (20 rakhne) jastai bhannus. Order card ustai chha.',
    'hi' => 'Woh edit samajh nahi aaya — "[item] 12 hata" (kaatna) ya "[item] 20 thap" (20 rakhna) jaise kahiye. Order card waisa hi hai.',
    'dev' => 'त्यो edit बुझिन — "[item] 12 hata" (काट्ने) वा "[item] 20 thap" (20 राख्ने) जस्तै भन्नुस्। अर्डर कार्ड उस्तै छ।'],
  'ord_done' => [
    'en' => 'Order confirmed for %s — %d lines, est. %s. The order sheet is in the Owner panel (Today\'s rep visits → Print / Share) to hand to the rep. Nothing has been sent to the vendor.',
    'np' => 'Order pakka bhayo — %s, %d line, anumaan %s. Order sheet Owner panel ma chha (Today\'s rep visits → Print / Share) — rep lai dina. Vendor lai kehi pathaieko chhaina.',
    'hi' => 'Order pakka ho gaya — %s, %d line, andaz %s. Order sheet Owner panel mein hai (Today\'s rep visits → Print / Share) — rep ko dene ke liye. Vendor ko kuchh nahi bheja gaya.',
    'dev' => 'अर्डर पक्का भयो — %s, %d लाइन, अनुमान %s। अर्डर sheet Owner panel मा छ (Today\'s rep visits → Print / Share) — rep लाई दिन। Vendor लाई केही पठाइएको छैन।'],
  'ord_given' => [
    'en' => 'Marked — the %s order (%s) is recorded as given to the rep.',
    'np' => 'Mark bhayo — %s ko order (%s) rep lai diyeko record bhayo.',
    'hi' => 'Mark ho gaya — %s ka order (%s) rep ko diya record ho gaya.',
    'dev' => 'Mark भयो — %s को अर्डर (%s) rep लाई दिएको रेकर्ड भयो।'],
  'ord_given_none' => [
    'en' => 'There is no confirmed order for %s to mark as given — prepare it first ("%s ko order bana") and confirm with "gol".',
    'np' => '%s ko pakka order chhaina given mark garna — pahile tayar garnus ("%s ko order bana") ani "gol" le pakka garnus.',
    'hi' => '%s ka koi pakka order nahi hai given mark karne ko — pehle taiyaar kijiye ("%s ko order bana") aur "gol" se pakka kijiye.',
    'dev' => '%s को पक्का अर्डर छैन given mark गर्न — पहिले तयार गर्नुस् ("%s को अर्डर बना") अनि "गोल" ले पक्का गर्नुस्।'],
  'ord_status' => [
    'en' => '%s order for %s: %s.',
    'np' => '%s ko %s order: %s.',
    'hi' => '%s ka %s order: %s.',
    'dev' => '%s को %s अर्डर: %s।'],
  'ord_status_none' => [
    'en' => 'No order prepared for %s today yet — say "%s ko order bana" and I\'ll draft it from the shelf.',
    'np' => '%s ko aaja ko order ajhai tayar chhaina — "%s ko order bana" bhannus, shelf bata draft banauchhu.',
    'hi' => '%s ka aaj ka order abhi taiyaar nahi — "%s ko order bana" kahiye, shelf se draft bana dunga.',
    'dev' => '%s को आजको अर्डर अझै तयार छैन — "%s को अर्डर बना" भन्नुस्, shelf बाट draft बनाउँछु।'],
  'ord_which_vendor' => [
    'en' => 'Which vendor\'s order? Today\'s rep day: %s. Or name any vendor from the pack: %s.',
    'np' => 'Kun vendor ko order? Aaja ko rep din: %s. Wa pack ko kunai vendor ko naam linus: %s.',
    'hi' => 'Kis vendor ka order? Aaj ka rep din: %s. Ya pack ke kisi vendor ka naam lijiye: %s.',
    'dev' => 'कुन vendor को अर्डर? आजको रेप दिन: %s। वा pack को कुनै vendor को नाम लिनुस्: %s।'],
  'ord_no_vendors' => [
    'en' => 'No vendors are in the pack yet. Teach me one — "Lipton ko rep sombar aaucha" — and I\'ll keep its days, cadence and usual lines here at the register.',
    'np' => 'Pack ma ajhai vendor chhaina. Euta sikaunus — "Lipton ko rep sombar aaucha" — tesko din, cadence ra sadhain ko line yahi register ma rakhchhu.',
    'hi' => 'Pack mein abhi koi vendor nahi hai. Ek sikhaaiye — "Lipton ko rep sombar aaucha" — uske din, cadence aur aam lines yahi register par rakhunga.',
    'dev' => 'Pack मा अझै vendor छैन। एउटा सिकाउनुस् — "Lipton को rep sombar aaucha" — त्यसको दिन, cadence र सधैंको लाइन यहीँ register मा राख्छु।'],
  'ord_vendor_unknown' => [
    'en' => 'I don\'t have that vendor in the pack. The pack has: %s. Teach a new one with "X ko rep sombar aaucha".',
    'np' => 'Tyo vendor pack ma chhaina. Pack ma chha: %s. Naya "X ko rep sombar aaucha" bhani sikaunus.',
    'hi' => 'Woh vendor pack mein nahi hai. Pack mein hai: %s. Naya "X ko rep sombar aaucha" karke sikhaaiye.',
    'dev' => 'त्यो vendor pack मा छैन। Pack मा छ: %s। नयाँ "X ko rep sombar aaucha" भनी सिकाउनुस्।'],
  'rep_today_none' => [
    'en' => 'No rep is due today. Next visits: %s.',
    'np' => 'Aaja kohi rep aaudaina. Arko bhraman: %s.',
    'hi' => 'Aaj koi rep nahi aayega. Agli visits: %s.',
    'dev' => 'आज कोही rep आउँदैन। अर्को भ्रमण: %s।'],
  'rep_today_head' => [
    'en' => 'Today\'s rep visits at %s:',
    'np' => 'Aaja %s ma rep bhraman:',
    'hi' => 'Aaj %s mein rep visits:',
    'dev' => 'आज %s मा rep भ्रमण:'],
  'rep_hint' => [
    'en' => 'To draft one, say "%s ko order bana".',
    'np' => 'Draft banauna "%s ko order bana" bhannus.',
    'hi' => 'Draft banane ke liye "%s ko order bana" kahiye.',
    'dev' => 'Draft बनाउन "%s को अर्डर बना" भन्नुस्।'],
  'teach_done' => [
    'en' => 'Saved — %s: %s is now %s.',
    'np' => 'Save bhayo — %s: %s aba %s bhayo.',
    'hi' => 'Save ho gaya — %s: %s ab %s hai.',
    'dev' => 'Save भयो — %s: %s अब %s भयो।'],
  'teach_ask' => [
    'en' => 'Which vendor? Say it like "Lipton ko rep sombar aaucha" — the vendor\'s name and the rep\'s day.',
    'np' => 'Kun vendor? "Lipton ko rep sombar aaucha" jastai bhannus — vendor ko naam ra rep ko din.',
    'hi' => 'Kaun sa vendor? "Lipton ko rep sombar aaucha" jaise kahiye — vendor ka naam aur rep ka din.',
    'dev' => 'कुन vendor? "Lipton को rep sombar aaucha" जस्तै भन्नुस् — vendor को नाम र rep को दिन।'],
  'morning_due' => [
    'en' => 'Good morning. Today is %s\'s rep day — the order isn\'t prepared yet. Say "%s ko order bana" and I\'ll draft it from the shelf.',
    'np' => 'Good morning. Aaja %s ko rep din ho — order ajhai tayar chhaina. "%s ko order bana" bhannus, shelf bata draft banauchhu.',
    'hi' => 'Good morning. Aaj %s ka rep din hai — order abhi taiyaar nahi. "%s ko order bana" kahiye, shelf se draft bana dunga.',
    'dev' => 'Good morning। आज %s को rep दिन हो — अर्डर अझै तयार छैन। "%s को अर्डर बना" भन्नुस्, shelf बाट draft बनाउँछु।'],
  // --- day aggregates: best sellers + day compare (hisab D-12) ---------------
  'best_none' => [
    'en' => 'No sales recorded %s yet, so there is no best seller to name.',
    'np' => '%s ahile samma bikri record chhaina, tyasaile best seller bhanna mildaina.',
    'hi' => '%s abhi tak koi bikri record nahi hai, isliye best seller nahi bata sakta.',
    'dev' => '%s अहिलेसम्म बिक्री रेकर्ड छैन, त्यसैले best seller भन्न मिल्दैन।'],
  'best_line' => [
    'en' => 'Best sellers %s — by quantity: %s. By sales: %s.',
    'np' => '%s ko best seller — sankhya le: %s. Bikri le: %s.',
    'hi' => '%s ke best seller — sankhya se: %s. Bikri se: %s.',
    'dev' => '%s को best seller — संख्याले: %s। बिक्रीले: %s।'],
  'cmp_line' => [
    'en' => '%s: %s across %d sales. %s: %s across %d sales. %s',
    'np' => '%s: %s, %d ota sale. %s: %s, %d ota sale. %s',
    'hi' => '%s: %s, %d sale. %s: %s, %d sale. %s',
    'dev' => '%s: %s, %d वटा बिक्री। %s: %s, %d वटा बिक्री। %s'],
  'cmp_diff_up' => [
    'en' => '%s is %s higher.',
    'np' => '%s %s le badhi chha.',
    'hi' => '%s %s zyada hai.',
    'dev' => '%s %s ले बढी छ।'],
  'cmp_diff_down' => [
    'en' => '%s is %s lower.',
    'np' => '%s %s le kam chha.',
    'hi' => '%s %s kam hai.',
    'dev' => '%s %s ले कम छ।'],
  'cmp_diff_even' => [
    'en' => 'The two days are level.',
    'np' => 'Dui din barabar chha.',
    'hi' => 'Dono din barabar hain.',
    'dev' => 'दुवै दिन बराबर छ।'],
  // --- visual reports: graph card titles (owner, Oct 28) ----------------------
  'graph_week' => [
    'en' => 'Last 7 days',
    'np' => 'Pachhillo 7 din',
    'hi' => 'Pichhle 7 din',
    'dev' => 'पछिल्लो ७ दिन'],
  'graph_month' => [
    'en' => 'This month, day by day',
    'np' => 'Yo mahina, din anusar',
    'hi' => 'Is mahina, din ke hisaab se',
    'dev' => 'यो महिना, दिनअनुसार'],
  'graph_best' => [
    'en' => 'Best sellers — %s',
    'np' => 'Best seller — %s',
    'hi' => 'Best seller — %s',
    'dev' => 'बेस्ट सेलर — %s'],
  'graph_compare' => [
    'en' => 'Day compare',
    'np' => 'Din ko tulana',
    'hi' => 'Din ki tulna',
    'dev' => 'दिनको तुलना'],
  'graph_today' => [
    'en' => 'Today',
    'np' => 'Aaja',
    'hi' => 'Aaj',
    'dev' => 'आज'],
  'graph_empty' => [
    'en' => 'Nothing to draw yet — no sales in this range.',
    'np' => 'Ahile korna kei chhaina — yo range ma bikri chhaina.',
    'hi' => 'Abhi kuchh dikhane ko nahi hai — is range mein bikri nahi hai.',
    'dev' => 'अहिले कोर्न केही छैन — यो दायरामा बिक्री छैन।'],
  // --- report card companions: cash block + calendar (owner, Oct 28) ---------
  'cash_lbl' => [
    'en' => 'Cash',
    'np' => 'Cash',
    'hi' => 'Cash',
    'dev' => 'नगद'],
  'card_lbl' => [
    'en' => 'Card',
    'np' => 'Card',
    'hi' => 'Card',
    'dev' => 'कार्ड'],
  'credit_lbl' => [
    'en' => 'Credit',
    'np' => 'Credit',
    'hi' => 'Credit',
    'dev' => 'क्रेडिट'],
  'cash_split_none' => [
    'en' => 'Tender split not recorded for these sales.',
    'np' => 'Yo bikri haru ko tender split record chhaina.',
    'hi' => 'In bikriyon ka tender split record nahi hai.',
    'dev' => 'यी बिक्रीहरूको टेन्डर विभाजन रेकर्ड छैन।'],
  'cal_dows' => [
    'en' => 'Su,Mo,Tu,We,Th,Fr,Sa',
    'np' => 'Su,Mo,Tu,We,Th,Fr,Sa',
    'hi' => 'Ra,So,Ma,Bu,Gu,Shu,Sha',
    'dev' => 'आ,सो,मं,बु,बि,शु,श'],
  'cal_hint' => [
    'en' => 'Tap a day for its report',
    'np' => 'Kunai din ko report ko lagi tyo din ma tap garnus',
    'hi' => 'Kisi din ki report ke liye us din par tap karein',
    'dev' => 'कुनै दिनको रिपोर्टका लागि त्यो दिनमा ट्याप गर्नुहोस्'],
  'named_day_total' => [
    'en' => '%s: %s total (%d sales) — cash %s, card %s.',
    'np' => '%s ko hisab: jamma %s (%d ota sale) — cash %s, card %s.',
    'hi' => '%s ka hisaab: kul %s (%d sale) — cash %s, card %s.',
    'dev' => '%s को हिसाब: जम्मा %s (%d वटा बिक्री) — नगद %s, कार्ड %s।'],
  'named_day_none' => [
    'en' => 'No sales recorded on %s.',
    'np' => '%s ma bikri record chhaina.',
    'hi' => '%s ko koi bikri record nahi hai.',
    'dev' => '%s मा बिक्री रेकर्ड छैन।'],
  'pay_pending' => [
    'en' => 'Your pay request is already with the owner: %s for %s — waiting on the owner. I won\'t send a second one.',
    'np' => 'Tapai ko talab ko anurodh pahile nai malik samma pugisakyo: %s (%s ko lagi) — malik ko jawaf parkhidaichha. Dosro pathaudina.',
    'hi' => 'Aapki pay request pehle se hi owner ke paas hai: %s (%s ke liye) — owner ke jawab ka intezaar hai. Dusri nahi bhejunga.',
    'dev' => 'तपाईंको तलबको अनुरोध पहिल्यै मालिकसम्म पुगिसक्यो: %s (%s को लागि) — मालिकको जवाफ पर्खिँदै छ। दोस्रो पठाउँदिनँ।'],
  'pay_no_rate' => [
    'en' => 'Your hourly rate is not on the register record yet, so I can\'t price your hours. The owner sets it in chat ("my rate" is his to change) — ask him to set it and I\'ll do the math right away.',
    'np' => 'Tapai ko rate ahile register ko record ma chhaina, tesaile ghanta ko hisab garna mildaina. Malik le chat mai rate rakhchan — unlai bhannus, rate aune bittikai hisab garchhu.',
    'hi' => 'Aapki rate abhi register record mein nahi hai, isliye ghante ka hisaab nahi ho payega. Owner chat mein rate rakhte hain — unse kahiye, rate aate hi hisaab karta hoon.',
    'dev' => 'तपाईंको दर अहिले रजिस्टरको रेकर्डमा छैन, त्यसैले घण्टाको हिसाब गर्न मिल्दैन। मालिकले च्याटमै दर राख्छन् — उनलाई भन्नुस्, दर आउने बित्तिकै हिसाब गर्छु।'],
  'pay_unrated' => [
    'en' => 'Some of your hours fall on days with no rate on record (%s), so I can\'t price those yet — the owner sets the rate and then I can. Nothing was sent.',
    'np' => 'Tapai ka kehi ghanta rate record nabhaeka din ma pare (%s) — tiniharu ko hisab ahile mildaina, malik le rate rakhepachhi hunchha. Kei pathaieko chhaina.',
    'hi' => 'Aapke kuchh ghante un dinon par hain jinki rate record mein nahi (%s) — unka hisaab abhi nahi hoga, owner rate rakhe to hoga. Kuchh nahi bheja gaya.',
    'dev' => 'तपाईंका केही घण्टा दर रेकर्ड नभएका दिनमा परे (%s) — तिनीहरूको हिसाब अहिले मिल्दैन, मालिकले दर राखेपछि हुन्छ। केही पठाइएको छैन।'],
  'pay_no_hours' => [
    'en' => 'I find no register hours on record for you since %s — no unlock-to-lock shift is recorded in that window, so there is nothing to price. If you worked a shift the register didn\'t record, tell me ("I worked Tuesday 4 hours") and it will ride to the owner as your correction, in your words.',
    'np' => '%s dekhi tapai ko register ghanta record ma bhettina — tyo bich ma unlock dekhi lock samma ko shift record chhaina, hisab garne kei chhaina. Register le record nagareko shift garnubhaeko chha bhane bhannus ("ma mangalbar 4 ghanta thiye") — tapai kai shabda ma malik samma correction ko roop ma janchha.',
    'hi' => '%s se aapke register ghante record mein nahi mile — us beech unlock se lock tak ki koi shift record nahi, hisaab ke liye kuchh nahi. Agar koi shift register mein record nahi hui to batayein ("main mangalvaar 4 ghante thi") — woh aapke hi shabdon mein owner tak correction ban kar jayegi.',
    'dev' => '%s देखि तपाईंको रजिस्टर घण्टा रेकर्डमा भेटिनँ — त्यो बीचमा अनलकदेखि लकसम्मको शिफ्ट रेकर्ड छैन, हिसाब गर्ने केही छैन। रजिस्टरले रेकर्ड नगरेको शिफ्ट गर्नुभएको छ भने भन्नुस् ("म मंगलबार ४ घण्टा थिएँ") — तपाईं कै शब्दमा मालिकसम्म करेक्सनको रूपमा जान्छ।'],
  'pay_sent' => [
    'en' => 'Sent to the owner: %s is asking for pay — %s. You\'ll see his answer here.',
    'np' => 'Malik lai pathaiyo: %s le talab mageko — %s. Jawaf yahi dekhna paunuhunchha.',
    'hi' => 'Owner ko bheja gaya: %s pay maang rahi hai — %s. Jawab yahin dikhega.',
    'dev' => 'मालिकलाई पठाइयो: %s ले तलब मागेको — %s। जवाफ यहीँ देख्न पाउनुहुनेछ।'],
  'pay_declined_note' => [
    'en' => 'The owner declined your last pay request (%s for %s). ',
    'np' => 'Malik le tapai ko aghillo talab anurodh aswikar garnubhaeko chha (%s, %s ko lagi). ',
    'hi' => 'Owner ne aapki pichhli pay request mana kar di thi (%s, %s ke liye). ',
    'dev' => 'मालिकले तपाईंको अघिल्लो तलब अनुरोध अस्वीकार गर्नुभएको छ (%s, %s को लागि)। '],
  'pay_paid_note' => [
    'en' => 'Your last request was paid: %s for %s. ',
    'np' => 'Tapai ko aghillo anurodh talab paiyo: %s, %s ko lagi. ',
    'hi' => 'Aapki pichhli request ka pay ho gaya: %s, %s ke liye. ',
    'dev' => 'तपाईंको अघिल्लो अनुरोधको तलब पाइयो: %s, %s को लागि। '],
  'pay_approved' => [
    'en' => 'Paid and booked (ref %s): %s — %s.',
    'np' => 'Talab diyo, record ma chadhisakyo (ref %s): %s — %s.',
    'hi' => 'Pay ho gaya aur book ho gaya (ref %s): %s — %s.',
    'dev' => 'तलब दिइयो, रेकर्डमा चढिसक्यो (ref %s): %s — %s।'],
  'pay_already_decided' => [
    'en' => 'That request is no longer waiting — it was already decided.',
    'np' => 'Tyo anurodh ab parkhiraheko chhaina — nirnaya bhaisakyo.',
    'hi' => 'Woh request ab intezaar mein nahi hai — faisla ho chuka hai.',
    'dev' => 'त्यो अनुरोध अब पर्खिरहेको छैन — निर्णय भइसक्यो।'],
  'pay_declined_done' => [
    'en' => 'Declined: %s\'s pay request (%s) is closed as declined. She\'ll see it in her session.',
    'np' => 'Aswikar bhayo: %s ko talab anurodh (%s) aswikar ma banda bhayo. Usle aafno session ma dekhchhe.',
    'hi' => 'Mana ho gaya: %s ki pay request (%s) declined band ho gayi. Use apne session mein dikhega.',
    'dev' => 'अस्वीकार भयो: %s को तलब अनुरोध (%s) अस्वीकारमा बन्द भयो। उसले आफ्नो सेसनमा देख्छे।'],
  'pay_owner_hint' => [
    'en' => 'Pay asks belong to the employee\'s own session — she asks, you decide. Say "pay requests" to see what is waiting on you.',
    'np' => 'Talab magne kaam employee kai session bata hunchha — usle magchha, tapai le nirnaya garnuhunchha. Parkhiraheko herna "pay requests" bhannus.',
    'hi' => 'Pay maangna employee ke apne session se hota hai — woh maangti hai, aap faisla karte hain. Intezaar wala dekhne ke liye "pay requests" kahiye.',
    'dev' => 'तलब माग्ने काम कर्मचारीकै सेसनबाट हुन्छ — उसले माग्छ, तपाईंले निर्णय गर्नुहुन्छ। पर्खिरहेको हेर्न "pay requests" भन्नुस्।'],
];
function t(string $key, ...$args): string {
    global $S, $L;
    $s = $S[$key][$L] ?? $S[$key]['en'];
    return $args ? vsprintf($s, $args) : $s;
}

// --- receipt print settings: chat door ---------------------------------------
// The settings live in ONE store (store.php golpos_settings); the owner
// panel's form is the manual door to the same values. Here they are read on
// ask and changed by staged card — old -> new, executed only on "gol".
function golpos_onoff(bool $b): string {
    global $L;
    return $b ? ['en' => 'on', 'np' => 'on', 'hi' => 'on', 'dev' => 'खुला'][$L]
              : ['en' => 'off', 'np' => 'bandha (off)', 'hi' => 'band (off)', 'dev' => 'बन्द'][$L];
}
function golpos_shown(bool $b): string {
    global $L;
    return $b ? ['en' => 'shown', 'np' => 'dekhine', 'hi' => 'dikhega', 'dev' => 'देखिने'][$L]
              : ['en' => 'hidden', 'np' => 'lukeko', 'hi' => 'chhupa hua', 'dev' => 'लुकेको'][$L];
}
function golpos_setting_label(string $key): string {
    global $L;
    $map = [
      'store_name'   => ['en' => 'Store name', 'np' => 'Pasal ko naam', 'hi' => 'Dukaan ka naam', 'dev' => 'पसलको नाम'],
      'address'      => ['en' => 'Address', 'np' => 'Address (thegana)', 'hi' => 'Address (pata)', 'dev' => 'ठेगाना'],
      'show_address' => ['en' => 'Address line', 'np' => 'Address line', 'hi' => 'Address line', 'dev' => 'ठेगाना लाइन'],
      'phone'        => ['en' => 'Phone', 'np' => 'Phone', 'hi' => 'Phone', 'dev' => 'फोन'],
      'show_phone'   => ['en' => 'Phone line', 'np' => 'Phone line', 'hi' => 'Phone line', 'dev' => 'फोन लाइन'],
      'logo'         => ['en' => 'Logo on receipt', 'np' => 'Receipt ma logo', 'hi' => 'Receipt par logo', 'dev' => 'रसिदमा लोगो'],
      'footer'       => ['en' => 'Footer message', 'np' => 'Footer message', 'hi' => 'Footer message', 'dev' => 'फुटर सन्देश'],
      'copies'       => ['en' => 'Copies', 'np' => 'Copy sankhya', 'hi' => 'Copies', 'dev' => 'प्रति संख्या'],
      'paper_width'  => ['en' => 'Paper width', 'np' => 'Paper width', 'hi' => 'Paper width', 'dev' => 'कागज चौडाइ'],
      'show_tax'     => ['en' => 'Tax line', 'np' => 'Tax line', 'hi' => 'Tax line', 'dev' => 'कर लाइन'],
      'auto_open'    => ['en' => 'Receipt after each sale', 'np' => 'Harek sale pachhi receipt', 'hi' => 'Har sale ke baad receipt', 'dev' => 'हरेक बिक्रीपछि रसिद'],
      'store_open'   => ['en' => 'Store opens', 'np' => 'Pasal khulne samaya', 'hi' => 'Dukaan khulne ka samay', 'dev' => 'पसल खुल्ने समय'],
      'store_close'  => ['en' => 'Store closes', 'np' => 'Pasal banda hune samaya', 'hi' => 'Dukaan band hone ka samay', 'dev' => 'पसल बन्द हुने समय'],
      'batch_mode'   => ['en' => 'Batch close mode', 'np' => 'Batch close mode', 'hi' => 'Batch close mode', 'dev' => 'ब्याच क्लोज मोड'],
      'card_surcharge_pct' => ['en' => 'Card surcharge', 'np' => 'Card surcharge', 'hi' => 'Card surcharge', 'dev' => 'कार्ड सरचार्ज'],
      'watch_frame'  => ['en' => 'Camera frame on cash sales', 'np' => 'Cash sale ma camera frame', 'hi' => 'Cash sale par camera frame', 'dev' => 'नगद बिक्रीमा क्यामेरा फ्रेम'],
      'register_camera_audio' => ['en' => 'Camera audio (mic)', 'np' => 'Camera audio (mic)', 'hi' => 'Camera audio (mic)', 'dev' => 'क्यामेरा अडियो (माइक)'],
      'register_camera_record' => ['en' => 'Event footage recording', 'np' => 'Event footage record', 'hi' => 'Event footage record', 'dev' => 'इभेन्ट फुटेज रेकर्ड'],
      'refunds_allowed' => ['en' => 'Refunds allowed', 'np' => 'Refund khula', 'hi' => 'Refund khula', 'dev' => 'फिर्ता खुला'],
      'refund_pin'   => ['en' => 'Refund PIN', 'np' => 'Refund ma PIN', 'hi' => 'Refund par PIN', 'dev' => 'फिर्तामा PIN'],
      'refund_window_days' => ['en' => 'Refund window', 'np' => 'Refund ko samaya (din)', 'hi' => 'Refund window (din)', 'dev' => 'फिर्ता अवधि (दिन)'],
      'credit_redeem' => ['en' => 'Credit redeem at the till', 'np' => 'Till ma credit chalaune', 'hi' => 'Till par credit', 'dev' => 'टिलमा क्रेडिट चलाउने'],
    ];
    return $map[$key][$L] ?? $map[$key]['en'] ?? $key;
}
// "21:00" -> "9:00 PM" for spoken replies.
function golpos_time12(string $hhmm): string {
    $ts = strtotime($hhmm);
    return $ts === false ? $hhmm : date('g:i A', $ts);
}
function golpos_setting_display(string $key, $v): string {
    if (in_array($key, ['show_address', 'show_phone', 'show_tax'], true)) { return golpos_shown((bool)$v); }
    if (in_array($key, ['logo', 'auto_open', 'watch_frame', 'register_camera_audio', 'register_camera_record', 'refunds_allowed', 'refund_pin', 'credit_redeem'], true)) { return golpos_onoff((bool)$v); }
    if ($key === 'refund_window_days') { return (string)(int)$v . ((int)$v === 1 ? ' day' : ' days'); }
    if ($key === 'card_surcharge_pct') { return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . '%'; }
    if ($key === 'copies') { return (string)(int)$v; }
    if ($key === 'store_open' || $key === 'store_close') { return golpos_time12((string)$v); }
    return (string)$v;
}
function golpos_settings_read_reply(): string {
    $s = golpos_settings();
    $lines = [t('settings_head')];
    $lines[] = '• ' . golpos_setting_label('store_name') . ': ' . $s['store_name'];
    $lines[] = '• ' . golpos_setting_label('address') . ': ' . $s['address'] . ' (' . golpos_shown((bool)$s['show_address']) . ')';
    $lines[] = '• ' . golpos_setting_label('phone') . ': ' . $s['phone'] . ' (' . golpos_shown((bool)$s['show_phone']) . ')';
    $lines[] = '• ' . golpos_setting_label('logo') . ': ' . golpos_onoff((bool)$s['logo']);
    $lines[] = '• ' . golpos_setting_label('footer') . ': "' . $s['footer'] . '"';
    $lines[] = '• ' . golpos_setting_label('copies') . ': ' . (int)$s['copies'];
    $lines[] = '• ' . golpos_setting_label('paper_width') . ': ' . $s['paper_width'];
    $lines[] = '• ' . golpos_setting_label('show_tax') . ': ' . golpos_shown((bool)$s['show_tax']);
    $lines[] = '• ' . golpos_setting_label('auto_open') . ': ' . golpos_onoff((bool)$s['auto_open']);
    $lines[] = '• ' . golpos_setting_label('store_open') . ': ' . golpos_time12((string)$s['store_open']);
    $lines[] = '• ' . golpos_setting_label('store_close') . ': ' . golpos_time12((string)$s['store_close']);
    $lines[] = '• ' . golpos_setting_label('batch_mode') . ': ' . $s['batch_mode']
        . ($s['batch_mode'] === 'auto' ? ' (seals at ' . golpos_time12((string)$s['store_close']) . ')' : '');
    $lines[] = '• ' . golpos_setting_label('card_surcharge_pct') . ': '
        . golpos_setting_display('card_surcharge_pct', $s['card_surcharge_pct']);
    $lines[] = '• ' . golpos_setting_label('watch_frame') . ': ' . golpos_onoff((bool)$s['watch_frame']);
    $rc = golpos_regcam_state();
    if ($rc['mode'] === 'link') {
        $lines[] = '• Register Camera (MR.STORE master): ' . golpos_onoff($rc['master'])
            . ' · audio ' . golpos_onoff($rc['audio']) . ' · footage ' . golpos_onoff($rc['record']);
    } else {
        $lines[] = '• ' . golpos_setting_label('register_camera_audio') . ': ' . golpos_onoff((bool)$s['register_camera_audio']);
        $lines[] = '• ' . golpos_setting_label('register_camera_record') . ': ' . golpos_onoff((bool)$s['register_camera_record']);
    }
    $lines[] = '• ' . golpos_setting_label('refunds_allowed') . ': ' . golpos_onoff((bool)$s['refunds_allowed']);
    $lines[] = '• ' . golpos_setting_label('refund_pin') . ': ' . golpos_onoff((bool)$s['refund_pin']);
    $lines[] = '• ' . golpos_setting_label('refund_window_days') . ': '
        . golpos_setting_display('refund_window_days', $s['refund_window_days']);
    $lines[] = '• ' . golpos_setting_label('credit_redeem') . ': ' . golpos_onoff((bool)$s['credit_redeem']);
    $lines[] = '• Login to MR.STORE: Settings panel — opens this store in MR.STORE (owner/admin session)';
    return implode("\n", $lines);
}
// Stage one settings change as a card (old -> new). A value that already
// matches is answered, not staged — a no-op never earns a card.
function golpos_stage_setting(string $key, $new): void {
    $old = golpos_settings()[$key] ?? null;
    if ($old === $new) {
        out(['reply' => t('setting_same', golpos_setting_label($key), golpos_setting_display($key, $new))]);
    }
    $card = golpos_stage(['type' => 'setting_change', 'setting' => $key,
                          'label' => golpos_setting_label($key), 'old' => $old, 'new' => $new]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
// Toggle polarity from the owner's words: true = wants it OFF, false = ON,
// null = no toggle verb present (the message is setting a value instead).
function golpos_wants_off(string $msg): ?bool {
    if (preg_match('/(hide|bandha|lukau|hata|बन्द|लुकाउ|हटाउ)/iu', $msg) || preg_match('/\boff\b/iu', $msg)) { return true; }
    if (preg_match('/(show|khola|dekha|खोल|देखाउ)/iu', $msg) || preg_match('/\bon\b/iu', $msg)) { return false; }
    return null;
}

// --- access intents: matchers shared by the PIN gate and the dispatch --------
// The gate (bottom of this file) must classify a message EXACTLY as the
// dispatch will treat it, so both use these same matchers. Admin-domain
// intents: reports beyond the running day total, the day-close record, batch
// close, employee management, the loyalty leaderboard — plus every staged
// card whose EXECUTION changes the business (price_change, new_item,
// setting_change, batch_close, employee_remove), which is gated at the
// confirm word rather than at staging.
function golpos_is_lock_intent(string $msg): bool {
    return preg_match('/^\s*(lock|log\s*out|admin\s+off)\s*[.!]?\s*$/iu', $msg) === 1
        || preg_match('/(admin bata bahira|lock gara|लक गर)/iu', $msg) === 1;
}
// PIN / admin guidance intents (owner trace, Oct 31): the words an owner
// naturally types when he wants the PIN itself — set / change / create,
// Romanized Nepali verbs, a forgot-PIN, a register-lock question. Pure
// "lock" is NOT here (golpos_is_lock_intent owns it, above); a bare
// "admin"/"pin" is handled by the gate when unelevated and by the
// guidance intercept when the session is already elevated.
function golpos_is_pin_help_intent(string $msg): bool {
    if (preg_match('/\b(set|change|reset|create|make)\s+(a\s+)?(new\s+)?(admin\s+)?pin\b/iu', $msg)) { return true; }
    if (preg_match('/\bpin\s+(set|change|reset|setup|banaune|banauna|banau|badalne|badalna|badlau|kasari)\b/iu', $msg)) { return true; }
    if (preg_match('/\b(admin\s+pin|forgot\s+(my\s+)?pin|pin\s+birse|pin\s+bhule|pin\s+bhulyo)\b/iu', $msg)) { return true; }
    if (preg_match('/\bregister\s+lock\b/iu', $msg)) { return true; }
    if (preg_match('/(पिन\s*(बनाउ|बदल|सेट))/iu', $msg)) { return true; }
    return false;
}
// The truthful PIN guidance for THIS register's state: no admin PIN yet
// (create path via an admin action's gate) vs a PIN exists (the owner's
// set/change paths + the lock path). Never invents a step the code lacks.
function golpos_pin_help_reply(): string {
    return golpos_admin_pin_hash() === null ? t('pin_help_none') : t('pin_help_have');
}
// PIN set/reset asks (TASKS 230/231, Nov 2): the OWNER sets every PIN —
// his own admin PIN and each employee's — and hands them out; an
// employee can never set or reset a PIN. The ask names the person (or
// the admin PIN) with a set verb; the digits may ride in the same
// message. Staging lives in the dispatch lane; the confirm runs through
// the admin gate like every roster write (golpos_needs_admin).
function golpos_is_pin_set_intent(string $msg): bool {
    if (!preg_match('/\bpin\b|पिन/iu', $msg)) { return false; }
    if (preg_match('/\b(set|change|reset|make|give|assign)\b/iu', $msg)) { return true; }
    if (preg_match('/\b(rakh\w*|badal\w*|fer|hal\w*|सेट|बदल|राख|फेर|हाल)/iu', $msg)) { return true; }
    // No verb: a named person + a 4–6 digit run still reads as a set ask.
    return golpos_pin_set_target($msg) !== null
        && preg_match('/\b\d{4,6}\b/', golpos_norm_digits($msg)) === 1;
}
// Who a PIN-set ask is about: the admin PIN, or one roster employee
// named by email, full name or first name. Null when nobody is named —
// the lane then asks who, and never guesses.
function golpos_pin_set_target(string $msg): ?array {
    if (preg_match('/\b(admin|owner)\b[^.]{0,24}\bpin\b/iu', $msg)
        || preg_match('/\bpin\b[^.]{0,24}\b(admin|owner)\b/iu', $msg)
        || preg_match('/\b(mero|my)\s+pin\b/iu', $msg)) {
        return ['kind' => 'admin', 'email' => '', 'name' => 'Owner (admin PIN)'];
    }
    $low = mb_strtolower($msg);
    foreach (golpos_people()['employees'] as $e) {
        $email = (string)($e['email'] ?? '');
        if ($email !== '' && strpos($low, $email) !== false) {
            return ['kind' => 'employee', 'email' => $email, 'name' => golpos_display_name($e)];
        }
    }
    foreach (golpos_people()['employees'] as $e) {
        $name = trim((string)($e['name'] ?? ''));
        if ($name === '') { continue; }
        $first = explode(' ', mb_strtolower($name))[0];
        foreach (array_unique([mb_strtolower($name), $first]) as $cand) {
            if ($cand !== '' && preg_match('/\b' . preg_quote($cand, '/') . '\b/iu', $msg)) {
                return ['kind' => 'employee', 'email' => (string)$e['email'],
                        'name' => golpos_display_name($e)];
            }
        }
    }
    return null;
}
// "Who is on this register?" (owner addition, Oct 31): the ask names no
// person — it asks for the register's CURRENT actor(s). Distinct from a
// presence ask about a named employee (golpos_parse_presence_ask), which
// stays admin-gated exactly as before.
function golpos_is_who_intent(string $msg): bool {
    if (preg_match('/\bwho\b.{0,30}\b(logged|signed|unlocked|login|on the register)\b/iu', $msg)) { return true; }
    if (preg_match('/(ko|kun|kasle)\b.{0,25}(logged|login|unlock|kholeko|kholyo)\b/iu', $msg)) { return true; }
    if (preg_match('/\b(logged\s*in|login)\b.{0,12}\b(cha|chha|ko|kun|garne|cha\?)\b/iu', $msg)) { return true; }
    if (preg_match('/(को|कसले|कुन).{0,20}(लगइन|खोलेको|अनलक|लग इन)/u', $msg)) { return true; }
    return false;
}
function golpos_is_cancel_intent(string $msg): bool {
    if (preg_match('/^\s*(cancel|radda|chhod|chhoda|nahune|hudaina|nakara|रद्द|छोड|नगर|नहुने)\s*[.!]?\s*$/iu', $msg) === 1) return true;
    /* audit GOL-04: a cancel SENTENCE ("Cancel the staged action. Do
       not confirm it.") is a cancel too — the anchored set alone fell
       through to the composer and the card stayed armed. Short
       messages only; a plain "do not cancel" vetoes. */
    $t = mb_strtolower(trim($msg));
    $t = trim(rtrim($t, '.!?~। '));
    if ($t === '' || count(preg_split('/\s+/u', $t) ?: []) > 12) return false;
    $dnc = (strpos($t, 'do not confirm') !== false || strpos($t, "don't confirm") !== false
        || strpos($t, 'confirm nagara') !== false || strpos($t, 'confirm nagarne') !== false);
    if (!$dnc && preg_match('/\b(do not|don\'t|dont|never)\s+(cancel|stop)\b/u', $t)
        && !preg_match('/^(cancel|stop|radda|rada|रद्द)\b/u', $t)) return false;
    if ($dnc) return true;
    if (preg_match('/^(please\s+)?(cancel|stop|drop|scrap|radda|rada|रद्द|बन्द)\b/u', $t)) return true;
    if (preg_match('/\b(radda|rada)\s+(gara|gar|garideu|gardinu|garnu|karo|kar)\b/u', $t)) return true;
    if (preg_match('/\b(nagareko|nagarnu|nagare|chhod|chhoda|chhodideu)\b/u', $t)) return true;
    if (preg_match('/(रद्द|नगर्नु|नगर|बन्द\s*गर)/u', $t)) return true;
    return false;
}
/* The legacy confirm word (Books parity, Oct 31 final-pass follow-up):
   the whole message is exactly "ho" / "हो" (trailing punctuation
   tolerated) — the pre-Oct-25 confirm habit. Mirrors Books'
   sb_gol_is_legacy_confirm: it never executes and never drops the
   card; against a staged card it earns the migration teach below. */
function golpos_is_legacy_confirm(string $msg): bool {
    $x = trim($msg);
    if ($x === '') return false;
    $x = trim(rtrim($x, '.!?~।'));
    return $x === 'ho' || $x === 'हो';
}
function golpos_is_batch_close_intent(string $msg): bool {
    return preg_match('/(batch\s*(close|banda|seal)|ब्याच)/iu', $msg) === 1;
}
function golpos_is_batch_mode_set(string $msg): bool {
    return preg_match('/batch/iu', $msg) === 1 && preg_match('/\b(auto|manual)\b/iu', $msg) === 1;
}
function golpos_is_employee_intent(string $msg): bool {
    if (preg_match('/(employee|employees|staff|karmachari|कर्मचारी)/iu', $msg)) { return true; }
    // An email plus a roster verb is an employee act even when the
    // word "employee" goes unsaid — "add rita@x as closer" is how the
    // owner actually says it (battery catch, Oct 28: the message fell
    // through to the stock-count lane on the word "ginti").
    return preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $msg) === 1
        && preg_match('/(add|thap|invite|join|remove|hata|nikal|थप|निकाल|हटाउ)/iu', $msg) === 1;
}
function golpos_is_loyalty_board_intent(string $msg): bool {
    return preg_match('/(loyalty|leader\s*board)/iu', $msg) === 1;
}
// A CAPABILITY question (audit GOL-01, Oct 30): the owner asking what
// Gol can do — explicitly ("what can you do", "ke ke garna sakchha")
// or by listing several workflows in a can-you question ("Can you add
// products, show reports, manage employees, change settings, and
// print receipts?"). It must get the capability answer, never the
// first workflow lane whose keyword appears in the list (that list
// used to land in receipt print settings / employee instructions).
function golpos_is_capability_question(string $msg): bool {
    if (preg_match('/(what can you (do|help)|what do you do|how can you help|can you help me|ke ke garna (sak|mil)|kun kun kaam|timile ke ke|तिमीले के के|के के गर्न सक्छ)/iu', $msg)) {
        return true;
    }
    if (strpos($msg, '?') !== false
        && preg_match('/(can you|sakchhau|sakhcha|milcha|गर्न सक्छ)/iu', $msg)) {
        $nouns = 0;
        foreach (['product', 'report', 'employee', 'setting', 'receipt', 'print', 'stock', 'sales', 'bill', 'cart', 'price', 'order'] as $w) {
            if (preg_match('/\b' . $w . '/iu', $msg)) { $nouns++; }
        }
        return $nouns >= 2;
    }
    return false;
}
function golpos_is_hours_read(string $msg): bool {
    if (preg_match('/store\s+hours/iu', $msg)) { return true; }
    if (preg_match('/(khulne|banda).{0,20}(kati|time|samaya|baje|when)/iu', $msg)) { return true; }
    if (preg_match('/(kati baje|kun time|what time).{0,20}(khul|banda|open|close)/iu', $msg)) { return true; }
    return false;
}
function golpos_is_hours_set(string $msg): bool {
    $n = golpos_norm_digits($msg);
    if (!preg_match('/\d{1,2}/', $n)) { return false; }
    if (preg_match('/(store|pasal|shop|पसल)/iu', $msg) && preg_match('/(banda|close|khul|open|बन्द|खुल)/iu', $msg)) { return true; }
    if (preg_match('/(banda|khulne).{0,12}\d{1,2}\s*(baje|pm|am)/iu', $n)) { return true; }
    return false;
}
// A report ask: sales/report FIGURES for a time OTHER than "today so far".
// The meaning is (figure words, or a "kati … bhayo/thiyo" figure shape)
// crossed with a non-today time reference — Romanized Nepali, Hindi,
// English and Devanagari time words all count, as do explicit dates and
// month names. A pure "today so far" ask never carries one of these
// references, so the cashier day total stays open; and a price-of-item
// query that merely names a day ("hijo Coke ko price kati thiyo?") is
// still a price query — figure+non-today ⇒ admin, price-of-item ⇒ cashier.
function golpos_is_report_intent(string $msg): bool {
    // Non-today time references: other days (hijo/asti/kal), last/previous,
    // week-and-month periods, "days ago", an explicit date or month name.
    $other = preg_match('/(hijo|hiju|asti|parsi|bholi|gata|\blast\b|previous|pichhla|pichhle|\bkal\b|agadi|\bago\b'
        . '|hapta|hafte|week|mahina|mahine|month|tarikh|miti'
        . '|हिजो|अस्ति|गत|हप्ता|महिना|मिति)/iu', $msg) === 1
        || preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[\/.\-]\d{1,2}([\/.\-]\d{2,4})?\b/u',
                      golpos_norm_digits($msg)) === 1
        || preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december'
                      . '|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec)\b/iu', $msg) === 1;
    if (!$other) { return false; }
    // Figure-seeking: a figure noun, or the "kati … bhayo/thiyo" ask shape.
    $figureNoun = preg_match('/(report|hisab|hisaab|हिसाब|bikri|बिक्री|sales|sale|total|jamma|kamai|revenue|earning)/iu', $msg) === 1;
    $figure = $figureNoun
        || preg_match('/(kati|kitna|kitni|kitne|कति).{0,40}(bhayo|bho|thiyo|भयो|थियो)/iu', $msg) === 1;
    if (!$figure) { return false; }
    // Price-of-item guard: price words and no figure noun ⇒ a price query
    // that happens to name a day, not a report.
    if (!$figureNoun && preg_match('/(price|mulya|dam|daam|मूल्य|दाम)/iu', $msg) === 1) { return false; }
    return true;
}
// A typed PIN: 4–6 digits and nothing else (Devanagari digits normalized).
function golpos_pin_shaped(string $msg): ?string {
    $d = golpos_norm_digits(trim($msg));
    return preg_match('/^\d{4,6}$/', $d) ? $d : null;
}
// --- employee lane matchers + parsers (owner directive, Oct 28) ----------------
// Shared by the gate and the dispatch, like every access matcher: the
// gate must classify a message exactly as the dispatch will treat it.

// A refund ask. Refunds run from Transactions and from this chat lane
// (the same staged card, the same executor); a refund word must NEVER
// fall into the price lane (alive audit #53: "coke ko refund gara"
// staged a price question).
function golpos_is_refund_ask(string $msg): bool {
    return preg_match('/(refund|refand|farkau|farkaine|फिर्ता|रिफन्ड)/iu', $msg) === 1;
}

// A store-credit ask: a balance read ("credit kati chha?", a phone
// number with "credit") or the credit_redeem setting's toggle words.
// Bare "credit card" talk is tender talk, not this lane — and the card
// surcharge lane never says "credit", so the two cannot collide.
function golpos_is_credit_ask(string $msg): bool {
    // The employee chat door names no credit word at all ("Ram le
    // redeem garna khojchha") — it is a credit ask by shape.
    if (golpos_parse_credit_attach($msg) !== null) { return true; }
    // A calendar shape is never a credit ask (seam catch, Oct 28:
    // "Test Bim ko calendar ma ke chha?" landed here asking for a
    // phone number — the calendar lane owns those sentences).
    if (golpos_cal_parse_read($msg) !== null || golpos_cal_parse_create($msg) !== null) { return false; }
    if (!preg_match('/(credit|points?|क्रेडिट|पोइन्ट)/iu', $msg)) { return false; }
    $hasPhone = preg_match('/\d{7,}/', golpos_norm_digits($msg)) === 1;
    if (preg_match('/\bcard\b/iu', $msg) && !$hasPhone) { return false; }
    return $hasPhone
        || preg_match('/(kati|how much|balance|check|kha|chha|baki|redeem|spend|off|on|bandha|khola|कति|बाँकी)/iu', $msg) === 1;
}

// Parse an assignment: "<name> lai bhandeu / kam deu: <tasks…>". The
// items are the owner's own words, split on commas / "ani" / "and" —
// nothing is invented; a deadline rides only when a time was said.
function golpos_parse_task_assign(string $msg): ?array {
    $m = null;
    if (preg_match('/^(.+?)\s+(?:lai|laai|लाई)\s+(?:yo\s+)?(?:bhandeu|bhanideu|bhana|bhan|kam\s+deu|task\s+deu|deu|dinus|dinuhos)\s*[:\-]?\s*(.+)$/isu', $msg, $mm)) {
        $m = $mm;
    } elseif (preg_match('/^(?:assign|give)\s+(.+?)\s*(?:lai\s+)?[:\-]\s*(.+)$/isu', $msg, $mm)) {
        $m = $mm;
    }
    if ($m === null) { return null; }
    $who = trim((string)preg_replace('/^(please|plz|gol)\s+/iu', '', trim($m[1])));
    $rest = trim($m[2]);
    if ($who === '' || $rest === '') { return null; }
    // "boss lai bhandeu" is a NOTE, never an assignment — the boss is
    // not an assignee. (Battery catch, Oct 28: the assign parser ate
    // the note lane's own opening words.)
    if (preg_match('/^(boss|malik|owner|gol|बस)$/iu', $who)) { return null; }
    $parts = preg_split('/\s*(?:,|;|\n)\s*|\s+\bani\b\s+|\s+\band\b\s+/iu', $rest) ?: [];
    $items = []; $due = null;
    foreach ($parts as $part) {
        $part = trim((string)$part, " \t.!।");
        if ($part === '' || preg_match('/^(kam|task|tasks|kaam)$/iu', $part)) { continue; }
        $items[] = $part;
        if ($due === null && preg_match('/\d{1,2}(?::\d{2})?\s*(?:baje|am|pm)/iu', golpos_norm_digits($part))) {
            $hint = preg_match('/(banda|close|बन्द)/iu', $part) ? 'pm' : 'am';
            $t24 = golpos_parse_time($part, $hint);
            if ($t24 !== null) { $due = date('Y-m-d') . 'T' . $t24 . ':00'; }
        }
    }
    if (!$items) { return null; }
    return ['who' => $who, 'items' => $items, 'due' => $due];
}

// A note to the boss: "boss lai bhandeu: <text>" from the employee's
// own session. Returns the note text or null.
function golpos_parse_team_note(string $msg): ?string {
    if (preg_match('/(?:boss|malik|owner|बस)\s*(?:lai|laai|लाई)?\s*(?:bhandeu|bhanideu|bhana|bhan|pathau|pathaideu)\s*[:\-]?\s*(.+)$/isu', $msg, $m)) {
        $txt = trim($m[1]);
        return $txt !== '' ? $txt : null;
    }
    if (preg_match('/^note\s+for\s+(?:the\s+)?boss\s*[:\-]\s*(.+)$/isu', $msg, $m)) {
        $txt = trim($m[1]);
        return $txt !== '' ? $txt : null;
    }
    return null;
}

// "mero kam ke chha?" / "my tasks" — the employee's own list.
function golpos_is_my_tasks_ask(string $msg): bool {
    if (preg_match('/(mero|my)\s+(kam|task|tasks|काम)/iu', $msg)) { return true; }
    if (preg_match('/^(tasks?|kam|काम)\s*(dekha|list|show|ke|k)/iu', $msg)) { return true; }
    return preg_match('/(kam|task)\s+(ke\s+chha|ke\s+cha|k\s+chha)/iu', $msg) === 1;
}

// An ordinal as spoken: digits, English, Romanized Nepali, Devanagari.
function golpos_ordinal_in(string $msg): ?int {
    $map = ['first' => 1, 'pahilo' => 1, 'पहिलो' => 1,
            'second' => 2, 'dosro' => 2, 'दोस्रो' => 2,
            'third' => 3, 'tesro' => 3, 'तेस्रो' => 3,
            'fourth' => 4, 'chautho' => 4, 'चौथो' => 4,
            'fifth' => 5, 'pachau' => 5, 'पाँचौं' => 5];
    $n = golpos_norm_digits(mb_strtolower($msg));
    foreach ($map as $w => $v) {
        if (preg_match('/' . preg_quote($w, '/') . '/iu', $n)) { return $v; }
    }
    if (preg_match('/(\d+)\s*(?:st|nd|rd|th)\b/iu', $n, $m)) { return (int)$m[1]; }
    return null;
}

// Team reads (owner domain): "<name> le ke bhanin?" -> that person's
// notes; "kasle ke sakyo?" -> the whole team's progress.
function golpos_parse_note_ask(string $msg): ?string {
    if (preg_match('/^(.+?)\s+le\s+ke\s+(?:bhanin|bhaneko|bhanyo|bhanin|bhane)/iu', $msg, $m)) { return trim($m[1]); }
    if (preg_match('/(?:what\s+did|notes?\s+from)\s+(.+?)\s*(?:say|bhanin)?\s*\??$/iu', $msg, $m)) { return trim($m[1]); }
    if (preg_match('/^(.+?)\s+(?:ko|ka|ki)\s+(?:note|notes|message|sandesh)/iu', $msg, $m)) { return trim($m[1]); }
    return null;
}
function golpos_is_progress_ask(string $msg): bool {
    if (preg_match('/kasle\s+ke\s+(kam\s+)?(sakyo|sake|sakye)/iu', $msg)) { return true; }
    if (preg_match('/(who\s+finished\s+what|team\s+progress|team\s+status)/iu', $msg)) { return true; }
    return preg_match('/(team|kasle).{0,24}(progress|sakyo|ke\s+bhayo)/iu', $msg) === 1;
}

// A presence ask (owner domain): "<name> ko haal ke chha?", "<name> ko
// barema bhana", "is <name> in?" — answered only when the name truly
// resolves to the roster; anything else falls through untouched.
function golpos_parse_presence_ask(string $msg): ?string {
    if (preg_match('/^(.+?)\s+ko\s+(?:haal|barema|status|presence)/iu', $msg, $m)) { return trim($m[1]); }
    if (preg_match('/^(?:about|barema)\s+(.+?)\s*\??$/iu', $msg, $m)) { return trim($m[1]); }
    if (preg_match('/^is\s+(.+?)\s+in\s*\??$/iu', $msg, $m)) { return trim($m[1]); }
    if (preg_match('/^(.+?)\s+(?:chha|cha|is\s+here|aayeko\s+chha)\s*\??$/iu', $msg, $m)
        && preg_match('/(haal)/iu', $msg)) { return trim($m[1]); }
    return null;
}

// Read a time of day out of a message -> "HH:MM" 24h, or null. With no
// am/pm marker, the hint decides ("store 9 baje banda" means 9 PM; an
// opening "7 baje" means 7 AM).
function golpos_parse_time(string $msg, string $hint): ?string {
    $n = golpos_norm_digits($msg);
    if (!preg_match('/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/iu', $n, $m)) { return null; }
    $h = (int)$m[1]; $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
    $mer = mb_strtolower((string)($m[3] ?? ''));
    if ($h > 23 || $min > 59) { return null; }
    if ($mer === 'pm' && $h < 12) { $h += 12; }
    elseif ($mer === 'am' && $h === 12) { $h = 0; }
    elseif ($mer === '' && $hint === 'pm' && $h >= 1 && $h < 12) { $h += 12; }
    return sprintf('%02d:%02d', $h, $min);
}
// --- Pay Ask matchers + composition (owner directive, Oct 28, 14:30) ---------
// Shared by the gate and the dispatch, like every access matcher. Her
// pay ask is cashier-domain (her own facts); the rate change and the
// owner's request work are admin-domain.

// Her ask, in her shapes: "can I get my pay", "mero talab?", "talab
// paincha?", a salary/paycheck ask in first person. Tight on purpose:
// a bare "pay" in a customer sentence at the till is NOT this lane.
function golpos_is_pay_ask(string $msg): bool {
    if (preg_match('/(talab|talav|jyala|तलब|ज्याला)/iu', $msg)) {
        // A rate fact about someone ("pranisha ko talab 13") is the
        // owner's rate lane, not her ask — that shape carries a number
        // or a change word next to the pay word; her ask never does.
        if (golpos_parse_rate_change($msg) !== null) { return false; }
        return true;
    }
    return preg_match('/\b(my\s+(pay|salary|paycheck|pay\s*check|wages?)'
        . '|(can|could|may)\s+i\s+(get|have|take)\s+my\s+(pay|salary)'
        . '|pay\s+me\b|get\s+paid|i\s+want\s+my\s+(pay|salary))\b/iu', $msg) === 1;
}

// The owner's side of the lane: what is waiting on him.
function golpos_is_pay_requests_ask(string $msg): bool {
    return preg_match('/(pay\s+requests?|talab\s+(mageko|anurodh)|kasle\s+(pay|talab)|pay\s+mageko'
        . '|pending\s+pay|pay\s+pending)/iu', $msg) === 1;
}

// A refusal word over a staged card. With a pay_approve card staged
// this is a DECISION (the request closes declined), intercepted before
// the generic cancel check — plain "cancel/radda" still only clears
// the card and leaves the request waiting.
function golpos_is_decline_intent(string $msg): bool {
    return preg_match('/^\s*(decline|declined|reject|rejected|nahune|hudaina|nagarne|no\s+gara)\s*[.!]?\s*$/iu', $msg) === 1;
}

// The owner's rate change: a roster name + a pay word + a number —
// "Pranisha ko pay 13 bana", "Pranisha's rate is now $13", "pranisha
// ko talab 2 le badha" (by $2). Returns ['emp', 'by', 'amount'] or
// null. 'by' phrasing computes old+amount and the CARD shows the
// resulting old→new, so a by/to misread is caught before "gol".
function golpos_parse_rate_change(string $msg): ?array {
    if (!preg_match('/(pay|rate|talab|talav|jyala|salary|तलब|ज्याला|दर)/iu', $msg)) { return null; }
    $n = golpos_norm_digits($msg);
    if (!preg_match('/\$?\s*(\d+(?:\.\d{1,2})?)/', $n, $m)) { return null; }
    $amount = (float)$m[1];
    if ($amount <= 0 || $amount > 500) { return null; }
    $hits = [];
    foreach (golpos_people()['employees'] as $e) {
        if (($e['status'] ?? '') === 'removed') { continue; }
        $names = [golpos_display_name($e), (string)($e['name'] ?? '')];
        foreach ((array)($e['aliases'] ?? []) as $a) { $names[] = (string)$a; }
        $first = explode(' ', golpos_display_name($e))[0] ?? '';
        if ($first !== '') { $names[] = $first; }
        foreach (array_unique(array_filter($names)) as $nm) {
            if (preg_match('/(^|[^\p{L}])' . preg_quote($nm, '/') . '([^\p{L}]|$)/iu', $msg)) {
                $hits[] = $e; break;
            }
        }
    }
    if (count($hits) !== 1) { return null; }
    $by = preg_match('/(\bby\b|\ble\s+badha|\bbadha|\bincrease\b|\braise\b|\+)/iu', $msg) === 1
        && !preg_match('/(is\s+now|aba|now\s*\$?\s*\d)/iu', $msg);
    return ['emp' => $hits[0], 'by' => $by, 'amount' => $amount];
}

// A correction to a staged pay card: HER words about a shift the
// register didn't evidence — a weekday and an hour count ("ma
// mangalbar 4 ghanta thiye"), or an explicit "correction:" lead. The
// line rides the owner's card VERBATIM; it never merges into the
// evidenced hours (the spec's honesty rule).
function golpos_is_pay_correction(string $msg): bool {
    if (preg_match('/^\s*(correction|sachai|sudhaar)\s*[:\-]/iu', $msg)) { return true; }
    $days = '(monday|tuesday|wednesday|thursday|friday|saturday|sunday'
          . '|aaitabar|aitabar|sombar|mangalbar|budhabar|bihibar|shukrabar|shanibar'
          . '|सोमबार|मंगलबार|बुधबार|बिहीबार|शुक्रबार|शनिबार|आइतबार)';
    return preg_match('/' . $days . '/iu', $msg) === 1
        && preg_match('/\d+\s*(ghanta|ghante|hour|hrs|hr\b|घण्टा)/iu', golpos_norm_digits($msg)) === 1;
}

// The one-line headline every brain surface shares: "38.5 hrs × $11
// = $423.50" — or, across a rate change, one segment per rate:
// "20 hrs × $11 + 18.5 hrs × $13 = $460.50". Hours trim trailing zeros.
function golpos_pay_headline(array $segments, float $total): string {
    $hrs = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $parts = [];
    foreach ($segments as $s) {
        $parts[] = $hrs($s['hours']) . ' hrs × ' . money((float)$s['rate']);
    }
    return ($parts ? implode(' + ', $parts) : '0 hrs') . ' = ' . money($total);
}

// The invite link an employee receives. Derived from the request so the
// same code works on the demo preview and any future install.
function golpos_invite_link(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/server/brain.php'))), '/');
    return $scheme . '://' . $host . $dir . '/web/invite.html?t=' . $token;
}
// Send the invite in Gol's voice. Returns false when mail refuses, so the
// caller shows the link in chat instead of pretending it sent. Rides the
// ONE mail path (store.php golpos_mail — the same path task mail uses,
// including its test sink).
function golpos_send_invite(string $email, string $name, string $link, string $shop): bool {
    $who = $name !== '' ? $name : $email;
    $subject = "You're invited to join {$shop} on Gol POS";
    $body = "Hi {$who},\n\n"
        . "You have been added as an employee at {$shop}. Gol runs the shop by chat — "
        . "set your own employee PIN from this one-time link and your device will "
        . "remember you at the register:\n\n{$link}\n\n"
        . "The link works once. If you didn't expect it, you can ignore this email.\n\n"
        . "— Gol, your store brain";
    return golpos_mail($email, $subject, $body);
}

// --- PRIMARY BRAIN: the Muse family via the Meta Model API -------------------
// Story (owner-locked, Oct 26): MR.STORE was already a platform. Then Muse
// arrived — and with Muse's APIs, we built Gol, the brain that runs it by
// chat. The full Muse family works inside Gol, each with its own job:
//   Spark  (muse-spark-1.3)  — thinks fast: register-speed composed replies.
//   Wonder (muse-wonder-1.0) — thinks deep: owner-level analysis & judgment.
//   SAM    (sam-3.1)         — sees: the photo lane (server/photo.php).
//   Image  (muse-image-1.0)  — creates: offer posters, staged as cards.
// The client lives in meta.php; the key stays server-side and is never sent
// to the browser or logged. Every composed reply is grounding-checked by its
// caller against the exact figures it was given; a reply that drops or
// changes a figure is rejected and the deterministic template speaks instead.
$GLOBALS['golpos_meta'] = ['provider' => 'deterministic', 'model' => null];

function golpos_spark_model(): string {
    $model = getenv('META_MODEL');
    return (is_string($model) && trim($model) !== '') ? trim($model) : 'muse-spark-1.3';
}
function golpos_wonder_model(): string {
    $model = getenv('META_MODEL_WONDER');
    return (is_string($model) && trim($model) !== '') ? trim($model) : 'muse-wonder-1.0';
}
function golpos_lang_name(): string {
    global $L;
    return ['en'  => 'English',
            'np'  => 'Romanized Nepali (Latin script)',
            'hi'  => 'Romanized Hindi (Latin script)',
            'dev' => 'Nepali (Devanagari script)'][$L] ?? 'English';
}

// Ask a Muse text model to compose one reply from grounded facts. Returns
// null on ANY failure (no key, HTTP error, timeout, empty content) so the
// caller falls back — silently, register first. $deep switches to the
// Wonder analyst framing (a judgment answer, one grounded suggestion).
function golpos_meta_compose(string $shop, string $facts, string $userMsg, ?string $model = null, bool $deep = false): ?string {
    $model = $model ?? golpos_spark_model();
    $langName = golpos_lang_name();
    if ($deep) {
        $system = "You are Gol, the AI brain inside the register at {$shop}, a small convenience store. "
            . "You are the owner's analyst now — think deeply over the week's facts below and answer "
            . "the owner's judgment question (3-5 sentences) in {$langName} only. Use ONLY these facts: "
            . "never invent a price, a product, or a figure, and state every figure exactly as given. "
            . "You may offer ONE practical suggestion, but it must follow from the facts (a slow day, "
            . "a best seller, a tender pattern) — never generic business advice.\nFacts:\n{$facts}";
        return golpos_meta_chat($model, $system, $userMsg, 1500, 60);
    }
    $system = "You are Gol, the AI brain inside the register at {$shop}, a small convenience store. "
        . "You are chatting with the shop owner at the till. Write ONE short, natural reply "
        . "(1-2 sentences) in {$langName} only. Use ONLY the facts below — never invent a price, "
        . "a product, or a figure, and state every figure exactly as given. If the facts do not "
        . "answer the question, say briefly what you can do instead: add items to the cart by "
        . "name, quote a price, stage a price change as a card (it runs only when the owner says "
        . "the confirm word \"gol\"), or give today's total. Name those capabilities GENERICALLY \u2014 never cite a specific product or a specific price as an example of what you can do.\nFacts:\n{$facts}";
    return golpos_meta_chat($model, $system, $userMsg, 1500, 30);
}

// The deterministic layer assembles the week's facts for the Wonder lane:
// per-day totals for the last 7 days, the week aggregate, the tender split,
// and the top sellers by revenue. Returns [facts string, grounding figures].

// --- month records: closes, bills, expenses (owner bare-month law, Nov 1) ----
// A named or bare month is a full report period on this register: its
// sales compose from the saved day closes (the day's own summary covers a
// day never closed), its purchases from the executed-bill records
// (purchases.json), its expenses from the expense lines (expenses.json).
// These readers are the ONLY source for month figures — no lane ever
// substitutes today's numbers for a month's.
function golpos_month_ref(string $msg): ?array {
    $names = ['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,
              'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
              'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,
              'oct'=>10,'nov'=>11,'dec'=>12];
    $low = mb_strtolower(trim($msg));
    if (preg_match('/(last|previous|gata)\s*(month|mahina)/iu', $msg)
        || preg_match('/(mahina|month)\s*(agadi|aghi)/iu', $msg)) {
        $d = new DateTime('first day of last month');
        return ['ym' => $d->format('Y-m'), 'label' => $d->format('F Y'), 'bare' => false];
    }
    if (preg_match('/(this|current|yo)\s*(month|mahina)/iu', $msg)) {
        $d = new DateTime('first day of this month');
        return ['ym' => $d->format('Y-m'), 'label' => $d->format('F Y'), 'bare' => false];
    }
    foreach ($names as $nm => $mn) {
        if (preg_match('/\b' . $nm . '\b/iu', $low)) {
            $y = (int)date('Y'); if ($mn > (int)date('n')) { $y--; }
            if (preg_match('/\b(19|20)\d{2}\b/', $msg, $ym2)) { $y = (int)$ym2[0]; }
            $dt = DateTime::createFromFormat('Y-n-j', $y . '-' . $mn . '-1');
            $stripped = trim((string)preg_replace('/[^a-z]/iu', ' ', str_ireplace($nm, ' ', $low)));
            $bare = in_array($stripped, ['', 'report', 'hisab', 'hisaab', 'ko', 'the', 'of'], true);
            return ['ym' => $dt->format('Y-m'), 'label' => $dt->format('F Y'), 'bare' => $bare];
        }
    }
    return null;
}

// Every distinct month a message names, in the order named — the
// comparison lane's raw material ("October vs September", "October
// compared to last month").
function golpos_month_refs_all(string $msg): array {
    $names = ['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,
              'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
              'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,
              'oct'=>10,'nov'=>11,'dec'=>12];
    $low = mb_strtolower($msg);
    $hits = []; // [pos, ym, label]
    foreach ($names as $nm => $mn) {
        if (preg_match('/\b' . $nm . '\b/iu', $low, $mm, PREG_OFFSET_CAPTURE)) {
            $y = (int)date('Y'); if ($mn > (int)date('n')) { $y--; }
            $dt = DateTime::createFromFormat('Y-n-j', $y . '-' . $mn . '-1');
            $hits[] = [$mm[0][1], $dt->format('Y-m'), $dt->format('F Y')];
        }
    }
    if (preg_match('/(last|previous|gata)\s*(month|mahina)/iu', $msg, $mm, PREG_OFFSET_CAPTURE)) {
        $d = new DateTime('first day of last month');
        $hits[] = [$mm[0][1], $d->format('Y-m'), $d->format('F Y')];
    }
    if (preg_match('/(this|current|yo)\s*(month|mahina)/iu', $msg, $mm, PREG_OFFSET_CAPTURE)) {
        $d = new DateTime('first day of this month');
        $hits[] = [$mm[0][1], $d->format('Y-m'), $d->format('F Y')];
    }
    usort($hits, static fn($a, $b) => $a[0] <=> $b[0]);
    $out = []; $seen = [];
    foreach ($hits as $h) {
        if (isset($seen[$h[1]])) { continue; }
        $seen[$h[1]] = true;
        $out[] = ['ym' => $h[1], 'label' => $h[2]];
    }
    return $out;
}

function golpos_month_days(string $ym): array {
    $out = []; $n = (int)date('t', strtotime($ym . '-01'));
    for ($d = 1; $d <= $n; $d++) { $out[] = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT); }
    return $out;
}

// One month's sales: the saved closes first (the register's frozen
// truth), the live day summary for any day never closed.
function golpos_month_sales(string $ym): array {
    $closes = [];
    foreach (golpos_read('closes.json', []) as $c) {
        if (is_array($c) && isset($c['date'])) { $closes[(string)$c['date']] = $c; }
    }
    $gross = 0.0; $cash = 0.0; $card = 0.0; $txns = 0; $days = [];
    foreach (golpos_month_days($ym) as $ymd) {
        if (isset($closes[$ymd])) {
            $r = $closes[$ymd];
            $g = (float)($r['gross'] ?? 0);
            $days[$ymd] = $g;
            $gross += $g; $cash += (float)($r['cash'] ?? 0); $card += (float)($r['card'] ?? 0);
            $txns += (int)($r['txns'] ?? 0);
        } else {
            $s = golpos_day_summary($ymd);
            $days[$ymd] = (float)$s['gross'];
            $gross += (float)$s['gross']; $cash += (float)$s['cash']; $card += (float)$s['card'];
            $txns += (int)$s['txns'];
        }
    }
    return ['gross' => round($gross, 2), 'cash' => round($cash, 2), 'card' => round($card, 2),
            'txns' => $txns, 'days' => $days];
}

function golpos_month_purchases(string $ym): array {
    $total = 0.0; $n = 0; $byVendor = [];
    foreach (golpos_read('purchases.json', []) as $rec) {
        if (!is_array($rec)) { continue; }
        if (substr((string)($rec['date'] ?? $rec['at'] ?? ''), 0, 7) !== $ym) { continue; }
        $t = (float)($rec['total'] ?? 0);
        $total += $t; $n++;
        $v = (string)($rec['vendor'] ?? 'Vendor');
        $byVendor[$v] = round(($byVendor[$v] ?? 0) + $t, 2);
    }
    arsort($byVendor);
    return ['total' => round($total, 2), 'bills' => $n, 'by_vendor' => $byVendor];
}

function golpos_month_expenses(string $ym): array {
    $total = 0.0; $rows = [];
    foreach (golpos_read('expenses.json', []) as $rec) {
        if (!is_array($rec)) { continue; }
        if (substr((string)($rec['date'] ?? $rec['at'] ?? ''), 0, 7) !== $ym) { continue; }
        $total += (float)($rec['amount'] ?? 0); $rows[] = $rec;
    }
    usort($rows, static fn($a, $b) => (float)($b['amount'] ?? 0) <=> (float)($a['amount'] ?? 0));
    return ['total' => round($total, 2), 'count' => count($rows), 'rows' => $rows];
}

function golpos_week_facts(string $shop, array $products): array {
    // Top sellers by revenue come from the raw log — line detail lives
    // only there. Every TOTAL below comes from golpos_day_summary, the
    // one function the day lane, the compare lane and the visual-report
    // payload all read, so the facts, the fallback lines and the drawn
    // bars agree by construction — refunds included (a refund adjusts
    // the day it was rung on, everywhere, the same way).
    $items = [];
    $weekStart = date('Y-m-d', strtotime('-6 days'));
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if ($d < $weekStart || $d > date('Y-m-d')) { continue; }
        foreach (($s['lines'] ?? []) as $l) {
            $n = (string)($l['name'] ?? '');
            if ($n !== '') { $items[$n] = ($items[$n] ?? 0.0) + (float)($l['line_total'] ?? 0); }
        }
    }
    arsort($items);
    $days = []; $weekGross = 0.0; $weekTx = 0; $cash = 0.0; $card = 0.0;
    for ($i = 6; $i >= 0; $i--) {
        $ymd = date('Y-m-d', strtotime("-{$i} days"));
        $s = golpos_day_summary($ymd);
        $days[$ymd] = ['gross' => (float)$s['gross'], 'txns' => (int)$s['txns']];
        $weekGross += (float)$s['gross']; $weekTx += (int)$s['txns'];
        $cash += (float)$s['cash']; $card += (float)$s['card'];
    }
    $weekGross = round($weekGross, 2); $cash = round($cash, 2); $card = round($card, 2);
    $monthGross = 0.0; $monthTx = 0; $monthCash = 0.0; $monthCard = 0.0;
    for ($d = 1; $d <= (int)date('j'); $d++) {
        $s = golpos_day_summary(date('Y-m-') . str_pad((string)$d, 2, '0', STR_PAD_LEFT));
        $monthGross += (float)$s['gross']; $monthTx += (int)$s['txns'];
        $monthCash += (float)$s['cash']; $monthCard += (float)$s['card'];
    }
    $monthGross = round($monthGross, 2); $monthCash = round($monthCash, 2); $monthCard = round($monthCard, 2);
    $today = golpos_day_summary(date('Y-m-d'));
    $lines = ["Shop: {$shop}. Last 7 days (date — total — sales):"];
    $figures = [money($weekGross), money($today['gross'])];
    foreach ($days as $d => $v) {
        $lines[] = "{$d} — " . money($v['gross']) . " — {$v['txns']}";
        $figures[] = money($v['gross']);
    }
    $lines[] = "Week total: " . money($weekGross) . " across {$weekTx} sales (cash " . money($cash) . ", card " . money($card) . ").";
    $lines[] = "Month to date (" . date('F Y') . "): " . money($monthGross) . " across {$monthTx} sales (cash " . money($monthCash) . ", card " . money($monthCard) . "). Purchases and expenses are recorded at this register too — the lines below carry this month and last month in full. Profit is computed in the books; never present a profit figure for the POS.";
    $figures[] = money($monthGross);
    $pmF = golpos_month_ref('last month');
    if ($pmF !== null) {
        $psF = golpos_month_sales($pmF['ym']); $ppF = golpos_month_purchases($pmF['ym']); $peF = golpos_month_expenses($pmF['ym']);
        $lines[] = "Last month (" . $pmF['label'] . "), the FULL month from the day closes: sales " . money($psF['gross'])
            . " across {$psF['txns']} sales (cash " . money($psF['cash']) . ", card " . money($psF['card'])
            . "). Purchases " . money($ppF['total']) . " across {$ppF['bills']} vendor bills. Expenses "
            . money($peF['total']) . " across {$peF['count']} expense lines. A question naming that month is answered with THESE figures — never today's, never the trailing week's.";
        $figures[] = money($psF['gross']); $figures[] = money($ppF['total']); $figures[] = money($peF['total']);
        $cmP = golpos_month_purchases(date('Y-m')); $cmE = golpos_month_expenses(date('Y-m'));
        $lines[] = "This month to date: purchases " . money($cmP['total']) . " across {$cmP['bills']} bills; expenses " . money($cmE['total']) . ".";
        $figures[] = money($cmP['total']); $figures[] = money($cmE['total']);
        $trendParts = [];
        for ($k = 5; $k >= 0; $k--) {
            $tm = (new DateTime('first day of this month'))->modify("-{$k} months");
            $ts = golpos_month_sales($tm->format('Y-m'));
            if ($ts['txns'] === 0 && $k !== 0) { continue; }
            $tp = golpos_month_purchases($tm->format('Y-m')); $te = golpos_month_expenses($tm->format('Y-m'));
            $trendParts[] = $tm->format('M Y') . ": sales " . money($ts['gross']) . ", purchases " . money($tp['total']) . ", expenses " . money($te['total']);
            $figures[] = money($ts['gross']);
        }
        if ($trendParts) { $lines[] = "Monthly trend (full months, from the closes, bills and expenses): " . implode('; ', $trendParts) . ". Month comparisons and trend questions are answered from THESE figures."; }
    }
    $lines[] = "Today so far: " . money($today['gross']) . " across {$today['txns']} sales.";
    $top = array_slice($items, 0, 5, true);
    if ($top) {
        $lines[] = "Top sellers this week by revenue: " . implode('; ', array_map(
            fn($n, $v) => $n . ' — ' . money($v), array_keys($top), array_values($top))) . ".";
        foreach ($top as $v) { $figures[] = money($v); }
    }
    $lines[] = "Catalog (" . count($products) . " items, name — price): "
        . implode('; ', array_map(fn($p) => $p['name'] . ' — ' . money((float)$p['price']), $products));
    return [implode("\n", $lines), $figures, $weekTx, $weekGross, $today, $monthGross, $monthTx, $monthCash, $monthCard];
}

// --- number MEANING: a price, a count, or part of a name ----------------------
// A number in the owner's sentence is one of three things: a PRICE, a COUNT,
// or part of a product's NAME (Coca-Cola 20oz). Reading the first number in
// the message as the price staged "$20.00" when the owner said "change the
// price of coca cola 20 oz to $3" — the size was read as money (owner-caught,
// Oct 28). These extractors are the fix, shared by every numeric intent, and
// they never guess: ambiguous or absent comes back as a status and the lane
// ASKS. The rules, in order:
//   1. The matched product is consumed FIRST: its catalog name, wherever it
//      literally appears in the message, is blanked before any number is
//      read — extraction runs on what remains.
//   2. A number glued to a size unit (20 oz, 12oz, 16.9oz, 4pk, 500ml) is
//      part of a name — blanked; never money, never a count.
//   3. An explicit $ amount is money and always wins as the price. A $ amount
//      followed by "bata/from" is the FROM price of a change, not a
//      candidate. Two different live candidates = ambiguity — ask.
//   4. Without $, the price is the number bound to a price word (after
//      to/at/price/mulya/dam/daam, or before the postposition ma), or the
//      one candidate left standing.
// A count is bound to its own shapes instead: a count classifier (ota/vata/
// वटा/pcs), a receive verb (received/got), or the item word it counts.
function golpos_blank(string $s, array $spans): string {
    usort($spans, fn($a, $b) => $b['start'] <=> $a['start']);
    foreach ($spans as $sp) {
        $s = substr_replace($s, str_repeat(' ', $sp['len']), $sp['start'], $sp['len']);
    }
    return $s;
}
// Spans that can never carry a price or a count: the product's own name and
// every size-glued number. Returns [working string, spans blanked in it].
function golpos_mask_sizes(string $work, ?array $product): string {
    $spans = [];
    if ($product && !empty($product['name']) &&
        preg_match('/' . preg_quote((string)$product['name'], '/') . '/iu', $work, $mm, PREG_OFFSET_CAPTURE)) {
        $spans[] = ['start' => $mm[0][1], 'len' => strlen($mm[0][0])];
    }
    if (preg_match_all('/\d+(?:\.\d+)?[\s-]*(?:fl\s*oz|ounces?|oz|ml|ltr|litres?|liters?|l|gm|g|kg|lb|pk|packs?|count|ct)\b/iu',
                       $work, $um, PREG_OFFSET_CAPTURE)) {
        foreach ($um[0] as $u) { $spans[] = ['start' => $u[1], 'len' => strlen($u[0])]; }
    }
    return golpos_blank($work, $spans);
}
// Every number token left standing: value, byte span, and whether it wore a $.
function golpos_num_spans(string $work): array {
    $out = [];
    if (preg_match_all('/\$\s*(\d+(?:\.\d+)?)|(\d+(?:\.\d+)?)(\s*\$)?/', $work, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $pre = ($m[1][$i][1] ?? -1) >= 0;
            $post = ($m[3][$i][1] ?? -1) >= 0;
            // "$" wears either side of the amount in the owner's typing —
            // "$3", "3$" and "3 $" are the same money, and the span covers
            // the sign so the word AFTER the money is read past it.
            $out[] = ['value' => (float)($pre ? $m[1][$i][0] : $m[2][$i][0]),
                      'start' => $full[1], 'len' => strlen($full[0]), 'dollar' => $pre || $post];
        }
    }
    return $out;
}
function golpos_word_before(string $s, int $start): string {
    if (preg_match('/([^\s:;,]+)\s*$/u', substr($s, 0, $start), $m)) {
        return mb_strtolower(trim($m[1], "\"'"));
    }
    return '';
}
function golpos_word_after(string $s, int $end): string {
    if (preg_match('/^\s*([^\s:;,]+)/u', substr($s, $end), $m)) {
        return mb_strtolower(trim($m[1], "\"'"));
    }
    return '';
}
// The price the owner actually said: ['status' => ok|none|ambiguous, ...].
function golpos_price_words(string $s): string {
    // Spoken amounts are prices. "four dollars" used to have no digit, so the
    // price question was dropped and the capability menu answered instead.
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
              'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
              'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
              'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
              'nineteen' => 19, 'twenty' => 20];
    $alt = implode('|', array_map(static fn($w) => preg_quote($w, '/'), array_keys($words)));
    $num = static function (string $w) use ($words): string {
        return (string)($words[mb_strtolower($w)] ?? $w);
    };
    $s = (string)preg_replace_callback('/\b(' . $alt . ')\b(\s+)(dollars?|bucks?)\b/iu',
        static function ($m) use ($num) { return $num($m[1]) . $m[2] . $m[3]; }, $s);
    if (preg_match('/^\s*(' . $alt . ')\s*[.!]?\s*$/iu', $s, $m)) {
        return $num($m[1]);
    }
    return $s;
}
function golpos_extract_price(string $msg, ?array $product): array {
    $msg = golpos_price_words($msg);
    $work = golpos_mask_sizes(golpos_norm_digits($msg), $product);
    $nums = golpos_num_spans($work);
    if (!$nums) { return ['status' => 'none']; }
    $fromWords = ['bata', 'बाट', 'from'];
    $bindWords = ['to', 'at', 'price', 'mulya', 'dam', 'daam', 'दाम', 'मूल्य', '=', 'rs', 'rs.'];
    $classifiers = ['ota', 'vata', 'वटा', 'pcs', 'pieces', 'piece'];
    $dollars = []; $bound = []; $all = [];
    foreach ($nums as $n) {
        $after = golpos_word_after($work, $n['start'] + $n['len']);
        if (in_array($after, $fromWords, true)) { continue; } // "2.19 bata 2.50" — the FROM price
        if (in_array($after, $classifiers, true)) { continue; } // "20 ota" — a count, never money
        $all[] = $n['value'];
        if ($n['dollar']) { $dollars[] = $n['value']; continue; }
        $before = golpos_word_before($work, $n['start']);
        if (in_array($before, $bindWords, true) || $after === 'ma' || $after === 'मा') {
            $bound[] = $n['value'];
        }
    }
    $distinct = fn($a) => array_values(array_unique(array_map(fn($v) => round($v, 2), $a)));
    $d = $distinct($dollars);
    if (count($d) === 1) { return ['status' => 'ok', 'value' => $d[0]]; }
    if (count($d) > 1) { return ['status' => 'ambiguous', 'candidates' => $d]; }
    $b = $distinct($bound);
    if (count($b) === 1) { return ['status' => 'ok', 'value' => $b[0]]; }
    if (count($b) > 1) { return ['status' => 'ambiguous', 'candidates' => $b]; }
    $a = $distinct($all);
    if (count($a) === 1) { return ['status' => 'ok', 'value' => $a[0]]; }
    if (count($a) > 1) { return ['status' => 'ambiguous', 'candidates' => $a]; }
    return ['status' => 'none'];
}
// The count the owner actually said ("coke 20 ota ayo" -> 20), or null when
// no number is bound as a count. Money is never a count; a size is never a
// count; an unbound tie returns null rather than a guess.
/* Number WORDS in a count position (audit GOL-01, Oct 30): the span
   reader in golpos_extract_count only sees digits, so "Add two Coke
   20oz" silently added ONE. Convert a number word to its digit only
   where it is bound like a count: directly before a classifier
   (ota/vata/pcs) or directly before the resolved item's own first
   name word / alias ("two Coke"). A number word inside a product's
   name ("Five Hour Energy") is never touched — conversion needs the
   item's FIRST word or a classifier after the number word, and the
   item's first word is matched whole. */
function golpos_count_words(string $s, ?array $product): string {
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
              'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
              'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
              'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
              'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
              'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
              'ek' => 1, 'dui' => 2, 'tin' => 3, 'char' => 4, 'chaar' => 4, 'panch' => 5,
              'paanch' => 5, 'chha' => 6, 'saat' => 7, 'aath' => 8, 'nau' => 9, 'das' => 10,
              'eghara' => 11, 'bahra' => 12, 'chaudha' => 14, 'choudha' => 14,
              'pandhra' => 15, 'pandra' => 15, 'sohra' => 16, 'satra' => 17,
              'athara' => 18, 'unnais' => 19, 'bis' => 20, 'ekkais' => 21, 'bais' => 22,
              'teis' => 23, 'chaubis' => 24, 'pachchis' => 25, 'chhabbis' => 26,
              'sattais' => 27, 'aththais' => 28, 'unantis' => 29,
              'दुई' => 2, 'तीन' => 3, 'चार' => 4, 'पाँच' => 5, 'छ' => 6,
              'सात' => 7, 'आठ' => 8, 'नौ' => 9, 'दश' => 10, 'एघार' => 11, 'बाह्र' => 12,
              'चौध' => 14, 'पन्ध्र' => 15, 'सोह्र' => 16, 'सत्र' => 17, 'अठार' => 18,
              'उन्नाइस' => 19, 'बीस' => 20, 'चौबीस' => 24, 'तीस' => 30];
    $alt = implode('|', array_map(static fn($w) => preg_quote($w, '/'), array_keys($words)));
    $toNum = static function ($m) use ($words) { return (string) ($words[mb_strtolower($m[1])] ?? $m[1]); };
    /* Compound tens ("twenty-four", "twenty four") are single counts.
       The Nov 2 ear test: "Add twenty-four 8.4-ounce Red Bull" rang 8 —
       the compound was invisible to the word reader while the size's
       8.4 stood unmasked ("ounce" missing from the size units) and the
       count reader took the 8. Compounds convert under the same
       binding gates as single words: before a classifier, before the
       item's first word, or before a SIZE that stands before the item
       ("twenty-four 8.4-ounce Red Bull" — the count binds through the
       size to the item it counts). */
    $tens = ['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
             'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];
    $ones = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
             'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];
    $tAlt = implode('|', array_keys($tens));
    $oAlt = implode('|', array_keys($ones));
    $compound = static function ($m) use ($tens, $ones) {
        return (string) (($tens[mb_strtolower($m[1])] ?? 0) + ($ones[mb_strtolower($m[2])] ?? 0));
    };
    $toNumPhrase = static function (string $ph) use ($words, $tens, $ones) {
        $ph = mb_strtolower(trim($ph));
        $parts = preg_split('/[-\s]+/u', $ph);
        if (count($parts) === 2 && isset($tens[$parts[0]], $ones[$parts[1]])) {
            return (string) ($tens[$parts[0]] + $ones[$parts[1]]);
        }
        return (string) ($words[$ph] ?? $ph);
    };
    $s = (string) preg_replace_callback('/\b(' . $tAlt . ')[-\s](' . $oAlt . ')\b(\s*)(ota|vata|वटा|pcs|pieces?|पिस)\b/iu',
        static function ($m) use ($compound) { return $compound($m) . $m[3] . $m[4]; }, $s);
    $s = (string) preg_replace_callback('/\b(' . $alt . ')\b(\s*)(ota|vata|वटा|pcs|pieces?|पिस)\b/iu',
        static function ($m) use ($toNum) { return $toNum($m) . $m[2] . $m[3]; }, $s);
    if ($product) {
        $names = [];
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string) ($product['name'] ?? ''))) as $w) {
            if ($w !== '') { $names[] = $w; break; }
        }
        foreach (($product['aliases'] ?? []) as $a) {
            $a = mb_strtolower(trim((string) $a));
            if ($a !== '') $names[] = $a;
        }
        $sizeSpan = '\d+(?:\.\d+)?[\s-]*(?:fl\s*oz|ounces?|oz|ml|ltr|litres?|liters?|l|gm|g|kg|lb|pk|packs?|count|ct)\b';
        foreach (array_unique($names) as $nm) {
            $qnm = preg_quote($nm, '/');
            $s = (string) preg_replace_callback('/\b((?:' . $tAlt . ')[-\s](?:' . $oAlt . ')|' . $alt . ')\b(\s+)(' . $sizeSpan . ')(\s+)' . $qnm . '\b/iu',
                static function ($m) use ($toNumPhrase, $nm) { return $toNumPhrase($m[1]) . $m[2] . $m[3] . $m[4] . $nm; }, $s, 1);
            $s = (string) preg_replace_callback('/\b(' . $tAlt . ')[-\s](' . $oAlt . ')\b(\s+)' . $qnm . '\b/iu',
                static function ($m) use ($compound, $nm) { return $compound($m) . $m[3] . $nm; }, $s, 1);
            $s = (string) preg_replace_callback('/\b(' . $alt . ')\b(\s+)' . $qnm . '\b/iu',
                static function ($m) use ($toNum, $nm) { return $toNum($m) . $m[2] . $nm; }, $s, 1);
        }
    }
    return $s;
}

function golpos_extract_count(string $msg, ?array $product): ?float {
    $work = golpos_mask_sizes(golpos_norm_digits($msg), $product);
    if (preg_match_all('/\$\s*\d+(?:\.\d+)?|\d+(?:\.\d+)?\s*\$/', $work, $dm, PREG_OFFSET_CAPTURE)) {
        $spans = [];
        foreach ($dm[0] as $d) { $spans[] = ['start' => $d[1], 'len' => strlen($d[0])]; }
        $work = golpos_blank($work, $spans);
    }
    $nums = golpos_num_spans($work);
    if (!$nums) { return null; }
    $classifiers = ['ota', 'vata', 'वटा', 'pcs', 'pieces', 'piece'];
    $verbs = ['received', 'receive', 'got', 'restock', 'restocked', 'ayo', 'aayo', 'आयो'];
    $bindWords = ['to', 'at', 'price', 'mulya', 'dam', 'daam', 'दाम', 'मूल्य', '='];
    $itemWords = [];
    if ($product) {
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string)$product['name'])) as $w) {
            if ($w !== '') { $itemWords[] = $w; }
        }
        foreach (($product['aliases'] ?? []) as $a) { $itemWords[] = mb_strtolower((string)$a); }
    }
    $byClass = []; $byVerb = []; $byItem = []; $rest = [];
    foreach ($nums as $n) {
        $before = golpos_word_before($work, $n['start']);
        $after = golpos_word_after($work, $n['start'] + $n['len']);
        if ($after === 'ma' || $after === 'मा' || in_array($before, $bindWords, true)) { continue; }
        if (in_array($after, $classifiers, true)) { $byClass[] = $n['value']; continue; }
        if (in_array($before, $verbs, true)) { $byVerb[] = $n['value']; continue; }
        if ($after !== '' && in_array($after, $itemWords, true)) { $byItem[] = $n['value']; continue; }
        $rest[] = $n['value'];
    }
    foreach ([$byClass, $byVerb, $byItem, $rest] as $pool) {
        $d = array_values(array_unique(array_map(fn($v) => round($v, 2), $pool)));
        if (count($d) === 1) { return $d[0]; }
        if (count($d) > 1) { return null; }
    }
    return null;
}
// The barcode the owner said, if any — read FIRST, before price, count or
// name extraction, and masked out of the working string those readers see,
// so a barcode can never be mistaken for money or a quantity (the run
// "012345678905" once priced an item at $12,345,678,905.00). Two shapes
// count: a labeled run ("barcode 012345678905", "barcode: 0123...") or a
// bare run of 8-14 digits standing in the sentence. A run under 8 digits
// is not a barcode; a run glued to a size unit is a name's size. The
// holder is the product already carrying that code (as a barcode OR a
// sku — the same test golpos_create_product dedupes with): a new item
// may not take it over. Returns ['barcode' => ?string, 'work' => the
// message with the barcode (and its label) blanked, 'holder' => ?array];
// with no barcode the message comes back untouched.
function golpos_extract_barcode(string $msg, array $products): array {
    $none = ['barcode' => null, 'work' => $msg, 'holder' => null];
    $work = golpos_norm_digits($msg);
    $found = null; $spans = [];
    if (preg_match('/\bbar\s*code\s*[:#]?\s*(\d{8,14})(?!\d)/iu', $work, $m, PREG_OFFSET_CAPTURE)) {
        $found = $m[1][0];
        $spans[] = ['start' => $m[0][1], 'len' => strlen($m[0][0])];
    } elseif (preg_match_all('/(?<![\d.])(\d{8,14})(?![\d.])/u', $work, $mm, PREG_OFFSET_CAPTURE)) {
        $units = ['oz', 'fl', 'ml', 'l', 'ltr', 'litre', 'litres', 'liter', 'liters',
                  'gm', 'g', 'kg', 'lb', 'pk', 'pack', 'packs', 'count', 'ct'];
        foreach ($mm[1] as $run) {
            if (in_array(golpos_word_after($work, $run[1] + strlen($run[0])), $units, true)) {
                continue; // glued to a size unit — part of a name, not a code
            }
            $found = $run[0];
            $spans[] = ['start' => $run[1], 'len' => strlen($run[0])];
            break;
        }
    }
    if ($found === null) { return $none; }
    $holder = null;
    foreach ($products as $p) {
        if ((string)($p['barcode'] ?? '') === $found || (string)($p['sku'] ?? '') === $found) {
            $holder = $p; break;
        }
    }
    return ['barcode' => $found, 'work' => golpos_blank($work, $spans), 'holder' => $holder];
}
// An "add a sale" ask (owner law, Oct 28): sale words crossed with a
// record verb, in any of the shop's languages — and NOT a figure question
// (those are the day total / report lanes). Chat never writes a sale;
// the dispatch routes these to the register truth.
function golpos_is_sale_add_ask(string $msg): bool {
    if (!preg_match('/(\bsales?\b|bikri|बिक्री)/iu', $msg)) { return false; }
    if (preg_match('/(report|kati|kitna|how|total|hisab|hisaab|हिसाब|कति)/iu', $msg)) { return false; }
    return preg_match('/(\badd\b|\brecord\b|\bentry\b|\bhal|\bhaal|\bthap|राख|हाल|थप)/iu', $msg) === 1;
}
// --- AI Calendar + AI Notes: the register's shapes -------------------------
// A calendar READ: "<Name> ko calendar / bholi", "mero calendar ma ke
// chha?", a bare store-calendar ask. Scope rides the day words.
function golpos_cal_parse_read(string $msg): ?array {
    $scope = 'week';
    if (preg_match('/(bholi|tomorrow|भोलि)/iu', $msg)) { $scope = 'tomorrow'; }
    elseif (preg_match('/(aaja|today|आज)/iu', $msg)) { $scope = 'today'; }
    if (preg_match('/^(.+?)\s+ko\s+(calendar|calender)\b/iu', $msg, $m)
        || preg_match('/^(.+?)\s+ko\s+(bholi|bholiko|aaja|hapta)\b/iu', $msg, $m)) {
        $name = trim($m[1]);
        if ($name !== '' && !preg_match('/(mero|store|pasal|hamro|hamra)/iu', $name)) {
            return ['who' => $name, 'scope' => $scope];
        }
        return ['who' => 'self', 'scope' => $scope];
    }
    if (preg_match('/(calendar|calender|पात्रो)/iu', $msg)
        && preg_match('/(ke chha|ke ke|dekha|show|what|mero|my|list|herau|aaja|bholi|hapta|week)/iu', $msg)
        && !preg_match('/(rakha|gara|lekha|add|save|bana|halnu|hala)/iu', $msg)) {
        return ['who' => preg_match('/(mero|my)/iu', $msg) ? 'self' : 'store', 'scope' => $scope];
    }
    return null;
}

// A calendar CREATE: an explicit date, or a relative day + a calendar
// verb, in a STATEMENT (a dated question is a read, never a create).
// Returns the staged card's fields or null.
function golpos_cal_parse_create(string $msg): ?array {
    if (preg_match('/(kati|kun|how|why|kina|ke\s+ho|ke\s+chha|\?)/iu', $msg)) { return null; }
    $hasNoteVerb = (bool)preg_match('/(note\s*(gara|rakha|lekha|gar|rakh|garna|rakhna)|नोट)/iu', $msg);
    $hasCalVerb = (bool)preg_match('/(calendar\s*ma|meeting|remind|samjhau|samjha|appointment|entry\s*gara)/iu', $msg);
    $explicitDate = (bool)(preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $msg)
        || preg_match('/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/', $msg)
        || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}\b/i', $msg)
        || preg_match('/\b\d{1,2}(st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $msg));
    $isStatement = (bool)preg_match('/(chha|cha|huncha|hunchha|aauchha|aaunchha)\s*[.!]?\s*$/iu', trim($msg));
    $date = golpos_cal_parse_date($msg);
    if ($date === null) { return null; }
    if (!(($explicitDate && ($hasNoteVerb || $hasCalVerb || $isStatement)) || $hasCalVerb)) { return null; }
    $persona = '';
    if (preg_match('/^(.+?)\s+ko\s+calendar\s+ma\b/iu', $msg, $m)) { $persona = trim($m[1]); }
    elseif (preg_match('/([A-Za-z][A-Za-z]*(?:\s+[A-Za-z][A-Za-z]*)?)\s+(?:ko\s+lagi|lai)\b/u', $msg, $m)) { $persona = trim($m[1]); }
    elseif (preg_match('/\bfor\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/', $msg, $m)) { $persona = trim($m[1]); }
    if ($persona !== '' && preg_match('/(mero|store|pasal|hamro|calendar)/iu', $persona)) { $persona = ''; }
    $time = golpos_parse_time($msg, '') ?? '';
    /* golpos_parse_time will happily read the day-of-month as a time on
       a date-only sentence ("Nov 5 ma meeting" -> 05:00) — only trust
       it when an am/pm or an HH:MM shape is actually present. */
    if (!preg_match('/(\d{1,2}:\d{2}|\d{1,2}\s*(am|pm))/iu', $msg)) { $time = ''; }
    $title = golpos_cal_strip_date($msg);
    $title = (string)preg_replace('/\b\d{1,2}(:\d{2})?\s*(am|pm)\b/i', ' ', $title);
    $title = (string)preg_replace('/(note\s*(gara|rakha|lekha|gar|rakh|garna|rakhna)|calendar\s+ma\s*(rakha|hala|add)?|remind\s*(gara|garna)?|samjhau|samjha|entry\s*gara)\b/iu', ' ', $title);
    if ($persona !== '') {
        $title = (string)preg_replace('/' . preg_quote($persona, '/') . '\s+(ko\s+lagi|lai|ko\s+calendar\s+ma)\b/iu', ' ', $title);
        $title = (string)preg_replace('/\bfor\s+' . preg_quote($persona, '/') . '\b/i', ' ', $title);
    }
    $title = trim((string)preg_replace('/\s+/', ' ', $title), " \t.,:;-");
    $title = trim((string)preg_replace('/^(ma|on|at)\s+/iu', '', $title));
    if ($title === '') { return null; }
    if (preg_match('/\s(chha|cha)$/iu', $title)) { $title = trim((string)preg_replace('/\s+(chha|cha)$/iu', '', $title)); }
    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    $type = 'other'; $duty = '';
    if (preg_match('/(delivery|truck|rep\b|vendor)/iu', $title)) { $type = 'vendor'; $duty = 'delivery'; }
    elseif (preg_match('/(bill|invoice)/iu', $title)) { $type = 'books'; $duty = 'bills'; }
    elseif (preg_match('/(close|report|\bz\b)/iu', $title)) { $type = 'books'; $duty = 'close'; }
    elseif (preg_match('/(count|ginti|stock)/iu', $title)) { $type = 'other'; $duty = 'stock'; }
    elseif (preg_match('/(meeting|staff)/iu', $title)) { $type = 'staff'; }
    return ['date' => $date, 'time' => $time, 'title' => $title,
            'persona' => $persona, 'duty_group' => $duty, 'etype' => $type];
}

// An undated NOTE create: "note rakha: X" / "note gara — X". A dated
// sentence took the calendar branch; relative words inside stay part
// of the note. Questions never create.
function golpos_note_parse_create(string $msg): ?string {
    if (preg_match('/(kati|kun|how|why|kina|\?)/iu', $msg)) { return null; }
    if (!preg_match('/(note\s*(rakha|gara|lekha|gar|rakh|garna|rakhna)|नोट)/iu', $msg)) { return null; }
    if (golpos_cal_parse_create($msg) !== null) { return null; } // dated -> calendar owns it
    $body = (string)preg_replace('/.*?(note\s*(rakha|gara|lekha|gar|rakh|garna|rakhna))\s*[:\-]?\s*/iu', '', $msg, 1);
    if ($body === $msg) { $body = trim((string)preg_replace('/\b(note|नोट)\b/iu', ' ', $msg)); }
    $body = trim($body, " \t.,:;-");
    return $body !== '' ? $body : null;
}

// Note lines tagged to an item — appended to that item's stock/count
// answers (AI Notes are alive: they resurface where they matter).
function golpos_cal_notes_tail(string $itemName): string {
    $lines = [];
    foreach (golpos_cal_item_notes($itemName) as $n) {
        $lines[] = '📝 Note (' . (string)($n['item'] ?? $itemName) . '): '
            . mb_substr(trim((string)($n['body'] ?? '')), 0, 120);
    }
    return $lines ? implode("\n", array_slice($lines, 0, 3)) : '';
}

// Small talk (owner catch, Oct 28): a greeting carrying a how-are-you
// tail — "Hi how are you today", "namaste, kasto cha" — is a GREETING,
// never a hisab ask. The day-total lane matches any message containing
// "today", so without this shape a friendly hello got a sales lecture.
// The match is anchored to the WHOLE message: a greeting followed by a
// real ask ("hi, what's today's total?") is not small talk and falls
// through to the lanes as always. A BARE greeting does not match either
// (no tail) — it keeps its own path (the rep-day morning card, then the
// friendly fallback).
function golpos_is_smalltalk(string $msg): bool {
    $greet = '(?:good\s+(?:morning|afternoon|evening)|namaste|namaskar|hello|hi|hey|suprabhat|नमस्ते|नमस्कार|शुभ\s*प्रभात)';
    $tail = '(?:how\s+(?:are\s+(?:you|u)|\'re\s+you)|how\'?s\s+(?:it\s+going|your\s+day|everything)|how\s+is\s+(?:it\s+going|your\s+day)|how\s+are\s+things'
          . '|kasto\s+(?:cha|chha|hunuhuncha)|sanchai\s+(?:cha|chha)|k\s+cha|ke\s+cha|कस्तो\s*छ|सन्चै\s*छ|ठिक\s*छ)';
    return preg_match('/^\s*(?:' . $greet . '\s*[,!.]?\s*)?' . $tail . '(?:\s+(?:today|aaja|आज))?\s*[!.?।]?\s*$/iu', $msg) === 1;
}
// One line naming a staged card truthfully — used when the owner says
// "stage it" about the card already on the till.
function golpos_card_summary(array $card): string {
    $qty = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    switch ($card['type'] ?? '') {
        case 'price_change':
            return ($card['name'] ?? '') . ': ' . money((float)($card['old_price'] ?? 0))
                . ' → ' . money((float)($card['new_price'] ?? 0));
        case 'price_fill':
            return ($card['name'] ?? '') . ': no shelf price yet → ' . money((float)($card['new_price'] ?? 0));
        case 'cost_fill':
            return ($card['name'] ?? '') . ': cost — none on record → ' . money((float)($card['new_cost'] ?? 0));
        case 'new_item':
            return 'new item ' . ($card['name'] ?? '') . ' — ' . money((float)($card['price'] ?? 0));
        case 'setting_change':
            return ($card['label'] ?? ($card['setting'] ?? 'setting')) . ': '
                . golpos_setting_display((string)($card['setting'] ?? ''), $card['old'] ?? null)
                . ' → ' . golpos_setting_display((string)($card['setting'] ?? ''), $card['new'] ?? null);
        case 'stock_receive':
            return 'stock receive — ' . ($card['name'] ?? '') . ' × ' . $qty($card['qty'] ?? 0);
        case 'employee_add': {
            $duties = array_values(array_map('strval', (array)($card['duties'] ?? [])));
            $groups = [];
            foreach (golpos_persona_data()['checklist'] as $gname => $gitems) {
                $n = 0;
                foreach ($gitems as $d) {
                    if (in_array(golpos_bill_name_key((string)$d),
                                 array_map('golpos_bill_name_key', $duties), true)) { $n++; }
                }
                if ($n > 0) { $groups[] = $gname . ' ' . $n; }
            }
            return 'add employee ' . ($card['email'] ?? '') . ' — ' . ucfirst((string)($card['role'] ?? 'cashier'))
                . ' preset, ' . count($duties) . ' duties (' . implode(' · ', $groups) . ')'
                . '. This persona starts knowing: ' . implode(' · ', array_slice($duties, 0, 5))
                . (count($duties) > 5 ? ' · +' . (count($duties) - 5) . ' more' : '');
        }
        case 'pin_set':
            return 'PIN for ' . ($card['name'] ?? '') . ' — a new PIN the owner hands out (the digits are never shown)';
        case 'task_assign':
            return 'tasks for ' . ($card['employee_name'] ?? '') . ' — '
                . count((array)($card['items'] ?? [])) . ' item(s): '
                . implode('; ', array_map('strval', (array)($card['items'] ?? [])));
        case 'team_note':
            return 'note to the boss from ' . ($card['from_name'] ?? '') . ' — "' . ($card['text'] ?? '') . '"';
        case 'pay_send':
            return 'pay request — ' . ($card['employee_name'] ?? '') . ': '
                . golpos_pay_headline((array)($card['segments'] ?? []), (float)($card['amount'] ?? 0))
                . ' · ' . golpos_pay_period_label((string)($card['period_from'] ?? ''), (string)($card['period_to'] ?? ''))
                . (count((array)($card['corrections'] ?? []))
                    ? ' · +' . count((array)$card['corrections']) . ' correction line(s) in her words' : '');
        case 'pay_approve':
            return 'pay approval — ' . ($card['employee_name'] ?? '') . ' is asking: '
                . golpos_pay_headline((array)($card['segments'] ?? []), (float)($card['amount'] ?? 0))
                . ' · ' . golpos_pay_period_label((string)($card['period_from'] ?? ''), (string)($card['period_to'] ?? ''))
                . (count((array)($card['corrections'] ?? []))
                    ? ' · she adds: "' . implode('" · "', array_map('strval', (array)$card['corrections'])) . '"' : '');
        case 'rate_change':
            return 'rate change — ' . ($card['employee_name'] ?? '') . ': '
                . (($card['old_rate'] ?? null) === null ? 'no rate on record' : money((float)$card['old_rate']))
                . ' → ' . money((float)($card['new_rate'] ?? 0)) . ' from today';
        case 'whats_out':
            return 'What\'s Out report — ' . ($card['name'] ?? '') . ' (by ' . ($card['by_name'] ?? '') . ')';
        case 'stock_count':
            return 'stock count — ' . ($card['name'] ?? '') . ': counted ' . $qty($card['counted'] ?? 0)
                . ', system ' . (isset($card['system']) && $card['system'] !== null ? $qty($card['system']) : '—');
        case 'batch_close':
            return 'batch close — ' . (int)($card['count'] ?? 0) . ' card sales, ' . money((float)($card['total'] ?? 0));
        case 'refund':
            return 'refund — sale ' . ($card['sale_id'] ?? '') . ' (' . ($card['sale_when'] ?? '') . '): '
                . money((float)($card['total'] ?? 0)) . ' back to ' . ($card['back_to'] ?? 'the original tender')
                . ' · ' . ($card['lines_label'] ?? '');
        case 'bill':
            return 'bill — ' . money((float)($card['total'] ?? 0));
        case 'order_prep':
            return 'order — ' . ($card['vendor'] ?? '') . ' · '
                . count((array)($card['lines'] ?? [])) . ' lines, est. '
                . money((float)($card['est_total'] ?? 0));
        case 'vendor_teach':
            return ($card['create'] ?? false ? 'new vendor ' : 'vendor ')
                . ($card['vendor_name'] ?? '') . ' — '
                . (($card['field'] ?? '') === 'delivery_day' ? 'delivery day' : 'rep day')
                . ': ' . (is_array($card['new'] ?? null)
                    ? golpos_day_names($card['new']) : golpos_day_name((string)($card['new'] ?? '')));
        case 'cal_entry':
            $forC = (($card['for_name'] ?? 'store') !== 'store') ? ' for ' . $card['for_name'] : '';
            $timeC = !empty($card['time']) ? ', ' . $card['time'] : '';
            return 'calendar — ' . ($card['title'] ?? '') . ' on ' . ($card['date'] ?? '') . $timeC . $forC;
        case 'note_add':
            $reC = !empty($card['item']) ? ' (re: ' . $card['item'] . ')' : '';
            return 'note — "' . ($card['body'] ?? '') . '"' . $reC;
        case 'employee_remove':
            return 'remove employee — ' . ($card['email'] ?? '');
        case 'poster':
            return 'poster — ' . ($card['name'] ?? '') . ' — ' . money((float)($card['price'] ?? 0));
        default:
            return (string)($card['type'] ?? 'card');
    }
}
// --- the price question Gol just asked, remembered ---------------------------
// When the price-change lane resolves the item but not the price (or the
// price but not the item), the ask it makes rides the session like a
// bill's price wait does: the owner's next words carry the missing piece
// and the staging completes. Gol never asks twice for what it was told.
function golpos_price_await_set(?array $await): void {
    $id = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($id === '') { return; }
    $s = golpos_session($id);
    $s['price_await'] = $await;
    golpos_save_session($id, $s);
}

// --- conversation context: the thread Gol is IN ------------------------------
// A conversation is not a pile of independent sentences: when a lane
// resolves a product, that product becomes the session's topic (ctx),
// and follow-ups bind to it — "change is to $3.19" right after a Coke
// price answer is a Coke price change, not a fresh puzzle. The ctx
// carries only what a lane actually resolved (sku + the intent that
// resolved it); it never guesses a topic into existence.
function golpos_ctx_set(?array $product, string $intent): void {
    $id = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($id === '') { return; }
    $s = golpos_session($id);
    $s['ctx'] = $product === null ? null : [
        'sku' => (string)($product['sku'] ?? ''), 'name' => (string)($product['name'] ?? ''),
        'intent' => $intent, 'ts' => time()];
    golpos_save_session($id, $s);
}

// The ctx topic resolved against the CURRENT shelf (a product renamed
// or removed since it was discussed simply stops binding), or null.
function golpos_ctx_product(array $products): ?array {
    $id = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($id === '') { return null; }
    $ctx = golpos_session($id)['ctx'] ?? null;
    if (!is_array($ctx) || empty($ctx['sku'])) { return null; }
    foreach ($products as $p) {
        if ((string)$p['sku'] === (string)$ctx['sku']) { return $p; }
    }
    return null;
}

// Is this message a QUESTION? The same question words the price lanes
// already trust, plus the question mark itself. While a clarifier wait
// is armed, a question is answered by its own lane and does NOT get
// eaten as the wait's answer (the standing pass-through rule).
function golpos_is_question(string $msg): bool {
    if (preg_match('/\?\s*$/u', trim($msg))) { return true; }
    return preg_match('/(kati|kitna|kitne|how\s+much|how\s+many|ke\s+chha|k\s+ho|kasto|kina|what|which|why|kun|कति|कितना|कुन)/iu', $msg) === 1;
}

// An INFORMATION question — a turn seeking an answer, as opposed to a
// polite imperative ("Can you add ...?", "please add ...", a bare
// imperative wearing a stray "?"). Only information questions are
// fenced off from the write lanes: the Nov 2 ear test watched a whole
// spoken question ("What does your laptop show after adding M&M's
// Peanut at $1.69? Is it in the cart now?") staged as a "New item"
// card named by the question itself. A question may be answered or
// honestly not-caught — it never stages, never rings, never writes.
function golpos_is_info_question(string $msg): bool {
    if (!golpos_is_question($msg)) { return false; }
    if (preg_match('/^\s*(can|could|would|will)\s+you\s+(please\s+)?(add|put|ring|set|make|stage|thap|badha|receive|restock|hala|joda|rakha)\b/iu', $msg)) { return false; }
    if (preg_match('/\bplease\s+(add|put|ring|set|thap)\b/iu', $msg)) { return false; }
    if (preg_match('/^\s*(please\s+)?(add|thap|jodo|lagao|give|ring|receive|restock|set|change|make|put)\b/iu', $msg)) { return false; }
    return true;
}

// A bare greeting (the dispatch greeting lane's own anchored shape) —
// it cancels every armed wait and takes its normal path.
function golpos_is_bare_greeting(string $msg): bool {
    return preg_match('/^\s*(good\s*(morning|afternoon|evening)|namaste|namaskar|hello|hi|hey|suprabhat|नमस्ते|नमस्कार|शुभ\s*प्रभात)\s*[!.।]?\s*$/iu', $msg) === 1;
}

// A price-change imperative carrying its price but naming NO item and
// using NO price word: "change is to $3.19", "make it $3.19". The bare
// form below cannot see it (nothing resolves, so its money test never
// runs) and the named lanes all demand a price word — the sentence fell
// to the free-form composer, which asked "which item?" in prose and
// held no state for the answer. The deterministic lane owns it now:
// bind the conversation's topic, or arm the item wait and ask for real.
function golpos_amount_price_change(string $msg): bool {
    if (golpos_is_question($msg)) { return false; }
    if (preg_match('/(\badd\b|\bthap|\bjodo\b|\blagao\b|\bgive\b|\bchahiyo\b|\bchahinchha\b|\bdeu\b|\bdinus\b|\bayo\b|\baayo\b|\breceive\b|\breceived\b|\brestock\b|\bdelivery\b|\bwithdraw\b|\bexpense\b|kharcha|थप|चाहियो|आयो)/iu', $msg)) { return false; }
    if (!preg_match('/(\bchange\b|\bset\b|\bmake\b|badha|badal|badlo|rakha|gara|गर|बढा|बदल|राख|बनाउ)/iu', $msg)) { return false; }
    return golpos_extract_price($msg, null)['status'] === 'ok';
}

// The bare form rides the same meaning with no price word and no verb at
// all: an item that resolves, plus a price SAID as money ($ on either
// side) or bound to the change word "to". Asking, wanting and receiving
// are other lanes' verbs and never open this one.
function golpos_bare_price_change(string $msg, array $products): bool {
    if (preg_match('/(kati|kitna|kitne|how much|how many|ke chha|k ho|what|why|कति|कितना)/iu', $msg)) { return false; }
    if (preg_match('/(\badd\b|\bthap|\bjodo\b|\blagao\b|\bgive\b|\bchahiyo\b|\bchahinchha\b|\bdeu\b|\bdinus\b|\bayo\b|\baayo\b|\breceive\b|\breceived\b|\brestock\b|\bdelivery\b|थप|चाहियो|आयो)/iu', $msg)) { return false; }
    $verb = preg_match('/(\bchange\b|\bset\b|badha|badal|badlo|rakha|gara|गर|बढा|बदल|राख)/iu', $msg) === 1;
    // A price SAID as money: $-marked (either side) or bound to "to"/"=".
    $moneyBound = static function (?array $product) use ($msg, $products): ?array {
        $ex = golpos_extract_price($msg, $product);
        if ($ex['status'] !== 'ok') { return null; }
        $work = golpos_mask_sizes(golpos_norm_digits($msg), $product);
        foreach (golpos_num_spans($work) as $n) {
            if (abs($n['value'] - $ex['value']) > 0.004) { continue; }
            if ($n['dollar']) { return $ex; }
            $before = golpos_word_before($work, $n['start']);
            if ($before === 'to' || $before === '=') { return $ex; }
        }
        return null;
    };
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        if ($moneyBound($r['one']) !== null) { return true; }
        // A change verb with the item named but the price missing (or
        // two live candidates): the lane owns the follow-up — it asks
        // for the price instead of letting the composer improvise one.
        return $verb;
    }
    if (!empty($r['many'])) {
        if ($moneyBound(null) !== null) { return true; }
        return $verb;
    }
    return false;
}
// --- the bill price wait: margins and per-item answers -----------------------
// A read bill whose new lines lack shelf prices waits in the session
// (bill_await); these readers price it from the owner's next words. The
// margin the owner offered for every unpriced line ("sabai ma 40%
// margin", "40% margin on all", "margin 40%"), or null when the message
// offers none.
function golpos_bill_margin_pct(string $msg): ?float {
    if (!preg_match('/(margin|marjin|मार्जिन)/iu', $msg)) { return null; }
    if (!preg_match('/(\d+(?:\.\d+)?)\s*(?:%|percent)/iu', golpos_norm_digits($msg), $m)) { return null; }
    $p = (float)$m[1];
    return ($p >= 0 && $p <= 1000) ? $p : null;
}
// The still-unpriced new lines of a waiting bill, named with their costs
// for the re-ask. An empty string means the bill is fully priced.
function golpos_bill_missing_list(array $await): string {
    $out = [];
    foreach (($await['lines'] ?? []) as $l) {
        if (!empty($l['new']) && !isset($l['price'])) {
            $out[] = $l['name'] . ' (cost $' . number_format((float)$l['unit_cost'], 2) . ')';
        }
    }
    return implode(', ', $out);
}
// Merge one owner message into a waiting bill's prices. A margin prices
// every unpriced new line from its bill cost, rounded to cents (a line
// whose cost never read stays unpriced — a price is never invented from
// nothing). Otherwise the message is read as per-item prices: segments
// split on commas / "and" / "ani", each carrying one price (read by the
// shared meaning rules) and the name it belongs to, matched against the
// still-unpriced new lines and nobody else; a bare price with exactly
// one line still waiting is that line's price. Returns the updated wait,
// or null when the message priced nothing at all.
function golpos_bill_apply_prices(array $await, string $msg): ?array {
    $lines = $await['lines'] ?? [];
    $unpriced = [];
    foreach ($lines as $i => $l) {
        if (!empty($l['new']) && !isset($l['price'])) { $unpriced[] = $i; }
    }
    if (!$unpriced) { return $await; }
    $pct = golpos_bill_margin_pct($msg);
    if ($pct !== null) {
        $applied = 0;
        foreach ($unpriced as $i) {
            $cost = (float)($lines[$i]['unit_cost'] ?? 0);
            if ($cost <= 0) { continue; }
            $lines[$i]['price'] = round($cost * (1 + $pct / 100), 2);
            $applied++;
        }
        if ($applied === 0) { return null; }
        $await['lines'] = $lines;
        return $await;
    }
    $applied = 0;
    $segments = preg_split('/[,;\n]|\band\b|\bani\b/iu', $msg) ?: [];
    foreach ($segments as $seg) {
        $seg = trim((string)$seg);
        if ($seg === '') { continue; }
        $ex = golpos_extract_price($seg, null);
        if ($ex['status'] !== 'ok' || $ex['value'] <= 0) { continue; }
        $nameText = golpos_item_name_from($seg, $ex['value']);
        $match = null;
        if ($nameText !== '') {
            $scored = [];
            foreach ($unpriced as $i) {
                if (isset($lines[$i]['price'])) { continue; }
                if (golpos_names_equal((string)$lines[$i]['name'], $nameText)) {
                    $scored[] = ['i' => $i, 's' => 99];
                    continue;
                }
                $shared = 0;
                $lk = golpos_name_key((string)$lines[$i]['name']);
                foreach (preg_split('/[^a-z0-9]+/u', golpos_name_key($nameText)) ?: [] as $tok) {
                    if (strlen((string)$tok) >= 3
                        && preg_match('/\b' . preg_quote((string)$tok, '/') . '\b/u', $lk)) { $shared++; }
                }
                if ($shared > 0) { $scored[] = ['i' => $i, 's' => $shared]; }
            }
            if ($scored) {
                usort($scored, static fn($a, $b) => $b['s'] <=> $a['s']);
                $best = array_filter($scored, static fn($c) => $c['s'] === $scored[0]['s']);
                if (count($best) === 1) { $match = $scored[0]['i']; }
            }
        }
        if ($match === null && $nameText === '') {
            $still = array_values(array_filter($unpriced,
                static fn($i) => !isset($lines[$i]['price'])));
            if (count($still) === 1) { $match = $still[0]; }
        }
        if ($match !== null) { $lines[$match]['price'] = round($ex['value'], 2); $applied++; }
    }
    if ($applied === 0) { return null; }
    $await['lines'] = $lines;
    return $await;
}

// A new item's name from the owner's words: command/price words and the one
// price token come out; the item's own sizes (12oz, 4pk) stay — they are
// part of its name.
function golpos_item_name_from(string $msg, ?float $price): string {
    $drop = ['add', 'new', 'item', 'product', 'naya', 'saman', 'thap', 'thapnus', 'thapdinu',
             'jodo', 'price', 'mulya', 'dam', 'daam', 'ma', 'ho', 'for', 'at', 'to', 'set',
             'gara', 'gar', 'rakha', 'please', 'plz', 'ota', 'vata', 'pcs', 'pieces',
             'नयाँ', 'नया', 'सामान', 'थप', 'थप्नुस्', 'मूल्य', 'दाम', 'मा', 'गर', 'राख'];
    $out = []; $dropped = false;
    foreach (preg_split('/\s+/u', trim(golpos_norm_digits($msg))) as $tok) {
        $clean = trim((string)preg_replace('/[\p{P}\p{S}]+/u', '', (string)$tok));
        if ($clean === '') { continue; }
        // The price token itself: read the RAW token as a pure number
        // ("$3.99", "3.25") — the cleaned token has its punctuation
        // stripped ("399", "325") and never equals the price, so a
        // punctuated price used to survive into the item's name. A token
        // with letters ("12oz", "4pk") is a size, never the price.
        if ($price !== null && !$dropped
            && preg_match('/^\$?\d+(?:\.\d+)?$/', (string)$tok)
            && abs((float)str_replace('$', '', (string)$tok) - $price) < 0.005) {
            $dropped = true; continue; // the price token itself
        }
        if (in_array(mb_strtolower($clean), $drop, true)) { continue; }
        $out[] = $clean;
    }
    return trim(implode(' ', $out));
}
// A name's comparison key: case, punctuation and size-gluing normalized, so
// "Coca-Cola 20oz", "coca cola 20 oz" and "CocaCola 20oz" are the same name
// — while "AAA" never collapses into "AA".
function golpos_name_key(string $s): string {
    $s = mb_strtolower(golpos_norm_digits($s));
    $s = preg_replace('/(\d)(oz|pk|ct|ml|kg|gm|lb)\b/u', '$1 $2', $s);
    $s = preg_replace('/\bpk\b/u', 'pack', (string)$s);
    $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', (string)$s);
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
}
function golpos_names_equal(string $a, string $b): bool {
    $ka = golpos_name_key($a); $kb = golpos_name_key($b);
    if ($ka === $kb) { return true; }
    return str_replace(' ', '', $ka) === str_replace(' ', '', $kb);
}
// Stage the one new-item card shape every lane uses.
function golpos_stage_new_item(string $name, float $price, string $barcode = ''): void {
    $card = golpos_stage(['type' => 'new_item', 'name' => $name, 'price' => $price,
                          'category' => 'Counter', 'barcode' => $barcode]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}

// --- register order prep: vendors, rep days, the draft ------------------------
// The pack (store.php golpos_vendors) is taught and read here. Weekday keys
// are 3-letter lowercase (mon..sun); the owner's words for them are English
// (full + short), Romanized Nepali (-bar forms) and Devanagari.
function golpos_weekday_map(): array {
    return [
        'sun' => ['sunday', 'sun', 'aitabar', 'आइतबार'],
        'mon' => ['monday', 'mon', 'sombar', 'सोमबार'],
        'tue' => ['tuesday', 'tue', 'tues', 'mangalbar', 'मङ्गलबार', 'मंगलवार'],
        'wed' => ['wednesday', 'wed', 'budhabar', 'बुधबार'],
        'thu' => ['thursday', 'thu', 'thur', 'thurs', 'bihibar', 'बिहीबार', 'बिहिवार'],
        'fri' => ['friday', 'fri', 'shukrabar', 'शुक्रबार'],
        'sat' => ['saturday', 'sat', 'shanibar', 'शनिबार'],
    ];
}
// Every weekday the message names, in spoken order, deduped.
function golpos_weekdays_in(string $msg): array {
    $low = ' ' . mb_strtolower(golpos_norm_digits($msg)) . ' ';
    $found = []; // word position -> day key
    foreach (golpos_weekday_map() as $key => $words) {
        foreach ($words as $w) {
            $pos = preg_match('/[\x{0900}-\x{097F}]/u', $w)
                ? mb_strpos($low, $w)
                : (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $low, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false);
            if ($pos !== false) { $found[(int)$pos] = $key; }
        }
    }
    ksort($found);
    return array_values(array_unique(array_values($found)));
}
function golpos_day_name(string $key): string {
    return ['sun' => 'Sunday', 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
            'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday'][$key] ?? $key;
}
function golpos_day_names(array $keys): string {
    return implode(' + ', array_map('golpos_day_name', $keys));
}
// (The due-day rule itself lives with the data layer in store.php —
// golpos_vendor_due_on / golpos_vendors_due — because the Owner panel's
// API reads it too.)
// Resolve a vendor from the owner's words: the full name key inside the
// text wins, then an alias, then one distinctive name token (4+ letters,
// not a generic company word). Same one/many/none shape as resolve_item.
function golpos_vendor_resolve(array $vendors, string $text): array {
    $t = ' ' . golpos_name_key($text) . ' ';
    foreach ($vendors as $v) {
        $k = golpos_name_key((string)($v['name'] ?? ''));
        if ($k !== '' && mb_strpos($t, ' ' . $k . ' ') !== false) { return ['one' => $v]; }
    }
    $hits = [];
    foreach ($vendors as $v) {
        foreach ((array)($v['aliases'] ?? []) as $a) {
            $a = golpos_name_key((string)$a);
            if ($a !== '' && mb_strpos($t, ' ' . $a . ' ') !== false) { $hits[] = $v; break; }
        }
    }
    if (count($hits) === 1) { return ['one' => $hits[0]]; }
    if (count($hits) > 1) { return ['many' => $hits]; }
    $generic = ['co', 'company', 'inc', 'llc', 'distributors', 'distributor', 'supply', 'supplies',
                'beverage', 'beverages', 'foods', 'group', 'sales', 'mart', 'store'];
    $tokHits = [];
    foreach ($vendors as $v) {
        foreach (preg_split('/\s+/u', golpos_name_key((string)($v['name'] ?? ''))) ?: [] as $tok) {
            if (mb_strlen((string)$tok) >= 4 && !in_array($tok, $generic, true)
                && mb_strpos($t, ' ' . $tok . ' ') !== false) { $tokHits[] = $v; break; }
        }
    }
    if (count($tokHits) === 1) { return ['one' => $tokHits[0]]; }
    if (count($tokHits) > 1) { return ['many' => $tokHits]; }
    return ['none' => true];
}
// The draft for one vendor, built from the three inputs IN ORDER:
// (1) What's Out + below-par shelf state, (2) sales velocity since the
// vendor's last delivery, (3) the vendor's typical lines from bill
// history (this pack's records + the register's executed purchases).
// A line earns a suggested quantity of max(what sold since the last
// delivery, what refills the shelf to par); an OUT line with no signal
// falls back to its usual bill quantity — never to an invented number.
function golpos_prep_build(array $vendor, array $products): array {
    $seeds = []; // name key -> seed line
    $addSeed = static function (array $s) use (&$seeds): void {
        $k = golpos_bill_name_key((string)($s['name'] ?? ''));
        if ($k === '') { return; }
        if (!isset($seeds[$k])) { $seeds[$k] = $s; return; }
        foreach (['qty', 'cost', 'par'] as $f) {
            if (!isset($seeds[$k][$f]) && isset($s[$f])) { $seeds[$k][$f] = $s[$f]; }
        }
    };
    // What's Out reports seed FIRST (employee lane): an item a person
    // stood in front of and reported out earns its line before any
    // history heuristic — the report is itself the signal, and the
    // quantity rules below give it max(sold, refill-to-par, usual).
    foreach (golpos_whatsout_open() as $w) {
        $wn = trim((string)($w['name'] ?? ''));
        if ($wn !== '') { $addSeed(['name' => $wn, 'from_report' => true]); }
    }
    foreach ((array)($vendor['typical'] ?? []) as $tl) {
        if (is_array($tl)) {
            $addSeed(['name' => (string)($tl['name'] ?? ''),
                      'qty' => isset($tl['qty']) && is_numeric($tl['qty']) ? (int)$tl['qty'] : null,
                      'cost' => isset($tl['cost']) && is_numeric($tl['cost']) ? (float)$tl['cost'] : null,
                      'par' => isset($tl['par']) && is_numeric($tl['par']) ? (int)$tl['par'] : null]);
        }
    }
    foreach (golpos_read('purchases.json', []) as $rec) {
        if (!is_array($rec)) { continue; }
        if (!golpos_names_equal((string)($rec['vendor'] ?? ''), (string)($vendor['name'] ?? ''))) { continue; }
        foreach ((array)($rec['lines'] ?? []) as $l) {
            if (!is_array($l)) { continue; }
            $addSeed(['name' => (string)($l['name'] ?? ''),
                      'qty' => isset($l['qty']) && is_numeric($l['qty']) ? (int)$l['qty'] : null,
                      'cost' => isset($l['unit_cost']) && is_numeric($l['unit_cost']) ? (float)$l['unit_cost'] : null]);
        }
    }
    $since = (string)($vendor['last_delivery'] ?? ($vendor['last_bill']['date'] ?? ''));
    if ($since === '' || strtotime($since) === false) { $since = date('Y-m-d', strtotime('-7 days')); }
    $since = substr($since, 0, 10);
    $soldBy = []; // sku and name key -> units sold strictly after the delivery day
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) <= $since) { continue; }
        foreach ((array)($s['lines'] ?? []) as $l) {
            $q = max(1, (int)($l['qty'] ?? 1));
            if (!empty($l['sku'])) { $soldBy['sku:' . $l['sku']] = ($soldBy['sku:' . $l['sku']] ?? 0) + $q; }
            $nk = golpos_bill_name_key((string)($l['name'] ?? ''));
            if ($nk !== '') { $soldBy['nm:' . $nk] = ($soldBy['nm:' . $nk] ?? 0) + $q; }
        }
    }
    $lines = []; $fine = 0; $est = 0.0; $uncosted = 0;
    foreach ($seeds as $k => $seed) {
        $p = golpos_bill_match($products, (string)$seed['name']);
        $name = $p !== null ? (string)$p['name'] : (string)$seed['name'];
        $sku = $p !== null ? (string)$p['sku'] : '';
        $onHand = null; $par = $seed['par'] ?? null;
        if ($p !== null) {
            if (isset($p['onHand']) && is_numeric($p['onHand'])) { $onHand = $p['onHand'] + 0; }
            elseif (isset($p['stock']) && is_numeric($p['stock'])) { $onHand = $p['stock'] + 0; }
            if (isset($p['par']) && is_numeric($p['par'])) { $par = (int)$p['par']; }
        }
        $sold = $sku !== '' && isset($soldBy['sku:' . $sku]) ? $soldBy['sku:' . $sku]
            : ($soldBy['nm:' . $k] ?? 0);
        $topup = ($par !== null && $onHand !== null) ? max(0, (int)$par - (int)$onHand) : 0;
        $sug = max((int)$sold, $topup);
        if ($sug === 0 && $onHand !== null && $onHand <= 0) {
            $sug = (int)($seed['qty'] ?? 0) > 0 ? (int)$seed['qty'] : 1; // OUT, no signal: the usual line
        }
        if ($sug <= 0 && !empty($seed['from_report'])) {
            $sug = (int)($seed['qty'] ?? 0) > 0 ? (int)$seed['qty'] : 1; // a report is itself the signal
        }
        if ($sug <= 0) { $fine++; continue; }
        $cost = $seed['cost'] ?? null;
        if ($cost === null && $p !== null && isset($p['cost']) && is_numeric($p['cost'])) {
            $cost = (float)$p['cost'];
        }
        $flag = !empty($seed['from_report']) ? 'out'
            : (($onHand !== null && $onHand <= 0) ? 'out'
            : ($topup > 0 ? 'low' : 'sold'));
        $line = ['sku' => $sku, 'name' => $name, 'qty' => $sug,
                 'cost' => $cost !== null ? round((float)$cost, 2) : null,
                 'line_total' => $cost !== null ? round($sug * (float)$cost, 2) : null,
                 'on_hand' => $onHand, 'par' => $par, 'sold' => (int)$sold, 'flag' => $flag];
        if ($cost !== null) { $est += $line['line_total']; } else { $uncosted++; }
        $lines[] = $line;
    }
    $rank = ['out' => 0, 'low' => 1, 'sold' => 2];
    usort($lines, static fn($a, $b) => (($rank[$a['flag']] ?? 3) <=> ($rank[$b['flag']] ?? 3))
        ?: ((float)($b['line_total'] ?? 0) <=> (float)($a['line_total'] ?? 0)));
    return ['lines' => $lines, 'fine' => $fine, 'est_total' => round($est, 2),
            'uncosted' => $uncosted, 'since' => $since];
}
// One line of a staged order card, spoken: name × qty, its cost when one is
// on record, and the shelf truth that earned it (OUT / on hand vs par /
// sold since the last delivery).
function golpos_order_line_text(array $l): string {
    $tail = '';
    if (($l['flag'] ?? '') === 'out') { $tail .= ' — OUT'; }
    elseif (isset($l['on_hand']) && $l['on_hand'] !== null) {
        $tail .= ' — ' . rtrim(rtrim(number_format((float)$l['on_hand'], 2, '.', ''), '0'), '.') . ' on hand'
            . (isset($l['par']) && $l['par'] !== null ? ' / par ' . (int)$l['par'] : '')
            . (!empty($l['sold']) ? ' · sold ' . (int)$l['sold'] : '');
    }
    if (isset($l['cost']) && $l['cost'] !== null) { $tail .= ' · ' . money((float)$l['cost']) . ' ea'; }
    return t('ord_line', (string)$l['name'], (int)$l['qty'], $tail);
}
function golpos_order_status_word(string $st): string {
    return ['prepared' => 'prepared (draft staged)', 'confirmed' => 'confirmed — sheet ready for the rep',
            'given' => 'given to the rep', 'none' => 'not prepared yet'][$st] ?? $st;
}
// A rep-day / order ask about a vendor Gol does not know gets the pack
// list, never a guess.
function golpos_order_ask(string $msg, array $products): void {
    $vendors = golpos_vendors();
    if (!$vendors) { out(['reply' => t('ord_no_vendors')]); }
    $r = golpos_vendor_resolve($vendors, $msg);
    if (!empty($r['one'])) { golpos_stage_order_prep($r['one'], $products); }
    if (!empty($r['many'])) {
        out(['reply' => t('ord_which_vendor', golpos_vendors_due(date('Y-m-d'))
                ? implode(', ', array_map(static fn($v) => $v['name'], golpos_vendors_due(date('Y-m-d'))))
                : 'none',
            implode(', ', array_map(static fn($v) => $v['name'], $vendors)))]);
    }
    // No vendor named: exactly one due today -> that one; several -> ask.
    $due = golpos_vendors_due(date('Y-m-d'));
    if (count($due) === 1) { golpos_stage_order_prep($due[0], $products); }
    if (count($due) > 1) {
        out(['reply' => t('ord_which_vendor',
            implode(', ', array_map(static fn($v) => $v['name'], $due)),
            implode(', ', array_map(static fn($v) => $v['name'], $vendors)))]);
    }
    out(['reply' => t('ord_vendor_unknown',
        implode(', ', array_map(static fn($v) => $v['name'], $vendors)))]);
}
// Build the draft, keep it as the vendor's order record for today
// (prepared), and stage the ONE card. A draft with nothing to order is
// answered, never staged — an empty card is a bluff of its own kind.
function golpos_stage_order_prep(array $vendor, array $products): void {
    $today = date('Y-m-d');
    $prep = golpos_prep_build($vendor, $products);
    if (!$prep['lines']) {
        out(['reply' => t('ord_none_needed', (string)$vendor['name'],
                          count((array)($vendor['typical'] ?? [])) + $prep['fine'])]);
    }
    $existing = golpos_order_for((string)$vendor['id'], $today);
    $rec = ['id' => $existing['id'] ?? ('ord_' . substr(sha1(uniqid('', true)), 0, 10)),
            'vendor_id' => (string)$vendor['id'], 'vendor' => (string)$vendor['name'],
            'date' => $today, 'lines' => $prep['lines'],
            'est_total' => $prep['est_total'], 'uncosted' => $prep['uncosted'],
            'fine' => $prep['fine'], 'status' => 'prepared',
            'staged_at' => date('c'), 'confirmed_at' => $existing['confirmed_at'] ?? null,
            'given_at' => null, 'since' => $prep['since']];
    if (($existing['status'] ?? '') === 'given') { $rec['status'] = 'prepared'; }
    golpos_order_put($rec);
    $card = golpos_stage(['type' => 'order_prep', 'order_id' => $rec['id'],
                          'vendor_id' => (string)$vendor['id'], 'vendor' => (string)$vendor['name'],
                          'date' => $today, 'lines' => $prep['lines'],
                          'est_total' => $prep['est_total'], 'uncosted' => $prep['uncosted'],
                          'fine' => $prep['fine']]);
    $lines = [t('ord_head', (string)$vendor['name'], golpos_day_name(strtolower(date('D'))))];
    foreach ($prep['lines'] as $l) { $lines[] = golpos_order_line_text($l); }
    if ($prep['fine'] > 0) { $lines[] = t('ord_fine', $prep['fine']); }
    $lines[] = t('ord_est', money($prep['est_total']),
                 $prep['uncosted'] > 0 ? t('ord_uncosted', $prep['uncosted']) : '');
    out(['reply' => implode("\n", $lines), 'card' => $card]);
}
// The owner's edits to a staged order card: "coke 12 hata" strikes a line,
// "pepsi 20 thap" sets its quantity (a catalog item not yet on the card
// joins it at the vendor's recorded cost). Returns true when the message
// was an edit (handled), false when it was something else entirely.
function golpos_order_apply_edits(array $card, string $msg, array $products): bool {
    $segments = preg_split('/[,;\n]|\band\b|\bani\b/iu', $msg) ?: [];
    $lines = (array)($card['lines'] ?? []);
    $changed = false;
    foreach ($segments as $seg) {
        $seg = trim((string)$seg);
        if ($seg === '') { continue; }
        $strike = preg_match('/(hata|hatau|remove|strike|nikal|हटाउ|निकाल)/iu', $seg) === 1;
        $set = preg_match('/(thap|thapnus|add|jodo|set|rakha|थप|राख)/iu', $seg) === 1;
        if (!$strike && !$set) { return false; }
        // The quantity, by the shared meaning rules — a size inside an
        // item's own name (20oz) is never the count: extraction runs on
        // the size-masked string, and only a lone standing number counts.
        $qty = null;
        $maskedCount = golpos_extract_count($seg, null);
        if ($maskedCount !== null) { $qty = (int)$maskedCount; }
        else {
            $bare = golpos_mask_sizes(golpos_norm_digits($seg), null);
            if (preg_match_all('/\d+(?:\.\d+)?/u', $bare, $nm)) {
                $nums = array_values(array_filter(array_map('floatval', $nm[0]),
                    static fn($v) => $v > 0 && $v <= 9999));
                if (count($nums) === 1) { $qty = (int)$nums[0]; }
            }
        }
        // Resolve the item the segment names. An edit is a precise act:
        // the segment must name the item by its name key or a known
        // nickname, or share a DISTINCTIVE (non-size) name token with
        // it. The generic resolver's token score is NOT used here — it
        // once set the Coke line's quantity for a Sprite edit because
        // both names carry "20oz".
        $nameText = trim((string)preg_replace(
            '/\b(hata|hatau|hataideu|remove|strike|nikal|thap|thapnus|thapdeu|add|jodo|set|rakha|ota|vata|pcs|pieces)\b/iu',
            ' ', $seg));
        $nameText = trim((string)preg_replace('/\s+/u', ' ', $nameText));
        $candKey = golpos_bill_name_key($nameText);
        $candDistinct = array_values(array_filter(
            preg_split('/\s+/u', $candKey) ?: [],
            static fn($w) => $w !== '' && !is_numeric($w)
                && !in_array($w, ['oz', 'pk', 'ct', 'ml', 'gm', 'g', 'kg', 'lb', 'pack', 'count', 'fl'], true)));
        $matchPool = static function (array $pool) use ($candKey, $candDistinct, $seg): ?array {
            if ($candKey === '') { return null; }
            foreach ($pool as $p) {
                if (golpos_bill_name_key((string)$p['name']) === $candKey) { return $p; }
                foreach ((array)($p['aliases'] ?? []) as $a) {
                    $ak = golpos_bill_name_key((string)$a);
                    if ($ak !== '' && ($ak === $candKey
                        || preg_match('/\b' . preg_quote((string)$a, '/') . '(?:s|es)?\b/iu', $seg) === 1)) {
                        return $p;
                    }
                }
            }
            foreach ($pool as $p) {
                $pk = golpos_bill_name_key((string)$p['name']);
                if ($pk !== '' && (mb_strpos($candKey, $pk) !== false || mb_strpos($pk, $candKey) !== false)) {
                    return $p;
                }
                $shared = array_intersect($candDistinct,
                    preg_split('/\s+/u', $pk) ?: []);
                if ($shared) { return $p; }
            }
            return null;
        };
        $target = null; $targetIdx = null;
        $aliasOf = [];
        foreach ($products as $p) { $aliasOf[(string)($p['sku'] ?? '')] = (array)($p['aliases'] ?? []); }
        $lineProducts = array_map(static fn($l) => ['name' => $l['name'], 'sku' => $l['sku'] ?? '',
            'aliases' => $aliasOf[(string)($l['sku'] ?? '')] ?? []], $lines);
        $hitLine = $matchPool($lineProducts);
        if ($hitLine !== null) {
            foreach ($lines as $i => $l) {
                if (($l['sku'] ?? '') === ($hitLine['sku'] ?? '') || $l['name'] === $hitLine['name']) {
                    $target = $l; $targetIdx = $i; break;
                }
            }
        }
        if ($target === null) {
            $p = $matchPool($products);
            if ($p !== null) {
                // The vendor's own recorded cost for the line beats the
                // catalog's (the bill history is where costs live).
                $cost = null;
                $vend = golpos_vendor_by_id((string)($card['vendor_id'] ?? ''));
                foreach ((array)($vend['typical'] ?? []) as $tl) {
                    if (is_array($tl) && golpos_names_equal((string)($tl['name'] ?? ''), (string)$p['name'])
                        && isset($tl['cost']) && is_numeric($tl['cost'])) {
                        $cost = (float)$tl['cost'];
                        break;
                    }
                }
                if ($cost === null && isset($p['cost']) && is_numeric($p['cost'])) {
                    $cost = (float)$p['cost'];
                }
                $target = ['sku' => (string)$p['sku'], 'name' => (string)$p['name'],
                           'qty' => 0, 'cost' => $cost, 'line_total' => null,
                           'on_hand' => $p['onHand'] ?? ($p['stock'] ?? null),
                           'par' => isset($p['par']) ? (int)$p['par'] : null, 'sold' => 0, 'flag' => 'sold'];
                $targetIdx = count($lines);
                $lines[] = $target;
            }
        }
        if ($target === null || $targetIdx === null) { return false; }
        if ($strike && $qty === null) {
            array_splice($lines, $targetIdx, 1);
            $changed = true;
            continue;
        }
        $newQty = $qty ?? (int)($target['qty'] ?? 0);
        if ($strike) { $newQty = max(0, (int)$target['qty'] - $newQty); }
        if ($newQty <= 0) {
            array_splice($lines, $targetIdx, 1);
        } else {
            $lines[$targetIdx]['qty'] = $newQty;
            $lines[$targetIdx]['line_total'] = isset($lines[$targetIdx]['cost']) && $lines[$targetIdx]['cost'] !== null
                ? round($newQty * (float)$lines[$targetIdx]['cost'], 2) : null;
        }
        $changed = true;
    }
    if (!$changed) { return false; }
    if (!$lines) {
        // Every line struck: the draft is empty, so the card clears —
        // an empty order is never staged or confirmed.
        golpos_clear_pending();
        out(['reply' => t('ask_cancelled'), 'cancelled' => true]);
    }
    $est = 0.0; $uncosted = 0;
    foreach ($lines as $l) {
        if (isset($l['cost']) && $l['cost'] !== null) { $est += (float)($l['line_total'] ?? 0); }
        else { $uncosted++; }
    }
    $card['lines'] = $lines;
    $card['est_total'] = round($est, 2);
    $card['uncosted'] = $uncosted;
    $card = golpos_stage($card); // a fresh card id, the same order record
    $rec = golpos_order_for((string)$card['vendor_id'], (string)$card['date']);
    if ($rec !== null) {
        $rec['lines'] = $lines; $rec['est_total'] = $card['est_total'];
        $rec['uncosted'] = $uncosted; $rec['status'] = 'prepared';
        golpos_order_put($rec);
    }
    out(['reply' => t('ord_edited', (string)$card['vendor'], count($lines), money($card['est_total'])),
         'card' => $card]);
}
// --- order-prep intent matchers ------------------------------------------------
function golpos_is_order_prep_ask(string $msg): bool {
    if (!preg_match('/(order|अर्डर)/iu', $msg)) { return false; }
    if (preg_match('/(status|diye|diyo|diyeko|given|handed|delivered|ke bhayo|k bhayo|भयो|दिए)/iu', $msg)) { return false; }
    return preg_match('/(bana|banau|banaideu|banai|prepare|prep|tayar|tayaar|deu|dinus|chahiyo|चाहियो|बना|तयार)/iu', $msg) === 1;
}
function golpos_is_order_given(string $msg): bool {
    return preg_match('/(order|अर्डर)/iu', $msg) === 1
        && preg_match('/(diye|diyo|diyeko|diyisake|handed|delivered|rep lai|rep laai|दिए|दियो|गयो)/iu', $msg) === 1;
}
function golpos_is_order_status_ask(string $msg): bool {
    return preg_match('/(order|अर्डर)/iu', $msg) === 1
        && preg_match('/(status|k bhayo|ke bhayo|bhayo|ready|tayar|confirmed|pakka|भयो|तयार)/iu', $msg) === 1;
}
function golpos_is_repday_ask(string $msg): bool {
    if (preg_match('/(aaja|today|आज)/iu', $msg) && preg_match('/(rep|vendor|ko aau|ko ko|aauch|आउँछ|come|visit)/iu', $msg)) { return true; }
    return preg_match('/(rep|vendor).{0,16}(aaja|today|din|day|आज)/iu', $msg) === 1;
}
// A teach sentence names a weekday AND a rep/visit word ("lipton ko rep
// sombar aaucha", "sunrise dairy — delivery mangalbar"). The weekday list
// is read by golpos_weekdays_in; this matcher only decides the lane.
function golpos_is_teach_ask(string $msg): bool {
    if (golpos_weekdays_in($msg) === []) { return false; }
    return preg_match('/(rep|visit|delivery|deliver|aauch|auchha|aaucha|comes|आउँछ)/iu', $msg) === 1;
}
// --- day aggregates the register already owns (hisab D-12) ---------------------
// Best-seller and day-compare questions are READS over the till's own
// sales log: top items by quantity and by revenue for a day, and two
// days side by side. They are the same class as the running day total —
// cashier-domain, answered deterministically, never composed.
function golpos_day_items(string $ymd): array {
    $items = [];
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        foreach ((array)($s['lines'] ?? []) as $l) {
            $n = (string)($l['name'] ?? '');
            if ($n === '') { continue; }
            if (!isset($items[$n])) { $items[$n] = ['name' => $n, 'qty' => 0, 'rev' => 0.0]; }
            $items[$n]['qty'] += max(1, (int)($l['qty'] ?? 1));
            $items[$n]['rev'] = round($items[$n]['rev'] + (float)($l['line_total'] ?? 0), 2);
        }
    }
    return array_values($items);
}
function golpos_is_bestseller_ask(string $msg): bool {
    if (preg_match('/(week|hapta|month|mahina|हप्ता|महिना)/iu', $msg)) { return false; }
    return preg_match('/(best|top)[\s-]*(sell|selling|sold|seller)/iu', $msg) === 1
        || preg_match('/sold\s+(the\s+)?(most|best)/iu', $msg) === 1
        || preg_match('/(dherai|धेरै).{0,20}(bik|बिक)/iu', $msg) === 1;
}
function golpos_is_compare_ask(string $msg): bool {
    if (preg_match('/(week|month|mahina|हप्ता|महिना)/iu', $msg)) { return false; }
    if (!preg_match('/(compare|vs\.?|versus|difference|farak|फरक|तुलना)/iu', $msg)) { return false; }
    return preg_match('/(hijo|yesterday|aaja|today|हिजो|आज)/iu', $msg) === 1;
}
function golpos_day_label(string $ymd): string {
    if ($ymd === date('Y-m-d')) { return 'Today (' . date('M j', strtotime($ymd)) . ')'; }
    if ($ymd === date('Y-m-d', strtotime('-1 day'))) { return 'Yesterday (' . date('M j', strtotime($ymd)) . ')'; }
    return date('M j', strtotime($ymd));
}

// --- visual reports: ONE payload, computed once (owner, Oct 28) ----------------
// Report answers carry a graph payload the chat renders as an inline
// card under the text. PROVENANCE LAW: the payload is built by the same
// lane, from the same golpos_day_summary / golpos_day_items figures the
// text states — never a second read for the picture. If the text says
// $1,240, the bars sum to $1,240 by construction. An all-zero range is
// flagged empty and the client draws an honest flat card, never a shape.
function golpos_graph_payload(string $kind, string $title, array $series, float $total, ?array $focus = null): array {
    $empty = true;
    foreach ($series as $p) { if ((float)($p['value'] ?? 0) != 0.0) { $empty = false; break; } }
    $g = ['kind' => $kind, 'title' => $title, 'unit' => 'money',
          'series' => $series, 'total' => round($total, 2), 'empty' => $empty];
    if ($empty) { $g['empty_text'] = t('graph_empty'); }
    if ($focus !== null) { $g['focus'] = $focus; }
    return $g;
}
// Per-day gross bars for the trailing $n days ending today, from the
// same day summaries every total in this brain is read from.
function golpos_graph_days_series(int $n, ?string $focusYmd = null): array {
    $series = []; $total = 0.0;
    for ($i = $n - 1; $i >= 0; $i--) {
        $ymd = date('Y-m-d', strtotime("-{$i} days"));
        $v = round((float)golpos_day_summary($ymd)['gross'], 2);
        $total = round($total + $v, 2);
        $p = ['label' => date('D j', strtotime($ymd)), 'value' => $v, 'ymd' => $ymd];
        if ($focusYmd !== null && $ymd === $focusYmd) { $p['highlight'] = true; }
        $series[] = $p;
    }
    return [$series, $total];
}
// Per-day gross points for the current month, day 1 through today.
function golpos_graph_month_series(): array {
    $series = []; $total = 0.0;
    for ($d = 1; $d <= (int)date('j'); $d++) {
        $ymd = date('Y-m-') . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $v = round((float)golpos_day_summary($ymd)['gross'], 2);
        $total = round($total + $v, 2);
        $series[] = ['label' => date('M j', strtotime($ymd)), 'value' => $v, 'ymd' => $ymd,
                     'highlight' => $ymd === date('Y-m-d')];
    }
    return [$series, $total];
}
// Per-day gross bars for the trailing $n days ENDING on a named day —
// the calendar tap's picture: the week that led into the asked day,
// with the asked day lit. Same day summaries, same return shape.
function golpos_graph_days_series_to(int $n, string $endYmd): array {
    $series = []; $total = 0.0;
    $end = strtotime($endYmd);
    for ($i = $n - 1; $i >= 0; $i--) {
        $ymd = date('Y-m-d', strtotime("-{$i} days", $end));
        $v = round((float)golpos_day_summary($ymd)['gross'], 2);
        $total = round($total + $v, 2);
        $p = ['label' => date('D j', strtotime($ymd)), 'value' => $v, 'ymd' => $ymd];
        if ($ymd === $endYmd) { $p['highlight'] = true; }
        $series[] = $p;
    }
    return [$series, $total];
}
// --- report card companions: cash block + month calendar (owner, Oct 28) -----
// The graph card grows two companions built from the SAME reads that
// compose the sentence and the bars — golpos_day_summary() over the
// register journal. No second truth: the cash block sums exactly the
// days the lane reports on, and the calendar is the register's own
// journal days for the current month.
function golpos_cash_block(array $ymds): array {
    $cash = 0.0; $card = 0.0; $credit = 0.0; $total = 0.0; $txns = 0;
    foreach ($ymds as $ymd) {
        $s = golpos_day_summary($ymd);
        $cash += $s['cash']; $card += $s['card']; $credit += $s['credit'];
        $total += $s['gross']; $txns += $s['txns'];
    }
    // Honesty gate: the split is fact only when every sale in the period
    // actually carries its tender — day_summary reads a sale with no
    // tender record as cash, which is an assumption, not a fact. A
    // period containing such a sale (or a refund with no tender map)
    // says so plainly instead of dressing the assumption up as truth.
    $split = true;
    if ($txns > 0) {
        $set = array_flip($ymds);
        foreach (golpos_sales() as $r) {
            if (!isset($set[substr((string)($r['at'] ?? ''), 0, 10)])) { continue; }
            if (!isset($r['tender']) && !isset($r['tenders'])) { $split = false; break; }
        }
        if ($split) {
            foreach (golpos_refunds() as $r) {
                if (!isset($set[substr((string)($r['at'] ?? ''), 0, 10)])) { continue; }
                if (empty($r['tenders']) || !is_array($r['tenders'])) { $split = false; break; }
            }
        }
    }
    $blk = ['cash' => round($cash, 2), 'card' => round($card, 2),
            'total' => round($total, 2), 'txns' => $txns, 'split' => $split,
            'cash_lbl' => t('cash_lbl'), 'card_lbl' => t('card_lbl'),
            'credit_lbl' => t('credit_lbl')];
    if ($credit > 0) { $blk['credit'] = round($credit, 2); }
    if (!$split && $txns > 0) { $blk['note'] = t('cash_split_none'); }
    return $blk;
}
function golpos_calendar_payload(): array {
    $today = date('Y-m-d');
    $first = date('Y-m-01');
    $days = [];
    $n = (int)date('t');
    for ($d = 1; $d <= $n; $d++) {
        $ymd = substr($first, 0, 8) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $s = golpos_day_summary($ymd);
        $days[] = ['d' => $d, 'ymd' => $ymd, 'value' => round((float)$s['gross'], 2),
                   'txns' => (int)$s['txns'],
                   'has' => $s['gross'] > 0 || $s['txns'] > 0,
                   'today' => $ymd === $today];
    }
    return ['month' => date('F Y', strtotime($first)),
            'lead' => (int)date('w', strtotime($first)),
            'dows' => array_map('trim', explode(',', t('cal_dows'))),
            'days' => $days, 'hint' => t('cal_hint')];
}
function golpos_report_card(array $graph, array $ymds): array {
    $graph['cash'] = golpos_cash_block($ymds);
    $graph['calendar'] = golpos_calendar_payload();
    return $graph;
}

// --- employee lane composition --------------------------------------------------
// The session's actor: who this register is open as. An unlock stamps
// it (unlocked_by / unlocked_role); a device-identified employee
// session carries it too. Admin is the owner; an employee actor holds
// the cashier domain only.
function golpos_actor(): ?array {
    $sid = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($sid === '') { return null; }
    $s = golpos_session($sid);
    if (($s['unlocked_role'] ?? '') === 'employee' && !empty($s['unlocked_by'])) {
        $e = golpos_employee((string)$s['unlocked_by']);
        return ['email' => (string)$s['unlocked_by'], 'role' => 'employee',
                'name' => (string)($s['unlocked_name'] ?? ''), 'record' => $e];
    }
    if (!empty($s['employee'])) {
        $e = golpos_employee((string)$s['employee']);
        if ($e !== null) {
            return ['email' => (string)$e['email'], 'role' => 'employee',
                    'name' => golpos_display_name($e), 'record' => $e];
        }
    }
    if (!empty($s['admin'])) { return ['email' => '', 'role' => 'admin', 'name' => 'Owner', 'record' => null]; }
    return null;
}

// The owner's team line: the backlog as ONE glanceable line of
// counts only (owner, Nov 2 late: the named multi-sentence version
// was "to much to read"). No holder names, no parentheticals, no
// detail — the grouped detail answers when he asks ("what tasks are
// open?", "pay requests", "price gaps"). Empty string when clear.
function golpos_team_line(): string {
    $today = date('Y-m-d');
    $openCount = 0;
    foreach (golpos_tasks() as $t) {
        if (($t['status'] ?? '') === 'superseded' || golpos_task_status($t) === 'done') { continue; }
        if (substr((string)($t['created'] ?? ''), 0, 10) >= $today) { continue; }
        foreach ((array)($t['items'] ?? []) as $it) { if (empty($it['done'])) { $openCount++; } }
    }
    $since = strtotime(date('Y-m-d', strtotime('-1 day')) . ' 18:00');
    $notes = 0;
    foreach (golpos_team_notes() as $n) {
        if (empty($n['read']) && strtotime((string)($n['at'] ?? '')) >= $since) { $notes++; }
    }
    $payCount = count(golpos_pay_pending_all());
    $gapWait = count(golpos_gap_scan());
    $bits = [];
    if ($openCount > 0) { $bits[] = $openCount . ' tasks open'; }
    if ($gapWait > 0) { $bits[] = $gapWait . ' prices waiting'; }
    if ($notes > 0) { $bits[] = $notes . ' note(s) unread'; }
    if ($payCount > 0) { $bits[] = $payCount . ' pay request(s) waiting'; }
    return $bits ? implode(' · ', $bits) . '.' : '';
}

// One person's open items, flattened in list order — the numbering the
// task view speaks and the done lane counts by.
function golpos_open_items_for(string $email): array {
    $out = [];
    foreach (golpos_tasks_for($email) as $t) {
        foreach ((array)($t['items'] ?? []) as $idx => $it) {
            if (!empty($it['done'])) { continue; }
            $out[] = ['task_id' => (string)$t['id'], 'index' => $idx,
                      'text' => (string)($it['text'] ?? ''),
                      'source' => (string)($t['source'] ?? 'owner'),
                      'reason' => (string)($t['reason'] ?? '')];
        }
    }
    return $out;
}

// The greeting an unlock opens with: the person's name, then the work
// already waiting for them (owner-assigned or Gol-originated alike —
// including anything assigned before their first ever unlock). On her
// FIRST unlock the greeting also names her role and her standing
// duties, briefly — the persona she was born with (owner, Oct 28).
function golpos_store_tz(): DateTimeZone {
    $tz = 'America/New_York';
    try {
        $set = trim((string)(golpos_settings()['timezone'] ?? ''));
        if ($set !== '') { $tz = $set; }
    } catch (Throwable $e) {}
    try { return new DateTimeZone($tz); } catch (Throwable $e) { return new DateTimeZone('America/New_York'); }
}
function golpos_greet_word(): string {
    $h = (int)(new DateTimeImmutable('now', golpos_store_tz()))->format('G');
    if ($h < 12) { return 'Good morning'; }
    if ($h < 17) { return 'Good afternoon'; }
    return 'Good evening';
}
function golpos_unlock_greeting(array $actor, bool $firstUnlock = false, ?string $prevUnlockAt = null): string {
    $greet = golpos_greet_word();
    if (($actor['role'] ?? '') === 'employee') {
        $email = (string)$actor['email'];
        $lines = [$greet . ', ' . $actor['name'] . ' — the register is open in your name.'];
        $pres = ($actor['record'] ?? null) !== null ? golpos_presence_for($actor['record']) : null;
        $pack = $pres !== null ? array_values(array_map('strval', (array)($pres['job_pack']['items'] ?? []))) : [];
        $packHas = static function (string $duty) use ($pack): bool {
            foreach ($pack as $d) {
                if (golpos_bill_name_key($d) === golpos_bill_name_key($duty)) { return true; }
            }
            return false;
        };
        if ($firstUnlock && $pack) {
            $show = array_slice($pack, 0, 4);
            $lines[] = 'Your role: ' . ucfirst((string)($pres['role'] ?? 'cashier'))
                . ' — your duties: ' . implode(' · ', $show)
                . (count($pack) > 4 ? ' · +' . (count($pack) - 4) . ' more' : '');
        }
        // (1) SINCE SHE WAS GONE — the delta since her last unlock,
        // composed only from records: tasks added for her (owner or
        // Gol), her notes still waiting on the boss, and What's Out
        // items still open under a duty her checklist carries. A
        // re-unlock minutes later finds an empty delta and says so.
        if ($prevUnlockAt !== null && !$firstUnlock) {
            $prev = strtotime($prevUnlockAt);
            $delta = [];
            foreach (golpos_tasks_for($email) as $t) {
                if (strtotime((string)($t['created'] ?? '')) <= $prev) { continue; }
                if (($t['status'] ?? '') === 'superseded') { continue; }
                if (($t['employee_email'] ?? null) !== $email) { continue; }
                foreach ((array)($t['items'] ?? []) as $it) {
                    if (empty($it['done'])) {
                        $delta[] = 'new task: ' . (string)($it['text'] ?? '')
                            . (($t['source'] ?? '') === 'gol' ? ' (from Gol)' : ' (from the boss)');
                    }
                }
            }
            $unread = 0;
            foreach (golpos_team_notes() as $n) {
                if (($n['from_email'] ?? '') === $email && empty($n['read'])) { $unread++; }
            }
            if ($unread > 0) { $delta[] = $unread . ' of your note(s) still waiting for the boss to read'; }
            if ($packHas("What's Out report garne")) {
                $openOuts = golpos_whatsout_open();
                if ($openOuts) { $delta[] = count($openOuts) . " What's Out item(s) still open"; }
            }
            $lines[] = $delta ? 'Since you were gone:' : 'Nothing new since you were last here.';
            foreach (array_slice($delta, 0, 4) as $d) { $lines[] = '• ' . $d; }
        }
        // (2) TODAY'S SHAPE — what the day holds for HER checklist:
        // deliveries/reps due today, her closing duty moment, counts
        // still waiting on her.
        $shape = [];
        if ($packHas('delivery lina') || $packHas('bill sanga saman check garne')) {
            foreach (golpos_vendors_due(date('Y-m-d')) as $v) {
                $shape[] = 'rep today: ' . (string)$v['name'];
            }
            $dow = strtolower(date('D'));
            foreach (golpos_vendors() as $v) {
                if (strtolower(substr((string)($v['delivery'] ?? ''), 0, 3)) === $dow) {
                    $shape[] = 'delivery expected today: ' . (string)$v['name'];
                }
            }
        }
        if ($packHas('day close garne')) {
            $shape[] = 'your pack carries the close — day close + report at closing time';
        }
        foreach (golpos_tasks_for($email) as $t) {
            if (($t['kind'] ?? '') !== 'whatsout_aging' || golpos_task_status($t) === 'done') { continue; }
            foreach ((array)($t['items'] ?? []) as $it) {
                if (empty($it['done'])) { $shape[] = 'count waiting: ' . (string)($it['text'] ?? ''); }
            }
        }
        if ($shape) {
            $lines[] = "Today's shape:";
            foreach (array_slice($shape, 0, 4) as $sh) { $lines[] = '• ' . $sh; }
        }
        // (2b) HER CALENDAR — the entries whose moment arrived while
        // she was away (marked missed) plus today's, fanned out to her
        // persona. This is the buzz at the till: shown once per
        // audience (marks), never dug for, never repeated.
        try {
            $calAll = golpos_cal_all_events(date('Y-m-d', strtotime('-7 days')),
                                            date('Y-m-d', strtotime('+7 days')));
            $hers = golpos_cal_for_persona($calAll, (string)$actor['name'],
                ($actor['record'] ?? null) !== null ? golpos_cal_duty_groups_for($actor['record']) : []);
            [$dueCal, $calMarks] = golpos_cal_due_for($hers, 'persona:' . golpos_name_key((string)$actor['name']));
            if ($dueCal) {
                golpos_cal_mark_shown($calMarks, $email);
                $lines[] = '📅 Calendar:';
                foreach (array_slice($dueCal, 0, 4) as $ce) { $lines[] = golpos_cal_line($ce); }
            }
        } catch (Throwable $e) {}
        // (3) Her task list, duty-shaped kinds first (report before
        // counts before the rest), then the owner's words in order.
        $items = golpos_open_items_for($email);
        if (!$items) {
            $lines[] = t('tasks_none');
        } else {
            $kindOf = [];
            foreach (golpos_tasks_for($email) as $t) {
                foreach ((array)($t['items'] ?? []) as $it) {
                    if (empty($it['done'])) {
                        $kindOf[golpos_bill_name_key((string)($it['text'] ?? ''))] = (string)($t['kind'] ?? 'manual');
                    }
                }
            }
            $rank = static function (array $it) use ($kindOf): int {
                $k = $kindOf[golpos_bill_name_key($it['text'])] ?? 'manual';
                return ['report_due' => 0, 'bill_waiting' => 1, 'whatsout_aging' => 2,
                        'manual' => 3, 'price_gap' => 4][$k] ?? 3;
            };
            usort($items, static fn($a, $b) => $rank($a) <=> $rank($b));
            $lines[] = 'Your tasks (' . count($items) . ' open):';
            foreach ($items as $i => $it) {
                $lines[] = ($i + 1) . '. ' . $it['text']
                    . ($it['source'] === 'gol' && $it['reason'] !== '' ? ' — from Gol: ' . $it['reason'] : '');
            }
        }
        // A price gap waiting for her is promised here, in one line —
        // the idle prompt later in her shift is then no surprise.
        $gapBrief = golpos_gap_brief_line($email);
        if ($gapBrief !== '') { $lines[] = $gapBrief; }
        return implode("\n", $lines);
    }
    $lines = [$greet . ' — admin mode, the register is open.'];
    $tl = golpos_team_line();
    $lines[] = $tl !== '' ? $tl : 'Team: no open tasks carried over, no unread notes.';
    return implode("\n", $lines);
}

// The presence read (owner, admin chat): ONE summary for one person,
// from the records the lane keeps — unlock, today's tasks, the latest
// note, standing open items, and the din-bhari trail line. Absent data
// is stated as absent; nothing is invented.
function golpos_presence_reply(array $emp): string {
    $email = (string)$emp['email'];
    $name = golpos_display_name($emp);
    $pres = golpos_presence_for($emp);
    $lines = [$name . ' — presence:'];
    $justAdded = empty($pres['first_unlock_at'])
        && strtotime((string)($pres['added_at'] ?? '')) >= time() - 86400;
    $lines[] = '• Role: ' . (string)($pres['role'] ?? 'employee')
        . ' · on the roster since ' . date('M j', strtotime((string)($pres['added_at'] ?? 'now')));
    $latest = golpos_unlock_latest_for($email);
    if ($latest !== null) {
        $lines[] = '• Last unlock: ' . date('M j, g:i A', strtotime((string)$latest['at']))
            . (!empty($latest['photo']) ? ' · photo stamp saved' : ' · no photo (no camera frame)');
    } else {
        $lines[] = '• No unlock recorded yet.';
    }
    $today = date('Y-m-d');
    $doneToday = 0; $openToday = 0; $standing = [];
    foreach (golpos_tasks_for($email) as $t) {
        $isToday = substr((string)($t['created'] ?? ''), 0, 10) === $today
            || substr((string)($t['due'] ?? ''), 0, 10) === $today;
        foreach ((array)($t['items'] ?? []) as $it) {
            if (!empty($it['done'])) {
                if (substr((string)($it['done_at'] ?? ''), 0, 10) === $today) { $doneToday++; }
            } else {
                // Standing open items carry their AGE (closure law,
                // Oct 28): a duty stays on the record, visibly ageing,
                // until evidence closes it.
                $ageH = max(0, (int)floor((time() - (strtotime((string)($t['created'] ?? 'now')) ?: time())) / 3600));
                $age = $ageH >= 48 ? intdiv($ageH, 24) . 'd' : $ageH . 'h';
                $standing[] = (string)($it['text'] ?? '') . ' (' . $age . ')';
                if ($isToday) { $openToday++; }
            }
        }
    }
    $lines[] = '• Today: ' . $doneToday . ' task item(s) done, ' . $openToday . ' open.';
    $latestNote = null;
    foreach (golpos_team_notes() as $n) {
        if (($n['from_email'] ?? '') === $email) { $latestNote = $n; }
    }
    $lines[] = $latestNote !== null
        ? '• Latest note: "' . (string)$latestNote['text'] . '" (' . date('M j, g:i A', strtotime((string)$latestNote['at'])) . ')'
        : '• No notes from them yet.';
    $lines[] = '• Standing open: ' . ($standing ? implode('; ', array_slice($standing, 0, 3)) : 'none');
    $trail = golpos_presence_trail($email, $today);
    $lines[] = '• Din bhari: ' . count($trail['done']) . ' done · ' . count($trail['notes']) . ' note(s) · '
        . count($trail['outs']) . ' out report(s) · ' . count($trail['counts']) . ' count(s) · '
        . count($trail['questions']) . ' question(s) asked of Gol.';
    if ($justAdded && !$standing && $latest === null && $latestNote === null) {
        $lines[] = 'Just added — no tasks yet, nothing filed yet.';
    }
    return implode("\n", $lines);
}

// Edits to a staged task_assign card, order-prep style: "dosro kam
// hata" strikes an item, "arko thap: <task>" adds one. Returns true
// when the message was an edit (and has been answered).
function golpos_task_card_apply_edits(array $card, string $msg): bool {
    $items = array_values(array_map('strval', (array)($card['items'] ?? [])));
    if (preg_match('/(hata|hatau|remove|strike|nikal|हटाउ|निकाल)/iu', $msg)) {
        $idx = null;
        $ord = golpos_ordinal_in($msg);
        if ($ord !== null && isset($items[$ord - 1])) { $idx = $ord - 1; }
        if ($idx === null) {
            $mk = golpos_name_key($msg);
            foreach ($items as $i => $txt) {
                $ik = golpos_name_key($txt);
                if ($ik !== '' && (mb_strpos($mk, $ik) !== false || mb_strpos($ik, $mk) !== false)) { $idx = $i; break; }
            }
        }
        if ($idx === null) { return false; }
        array_splice($items, $idx, 1);
        if (!$items) {
            golpos_clear_pending();
            out(['reply' => t('ask_cancelled'), 'cancelled' => true]);
        }
        $card['items'] = $items;
        $card = golpos_stage($card);
        out(['reply' => t('assign_edited', (string)$card['employee_name'], count($items)), 'card' => $card]);
    }
    if (preg_match('/(?:^|\b)(?:arko\s+)?(?:thap|add|jodo|thapnus|thapdeu)\s*[:\-]?\s*(.+)$/isu', $msg, $m)) {
        $txt = trim($m[1], " \t.!।");
        if ($txt === '') { return false; }
        $items[] = $txt;
        $card['items'] = $items;
        $card = golpos_stage($card);
        out(['reply' => t('assign_edited', (string)$card['employee_name'], count($items)), 'card' => $card]);
    }
    return false;
}

// --- persona helpers: the add-employee card's checklist editing ------------------
// A duty is "mentioned" when its distinctive words all appear in the
// message ("cooler bharnee hata" names the duty 'cooler bharnee'). The
// owner checks/unchecks in plain words; the card carries the FINAL
// checked set and the persona is born from exactly that set.
function golpos_duty_mentioned(array $msgTokens, string $duty): bool {
    // A duty is named when the owner speaks its distinctive words —
    // all of them for a short label, at least half (and never fewer
    // than two) for a long one: "cash ginti hata" names the duty
    // 'cash ginti (drawer)' without saying the parenthetical.
    $toks = array_values(array_filter(preg_split('/[^a-z0-9]+/u', golpos_bill_name_key($duty)) ?: [],
        static fn($w) => mb_strlen((string)$w) >= 4));
    if (!$toks) { return false; }
    $hit = 0;
    foreach ($toks as $tk) { if (in_array($tk, $msgTokens, true)) { $hit++; } }
    if (count($toks) <= 2) { return $hit === count($toks); }
    return $hit >= 2 && $hit >= (int)ceil(count($toks) / 2);
}

// Apply the check/uncheck words of a message to a duty set. Negation
// words near a mentioned duty uncheck it; "thap/add/pani" near one
// checks it (a bare mention in an add message changes nothing — the
// preset already decided).
function golpos_apply_duty_words(array $duties, string $msg, bool $editsOnly): array {
    $data = golpos_persona_data();
    $all = [];
    foreach ($data['checklist'] as $items) { $all = array_merge($all, array_map('strval', $items)); }
    $msgTokens = array_values(array_filter(preg_split('/[^a-z0-9]+/u', golpos_bill_name_key($msg)) ?: [],
        static fn($w) => $w !== ''));
    $neg = preg_match('/(hata|hatau|narakh|na\s+rakh|remove|exclude|binā|बिना|हटाउ)/iu', $msg) === 1;
    $add = preg_match('/(thap|thapnus|add|include|pani|पनि|थप)/iu', $msg) === 1;
    $keys = array_map('golpos_bill_name_key', $duties);
    foreach ($all as $duty) {
        if (!golpos_duty_mentioned($msgTokens, $duty)) { continue; }
        $k = golpos_bill_name_key($duty);
        if ($neg && in_array($k, $keys, true)) {
            $duties = array_values(array_filter($duties,
                static fn($d) => golpos_bill_name_key((string)$d) !== $k));
            $keys = array_map('golpos_bill_name_key', $duties);
        } elseif ($add && !in_array($k, $keys, true)) {
            $duties[] = $duty;
            $keys[] = $k;
        }
    }
    // Keep checklist order, whatever the edits did.
    $ordered = [];
    foreach ($all as $duty) {
        if (in_array(golpos_bill_name_key($duty), array_map('golpos_bill_name_key', $duties), true)) {
            $ordered[] = $duty;
        }
    }
    return $ordered;
}

function golpos_role_word_in(string $msg): ?string {
    if (preg_match('/\b(cashier|closer|stocker|manager)\b/iu', $msg, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

// Edits to a staged employee_add card: a role word re-presets the
// bundle, duty words check/uncheck on top. Re-stages and answers when
// the message was an edit; returns false when it wasn't one.
function golpos_emp_card_apply_edits(array $card, string $msg): bool {
    $changed = false;
    $role = golpos_role_word_in($msg);
    if ($role !== null && $role !== ($card['role'] ?? '')) {
        $card['role'] = $role;
        $card['duties'] = golpos_preset_duties($role);
        $changed = true;
    }
    $before = $card['duties'] ?? [];
    $after = golpos_apply_duty_words(is_array($before) ? $before : [], $msg, true);
    if ($after !== $before) { $card['duties'] = $after; $changed = true; }
    if (!$changed) { return false; }
    $card = golpos_stage($card);
    out(['reply' => 'Add-employee card updated — ' . golpos_card_summary($card)
        . ' Say "gol" to add + invite, or keep editing.', 'card' => $card]);
}

// --- refunds + customer credit (owner directive, Oct 28) ----------------------
// One refund, two doors: Transactions stages it from the sale row, this
// lane stages the SAME card from words ("hijo ko tyo sale refund
// gara"), and both execute through golpos_execute_refund. The lane
// finds the sale by the hints a person actually gives — the day, the
// item, the tender, the amount — and when more than one sale fits, it
// ASKS with the list and remembers it (refund_await): a guess is never
// refunded. Policy lives in the settings store and changes through the
// setting_change card like every other setting.

function golpos_refund_await_set(?array $await): void {
    $id = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($id === '') { return; }
    $s = golpos_session($id);
    $s['refund_await'] = $await;
    golpos_save_session($id, $s);
}

function golpos_refund_await_get(): ?array {
    $id = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($id === '') { return null; }
    $s = golpos_session($id);
    $a = $s['refund_await'] ?? null;
    return is_array($a) && !empty($a['ids']) ? $a : null;
}

// A sale can still take a refund when at least one line has units left.
function golpos_refund_sale_open(array $sale): bool {
    $prior = golpos_refunds_for_sale((string)($sale['id'] ?? ''));
    if (!$prior) { return true; }
    foreach ((array)($sale['lines'] ?? []) as $i => $l) {
        if (!is_array($l)) { continue; }
        [$q] = golpos_refunded_on_line($prior, golpos_sale_line_key($l, (int)$i));
        if ((int)($l['qty'] ?? 1) - $q > 0) { return true; }
    }
    return false;
}

function golpos_refund_policy_line(): string {
    $s = golpos_settings();
    return golpos_setting_label('refunds_allowed') . ': ' . golpos_onoff((bool)$s['refunds_allowed'])
        . ' · ' . golpos_setting_label('refund_pin') . ': ' . golpos_onoff((bool)$s['refund_pin'])
        . ' · ' . golpos_setting_label('refund_window_days') . ': '
        . golpos_setting_display('refund_window_days', $s['refund_window_days'])
        . ' · ' . golpos_setting_label('credit_redeem') . ': ' . golpos_onoff((bool)$s['credit_redeem']);
}

// Stage the refund card for one sale (full remaining — line-level
// picks live on the Transactions sheet, where the lines are visible).
function golpos_stage_refund(array $sale): void {
    $q = golpos_refund_quote($sale, null);
    if (isset($q['error'])) { out(['reply' => $q['error']]); }
    $names = [];
    foreach ($q['lines'] as $l) { $names[] = (string)$l['name'] . ' ×' . (int)$l['qty']; }
    $back = [];
    foreach (['cash' => 'cash', 'card' => 'card', 'credit' => 'store credit'] as $k => $lbl) {
        if ((float)($q['tenders'][$k] ?? 0) > 0) { $back[] = $lbl; }
    }
    $card = golpos_stage(['type' => 'refund', 'sale_id' => (string)($sale['id'] ?? ''),
        'sale_when' => date('g:i A, M j', strtotime((string)($sale['at'] ?? '')) ?: time()),
        'picks' => null, 'total' => $q['total'],
        'back_to' => implode(' + ', $back), 'lines_label' => implode(', ', $names)]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}

// Consume a pick from the waiting candidate list ("dosro", "2", or a
// distinguishing item name). Returns true when the message WAS a pick
// (the staged card has already answered); false lets dispatch run on.
function golpos_refund_await_pick(string $msg, array $products): bool {
    $await = golpos_refund_await_get();
    if ($await === null) { return false; }
    $ids = array_map('strval', (array)$await['ids']);
    $pick = null;
    $ord = golpos_ordinal_in($msg);
    if ($ord !== null && isset($ids[$ord - 1])) { $pick = $ids[$ord - 1]; }
    if ($pick === null && preg_match('/^\s*(\d{1,2})\s*[.!]?\s*$/', golpos_norm_digits(trim($msg)), $mm)) {
        if (isset($ids[(int)$mm[1] - 1])) { $pick = $ids[(int)$mm[1] - 1]; }
    }
    if ($pick === null) {
        $r = resolve_item($products, $msg);
        if (!empty($r['one'])) {
            foreach ($ids as $id) {
                $s = golpos_sale_by_id($id);
                if ($s === null) { continue; }
                foreach ((array)($s['lines'] ?? []) as $l) {
                    if (is_array($l) && (string)($l['sku'] ?? '') === (string)$r['one']['sku']) {
                        $pick = $id; break 2;
                    }
                }
            }
        }
    }
    if ($pick === null) { return false; }
    golpos_refund_await_set(null);
    $s = golpos_sale_by_id($pick);
    if ($s === null) { return false; }
    golpos_stage_refund($s);
    return true; // unreachable — staging answers
}

function golpos_refund_lane(string $msg, array $products): void {
    $st = golpos_settings();
    $off = golpos_wants_off($msg);
    // Policy sub-lane: the refund settings read and change here, ahead
    // of any sale finding — "refund pin off gara" is a settings card,
    // never a sale search.
    if (preg_match('/\bpin\b/iu', $msg) && $off !== null) {
        golpos_stage_setting('refund_pin', !$off);
    }
    if (preg_match('/(window|samaya|अवधि)/iu', $msg)) {
        if (preg_match('/(\d{1,3})/', golpos_norm_digits($msg), $mm)
            && preg_match('/(gara|rakha|rakh|set|change|make|गर|राख)/iu', $msg)) {
            $days = (int)$mm[1];
            if ($days > 365) { out(['reply' => 'The refund window runs 0–365 days — that number is outside it, nothing staged.']); }
            golpos_stage_setting('refund_window_days', $days);
        }
        out(['reply' => golpos_refund_policy_line()]);
    }
    if (preg_match('/(setting|policy|allowed)/iu', $msg)
        || ($off !== null && !preg_match('/\bpin\b/iu', $msg))
        || (preg_match('/\b(pin|window)\b/iu', $msg)
            && preg_match('/(kati|how|what|ke|कति)/iu', $msg))) {
        // An on/off word aimed at refunds (and not at the PIN, carved
        // out above) IS the switch ask — "refunds off gara" stages the
        // refunds_allowed card; no one has to say the word "allowed".
        if ($off !== null && !preg_match('/\bpin\b/iu', $msg)) {
            golpos_stage_setting('refunds_allowed', !$off);
        }
        out(['reply' => golpos_refund_policy_line()]);
    }
    if (empty($st['refunds_allowed'])) { out(['reply' => t('refund_off')]); }
    // Find the sale: window-eligible, still refundable, newest first —
    // then the person's own hints (day, amount, tender, item) narrow it.
    $window = (int)($st['refund_window_days'] ?? 30);
    $todayStart = strtotime(date('Y-m-d'));
    $list = [];
    foreach (array_reverse(golpos_sales()) as $s) {
        $age = ($todayStart - strtotime(substr((string)($s['at'] ?? ''), 0, 10))) / 86400;
        if ($age > $window || !golpos_refund_sale_open($s)) { continue; }
        $list[] = $s;
        if (count($list) >= 100) { break; }
    }
    if (preg_match('/(hijo|yesterday|हिजो)/iu', $msg)) {
        $y = date('Y-m-d', strtotime('-1 day'));
        $list = array_values(array_filter($list,
            static fn($s) => substr((string)($s['at'] ?? ''), 0, 10) === $y));
    }
    $amt = null;
    if (preg_match('/\$\s*(\d+(?:\.\d{1,2})?)/u', $msg, $m)) { $amt = (float)$m[1]; }
    elseif (preg_match('/(\d+(?:\.\d{1,2})?)\s*(?:\$|dollar)/iu', $msg, $m)) { $amt = (float)$m[1]; }
    if ($amt !== null) {
        $list = array_values(array_filter($list,
            static fn($s) => abs((float)($s['total'] ?? 0) - $amt) < 0.005));
    }
    if (preg_match('/\bcard\b/iu', $msg)) {
        $list = array_values(array_filter($list,
            static fn($s) => golpos_sale_tenders($s)['card'] > 0));
    }
    if (preg_match('/\bcash\b/iu', $msg)) {
        $list = array_values(array_filter($list,
            static fn($s) => golpos_sale_tenders($s)['cash'] > 0));
    }
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $sku = (string)$r['one']['sku'];
        $list = array_values(array_filter($list, static function ($s) use ($sku) {
            foreach ((array)($s['lines'] ?? []) as $l) {
                if (is_array($l) && (string)($l['sku'] ?? '') === $sku) { return true; }
            }
            return false;
        }));
    }
    if (!$list) { out(['reply' => t('refund_none')]); }
    $latest = preg_match('/(last|bharkhar|just|recent|ahile|भर्खर|अहिले)/iu', $msg) === 1;
    if (count($list) === 1 || $latest) { golpos_stage_refund($list[0]); }
    // Ambiguous: name the candidates and remember the list — the pick
    // ("dosro", an item name) stages from the await, never a guess.
    $show = array_slice($list, 0, 5);
    golpos_refund_await_set(['ids' => array_map(static fn($s) => (string)($s['id'] ?? ''), $show),
                             'at' => date('c')]);
    $lines = [t('refund_which')];
    foreach ($show as $i => $s) {
        $names = [];
        foreach ((array)($s['lines'] ?? []) as $l) {
            if (is_array($l)) { $names[] = (string)($l['name'] ?? '') . ' ×' . (int)($l['qty'] ?? 1); }
        }
        $lines[] = ($i + 1) . '. ' . date('g:i A, M j', strtotime((string)($s['at'] ?? '')) ?: time())
            . ' · ' . implode(', ', $names) . ' — ' . money((float)($s['total'] ?? 0))
            . ' (' . (string)($s['tender'] ?? 'cash') . ')';
    }
    out(['reply' => implode("\n", $lines)]);
}

// The credit lane: balance reads and the credit_redeem toggle. The
// spend itself happens ONLY at the till tender (the Credit button) —
// chat reads the store's records and says whose balance it is. Reads
// go through the facade, so in link mode they read MR.STORE's ONE
// customer record (Customer Identity Lane, Oct 28) — the balance the
// website earned is the balance this lane reports.

// Parse the employee chat door: "Ram le redeem garna khojchha" /
// "Ram wants to redeem his points" — a customer AT the counter asking
// to spend. Returns the customer's name as spoken, or null.
function golpos_parse_credit_attach(string $msg): ?string {
    $m = null;
    if (preg_match('/^(.+?)\s+le\s+redeem\s+garna\s+khoj/iu', $msg, $mm)) { $m = $mm[1]; }
    elseif (preg_match('/^(.+?)\s+wants\s+to\s+redeem/iu', $msg, $mm)) { $m = $mm[1]; }
    elseif (preg_match('/^redeem\s+(?:for\s+)?(.+?)\s*$/iu', $msg, $mm)) { $m = $mm[1]; }
    if ($m === null) { return null; }
    $name = trim((string)preg_replace('/^(customer|the)\s+/iu', '', trim($m)));
    $name = trim((string)preg_replace('/\s+(ko|ka|ki)\s*$/iu', '', $name));
    return $name !== '' ? $name : null;
}

// Stage the attach card for one chosen account. The card carries the
// balance read at staging; execution re-reads the account and hands
// the client an action — the SPEND still happens at the tender through
// api.php's sale path, the single redeem write path (idempotent per
// sale id). Chat never writes the ledger itself.
function golpos_stage_credit_attach(array $acct): void {
    if (empty(golpos_settings()['credit_redeem'])) {
        out(['reply' => t('credit_attach_off')]);
    }
    if ((float)$acct['balance'] <= 0) {
        out(['reply' => t('credit_attach_gone', $acct['name'] !== '' ? $acct['name'] : $acct['phone'])]);
    }
    $card = golpos_stage(['type' => 'credit_attach', 'phone' => (string)$acct['phone'],
        'name' => (string)$acct['name'], 'balance' => (float)$acct['balance']]);
    out(['reply' => t('credit_attach_staged',
        $acct['name'] !== '' ? $acct['name'] : $acct['phone'], money((float)$acct['balance'])),
        'card' => $card]);
}

function golpos_credit_lane(string $msg): void {
    $off = golpos_wants_off($msg);
    if (preg_match('/(redeem|spend|chalaune|चलाउने)/iu', $msg) && $off !== null) {
        golpos_stage_setting('credit_redeem', !$off);
    }
    $digits = preg_replace('/\D+/', '', golpos_norm_digits($msg)) ?? '';
    // The chat door first: a named customer wants to redeem. With a
    // number in the same breath, the number IS the pick; with a name
    // alone, one match stages the card and a collision ASKS — money
    // never auto-picks.
    $attachName = golpos_parse_credit_attach($msg);
    if ($attachName !== null) {
        if (strlen($digits) >= 7) {
            $acct = golpos_credit_account($digits);
            if ($acct === null) { out(['reply' => t('credit_none')]); }
            golpos_stage_credit_attach($acct);
        }
        $hits = golpos_credit_find_by_name($attachName);
        if (!$hits) { out(['reply' => t('credit_attach_none', $attachName)]); }
        if (count($hits) > 1) {
            $list = implode('; ', array_map(static fn($h) =>
                ($h['name'] !== '' ? $h['name'] : $h['phone']) . ' (…' . $h['phone_tail'] . ') '
                . money((float)$h['balance']), $hits));
            out(['reply' => t('credit_attach_many', $attachName) . $list]);
        }
        golpos_stage_credit_attach($hits[0]);
    }
    if (strlen($digits) >= 7) {
        $acct = golpos_credit_account($digits);
        if ($acct === null) { out(['reply' => t('credit_none')]); }
        out(['reply' => t('credit_balance',
            $acct['name'] !== '' ? $acct['name'] : $acct['phone'], money((float)$acct['balance']))]);
    }
    // Name-first balance read: "Ram ko credit kati chha?" — strip the
    // ask's filler words and search the record by what remains.
    $q = trim((string)preg_replace(
        '/\b(credit|points?|kati|how much|balance|check|chha|cha|ko|ka|ki|mero|his|her|their)\b|(क्रेडिट|पोइन्ट|कति|छ)/iu',
        ' ', $msg));
    $q = trim((string)preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $q));
    $q = trim((string)preg_replace('/\s+/', ' ', $q));
    if (mb_strlen($q) >= 2) {
        $hits = golpos_credit_find_by_name($q);
        if (count($hits) === 1) {
            $h = $hits[0];
            out(['reply' => t('credit_balance',
                ($h['name'] !== '' ? $h['name'] : $h['phone']) . ' (…' . $h['phone_tail'] . ')',
                money((float)$h['balance']))]);
        }
        if (count($hits) > 1) {
            $list = implode('; ', array_map(static fn($h) =>
                ($h['name'] !== '' ? $h['name'] : $h['phone']) . ' (…' . $h['phone_tail'] . ') '
                . money((float)$h['balance']), $hits));
            out(['reply' => t('credit_attach_many', $q) . $list]);
        }
    }
    out(['reply' => t('credit_ask_phone')]);
}

// --- the dispatch -------------------------------------------------------------
// Every intent below runs through this one function so the access layer (the
// boot code at the bottom of this file) can replay the owner's original
// message verbatim after a PIN elevation: the PIN turn then answers the
// question that was asked before it, in the same reply. The confirm check
// runs BEFORE the empty-message check — the register's Confirm button posts
// {confirm:true} with no message text, and it must execute the card, not be
// told to "type something".
function golpos_dispatch(string $msg, array $in, array $catalog, array $products, string $shop): void {
    global $L;
    $L = golpos_lang($msg);

// --- a decline word over a pay-approve card is a DECISION -------------------
// "nahune / hudaina / decline" with the owner's pay card staged closes
// the request DECLINED — it is not the generic cancel (which only
// clears the card and leaves the request waiting). Checked before the
// cancel block because the NP refusal words live in both matchers.
$pendDecline = golpos_pending();
if ($pendDecline !== null && ($pendDecline['type'] ?? '') === 'pay_approve'
    && golpos_is_decline_intent($msg)) {
    $req = null;
    foreach (golpos_pay_asks() as $r) {
        if ((string)($r['id'] ?? '') === (string)($pendDecline['request_id'] ?? '')) { $req = $r; }
    }
    golpos_clear_pending();
    if ($req === null || ($req['status'] ?? '') !== 'pending') {
        out(['reply' => t('pay_already_decided')]);
    }
    $req['status'] = 'declined';
    $req['decided_at'] = date('c');
    $req['decided_by'] = 'Owner';
    $req['seen'] = false;
    golpos_pay_ask_save($req);
    out(['reply' => t('pay_declined_done', (string)$req['employee_name'],
                      golpos_pay_headline((array)$req['segments'], (float)$req['amount'])
                      . ' · ' . golpos_pay_period_label((string)$req['period_from'], (string)$req['period_to'])),
         'done' => $pendDecline]);
}

// --- a staged card + a cancel word: cleared here, deterministically --------
// The word alone, with a card waiting, is the whole instruction — it must
// never fall through to the composer (hisab test R08b: "cancel" after a
// staged price card got "I can't cancel that here" and the card stayed
// armed). One truth, one line, before every lane.
if (golpos_is_cancel_intent($msg) && golpos_pending() !== null) {
    $pendCx = golpos_pending();
    golpos_clear_pending();
    out(['reply' => t('card_cancelled'), 'cancelled' => true, 'cancelled_pid' => (string)($pendCx['id'] ?? '')]);
}

// --- the legacy confirm word: teach, never execute (Books parity) ----------
// "ho" against a staged card: the Books Gol teaches the migration and
// re-shows the SAME card (Oct 25 law); the POS executed nothing on "ho"
// (correct) but taught nothing (gap closed Oct 31). The card stays
// armed for the owner's "gol". Checked before the confirm block; with
// no card waiting, "ho" is an ordinary turn and flows on untouched.
$pendHo = golpos_pending();
if ($pendHo !== null && golpos_is_legacy_confirm($msg)) {
    out(['reply' => t('ho_teach') . "\n" . golpos_card_summary($pendHo), 'card' => $pendHo]);
}

// --- the confirm word --------------------------------------------------------
if (in_array(mb_strtolower($msg), ['gol', 'गोल'], true) || !empty($in['confirm'])) {
    if (golpos_echo_confirm($msg, $in)) { out(['reply' => 'That was my own voice. The card is still waiting. Tap Confirm after I am quiet.']); }

    $card = golpos_pending();
    if (!$card) { out(['reply' => t('no_pending')]); }
    /* a Confirm tap carrying a DIFFERENT card's id is stale
       (audit GOL-04): the live card keeps waiting, nothing runs. */
    if (!empty($in['confirm']) && !empty($in['pending_pid'])
        && (string)($in['pending_pid']) !== (string)($card['id'] ?? '')) {
        out(['reply' => t('card_not_live'), 'stale' => 'card_not_live', 'cancelled_pid' => (string)$in['pending_pid']]);
    }
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
    // A price-gap fill (price-gap engine, Oct 28): the value she read
    // off the shelf tag lands through the price lane's own write path —
    // edit the products, save the catalog (the link sync carries it to
    // MR.STORE). The gap TASK closes later, by evidence, when a scan
    // sees the price on the record — never by this reply's say-so.
    if ($card['type'] === 'price_fill') {
        foreach ($products as &$p) {
            if ($p['sku'] === $card['sku']) { $p['price'] = $card['new_price']; }
        }
        unset($p);
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => ($card['name'] ?? '') . ' ko shelf price ' . money((float)$card['new_price'])
                      . ' bhayo' . (!empty($card['by_name']) ? ' — ' . $card['by_name'] . ' le hale, dhanyabad!' : '.'),
             'done' => $card]);
    }
    // A cost-gap fill: the cost lands through the engine's one write
    // door (pricegap.php — local catalog standalone, the MR.STORE
    // record in link mode). A refused write is reported as a refusal
    // and the gap stays open; the card is consumed either way.
    if ($card['type'] === 'cost_fill') {
        $okFill = golpos_gap_write_cost($card);
        golpos_clear_pending();
        if (!$okFill) {
            out(['reply' => ($card['name'] ?? '') . ' ko cost record ma halna sakiyena — gap khula nai chha, owner le dekhcha.']);
        }
        out(['reply' => ($card['name'] ?? '') . ' ko cost ' . money((float)$card['new_cost'])
                      . ' record bhayo' . (!empty($card['by_name']) ? ' — ' . $card['by_name'] . ' le hale, dhanyabad!' : '.'),
             'done' => $card]);
    }
    if ($card['type'] === 'new_item') {
        // A card carrying a barcode executes only if the code is still
        // free — the same refusal the Add Product form gives, naming the
        // product that holds it, in case the code was taken after staging.
        if (!empty($card['barcode'])) {
            foreach ($products as $p) {
                if ((string)($p['barcode'] ?? '') === (string)$card['barcode']
                    || (string)($p['sku'] ?? '') === (string)$card['barcode']) {
                    golpos_clear_pending();
                    out(['reply' => t('barcode_taken', (string)($p['name'] ?? 'another product'))]);
                }
            }
        }
        $sku = (string)(1000 + count($products) + 1);
        $products[] = ['sku' => $sku, 'barcode' => $card['barcode'] ?? '', 'name' => $card['name'],
                       'category' => $card['category'] ?? 'Counter', 'price' => $card['price']];
        $catalog['products'] = $products;
        golpos_save_catalog($catalog);
        golpos_clear_pending();
        out(['reply' => t('item_added', $card['name'], money((float)$card['price'])), 'done' => $card]);
    }
    // A poster card publishes only on "gol" — into the demo store's feed
    // (fictional Sunrise Mart content only; never a real store, never live).
    if ($card['type'] === 'poster') {
        $feed = golpos_read('feed.json', []);
        $feed[] = ['id' => 'feed_' . substr(sha1(uniqid('', true)), 0, 8),
                   'kind' => 'poster', 'image' => $card['image'], 'caption' => $card['caption'],
                   'name' => $card['name'], 'price' => $card['price'], 'at' => date('c')];
        golpos_write('feed.json', $feed);
        golpos_clear_pending();
        out(['reply' => t('poster_done', $shop, $card['name'], money((float)$card['price'])), 'done' => $card]);
    }
    // A photo card: a matched catalog item goes into the cart; an unmatched
    // read is only logged as a photo — SAM never silently invents an item.
    if ($card['type'] === 'photo_read') {
        $photos = golpos_read('photos.json', []);
        $photos[] = ['image' => $card['image'], 'prompt' => $card['prompt'],
                     'regions' => $card['regions'], 'match_sku' => $card['match_sku'] ?? null, 'at' => date('c')];
        golpos_write('photos.json', $photos);
        golpos_clear_pending();
        if (!empty($card['match_sku'])) {
            out(['reply' => t('photo_added', $card['match_name'], money((float)$card['match_price'])),
                 'action' => ['type' => 'add', 'sku' => $card['match_sku']], 'done' => $card]);
        }
        out(['reply' => t('photo_saved', (int)($card['regions'] ?? 0), $card['prompt']), 'done' => $card]);
    }
    // A print-settings card: apply the one change (plus any paired change,
    // e.g. setting a phone number also shows the phone line) to the ONE
    // settings store the owner panel also writes.
    if ($card['type'] === 'setting_change') {
        $settings = golpos_settings();
        $settings[$card['setting']] = $card['new'];
        foreach (($card['also'] ?? []) as $k => $v) { $settings[$k] = $v; }
        golpos_save_settings($settings);
        golpos_clear_pending();
        out(['reply' => t('setting_done', golpos_setting_label($card['setting']),
                          golpos_setting_display($card['setting'], $card['new'])), 'done' => $card]);
    }
    // A batch-close card: seal exactly the staged segment — re-checked
    // against the still-unbatched sales, so a segment can never seal twice
    // (an auto close or a second confirm finds nothing left of it).
    if ($card['type'] === 'batch_close') {
        $ids = array_map('strval', $card['sale_ids'] ?? []);
        $seg = array_values(array_filter(golpos_unbatched_card_sales(),
            static fn($s) => in_array((string)($s['id'] ?? ''), $ids, true)));
        golpos_clear_pending();
        if (!$seg) { out(['reply' => t('batch_none')]); }
        $b = golpos_seal_batch($seg, 'manual');
        out(['reply' => t('batch_done', $b['n'], $b['count'], money((float)$b['total'])), 'done' => $card]);
    }
    // A refund card: ONE execution path with the Transactions sheet
    // (golpos_execute_refund) — the quote is recomputed at execution,
    // so a card can never spend stale state, refund a line twice, or
    // slip past the window. The gate holds this confirm to the admin
    // PIN exactly when the store's refund_pin setting says so.
    if ($card['type'] === 'refund') {
        $target = golpos_sale_by_id((string)($card['sale_id'] ?? ''));
        golpos_clear_pending();
        if ($target === null) { out(['reply' => t('card_fail')]); }
        $sidNow = (string)($GLOBALS['golpos_session_id'] ?? '');
        $sessNow = $sidNow !== '' ? golpos_session($sidNow) : [];
        $byName = (string)($sessNow['unlocked_name'] ?? '');
        $byEmail = (!empty($sessNow['unlocked_by']) && $sessNow['unlocked_by'] !== 'admin')
            ? (string)$sessNow['unlocked_by'] : '';
        $res = golpos_execute_refund($target, $card['picks'] ?? null, $byName, $byEmail);
        if (isset($res['error'])) { out(['reply' => $res['error']]); }
        $rec = $res['refund'];
        $parts = [];
        foreach (['cash' => 'cash', 'card' => 'card', 'credit' => 'store credit'] as $k => $lbl) {
            if ((float)($rec['tenders'][$k] ?? 0) > 0) {
                $parts[] = money((float)$rec['tenders'][$k]) . ' to ' . $lbl;
            }
        }
        $reply = t('refund_done', money((float)$rec['total']), implode(' + ', $parts) ?: 'the original tender');
        if (!empty($rec['credit_restored'])) {
            $reply .= t('refund_credit_back', money((float)$rec['credit_restored']));
        }
        out(['reply' => $reply, 'done' => $card]);
    }
    // A credit-attach card (employee chat door): execution does NOT
    // write the ledger — it re-reads the account and hands the till an
    // action, and the credit rides the current sale into api.php's
    // tender, the ONE redeem write path (keyed by the sale's id, so it
    // can never spend twice). The card type is deliberately absent
    // from golpos_needs_admin's list: attaching a customer's own credit
    // in her presence is cashier-domain work.
    if ($card['type'] === 'cal_entry') {
        /* the ONE write: the shared KV store (link) or the local
           pack (standalone) — the entry exists once, owned by the
           person or the store, exactly as the card showed */
        $rec = ['title' => (string)$card['title'], 'date' => (string)$card['date'],
                'time' => (string)($card['time'] ?? ''), 'type' => (string)($card['etype'] ?? 'other'),
                'note' => (string)$card['title'], 'owner' => (string)($card['for_name'] ?? 'store'),
                'duty_group' => (string)($card['duty_group'] ?? ''), 'item' => ''];
        if (($card['for_name'] ?? 'store') !== 'store') {
            $rec['who'] = [['id' => (string)($card['for_email'] ?? ''), 'name' => (string)$card['for_name']]];
        }
        $ok = golpos_cal_add_event($rec, (string)($card['by_email'] ?? ''));
        golpos_clear_pending();
        if (!$ok) { out(['reply' => t('card_fail')]); }
        $forTxt = ($card['for_name'] ?? 'store') !== 'store' ? ' (for ' . $card['for_name'] . ')' : '';
        out(['reply' => 'Calendar saved: ' . golpos_cal_fmt_day((string)$card['date']) . ' · '
            . $card['title'] . $forTxt . ' — Gol will show it on the day.', 'done' => $card]);
    }
    if ($card['type'] === 'note_add') {
        $ok = golpos_cal_add_note(['body' => (string)$card['body'], 'title' => '',
            'owner' => (string)($card['for_name'] ?? 'store'), 'item' => (string)($card['item'] ?? ''),
            'by' => (string)($card['by_email'] ?? '')], $shop, (string)($card['by_email'] ?? ''));
        golpos_clear_pending();
        if (!$ok) { out(['reply' => t('card_fail')]); }
        $tail = ($card['item'] ?? '') !== ''
            ? ' It will come back whenever ' . $card['item'] . ' is stocked, counted or asked about.'
            : '';
        out(['reply' => 'Note saved: ' . $card['body'] . $tail, 'done' => $card]);
    }
    if ($card['type'] === 'credit_attach') {
        golpos_clear_pending();
        if (empty(golpos_settings()['credit_redeem'])) {
            out(['reply' => t('credit_attach_off')]);
        }
        $acct = golpos_credit_account((string)($card['phone'] ?? ''));
        if ($acct === null || (float)$acct['balance'] <= 0) {
            out(['reply' => t('credit_attach_gone', (string)($card['name'] ?? ''))]);
        }
        out(['reply' => t('credit_attach_done',
                $acct['name'] !== '' ? $acct['name'] : $acct['phone'], money((float)$acct['balance'])),
             'action' => ['type' => 'credit', 'phone' => (string)$acct['phone'],
                          'name' => (string)$acct['name'], 'balance' => (float)$acct['balance']],
             'done' => $card]);
    }
    // An employee-remove card: the record leaves the people store.
    if ($card['type'] === 'employee_add') {
        // The persona card lands: the roster write, the presence birth
        // (inside golpos_save_employee — the pack copies the card's
        // final checked duties), and the invite, all on the owner's gol.
        $email = golpos_norm_email((string)($card['email'] ?? ''));
        golpos_clear_pending();
        if ($email === '' || golpos_employee($email) !== null) { out(['reply' => t('card_fail')]); }
        $token = bin2hex(random_bytes(16));
        golpos_save_employee(['name' => (string)($card['name'] ?? ''), 'email' => $email,
            'role' => (string)($card['role'] ?? 'cashier'),
            'duties' => array_values(array_map('strval', (array)($card['duties'] ?? []))),
            'status' => 'invited', 'pin_hash' => null,
            'invite_token_hash' => hash('sha256', $token), 'created' => date('c')]);
        $nm = (string)($card['name'] ?? '');
        $display = $nm !== '' ? "{$nm} ({$email})" : $email;
        if (golpos_send_invite($email, $nm, golpos_invite_link($token), $shop)) {
            out(['reply' => t('emp_added_sent', $display), 'done' => $card]);
        }
        out(['reply' => t('emp_added_link', $display, golpos_invite_link($token)), 'done' => $card]);
    }
    if ($card['type'] === 'employee_remove') {
        golpos_clear_pending();
        if (golpos_remove_employee((string)$card['email'])) {
            out(['reply' => t('emp_removed', (string)$card['email']), 'done' => $card]);
        }
        out(['reply' => t('emp_not_found', (string)$card['email'])]);
    }
    // A PIN-set card (TASKS 231): the hash was stashed in the session at
    // staging — the card itself never carries digits or a hash, and no
    // reply ever repeats them. The owner hands the PIN out himself.
    if ($card['type'] === 'pin_set') {
        golpos_clear_pending();
        $sid = (string)($GLOBALS['golpos_session_id'] ?? '');
        $ss = golpos_session($sid);
        $hash = (string)($ss['pin_set_hash'] ?? '');
        unset($ss['pin_set_hash']);
        golpos_save_session($sid, $ss);
        if ($hash === '') { out(['reply' => t('card_fail')]); }
        if (($card['target'] ?? '') === 'admin') {
            golpos_set_admin_pin($hash);
            out(['reply' => t('pin_set_done_admin'), 'done' => $card]);
        }
        $emp = golpos_employee((string)($card['email'] ?? ''));
        if ($emp === null) { out(['reply' => t('pin_set_gone', (string)($card['name'] ?? ''))]); }
        $emp['pin_hash'] = $hash;
        golpos_save_employee($emp);
        out(['reply' => t('pin_set_done_emp', golpos_display_name($emp)), 'done' => $card]);
    }
    // A stock-receive card (link mode): the goods physically arrived, the
    // owner confirmed — MR.STORE stock goes up by the received quantity.
    // A stock write is an admin execution: the gate's card-type list
    // includes stock_receive, so "gol" from an unelevated session demands
    // the PIN before this runs, exactly like a price change.
    if ($card['type'] === 'stock_receive') {
        $fmt = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        if (golpos_link_on()) {
            $res = mrstore_adjust_stock((string)$card['name'], (float)$card['qty']);
            golpos_clear_pending();
            if ($res === null) { out(['reply' => t('card_fail')]); }
            golpos_link_log(['kind' => 'receive', 'name' => $res['name'], 'qty' => (float)$card['qty'],
                             'before' => $res['before'], 'after' => $res['after'], 'via' => 'chat']);
            out(['reply' => t('stock_recv_done', $res['name'], $fmt($res['before']), $fmt($res['after'])), 'done' => $card]);
        }
        // Standalone: the same card lands on the register's OWN shelf —
        // the one receive path (golpos_receive_stock), so a spoken
        // delivery and a tile receive compose on one number.
        $res = golpos_receive_stock((string)($card['sku'] ?? ''), (int)$card['qty']);
        golpos_clear_pending();
        if ($res === null) { out(['reply' => t('card_fail')]); }
        out(['reply' => t('stock_recv_done_local', $res['name'], $fmt($res['before']), $fmt($res['after'])), 'done' => $card]);
    }
    // A pay-send card (her confirm): the request RECORD is the write —
    // one pending request per employee, enforced here as well as at
    // staging, so a double confirm can never mint a second request.
    if ($card['type'] === 'pay_send') {
        golpos_clear_pending();
        $existing = golpos_pay_pending_for((string)($card['employee_email'] ?? ''));
        if ($existing !== null) {
            out(['reply' => t('pay_pending', money((float)$existing['amount']),
                              golpos_pay_period_label((string)$existing['period_from'], (string)$existing['period_to'])),
                 'done' => $card]);
        }
        $rec = golpos_pay_ask_add([
            'employee_email' => (string)$card['employee_email'],
            'employee_name' => (string)$card['employee_name'],
            'period_from' => (string)$card['period_from'],
            'period_to' => (string)$card['period_to'],
            'lines' => (array)($card['lines'] ?? []),
            'segments' => (array)($card['segments'] ?? []),
            'hours' => (float)($card['hours'] ?? 0),
            'amount' => (float)($card['amount'] ?? 0),
            'corrections' => array_values(array_map('strval', (array)($card['corrections'] ?? []))),
            'unended' => (array)($card['unended'] ?? []),
            'covered_until' => $card['covered_until'] ?? null,
            'status' => 'pending', 'created' => date('c'),
            'decided_at' => null, 'decided_by' => null, 'booking_ref' => null, 'seen' => true,
        ]);
        out(['reply' => t('pay_sent', (string)$rec['employee_name'],
                          golpos_pay_headline($rec['segments'], (float)$rec['amount'])
                          . ' · ' . golpos_pay_period_label($rec['period_from'], $rec['period_to'])),
             'done' => $card]);
    }
    // A pay-approve card (the owner's confirm): approval books the pay
    // ONCE — the request record itself is the register's pay ledger
    // entry, and its id is the booking reference. A request already
    // decided cannot be paid twice: the status check is the lock.
    if ($card['type'] === 'pay_approve') {
        $req = null;
        foreach (golpos_pay_asks() as $r) {
            if ((string)($r['id'] ?? '') === (string)($card['request_id'] ?? '')) { $req = $r; }
        }
        golpos_clear_pending();
        if ($req === null || ($req['status'] ?? '') !== 'pending') {
            out(['reply' => t('pay_already_decided')]);
        }
        $req['status'] = 'paid';
        $req['decided_at'] = date('c');
        $req['decided_by'] = 'Owner';
        $req['booking_ref'] = (string)$req['id'];
        $req['seen'] = false;
        golpos_pay_ask_save($req);
        out(['reply' => t('pay_approved', (string)$req['booking_ref'],
                          (string)$req['employee_name'],
                          golpos_pay_headline($req['segments'], (float)$req['amount'])
                          . ' · ' . golpos_pay_period_label($req['period_from'], $req['period_to'])),
             'done' => $card]);
    }
    // A rate-change card: the new rate lands on the roster record
    // effective TODAY, with its history — past periods keep the rates
    // they were worked at (golpos_set_rate, store.php).
    if ($card['type'] === 'rate_change') {
        $emp = golpos_employee((string)($card['employee_email'] ?? ''));
        golpos_clear_pending();
        if ($emp === null) { out(['reply' => t('card_fail')]); }
        golpos_set_rate($emp, (float)$card['new_rate'], date('Y-m-d'));
        $oldTxt = ($card['old_rate'] ?? null) === null ? 'no rate on record' : money((float)$card['old_rate']);
        out(['reply' => (string)($card['employee_name'] ?? '') . "'s rate: " . $oldTxt
                      . ' → ' . money((float)$card['new_rate']) . ' — from today ('
                      . date('M j', strtotime((string)($card['effective'] ?? 'now'))) . ').',
             'done' => $card]);
    }
    // A task assignment (employee lane): the record lands in the task
    // store AND the numbered list is emailed to the employee's address
    // on file — one executor (store.php) so the reply can state both
    // halves truthfully.
    if ($card['type'] === 'task_assign') {
        $res = golpos_execute_task_assign($card, $shop);
        golpos_clear_pending();
        $list = implode('; ', array_map(static fn($it) => (string)($it['text'] ?? ''), $res['task']['items'] ?? []));
        if ($res['sent']) {
            out(['reply' => t('assign_done_sent', (string)$card['employee_name'],
                              (string)$card['employee_email'], $list), 'done' => $card]);
        }
        out(['reply' => t('assign_done_mailfail', (string)$card['employee_name'],
                          (string)$card['employee_email']), 'done' => $card]);
    }
    // A team note: the employee's words land in the owner's inbox.
    if ($card['type'] === 'team_note') {
        golpos_team_note_add(['from_email' => (string)($card['from_email'] ?? ''),
                              'from_name' => (string)($card['from_name'] ?? ''),
                              'text' => (string)($card['text'] ?? '')]);
        golpos_clear_pending();
        out(['reply' => t('note_done', (string)$card['text']), 'done' => $card]);
    }
    // A What's Out report: a signal with a name and a time — it lands
    // on the store's What's Out record (standalone file / the MR.STORE
    // Floor bridge) and NEVER edits a stock count.
    if ($card['type'] === 'whats_out') {
        $ok = false;
        if (golpos_link_on()) {
            $ok = mrstore_append_whats_out((string)$card['name'],
                    (string)($card['by_name'] ?? ''), (string)($card['by_email'] ?? ''));
        } else {
            golpos_whatsout_add(['name' => (string)$card['name'], 'sku' => (string)($card['sku'] ?? ''),
                                 'by_name' => (string)($card['by_name'] ?? ''),
                                 'by_email' => (string)($card['by_email'] ?? '')]);
            $ok = true;
        }
        golpos_clear_pending();
        if (!$ok) { out(['reply' => t('card_fail')]); }
        out(['reply' => t('whatsout_done', (string)$card['name'], (string)($card['by_name'] ?? '')), 'done' => $card]);
    }
    // A stock count report: the count EVENT is filed with its variance;
    // the shelf stock itself is never edited by a count.
    if ($card['type'] === 'stock_count') {
        golpos_count_add(['name' => (string)$card['name'], 'sku' => (string)($card['sku'] ?? ''),
                          'counted' => $card['counted'] ?? null, 'system' => $card['system'] ?? null,
                          'variance' => $card['variance'] ?? null,
                          'by_name' => (string)($card['by_name'] ?? ''),
                          'by_email' => (string)($card['by_email'] ?? '')]);
        golpos_clear_pending();
        $fmt = static fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        $var = ($card['variance'] ?? null) === null ? '—'
            : (((float)$card['variance'] > 0 ? '+' : '') . $fmt($card['variance']));
        $reply = t('count_done', (string)$card['name'], $fmt($card['counted'] ?? null),
                          $fmt($card['system'] ?? null), $var);
        $tail = golpos_cal_notes_tail((string)$card['name']);
        if ($tail !== '') { $reply .= "\n" . $tail; }
        out(['reply' => $reply, 'done' => $card]);
    }
    // A vendor bill card: the executor lands the creates first, records
    // the purchase once, receives every line through the one receive
    // path and refreshes matched costs — and this reply is composed from
    // exactly what it reports landed, never from the card's hopes. A
    // line that could not land is named with its reason.
    if ($card['type'] === 'bill') {
        $res = golpos_execute_bill($card);
        golpos_clear_pending();
        $fmt = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        $lines = [t('bill_done_head', money((float)$card['total']), count($card['lines'] ?? []))];
        foreach ($res['created'] as $c) {
            if (!$c['received']) { continue; } // its failure line below speaks for it
            $lines[] = t('bill_new_line', $c['name'], money($c['price']), money($c['cost']),
                         $c['qty'], $fmt($c['after']));
        }
        foreach ($res['received'] as $r) {
            $lines[] = t('bill_recv_line', $r['name'], $r['qty'], $fmt($r['before']), $fmt($r['after']));
        }
        foreach ($res['failed'] as $f) {
            $lines[] = t('bill_fail_line', $f['name'], $f['reason']);
        }
        out(['reply' => implode("\n", $lines), 'done' => $card]);
    }
    // An order-prep card: confirming marks the vendor's order record for
    // today CONFIRMED — the sheet the Owner panel prints/shares for the
    // rep. Nothing is submitted to the vendor; the rep gets the sheet
    // from the owner's hand, exactly as the Superior rule demands.
    if ($card['type'] === 'order_prep') {
        $rec = golpos_order_for((string)$card['vendor_id'], (string)$card['date']);
        golpos_clear_pending();
        if ($rec === null) { out(['reply' => t('card_fail')]); }
        $rec['status'] = 'confirmed';
        $rec['confirmed_at'] = date('c');
        $rec['lines'] = $card['lines'];
        $rec['est_total'] = (float)$card['est_total'];
        golpos_order_put($rec);
        out(['reply' => t('ord_done', (string)$card['vendor'],
                          count((array)$card['lines']), money((float)$card['est_total'])),
             'done' => $card]);
    }
    // A vendor-teach card: the rep day / delivery day the owner stated
    // lands in the pack — a new vendor record is born with the day on it,
    // or an existing vendor's days are replaced by the taught ones.
    if ($card['type'] === 'vendor_teach') {
        $vendors = golpos_vendors();
        $target = null;
        foreach ($vendors as $i => $v) {
            if ((string)($v['id'] ?? '') === (string)($card['vendor_id'] ?? '')) { $target = $i; break; }
        }
        golpos_clear_pending();
        if ($target === null && empty($card['create'])) { out(['reply' => t('card_fail')]); }
        if ($target === null) {
            $vendors[] = ['id' => 'ven_' . substr(sha1(uniqid('', true)), 0, 8),
                          'name' => (string)$card['vendor_name'], 'aliases' => [],
                          'rep_days' => [], 'delivery_day' => null, 'cadence' => 'weekly',
                          'anchor' => null, 'account' => '', 'phone' => '', 'channel' => 'rep',
                          'last_bill' => null, 'typical' => []];
            $target = count($vendors) - 1;
        }
        if (($card['field'] ?? '') === 'delivery_day') {
            $vendors[$target]['delivery_day'] = (string)($card['new'] ?? '');
            $shown = golpos_day_name((string)$card['new']);
            $fieldWord = 'delivery day';
        } else {
            $vendors[$target]['rep_days'] = array_values((array)($card['new'] ?? []));
            $shown = golpos_day_names((array)$card['new']);
            $fieldWord = 'rep day';
        }
        golpos_save_vendors($vendors);
        out(['reply' => t('teach_done', (string)$card['vendor_name'], $fieldWord, $shown),
             'done' => $card]);
    }
    out(['reply' => t('card_fail')]);
}

if ($msg === '') { out(['reply' => t('empty')]); }

// --- add-a-sale asks: routed to the register, never written (owner law) ------
// "add sales" / "sale add gara" / "bikri hal" — a sale exists ONLY by being
// rung at the register (cart -> tender through the sale endpoint). Chat
// puts items in the CART by name; the sale records when the register
// tenders it. This lane owns the ask before any other lane can improvise.
if (golpos_is_sale_add_ask($msg)) {
    out(['reply' => t('sale_route')]);
}

// --- small talk: a greeting is a greeting -------------------------------------
// "Hi how are you today" is small talk, not a hisab ask — it takes the
// same friendly path a bare greeting takes (the fallback's own words),
// before the day-total lane can hear the word "today" in it. The shape
// is whole-message (see golpos_is_smalltalk): a greeting followed by a
// real question is untouched by this lane.
if (golpos_is_smalltalk($msg)) {
    out(['reply' => t('fallback')]);
}

// --- offer posters: MR.STORE Gol's desk, not the till (owner ruling) --------
// Making offer posters is marketing work and lives in MR.STORE's Gol; the
// POS keeps only its selling screen. A poster ask here gets the truthful
// route in one line — never a staged poster card, never a "can't" dead
// end. This runs before every other content lane so a deal's price inside
// a poster ask ("2 for 5 $") is never mistaken for a price change.
if (preg_match('/(poster|banner)/iu', $msg) ||
    preg_match('/\boffer\s+posts?\b/iu', $msg) ||
    preg_match('/(make|create|bana|banau|बना).{0,24}\bpost\b/iu', $msg)) {
    out(['reply' => t('poster_route')]);
}

// --- refunds: never the price lane (alive audit #53) ---------------------------
// A refund word is routed HERE, before every price lane can hear it.
// First, a pick from a waiting candidate list is consumed (the pick
// itself — "dosro" — carries no refund word, so it is checked before
// the refund-ask gate); a message that is not a pick clears the list
// and dispatch runs on. Then the lane itself: policy reads and setting
// cards, or the sale found and staged as a refund card.
if (golpos_refund_await_get() !== null) {
    golpos_refund_await_pick($msg, $products);
    golpos_refund_await_set(null);
}
if (golpos_is_refund_ask($msg)) {
    golpos_refund_lane($msg, $products);
}

// --- customer credit: balance reads + the redeem toggle -----------------------
// Chat reads the balance from the store's records; the spend itself
// happens only at the till tender (the Credit button beside Cash/Card).
if (golpos_is_credit_ask($msg)) {
    golpos_credit_lane($msg);
}

// --- edits to a staged ORDER card ---------------------------------------------
// While an order_prep card waits, "coke 12 hata / pepsi 20 thap" edits its
// lines and re-stages the same order. A message that parses as no edit at
// all falls through untouched — the card simply stays where it is.
$pendNow = golpos_pending();
if ($pendNow !== null && ($pendNow['type'] ?? '') === 'order_prep') {
    golpos_order_apply_edits($pendNow, $msg, $products);
}

// --- edits to a staged TASK card ------------------------------------------------
// While a task_assign card waits, "dosro kam hata" / "arko thap: …"
// edit its items and re-stage the same card — the order-prep pattern.
$pendTask = golpos_pending();
if ($pendTask !== null && ($pendTask['type'] ?? '') === 'task_assign') {
    golpos_task_card_apply_edits($pendTask, $msg);
}

// --- edits to a staged ADD-EMPLOYEE card -----------------------------------------
// While the persona card waits, a role word re-presets the bundle and
// duty words check/uncheck on top of it.
$pendEmp = golpos_pending();
if ($pendEmp !== null && ($pendEmp['type'] ?? '') === 'employee_add') {
    golpos_emp_card_apply_edits($pendEmp, $msg);
}

// --- "stage it": speak only the truth about the card on the till -------------
if (preg_match('/\bstage\b|स्टेज/iu', $msg) && !preg_match('/\d|\$/u', $msg)) {
    $pendingCard = golpos_pending();
    if ($pendingCard !== null) {
        out(['reply' => t('already_staged', golpos_card_summary($pendingCard)), 'card' => $pendingCard]);
    }
    out(['reply' => t('stage_what')]);
}

// --- capability questions (audit GOL-01, Oct 30) -----------------------------
// "What can you do? Can you add products, show reports, manage
// employees, change settings, and print receipts?" lists workflows —
// it is a question ABOUT abilities and gets the capability answer,
// never the first workflow lane whose keyword appears in the list
// (it used to land in the receipt print settings dump below, and a
// greeting/scope question mentioning employees landed in the
// employee-invitation instructions).
if (golpos_is_capability_question($msg)) {
    out(['reply' => t('capabilities')]);
}

// --- receipt print settings: read + staged changes ---------------------------
// The POS keeps only its selling screen — print settings are run from this
// chat; the Owner panel only mirrors their values, it never edits them.
// "print settings" / "receipt settings ke chha?" reads them back; a change
// is staged as an old -> new card and executes only on "gol".
if (preg_match('/(receipt|print|bill|paper).{0,30}settings|settings.{0,30}(receipt|print|bill)|settings\s+(ke|kasto)/iu', $msg)) {
    out(['reply' => golpos_settings_read_reply()]);
}

// --- store hours + batch mode (read open; changes stage like any setting) ---
if (golpos_is_hours_read($msg)) {
    $s = golpos_settings();
    out(['reply' => t('hours_line', golpos_time12((string)$s['store_open']),
                      golpos_time12((string)$s['store_close']), (string)$s['batch_mode'])]);
}
if (golpos_is_batch_mode_set($msg)) {
    golpos_stage_setting('batch_mode', preg_match('/auto/iu', $msg) ? 'auto' : 'manual');
}
if (golpos_is_hours_set($msg)) {
    $isClose = preg_match('/(banda|close|बन्द)/iu', $msg) === 1;
    $t24 = golpos_parse_time($msg, $isClose ? 'pm' : 'am');
    if ($t24 !== null) { golpos_stage_setting($isClose ? 'store_close' : 'store_open', $t24); }
    out(['reply' => t('hours_ask')]);
}

// --- card surcharge + camera frame: staged like any other setting ------------
// Parity lanes (owner 85/15 rule, Oct 28): the Owner panel only MIRRORS
// these values — chat is the door that changes them, through the same
// setting_change card as every other print setting. The surcharge takes
// a percent ("card surcharge 3%"), a bare number with a set verb, or an
// off word (0); the settings store itself refuses anything past 10, and
// the lane says so instead of staging a card that could never land.
if (preg_match('/surcharge/iu', $msg)) {
    $n = golpos_norm_digits($msg);
    $pct = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:%|percent)/iu', $n, $sm)) { $pct = round((float)$sm[1], 1); }
    elseif (preg_match('/(off|hata|bandha|zero|हटाउ|बन्द)/iu', $msg)) { $pct = 0.0; }
    elseif (preg_match('/\b(\d+(?:\.\d+)?)\b/', $n, $sm)) { $pct = round((float)$sm[1], 1); }
    if ($pct !== null) {
        if ($pct < 0 || $pct > 10) { out(['reply' => t('setting_surcharge_range')]); }
        golpos_stage_setting('card_surcharge_pct', $pct);
    }
    if (preg_match('/(kati|how much|what|कति)/iu', $msg)) {
        $s = golpos_settings();
        out(['reply' => golpos_setting_label('card_surcharge_pct') . ': '
                      . golpos_setting_display('card_surcharge_pct', $s['card_surcharge_pct'])]);
    }
    out(['reply' => t('setting_ask_surcharge')]);
}
if (preg_match('/(watch\s*frame|camera\s*frame|counter\s*frame)/iu', $msg)) {
    $off = golpos_wants_off($msg);
    if ($off !== null) { golpos_stage_setting('watch_frame', !$off); }
    out(['reply' => t('setting_ask_watch')]);
}

// --- Register Camera: the master, its children, the state (Part B) ----------
// One obedience for every camera word that reaches here. In link mode
// the MR.STORE master rules: its children (audio, footage) change only
// in MR.STORE Settings — chat says so and stages nothing — and the till
// flip is HELD while the master is off. Standalone, the register's own
// audio/record keys stage like any other setting. State asks answer
// from golpos_regcam_state() — the same facts the till itself obeys —
// never from the local flip alone. A state ask ("camera on chha?") is
// always a read, never a flip change wearing a question's clothes.
$rcState = golpos_regcam_state();
if (preg_match('/(camera|क्यामेरा|\bcam\b)/iu', $msg)) {
    $isStateAsk = preg_match('/(chha|chhan|chha\?|छ|is\b|are\b|status|haal|हाल)/iu', $msg) === 1;
    if (preg_match('/(audio|mic|sound|awaj|आवाज|अडियो)/iu', $msg)) {
        if ($rcState['mode'] === 'link') { out(['reply' => t('cam_master_hold')]); }
        $off = golpos_wants_off($msg);
        if (!$isStateAsk && $off !== null) { golpos_stage_setting('register_camera_audio', !$off); }
        if ($isStateAsk) {
            out(['reply' => t('cam_state_local', golpos_onoff($rcState['flip']),
                golpos_onoff($rcState['audio']), golpos_onoff($rcState['record']))]);
        }
        out(['reply' => t('cam_ask_audio')]);
    }
    if (preg_match('/(record|footage|clip|रेकर्ड)/iu', $msg)) {
        if ($rcState['mode'] === 'link') { out(['reply' => t('cam_master_hold')]); }
        $off = golpos_wants_off($msg);
        if (!$isStateAsk && $off !== null) { golpos_stage_setting('register_camera_record', !$off); }
        if ($isStateAsk) {
            out(['reply' => t('cam_state_local', golpos_onoff($rcState['flip']),
                golpos_onoff($rcState['audio']), golpos_onoff($rcState['record']))]);
        }
        out(['reply' => t('cam_ask_record')]);
    }
    $off = golpos_wants_off($msg);
    if (!$isStateAsk && $off !== null) {
        // A bare "camera on gara / camera off gara" is the till flip by
        // another name — staged like the frame lane, unless the master
        // holds it.
        if ($rcState['mode'] === 'link' && !$rcState['master']) { out(['reply' => t('cam_master_hold')]); }
        golpos_stage_setting('watch_frame', !$off);
    }
    if ($rcState['mode'] === 'link') {
        if (!$rcState['master']) { out(['reply' => t('cam_state_link_off')]); }
        out(['reply' => t('cam_state_link_on',
            golpos_onoff($rcState['audio']), golpos_onoff($rcState['record']),
            golpos_onoff($rcState['flip']), golpos_onoff($rcState['stream']))]);
    }
    out(['reply' => t('cam_state_local',
        golpos_onoff($rcState['flip']), golpos_onoff($rcState['audio']), golpos_onoff($rcState['record']))]);
}

// --- batch close: stage the unbatched card segment (execution on "gol") -----
if (golpos_is_batch_close_intent($msg)) {
    $seg = golpos_unbatched_card_sales();
    if (!$seg) { out(['reply' => t('batch_none')]); }
    $total = 0.0;
    foreach ($seg as $s) { $total += golpos_batch_amount($s); }
    $card = golpos_stage(['type' => 'batch_close', 'count' => count($seg),
        'total' => round($total, 2),
        'from' => (string)($seg[0]['at'] ?? ''), 'to' => (string)($seg[count($seg) - 1]['at'] ?? ''),
        'sale_ids' => array_map(static fn($s) => (string)($s['id'] ?? ''), $seg)]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}

// --- register order prep: teach, stage, confirm, hand over --------------------
// The register prepares each vendor's order on the rep's visiting day
// (owner directive, Oct 28). Teaching and confirming run through staged
// cards like every business write; "given" is the owner reporting a fact
// (the day-close precedent) and executes on his word. Nothing is ever
// submitted to a vendor from here.
$vendorsAll = golpos_vendors();
if (golpos_is_teach_ask($msg)) {
    $days = golpos_weekdays_in($msg);
    $isDelivery = preg_match('/(delivery|deliver)/iu', $msg) === 1;
    $field = $isDelivery ? 'delivery_day' : 'rep_days';
    $newVal = $isDelivery ? $days[0] : $days;
    // The words before the rep/delivery word are the vendor's name as the
    // owner speaks it ("new valley dairy ko rep sombar aaucha"). The
    // candidate name decides FIRST: a name that equals a pack vendor's
    // name or alias re-times THAT vendor; any other real name is a NEW
    // vendor — a shared word like "dairy" must never snap "Valley Dairy"
    // onto "Sunrise Dairy Fresh".
    $head = preg_split('/\b(rep|representative|delivery|deliver|visit)\b/iu', $msg)[0] ?? '';
    $toks = array_values(array_filter(preg_split('/\s+/u', trim((string)preg_replace('/[^\p{L}\p{N}\s&.\'-]/u', ' ', $head))) ?: [],
        static fn($w) => $w !== ''));
    $dropTail = ['ko', 'को', 'the', 'a', 'an', 'ma', 'मा'];
    while ($toks && in_array(mb_strtolower((string)end($toks)), $dropTail, true)) { array_pop($toks); }
    if (count($toks) > 1 && in_array(mb_strtolower((string)$toks[0]), ['add', 'new', 'naya', 'teach'], true)) {
        array_shift($toks);
    }
    $name = trim(implode(' ', array_slice($toks, -3)));
    $named = null;
    if ($name !== '' && golpos_weekdays_in($name) === []) {
        foreach ($vendorsAll as $v) {
            if (golpos_names_equal((string)$v['name'], $name)) { $named = $v; break; }
            foreach ((array)($v['aliases'] ?? []) as $a) {
                if (golpos_names_equal((string)$a, $name)) { $named = $v; break 2; }
            }
        }
    }
    if ($named !== null) {
        $v = $named;
        $card = golpos_stage(['type' => 'vendor_teach', 'vendor_id' => (string)$v['id'],
            'vendor_name' => (string)$v['name'], 'field' => $field,
            'old' => $isDelivery ? (string)($v['delivery_day'] ?? '') : (array)($v['rep_days'] ?? []),
            'new' => $newVal, 'create' => false]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if ($name === '') {
        // No name in the head: fall back to resolving the whole sentence
        // (the vendor may be named after the day words).
        $vr = $vendorsAll ? golpos_vendor_resolve($vendorsAll, $msg) : ['none' => true];
        if (!empty($vr['one'])) {
            $v = $vr['one'];
            $card = golpos_stage(['type' => 'vendor_teach', 'vendor_id' => (string)$v['id'],
                'vendor_name' => (string)$v['name'], 'field' => $field,
                'old' => $isDelivery ? (string)($v['delivery_day'] ?? '') : (array)($v['rep_days'] ?? []),
                'new' => $newVal, 'create' => false]);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        out(['reply' => t('teach_ask')]);
    }
    if (preg_match('/(order|aaja|today|rep|vendor)/iu', $name)
        || golpos_weekdays_in($name) !== []) {
        out(['reply' => t('teach_ask')]);
    }
    if ($name === mb_strtolower($name)) { $name = ucwords($name); }
    $card = golpos_stage(['type' => 'vendor_teach', 'vendor_id' => '', 'vendor_name' => $name,
        'field' => $field, 'old' => $isDelivery ? '' : [], 'new' => $newVal, 'create' => true]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
if (golpos_is_order_given($msg)) {
    if (!$vendorsAll) { out(['reply' => t('ord_no_vendors')]); }
    $vr = golpos_vendor_resolve($vendorsAll, $msg);
    if (empty($vr['one'])) {
        out(['reply' => t('ord_vendor_unknown',
            implode(', ', array_map(static fn($v) => $v['name'], $vendorsAll)))]);
    }
    $v = $vr['one'];
    $target = null;
    foreach (golpos_orders() as $o) {
        if ((string)($o['vendor_id'] ?? '') === (string)$v['id'] && ($o['status'] ?? '') === 'confirmed') {
            $target = $o;
        }
    }
    if ($target === null) { out(['reply' => t('ord_given_none', (string)$v['name'], (string)$v['name'])]); }
    $target['status'] = 'given';
    $target['given_at'] = date('c');
    golpos_order_put($target);
    out(['reply' => t('ord_given', (string)$v['name'], date('M j', strtotime((string)$target['date'])))]);
}
if (golpos_is_order_prep_ask($msg)) {
    golpos_order_ask($msg, $products);
}
if (golpos_is_order_status_ask($msg) && $vendorsAll) {
    $vr = golpos_vendor_resolve($vendorsAll, $msg);
    if (!empty($vr['one'])) {
        $v = $vr['one'];
        $rec = golpos_order_for((string)$v['id'], date('Y-m-d'));
        if ($rec === null) { out(['reply' => t('ord_status_none', (string)$v['name'], (string)$v['name'])]); }
        $st = golpos_order_status_word((string)$rec['status'])
            . ' — ' . count((array)$rec['lines']) . ' lines, est. ' . money((float)$rec['est_total']);
        out(['reply' => t('ord_status', (string)$v['name'], 'today', $st)]);
    }
}
if (golpos_is_repday_ask($msg)) {
    if (!$vendorsAll) { out(['reply' => t('ord_no_vendors')]); }
    $today = date('Y-m-d');
    $due = golpos_vendors_due($today);
    if (!$due) {
        $next = [];
        for ($i = 1; $i <= 7 && count($next) < 4; $i++) {
            $d = date('Y-m-d', strtotime("+{$i} days"));
            foreach (golpos_vendors_due($d) as $v) {
                $next[] = $v['name'] . ' — ' . date('D, M j', strtotime($d));
            }
        }
        out(['reply' => t('rep_today_none', $next ? implode('; ', $next) : '—')]);
    }
    $pend = golpos_pending();
    $lines = [t('rep_today_head', $shop)];
    foreach ($due as $v) {
        $rec = golpos_order_for((string)$v['id'], $today);
        $st = $rec !== null ? (string)$rec['status']
            : (($pend !== null && ($pend['type'] ?? '') === 'order_prep'
                && ($pend['vendor_id'] ?? '') === (string)$v['id']) ? 'prepared' : 'none');
        $lines[] = '• ' . $v['name'] . ' — ' . golpos_order_status_word($st)
            . ($rec !== null ? ' · ' . count((array)$rec['lines']) . ' lines, est. '
                . money((float)$rec['est_total']) : '');
    }
    $lines[] = t('rep_hint', (string)$due[0]['name']);
    out(['reply' => implode("\n", $lines)]);
}
// A bare greeting on a rep day carries the morning card: a due vendor
// whose order is not confirmed yet is named before anything else. With
// nothing due, the greeting falls through to the normal conversation.
if (preg_match('/^\s*(good\s*(morning|afternoon|evening)|namaste|namaskar|hello|hi|hey|suprabhat|नमस्ते|नमस्कार|शुभ\s*प्रभात)\s*[!.।]?\s*$/iu', $msg)) {
    $waiting = [];
    foreach (golpos_vendors_due(date('Y-m-d')) as $v) {
        $rec = golpos_order_for((string)$v['id'], date('Y-m-d'));
        if ($rec === null || ($rec['status'] ?? '') === 'prepared') { $waiting[] = $v; }
    }
    // The owner's morning line (employee lane): yesterday's unfinished
    // tasks and overnight notes ride the greeting, before anything
    // else — the boss buzz for v1 (audit ruling S5).
    $sessNow = golpos_session((string)($GLOBALS['golpos_session_id'] ?? ''));
    $teamLine = !empty($sessNow['admin']) ? golpos_team_line() : '';
    // The owner's calendar buzz at the till: store-owned entries whose
    // moment arrived (missed ones included), shown once per audience.
    $calLine = '';
    if (!empty($sessNow['admin'])) {
        try {
            $storeEvts = array_values(array_filter(
                golpos_cal_all_events(date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('+1 day'))),
                static fn($e) => ($e['owner'] ?? 'store') === 'store'));
            [$calDueEvts, $calDueMarks] = golpos_cal_due_for($storeEvts, 'store');
            if ($calDueEvts) {
                golpos_cal_mark_shown($calDueMarks);
                $calLines = ['📅 Calendar:'];
                foreach (array_slice($calDueEvts, 0, 4) as $ce) { $calLines[] = golpos_cal_line($ce); }
                $calLine = implode("\n", $calLines);
            }
        } catch (Throwable $e) {}
    }
    $greetHead = golpos_greet_word() . '.';
    $dueBody = static function () use ($waiting): string {
        if (!$waiting) { return ''; }
        $raw = t('morning_due',
            implode(' + ', array_map(static fn($v) => (string)$v['name'], $waiting)),
            (string)$waiting[0]['name']);
        return (string)preg_replace('/^Good morning[।.]?\s*/u', '', $raw);
    };
    if ($teamLine !== '' || $calLine !== '') {
        $rep = $dueBody();
        out(['reply' => $greetHead
            . ($teamLine !== '' ? "\n" . $teamLine : '')
            . ($calLine !== '' ? "\n" . $calLine : '')
            . ($rep !== '' ? "\n" . $rep : '')]);
    }
    if ($waiting) {
        out(['reply' => $greetHead . "\n" . $dueBody()]);
    }
}

// --- employee lane: assign, note back, my tasks, done marks ---------------------
// The owner assigns (admin domain — the gate has run), the employee
// answers from her own session (cashier domain). Assign and note both
// stage a card; nothing fires on a bare mention of a name.
$assignParse = golpos_parse_task_assign($msg);
if ($assignParse !== null) {
    $emp = golpos_employee_resolve($assignParse['who']);
    if ($emp === null) {
        // Two roster members sharing the name is not a stranger —
        // say so and name both addresses, so the owner picks.
        $sameName = [];
        foreach (golpos_people()['employees'] as $e) {
            if (golpos_bill_name_key(golpos_display_name($e)) === golpos_bill_name_key($assignParse['who'])
                || golpos_bill_name_key((string)preg_replace('/\s+.*$/', '', golpos_display_name($e)))
                   === golpos_bill_name_key($assignParse['who'])) {
                $sameName[] = (string)$e['email'];
            }
        }
        if (count($sameName) > 1) {
            out(['reply' => 'Two people named ' . $assignParse['who'] . ' are on the roster — '
                . implode(' / ', $sameName) . '. Say the email too and I will assign to the right one.']);
        }
        out(['reply' => t('assign_roster_miss', $assignParse['who'])]);
    }
    $card = golpos_stage(['type' => 'task_assign',
                          'employee_email' => (string)$emp['email'],
                          'employee_name' => golpos_display_name($emp),
                          'items' => $assignParse['items'], 'due' => $assignParse['due']]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
$noteText = golpos_parse_team_note($msg);
if ($noteText !== null) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'employee') {
        out(['reply' => t('note_ask_identity')]);
    }
    $card = golpos_stage(['type' => 'team_note',
                          'from_email' => (string)$actor['email'],
                          'from_name' => (string)$actor['name'], 'text' => $noteText]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
if (golpos_is_my_tasks_ask($msg)) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'employee') {
        out(['reply' => t('note_ask_identity')]);
    }
    golpos_tasks_sync(); // a task-view sync point: Gol's own tasks land here too
    $items = golpos_open_items_for((string)$actor['email']);
    if (!$items) { out(['reply' => t('tasks_none')]); }
    $nextDue = golpos_next_due((string)$actor['email']);
    $lines = $nextDue !== null
        ? ['Next: ' . $nextDue['text'] . ($nextDue['reason'] !== '' ? ' — ' . $nextDue['reason'] : ''),
           'Your open tasks (' . count($items) . '):']
        : ['Your open tasks (' . count($items) . '):'];
    foreach ($items as $i => $it) {
        $lines[] = ($i + 1) . '. ' . $it['text']
            . ($it['source'] === 'gol' && $it['reason'] !== '' ? ' — from Gol: ' . $it['reason'] : '');
    }
    out(['reply' => implode("\n", $lines)]);
}
// Her duties, from HER pack (owner, Oct 28): "mero duty ke ho?" is
// answered from the persona she was born with and has grown — never
// from a generic store list.
if (preg_match('/(mero|my)\s+(duty|duties|job|kartabya|कर्तव्य)/iu', $msg)
    || preg_match('/(maile\s+ke\s+ke\s+garne|what\s+are\s+my\s+duties)/iu', $msg)) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'employee' || ($actor['record'] ?? null) === null) {
        out(['reply' => t('note_ask_identity')]);
    }
    $pres = golpos_presence_for($actor['record']);
    $pack = array_values(array_map('strval', (array)($pres['job_pack']['items'] ?? [])));
    if (!$pack) { out(['reply' => t('tasks_none')]); }
    $lines = ['Your duties (' . ucfirst((string)($pres['role'] ?? 'cashier')) . ' pack):'];
    foreach ($pack as $i => $d) { $lines[] = ($i + 1) . '. ' . $d; }
    out(['reply' => implode("\n", $lines)]);
}
// --- Pay Ask lane (owner directive, Oct 28, 14:30) -----------------------------
// She asks in her own session; Gol prices her EVIDENCED hours at her
// roster rate and stages a pay_send card; her "gol" creates the ONE
// pending request; the owner meets it as a pay_approve card and his
// "gol" books it once (the decline word closes it instead). Money
// never moves on her word, and hours never pad themselves.

// A correction rides the staged pay_send card — her words, verbatim,
// appended for the owner's eyes; evidenced hours never change.
$pendPay = golpos_pending();
if ($pendPay !== null && ($pendPay['type'] ?? '') === 'pay_send'
    && golpos_is_pay_correction($msg)) {
    $actorC = golpos_actor();
    if ($actorC !== null && ($actorC['role'] ?? '') === 'employee'
        && (string)($actorC['email'] ?? '') === (string)($pendPay['employee_email'] ?? '')) {
        $pendPay['corrections'] = array_values(array_map('strval', (array)($pendPay['corrections'] ?? [])));
        $pendPay['corrections'][] = trim($msg);
        $card = golpos_stage($pendPay);
        out(['reply' => 'Correction added to your card, in your words: "' . trim($msg) . '"'
                      . ' — the owner sees it on the request. Say "gol" to send, or tell me another.',
             'card' => $card]);
    }
}

if (golpos_is_pay_ask($msg)) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'employee' || ($actor['record'] ?? null) === null) {
        out(['reply' => t('pay_owner_hint')]);
    }
    $emp = $actor['record'];
    $email = (string)$emp['email'];
    $name = golpos_display_name($emp);
    $np = in_array($L, ['np', 'dev'], true);
    $hrs = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    // A request already waiting: its status is the whole answer.
    $pendReq = golpos_pay_pending_for($email);
    if ($pendReq !== null) {
        out(['reply' => t('pay_pending', money((float)$pendReq['amount']),
                          golpos_pay_period_label((string)$pendReq['period_from'], (string)$pendReq['period_to']))]);
    }
    // The owner's last decision on her, delivered once, in her session.
    $prefix = '';
    $latest = golpos_pay_latest_for($email);
    if ($latest !== null && empty($latest['seen'])) {
        if (($latest['status'] ?? '') === 'declined') {
            $prefix = t('pay_declined_note', money((float)$latest['amount']),
                golpos_pay_period_label((string)$latest['period_from'], (string)$latest['period_to']));
        } elseif (($latest['status'] ?? '') === 'paid') {
            $prefix = t('pay_paid_note', money((float)$latest['amount']),
                golpos_pay_period_label((string)$latest['period_from'], (string)$latest['period_to']));
        }
        if ($prefix !== '') {
            $latest['seen'] = true;
            golpos_pay_ask_save($latest);
        }
    }
    $pf = golpos_pay_period_from($email);
    $from = (string)$pf['from'];
    $to = date('Y-m-d');
    $comp = golpos_pay_compute($emp, $from, $to, $pf['since'] ?? null);
    if (!$comp['lines'] && !$comp['unended']) {
        out(['reply' => $prefix . t('pay_no_hours', date('M j', strtotime($from)))]);
    }
    if ($comp['unrated']) {
        $days = implode(', ', array_map(
            static fn($l) => date('M j', strtotime((string)$l['date'])), $comp['unrated']));
        if (!$comp['segments']) {
            out(['reply' => $prefix . t('pay_no_rate')]);
        }
        out(['reply' => $prefix . t('pay_unrated', $days)]);
    }
    if (!$comp['lines']) {
        // Only unended stints: nothing evidenced to price.
        $u = $comp['unended'][0];
        out(['reply' => $prefix . t('pay_no_hours', date('M j', strtotime((string)$u['date'])))]);
    }
    $period = golpos_pay_period_label($from, $to);
    $lines = [$prefix . ($np ? 'Tapai ko talab — ' : 'Your pay — ') . $period . ':'];
    $dayLines = array_slice($comp['lines'], 0, 14);
    foreach ($dayLines as $l) {
        $lines[] = '• ' . date('D M j', strtotime((string)$l['date'])) . ': ' . $hrs($l['hours']) . ' hrs'
            . (!empty($l['open']) ? ($np ? ' (ahile khula chha — ahile samma)' : ' (still open — counted to now)') : '');
    }
    if (count($comp['lines']) > count($dayLines)) {
        $lines[] = '• +' . (count($comp['lines']) - count($dayLines)) . ' more day(s) on the card';
    }
    foreach ($comp['unended'] as $u) {
        $lines[] = '• ' . date('D M j', strtotime((string)$u['date']))
            . ($np ? ': yo shift ko anta record chhaina — gineko chhaina.'
                   : ': this shift has no recorded end — not counted.');
    }
    $lines[] = golpos_pay_headline($comp['segments'], (float)$comp['amount']);
    $lines[] = $np
        ? 'Card tayar chha — malik lai pathauna "gol" bhannus. Kunai shift छुटेको chha bhane bhannus (jastai "ma mangalbar 4 ghanta thiye") — tapai kai shabda ma card ma baschha.'
        : 'Card ready — say "gol" to send this to the owner. If a shift is missing, tell me (e.g. "ma mangalbar 4 ghanta thiye") and it rides on the card in your words.';
    $card = golpos_stage(['type' => 'pay_send',
        'employee_email' => $email, 'employee_name' => $name,
        'period_from' => $from, 'period_to' => $to,
        'lines' => $comp['lines'], 'segments' => $comp['segments'],
        'hours' => (float)$comp['hours'], 'amount' => (float)$comp['amount'],
        'covered_until' => $comp['covered_until'] ?? null,
        'corrections' => [], 'unended' => $comp['unended']]);
    out(['reply' => implode("\n", $lines), 'card' => $card]);
}

// The owner's request work (admin domain — the gate has run): what is
// waiting, and the approve card for one of them.
if (golpos_is_pay_requests_ask($msg)) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'admin') {
        out(['reply' => t('pay_owner_hint')]);
    }
    $waiting = golpos_pay_pending_all();
    if (!$waiting) { out(['reply' => 'No pay requests waiting on you.']); }
    $pick = null;
    foreach ($waiting as $r) {
        $nm = (string)($r['employee_name'] ?? '');
        $first = explode(' ', $nm)[0] ?? '';
        foreach (array_unique(array_filter([$nm, $first])) as $cand) {
            if (preg_match('/(^|[^\p{L}])' . preg_quote($cand, '/') . '([^\p{L}]|$)/iu', $msg)) {
                $pick = $r; break 2;
            }
        }
    }
    if ($pick === null && count($waiting) === 1) { $pick = $waiting[0]; }
    if ($pick === null) {
        $ls = ['Pay requests waiting on you:'];
        foreach ($waiting as $r) {
            $ls[] = '• ' . (string)$r['employee_name'] . ' — '
                . golpos_pay_headline((array)$r['segments'], (float)$r['amount'])
                . ' · ' . golpos_pay_period_label((string)$r['period_from'], (string)$r['period_to']);
        }
        $ls[] = 'Say her name and I\'ll bring her card.';
        out(['reply' => implode("\n", $ls)]);
    }
    $card = golpos_stage(['type' => 'pay_approve',
        'request_id' => (string)$pick['id'],
        'employee_email' => (string)$pick['employee_email'],
        'employee_name' => (string)$pick['employee_name'],
        'period_from' => (string)$pick['period_from'],
        'period_to' => (string)$pick['period_to'],
        'segments' => (array)$pick['segments'],
        'hours' => (float)$pick['hours'], 'amount' => (float)$pick['amount'],
        'corrections' => (array)($pick['corrections'] ?? [])]);
    out(['reply' => (string)$pick['employee_name'] . ' is asking for pay — '
                  . golpos_pay_headline((array)$pick['segments'], (float)$pick['amount'])
                  . ' · ' . golpos_pay_period_label((string)$pick['period_from'], (string)$pick['period_to'])
                  . '. Card ready — say "gol" to approve and book it, or "decline" to decline.',
         'card' => $card]);
}

// The owner's rate change (admin domain — the gate has run): staged
// old→new, effective today; "gol" writes the roster record.
$rateChange = golpos_parse_rate_change($msg);
if ($rateChange !== null) {
    $actor = golpos_actor();
    if ($actor === null || ($actor['role'] ?? '') !== 'admin') {
        out(['reply' => t('pay_owner_hint')]);
    }
    $emp = $rateChange['emp'];
    $today = date('Y-m-d');
    $old = golpos_rate_on($emp, $today);
    if ($rateChange['by'] && $old === null) {
        out(['reply' => golpos_display_name($emp)
            . ' has no rate on record yet — say the new rate itself ("'
            . golpos_display_name($emp) . ' ko pay 13") and I\'ll stage it.']);
    }
    $new = $rateChange['by'] ? round($old + $rateChange['amount'], 2) : round($rateChange['amount'], 2);
    if ($old !== null && abs($new - $old) < 0.001) {
        out(['reply' => golpos_display_name($emp) . "'s rate is already " . money($old) . ' — nothing to change.']);
    }
    $card = golpos_stage(['type' => 'rate_change',
        'employee_email' => (string)$emp['email'],
        'employee_name' => golpos_display_name($emp),
        'old_rate' => $old, 'new_rate' => $new, 'effective' => $today]);
    out(['reply' => 'Rate change staged — ' . golpos_card_summary($card)
                  . '. Say "gol" to set it from today; past periods keep the old rate.',
         'card' => $card]);
}

// --- AI Calendar + AI Notes: read / create, both domains -------------------
// Her own calendar and notes are cashier domain (the gate has already
// run: a by-name read or a for-someone-else create arrived here only
// with admin elevation). Creates stage a card; "gol" writes once into
// the ONE store (MR.STORE KV in link mode, the local pack standalone).
$calRead = golpos_cal_parse_read($msg);
if ($calRead !== null) {
    $actor = golpos_actor();
    $scope = $calRead['scope'];
    $todayD = date('Y-m-d');
    [$rFrom, $rTo] = $scope === 'today' ? [$todayD, $todayD]
        : ($scope === 'tomorrow' ? [date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day'))]
        : [$todayD, date('Y-m-d', strtotime('+7 days'))]);
    $all = golpos_cal_all_events(date('Y-m-d', strtotime('-7 days')), $rTo);
    $inRange = static fn(array $evs) => array_values(array_filter($evs,
        static fn($e) => ($e['date'] ?? '') >= $rFrom && ($e['date'] ?? '') <= $rTo));
    $scopeWord = ['today' => 'today', 'tomorrow' => 'tomorrow (bholi)', 'week' => 'next 7 days'][$scope];
    /* owned entries lead; computed system faces (rep/delivery days)
       ride below and can never push a real entry out of the answer */
    $composeCal = static function (array $view) use ($scopeWord): array {
        $real = array_values(array_filter($view, static fn($e) => ($e['source'] ?? '') !== 'system'));
        $sys = array_values(array_filter($view, static fn($e) => ($e['source'] ?? '') === 'system'));
        $lines = [];
        foreach (array_slice($real, 0, 10) as $e) { $lines[] = golpos_cal_line($e); }
        foreach (array_slice($sys, 0, 5) as $e) { $lines[] = golpos_cal_line($e); }
        if (!$lines) { $lines[] = 'Nothing in this scope.'; }
        return $lines;
    };
    if (($calRead['who'] === 'store' || $calRead['who'] === 'self')
        && ($actor === null || ($actor['role'] ?? '') !== 'employee')) {
        /* admin asking "mero calendar" means the store's own book */
        $lines = array_merge(['📅 ' . $shop . ' calendar — ' . $scopeWord . ':'],
                             $composeCal($inRange($all)));
        out(['reply' => implode("\n", $lines)]);
    }
    /* an employee asking for "the calendar" gets HER OWN view —
       one persona, her dated world, never the store's whole book */
    $empRec = null; $empName = ''; $empGroups = [];
    if ($calRead['who'] === 'self' || $calRead['who'] === 'store') {
        if ($actor === null || ($actor['role'] ?? '') !== 'employee' || ($actor['record'] ?? null) === null) {
            out(['reply' => t('note_ask_identity')]);
        }
        $empRec = $actor['record']; $empName = (string)$actor['name'];
    } else {
        $emp = golpos_employee_resolve($calRead['who']);
        if ($emp === null) { out(['reply' => t('roster_miss', $calRead['who'])]); }
        $empRec = $emp; $empName = golpos_display_name($emp);
    }
    $empGroups = golpos_cal_duty_groups_for($empRec);
    $view = $inRange(golpos_cal_for_persona($all, $empName, $empGroups));
    $lines = array_merge(['📅 ' . $empName . ' — calendar, ' . $scopeWord . ':'],
                         $composeCal($view));
    out(['reply' => implode("\n", $lines)]);
}
$calCreate = golpos_cal_parse_create($msg);
if ($calCreate !== null) {
    $actor = golpos_actor();
    if ($actor === null) { out(['reply' => t('note_ask_identity')]); }
    $forName = 'store'; $forEmail = '';
    if (($actor['role'] ?? '') === 'employee') {
        /* her own calendar — a name she says can only be herself */
        if ($calCreate['persona'] !== ''
            && golpos_name_key($calCreate['persona']) !== golpos_name_key((string)$actor['name'])) {
            out(['reply' => 'That entry names ' . $calCreate['persona'] . ' — calendar entries for someone else are the boss\'s to add. I can add it to YOUR calendar if you say so.']);
        }
        $forName = (string)$actor['name']; $forEmail = (string)$actor['email'];
    } elseif ($calCreate['persona'] !== '') {
        $emp = golpos_employee_resolve($calCreate['persona']);
        if ($emp === null) { out(['reply' => t('roster_miss', $calCreate['persona'])]); }
        $forName = golpos_display_name($emp); $forEmail = (string)$emp['email'];
    }
    $card = golpos_stage(['type' => 'cal_entry', 'date' => $calCreate['date'],
        'time' => $calCreate['time'], 'title' => $calCreate['title'],
        'for_name' => $forName, 'for_email' => $forEmail,
        'duty_group' => $calCreate['duty_group'], 'etype' => $calCreate['etype'],
        'by_email' => (string)($actor['email'] ?? ''), 'by_name' => (string)($actor['name'] ?? '')]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
$noteBody = golpos_note_parse_create($msg);
if ($noteBody !== null) {
    $actor = golpos_actor();
    if ($actor === null) { out(['reply' => t('note_ask_identity')]); }
    $forName = ($actor['role'] ?? '') === 'employee' ? (string)$actor['name'] : 'store';
    $itemName = '';
    $ri = resolve_item($products, $noteBody);
    if (!empty($ri['one'])) { $itemName = (string)($ri['one']['mr_name'] ?? $ri['one']['name']); }
    $card = golpos_stage(['type' => 'note_add', 'body' => $noteBody,
        'for_name' => $forName, 'item' => $itemName,
        'by_email' => (string)($actor['email'] ?? ''), 'by_name' => (string)($actor['name'] ?? '')]);
    out(['reply' => t('card_staged'), 'card' => $card]);
}
if (preg_match('/\bnotes?\b/iu', $msg)
    && preg_match('/(dekha|show|list|ke ke|ke chha|herau|read)/iu', $msg)
    && !preg_match('/(rakha|gara|lekha|add|save|bana)/iu', $msg)) {
    $actor = golpos_actor();
    if ($actor === null) { out(['reply' => t('note_ask_identity')]); }
    $notes = golpos_cal_stored_notes();
    if (($actor['role'] ?? '') === 'employee') {
        $mine = array_values(array_filter($notes, static fn($n) =>
            golpos_name_key((string)($n['owner'] ?? 'store')) === golpos_name_key((string)$actor['name'])));
        if (!$mine) { out(['reply' => '📝 Your notes: none yet. Say "note rakha: <kura>" and I will keep it.']); }
        $lines = ['📝 Your notes (' . count($mine) . '):'];
        foreach (array_slice($mine, -10) as $n) {
            $lines[] = '• ' . mb_substr(trim((string)($n['body'] ?? '')), 0, 120)
                . ((string)($n['item'] ?? '') !== '' ? ' (re: ' . $n['item'] . ')' : '');
        }
        out(['reply' => implode("\n", $lines)]);
    }
    if (!$notes) { out(['reply' => '📝 ' . $shop . ' notes: none yet. Say "note rakha: <kura>" and I will keep it.']); }
    $lines = ['📝 ' . $shop . ' notes (' . count($notes) . '):'];
    foreach (array_slice($notes, -12) as $n) {
        $body = trim((string)($n['body'] ?? '')) !== '' ? (string)$n['body'] : (string)($n['title'] ?? '');
        $lines[] = '• [' . (string)($n['owner'] ?? 'store') . '] ' . mb_substr($body, 0, 120)
            . ((string)($n['item'] ?? '') !== '' ? ' (re: ' . $n['item'] . ')' : '');
    }
    out(['reply' => implode("\n", $lines)]);
}

// A done mark claims ONLY an ordinal or a named open task item — the
// same words ("sakiyo") in a shelf statement belong to the What's Out
// lane below, never to a task the employee didn't name.
if (preg_match('/(sakiyo|sakyo|sakisakyo|done|finished|complete|bhaisakyo|सकियो)/iu', $msg)
    && !preg_match('/(kati|ke\s+ke|kun|which|list|dekha)/iu', $msg)) {
    $actor = golpos_actor();
    if ($actor !== null && ($actor['role'] ?? '') === 'employee') {
        $items = golpos_open_items_for((string)$actor['email']);
        if ($items) {
            $pick = null;
            $ord = golpos_ordinal_in($msg);
            if ($ord !== null && isset($items[$ord - 1])) { $pick = $items[$ord - 1]; }
            if ($pick === null) {
                $clean = trim((string)preg_replace(
                    '/(sakiyo|sakyo|sakisakyo|done|finished|complete|bhaisakyo|सकियो|bhayo|ho\s*gayo)/iu', ' ', $msg));
                $mk = golpos_name_key($clean);
                foreach ($items as $it) {
                    $ik = golpos_name_key($it['text']);
                    if ($ik !== '' && $mk !== '' && (mb_strpos($mk, $ik) !== false || mb_strpos($ik, $mk) !== false)) {
                        $pick = $it; break;
                    }
                }
            }
            if ($pick === null && count($items) === 1 && $ord === null
                && preg_match('/^(sakiyo|sakyo|done|finished|bhaisakyo|सकियो)\s*[.!]?$/iu', trim($msg))) {
                $pick = $items[0];
            }
            if ($pick !== null) {
                foreach (golpos_tasks() as $t) {
                    if ((string)($t['id'] ?? '') !== $pick['task_id']) { continue; }
                    $t['items'][$pick['index']]['done'] = true;
                    $t['items'][$pick['index']]['done_at'] = date('c');
                    golpos_task_put($t);
                    break;
                }
                out(['reply' => t('task_done_ok', $pick['text'], date('g:i A'))]);
            }
            $list = implode('; ', array_map(static fn($i, $it) => ($i + 1) . '. ' . $it['text'],
                                             array_keys($items), $items));
            out(['reply' => t('task_done_which', $list)]);
        }
    }
}

// --- PIN set/reset: the owner's card (TASKS 230/231, Nov 2) -------------------
// Admin domain — the gate has already run, so a session reaching here
// with a PIN-set ask is elevated (or no admin exists yet); an employee
// session can never stage this card. The owner names the person (or the
// admin PIN) and the new digits; the staged card carries NEITHER digits
// NOR the hash — the hash waits in the session and the execution case
// reads it from there. Only the owner's "gol" lands it.
if (golpos_is_pin_set_intent($msg)) {
    $target = golpos_pin_set_target($msg);
    if ($target === null) { out(['reply' => t('pin_set_who')]); }
    $digits = null;
    if (preg_match('/\b(\d{4,6})\b/', golpos_norm_digits($msg), $dm)) {
        $digits = golpos_norm_pin($dm[1]);
    }
    if ($digits === null) {
        out(['reply' => t('pin_set_ask', (string)$target['name'])]);
    }
    $card = golpos_stage(['type' => 'pin_set', 'target' => (string)$target['kind'],
                          'email' => (string)$target['email'], 'name' => (string)$target['name']]);
    $sid = (string)($GLOBALS['golpos_session_id'] ?? '');
    $ss = golpos_session($sid);
    $ss['pin_set_hash'] = password_hash($digits, PASSWORD_DEFAULT);
    golpos_save_session($sid, $ss);
    out(['reply' => t('card_staged'), 'card' => $card]);
}

// --- employees: add (invite by email), list, staged remove -------------------
// Admin domain — the gate has already run, so a session reaching here with
// an employee intent is elevated (or no admin exists yet).
if (golpos_is_employee_intent($msg)) {
    /* A NEGATED employee ask saves nothing and starts no invitation
       flow (audit GOL-01, Oct 30): "Do not add or save any employees,
       just tell me" is a scope statement — acknowledge it plainly.
       An email in the same breath overrides: that is a real act. */
    if (!preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $msg)
        && preg_match('/(do\s*not|don\'?t|never|nagar|nagari|नगर)\b[^.]{0,40}\b(add|save|invite|create|thap|rakh|employee|staff|karmachari)\b/iu', $msg)) {
        out(['reply' => t('emp_negated')]);
    }
    $email = null;
    if (preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $msg, $em)) { $email = golpos_norm_email($em[0]); }
    // An add verb leading the message owns it: in "add rita@x as
    // closer, cash ginti hata" the hata strikes a DUTY on her card,
    // not the employee (battery catch, Oct 28).
    if ($email !== null && preg_match('/(remove|hata|hatau|nikal|निकाल|हटाउ)/iu', $msg)
        && !preg_match('/\b(add|invite|join)\b/iu', $msg)) {
        $e = golpos_employee($email);
        if ($e === null) { out(['reply' => t('emp_not_found', $email)]); }
        $card = golpos_stage(['type' => 'employee_remove', 'email' => $email,
                              'name' => (string)($e['name'] ?? '')]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if ($email !== null && preg_match('/(add|thap|थप|invite|join)/iu', $msg)) {
        $existing = golpos_employee($email);
        if ($existing !== null) {
            out(['reply' => t('emp_exists', $email, (string)($existing['status'] ?? ''))]);
        }
        // Her name is the plain words BEFORE the email ("add Rita
        // Sharma rita@x …") — never the words after it: in "add
        // rita@x as closer, cash ginti hata" everything past the email
        // is role + duty edits, and an earlier build swallowed them as
        // her name (battery catch, Oct 28). With no name said, the
        // display name derives from the email itself.
        $name = '';
        $before = trim((string)preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+.*$/su', '', $msg));
        $before = trim((string)preg_replace('/\b(add|invite|join|please|plz)\b/iu', ' ', $before));
        $before = trim((string)preg_replace('/[^\p{L}\s.\'-]/u', ' ', $before));
        $before = (string)preg_replace('/\s+/', ' ', $before);
        if (preg_match('/^[\p{L}][\p{L} .\'-]{0,40}$/u', $before)
            && !preg_match('/(hata|thap|pani|ginti|garne|employee|staff|cashier|closer|stocker|manager)/iu', $before)) {
            $name = $before;
        }
        // The persona is chosen HERE (owner, Oct 28): a role word picks
        // the preset (Cashier when none is said), duty words in the same
        // message check/uncheck on top of it, and the staged card shows
        // the final set — count, groups, and what she starts knowing.
        // The add itself lands on "gol", like every roster write.
        $role = golpos_role_word_in($msg) ?? 'cashier';
        $duties = golpos_apply_duty_words(golpos_preset_duties($role), $msg, false);
        $card = golpos_stage(['type' => 'employee_add', 'email' => $email, 'name' => $name,
                              'role' => $role, 'duties' => $duties]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if (preg_match('/(list|dekha|dekhaunus|show|ko ko|kati|who|my\s+(employees|staff|team)|mero\s+(employee|staff)|हेर|देखा)/iu', $msg)) {
        $emps = golpos_people()['employees'];
        if (!$emps) { out(['reply' => t('emp_none')]); }
        $lines = [t('emp_list_head')];
        foreach ($emps as $e) {
            $who = trim((string)($e['name'] ?? '')) !== '' ? $e['name'] . ' <' . $e['email'] . '>' : (string)$e['email'];
            $lines[] = '• ' . $who . ' — ' . (string)($e['status'] ?? '');
        }
        out(['reply' => implode("\n", $lines)]);
    }
    out(['reply' => t('emp_help')]);
}

// --- loyalty leaderboard in chat (the panel board, chat-native) --------------
if (golpos_is_loyalty_board_intent($msg)) {
    $board = golpos_loyalty_board();
    if (!$board) { out(['reply' => t('board_none')]); }
    $lines = [t('board_head')];
    foreach ($board as $row) {
        $lines[] = "#{$row['rank']} {$row['name']} — " . money((float)$row['total'])
            . " ({$row['receipts']} " . ($row['receipts'] === 1 ? 'receipt' : 'receipts') . ')';
    }
    out(['reply' => implode("\n", $lines)]);
}

// --- owner team reads + presence (admin domain — the gate has run) --------------
// The boss's side of the lane: what she said, who finished what, and
// the presence read — all composed from the lane's own records, with
// a generator sync first (an owner inbox read is a sync point).
$noteWho = golpos_parse_note_ask($msg);
if ($noteWho !== null) {
    $emp = golpos_employee_resolve($noteWho);
    if ($emp === null) { out(['reply' => t('roster_miss', $noteWho)]); }
    golpos_tasks_sync();
    $notes = [];
    foreach (golpos_team_notes() as $n) {
        if (($n['from_email'] ?? '') === (string)$emp['email']) { $notes[] = $n; }
    }
    if (!$notes) { out(['reply' => t('team_no_notes', golpos_display_name($emp))]); }
    $notes = array_slice(array_reverse($notes), 0, 5);
    $lines = ['Notes from ' . golpos_display_name($emp) . ':'];
    foreach ($notes as $n) {
        $lines[] = '• "' . (string)$n['text'] . '" — ' . date('M j, g:i A', strtotime((string)$n['at']));
    }
    golpos_team_notes_mark_read((string)$emp['email']);
    out(['reply' => implode("\n", $lines)]);
}
if (golpos_is_progress_ask($msg)) {
    golpos_tasks_sync();
    $lines = ['Team progress:'];
    $any = false;
    foreach (golpos_people()['employees'] as $e) {
        $email = (string)$e['email'];
        $done = 0; $open = 0; $lastDone = '';
        foreach (golpos_tasks_for($email) as $t) {
            if (($t['employee_email'] ?? null) !== $email) { continue; } // own tasks only in this count
            foreach ((array)($t['items'] ?? []) as $it) {
                if (!empty($it['done'])) { $done++; $lastDone = (string)($it['text'] ?? ''); }
                else { $open++; }
            }
        }
        $lines[] = '• ' . golpos_display_name($e) . ' — ' . $done . ' done, ' . $open . ' open'
            . ($lastDone !== '' ? ' · latest done: ' . $lastDone : '');
        $any = true;
    }
    $roleOpen = 0;
    foreach (golpos_tasks() as $t) {
        if (!empty($t['employee_email']) || ($t['status'] ?? '') === 'superseded'
            || golpos_task_status($t) === 'done') { continue; }
        foreach ((array)($t['items'] ?? []) as $it) { if (empty($it['done'])) { $roleOpen++; } }
    }
    if ($roleOpen > 0) { $lines[] = '• Role tasks (unassigned) — ' . $roleOpen . ' open'; $any = true; }
    if (!$any) { $lines[] = 'No employees on the roster yet.'; }
    out(['reply' => implode("\n", $lines)]);
}
$presenceWho = golpos_parse_presence_ask($msg);
if ($presenceWho !== null) {
    $emp = golpos_employee_resolve($presenceWho);
    if ($emp === null) { out(['reply' => t('roster_miss', $presenceWho)]); }
    golpos_tasks_sync();
    out(['reply' => golpos_presence_reply($emp)]);
}
$msgN = golpos_norm_digits($msg);
if (preg_match('/(receipt|bill|print|paper|footer|logo|tax|auto|cop(y|ies)|phone|address|store name|फोन|ठेगाना|लोगो|कर)/iu', $msg)) {
    // paper width — "paper 58mm gara"
    if (preg_match('/\b(58|80)\s*mm\b/iu', $msgN, $wm)) {
        golpos_stage_setting('paper_width', $wm[1] . 'mm');
    }
    // copies — "bill 2 copy print gara"
    if (preg_match('/cop(y|ies)/iu', $msg)) {
        if (preg_match('/\b([123])\b/', $msgN, $cm)) { golpos_stage_setting('copies', (int)$cm[1]); }
        out(['reply' => t('setting_ask_copies')]);
    }
    // footer text — quoted words win; otherwise the words left after the
    // command words are stripped. No words -> Gol asks; a footer is never
    // invented.
    if (preg_match('/footer/iu', $msg)) {
        $val = '';
        if (preg_match('/["\'“”](.+?)["\'“”]/u', $msg, $qm)) { $val = trim($qm[1]); }
        if ($val === '') {
            $val = preg_replace('/(receipt|bill|print|footer|message|ma|मा|rakha|rakh|set|gara|gar|change|to|lekha|लेख|राख)/iu', ' ', $msg);
            $val = trim(preg_replace('/\s+/u', ' ', preg_replace('/[:"\'“”]/u', ' ', (string)$val)));
        }
        if ($val === '') { out(['reply' => t('setting_ask_footer')]); }
        golpos_stage_setting('footer', $val);
    }
    // phone — a number in the message sets the line (and shows it); a bare
    // show/hide verb toggles the line.
    if (preg_match('/(phone|फोन)/iu', $msg)) {
        if (preg_match('/(\+?\d[\d\s\-()]{6,}\d)/', $msgN, $pm)) {
            $card = golpos_stage(['type' => 'setting_change', 'setting' => 'phone',
                                  'label' => golpos_setting_label('phone'),
                                  'old' => golpos_settings()['phone'], 'new' => trim($pm[1]),
                                  'also' => ['show_phone' => true]]);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        $off = golpos_wants_off($msg);
        if ($off !== null) { golpos_stage_setting('show_phone', !$off); }
    }
    // address — quoted text sets it (and shows it); a bare verb toggles it.
    if (preg_match('/(address|ठेगाना)/iu', $msg)) {
        if (preg_match('/["\'“”](.+?)["\'“”]/u', $msg, $qm)) {
            $card = golpos_stage(['type' => 'setting_change', 'setting' => 'address',
                                  'label' => golpos_setting_label('address'),
                                  'old' => golpos_settings()['address'], 'new' => trim($qm[1]),
                                  'also' => ['show_address' => true]]);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        $off = golpos_wants_off($msg);
        if ($off !== null) { golpos_stage_setting('show_address', !$off); }
    }
    // logo on/off
    if (preg_match('/(logo|लोगो)/iu', $msg)) {
        $off = golpos_wants_off($msg);
        if ($off !== null) { golpos_stage_setting('logo', !$off); }
    }
    // tax line show/hide
    if (preg_match('/(tax|कर)/iu', $msg)) {
        $off = golpos_wants_off($msg);
        if ($off !== null) { golpos_stage_setting('show_tax', !$off); }
    }
    // auto-open receipt after each sale, on/off
    if (preg_match('/(auto)/iu', $msg)) {
        $off = golpos_wants_off($msg);
        if ($off !== null) { golpos_stage_setting('auto_open', !$off); }
    }
    // store name line (quoted text)
    if (preg_match('/(store name|receipt name)/iu', $msg) &&
        preg_match('/["\'“”](.+?)["\'“”]/u', $msg, $qm)) {
        golpos_stage_setting('store_name', trim($qm[1]));
    }
    // A settings-flavoured message no parser claimed falls through to the
    // normal intents below — never a dead end.
}

// --- What's Out REPORT lane (employee lane, Oct 28) ------------------------------
// A statement shaped "<item> out / sakiyo / khatam" — item named, NO
// question words — is a REPORT from the shelf, not a question: it
// stages a whats_out card under the signed-in person's name, and the
// confirm lands it on the store's What's Out record. The item is
// resolved FIRST and no count is ever extracted: in "Newport 100 box
// out" the 100 belongs to the product's NAME. Question shapes ("ke ke
// out chha?") stay the read in the stock lane below.
/* Intent before dispatch (audit GOL-01, Oct 30): the "out" inside
   "check out" is not an out-of-stock word, a negation scoping over
   the report ("do not check out", "report nagara") vetoes this lane,
   and a cart ADD ("Add two X to the current cart only") is never a
   report — the complete intent is resolved BEFORE a lane is chosen.
   Gate fix (Oct 30): the "out" inside "close out" is not an
   out-of-stock word either — the whole day-close phrase family
   ("close out", "close out the day", "end of day close out",
   "day close out", "closeout", "close the day") belongs to the
   close lane below, so those phrasings are blanked from the probe
   AND a close intent vetoes this lane outright, whichever way the
   wording arrives. */
$woProbe = (string) preg_replace('/(check\s*out|checkout|take\s+payment|close\s*out|closeout|close\s+the\s+day|end\s+of\s+day|day\s+close)/iu', ' ', $msg);
$woCloseIntent = (bool) preg_match('/(close\s*out|closeout|close\s+the\s+day|end\s+of\s+day|day\s+close)/iu', $msg);
$woNegated = (bool) preg_match('/(do\s*not|don\'?t|never|nagar|nagari|नगर)\b[^.]{0,40}\b(out|check|report|file)\b/iu', $msg);
$woCartAdd = (bool) (preg_match('/\bcart\b/iu', $msg)
    && preg_match('/(add|thap|rakh|jodo|lagao|put|hal|थप|राख|हाल)/iu', $msg));
/* What's Out READ intent (POS-D1, Oct 30): the out-of-stock QUESTION
   in its natural shapes — "ke ke out chha?", "what's out?", "show me
   the out list", "sabai out bhako ke ke chha?", "all out Sato" —
   carries an out-state word (out / आउट / sakiyo family / khatam)
   with question or list intent, or an aggregate (all / sabai / सबै)
   over NO named item. Those shapes missed the stock lane's gate
   words below and fell through to the composed brain, which falsely
   claimed it had no stock information; the aggregate shape was even
   misread by the report lane as a shelf report on an unmatched item.
   The read is the stock lane's own — this flag opens its gate and
   vetoes the report lane for the shape. The close family stays
   dominant (the probe has already blanked it, and $woCloseIntent
   vetoes outright), the negation / cart-add vetoes stand, and an
   aggregate over a NAMED item ("all Coke out") stays that item's
   REPORT — resolve_item is the arbiter, so a named report is never
   stolen by the read. */
$woReadIntent = false;
if (!$woNegated && !$woCartAdd && !$woCloseIntent
    && preg_match('/(\bout\b|आउट|sakiyo|sakiyeko|sakyo|sakina|khatam|सकियो|खतम)/iu', $woProbe)) {
    if (mb_strpos($msg, '?') !== false
        || preg_match('/(\bke\b|kei|kun|which|what|kati|kitna|how\s+many|list|dekha|show|herau|batau|के|कुन|कति|देखा)/iu', $msg)) {
        $woReadIntent = true;
    } elseif (preg_match('/\b(all|sabai)\b|सबै/iu', $msg)) {
        $woRes = resolve_item($products, $msg);
        $woReadIntent = empty($woRes['one']) && empty($woRes['many']);
    }
}
if (!$woNegated && !$woCartAdd && !$woCloseIntent && !$woReadIntent
    && !preg_match('/(ke\s+ke|kati|kitna|kun|which|list|dekha|how\s+many|कति|कुन)/iu', $msg)
    && mb_strpos($msg, '?') === false
    && preg_match('/(\bout\b|sakiyo|sakyo|khatam|khatiyo|सकियो|खतम)/iu', $woProbe)) {
    $actor = golpos_actor();
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        $card = golpos_stage(['type' => 'whats_out', 'sku' => (string)$p['sku'],
                              'name' => (string)($p['mr_name'] ?? $p['name']),
                              'by_email' => (string)($actor['email'] ?? ''),
                              'by_name' => (string)($actor['name'] ?? 'Register')]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'], $r['many']));
        out(['reply' => t('which_one', $list)]);
    }
    // Unknown item: say so, offer the closest shelf matches, file nothing.
    $toks = array_filter(preg_split('/[^a-z0-9]+/u', mb_strtolower($msg)) ?: [],
        static fn($w) => mb_strlen((string)$w) >= 3);
    $sug = [];
    foreach ($products as $p) {
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string)$p['name'])) ?: [] as $tok) {
            if ($tok !== '' && in_array($tok, $toks, true)) { $sug[] = (string)$p['name']; break; }
        }
        if (count($sug) >= 3) { break; }
    }
    out(['reply' => t('whatsout_unknown', $sug ? implode(', ', $sug) : '—')]);
}

// --- stock COUNT report lane (sibling of What's Out, Oct 28) ---------------------
// A statement carrying an item + a count number + a count word
// ("coke 20oz ko ginti 42 chha") stages a count card under the
// counter's name: counted qty, the system qty at this moment, the
// variance. The confirm files the count EVENT — the shelf stock is
// never edited by a count. The number is read only AFTER the product
// is resolved and its name masked, so "Newport 100 ko ginti 12" counts
// the ITEM Newport 100 at 12. Question shapes stay reads (stock lane).
if (!preg_match('/(kati|kitna|how\s+many|कति)/iu', $msg)
    && mb_strpos($msg, '?') === false
    && preg_match('/(ginti|ganti|count|ganne|गिन्ती|गन्ती|गणना)/iu', $msg)) {
    $actor = golpos_actor();
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        $work = golpos_mask_sizes(golpos_norm_digits($msg), $p);
        // The product's own name numbers are identity, not a count:
        // "Newport 100 ko ginti 12" — the message rarely repeats the
        // full shelf name ('Box'), so the size mask misses the 100 and
        // it would stand next to the real count. Blank every number
        // the resolved product's name carries.
        if (preg_match_all('/\d+(?:\.\d+)?/', (string)$p['name'], $nm)) {
            foreach (array_unique($nm[0]) as $nv) {
                $work = (string)preg_replace('/(?<![\d.])' . preg_quote($nv, '/') . '(?![\d.])/u', ' ', $work);
            }
        }
        $work = (string)preg_replace('/(ginti|ganti|count|ganne|ko|chha|cha|गिन्ती|गन्ती|गणना)/iu', ' ', $work);
        $vals = [];
        foreach (golpos_num_spans($work) as $n) { $vals[] = round((float)$n['value'], 2); }
        $vals = array_values(array_unique($vals));
        if (count($vals) === 1 && $vals[0] >= 0 && $vals[0] <= 99999) {
            $counted = $vals[0];
            $system = null;
            if (golpos_link_on()) {
                $st = mrstore_stock_of((string)($p['mr_name'] ?? $p['name']));
                if ($st !== null) { $system = $st['onHand']; }
            } elseif (isset($p['stock']) && is_numeric($p['stock'])) {
                $system = $p['stock'] + 0;
            }
            $card = golpos_stage(['type' => 'stock_count', 'sku' => (string)$p['sku'],
                                  'name' => (string)($p['mr_name'] ?? $p['name']),
                                  'counted' => $counted, 'system' => $system,
                                  'variance' => $system !== null ? round($counted - (float)$system, 2) : null,
                                  'by_email' => (string)($actor['email'] ?? ''),
                                  'by_name' => (string)($actor['name'] ?? 'Register')]);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        out(['reply' => t('count_ask')]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'], $r['many']));
        out(['reply' => t('which_one', $list)]);
    }
    out(['reply' => t('count_ask')]);
}

// --- stock lane (link + standalone, one set of semantics) -------------------------
// With MR.STORE connected, stock answers come from MR.STORE's live
// state; standing alone they come from the register's OWN shelf — the
// counts its tiles already show (alive audit #9/#15: standalone denied
// its own shelf and read a spoken delivery as a customer cart). A
// delivery stages the SAME receive card in both modes; only the
// executor's landing differs. This lane sits before the price and
// day-total lanes because "stock kati chha?" is a stock question.
if ($woReadIntent
    || preg_match('/(stock|maujud|on\s*hand|sakina|sakiyo|khatam|restock|received|delivery|ayo|aayo|ginti|स्टक|आयो)/iu', $msg)) {
    $link = golpos_link_on();
    $fmtq = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $onHandOf = static function (array $p) use ($link) {
        if ($link) { return isset($p['onHand']) && is_numeric($p['onHand']) ? $p['onHand'] + 0 : null; }
        return isset($p['stock']) && is_numeric($p['stock']) ? $p['stock'] + 0 : null;
    };
    // a receive: "<item> <qty> ayo" / "received 24 Coke" — staged, not instant
    if (preg_match('/(ayo|aayo|aaya|received|restock|delivery|आयो)/iu', $msg)
        && !preg_match('/(kati|kun|which|list|dekha|कति|कुन)/iu', $msg)) {
        $r = resolve_item($products, $msg);
        if (!empty($r['one'])) {
            $p = $r['one'];
            // The COUNT the owner said, by the shared meaning rules — never
            // the last number standing: in "12 ota lays ayo, $1.19 ma" the
            // count is 12 and the money stays out of the card entirely.
            $qty = golpos_extract_count(golpos_count_words($msg, $p), $p);
            if ($qty !== null && $qty > 0) {
                $card = golpos_stage(['type' => 'stock_receive', 'sku' => (string)$p['sku'],
                                      'name' => $p['mr_name'] ?? $p['name'], 'qty' => $qty]);
                out(['reply' => t('card_staged'), 'card' => $card]);
            }
        }
    }
    // out / running-low list (a READ — reports stage in the lane above)
    if (preg_match('/(sakina|sakiyo|sakiyeko|khatam|low|out|आउट|kun kun|कुन कुन)/iu', $msg)) {
        $rows = [];
        foreach ($products as $p) {
            $oh = $onHandOf($p);
            if ($oh === null) { continue; }
            if ((float)$oh <= 3) { $rows[] = [$p, $oh]; }
        }
        usort($rows, static fn($a, $b) => (float)$a[1] <=> (float)$b[1]);
        if (!$rows) { out(['reply' => t($link ? 'stock_low_none' : 'stock_low_none_local')]); }
        $lines = [t($link ? 'stock_low_head' : 'stock_low_head_local', $shop)];
        foreach ($rows as [$p, $oh]) {
            $lines[] = '• ' . $p['name'] . ' — ' . ((float)$oh <= 0 ? '0 (out)' : $fmtq($oh) . ' left');
        }
        out(['reply' => implode("\n", $lines)]);
    }
    // one item's stock
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        if ($link) {
            $st = mrstore_stock_of((string)($p['mr_name'] ?? $p['name']));
            if ($st === null) { out(['reply' => t('stock_link_down')]); }
            if ($st['onHand'] === null) { out(['reply' => t('stock_untracked', $st['name'])]); }
            golpos_verify_journal(['class' => 'stock_count', 'label' => (string)$st['name'],
                'figure' => (float)$st['onHand'], 'unit' => 'count',
                'inputs' => ['sku' => (string)($p['sku'] ?? ''), 'name' => (string)$st['name'], 'mode' => 'link'],
                'session' => (string)($GLOBALS['golpos_session_id'] ?? '')]);
            $reply = t('stock_one', $st['name'], $fmtq($st['onHand']));
            $tail = golpos_cal_notes_tail($st['name']);
            if ($tail !== '') { $reply .= "\n" . $tail; }
            out(['reply' => $reply, 'dcard' => ['type' => 'stock_list', 'display' => true, 'title' => 'Stock',
                'items' => [['name' => (string)$st['name'], 'qty' => (float)$st['onHand'],
                    'stock_state' => (float)$st['onHand'] <= 0 ? 'Out of stock' : ((float)$st['onHand'] <= 3 ? 'Low' : 'In stock')]]]]);
        }
        $oh = $onHandOf($p);
        if ($oh === null) { out(['reply' => t('stock_untracked_local', $p['name'])]); }
        golpos_verify_journal(['class' => 'stock_count', 'label' => (string)$p['name'],
            'figure' => (float)$oh, 'unit' => 'count',
            'inputs' => ['sku' => (string)($p['sku'] ?? ''), 'name' => (string)$p['name'], 'mode' => 'local'],
            'session' => (string)($GLOBALS['golpos_session_id'] ?? '')]);
        $reply = t('stock_one_local', $p['name'], $fmtq($oh));
        $tail = golpos_cal_notes_tail((string)$p['name']);
        if ($tail !== '') { $reply .= "\n" . $tail; }
        out(['reply' => $reply, 'dcard' => ['type' => 'stock_list', 'display' => true, 'title' => 'Stock',
            'items' => [['name' => (string)$p['name'], 'qty' => (float)$oh,
                'stock_state' => (float)$oh <= 0 ? 'Out of stock' : ((float)$oh <= 3 ? 'Low' : 'In stock')]]]]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'], $r['many']));
        out(['reply' => t('which_one', $list)]);
    }
    out(['reply' => t($link ? 'stock_notfound' : 'stock_notfound_local')]);
}

// --- day aggregates: best sellers + day compare (hisab D-12) -------------------
// "What sold best today?" and "compare yesterday and today" are answered
// from the register's OWN sales log, deterministically — the data was
// always on this box; the lane didn't exist. Week/month scopes stay with
// the deep-think lane below (the matchers refuse them).
if (golpos_is_bestseller_ask($msg) || golpos_is_compare_ask($msg)) {
    $today = date('Y-m-d');
    $yday = date('Y-m-d', strtotime('-1 day'));
    if (golpos_is_compare_ask($msg)) {
        $a = golpos_day_summary($yday);
        $b = golpos_day_summary($today);
        $diff = round((float)$b['gross'] - (float)$a['gross'], 2);
        $tail = $diff > 0 ? t('cmp_diff_up', 'Today', money($diff))
            : ($diff < 0 ? t('cmp_diff_down', 'Today', money(abs($diff))) : t('cmp_diff_even'));
        // The two bars ARE the two figures in the sentence — same reads.
        $graph = golpos_graph_payload('compare', t('graph_compare'), [
            ['label' => golpos_day_label($yday), 'value' => round((float)$a['gross'], 2), 'ymd' => $yday],
            ['label' => golpos_day_label($today), 'value' => round((float)$b['gross'], 2), 'ymd' => $today,
             'highlight' => true],
        ], round((float)$a['gross'] + (float)$b['gross'], 2));
        $graph = golpos_report_card($graph, [$yday, $today]);
        out(['reply' => t('cmp_line', golpos_day_label($yday), money((float)$a['gross']), (int)$a['txns'],
                          golpos_day_label($today), money((float)$b['gross']), (int)$b['txns'], $tail),
             'graph' => $graph]);
    }
    $ymd = preg_match('/(hijo|yesterday|हिजो)/iu', $msg) === 1 ? $yday : $today;
    $items = golpos_day_items($ymd);
    if (!$items) {
        out(['reply' => t('best_none', golpos_day_label($ymd)),
             'graph' => golpos_report_card(
                 golpos_graph_payload('hbars', t('graph_best', golpos_day_label($ymd)), [], 0.0), [$ymd])]);
    }
    $fmtTop = static function (array $list): string {
        return implode('; ', array_map(static fn($i) => $i['name'] . ' ×' . $i['qty']
            . ' (' . money((float)$i['rev']) . ')', $list));
    };
    $byQty = $items;
    usort($byQty, static fn($x, $y) => ($y['qty'] <=> $x['qty']) ?: ($y['rev'] <=> $x['rev']));
    $byRev = $items;
    usort($byRev, static fn($x, $y) => ($y['rev'] <=> $x['rev']) ?: ($y['qty'] <=> $x['qty']));
    // Horizontal bars from the SAME sorted list the sentence names: the
    // top-3-by-revenue figures in the text are bars 1–3, value for value.
    $topRev = array_slice($byRev, 0, 5);
    $series = array_map(static fn($i) => ['label' => (string)$i['name'], 'ymd' => $ymd,
        'value' => round((float)$i['rev'], 2), 'sub' => '×' . (int)$i['qty']], $topRev);
    $gTotal = 0.0; foreach ($series as $p) { $gTotal = round($gTotal + $p['value'], 2); }
    out(['reply' => t('best_line', golpos_day_label($ymd),
                      $fmtTop(array_slice($byQty, 0, 3)), $fmtTop(array_slice($byRev, 0, 3))),
         'graph' => golpos_report_card(
             golpos_graph_payload('hbars', t('graph_best', golpos_day_label($ymd)), $series, $gTotal), [$ymd])]);
}

// --- month report lane (owner bare-month law, Nov 1) -------------------------
// A month reference — named, "last month", or a bare month name — is a
// full report period: this lane answers from the month's own records
// (closes, bills, expenses), deterministically, before the deep-think
// lane can substitute today or the trailing week. A figure word picks
// the focus; a bare month gets the whole month card.
$monthRef = golpos_month_ref($msg);
$monthRefsAll = golpos_month_refs_all($msg);
$isCompareAsk = preg_match('/(compar|vs\.?|versus|than|against|difference|bhanda|भन्दा)/iu', $msg) === 1;
if ($isCompareAsk && $monthRefsAll) {
    $cmpA = $monthRefsAll[0];
    $cmpB = $monthRefsAll[1] ?? null;
    if ($cmpB === null) {
        // One month named + a comparison word: the other side is the
        // month right before it ("October vs last month" names the
        // same pair in the other order — resolve by BEFORE, not equal).
        $prev = (new DateTime($cmpA['ym'] . '-01'))->modify('-1 month');
        $cmpB = ['ym' => $prev->format('Y-m'), 'label' => $prev->format('F Y')];
        if ($cmpB['ym'] === $cmpA['ym']) { $cmpB = null; }
    }
    if ($cmpB !== null) {
        $sa = golpos_month_sales($cmpA['ym']); $pa = golpos_month_purchases($cmpA['ym']); $ea = golpos_month_expenses($cmpA['ym']);
        $sb = golpos_month_sales($cmpB['ym']); $pb = golpos_month_purchases($cmpB['ym']); $eb = golpos_month_expenses($cmpB['ym']);
        // Speak the LATER month first when the pair is out of order.
        if ($cmpB['ym'] > $cmpA['ym']) {
            [$cmpA, $cmpB] = [$cmpB, $cmpA];
            [$sa, $sb] = [$sb, $sa]; [$pa, $pb] = [$pb, $pa]; [$ea, $eb] = [$eb, $ea];
        }
        $dSales = round($sa['gross'] - $sb['gross'], 2);
        $pct = $sb['gross'] > 0 ? round($dSales / $sb['gross'] * 100, 1) : 0.0;
        $reply = $cmpA['label'] . " vs " . $cmpB['label'] . ".\n"
            . $cmpA['label'] . ": sales " . money($sa['gross']) . " across {$sa['txns']} sales (cash " . money($sa['cash']) . ", card " . money($sa['card']) . "); purchases " . money($pa['total']) . "; expenses " . money($ea['total']) . ".\n"
            . $cmpB['label'] . ": sales " . money($sb['gross']) . " across {$sb['txns']} sales (cash " . money($sb['cash']) . ", card " . money($sb['card']) . "); purchases " . money($pb['total']) . "; expenses " . money($eb['total']) . ".\n"
            . "Difference: sales " . ($dSales >= 0 ? 'up ' : 'down ') . money(abs($dSales))
            . " (" . ($dSales >= 0 ? '+' : '-') . abs($pct) . "% vs " . $cmpB['label'] . "); purchases "
            . ($pa['total'] >= $pb['total'] ? 'up ' : 'down ') . money(abs(round($pa['total'] - $pb['total'], 2)))
            . "; expenses " . ($ea['total'] >= $eb['total'] ? 'up ' : 'down ') . money(abs(round($ea['total'] - $eb['total'], 2))) . ".";
        $graph = golpos_graph_payload('compare', $cmpA['label'] . ' vs ' . $cmpB['label'], [
            ['label' => $cmpB['label'], 'value' => round($sb['gross'], 2), 'ymd' => $cmpB['ym'] . '-01'],
            ['label' => $cmpA['label'], 'value' => round($sa['gross'], 2), 'ymd' => $cmpA['ym'] . '-01', 'highlight' => true],
        ], round($sa['gross'] + $sb['gross'], 2));
        out(['reply' => $reply, 'graph' => $graph]);
    }
}
if ($monthRef !== null
    && ($monthRef['bare']
        || preg_match('/(sales|sale|bikri|total|jamma|purchase|purchases|kharid|expense|expenses|kharcha|report|hisab|hisaab|how much|kati|revenue|earning|bill)/iu', $msg))) {
    $ms = golpos_month_sales($monthRef['ym']);
    $mp = golpos_month_purchases($monthRef['ym']);
    $me = golpos_month_expenses($monthRef['ym']);
    $lbl = $monthRef['label'];
    $salesLine = "Sales: " . money($ms['gross']) . " across {$ms['txns']} sales (cash " . money($ms['cash']) . ", card " . money($ms['card']) . ").";
    $purchLine = $mp['bills'] > 0
        ? "Purchases: " . money($mp['total']) . " across {$mp['bills']} vendor bills"
          . ($mp['by_vendor'] ? " — " . implode('; ', array_map(static fn($v, $a) => $v . ' ' . money((float)$a), array_keys($mp['by_vendor']), array_values($mp['by_vendor']))) : "") . "."
        : "Purchases: no vendor bills recorded for {$lbl}.";
    $expLine = $me['count'] > 0
        ? "Expenses: " . money($me['total']) . " across {$me['count']} lines"
          . " — biggest: " . implode('; ', array_map(static fn($r) => (string)($r['label'] ?? 'Expense') . ' ' . money((float)($r['amount'] ?? 0)), array_slice($me['rows'], 0, 3))) . "."
        : "Expenses: none recorded for {$lbl}.";
    if (preg_match('/(purchase|purchases|kharid)/iu', $msg)) {
        $reply = "{$lbl} — purchases.\n{$purchLine}\n{$salesLine}";
    } elseif (preg_match('/(expense|expenses|kharcha)/iu', $msg)) {
        $reply = "{$lbl} — expenses.\n{$expLine}\n{$salesLine}";
    } else {
        $reply = "{$lbl} — the full month.\n{$salesLine}\n{$purchLine}\n{$expLine}";
    }
    $series = [];
    foreach ($ms['days'] as $ymd => $g) {
        $series[] = ['label' => date('j', strtotime($ymd)), 'value' => round((float)$g, 2), 'ymd' => $ymd];
    }
    $graph = golpos_graph_payload('spark', $lbl . ' — ' . $shop, $series, round($ms['gross'], 2));
    $graph = golpos_report_card($graph, array_keys($ms['days']));
    out(['reply' => $reply, 'graph' => $graph]);
}

// --- analysis / advice: the DEEP-THINK lane (Muse Wonder) --------------------
// Judgment questions leave the register floor and go to Wonder: why was
// today slow, how was the week, what should I do. The deterministic layer
// assembles the week's facts; Wonder composes the judgment; Spark composes
// if Wonder is unreachable; the template speaks if neither answers. Same
// grounding law: a reply is accepted only if it carries a real figure.
if (preg_match('/(why|how come|how was|how did|how is|किन|कस्तो|kasto|kina|hapta|हप्ता|week|month|mahina|महिना|suggest|advice|sujhab|sujhav|sallah|slow|trend|improv)/iu', $msg)) {
    [$facts, $figures, $weekTx, $weekGross, $todaySum, $monthGross, $monthTx, $monthCash, $monthCard] = golpos_week_facts($shop, $products);
    // The visual report for this lane, built ONCE from the same day
    // summaries the facts were assembled from: month asks draw the
    // month spark, week asks the 7-day bars. It rides every answer this
    // lane gives — composed or template — because it reports the same
    // range the words describe, from the same reads.
    $isMonthAsk = preg_match('/(month|mahina|महिना)/iu', $msg) === 1;
    if ($isMonthAsk) {
        [$mSeries, $mTotal] = golpos_graph_month_series();
        $graph = golpos_graph_payload('spark', t('graph_month'), $mSeries, $mTotal);
        $graph = golpos_report_card($graph, array_column($mSeries, 'ymd'));
    } else {
        [$wSeries, $wTotal] = golpos_graph_days_series(7, date('Y-m-d'));
        $graph = golpos_graph_payload('bars', t('graph_week'), $wSeries, $wTotal);
        $graph = golpos_report_card($graph, array_column($wSeries, 'ymd'));
    }
    if ($weekTx === 0 && $monthTx === 0) { out(['reply' => t('week_none'), 'graph' => $graph]); }
    $grounded = function (?string $reply) use ($figures): bool {
        if ($reply === null) { return false; }
        foreach ($figures as $f) { if (strpos($reply, $f) !== false) { return true; } }
        return false;
    };
    $reply = golpos_meta_compose($shop, $facts, $msg, golpos_wonder_model(), true);
    if ($grounded($reply)) { out(['reply' => $reply, 'graph' => $graph]); }
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($grounded($reply)) { out(['reply' => $reply, 'graph' => $graph]); }
    if ($isMonthAsk) { out(['reply' => t('month_line', money($monthGross), $monthTx, money($monthCash), money($monthCard)), 'graph' => $graph]); }
    out(['reply' => t('week_line', money($weekGross), $weekTx, money($todaySum['gross'])), 'graph' => $graph]);
}

// --- close the day: writes the day record ------------------------------------
// Close words in any of the shop's languages (close / banda / बन्द / क्लोज)
// end the day: the summary is frozen into a saved record the owner view
// reads back. This branch runs BEFORE the day-total branch so a close is
// never mistaken for a question about the total.
if (preg_match('/(close|banda|बन्द|क्लोज)/iu', $msg)) {
    $rec = golpos_close_day(date('Y-m-d'));
    $reply = t('close_done', money((float)$rec['gross']), (int)$rec['txns'], money((float)$rec['cash']), money((float)$rec['card']));
    // The close composes WITH the batches: today's sealed batches are named
    // (auto batches with their close time), and any card sales still outside
    // a batch are flagged, never silently skipped.
    foreach (($rec['batches'] ?? []) as $b) {
        $reply .= "\n" . (($b['closed_by'] ?? '') === 'auto'
            ? t('batch_auto_line', (int)$b['n'], golpos_time12((string)($b['close_time'] ?? '')),
                (int)$b['count'], money((float)$b['total']))
            : t('batch_line', (int)$b['n'], (int)$b['count'], money((float)$b['total'])));
    }
    if (($rec['unbatched_card']['count'] ?? 0) > 0) {
        $reply .= "\n" . t('close_unbatched', (int)$rec['unbatched_card']['count'],
                           money((float)$rec['unbatched_card']['total']));
    }
    // The day close ends the shift: the register returns to PIN-please
    // (employee lane) — the next person opens it in their own name.
    $closeSid = (string)($GLOBALS['golpos_session_id'] ?? '');
    if ($closeSid !== '') { golpos_lock_session($closeSid); }
    out(['reply' => $reply, 'close_record' => $rec]);
}

// --- named-day total (calendar tap + typed dated asks) -----------------------
// A report ask carrying an explicit ISO date answers for THAT day, from
// the same summary the today lane uses; the card draws the trailing week
// ENDING on the named day. Deterministic on purpose — dated asks used to
// fall through to the free brain, which waved them off to "today".
if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $msg, $dm)
    && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])
    && preg_match('/(report|hisab|hisaab|total|sales|bikri)/iu', $msg)) {
    $ymd = $dm[1] . '-' . $dm[2] . '-' . $dm[3];
    $s = golpos_day_summary($ymd);
    [$nSeries, $nTotal] = golpos_graph_days_series_to(7, $ymd);
    $graph = golpos_graph_payload('bars', date('D, M j', strtotime($ymd)), $nSeries, $nTotal,
        ['label' => golpos_day_label($ymd), 'value' => round((float)$s['gross'], 2)]);
    $graph = golpos_report_card($graph, [$ymd]);
    if ($s['txns'] === 0) {
        out(['reply' => t('named_day_none', golpos_day_label($ymd)), 'graph' => $graph]);
    }
    out(['reply' => t('named_day_total', golpos_day_label($ymd), money($s['gross']),
        $s['txns'], money($s['cash']), money($s['card'])), 'graph' => $graph]);
}

// --- day total ---------------------------------------------------------------
if (preg_match('/(kati bikri|kitni bikri|aaja ko|aaj ka|aaja kati|becheu|आज|हिसाब|बिक्री|today|close|banda|hisab|hisaab|sales|total)/iu', $msg)) {
    $s = golpos_day_summary(date('Y-m-d'));
    // The day's graph: the trailing 7 days with today lit — the same
    // day summaries this lane's own figure comes from. The focus pins
    // the asked day, so the bar under the sentence is the sentence's
    // number, value for value.
    [$dSeries, $dTotal] = golpos_graph_days_series(7, date('Y-m-d'));
    $graph = golpos_graph_payload('bars', t('graph_week'), $dSeries, $dTotal,
        ['label' => t('graph_today'), 'value' => round((float)$s['gross'], 2)]);
    // The day lane's sentence is about TODAY — its cash card sums today
    // alone (the bars still show the trailing week they always showed).
    $graph = golpos_report_card($graph, [date('Y-m-d')]);
    if ($s['txns'] === 0) {
        out(['reply' => t('day_none'), 'graph' => $graph]);
    }
    // Muse composes the day line from the exact figures; template if it can't.
    $facts = "Today's sales at {$shop} so far: total " . money($s['gross']) . " across {$s['txns']} sales"
        . " — cash " . money($s['cash']) . ", card " . money($s['card']) . "."
        . (golpos_link_on() ? " These are the POS till's own figures; stock figures live in MR.STORE." : "");
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($reply !== null && strpos($reply, money($s['gross'])) !== false) { out(['reply' => $reply, 'graph' => $graph]); }
    out(['reply' => t('day_total', money($s['gross']), $s['txns'], money($s['cash']), money($s['card']))
                    . (golpos_link_on() ? "\n" . t('src_till') : ''), 'graph' => $graph]);
}

// --- new item (staged) ---------------------------------------------------------
// "add new item Mango Lassi 3.25" / "naya saman Momo 6.99 ma thap" /
// "नयाँ सामान लस्सी 3.25": Gol stages a new-item card (name + price) that
// executes only on "gol". With no price in the message Gol asks for it —
// a price is never invented.
if (preg_match('/(new\s+(item|product)|naya\s+(saman|item)|नयाँ|नया\s+सामान)/iu', $msg)) {
    // The barcode is read FIRST and masked out of the working string, so
    // price and name extraction never see it; a code another product
    // already carries refuses the add outright, naming that product.
    $bcx = golpos_extract_barcode($msg, $products);
    if ($bcx['holder'] !== null) {
        out(['reply' => t('barcode_taken', (string)$bcx['holder']['name'])]);
    }
    $wmsg = $bcx['work'];
    // A question stages nothing here either (see the add lane's fence).
    if (golpos_is_info_question($msg)) {
        out(['reply' => t('question_no_stage')]);
    }
    $ex = golpos_extract_price($wmsg, null);
    if ($ex['status'] === 'ambiguous') {
        out(['reply' => t('price_ask_which_plain', money($ex['candidates'][0]), money($ex['candidates'][1]))]);
    }
    $price = $ex['status'] === 'ok' ? $ex['value'] : null;
    $name = golpos_item_name_from($wmsg, $price);
    if ($name !== '') {
        if ($price === null) { out(['reply' => t('new_ask_price', $name)]); }
        $card = golpos_stage(['type' => 'new_item', 'name' => $name, 'price' => $price,
                              'category' => 'Counter', 'barcode' => $bcx['barcode'] ?? '']);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
}

// --- price change (staged) ---------------------------------------------------
if (preg_match('/(mulya|price|dam|daam|मूल्य|दाम).*(gara|set|badha|badal|rakha|change|badlo|karo|गर|राख|बनाउ|बदल)|(gara|set|change|badlo|गर|बनाउ).*(mulya|price|dam|daam|मूल्य|दाम)/iu', $msg) ||
    preg_match('/(price|mulya|dam|daam|मूल्य|दाम).*\bto\s*(?:\$\s*\d|\d+\.\d{1,2})/iu', golpos_norm_digits($msg)) ||
    golpos_bare_price_change($msg, $products) ||
    golpos_amount_price_change($msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        golpos_ctx_set($p, 'price_change');
        // The price the owner SAID, by the meaning rules — the product is
        // matched first, its size is never money, and an explicit $ amount
        // always wins. No readable price, or two live candidates: Gol asks
        // and stages nothing — and the ask rides the session, so the
        // owner's answer completes the staging instead of starting over.
        $ex = golpos_extract_price($msg, $p);
        if ($ex['status'] === 'ambiguous') {
            golpos_price_await_set(['sku' => (string)$p['sku'], 'price_candidates' => $ex['candidates']]);
            out(['reply' => t('price_ask_which', $p['name'], money($ex['candidates'][0]), money($ex['candidates'][1]))]);
        }
        if ($ex['status'] !== 'ok') {
            golpos_price_await_set(['sku' => (string)$p['sku']]);
            out(['reply' => t('price_ask', $p['name'])]);
        }
        golpos_price_await_set(null);
        $new = $ex['value'];
        if ($new === (float)$p['price']) { out(['reply' => t('price_same', $p['name'], money($new))]); }
        $card = golpos_stage(['type' => 'price_change', 'sku' => $p['sku'], 'name' => $p['name'],
                              'old_price' => $p['price'], 'new_price' => $new]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if (!empty($r['many'])) {
        // The price was said but the item is a tie: remember the price, so
        // the owner's one-word pick completes the staging.
        $ex = golpos_extract_price($msg, null);
        if ($ex['status'] === 'ok') {
            golpos_price_await_set(['price' => $ex['value'],
                'item_skus' => array_map(static fn($c) => (string)$c['sku'], $r['many'])]);
        } else { golpos_price_await_set(null); }
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => t('which_price', $list)]);
    }
    // No item in the words — but the CONVERSATION may be carrying one.
    // A price change said right after an item was discussed binds to
    // that item (the card names it, and execution still waits for
    // "gol" + the PIN gate). With no topic in play, the price the owner
    // DID say is remembered and Gol asks for the item for real: the
    // ask arms the item wait (price_await), so the owner's one-word
    // answer completes the staging instead of starting a new puzzle.
    $ctxP = golpos_ctx_product($products);
    $ex = golpos_extract_price($msg, $ctxP);
    if ($ctxP !== null) {
        if ($ex['status'] === 'ambiguous') {
            golpos_price_await_set(['sku' => (string)$ctxP['sku'], 'price_candidates' => $ex['candidates']]);
            out(['reply' => t('price_ask_which', $ctxP['name'], money($ex['candidates'][0]), money($ex['candidates'][1]))]);
        }
        golpos_price_await_set(null);
        if ($ex['status'] === 'ok') {
            golpos_ctx_set($ctxP, 'price_change');
            if ($ex['value'] === (float)$ctxP['price']) {
                out(['reply' => t('price_same', $ctxP['name'], money($ex['value']))]);
            }
            $card = golpos_stage(['type' => 'price_change', 'sku' => $ctxP['sku'], 'name' => $ctxP['name'],
                                  'old_price' => $ctxP['price'], 'new_price' => $ex['value']]);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        // Only the price is missing: the topic is known, so ask for its
        // price by name and hold the wait on it.
        golpos_price_await_set(['sku' => (string)$ctxP['sku']]);
        out(['reply' => t('price_ask', $ctxP['name'])]);
    }
    if ($ex['status'] === 'ok') {
        golpos_price_await_set(['price' => $ex['value'], 'need_item' => true]);
        out(['reply' => t('price_ask_item', money($ex['value']))]);
    }
    if ($ex['status'] === 'ambiguous') {
        golpos_price_await_set(null);
        out(['reply' => t('price_ask_which_plain', money($ex['candidates'][0]), money($ex['candidates'][1]))]);
    }
    golpos_price_await_set(null);
    out(['reply' => t('not_found_price')]);
}

// --- price query --------------------------------------------------------------
if (preg_match('/(kati|kitna|kitne|price|mulya|dam|daam|how much|मूल्य|कति)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        golpos_ctx_set($p, 'price_query');
        golpos_verify_journal(['class' => 'price_shelf', 'label' => (string)$p['name'],
            'figure' => round((float)$p['price'], 2), 'unit' => 'money',
            'inputs' => ['sku' => (string)($p['sku'] ?? ''), 'name' => (string)$p['name'],
                         'mode' => golpos_link_on() ? 'link' : 'local'],
            'session' => (string)($GLOBALS['golpos_session_id'] ?? '')]);
        // Muse phrases the answer around the exact resolved price.
        $dcPrice = ['type' => 'price', 'display' => true, 'name' => (string)$p['name'],
            'price' => round((float)$p['price'], 2), 'store' => $shop,
            'onHand' => isset($p['onHand']) && is_numeric($p['onHand']) ? (float)$p['onHand'] : (isset($p['stock']) && is_numeric($p['stock']) ? (float)$p['stock'] : null)];
        $reply = golpos_meta_compose($shop, "Price fact: {$p['name']} costs " . money((float)$p['price']) . ". The owner asked its price.", $msg);
        if ($reply !== null && strpos($reply, money((float)$p['price'])) !== false) { out(['reply' => $reply, 'dcard' => $dcPrice]); }
        out(['reply' => $p['name'] . ' — ' . money((float)$p['price']) . '.', 'dcard' => $dcPrice]);
    }
    if (!empty($r['many'])) {
        foreach ($r['many'] as $cand) {
            golpos_verify_journal(['class' => 'price_shelf', 'label' => (string)$cand['name'],
                'figure' => round((float)$cand['price'], 2), 'unit' => 'money',
                'inputs' => ['sku' => (string)($cand['sku'] ?? ''), 'name' => (string)$cand['name'],
                             'mode' => golpos_link_on() ? 'link' : 'local'],
                'session' => (string)($GLOBALS['golpos_session_id'] ?? '')]);
        }
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        $dcList = ['type' => 'price_list', 'display' => true, 'store' => $shop,
            'items' => array_map(static fn($p) => ['name' => (string)$p['name'], 'price' => round((float)$p['price'], 2),
                'onHand' => isset($p['onHand']) && is_numeric($p['onHand']) ? (float)$p['onHand'] : (isset($p['stock']) && is_numeric($p['stock']) ? (float)$p['stock'] : null)], $r['many'])];
        $reply = golpos_meta_compose($shop, "Several products match the owner's words. Candidates with exact prices: {$list}. Ask which one they mean.", $msg);
        if ($reply !== null) {
            foreach ($r['many'] as $cand) {
                if (strpos($reply, money((float)$cand['price'])) !== false) { out(['reply' => $reply, 'dcard' => $dcList]); }
            }
        }
        out(['reply' => t('which_one', $list), 'dcard' => $dcList]);
    }
    out(['reply' => t('not_in_list')]);
}

// --- add to cart ---------------------------------------------------------------
if (preg_match('/(thap|add|ek |one |chahiyo|give|jodo|jod |lagao|थप|चाहियो)/iu', $msg) ||
    preg_match('/\d+(?:\.\d+)?\s*(ota|vata|वटा)\b/iu', golpos_norm_digits($msg))) {
    // The barcode is read FIRST and masked out of the working string:
    // resolution, price, count and name all run on what remains, so the
    // code is never money, never a quantity, never part of a name. It is
    // spent only if this sentence turns out to CREATE an item (below).
    $bcx = golpos_extract_barcode($msg, $products);
    $wmsg = $bcx['work'];
    // A question never catalogs and never rings. The Nov 2 ear test
    // watched the spoken question "What does your laptop show after
    // adding M&M's Peanut at $1.69? Is it in the cart now?" ride this
    // lane's price branch into a staged "New item" card named by the
    // question itself. Information questions are answered or honestly
    // not-caught — the write branches below are for imperatives.
    if (golpos_is_info_question($msg)) {
        out(['reply' => t('question_no_stage')]);
    }
    $r = resolve_item($products, $wmsg);
    $ex = golpos_extract_price($wmsg, null);
    /* A bare count before the item's name is a QUANTITY, never a price
       (audit GOL-01, Oct 30): "Add 2 Coke 20oz to the cart" used to
       stage a new-item card priced $2 named after the whole sentence,
       because the price reader claimed the count digit (a classifier
       "2 ota Coke" shielded it; the bare digit did not). When exactly
       one item resolves and the count reader binds that same number
       as the quantity, and no money marker ($) was said, the number
       is the count — the cataloging branch below sees no price. */
    if ($ex['status'] === 'ok' && !empty($r['one']) && strpos($wmsg, '$') === false) {
        $cnt = golpos_extract_count(golpos_count_words($wmsg, $r['one']), $r['one']);
        if ($cnt !== null && abs($cnt - (float) $ex['value']) < 0.001) {
            $ex = ['status' => 'none'];
        }
    }
    // A price in the sentence makes it a CATALOGING sentence. Ringing never
    // needs a price — the register knows it — so when the name the owner
    // said is not exactly a known item's own name or alias, the item is
    // NEW, however its words score against the shelf: "add sunrise energy
    // drink 12oz at $3.49" is not a confused Red Bull, and "AAA batteries"
    // is not the AA pack.
    if ($ex['status'] === 'ok' && (!empty($r['one']) || !empty($r['many']))) {
        $candName = golpos_item_name_from($wmsg, $ex['value']);
        $exact = false;
        foreach ((!empty($r['one']) ? [$r['one']] : $r['many']) as $cp) {
            foreach (array_merge([$cp['name']], $cp['aliases'] ?? []) as $nm) {
                if (golpos_names_equal((string)$nm, $candName)) { $exact = true; break 2; }
            }
        }
        if (!$exact && $candName !== '') {
            // Creating with a code another product already carries is
            // refused, naming that product — never staged as a duplicate.
            if ($bcx['holder'] !== null) {
                out(['reply' => t('barcode_taken', (string)$bcx['holder']['name'])]);
            }
            golpos_stage_new_item($candName, $ex['value'], $bcx['barcode'] ?? '');
        }
    }
    if (!empty($r['one'])) {
        // A count rides along only when the owner bound one ("2 ota coke"
        // -> 2). The product's SIZE is never a quantity: "coke 20oz thap"
        // adds one 20oz Coke.
        $qty = golpos_extract_count(golpos_count_words($wmsg, $r['one']), $r['one']);
        $n = $qty !== null && $qty > 0 ? (int)floor($qty) : 1;
        out(['reply' => ($n > 1 ? $n . ' × ' : '') . t('added_cart', $r['one']['name'], money((float)$r['one']['price'])),
             'action' => ['type' => 'add', 'sku' => $r['one']['sku'], 'qty' => $n]]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        out(['reply' => t('which_add', $list)]);
    }
    // unknown item + a price the owner actually said -> the new-item card
    // itself, staged (name keeps its sizes; the price is the $ amount or
    // the bound number, never a size). Two candidate prices -> ask.
    if ($ex['status'] === 'ambiguous') {
        out(['reply' => t('price_ask_which_plain', money($ex['candidates'][0]), money($ex['candidates'][1]))]);
    }
    if ($ex['status'] === 'ok') {
        $name = golpos_item_name_from($wmsg, $ex['value']);
        if ($name !== '') {
            if ($bcx['holder'] !== null) {
                out(['reply' => t('barcode_taken', (string)$bcx['holder']['name'])]);
            }
            golpos_stage_new_item($name, $ex['value'], $bcx['barcode'] ?? '');
        }
        out(['reply' => t('new_card_offer', money($ex['value']))]);
    }
    // A quantity with NO item in the breath ("Add twenty-four
    // pieces") binds to the item the conversation is already about —
    // the one Gol just answered for — instead of dead-ending. It fires
    // only when the words carry no item at all: once the add verb, the
    // count and the classifiers are stripped, nothing may remain. A
    // named item that failed to resolve still earns the honest
    // not-found below.
    $ctxP = golpos_ctx_product($products);
    if ($ctxP !== null) {
        $rest = golpos_count_words($wmsg, $ctxP);
        $rest = preg_replace('/(add|thap|jodo|lagao|give|please|ota|vata|pcs|pieces?|पिस|वटा|more|aru|the|a|an|of|na|ta)\b/iu', ' ', $rest) ?? $rest;
        $rest = preg_replace('/\d+(?:\.\d+)?/u', ' ', $rest) ?? $rest;
        if (trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $rest)) === '') {
            $cqty = golpos_extract_count(golpos_count_words($wmsg, $ctxP), $ctxP);
            if ($cqty !== null && $cqty > 0) {
                $n = (int) floor($cqty);
                out(['reply' => ($n > 1 ? $n . ' × ' : '') . t('added_cart', $ctxP['name'], money((float)$ctxP['price'])),
                     'action' => ['type' => 'add', 'sku' => $ctxP['sku'], 'qty' => $n]]);
            }
        }
    }
    out(['reply' => t('not_found_add')]);
}

// --- free-form: Muse composes from the shop's own live pack ------------------
// The deterministic intents above own every write; anything else is a
// conversation, and the conversation belongs to Muse — grounded in the live
// catalog + today's numbers, never free invention.
$sum = golpos_day_summary(date('Y-m-d'));
$pack = "Shop: {$shop}. Today so far: " . money($sum['gross']) . " across {$sum['txns']} sales"
    . " (cash " . money($sum['cash']) . ", card " . money($sum['card']) . ").\nCatalog (name — price): "
    . implode('; ', array_map(fn($p) => $p['name'] . ' — ' . money((float)$p['price']), $products));
$ctxTopic = golpos_ctx_product($products);
if ($ctxTopic !== null) {
    $pack .= "\nCurrently discussing: {$ctxTopic['name']} — " . money((float)$ctxTopic['price'])
        . ". Follow-ups ('it', 'tesko', a bare price change) are about this item.";
}
if (golpos_link_on()) {
    $pack .= "\nStock on hand (MR.STORE live): " . implode('; ', array_map(
        fn($p) => $p['name'] . ' — ' . (isset($p['onHand']) && $p['onHand'] !== null ? (string)(float)$p['onHand'] : 'not tracked'),
        $products)) . ". Sales totals above are the POS till's own figures.";
}
$pmPack = golpos_month_ref('last month');
if ($pmPack !== null) {
    $psP = golpos_month_sales($pmPack['ym']); $ppP = golpos_month_purchases($pmPack['ym']); $peP = golpos_month_expenses($pmPack['ym']);
    $pack .= "\nLast month (" . $pmPack['label'] . "), the full month from the day closes: sales " . money($psP['gross'])
        . " across {$psP['txns']} sales (cash " . money($psP['cash']) . ", card " . money($psP['card']) . "); purchases "
        . money($ppP['total']) . " across {$ppP['bills']} vendor bills; expenses " . money($peP['total'])
        . " across {$peP['count']} lines. A question naming that month is answered with THESE figures — never with today's.";
    $trendP = [];
    for ($k = 5; $k >= 1; $k--) {
        $tm = (new DateTime('first day of this month'))->modify("-{$k} months");
        $ts = golpos_month_sales($tm->format('Y-m'));
        if ($ts['txns'] === 0) { continue; }
        $tp = golpos_month_purchases($tm->format('Y-m')); $te = golpos_month_expenses($tm->format('Y-m'));
        $trendP[] = $tm->format('M Y') . ": sales " . money($ts['gross']) . ", purchases " . money($tp['total']) . ", expenses " . money($te['total']);
    }
    if ($trendP) { $pack .= "\nMonthly trend (full months): " . implode('; ', $trendP) . ". Comparisons between months use these figures."; }
}
$reply = golpos_meta_compose($shop, $pack, $msg);
if ($reply !== null) { out(['reply' => $reply]); }

out(['reply' => t('fallback')]);
} // end golpos_dispatch

// =============================================================================
// ACCESS LAYER — the admin PIN gate (owner directive, Oct 28)
// =============================================================================
// The register is one chat with two domains. CASHIER domain (never gated):
// ringing sales, add-by-name, price queries, today's running total, poster
// creation, settings READS and the STAGING of any card. ADMIN domain: report
// questions beyond the running day total, the day-close record, batch close,
// employee management, the loyalty leaderboard — and the EXECUTION of staged
// price / new-item / setting / batch / remove cards, which is gated at the
// confirm word.
//
// The gate runs before the dispatch and classifies with the dispatch's own
// matchers, in the dispatch's own order, so a message is gated exactly as it
// would be answered. An admin message from an unelevated session is never
// answered and never hinted at: Gol asks for the PIN and nothing else, keeps
// the original message server-side, and replays it through the dispatch the
// moment the PIN verifies — one reply, figures and all. A typed PIN is
// consumed by the gate, never dispatched, never echoed (the client masks
// the bubble on pin_mask), and verified with password_verify. Three wrong
// PINs lock elevation for 10 minutes.
function golpos_needs_admin(string $msg, array $in): bool {
    if (in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true) || !empty($in['confirm'])) {
    if (golpos_echo_confirm($msg, $in)) { out(['reply' => 'That was my own voice. The card is still waiting. Tap Confirm after I am quiet.']); }

        $pend = golpos_pending();
        // A refund confirm is an admin execution exactly when the
        // store's refund_pin setting says so (default ON); with the
        // setting off, the open session's word is enough — the same
        // rule the Transactions sheet's PIN gate follows.
        if ($pend !== null && ($pend['type'] ?? '') === 'refund') {
            return !empty(golpos_settings()['refund_pin']);
        }
        return $pend !== null && in_array($pend['type'] ?? '', [
            'price_change', 'new_item', 'setting_change', 'batch_close', 'employee_remove', 'employee_add', 'pin_set',
            'stock_receive', 'bill', 'order_prep', 'vendor_teach', 'task_assign',
            'pay_approve', 'rate_change',
            // Price-gap fills (Oct 28) execute through the price lane's
            // own gate: a fill is a price/cost write, so its confirm
            // demands the admin PIN exactly as a price change does.
            'price_fill', 'cost_fill',
        ], true);
        // pay_send is deliberately absent: her confirm sends HER OWN
        // request up — cashier-domain, like the credit-attach card.
    }
    if (preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg)) { return true; }
    // A refund ask is never an admin ask and never a price ask — it has
    // its own honest lane (alive audit #53). Staging is cashier-domain;
    // the refund card's EXECUTION is gated above, at the confirm word.
    if (golpos_is_refund_ask($msg)) { return false; }
    // A credit balance read is a cashier-domain read, like a price ask.
    if (golpos_is_credit_ask($msg)) { return false; }
    // Pay lane (Oct 28): the owner's rate change and his request work
    // are admin asks; HER pay ask is cashier-domain — her own hours,
    // her own rate, her own total, like her tasks and her note back.
    // Rate change first: "Pranisha ko pay 13" names her but is HIS act.
    if (golpos_parse_rate_change($msg) !== null) { return true; }
    if (golpos_is_pay_requests_ask($msg)) { return true; }
    if (golpos_is_pay_ask($msg)) { return false; }
    // PIN set/reset (TASKS 231): the owner's act alone — an unelevated
    // session meets the PIN demand before the lane can even stage, and
    // the staged card's confirm is gated by the type list above.
    if (golpos_is_pin_set_intent($msg)) { return true; }
    // The owner's team domain (employee lane): assigning work, reading
    // a person's notes, team progress, and the presence read are admin
    // asks. The employee's own lanes (her tasks, her note back, her
    // reports and counts) are NOT — they run in the cashier domain.
    if (golpos_parse_task_assign($msg) !== null) { return true; }
    if (golpos_parse_note_ask($msg) !== null || golpos_is_progress_ask($msg)) { return true; }
    $presenceWhoGate = golpos_parse_presence_ask($msg);
    if ($presenceWhoGate !== null && golpos_employee_resolve($presenceWhoGate) !== null) { return true; }
    // AI Calendar + Notes: her OWN calendar and notes (read, create,
    // undated note) are cashier-domain, like her tasks and her note
    // back; a calendar read naming another person, or a create naming
    // one, is the boss's domain — persona surfaces belong to their
    // sessions, and the wall is the owner's PIN.
    if (preg_match('/(mero|my)\s+(calendar|calender|notes?\b)/iu', $msg)) { return false; }
    if (preg_match('/\bnotes?\s*(haru\s+)?(dekha|show|list)\b/iu', $msg)) { return false; }
    if (preg_match('/(note\s*(rakha|gara|lekha))\b/iu', $msg)
        && golpos_cal_parse_create($msg) === null) { return false; }
    $calGate = golpos_cal_parse_read($msg);
    if ($calGate !== null && !in_array($calGate['who'], ['self', 'store'], true)
        && golpos_employee_resolve($calGate['who']) !== null) { return true; }
    $calCreateGate = golpos_cal_parse_create($msg);
    if ($calCreateGate !== null && ($calCreateGate['persona'] ?? '') !== ''
        && golpos_employee_resolve($calCreateGate['persona']) !== null) { return true; }
    // Small talk is never an admin ask — "how is your day" greets, it
    // does not request a report (the shared matcher rules the dispatch
    // with the same shape).
    if (golpos_is_smalltalk($msg)) { return false; }
    if (preg_match('/(receipt|print|bill|paper).{0,30}settings|settings.{0,30}(receipt|print|bill)|settings\s+(ke|kasto)/iu', $msg)) { return false; }
    // Day aggregates over the till's own log (best sellers, day compare)
    // are the running day total's family — cashier-domain reads, never
    // gated (hisab D-12).
    if (golpos_is_bestseller_ask($msg) || golpos_is_compare_ask($msg)) { return false; }
    if (golpos_is_hours_read($msg) || golpos_is_batch_mode_set($msg) || golpos_is_hours_set($msg)) { return false; }
    if (golpos_is_batch_close_intent($msg)) { return true; }
    if (golpos_is_employee_intent($msg)) { return true; }
    if (golpos_is_loyalty_board_intent($msg)) { return true; }
    if (golpos_is_report_intent($msg)) { return true; }
    if (preg_match('/(receipt|bill|print|paper|footer|logo|tax|auto|cop(y|ies)|phone|address|store name|फोन|ठेगाना|लोगो|कर)/iu', $msg)) { return false; }
    if (preg_match('/(why|how come|how was|how did|how is|किन|कस्तो|kasto|kina|hapta|हप्ता|week|month|suggest|advice|sujhab|sujhav|sallah|slow|trend|improv)/iu', $msg)) { return true; }
    if (preg_match('/(close|banda|बन्द|क्लोज)/iu', $msg)) { return true; }
    return false;
}

// =============================================================================
// BIG BRAIN BRIDGE (owner directive, Oct 30) — "first class dimaag"
// =============================================================================
// The till's Gol panel used to run on this file's own register brain: a
// second, smaller brain that read only the POS-local sale journal, so on
// a linked store it answered "This week's sales" with "No sales recorded
// in the last 7 days yet" while the store's Books held a Z close every
// night, answered a yesterday ask with today's empty journal, and read
// a stock-state question ("all out chha ta?") as a product lookup. The
// owner caught all three live on the Lucky till.
//
// There is ONE Gol brain: store-api/gol-chat.php (gol_turn — Meta brain,
// live context pack, Vendor AI, the Books closes, the staged-card law).
// This bridge routes every STORE-KNOWLEDGE turn from the panel (typed
// chat and the Call loop alike — both arrive here as brain.php turns) to
// gol_turn with the POS surface identified, and translates the answer
// back into this panel's shapes (reply, a staged-card rendering, the
// report graph + month calendar drawn from the same Books closes).
// Genuinely register-local work stays in golpos_dispatch: ring control
// (add to cart), price queries against the shelf, receipt/print
// settings, hours/batch, camera, credit attach, refunds, the employee
// lanes (tasks / pay / presence), order prep, and the till's own staged
// cards (price changes stage through the price lane and its waits).
//
// Caller identity follows the till session: the admin PIN's owner is
// the store owner; an unlocked employee speaks as that employee (the
// big brain's own staff fences apply — a staff "gol" is held there,
// exactly as on every other surface); on a roster-less till the session
// is the owner's. If the big brain cannot take the turn (link off,
// caller unknown to the store, bridge failure), the till brain answers
// as before — the register never goes mute.

function golpos_bigbrain_cfg(): ?array {
    if (!golpos_link_on()) { return null; }
    $set = mrstore_settings();
    if ($set === null || empty($set['owner'])) { return null; }
    $cfg = golpos_link_config();
    return ['store_id' => (string)$cfg['store_id'], 'api_dir' => (string)$cfg['api_dir'],
            'owner' => (string)$set['owner'], 'name' => (string)($set['name'] ?? '')];
}

function golpos_bigbrain_caller(array $cfg, array $session): ?string {
    if (!empty($session['admin']) || (string)($session['unlocked_by'] ?? '') === 'admin') {
        return $cfg['owner'];
    }
    $email = (string)($session['unlocked_by'] ?? '');
    if ($email === '' && !empty($session['employee'])) { $email = (string)$session['employee']; }
    if ($email !== '') { return $email; }
    // No identity on the session: only possible when the till has no
    // PIN roster (the lock gate owns every other road here) — the
    // session is the owner's own till session.
    if (!golpos_roster_has_pin()) { return $cfg['owner']; }
    return null;
}

function golpos_bigbrain_turn(array $cfg, string $email, array $input): ?array {
    $cmd = 'php ' . escapeshellarg($cfg['api_dir'] . '/gol-chat.php')
         . ' --email=' . escapeshellarg($email)
         . ' --store=' . escapeshellarg($cfg['store_id'])
         . ' --surface=pos';
    if (!empty($input['action'])) { $cmd .= ' --action=' . escapeshellarg((string)$input['action']); }
    if (!empty($input['pending_pid'])) { $cmd .= ' --pid=' . escapeshellarg((string)$input['pending_pid']); }
    if (isset($input['text']) && (string)$input['text'] !== '') {
        $cmd .= ' --text=' . escapeshellarg((string)$input['text']);
    }
    $raw = shell_exec($cmd . ' 2>/dev/null');
    if (!is_string($raw) || trim($raw) === '') { return null; }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        $a = strpos($raw, '{'); $b = strrpos($raw, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $j = json_decode(substr($raw, $a, $b - $a + 1), true);
        }
    }
    return is_array($j) ? $j : null;
}

// The report graph for a bridged sales answer: trailing-7-day bars +
// the month calendar, computed from the store's Books closes (the
// gol-reads lane — the same parsing as Owner Pulse), never from the
// till's own journal. Returns null when the store has no closes on
// record in the current month (the text answer stands alone).
function golpos_bigbrain_graph(array $cfg, string $msg): ?array {
    try {
        $apiDir = $cfg['api_dir'];
        if (!function_exists('golr_closes')) {
            if (!is_file($apiDir . '/gol-reads.php')) { return null; }
            require_once $apiDir . '/gol-reads.php';
        }
        $store = golr_store_by_id($cfg['store_id']);
        if ($store === null) {
            $store = ['slug' => golr_slug_for($cfg['store_id']), 'id' => $cfg['store_id'],
                      'name' => $cfg['name'] !== '' ? $cfg['name'] : 'Store'];
        }
        /* Store binding (audit GOL-05, Oct 30): the text answer binds to
           the store the question NAMES — golr_store_mention is the same
           door the big brain's read lane (golr_answer) uses — so the
           graph, cash card, calendar and focus must bind to that same
           store, never silently to the till's own. No mention in the
           message -> the till's store, as before. */
        $mention = function_exists('golr_store_mention') ? golr_store_mention($msg) : null;
        if ($mention !== null) { $store = $mention; }
        $today = golr_today();
        $todayStr = $today->format('Y-m-d');
        $monthRows = golr_closes($store, $today->format('Y-m-01'), $todayStr);
        if (!$monthRows) { return null; }
        $ws = (clone $today)->modify('-6 days');
        $series = []; $wkTotal = 0.0; $wkCash = 0.0; $wkCard = 0.0; $wkNights = 0;
        $wkExp = 0.0; $wkTax = 0.0; $wkCounted = 0.0; $wkCountedN = 0;
        $wkOS = 0.0; $wkOSN = 0; $adjDates = [];
        $focusDate = golr_parse_date($msg, $todayStr);
        $isPeriodAsk = (bool)preg_match('/(week|hapta|हप्ता|month|mahina)/iu', $msg);
        for ($i = 0; $i < 7; $i++) {
            $d = (clone $ws)->modify('+' . $i . ' days')->format('Y-m-d');
            $r = $monthRows[$d] ?? null;
            $g = $r !== null ? (float)$r['gross'] : 0.0;
            $wkTotal += $g;
            if ($r !== null) {
                $wkCash += (float)($r['cash'] ?? 0); $wkCard += (float)($r['card'] ?? 0); $wkNights++;
                $expC = isset($r['expectedCash']) ? (float)$r['expectedCash']
                      : (float)$r['gross'] - (float)($r['card'] ?? 0);
                $wkExp += $expC; $wkTax += (float)($r['tax'] ?? 0);
                $cnt = $r['counted'] ?? null;
                $osv = $r['overShort'] ?? null;
                if ($cnt !== null) { $wkCounted += (float)$cnt; $wkCountedN++; }
                if ($osv !== null) { $wkOS += (float)$osv; $wkOSN++; }
                if ($cnt !== null && $osv !== null
                    && abs((float)$cnt - ($expC + (float)$osv)) > 0.01) {
                    $adjDates[] = (new DateTime($d))->format('M j');
                }
            }
            $series[] = ['label' => (new DateTime($d))->format('D'), 'value' => round($g, 2),
                         'highlight' => $focusDate !== null && $focusDate === $d && !$isPeriodAsk];
        }
        $graph = ['kind' => 'bars', 'title' => 'Last 7 days — ' . (string)($store['name'] ?? 'Store'), 'unit' => 'money',
                  'series' => $series, 'total' => round($wkTotal, 2),
                  'empty' => $wkTotal <= 0 && $wkNights === 0];
        if ($graph['empty']) { $graph['empty_text'] = 'No closes in the last 7 days yet.'; }
        /* Tender reconciliation (audit GOL-07, Oct 30): the closes'
           recorded cash column mixes bases night to night (the counted
           cash some nights, the register split others), so cash + card
           alone need not tie to the graph total. The block therefore
           also carries the expected cash (gross - card per night — the
           split that always ties), the counted cash and the recorded
           over/short from the SAME rows, plus one plain line that ties
           the tender block to the total and names any night whose count
           was booked against an adjusted close. */
        $recon = null;
        if ($wkNights > 0) {
            $recon = 'Ties to the total: Card ' . golr_money($wkCard)
                   . ' + expected cash ' . golr_money($wkExp)
                   . ' = ' . golr_money($wkTotal) . '.';
            if ($wkCountedN > 0) {
                $recon .= ' Counted cash ' . golr_money($wkCounted)
                        . ' (' . $wkCountedN . ' of ' . $wkNights . ' nights)';
                if ($wkOSN > 0) {
                    $recon .= ' · over/short as recorded '
                            . ($wkOS < -0.001 ? '-' : ($wkOS > 0.001 ? '+' : '')) . golr_money(abs($wkOS));
                }
                $recon .= '.';
            }
            if ($adjDates) {
                $recon .= ' ' . (count($adjDates) === 1
                    ? $adjDates[0] . "'s count was booked against an adjusted close — its day card shows both figures."
                    : 'Some counts (' . implode(', ', $adjDates) . ') were booked against adjusted closes — their day cards show both figures.');
            }
            if ($wkTax > 0) { $recon .= ' Tax collected in these closes: ' . golr_money($wkTax) . '.'; }
        }
        $graph['cash'] = ['cash' => round($wkCash, 2), 'card' => round($wkCard, 2),
                          'total' => round($wkTotal, 2), 'txns' => $wkNights, 'split' => true,
                          'cash_lbl' => t('cash_lbl'), 'card_lbl' => t('card_lbl'),
                          'credit_lbl' => t('credit_lbl'),
                          'expected' => round($wkExp, 2),
                          'counted' => $wkCountedN > 0 ? round($wkCounted, 2) : null,
                          'counted_n' => $wkCountedN,
                          'os' => $wkOSN > 0 ? round($wkOS, 2) : null,
                          'os_n' => $wkOSN,
                          'tax' => round($wkTax, 2),
                          'nights' => $wkNights,
                          'recon' => $recon];
        // month calendar from the same closes
        $first = $today->format('Y-m-01');
        $n = (int)$today->format('t');
        $days = [];
        for ($d = 1; $d <= $n; $d++) {
            $ymd = $today->format('Y-m-') . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $r = $monthRows[$ymd] ?? null;
            $days[] = ['d' => $d, 'ymd' => $ymd,
                       'value' => $r !== null ? round((float)$r['gross'], 2) : 0.0,
                       'txns' => $r !== null ? 1 : 0,
                       'has' => $r !== null, 'today' => $ymd === $todayStr];
        }
        $graph['calendar'] = ['month' => $today->format('F Y'),
                              'lead' => (int)(new DateTime($first))->format('w'),
                              'dows' => array_map('trim', explode(',', t('cal_dows'))),
                              'days' => $days, 'hint' => t('cal_hint')];
        if ($focusDate !== null && !$isPeriodAsk && isset($monthRows[$focusDate])) {
            $graph['focus'] = ['label' => (new DateTime($focusDate))->format('D, M j'),
                               'value' => round((float)$monthRows[$focusDate]['gross'], 2)];
        }
        // A calendar-day tap fetches its day detail silently for THIS
        // store (the one the question named) — the panel reads this
        // binding back instead of sending a chat message.
        $graph['cal_store'] = (string)$store['id'];
        $graph['cal_store_name'] = (string)$store['name'];
        return $graph;
    } catch (Throwable $e) {
        return null;
    }
}

/* The card's own Cancel button (audit GOL-04): it names the exact
   card by id — the till's own pending, or the big brain's staged card
   this till is showing. Anything else is already dead: the UI marks
   it cancelled and nothing anywhere is touched. */
function golpos_cancel_card_handle(array $in, array $session, string $sessionId): bool {
    if (empty($in['cancel_card'])) { return false; }
    $pid = trim((string)($in['pending_pid'] ?? ''));
    if ($pid === '') { return false; }
    $pend = golpos_pending();
    if ($pend !== null && (string)($pend['id'] ?? '') === $pid) {
        golpos_clear_pending();
        out(['reply' => t('card_cancelled'), 'cancelled' => true, 'cancelled_pid' => $pid]);
    }
    $sess = golpos_session($sessionId);
    if (!empty($sess['gol_card']) && (string)($sess['gol_card_pid'] ?? '') === $pid) {
        $cfg = golpos_bigbrain_cfg();
        $email = $cfg !== null ? golpos_bigbrain_caller($cfg, $session) : null;
        if ($cfg !== null && $email !== null) {
            $res = golpos_bigbrain_turn($cfg, $email, ['action' => 'cancel', 'pending_pid' => $pid]);
            if (is_array($res) && !empty($res['ok'])) {
                $sess['gol_card'] = false; $sess['gol_card_pid'] = '';
                golpos_save_session($sessionId, $sess);
                out(['reply' => (string)($res['reply'] ?? t('card_cancelled')), 'cancelled' => true, 'cancelled_pid' => $pid]);
            }
        }
        /* the big brain could not be reached: its card may still be
           armed there — say so, do not paint this one dead. */
        out(['reply' => t('card_not_live'), 'cancelled_pid' => $pid]);
    }
    out(['reply' => t('card_not_live'), 'cancelled' => true, 'cancelled_pid' => $pid]);
    return true; // unreachable — out() exits
}

// May the big brain take this turn? Register-local lanes keep their
// turns; everything else is store knowledge and goes up.
function golpos_bigbrain_claims(string $msg, array $in, array $session): bool {
    $norm = mb_strtolower(trim($msg));
    $golCard = !empty($session['gol_card']);
    $posPending = golpos_pending() !== null;
    $isConfirmWord = in_array($norm, ['gol', 'गोल'], true);
    $isCancelWord = golpos_is_cancel_intent($msg);
    if (!empty($in['confirm']) || $isConfirmWord || $isCancelWord) {
        // The till's own staged card executes first (it is the card
        // physically in front of the cashier); a big-brain card staged
        // earlier keeps waiting in the store's own pending, where the
        // next "gol" reaches it once the till card is done.
        if ($posPending) { return false; }
        return $golCard;
    }
    // A bare greeting cancels a staged big-brain card (the big brain's
    // own greeting law) — it must reach the big brain to do so.
    if ($golCard && golpos_is_bare_greeting($msg)) { return true; }
    // While the till's own card is staged, its guard owns the chat.
    if ($posPending) { return false; }
    // --- register-local quick lanes (the till's own work) ---
    // The PIN setup word itself ("admin") is the till's own vocabulary —
    // the creation flow replays it after the PIN is set, and it must
    // land in the till brain exactly as it always has.
    if (preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg)) { return false; }
    if (golpos_is_smalltalk($msg)) { return false; }
    if (golpos_is_sale_add_ask($msg)) { return false; }
    if (golpos_is_refund_ask($msg) || golpos_is_credit_ask($msg)) { return false; }
    if (golpos_is_bestseller_ask($msg) || golpos_is_compare_ask($msg)) { return false; }
    if (golpos_is_hours_read($msg) || golpos_is_batch_mode_set($msg)
        || golpos_is_hours_set($msg) || golpos_is_batch_close_intent($msg)) { return false; }
    if (golpos_parse_rate_change($msg) !== null || golpos_is_pay_ask($msg)
        || golpos_is_pay_requests_ask($msg)) { return false; }
    if (golpos_parse_task_assign($msg) !== null || golpos_is_my_tasks_ask($msg)) { return false; }
    if (preg_match('/(receipt|print|bill|paper).{0,30}settings|settings.{0,30}(receipt|print|bill)/iu', $msg)) { return false; }
    /* a capability question is answered by the till itself (audit
       GOL-01) — never bridged, never a workflow lane's keyword. */
    if (golpos_is_capability_question($msg)) { return false; }
    /* "low stock" is the register's own shelf threshold list (audit
       GOL-06) — never the bridge's What's Out report answer. */
    if (preg_match('/(low\s+stock|stock\s+low|running\s+low|sakina\s+lageko)/iu', $msg)) { return false; }
    if (preg_match('/(poster|banner)/iu', $msg)) { return false; }
    if (preg_match('/(camera|क्यामेरा|surcharge|watch\s*frame)/iu', $msg)) { return false; }
    if (golpos_is_employee_intent($msg) || golpos_is_loyalty_board_intent($msg)) { return false; }
    if (golpos_is_teach_ask($msg) || golpos_is_order_prep_ask($msg)
        || golpos_is_order_given($msg) || golpos_is_repday_ask($msg)) { return false; }
    // A stock RECEIVE is a till act (a delivery at the counter, staged
    // by the stock lane) — the same shape the stock lane itself uses.
    if (preg_match('/(ayo|aayo|aaya|received|restock|delivery|आयो)/iu', $msg)
        && !preg_match('/(kati|kun|which|list|dekha|कति|कुन)/iu', $msg)) { return false; }
    // An employee's out / count REPORT names its item and is not a
    // question — the What's Out + count report lanes file it. A
    // stock-state QUESTION (what's out / all out / ke sakiyo) is store
    // knowledge and falls through to the claim below.
    if (!preg_match('/(kati|\bke\s|kun|which|what|list|dekha|\?|चाहियो)/iu', $msg)
        && preg_match('/(out|saki|sakiyo|sakyo|khatam)/iu', $msg)) {
        $rr = resolve_item($GLOBALS['golpos_products'] ?? [], $msg);
        if (!empty($rr['one'])) { return false; }
    }
    // Named-day report asks: a PAST day belongs to the Books closes;
    // today's own day stays with the till's running total.
    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $msg, $dm)
        && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
        $ymd = $dm[1] . '-' . $dm[2] . '-' . $dm[3];
        if ($ymd === date('Y-m-d')) { return false; }
        return (bool)preg_match('/(report|hisab|hisaab|total|sales|bikri|close)/iu', $msg);
    }
    // Add-to-cart + new-item sentences are ring work, never knowledge.
    if (preg_match('/(thap|add|ek |one |chahiyo|give|jodo|jod |lagao|थप|चाहियो)/iu', $msg)
        || preg_match('/\d+(?:\.\d+)?\s*(ota|vata|वटा)\b/iu', golpos_norm_digits($msg))) { return false; }
    // A price QUESTION that resolves to a shelf item is the register's
    // fastest lane (the same catalog truth); one that resolves to no
    // item was never a shelf question ("Seaway ko bill kati?") and
    // belongs to the big brain.
    if (preg_match('/(kati|kitna|kitne|price|mulya|dam|daam|how much|मूल्य|कति)/iu', $msg)) {
        $r = resolve_item($GLOBALS['golpos_products'] ?? [], $msg);
        if (!empty($r['one']) || !empty($r['many'])) { return false; }
    }
    return true;
}

// Run one turn through the big brain and answer in this panel's shapes.
// Returns true when the turn was answered there (out() already ran);
// false hands the turn back to the till brain untouched.
function golpos_bigbrain_handle(string $msg, array $in, array $session, string $sessionId, string $shop): bool {
    $cfg = golpos_bigbrain_cfg();
    if ($cfg === null) { return false; }
    if (!golpos_bigbrain_claims($msg, $in, $session)) { return false; }
    $email = golpos_bigbrain_caller($cfg, $session);
    if ($email === null) { return false; }
    $isConfirm = !empty($in['confirm'])
        || in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true);
    $turnIn = $isConfirm ? ['action' => 'confirm'] : ['text' => $msg];
    if (!empty($in['pending_pid'])) { $turnIn['pending_pid'] = (string)$in['pending_pid']; }
    $res = golpos_bigbrain_turn($cfg, $email, $turnIn);
    if ($res === null || empty($res['ok'])) { return false; }
    $reply = (string)($res['reply'] ?? '');
    $pending = $res['pending'] ?? null;
    $cardIn = (isset($res['card']) && is_array($res['card'])) ? $res['card'] : null;
    // keep the till session's gol-card flag in step with the store's
    // own pending (the flag only routes future confirms; the truth
    // lives in gol-chat's state).
    $sess2 = golpos_session($sessionId);
    $had = !empty($sess2['gol_card']);
    $has = is_array($pending) && $pending !== [];
    $newPid = $has ? (string)($pending['pid'] ?? '') : '';
    if ($had !== $has || (string)($sess2['gol_card_pid'] ?? '') !== $newPid) {
        $sess2['gol_card'] = $has;
        $sess2['gol_card_pid'] = $newPid;
        golpos_save_session($sessionId, $sess2);
    }
    $resp = [];
    if ($cardIn !== null && $has) {
        // A staged card: the panel renders it as one card (its lines
        // are the reply's own lines — the same card text the MR.STORE
        // chat shows), and the card's Confirm button carries the
        // confirm, so no separate reply bubble repeats it.
        $lines = [];
        foreach (preg_split('/\r?\n/', $reply) as $ln) {
            $ln = trim((string)$ln);
            if ($ln !== '') { $lines[] = $ln; }
        }
        $resp['card'] = ['type' => 'gol_stage',
                         'pid' => (string)($pending['pid'] ?? ''),
                         'title' => (string)($pending['summary'] ?? 'Staged — Gol'),
                         'lines' => array_slice($lines, 0, 14)];
        $resp['reply'] = '';
    } else {
        $resp['reply'] = $reply;
        /* Lane 4 (Oct 31): a DESIGNED display card from the big brain
           (price / overview / stock list / vendor profile) rides through
           as 'dcard' and the panel renders it under the reply — the same
           envelope the backend chat uses, figures untouched. Staged
           cards keep the gol_stage path above; a display card is only
           taken when nothing is staged. */
        $dcard = null;
        if ($cardIn !== null && !empty($cardIn['display'])) { $dcard = $cardIn; }
        if ($dcard === null && !empty($res['messages']) && is_array($res['messages'])) {
            for ($mi = count($res['messages']) - 1; $mi >= 0; $mi--) {
                $mm = $res['messages'][$mi];
                if (($mm['role'] ?? '') === 'gol' && !empty($mm['card']) && is_array($mm['card']) && !empty($mm['card']['display'])) { $dcard = $mm['card']; break; }
            }
        }
        if ($dcard !== null) { $resp['dcard'] = $dcard; }
    }
    if ($isConfirm) { $resp['done'] = true; }
    /* cancellation + staleness ride back so the register can paint
       the exact rendered card dead (audit GOL-04). */
    if (!empty($res['cancelled']) && is_array($res['cancelled'])) {
        $resp['cancelled'] = true;
        $resp['cancelled_pid'] = (string)($res['cancelled']['pid'] ?? '');
    }
    if (!empty($res['stale'])) { $resp['stale'] = (string)$res['stale']; }
    // The report graph rides sales answers for the owner only (staff
    // callers never see money figures — the big brain fences their
    // reads, and its graph follows the same fence).
    if ($cardIn === null && !$isConfirm && $email === $cfg['owner']
        && preg_match('/(sales|bikri|close|\bz\b|total|report|hisab|week|hapta|month|mahina|yesterday|hijo|\d{4}-\d{2}-\d{2})/iu', $msg)) {
        $g = golpos_bigbrain_graph($cfg, $msg);
        if ($g !== null) { $resp['graph'] = $g; }
    }
    out($resp);
    return true; // unreachable — out() exits; kept for the route's shape
}

// The one door both the plain flow and the PIN-gate replays walk
// through: big brain first where it claims the turn, till brain after.
// --- Calendar day tap: a SILENT data fetch, never a chat turn ----------------
// Owner catch, Oct 31: tapping a day in a report calendar sent
// "YYYY-MM-DD report" as a chat message and stacked a whole new
// report (graph + cash card + calendar) under it, flooding the
// thread. The panel now POSTs {action:'day_detail'} instead: this
// read answers with ONE compact day payload and nothing else — no
// turn is journaled on either brain, no state is written, and the
// panel swaps a single day card in place under its report. Figures
// come from the SAME sources the report itself reads: the Books
// closes via gol-reads first (the rows the confirmed text answer
// prints, tax + result included), the till's own journal day as
// fallback. Money fence matches the report graph's own: Books
// figures ride owner callers only; the till fallback rides admin
// sessions (or a roster-less till — the owner's own session).
function golpos_day_detail(array $in, array $session): void {
    $ymd = trim((string)($in['ymd'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) { out(['day_detail' => null]); }
    $cfg = golpos_bigbrain_cfg();
    if ($cfg !== null) {
        $caller = golpos_bigbrain_caller($cfg, $session);
        if ($caller !== null && $caller === $cfg['owner']) {
            $apiDir = (string)$cfg['api_dir'];
            if (!function_exists('golr_close_on') && is_file($apiDir . '/gol-reads.php')) {
                require_once $apiDir . '/gol-reads.php';
            }
            if (function_exists('golr_close_on')) {
                $storeId = trim((string)($in['store_id'] ?? '')) ?: (string)$cfg['store_id'];
                $store = golr_store_by_id($storeId);
                if ($store === null) {
                    $store = ['slug' => golr_slug_for($storeId), 'id' => $storeId,
                              'name' => (string)$cfg['name']];
                }
                $row = golr_close_on($store, $ymd);
                if ($row !== null) {
                    out(['day_detail' => [
                        'ymd' => $ymd, 'basis' => 'books',
                        'store' => (string)$store['name'],
                        'label' => golr_fmt_date($ymd),
                        'gross' => round((float)$row['gross'], 2),
                        'cash' => round((float)$row['cash'], 2),
                        'card' => round((float)$row['card'], 2),
                        'tax' => round((float)($row['tax'] ?? 0), 2),
                        'counted' => isset($row['counted']) ? round((float)$row['counted'], 2) : null,
                        'expected' => isset($row['expectedCash']) ? round((float)$row['expectedCash'], 2) : null,
                        'result' => golr_os_text($row['overShort'] ?? null),
                        'cash_derived' => !empty($row['cashDerived']),
                    ]]);
                }
            }
        }
    }
    if (!empty($session['admin']) || !golpos_roster_has_pin()) {
        $sum = golpos_day_summary($ymd);
        if ((int)$sum['txns'] > 0) {
            out(['day_detail' => [
                'ymd' => $ymd, 'basis' => 'till',
                'store' => (string)(golpos_catalog()['store']['name'] ?? ''),
                'label' => date('D, M j, Y', (int)strtotime($ymd)),
                'gross' => $sum['gross'], 'cash' => $sum['cash'], 'card' => $sum['card'],
                'tax' => null, 'counted' => null, 'expected' => null,
                'result' => '', 'cash_derived' => false,
            ]]);
        }
    }
    out(['day_detail' => null]);
}

// --- "Ko logged in cha?" — who is on this register, from the live record --
// (Owner addition, Oct 31.) Computed AT ANSWER TIME from the register's
// own session store + unlock journal — never from the roster, never
// cached: a session counts as "on" only when it carries an identity and
// is not locked. "Since when" is the latest unlock journal entry for
// that same identity that no later lock entry closed; sheet-gate
// unlocks are not journaled, so when no entry matches, since is
// honestly null and the card says the time is not recorded — the
// session record itself carries no unlock timestamp. The panel renders
// the payload as a designed who-card (UI-answer law); the reply line is
// the spoken/short form of the same truth. Lives in the route so the
// PIN-gate replay (an unelevated "kun employee…" ask is employee-domain
// and gates first) lands here too, after the PIN verifies.
function golpos_who_answer(string $msg, string $sessionId): void {
    $allSessions = golpos_read('sessions.json', []);
    $lastUnlock = []; $lastLock = [];
    foreach (golpos_unlocks() as $u) {
        $key = ($u['role'] ?? '') === 'admin' ? 'admin' : (string)($u['email'] ?? '');
        if ($key === '') { continue; }
        if (($u['kind'] ?? '') === 'unlock') { $lastUnlock[$key] = (string)($u['at'] ?? ''); }
        if (($u['kind'] ?? '') === 'lock') { $lastLock[$key] = (string)($u['at'] ?? ''); }
    }
    $who = []; $whoPos = [];
    if (is_array($allSessions)) {
        foreach ($allSessions as $sid => $s) {
            if (!is_array($s) || empty($s['unlocked_by']) || golpos_lock_state($s)) { continue; }
            $key = $s['unlocked_by'] === 'admin' ? 'admin' : (string)$s['unlocked_by'];
            // One row per PERSON, not per device session: the same
            // identity open on two tills is one "who", with the
            // caller's own session flagged on the shared row.
            if (isset($whoPos[$key])) {
                if ($sid === $sessionId) { $who[$whoPos[$key]]['you'] = true; }
                continue;
            }
            $since = $lastUnlock[$key] ?? null;
            if ($since !== null && isset($lastLock[$key]) && $lastLock[$key] > $since) { $since = null; }
            $whoPos[$key] = count($who);
            $who[] = ['name' => (string)($s['unlocked_name'] ?? ''),
                      'role' => (string)($s['unlocked_role'] ?? ''),
                      'email' => $s['unlocked_by'] === 'admin' ? '' : (string)$s['unlocked_by'],
                      'since' => $since,
                      'you' => $sid === $sessionId];
        }
    }
    $np = in_array(golpos_lang($msg), ['np', 'dev'], true)
        || preg_match('/\b(ko|kun|kasle)\b.{0,25}(logged|login|unlock)|\b(logged|login)\b.{0,12}\b(cha|chha)\b/iu', $msg) === 1;
    if ($who === []) {
        out(['reply' => $np
            ? 'Ahile kunai session khuleko chhaina — register locked chha wa setup mode ma chha, kohi logged in chhaina.'
            : 'No session is open right now — the register is locked or in setup mode; nobody is logged in.',
             'who' => []]);
    }
    $names = implode(', ', array_map(static fn($w) => $w['name'] . ' (' . $w['role'] . ')', $who));
    out(['reply' => $np ? 'Register ma ahile: ' . $names . '.' : 'On this register now: ' . $names . '.',
         'who' => $who]);
}


// =============================================================================
// REASONING LAYER — TASKS 222 (owner order Nov 2; spec gol-brain-overall-fix-v1)
// -----------------------------------------------------------------------------
// The lanes below grew up as a rules machine: keyword gates chose the action
// and the first number spotted won the turn — "2 ta coke 20oz ring gara"
// staged a PRICE CHANGE because the price lane read the count as a price.
// This layer sits in FRONT of them in golpos_brain_route and changes the
// order of thinking:
//   1. PACK — assemble the turn's full live context first: the open sale the
//      client sends with the message, the catalog, today's and yesterday's
//      figures, the card Gol currently has staged.
//   2. INTERPRET — decide what the person is trying to DO (sell / ask /
//      change / report / greet) over the Nepali+English mix and imperfect
//      transcripts, including what each number MEANS inside that intent: a
//      count bound to a classifier (ta/ota/vata) is a QUANTITY; a number is
//      a price only inside a price change.
//   3. ANSWER from the pack, scoped to the ask. Writes stay exactly what
//      they were — staged cards confirmed by "gol". This layer never writes.
// The old lanes remain as servants for everything this layer does not own.
// Keyword rules here are fences only (the gol-confirm gate, the
// greeting-cancels-armed-card fence), never the brain choosing by keyword.
// =============================================================================

function golpos_reason_tokens(string $msg): array {
    $t = mb_strtolower(golpos_norm_digits($msg));
    $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t) ?? $t;
    $out = [];
    foreach (preg_split('/\s+/u', trim($t)) ?: [] as $w) { if ($w !== '') { $out[] = $w; } }
    return $out;
}

// Postpositions and fillers ride along with item names in Nepali speech
// ("Red Bull ko stock", "Red Bull go stock"). They carry grammar, never
// identity, so resolution never scores them.
function golpos_reason_content_tokens(string $msg): array {
    static $drop = ['ko' => 1, 'go' => 1, 'cha' => 1, 'chha' => 1, 'ho' => 1, 'ni' => 1,
        'le' => 1, 'lai' => 1, 'ra' => 1, 'ani' => 1, 'the' => 1, 'a' => 1, 'an' => 1,
        'of' => 1, 'please' => 1, 'plz' => 1, 'bhane' => 1,
        // Verb endings are grammar, never product evidence: without
        // these, the fuzzy skeleton pass read "chan" (…ota chan? =
        // how many are there) as Sugar's alias "chini" (skel chn).
        'chan' => 1, 'chhan' => 1];
    $out = [];
    foreach (golpos_reason_tokens($msg) as $w) { if (!isset($drop[$w])) { $out[] = $w; } }
    return $out;
}

// Resolve the item a turn means, tolerating speech. Three passes, in order:
// the register's own exact resolver; a DISTINCT-token scorer (a word counts
// once per product however many of its names/aliases carry it — the old
// shared scorer counted "red" twice for Red Wine, once from its name and
// once from its alias "red wine", and so tied it against Red Bull's two
// real words); then a fuzzy pass, so an in-catalog near-miss is never a
// flat not-found. A token carried by exactly one product on the shelf is
// that product's fingerprint and outweighs shared words.
function golpos_reason_resolve(array $products, string $msg): array {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) { return ['one' => $r['one'], 'how' => 'exact', 'items' => [$r['one']]]; }
    static $stop = ['stock' => 1, 'price' => 1, 'kati' => 1, 'ring' => 1, 'gara' => 1, 'gar' => 1,
        'add' => 1, 'thap' => 1, 'total' => 1, 'maujud' => 1, 'left' => 1, 'baki' => 1,
        'how' => 1, 'many' => 1, 'much' => 1, 'have' => 1, 'got' => 1];
    $content = [];
    foreach (golpos_reason_content_tokens($msg) as $w) { if (!isset($stop[$w])) { $content[] = $w; } }
    if (!$content) { return ['none' => true, 'items' => []]; }
    $sets = []; $freq = [];
    foreach ($products as $i => $p) {
        $set = [];
        $words = preg_split('/[^a-z0-9]+/u', mb_strtolower((string)$p['name'])) ?: [];
        foreach ((array)($p['aliases'] ?? []) as $a) {
            $words = array_merge($words, preg_split('/[^a-z0-9]+/u', mb_strtolower((string)$a)) ?: []);
        }
        foreach ($words as $w) { if (mb_strlen($w) >= 3) { $set[$w] = true; } }
        $sets[$i] = $set;
        foreach ($set as $w => $_) { $freq[$w] = ($freq[$w] ?? 0) + 1; }
    }
    $best = 0; $top = [];
    foreach ($products as $i => $p) {
        $s = 0;
        foreach ($sets[$i] as $w => $_) {
            if (in_array((string)$w, $content, true)) { $s += (($freq[$w] ?? 0) <= 1) ? 2 : 1; }
        }
        if ($s > $best) { $best = $s; $top = [$p]; }
        elseif ($s === $best && $s > 0) { $top[] = $p; }
    }
    if ($best > 0 && count($top) === 1) { return ['one' => $top[0], 'how' => 'tokens', 'items' => $top]; }
    if ($best > 0) { return ['many' => true, 'items' => $top]; }
    // Fuzzy pass: close spellings and garbles of a shelf word.
    $skel = static fn(string $w): string => preg_replace('/[aeiou]+/', '', $w) ?? $w;
    $fbest = 0; $ftop = [];
    foreach ($products as $i => $p) {
        $s = 0;
        foreach ($content as $w) {
            if (mb_strlen($w) < 4) { continue; }
            foreach ($sets[$i] as $pw => $_) {
                $pw = (string)$pw;
                if (mb_strlen($pw) < 4) { continue; }
                $lim = mb_strlen($w) >= 7 ? 2 : 1;
                if (levenshtein($w, $pw) <= $lim || $skel($w) === $skel($pw)) {
                    $s += (($freq[$pw] ?? 0) <= 1) ? 2 : 1;
                    break;
                }
            }
        }
        if ($s > $fbest) { $fbest = $s; $ftop = [$p]; }
        elseif ($s === $fbest && $s > 0) { $ftop[] = $p; }
    }
    if ($fbest > 0 && count($ftop) === 1) { return ['one' => $ftop[0], 'how' => 'fuzzy', 'items' => $ftop]; }
    if ($fbest > 0) { return ['many' => true, 'items' => $ftop]; }
    return ['none' => true, 'items' => []];
}

// The reasoning step for a transcript the deterministic passes cannot
// resolve: the model reads the turn against the shelf and names the item
// the speaker most plausibly meant. Structured answer only; the sku is
// validated against the catalog before anything uses it, and any failure
// (provider down, bad JSON, unknown sku, low confidence) returns null —
// the caller then answers honestly instead of guessing.
function golpos_reason_interpret(array $products, string $msg): ?array {
    if (!function_exists('golpos_meta_chat')) { return null; }
    $list = implode('; ', array_map(static fn($p) => $p['sku'] . '=' . $p['name'], $products));
    $sys = 'You are the interpreter inside a convenience-store register. A speech transcript may be garbled. '
        . 'Given the transcript and the catalog (sku=name), return ONLY a JSON object {"sku":"...","confidence":0.0} '
        . 'naming the ONE catalog item the speaker most plausibly meant, or {"sku":"","confidence":0} if none is plausible. No other text.';
    $raw = golpos_meta_chat(golpos_spark_model(), $sys, "Transcript: {$msg}\nCatalog: {$list}", 1500, 20);
    if ($raw === null) { return null; }
    if (!preg_match('/\{.*\}/s', $raw, $m)) { return null; }
    $j = json_decode($m[0], true);
    if (!is_array($j) || empty($j['sku']) || (float)($j['confidence'] ?? 0) < 0.6) { return null; }
    foreach ($products as $p) { if ((string)$p['sku'] === (string)$j['sku']) { return $p; } }
    return null;
}

// The pack: the turn's live context, assembled before any interpreting.
// The open sale rides in from the client with every message (app.js
// brainPayload); the staged card is read from the register's own store.
function golpos_reason_pack(array $in): array {
    $cart = ['items' => [], 'subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'count' => 0];
    if (!empty($in['cart']) && is_array($in['cart'])) {
        $c = $in['cart'];
        foreach ((array)($c['items'] ?? []) as $it) {
            if (!is_array($it)) { continue; }
            $cart['items'][] = ['sku' => (string)($it['sku'] ?? ''), 'name' => (string)($it['name'] ?? ''),
                'qty' => max(1, (int)($it['qty'] ?? 1)), 'price' => round((float)($it['price'] ?? 0), 2)];
        }
        $cart['count'] = array_sum(array_column($cart['items'], 'qty'));
        // The client's own totals are the register's truth; recompute
        // only when they did not ride along.
        if (!empty($c['total'])) {
            $cart['subtotal'] = round((float)($c['subtotal'] ?? 0), 2);
            $cart['tax'] = round((float)($c['tax'] ?? 0), 2);
            $cart['total'] = round((float)$c['total'], 2);
        } elseif ($cart['items']) {
            $rate = (float)(golpos_catalog()['store']['tax_rate'] ?? 0);
            $sub = 0.0;
            foreach ($cart['items'] as $it) { $sub += $it['qty'] * $it['price']; }
            $cart['subtotal'] = round($sub, 2);
            $cart['tax'] = round($sub * $rate, 2);
            $cart['total'] = round($cart['subtotal'] + $cart['tax'], 2);
        }
    }
    return ['cart' => $cart, 'pending' => golpos_pending()];
}

function golpos_reason_close_for(string $ymd): ?array {
    foreach (golpos_read('closes.json', []) as $c) {
        if ((string)($c['date'] ?? '') === $ymd) { return $c; }
    }
    return null;
}

function golpos_reason_last_close(): ?array {
    $best = null;
    foreach (golpos_read('closes.json', []) as $c) {
        $d = (string)($c['date'] ?? '');
        if ($d !== '' && $d <= date('Y-m-d') && ($best === null || $d > (string)$best['date'])) { $best = $c; }
    }
    return $best;
}

// The date a close question points at: an explicit date, or hijo/yesterday.
function golpos_reason_close_ref(string $msg): ?array {
    $d = golpos_norm_digits($msg);
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $d, $m)) { return ['ymd' => "{$m[1]}-{$m[2]}-{$m[3]}"]; }
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', $d, $m)) {
        $mo = $months[mb_strtolower(substr($m[1], 0, 3))];
        $yr = (int)date('Y');
        $ymd = sprintf('%04d-%02d-%02d', $yr, $mo, (int)$m[2]);
        if ($ymd > date('Y-m-d')) { $ymd = sprintf('%04d-%02d-%02d', $yr - 1, $mo, (int)$m[2]); }
        return ['ymd' => $ymd];
    }
    if (preg_match('/(hijo|yesterday|हिजो)/iu', $msg)) {
        return ['ymd' => date('Y-m-d', strtotime('-1 day'))];
    }
    return null;
}

// Does the turn carry a RANGE shape (two day-dates, or a since/from
// marker against a month)? Ranges belong to the period engine, never
// to the single-month block — "Oct 20 dekhi Oct 31 samma" used to be
// answered with the whole of October.
function golpos_reason_rangeish(string $msg): bool {
    $norm = golpos_norm_digits($msg);
    if (preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{1,2}/iu', $norm) >= 2) { return true; }
    if (preg_match_all('/\d{4}-\d{2}-\d{2}/', $norm) >= 2) { return true; }
    return preg_match('/(dekhi|since|from)/iu', $msg) === 1
        && preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*|\b(month|mahina)\b/iu', $msg) === 1;
}

// "Why was today slow?" is a reasoning question and gets a reasoned
// answer built from the pack's own facts: how much of the selling day is
// gone, what this weekday usually does, what the last close was, what
// the average ticket looks like, and where the date sits in the season.
// It never dumps the week line and calls that an answer.
function golpos_reason_why_slow(): string {
    $now = new DateTimeImmutable('now', golpos_store_tz());
    $todayYmd = $now->format('Y-m-d');
    $dow = strtolower($now->format('D'));
    $today = golpos_day_summary($todayYmd);
    $same = []; $tickGross = 0.0; $tickN = 0;
    for ($i = 1; $i <= 42; $i++) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $s = golpos_day_summary($d);
        if ($s['txns'] <= 0) { continue; }
        if (strtolower(date('D', strtotime($d))) === $dow) { $same[] = $s['gross']; }
        if ($i <= 7) { $tickGross += $s['gross']; $tickN += $s['txns']; }
    }
    $wdAvg = $same ? array_sum($same) / count($same) : 0.0;
    $avgTicket = $tickN > 0 ? $tickGross / $tickN : 0.0;
    $set = golpos_settings();
    $openH = 8; $closeH = 21;
    if (preg_match('/^(\d{1,2})/', (string)($set['store_open'] ?? ''), $m)) { $openH = (int)$m[1]; }
    if (preg_match('/^(\d{1,2})/', (string)($set['store_close'] ?? ''), $m)) { $closeH = (int)$m[1]; }
    $minsNow = (int)$now->format('G') * 60 + (int)$now->format('i');
    $span = max(1, ($closeH - $openH) * 60);
    $elapsed = max(0.0, min(1.0, ($minsNow - $openH * 60) / $span));
    $dayName = $now->format('l');
    $bits = [];
    if ($today['txns'] <= 0) {
        $bits[] = 'Nothing has been rung yet today — and it is already ' . $now->format('g:i A')
            . ', about ' . round($elapsed * 100) . '% through the selling day.';
    } else {
        $bits[] = 'Today is at ' . money($today['gross']) . ' across ' . $today['txns'] . ' sales so far ('
            . $now->format('g:i A') . ', about ' . round($elapsed * 100) . '% of the selling day gone).';
        $todayTicket = $today['gross'] / $today['txns'];
        if ($avgTicket > 0 && $todayTicket < $avgTicket * 0.9) {
            $bits[] = 'Baskets are smaller than usual too — today\'s average sale is ' . money($todayTicket)
                . ' against a usual ' . money($avgTicket) . ', so it is smaller shops, not just fewer people.';
        }
    }
    if ($wdAvg > 0) {
        $line = 'A typical ' . $dayName . ' here does about ' . money($wdAvg) . ' for the full day';
        if ($today['txns'] > 0 && $elapsed > 0.15 && $today['gross'] < $wdAvg * $elapsed * 0.75) {
            $line .= ' — today\'s pace is running well under that, so this is a genuinely quiet ' . $dayName . ', not just the clock';
        }
        $bits[] = $line . '.';
    }
    // Season: the days right after Halloween are this trade's quietest.
    $sinceH = (int)floor((strtotime($todayYmd) - strtotime($now->format('Y') . '-10-31')) / 86400);
    if ($sinceH >= 0 && $sinceH <= 4) {
        $bits[] = 'Part of it is the calendar: the shop is ' . ($sinceH === 0 ? 'on Halloween itself'
            : $sinceH . ($sinceH === 1 ? ' day' : ' days') . ' past Halloween')
            . ', and the post-Halloween lull is real in this trade — the rush money was spent last week.';
    }
    $yday = golpos_day_summary(date('Y-m-d', strtotime('-1 day')));
    if ($yday['txns'] > 0) {
        $bits[] = 'For reference, yesterday closed at ' . money($yday['gross']) . ' across ' . $yday['txns'] . ' sales.';
    } elseif (($lc = golpos_reason_last_close()) !== null) {
        $bits[] = 'The last completed close on record is ' . golpos_day_label((string)$lc['date']) . ' at ' . money((float)$lc['gross']) . '.';
    }
    return implode(' ', $bits);
}

// One item's stock + price, in the speaker's language, with the designed
// stock card the lanes use — and the read journaled the same way the
// stock lane journals it, so the verify tap sees the same truth.
function golpos_reason_answer_item(array $p, string $sessionId, string $lang): void {
    $oh = isset($p['stock']) && is_numeric($p['stock']) ? $p['stock'] + 0
        : (isset($p['onHand']) && is_numeric($p['onHand']) ? $p['onHand'] + 0 : null);
    $price = isset($p['price']) && is_numeric($p['price']) ? (float)$p['price'] : null;
    golpos_ctx_set($p, 'stock_query');
    golpos_verify_journal(['class' => 'stock_count', 'label' => (string)$p['name'],
        'figure' => $oh !== null ? (float)$oh : 0.0, 'unit' => 'count',
        'inputs' => ['sku' => (string)($p['sku'] ?? ''), 'name' => (string)$p['name'], 'mode' => 'local'],
        'session' => (string)($GLOBALS['golpos_session_id'] ?? '')]);
    if ($lang === 'np') {
        $reply = $p['name'] . ' — ' . ($oh === null ? 'stock record chhaina' : ($oh > 0 ? $oh . ' ota baki chha' : 'sakisakyo (0)'))
            . ($price !== null ? ', mulya ' . money($price) : '') . '.';
    } else {
        $reply = $p['name'] . ' — ' . ($oh === null ? 'stock not tracked' : ($oh > 0 ? $oh . ' left' : 'out of stock'))
            . ($price !== null ? ', ' . money($price) : '') . '.';
    }
    out(['reply' => $reply, 'dcard' => ['type' => 'stock_list', 'display' => true, 'title' => 'Stock check',
        'items' => [['name' => (string)$p['name'], 'qty' => $oh, 'price' => $price,
            'note' => $oh !== null && $oh <= 0 ? 'out' : '']]]]);
}

// Tax collected over a set of days (ymd => true), from the sale journal
// — the same scan the month block runs for its own month.
function golpos_reason_range_tax(array $set): float {
    $tax = 0.0;
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if (isset($set[$d])) { $tax += (float)($s['tax'] ?? 0); }
    }
    return round($tax, 2);
}

// Top sellers over a set of days, from the sale lines — the only place
// line detail lives. Returns rows by quantity and by revenue.
function golpos_reason_top_items(array $set, int $n): array {
    $agg = [];
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if (!isset($set[$d])) { continue; }
        foreach ((array)($s['lines'] ?? []) as $ln) {
            if (!is_array($ln)) { continue; }
            $nm = (string)($ln['name'] ?? '');
            if ($nm === '') { continue; }
            $q = (float)($ln['qty'] ?? 1);
            $agg[$nm] = $agg[$nm] ?? ['name' => $nm, 'qty' => 0.0, 'rev' => 0.0];
            $agg[$nm]['qty'] += $q;
            $agg[$nm]['rev'] = round($agg[$nm]['rev'] + $q * (float)($ln['price'] ?? 0), 2);
        }
    }
    $byQty = array_values($agg);
    usort($byQty, static fn($a, $b) => ($b['qty'] <=> $a['qty']) ?: ($b['rev'] <=> $a['rev']));
    $byRev = array_values($agg);
    usort($byRev, static fn($a, $b) => ($b['rev'] <=> $a['rev']) ?: ($b['qty'] <=> $a['qty']));
    return ['by_qty' => array_slice($byQty, 0, $n), 'by_rev' => array_slice($byRev, 0, $n)];
}

// One period, answered from the pack: the register's day summaries over
// the period's days (the cash block is the register's own split-honest
// aggregate), the journal's tax, and — when the ask scopes them — the
// tender slice, the average ticket, the best day and the top sellers.
// The designed report card rides every answer, as with every period.
function golpos_reason_period_report(array $days, string $label, string $msg, string $lang): void {
    $set = array_flip($days);
    $blk = golpos_cash_block($days);
    $tax = golpos_reason_range_tax($set);
    $np = in_array($lang, ['np', 'hi', 'dev'], true);
    $gross = (float)$blk['total']; $txns = (int)$blk['txns'];
    $perDay = [];
    foreach ($days as $ymd) { $perDay[$ymd] = (float)golpos_day_summary($ymd)['gross']; }
    if (count($days) <= 31) {
        $series = [];
        foreach ($days as $ymd) {
            $series[] = ['label' => count($days) <= 7 ? date('D j', strtotime($ymd)) : date('j', strtotime($ymd)),
                         'value' => round($perDay[$ymd], 2), 'ymd' => $ymd];
        }
    } else {
        $bym = [];
        foreach ($days as $ymd) { $bym[substr($ymd, 0, 7)] = ($bym[substr($ymd, 0, 7)] ?? 0.0) + $perDay[$ymd]; }
        $series = [];
        foreach ($bym as $ym => $v) {
            $series[] = ['label' => date('M', strtotime($ym . '-01')), 'value' => round($v, 2), 'ymd' => $ym . '-01'];
        }
    }
    $graph = golpos_graph_payload('bars', $label, $series, round($gross, 2));
    $graph = golpos_report_card($graph, $days);
    if (isset($graph['cash']) && is_array($graph['cash'])) { $graph['cash']['tax'] = $tax; }
    if ($txns <= 0) {
        out(['reply' => $label . ($np ? ' — yo period ma kunai bikri record chhaina.'
                                      : ' — no sales recorded in this period.'), 'graph' => $graph]);
    }
    $lines = [];
    // Scoped leads: the ask's own slice speaks first.
    if (preg_match('/\bcard\b/iu', $msg) && preg_match('/(bata|from|ma\s+kati|kati)/iu', $msg)) {
        $lines[] = $np ? 'Card bata: ' . money((float)$blk['card']) . ' (jamma ' . money($gross) . ' madhye).'
                       : 'By card: ' . money((float)$blk['card']) . ' of the ' . money($gross) . ' total.';
    } elseif (preg_match('/\bcash\b/iu', $msg) && preg_match('/(bata|from|ma\s+kati|kati)/iu', $msg)) {
        $lines[] = $np ? 'Cash bata: ' . money((float)$blk['cash']) . ' (jamma ' . money($gross) . ' madhye).'
                       : 'By cash: ' . money((float)$blk['cash']) . ' of the ' . money($gross) . ' total.';
    }
    if (preg_match('/(tax|कर)/iu', $msg)) {
        $lines[] = $np ? 'Tax collect: ' . money($tax) . '.'
                       : 'Tax collected: ' . money($tax) . '.';
    }
    $lines[] = $label . ': ' . ($np ? 'jamma ' : 'gross ') . money($gross)
        . ($np ? ' — ' : ' across ') . $txns . ($np ? ' bikri (cash ' : ' sales (cash ')
        . money((float)$blk['cash']) . ', card ' . money((float)$blk['card'])
        . (!empty($blk['credit']) ? ', credit ' . money((float)$blk['credit']) : '') . ').';
    if (!preg_match('/(tax|कर)/iu', $msg) && $tax > 0) {
        $lines[] = $np ? 'Tax collect: ' . money($tax) . '.' : 'Tax collected: ' . money($tax) . '.';
    }
    if (preg_match('/(average|ausat|औसत)/iu', $msg) && $txns > 0) {
        $lines[] = $np ? 'Ausat bikri: ' . money(round($gross / $txns, 2)) . ' prati sale.'
                       : 'Average sale: ' . money(round($gross / $txns, 2)) . ' per sale across ' . $txns . ' sales.';
    }
    if (preg_match('/(sabse|sabai\s*bhanda|most|best|highest|धेरै)/iu', $msg)
        && preg_match('/(din|day|bikri|sales|बिक्री)/iu', $msg)
        && !preg_match('/(item|saman|product|bikeko|bikyo)/iu', $msg) && count($days) > 1) {
        $bestD = null; $bestV = -1.0;
        foreach ($perDay as $ymd => $v) { if ($v > $bestV) { $bestV = $v; $bestD = $ymd; } }
        if ($bestD !== null && $bestV > 0) {
            $lines[] = $np ? 'Sabse dherai bikri bhayeko din: ' . golpos_day_label($bestD) . ' — ' . money($bestV) . '.'
                           : 'Best day: ' . golpos_day_label($bestD) . ' — ' . money($bestV) . '.';
        }
    }
    if (preg_match('/(bikeko|bikyo|bikri\s+bhayeko|sold|bestseller|best\s*sell|top|sabse)/iu', $msg)
        && preg_match('/(item|saman|product|bikeko|bikyo|kun|\bke\b)/iu', $msg)) {
        $top = golpos_reason_top_items($set, 3);
        if ($top['by_qty']) {
            $parts = [];
            foreach ($top['by_qty'] as $r) { $parts[] = $r['name'] . ' — ' . (int)$r['qty'] . ' sold (' . money($r['rev']) . ')'; }
            $lines[] = ($np ? 'Sabse dherai bikeko: ' : 'Top sellers: ') . implode('; ', $parts) . '.';
            if ($top['by_rev'] && $top['by_rev'][0]['name'] !== $top['by_qty'][0]['name']) {
                $lines[] = ($np ? 'Rakam ma sabse dherai: ' : 'By money: ') . $top['by_rev'][0]['name']
                    . ' — ' . money($top['by_rev'][0]['rev']) . '.';
            }
        }
    }
    out(['reply' => implode("\n", $lines), 'graph' => $graph]);
}

// The layer itself. Returns true when the turn is understood and answered
// here; false lets the lanes serve it. out() ends the request, as in the
// rest of the brain.
function golpos_reason_layer(string $msg, array $in, array $products, string $sessionId): bool {
    // Confirms, card buttons and gated replays belong to the executor.
    if (!empty($in['confirm']) || isset($in['cancel_card']) || isset($in['pending_pid'])) { return false; }
    $lm = mb_strtolower(trim($msg));
    if ($lm === 'gol' || $lm === 'गोल') { return false; }
    $lang = golpos_lang($msg);
    $pack = golpos_reason_pack($in);
    $toks = golpos_reason_tokens($msg);
    $hasW = static function (array $ws) use ($toks): bool {
        foreach ($ws as $w) { if (in_array($w, $toks, true)) { return true; } }
        return false;
    };
    $isQ = golpos_is_question($msg);

    // FENCE — a bare greeting cancels an armed card, and the cancellation
    // happens before the greeting's own path runs: afterwards a stray
    // "gol" finds no card and can execute nothing. (The Nov 1 critical:
    // "hi" answered nicely while a price change stayed armed — and then
    // the next "gol" executed it.)
    if (golpos_is_bare_greeting($msg) && $pack['pending'] !== null) {
        $card = $pack['pending'];
        golpos_clear_pending();
        golpos_price_await_set(null);
        $s = golpos_session($sessionId);
        $s['ctx'] = null;
        golpos_save_session($sessionId, $s);
        out(['reply' => golpos_greet_word() . '. I have cancelled the card that was waiting — '
            . golpos_card_summary($card) . '. Nothing will run unless you stage it again.']);
        return true;
    }

    // BATCH STATE — a question about the card batch is a READ over the
    // batch ledger, answered here. It must land BEFORE the close block
    // below: "batch close bhaisakyo?" names the batch, not the day, and
    // used to be answered with the DAY CLOSE — the wrong record. An
    // imperative ("batch close gara") is not this read: it falls
    // through to the batch lanes, which stage the seal as a card.
    if (preg_match('/(batch|ब्याच)/iu', $msg)
        && !golpos_is_batch_mode_set($msg)
        && !preg_match('/(close|seal|banda)\s*(gara|gar|gardinu|deu|dinus|now|गर)/iu', $msg)
        && ($isQ || preg_match('/(kati|kun|bhayo|bhaisakyo|bhaisakeko|cha|chha|छ|status|kahile|when|last|pichhillo|waiting|baki|बाँकी)/iu', $msg))) {
        $actorB = golpos_actor();
        $npB = in_array($lang, ['np', 'hi', 'dev'], true);
        if ($actorB !== null && ($actorB['role'] ?? '') === 'employee') {
            // Batch money is the owner's side of the till — an identified
            // cashier gets the domain truth, never the figures.
            out(['reply' => $npB
                ? 'Batch ko hisab owner ko side ho — card batch ko rakam ma tapai lai dekhाउन mildaina.'
                : 'Batch figures are on the owner\'s side — I can\'t show card batch totals to a cashier session.']);
            return true;
        }
        $batchesB = golpos_batches();
        $unbB = golpos_unbatched_card_sales();
        $unbT = 0.0;
        foreach ($unbB as $u) { $unbT += golpos_batch_amount($u); }
        $unbT = round($unbT, 2);
        $linesB = [];
        if ($batchesB) {
            $lastB = $batchesB[count($batchesB) - 1];
            $linesB[] = $npB
                ? 'Card batch — pichhillo seal: Batch #' . (int)$lastB['n'] . ', '
                    . golpos_day_label((string)$lastB['date']) . ' — ' . (int)$lastB['count']
                    . ' card bikri, ' . money((float)$lastB['total'])
                    . (($lastB['closed_by'] ?? '') === 'auto' ? ' (auto seal).' : ' (manual seal).')
                : 'Card batch — last sealed: Batch #' . (int)$lastB['n'] . ' on '
                    . golpos_day_label((string)$lastB['date']) . ' — ' . (int)$lastB['count']
                    . ' card sales, ' . money((float)$lastB['total'])
                    . (($lastB['closed_by'] ?? '') === 'auto' ? ' (sealed automatically).' : ' (sealed manually).');
        } else {
            $linesB[] = $npB ? 'Ahile samma kunai batch seal bhayeko chhaina.'
                             : 'No card batch has been sealed yet.';
        }
        $linesB[] = $unbB
            ? ($npB ? 'Seal huna baki: ' . count($unbB) . ' card bikri — ' . money($unbT) . ' batch bhitra chhaina.'
                    : 'Waiting to be sealed: ' . count($unbB) . ' card sales — ' . money($unbT) . ' not in a batch yet.')
            : ($npB ? 'Seal huna baki kehi chhaina — sabai card bikri batch bhitra chha.'
                    : 'Nothing is waiting — every card sale is inside a sealed batch.');
        out(['reply' => implode("\n", $linesB)]);
        return true;
    }

    // A question about a close is a READ. The old close lane matched the
    // word "close" alone and EXECUTED a close on a question — locking
    // the register mid-conversation. Questions answer from closes.json
    // here; only a bare imperative falls through to the closing lane.
    if (preg_match('/(close|banda|बन्द|क्लोज)/iu', $msg)
        && ($isQ || preg_match('/\b(were|was|did|figures|kati|कति|hisab|हिसाब)\b/iu', $msg)
            || golpos_reason_close_ref($msg) !== null)) {
        $ref = golpos_reason_close_ref($msg);
        $ymd = $ref['ymd'] ?? null;
        $rec = $ymd !== null ? golpos_reason_close_for($ymd) : null;
        $note = '';
        if ($rec === null) {
            $rec = golpos_reason_last_close();
            if ($rec !== null && $ymd !== null) {
                $note = 'No close is recorded for ' . golpos_day_label($ymd)
                    . ' — the most recent saved close is ' . golpos_day_label((string)$rec['date']) . '. ';
            }
        }
        if ($rec !== null) {
            out(['reply' => $note . golpos_day_label((string)$rec['date']) . ' close — gross '
                    . money((float)$rec['gross']) . ' across ' . (int)$rec['txns'] . ' sales: cash '
                    . money((float)$rec['cash']) . ', card ' . money((float)$rec['card'])
                    . (!empty($rec['credit']) ? ', credit ' . money((float)$rec['credit']) : '') . '.',
                'close_record' => $rec]);
            return true;
        }
        if ($ymd !== null) {
            $d = golpos_day_summary($ymd);
            if ($d['txns'] > 0) {
                out(['reply' => 'No close was saved for ' . golpos_day_label($ymd) . ', but the day itself shows gross '
                    . money($d['gross']) . ' across ' . $d['txns'] . ' sales: cash ' . money($d['cash'])
                    . ', card ' . money($d['card']) . '.']);
                return true;
            }
            out(['reply' => 'No close is recorded for ' . golpos_day_label($ymd) . ', and that day shows no sales either.']);
            return true;
        }
        return false;
    }

    // hijo / yesterday — a report period, answered as a report: the saved
    // close when there is one, with the day's graph and report card. When
    // yesterday itself has nothing recorded, the last completed day is
    // named and answered instead — never a bare "no figures".
    if ($hasW(['hijo', 'yesterday', 'हिजो'])
        && !preg_match('/(compar|vs|versus|भन्दा|bhanda)/iu', $msg)
        && ($isQ || preg_match('/(hisab|hisaab|total|sales|bikri|report|close|kati|bhayo|kasto|कस्तो|भयो)/iu', $msg)
            || count($toks) <= 2)) {
        $ymd = date('Y-m-d', strtotime('-1 day'));
        $rec = golpos_reason_close_for($ymd);
        $day = golpos_day_summary($ymd);
        $note = '';
        if ($rec === null && $day['txns'] <= 0) {
            $rec = golpos_reason_last_close();
            if ($rec !== null) {
                $note = golpos_day_label($ymd) . ' has nothing recorded on this register, '
                    . 'so here is the last completed day. ';
                $ymd = (string)$rec['date'];
                $day = golpos_day_summary($ymd);
            }
        }
        if ($rec !== null) {
            [$nSeries, $nTotal] = golpos_graph_days_series_to(7, $ymd);
            $graph = golpos_graph_payload('bars', date('D, M j', strtotime($ymd)), $nSeries, $nTotal,
                ['label' => golpos_day_label($ymd), 'value' => round((float)$rec['gross'], 2)]);
            $graph = golpos_report_card($graph, [$ymd]);
            $lbl = golpos_day_label($ymd);
            $head = stripos($lbl, 'Yesterday') === 0 ? $lbl : 'Yesterday — ' . $lbl;
            out(['reply' => $note . $head . ' close: gross '
                    . money((float)$rec['gross']) . ' across ' . (int)$rec['txns'] . ' sales — cash '
                    . money((float)$rec['cash']) . ', card ' . money((float)$rec['card']) . '.',
                'graph' => $graph, 'close_record' => $rec]);
            return true;
        }
        if ($day['txns'] > 0) {
            [$nSeries, $nTotal] = golpos_graph_days_series_to(7, $ymd);
            $graph = golpos_graph_payload('bars', date('D, M j', strtotime($ymd)), $nSeries, $nTotal,
                ['label' => golpos_day_label($ymd), 'value' => round((float)$day['gross'], 2)]);
            $graph = golpos_report_card($graph, [$ymd]);
            $lbl = golpos_day_label($ymd);
            $head = stripos($lbl, 'Yesterday') === 0 ? $lbl : 'Yesterday — ' . $lbl;
            out(['reply' => $note . $head . ': gross ' . money($day['gross'])
                    . ' across ' . $day['txns'] . ' sales — cash ' . money($day['cash']) . ', card ' . money($day['card']) . '.',
                'graph' => $graph]);
            return true;
        }
        out(['reply' => 'Yesterday has nothing recorded on this register yet.']);
        return true;
    }

    // A bare weekday name is a period too: the most recent such day,
    // answered as a report. (Tasks, shifts and vendor due-days keep
    // their own lanes.)
    $wds = golpos_weekdays_in($msg);
    if ($wds && !$hasW(['task', 'tasks', 'duty', 'duties', 'shift', 'shifts', 'vendor', 'due', 'birthday'])
        && ($isQ || preg_match('/(hisab|hisaab|total|sales|bikri|report|close|kati|bhayo|how)/iu', $msg) || count($toks) <= 3)) {
        $key = $wds[0];
        $target = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6][$key];
        $ymd = date('Y-m-d');
        while ((int)date('w', strtotime($ymd)) !== $target) { $ymd = date('Y-m-d', strtotime($ymd . ' -1 day')); }
        $rec = golpos_reason_close_for($ymd);
        $day = golpos_day_summary($ymd);
        if ($rec !== null || $day['txns'] > 0) {
            $gross = $rec !== null ? (float)$rec['gross'] : $day['gross'];
            $cash = $rec !== null ? (float)$rec['cash'] : $day['cash'];
            $cardv = $rec !== null ? (float)$rec['card'] : $day['card'];
            $txns = $rec !== null ? (int)$rec['txns'] : $day['txns'];
            [$nSeries, $nTotal] = golpos_graph_days_series_to(7, $ymd);
            $graph = golpos_graph_payload('bars', date('D, M j', strtotime($ymd)), $nSeries, $nTotal,
                ['label' => golpos_day_label($ymd), 'value' => round($gross, 2)]);
            $graph = golpos_report_card($graph, [$ymd]);
            out(['reply' => golpos_day_name($key) . ' — ' . golpos_day_label($ymd) . ': gross ' . money($gross)
                    . ' across ' . $txns . ' sales — cash ' . money($cash) . ', card ' . money($cardv) . '.',
                'graph' => $graph]);
            return true;
        }
        out(['reply' => 'No sales are recorded for ' . golpos_day_name($key) . ' (' . golpos_day_label($ymd) . ') yet.']);
        return true;
    }

    // A month name is a full report period — never today's figures, never
    // "no figures" — and the report carries the month's collected tax as
    // its own line, the way the profit rule reads it. (Comparisons keep
    // their lane; an item-price ask keeps its own.)
    $monthRef = golpos_month_ref($msg);
    // A measure ask that names the PERIOD KIND but no month — "mahina
    // ko kar kati" — still names a month: the one the conversation is
    // standing in (Gol's own last reply named one, inside the same
    // follow-up window the task/list reads use), else the current
    // month. Before this, the ask carried a scope and no referent and
    // fell through every lane to "bujhina" (owner battery, Nov 2).
    if ($monthRef === null
        && preg_match('/(mahina|month|महिना)/iu', $msg)
        && preg_match('/(tax|\bkar\b|कर|profit|nafa|नाफा|purchase|kharid|खरिद|expense|kharcha|खर्च|sales|sale|bikri|बिक्री|total|jamma|hisab|hisaab)/iu', $msg)) {
        $boundM = null;
        $ssM = golpos_session($sessionId);
        $saidM = (string)($ssM['last_spoken'] ?? '');
        $atM = (int)($ssM['last_spoken_at'] ?? 0);
        if ($saidM !== '' && $atM > 0 && (time() - $atM) < 900) {
            $boundM = golpos_month_ref($saidM);
        }
        if ($boundM === null) {
            $dM = new DateTime('first day of this month');
            $boundM = ['ym' => $dM->format('Y-m'), 'label' => $dM->format('F Y'), 'bare' => false];
        }
        $monthRef = $boundM;
    }
    // "sabai bhanda badhi" is a SUPERLATIVE (the most), not a comparison
    // between two periods — bhanda only vetoes when it is not sabai's.
    $cmpM = preg_match('/(compar|vs\.?|versus|than|against|difference)/iu', $msg)
        || (preg_match('/(bhanda|भन्दा)/iu', $msg) && !preg_match('/(sabai|सबै)\s*(bhanda|भन्दा)/iu', $msg));
    if ($monthRef !== null
        && !$cmpM
        && !golpos_reason_rangeish($msg)
        && !preg_match('/(price|mulya|dam|दाम)/iu', $msg)
        && ($monthRef['bare'] || preg_match('/(sales|sale|bikri|total|jamma|purchase|kharid|expense|kharcha|report|hisab|hisaab|how much|kati|revenue|tax|profit|nafa|नाफा|bikeko|sabse|best|biggest|top)/iu', $msg))) {
        $ym = $monthRef['ym'];
        $lbl = $monthRef['label'];
        $ms = golpos_month_sales($ym);
        $tax = 0.0;
        foreach (golpos_sales() as $s) {
            if (substr((string)($s['at'] ?? ''), 0, 7) === $ym) { $tax += (float)($s['tax'] ?? 0); }
        }
        $tax = round($tax, 2);
        $series = [];
        foreach ($ms['days'] as $dymd => $g) {
            $series[] = ['label' => date('j', strtotime($dymd)), 'value' => round((float)$g, 2), 'ymd' => $dymd];
        }
        $graph = golpos_graph_payload('spark', $lbl . ' — report', $series, round((float)$ms['gross'], 2));
        $graph = golpos_report_card($graph, array_keys($ms['days']));
        if (isset($graph['cash']) && is_array($graph['cash'])) { $graph['cash']['tax'] = $tax; }
        // The full profit-rule picture, from the month's own records:
        // sales, purchases (vendor bills), expenses, tax as its own
        // line, and the rule's result. A slice ask (kharid / kharcha /
        // nafa) LEADS with its slice — the first cut of this block
        // answered every month question with sales + tax only, so
        // "October ma kharcha kati?" never named the expenses.
        $mp = golpos_month_purchases($ym);
        $me = golpos_month_expenses($ym);
        $profit = round($ms['gross'] - $mp['total'] - $me['total'] - $tax, 2);
        $salesLine = 'Sales: ' . money($ms['gross']) . ' across ' . $ms['txns'] . ' sales (cash '
            . money($ms['cash']) . ', card ' . money($ms['card']) . ').';
        $purchLine = $mp['bills'] > 0
            ? 'Purchases: ' . money($mp['total']) . ' across ' . $mp['bills'] . ' vendor bills'
              . ($mp['by_vendor'] ? ' — biggest: ' . array_key_first($mp['by_vendor']) . ' '
                  . money((float)$mp['by_vendor'][array_key_first($mp['by_vendor'])]) : '') . '.'
            : 'Purchases: no vendor bills recorded for this month.';
        $expLine = $me['count'] > 0
            ? 'Expenses: ' . money($me['total']) . ' across ' . $me['count'] . ' lines'
              . ' — biggest: ' . (string)($me['rows'][0]['label'] ?? 'Expense') . ' '
              . money((float)($me['rows'][0]['amount'] ?? 0)) . '.'
            : 'Expenses: none recorded for this month.';
        $taxLine = 'Tax collected: ' . money($tax) . '.';
        $profLine = 'Profit (sales - purchases - expenses - tax): ' . money($profit) . '.';
        // Scoped superlatives over the month's own days — the same
        // questions the period engine answers for weeks and ranges,
        // bound to the month's day map and sale lines. Computed BEFORE
        // the lead is chosen: they are single-measure scopes too.
        $bestDayLine = null;
        if (preg_match('/(sabse|sabai\s*bhanda|most|best|biggest|highest|धेरै)/iu', $msg)
            && preg_match('/(din|day|बिक्री|bikri|sales)/iu', $msg)
            && !preg_match('/(item|saman|product|bikeko|bikyo)/iu', $msg)) {
            $bd = null; $bv = -1.0;
            foreach ($ms['days'] as $dymd => $g) { if ((float)$g > $bv) { $bv = (float)$g; $bd = $dymd; } }
            if ($bd !== null && $bv > 0) {
                $bestDayLine = 'Best day: ' . golpos_day_label($bd) . ' — ' . money($bv) . '.';
            }
        }
        $topLines = [];
        if (preg_match('/(bikeko|bikyo|sold|bestseller|best\s*sell|top|sabse)/iu', $msg)
            && preg_match('/(item|saman|product|bikeko|bikyo|kun|\bke\b)/iu', $msg)) {
            $topM = golpos_reason_top_items(array_flip(array_keys($ms['days'])), 3);
            if ($topM['by_qty']) {
                $partsM = [];
                foreach ($topM['by_qty'] as $r) { $partsM[] = $r['name'] . ' — ' . (int)$r['qty'] . ' sold (' . money($r['rev']) . ')'; }
                $topLines[] = 'Top sellers: ' . implode('; ', $partsM) . '.';
                if ($topM['by_rev'] && $topM['by_rev'][0]['name'] !== $topM['by_qty'][0]['name']) {
                    $topLines[] = 'By money: ' . $topM['by_rev'][0]['name'] . ' — ' . money($topM['by_rev'][0]['rev']) . '.';
                }
            }
        }
        // THE LEAD LAW: a month ask scoped to ONE measure LEADS with
        // that measure as the headline — the figure the owner named is
        // the first figure he reads, never buried under lines he did
        // not ask about. (Owner live catch, Nov 2 21:35: "oct total
        // sales tax" drew the full-month dump with the tax line FIFTH,
        // under the circle's fold — the block had leads for profit /
        // purchases / expenses but none for tax, best day or top
        // seller, so those scopes were detected at the door and dropped.)
        $leadUsed = null;
        if (preg_match('/(profit|nafa|नाफा|munafa)/iu', $msg)) {
            $reply = $lbl . ' — profit.' . "\n" . $profLine . "\n" . $salesLine . "\n"
                . $purchLine . "\n" . $expLine . "\n" . $taxLine;
            $leadUsed = 'profit';
        } elseif (preg_match('/(tax|कर|\bkar\b)/iu', $msg)) {
            $reply = $lbl . ' — tax.' . "\n" . $taxLine . "\n" . $salesLine . "\n" . $profLine;
            $leadUsed = 'tax';
        } elseif (preg_match('/(purchase|purchases|kharid|खरिद|bills?\b)/iu', $msg)) {
            $reply = $lbl . ' — purchases.' . "\n" . $purchLine . "\n" . $salesLine . "\n" . $profLine;
            $leadUsed = 'purchases';
        } elseif (preg_match('/(expense|expenses|kharcha|खर्च)/iu', $msg)) {
            $reply = $lbl . ' — expenses.' . "\n" . $expLine . "\n" . $salesLine . "\n" . $profLine;
            $leadUsed = 'expenses';
        } elseif ($bestDayLine !== null) {
            $reply = $lbl . ' — best day.' . "\n" . $bestDayLine . "\n" . $salesLine . "\n" . $profLine;
            $leadUsed = 'bestday';
        } elseif ($topLines) {
            $reply = $lbl . ' — top sellers.' . "\n" . implode("\n", $topLines) . "\n" . $salesLine . "\n" . $profLine;
            $leadUsed = 'top';
        } elseif ($ms['txns'] > 0) {
            $reply = $lbl . ' — the full month.' . "\n" . $salesLine . "\n" . $purchLine . "\n"
                . $expLine . "\n" . $taxLine . "\n" . $profLine;
        } else {
            $reply = $lbl . ' — no sales recorded for this month yet.' . "\n" . $purchLine . "\n" . $expLine;
        }
        // A superlative the lead did not already carry still rides
        // along after the block (mixed asks keep both answers).
        if ($bestDayLine !== null && $leadUsed !== 'bestday') { $reply .= "\n" . $bestDayLine; }
        if ($topLines && $leadUsed !== 'top') { $reply .= "\n" . implode("\n", $topLines); }
        out(['reply' => $reply, 'graph' => $graph]);
        return true;
    }

    // PERIOD ENGINE — every period shape the register can be asked
    // about, answered from the pack as ONE designed report: this week,
    // last week, the last N days, an explicit range, a year, and the
    // scoped single-day slices (tender / tax / average) the day lane
    // answers only as a whole line. Before this block these shapes fell
    // to the deep-think lane or the free-form composer, whose pack does
    // not even carry the week's figures — the answers came back empty,
    // deflected, or invented (owner battery, Nov 2: "yo hapta ko bikri"
    // -> an empty reply with a graph; "last 10 days" -> a capability
    // menu). Named months belong to the month block above; comparisons
    // and single named days keep their own lanes; task asks are vetoed
    // (the tasks block below owns them).
    $cmpP = preg_match('/(compar|vs\.?|versus|than|against|difference)/iu', $msg)
        || (preg_match('/(bhanda|भन्दा)/iu', $msg) && !preg_match('/(sabai|सबै)\s*(bhanda|भन्दा)/iu', $msg));
    $perVeto = $cmpP
        || preg_match('/(tasks?|duty|duties|shift)/iu', $msg)
        || (golpos_month_ref($msg) !== null && !golpos_reason_rangeish($msg))
        || golpos_weekdays_in($msg) !== [];
    $perShape = preg_match('/(sales|sale|bikri|बिक्री|total|jamma|hisab|hisaab|report|revenue|kamai|tax|कर|card|cash|average|ausat|औसत|best|top|bikeko|sabse|सबैभन्दा)/iu', $msg) || $isQ;
    if (!$perVeto && $perShape) {
        $todayP = date('Y-m-d');
        $normP = golpos_norm_digits($msg);
        $daysP = null; $labelP = null;
        $rngDays = static function (string $a, string $b): array {
            $out = [];
            for ($t = strtotime($a); $t <= strtotime($b); $t += 86400) { $out[] = date('Y-m-d', $t); }
            return $out;
        };
        $sinceRef = golpos_month_ref($msg);
        if ($daysP === null && $sinceRef !== null && preg_match('/(dekhi|since|from)/iu', $msg)
            && !preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{1,2}/iu', $normP)) {
            $daysP = $rngDays($sinceRef['ym'] . '-01', $todayP);
            $labelP = 'Since ' . $sinceRef['label'] . ' (' . date('M j', strtotime($daysP[0]))
                . ' – ' . date('M j, Y', strtotime($todayP)) . ')';
        }
        if (preg_match_all('/(\d{4})-(\d{2})-(\d{2})/', $normP, $mmP) && count($mmP[0]) >= 2
            && checkdate((int)$mmP[2][0], (int)$mmP[3][0], (int)$mmP[1][0])
            && checkdate((int)$mmP[2][1], (int)$mmP[3][1], (int)$mmP[1][1])) {
            $a = $mmP[0][0]; $b = $mmP[0][1];
            if ($a > $b) { [$a, $b] = [$b, $a]; }
            if ($b > $todayP) { $b = $todayP; }
            if ($a <= $b) {
                $daysP = $rngDays($a, $b);
                $labelP = date('M j', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
            }
        }
        if ($daysP === null) {
            $monN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                     'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
            if (preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', $normP, $mdP)
                && count($mdP[0]) >= 2) {
                $mkD = static function (int $mo, int $da) use ($todayP): string {
                    $yr = (int)date('Y');
                    $ymd = sprintf('%04d-%02d-%02d', $yr, $mo, $da);
                    if ($ymd > $todayP) { $ymd = sprintf('%04d-%02d-%02d', $yr - 1, $mo, $da); }
                    return $ymd;
                };
                $a = $mkD($monN[mb_strtolower(substr($mdP[1][0], 0, 3))], (int)$mdP[2][0]);
                $b = $mkD($monN[mb_strtolower(substr($mdP[1][1], 0, 3))], (int)$mdP[2][1]);
                if ($a > $b) { [$a, $b] = [$b, $a]; }
                $daysP = $rngDays($a, $b);
                $labelP = date('M j', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
            } elseif (preg_match('/(dekhi|from|since)/iu', $msg)
                && preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', $normP, $mdP1)
                && count($mdP1[0]) === 1
                && preg_match('/(\d{1,2})\s*(samma|gha|to| सम्म)/iu', $normP, $endP)) {
                $mo = $monN[mb_strtolower(substr($mdP1[1][0], 0, 3))];
                $yr = (int)date('Y');
                $a = sprintf('%04d-%02d-%02d', $yr, $mo, (int)$mdP1[2][0]);
                if ($a > $todayP) { $a = sprintf('%04d-%02d-%02d', $yr - 1, $mo, (int)$mdP1[2][0]); }
                $b = substr($a, 0, 8) . str_pad((string)(int)$endP[1], 2, '0', STR_PAD_LEFT);
                if ($b >= $a && checkdate($mo, (int)$endP[1], (int)substr($a, 0, 4))) {
                    $daysP = $rngDays($a, $b);
                    $labelP = date('M j', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
                }
            }
        }
        if ($daysP === null && preg_match('/(last|pichhillo|पछिल्लो|गत)\s*(\d{1,3})\s*(days?|din|दिन)/iu', $normP, $ndP)) {
            $nD = max(2, min(92, (int)$ndP[2]));
            $daysP = $rngDays(date('Y-m-d', strtotime('-' . ($nD - 1) . ' days')), $todayP);
            $labelP = 'Last ' . $nD . ' days (' . date('M j', strtotime($daysP[0])) . ' – ' . date('M j, Y', strtotime($todayP)) . ')';
        }
        if ($daysP === null && preg_match('/(last|gata|गत|pichhillo)\s*(week|hapta|हप्ता)/iu', $msg)) {
            $daysP = $rngDays(date('Y-m-d', strtotime('-13 days')), date('Y-m-d', strtotime('-7 days')));
            $labelP = 'Last week (' . date('M j', strtotime($daysP[0])) . ' – ' . date('M j, Y', strtotime($daysP[6])) . ')';
        }
        if ($daysP === null && preg_match('/(week|hapta|हप्ता)/iu', $msg)) {
            $daysP = $rngDays(date('Y-m-d', strtotime('-6 days')), $todayP);
            $labelP = 'This week (' . date('M j', strtotime($daysP[0])) . ' – ' . date('M j, Y', strtotime($todayP)) . ')';
        }
        if ($daysP === null && preg_match('/(this\s+year|yo\s+barsha|यो\s+वर्ष)/iu', $msg)) {
            $daysP = $rngDays(date('Y') . '-01-01', $todayP);
            $labelP = date('Y') . ' so far (Jan 1 – ' . date('M j, Y', strtotime($todayP)) . ')';
        }
        if ($daysP === null && preg_match('/\b(20\d{2})\b/', $normP, $yrP)) {
            $yr = (int)$yrP[1];
            if ($yr >= 2020 && $yr <= (int)date('Y')) {
                $endY = $yr === (int)date('Y') ? $todayP : $yr . '-12-31';
                $daysP = $rngDays($yr . '-01-01', $endY);
                $labelP = $yr . ($yr === (int)date('Y') ? ' so far' : '') . ' (' . $yr . ' full year)';
                if ($yr === (int)date('Y')) { $labelP = $yr . ' so far (Jan 1 – ' . date('M j, Y', strtotime($todayP)) . ')'; }
            }
        }
        if ($daysP === null && preg_match('/(aaja|today|आज)/iu', $msg)
            && preg_match('/(bikri|बिक्री|sales|sale|total|jamma|hisab|hisaab|report|kamai|revenue)/iu', $msg)
            && !preg_match('/(close|banda|बन्द|क्लोज)/iu', $msg)) {
            // The plain day report, in the reason layer: the dispatch
            // day lane's keyword net missed the most natural Nepali
            // shape of all ("aaja ko bikri kati bhayo?") and an item
            // lane answered it not-found. A period word (aaja) names
            // the day; the designed card rides, as with every period.
            $daysP = [$todayP];
            $labelP = 'Today (' . date('D, M j', strtotime($todayP)) . ')';
        }
        if ($daysP === null
            && (preg_match('/\b(card|cash|credit)\b/iu', $msg)
                || preg_match('/(tax|कर|average|ausat|औसत)/iu', $msg))
            && !preg_match('/(hijo|yesterday|हिजो|week|hapta|हप्ता|month|mahina|महिना|last|gata|गत|pichhillo|dekhi|year|barsha|वर्ष)/iu', $msg)) {
            // The scoped single-day slices — the whole-day report is the
            // day lane's; a tender / tax / average ask gets its slice.
            // A periodless scoped ask ("average sale kati parcha?")
            // defaults to today, the counter's now frame; any named
            // period above has already claimed its own turn.
            $daysP = [$todayP];
            $labelP = 'Today (' . date('D, M j', strtotime($todayP)) . ')';
        }
        if ($daysP !== null && $labelP !== null) {
            $actorP = golpos_actor();
            if ($actorP !== null && ($actorP['role'] ?? '') === 'employee'
                && !(count($daysP) === 1 && $daysP[0] === $todayP)) {
                // Period money beyond the running day is the owner's
                // domain — the PIN gate catches most shapes before
                // here; this fence covers the shapes it cannot see.
                out(['reply' => in_array($lang, ['np', 'hi', 'dev'], true)
                    ? 'Period ko hisab owner ko side ho — ma tapai lai bikri ko report dekhाउन mildaina.'
                    : 'Sales reports are on the owner\'s side — I can\'t show period figures to a cashier session.']);
                return true;
            }
            golpos_reason_period_report($daysP, $labelP, $msg, $lang);
            return true;
        }
    }

    // OPEN TASKS + PRICE GAPS — a question about the work still open is
    // a READ over the store's own records, answered HERE, in the
    // reasoning layer, before the big brain's claims, the default lanes
    // or the composer can improvise an answer. (Owner live test,
    // Nov 2: his greeting named "64 task item(s) from yesterday are
    // still open" and the follow-up "kun 64 task" fell through every
    // lane to the composer, which answered with a capability menu —
    // and "what tasks are open?" even drew "I don't have open tasks
    // here".) The classifier binds the REFERENT, not a phrase: the
    // turn names the task domain (task/kam/काम) in a question shape,
    // or it is a short which/show follow-up and Gol's own previous
    // reply (the session's last_spoken) named the list — the greeting's
    // task line or price-gap line. Assignment, done-marking and duty
    // turns are imperative work, never this read; they keep their lanes.
    $taskRead = false; $gapRead = false;
    // List PRESENTATION (owner live test, Nov 2, 20:40 — "K bhanya
    // yetro"): the first cut of this block named every subject, but as
    // ONE comma-joined line per group — a 63-item wall inside a chat
    // bubble. A list answer is PRESENTED, not dumped: the header
    // carries the true total, one group line per holder/field, the
    // shared reason once on its own line, items one per line, and a
    // long group is capped at LIST_CAP items and closed with the
    // count still hidden. "sabai" / "show all" (the expand follow-up
    // below) lifts the cap for that same read.
    $LIST_CAP = 10;
    $listCloser = static function (int $left, bool $np): string {
        return $np ? "…ra aru {$left} ota — sabai hernu bhaye 'sabai' bhannus"
                   : "…and {$left} more — say 'show all' to see the full list";
    };
    $taskNoun = (bool) preg_match('/(tasks?\b|\bkam\b|काम)/iu', $msg);
    $qShapeT = $isQ || (bool) preg_match('/(kun|which|what|ke\s+ke|k\s+k|kati|show|list|dekha|dekhaideu|herna|baki|बाँकी|remaining|still\s+open|chha|cha|छ|chhan)/iu', $msg);
    $workImperative = (bool) preg_match('/(assign|sakiyo|sakyo|siddiyo|garisak|mark\s+done|bhaisakyo|भइसक)/iu', $msg)
        || golpos_parse_task_assign($msg) !== null
        || (bool) preg_match('/(mero|my)\s+(duty|duties|kartabya|कर्तव्य)/iu', $msg);
    if ($taskNoun && $qShapeT && !$workImperative) { $taskRead = true; }
    if (preg_match('/(price\s*gaps?|gaps?)/iu', $msg) && $qShapeT && !$workImperative) { $gapRead = true; $taskRead = false; }
    if (!$taskRead && !$gapRead && count($toks) <= 5
        && preg_match('/(kun|which|show|list|dekha|dekhaideu|herau|tyo|ती)/iu', $msg)) {
        $ssT = golpos_session($sessionId);
        $saidT = (string)($ssT['last_spoken'] ?? '');
        $atT = (int)($ssT['last_spoken_at'] ?? 0);
        if ($saidT !== '' && $atT > 0 && (time() - $atT) < 900) {
            if (preg_match('/(task item|open tasks?|khula task)/i', $saidT)) { $taskRead = true; }
            elseif (preg_match('/price gaps?/i', $saidT)) { $gapRead = true; }
        }
    }
    // EXPAND follow-up — "sabai" / "show all" right after a CAPPED
    // list answer re-runs that same read with the cap lifted. The
    // capped reply carries its own marker (the "…ra aru N ota" /
    // "…and N more" closer), so the referent is proven from Gol's own
    // last words — the same last_spoken machinery as the which/show
    // follow-up above. A work imperative (e.g. "sabai sakiyo" — mark
    // everything done) never expands; it keeps its own lane.
    $expandList = false;
    if (!$workImperative && count($toks) <= 6
        && preg_match('/(sabai|सबै|show\s+all|full\s+list|pura|पुरा)/iu', $msg)) {
        $ssE = golpos_session($sessionId);
        $saidE = (string)($ssE['last_spoken'] ?? '');
        $atE = (int)($ssE['last_spoken_at'] ?? 0);
        if ($saidE !== '' && $atE > 0 && (time() - $atE) < 900
            && preg_match('/(…\s*ra\s+aru\s+\d+\s+ota|…\s*and\s+\d+\s+more)/u', $saidE)) {
            if (preg_match('/(task item|open tasks?|khula task)/i', $saidE)) { $taskRead = true; $expandList = true; }
            elseif (preg_match('/price gaps?/i', $saidE)) { $gapRead = true; $taskRead = false; $expandList = true; }
        }
    }
    if ($gapRead) {
        $actorG = golpos_actor();
        if ($actorG !== null && ($actorG['role'] ?? '') === 'admin') {
            $gaps = [];
            foreach ((array)(golpos_gap_state()['gaps'] ?? []) as $g) {
                if (($g['status'] ?? '') === 'open') { $gaps[] = $g; }
            }
            $npG = in_array($lang, ['np', 'hi', 'dev'], true);
            if (!$gaps) {
                out(['reply' => $npG ? 'Ahile kunai price gap khula chhaina — sabai record cha.'
                                     : 'No price gaps are open right now — every record is filled.']);
                return true;
            }
            $byField = [];
            foreach ($gaps as $g) { $byField[(string)($g['field'] ?? 'price')][] = (string)($g['name'] ?? ''); }
            $lines = [$npG ? 'Khula price gap haru (' . count($gaps) . '):'
                           : 'Open price gaps (' . count($gaps) . '):'];
            foreach ($byField as $f => $names) {
                $names = array_values(array_filter($names, static fn($x) => $x !== ''));
                if (!$names) { continue; }
                // One group line per field (label + count), then the
                // products as a compact set, one per line — capped,
                // with the count still hidden in the closer. Never a
                // comma wall.
                $lines[] = ($f === 'cost' ? ($npG ? 'Cost price chhaina' : 'Cost price missing')
                                          : ucfirst($f)) . ' — ' . count($names) . ':';
                $capG = $expandList ? count($names) : $LIST_CAP;
                foreach (array_slice($names, 0, $capG) as $n) { $lines[] = '  • ' . $n; }
                if (count($names) > $capG) { $lines[] = '  ' . $listCloser(count($names) - $capG, $npG); }
            }
            out(['reply' => implode("\n", $lines)]);
            return true;
        }
        if ($actorG !== null && ($actorG['role'] ?? '') === 'employee') {
            $brief = golpos_gap_brief_line((string)$actorG['email']);
            if ($brief !== '') { out(['reply' => $brief]); return true; }
        }
        // No identified actor: the identity lanes own the answer.
    }
    if ($taskRead) {
        golpos_tasks_sync();
        $actorT = golpos_actor();
        $npT = in_array($lang, ['np', 'hi', 'dev'], true);
        if ($actorT !== null && ($actorT['role'] ?? '') === 'employee') {
            // Her own view — the same source and shape her My Tasks lane
            // has always returned: her items plus the open role items,
            // never another person's list.
            $items = golpos_open_items_for((string)$actorT['email']);
            if (!$items) { out(['reply' => t('tasks_none')]); return true; }
            $nextDue = golpos_next_due((string)$actorT['email']);
            $lines = $nextDue !== null
                ? ['Next: ' . $nextDue['text'] . ($nextDue['reason'] !== '' ? ' — ' . $nextDue['reason'] : ''),
                   'Your open tasks (' . count($items) . '):']
                : ['Your open tasks (' . count($items) . '):'];
            $capE = $expandList ? count($items) : $LIST_CAP;
            foreach (array_slice($items, 0, $capE) as $i => $it) {
                $lines[] = ($i + 1) . '. ' . $it['text']
                    . ($it['source'] === 'gol' && $it['reason'] !== '' ? ' — from Gol: ' . $it['reason'] : '');
            }
            if (count($items) > $capE) { $lines[] = $listCloser(count($items) - $capE, $npT); }
            out(['reply' => implode("\n", $lines)]);
            return true;
        }
        if ($actorT !== null && ($actorT['role'] ?? '') === 'admin') {
            // The owner's list: every open item grouped by who holds
            // it. The greeting's own count (items opened before today)
            // is kept as a closing line so the answer binds to the
            // number he actually saw in the greeting.
            $todayT = date('Y-m-d');
            $groups = []; $orderT = []; $oldN = 0; $newN = 0;
            foreach (golpos_tasks() as $tk) {
                if (($tk['status'] ?? '') === 'superseded' || golpos_task_status($tk) === 'done') { continue; }
                $nmT = trim((string)($tk['employee_name'] ?? ''));
                if ($nmT === '') { $nmT = 'Unassigned (owner inbox)'; }
                $old = substr((string)($tk['created'] ?? ''), 0, 10) < $todayT;
                foreach ((array)($tk['items'] ?? []) as $it) {
                    if (!empty($it['done'])) { continue; }
                    if ($old) { $oldN++; } else { $newN++; }
                    if (!isset($groups[$nmT])) { $groups[$nmT] = []; $orderT[] = $nmT; }
                    $groups[$nmT][] = (string)($it['text'] ?? '');
                }
            }
            $totalT = $oldN + $newN;
            if ($totalT === 0) {
                out(['reply' => $npT ? 'Ahile khula task kehi chhaina — sabai sakiyo.'
                                     : 'No open tasks right now — everything is done.']);
                return true;
            }
            $lines = [$npT ? "Khula task haru ({$totalT}):" : "Open tasks ({$totalT}):"];
            foreach ($orderT as $nmT) {
                $its = $groups[$nmT];
                $lines[] = $nmT . ' — ' . count($its) . ($npT ? ' khula:' : ' open:');
                // Name every subject, ONE PER LINE. A reason tail
                // shared by the whole group is stated once, on its own
                // line, instead of repeated per item; the items then
                // read as a compact set (heads only). A long group is
                // capped and closed with the count still hidden —
                // "sabai" / "show all" expands it in full.
                $heads = []; $tails = [];
                foreach ($its as $tx) {
                    $pos = strpos($tx, ' — ');
                    // Byte math: ' — ' is 5 bytes (space + 3-byte em dash
                    // + space). Skipping only 3 cut the em dash in half
                    // and put a bare continuation byte at the head of
                    // every tail — invalid UTF-8 that made json_encode
                    // fail on the reply AND on the session save (which
                    // then wrote a 0-byte sessions.json). Skip the whole
                    // separator.
                    if ($pos !== false) { $heads[] = substr($tx, 0, $pos); $tails[] = substr($tx, $pos + strlen(' — ')); }
                    else { $heads[] = $tx; $tails[] = ''; }
                }
                $shared = (count($its) > 1 && count(array_unique($tails)) === 1 && $tails[0] !== '') ? $tails[0] : null;
                if ($shared !== null) { $lines[] = '  ' . ($npT ? 'Karan: ' : 'Reason: ') . $shared; }
                $showT = $shared !== null ? $heads : $its;
                $capT = $expandList ? count($showT) : $LIST_CAP;
                foreach (array_slice($showT, 0, $capT) as $tx) { $lines[] = '  • ' . $tx; }
                if (count($showT) > $capT) { $lines[] = '  ' . $listCloser(count($showT) - $capT, $npT); }
            }
            if ($newN > 0) {
                $lines[] = $npT
                    ? "Greeting le ginne {$oldN} hijo ra aghi ka hun; {$newN} aaja khuleka thap hun."
                    : "The greeting counted {$oldN} from yesterday and earlier; {$newN} more opened today.";
            }
            out(['reply' => implode("\n", $lines)]);
            return true;
        }
        // No identified actor: fall through — the identity lanes answer.
    }

    // COUNTING — "how many" over the pack's own sets: the catalog, a
    // category, the out / low shelves, the roster. The composer used to
    // answer these by counting a text string (it said 55 products; the
    // shelf holds 63). A count is a pack fact: the sets are counted
    // here, exactly. List asks ("kun kun sakiyo?") keep their list
    // lanes — this family fires only on a count word, and never when a
    // single item resolved (that is a stock ask, owned below).
    if (preg_match('/(kati|कति|how\s+many)/iu', $msg)
        && !preg_match('/(kun\s+kun|list|dekha|dekhaideu)/iu', $msg)) {
        $rCnt = golpos_reason_resolve($products, $msg);
        if (empty($rCnt['one'])) {
            $npC = in_array($lang, ['np', 'hi', 'dev'], true);
            if (preg_match('/(employee|employees|staff|karmachari|कर्मचारी)/iu', $msg)) {
                $named = [];
                foreach ((array)(golpos_people()['employees'] ?? []) as $e) {
                    $dn = trim((string)($e['name'] ?? ''));
                    if ($dn !== '') { $named[] = $dn; }
                }
                out(['reply' => $npC
                    ? 'Staff ' . count($named) . ' jana chhan: ' . implode(', ', $named) . '.'
                    : 'There are ' . count($named) . ' staff on the roster: ' . implode(', ', $named) . '.']);
                return true;
            }
            if (preg_match('/(sakiyeka|sakisakyo|sakisakeko|out\s*of\s*stock|khatam)/iu', $msg)) {
                $outN = [];
                foreach ($products as $p) {
                    $oh = isset($p['stock']) && is_numeric($p['stock']) ? $p['stock'] + 0
                        : (isset($p['onHand']) && is_numeric($p['onHand']) ? $p['onHand'] + 0 : null);
                    if ($oh !== null && $oh <= 0) { $outN[] = (string)$p['name']; }
                }
                out(['reply' => $npC
                    ? count($outN) . ' ota item sakisakeko chha' . ($outN ? ': ' . implode(', ', $outN) : '') . '.'
                    : count($outN) . ' items are out of stock' . ($outN ? ': ' . implode(', ', $outN) : '') . '.']);
                return true;
            }
            if (preg_match('/(low|kamti|thorai|कम्ती)/iu', $msg) && preg_match('/(stock|item|saman|baki|बाँकी)/iu', $msg)) {
                $lowN = [];
                foreach ($products as $p) {
                    $oh = isset($p['stock']) && is_numeric($p['stock']) ? $p['stock'] + 0
                        : (isset($p['onHand']) && is_numeric($p['onHand']) ? $p['onHand'] + 0 : null);
                    if ($oh !== null && $oh > 0 && $oh <= 5) { $lowN[] = (string)$p['name'] . ' (' . $oh . ')'; }
                }
                out(['reply' => $npC
                    ? count($lowN) . ' ota item ko stock kamti (5 wa kam) chha' . ($lowN ? ': ' . implode(', ', $lowN) : '') . '.'
                    : count($lowN) . ' items are running low (5 or fewer left)' . ($lowN ? ': ' . implode(', ', $lowN) : '') . '.']);
                return true;
            }
            $catHit = null; $catCounts = [];
            foreach ($products as $p) {
                $c = (string)($p['category'] ?? '');
                if ($c !== '') { $catCounts[$c] = ($catCounts[$c] ?? 0) + 1; }
            }
            foreach ($catCounts as $c => $n) {
                if (mb_stripos($msg, $c) !== false
                    || mb_stripos($msg, (string)preg_split('/\s+/', $c)[0]) !== false) { $catHit = $c; break; }
            }
            if ($catHit !== null) {
                out(['reply' => $npC
                    ? $catHit . ' category ma ' . $catCounts[$catHit] . ' ota item cha.'
                    : 'The ' . $catHit . ' category holds ' . $catCounts[$catHit] . ' items.']);
                return true;
            }
            if (preg_match('/(item|items|saman|product|products|catalog|menu)/iu', $msg)) {
                arsort($catCounts);
                $bitsC = [];
                foreach ($catCounts as $c => $n) { $bitsC[] = $c . ' ' . $n; }
                out(['reply' => $npC
                    ? 'Catalog ma jamma ' . count($products) . ' ota item cha — ' . implode(', ', $bitsC) . '.'
                    : 'The catalog holds ' . count($products) . ' items — ' . implode(', ', $bitsC) . '.']);
                return true;
            }
        }
    }

    // "Why is it slow?" — reasoning, answered by the composer above.
    if (preg_match('/\bwhy\b|किन/iu', $msg)
        && preg_match('/(slow|quiet|down|kam|कम|sales|bikri|today|aaja|आज)/iu', $msg)) {
        out(['reply' => golpos_reason_why_slow()]);
        return true;
    }

    // CART CONTENTS — "cart ma ke ke cha?" asks what the open sale
    // HOLDS. The pack carries the cart the client sent; before this
    // block the ask fell to the composer, whose pack does not carry
    // the cart at all, and it answered with a stale sale from another
    // context. Contents are read from the pack, lines and all.
    if (preg_match('/(cart|basket|tokari|टोकरी|current\s+sale|yo\s+sale)/iu', $msg)
        && preg_match('/(ke\s+ke|ke\s+cha|what|kun\s+kun|items?|list|dekha)/iu', $msg)
        && !preg_match('/(total|jamma)/iu', $msg)) {
        $cartK = $pack['cart'];
        if ($cartK['count'] === 0) {
            out(['reply' => $lang === 'np'
                ? 'Cart ahile khali chha — kehi add bhayeko chhaina.'
                : 'The cart is empty right now — nothing has been added yet.']);
            return true;
        }
        $linesK = []; $itemsK = [];
        foreach ($cartK['items'] as $it) {
            $linesK[] = $it['qty'] . ' × ' . $it['name'] . ' — ' . money($it['qty'] * $it['price']);
            $itemsK[] = ['name' => $it['name'], 'qty' => $it['qty'], 'price' => $it['price'],
                'total' => round($it['qty'] * $it['price'], 2)];
        }
        out(['reply' => ($lang === 'np' ? 'Cart ma ahile:' : 'In the cart right now:') . "\n"
                . implode("\n", $linesK)
                . "\n" . ($lang === 'np' ? 'Jamma ' : 'Total ') . money($cartK['total'])
                . ' (subtotal ' . money($cartK['subtotal']) . ' + tax ' . money($cartK['tax']) . ').',
            'dcard' => ['type' => 'sale_session', 'display' => true, 'title' => 'Current sale',
                'items' => $itemsK, 'subtotal' => $cartK['subtotal'], 'tax' => $cartK['tax'], 'total' => $cartK['total']]]);
        return true;
    }

    // "total" while a sale is open means THIS sale. The pack carries the
    // cart, so the answer is the session, its lines read back — a period
    // total always carries a period word and never lands here.
    $cart = $pack['cart'];
    if ($cart['count'] > 0 && preg_match('/(total|jamma|जम्मा)/iu', $msg)
        && !preg_match('/(today|aaja|आज|hijo|yesterday|month|mahina|week|hapta|sales|bikri|hisab|हिसाब|report|close|बन्द)/iu', $msg)) {
        $lines = []; $items = [];
        foreach ($cart['items'] as $it) {
            $lines[] = $it['qty'] . ' × ' . $it['name'] . ' — ' . money($it['qty'] * $it['price']);
            $items[] = ['name' => $it['name'], 'qty' => $it['qty'], 'price' => $it['price'],
                'total' => round($it['qty'] * $it['price'], 2)];
        }
        out(['reply' => "This sale so far:\n" . implode("\n", $lines)
                . "\nSubtotal " . money($cart['subtotal']) . ' + tax ' . money($cart['tax'])
                . ' = ' . money($cart['total']) . '.',
            'dcard' => ['type' => 'sale_session', 'display' => true, 'title' => 'Current sale',
                'items' => $items, 'subtotal' => $cart['subtotal'], 'tax' => $cart['tax'], 'total' => $cart['total']]]);
        return true;
    }

    // STOCK-ADD — "stock ma thapdeu", "add ... to/on our stock" is an
    // inventory INCREASE: not the stock CHECK the ASK lane answers and
    // not a sale. It stages the same stock_receive card a delivery
    // stages, and only "gol" executes it. The block claims the turn
    // before SELL and ASK whenever BOTH the stock destination and an
    // add/increase verb are spoken; a bare stock question ("Red Bull
    // ko stock kati cha") carries no add verb and still falls to the
    // ASK lane's answer, card-free. (Owner ear test, Nov 2: "stock ma
    // thapdeu na chaubis" got a stock check and staged nothing.)
    if (preg_match('/(stock|स्टक)/iu', $msg)
        && preg_match('/(thap|thapdeu|badha|badhau|add|put|hala|joda|rakha|increase|थप|बढा)/iu', $msg)
        && !preg_match('/(kati|how\s+many|how\s+much|कति)/iu', $msg)
        && !golpos_is_info_question($msg)) {
        $r = golpos_reason_resolve($products, $msg);
        $p = !empty($r['one']) ? $r['one'] : golpos_ctx_product($products);
        if ($p !== null) {
            $qty = golpos_reason_loose_qty($msg, $p);
            if ($qty !== null && $qty > 0) {
                $card = golpos_stage(['type' => 'stock_receive', 'sku' => (string)$p['sku'],
                                      'name' => (string)($p['mr_name'] ?? $p['name']), 'qty' => $qty]);
                out(['reply' => t('card_staged'), 'card' => $card]);
                return true;
            }
            out(['reply' => $lang === 'np'
                ? $p['name'] . ' ko stock ma kati ota thapne? Number bhannus, card banaidinchu.'
                : 'How many ' . $p['name'] . ' should I add to stock? Say the number and I will stage the card.']);
            return true;
        }
        if (!empty($r['many'])) { return false; } // a real tie — the lanes ask, with names
        out(['reply' => $lang === 'np'
            ? 'Kun item ko stock thapne? Naam ra kati ota bhannus na.'
            : 'Which item should I add stock for? Say its name and the count.']);
        return true;
    }

    // SELL — a ring word, or a count bound to a classifier in a turn with
    // no price word and no money sign, is a sale. The count is blanked
    // before resolution so nothing downstream can read it as a price or
    // a size; the answer is a read-back and states the running session
    // total from the pack. A PRICE CHANGE never enters here — it carries
    // a price word, and the lanes stage it as always.
    $norm = golpos_norm_digits($msg);
    $qtyBind = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:ta|ota|vata|wata|टा|वटा)\b/iu', $norm, $qm)) { $qtyBind = (float)$qm[1]; }
    $isRingWord = (bool) preg_match('/\bring\b/iu', $msg);
    $sellVeto = (bool) preg_match('/(ayo|aayo|आयो|received|delivery|restock|ginti|गिन्ती|count)/iu', $msg);
    if (!$sellVeto && ($isRingWord
        || ($qtyBind !== null && !preg_match('/(price|mulya|dam|daam|मूल्य|दाम)/iu', $msg) && strpos($msg, '$') === false))) {
        $work = $qtyBind !== null
            ? (preg_replace('/\d+(?:\.\d+)?\s*(?:ta|ota|vata|wata|टा|वटा)\b/iu', ' ', $norm) ?? $msg)
            : $msg;
        $r = golpos_reason_resolve($products, $work);
        if (!empty($r['one'])) {
            $p = $r['one'];
            $n = $qtyBind !== null ? max(1, (int)$qtyBind)
                : max(1, (int)(golpos_qty_in($msg, $p) ?? 1));
            // The session after this add, computed from the pack at
            // shelf prices — the same arithmetic the register runs.
            $items = $pack['cart']['items'];
            $found = false;
            foreach ($items as &$it) {
                if ($it['sku'] === (string)$p['sku']) { $it['qty'] += $n; $found = true; }
            }
            unset($it);
            if (!$found) {
                $items[] = ['sku' => (string)$p['sku'], 'name' => (string)$p['name'], 'qty' => $n, 'price' => (float)$p['price']];
            }
            $rate = (float)(golpos_catalog()['store']['tax_rate'] ?? 0);
            $sub = 0.0; $rlines = [];
            foreach ($items as $it) {
                $sub += $it['qty'] * $it['price'];
                $rlines[] = $it['qty'] . ' × ' . $it['name'] . ' — ' . money($it['qty'] * $it['price']);
            }
            $taxAmt = round($sub * $rate, 2);
            out(['reply' => 'Ringing up ' . $n . ' × ' . $p['name'] . ' — a sale, not a price change.' . "\n"
                    . implode("\n", $rlines)
                    . "\nSale total now: " . money(round($sub + $taxAmt, 2)) . ' (subtotal ' . money(round($sub, 2)) . ' + tax ' . money($taxAmt) . ').',
                'action' => ['type' => 'add', 'sku' => (string)$p['sku'], 'qty' => $n]]);
            return true;
        }
        if (!empty($r['many'])) {
            $names = implode(', ', array_map(static fn($x) => (string)$x['name'], array_slice($r['items'], 0, 4)));
            out(['reply' => 'Which one should I ring — ' . $names . '?']);
            return true;
        }
        out(['reply' => "I couldn't match that item on this register — name it once more and I'll ring it."]);
        return true;
    }

    // ASK about one item — stock and price travel together, whichever was
    // asked, because at the counter "kati cha" after a name means both.
    $wantsStock = (bool) preg_match('/(stock|maujud|on\s*hand|स्टक|बाँकी|baki|left)/iu', $msg);
    $wantsPrice = (bool) preg_match('/(price|mulya|dam|daam|मूल्य|दाम)/iu', $msg);
    if (($wantsStock || ($wantsPrice && $isQ))
        && !preg_match('/(ayo|aayo|आयो|received|delivery|restock|ginti|गिन्ती)/iu', $msg)) {
        $r = golpos_reason_resolve($products, $msg);
        if (!empty($r['one'])) {
            golpos_reason_answer_item($r['one'], $sessionId, $lang);
            return true;
        }
        if (!empty($r['many'])) { return false; } // a real tie — the lane asks, with prices
        // A follow-up with no item of its own binds to the conversation
        // topic ("tesko price kati ho?" right after a Coke answer). The
        // ctx is the pack's own memory of what was just resolved; the
        // interpreter pass below was the only road here before, and a
        // pronoun follow-up fell through it to the composer.
        $ctxP = golpos_ctx_product($products);
        if ($ctxP !== null
            && (preg_match('/(tesko|tyasko|yesko|esko|tyo|that|this|it\b)/iu', $msg) || count($toks) <= 4)) {
            golpos_reason_answer_item($ctxP, $sessionId, $lang);
            return true;
        }
        if (preg_match('/(out|low|sakiyo|sakina|khatam|खतम|सकियो)/iu', $msg)) { return false; } // aggregate lists are the lane's
        // An in-catalog near-miss the ear mangled: one interpreter pass
        // over the shelf, validated — anything less is answered honestly.
        $hit = golpos_reason_interpret($products, $msg);
        if ($hit !== null) {
            golpos_reason_answer_item($hit, $sessionId, $lang);
            return true;
        }
        out(['reply' => $lang === 'np'
            ? 'Item ko naam bujhina — naam feri ek choti bhannus, stock ra mulya ahile dinchhu.'
            : "I didn't catch the item name in that — say the name once more and I'll give you its stock and price right away."]);
        return true;
    }

    // Fully garbled speech lands here: short, no digits, no shelf hit,
    // no domain word at all. The honest answer is "didn't catch that" —
    // never the capability menu, which reads as a deflection. Greetings
    // and smalltalk are never garble — they keep their own lanes.
    if (count($toks) <= 4 && !preg_match('/\d/u', $msg)
        && !golpos_is_bare_greeting($msg) && !golpos_is_smalltalk($msg)
        && !golpos_is_cancel_intent($msg) && !golpos_is_legacy_confirm($msg)
        && !preg_match('/(setting|settings|print|receipt|hour|hours|open|close|lock|pin|admin|employee|staff|task|kam|काम|note|calendar|bill|vendor|order|refund|credit|batch|camera|poster|report|sales|sale|total|stock|price|item|product|add|ring|help|who|team|pay|rate|photo|scan)/iu', $msg)) {
        $r = golpos_reason_resolve($products, $msg);
        if (empty($r['one']) && empty($r['many'])) {
            out(['reply' => $lang === 'np'
                ? 'Bujhina — feri ek choti bhannus na?'
                : "I didn't catch that — say it once more?"]);
            return true;
        }
    }
    return false;
}

// Every number WORD in the sentence as digits (EN 1–99 incl. compound
// tens, Romanized Nepali 1–30, Devanagari core) — UNBOUND conversion:
// the caller has already decided the turn's intent and stripped what
// must not count (sizes, money, and — via golpos_mask_sizes — the
// item's own name, whose number words are part of the name, never a
// quantity). Used where a standing number word IS the quantity even
// when it binds to nothing: golpos_qty_in and the stock-add reader.
function golpos_numwords_to_digits(string $s): string {
    $tens = ['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
             'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];
    $ones = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
             'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];
    $tAlt = implode('|', array_keys($tens));
    $oAlt = implode('|', array_keys($ones));
    $s = (string) preg_replace_callback('/\b(' . $tAlt . ')[-\s](' . $oAlt . ')\b/iu',
        static function ($m) use ($tens, $ones) {
            return (string) (($tens[mb_strtolower($m[1])] ?? 0) + ($ones[mb_strtolower($m[2])] ?? 0));
        }, $s);
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
              'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
              'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
              'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
              'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
              'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
              'ek' => 1, 'euta' => 1, 'ekta' => 1, 'dui' => 2, 'duita' => 2, 'tin' => 3,
              'char' => 4, 'chaar' => 4, 'panch' => 5, 'paanch' => 5, 'chha' => 6,
              'saat' => 7, 'aath' => 8, 'nau' => 9, 'das' => 10,
              'eghara' => 11, 'bahra' => 12, 'chaudha' => 14, 'choudha' => 14,
              'pandhra' => 15, 'pandra' => 15, 'sohra' => 16, 'satra' => 17,
              'athara' => 18, 'unnais' => 19, 'bis' => 20, 'ekkais' => 21, 'bais' => 22,
              'teis' => 23, 'chaubis' => 24, 'pachchis' => 25, 'chhabbis' => 26,
              'sattais' => 27, 'aththais' => 28, 'unantis' => 29,
              'दुई' => 2, 'तीन' => 3, 'चार' => 4, 'पाँच' => 5, 'छ' => 6,
              'सात' => 7, 'आठ' => 8, 'नौ' => 9, 'दश' => 10, 'एघार' => 11, 'बाह्र' => 12,
              'चौध' => 14, 'पन्ध्र' => 15, 'सोह्र' => 16, 'सत्र' => 17, 'अठार' => 18,
              'उन्नाइस' => 19, 'बीस' => 20, 'चौबीस' => 24, 'तीस' => 30];
    $alt = implode('|', array_map(static fn($w) => preg_quote($w, '/'), array_keys($words)));
    $s = (string) preg_replace_callback('/\b(' . $alt . ')\b/iu',
        static function ($m) use ($words) { return (string) ($words[mb_strtolower($m[1])] ?? $m[1]); }, $s);
    return $s;
}

// The quantity for a stock-add turn: the strict count reader first
// (classifier-bound and item-bound counts); then a loose read for a
// number standing free at the end of the breath — "stock ma thapdeu
// na chaubis" binds 24 to nothing the strict reader accepts, and in a
// turn that already carries an add verb and the stock destination it
// IS the quantity. Sizes and money are stripped before the loose
// read, and the product's own name is masked, so a number inside a
// name or a size can never be taken as the count.
function golpos_reason_loose_qty(string $msg, ?array $product): ?int {
    $q = golpos_extract_count(golpos_count_words($msg, $product), $product);
    if ($q !== null && $q > 0) { return (int) round($q); }
    $t = golpos_norm_digits($msg);
    if ($product !== null) { $t = golpos_mask_sizes($t, $product); }
    $t = preg_replace('/\$\s*\d+(?:\.\d+)?/u', ' ', $t) ?? $t;
    $t = golpos_numwords_to_digits($t);
    if (preg_match('/(?<!\d)(\d{1,3})(?!\d)/u', $t, $m)) {
        $n = (int) $m[1];
        if ($n >= 1 && $n <= 999) { return $n; }
    }
    return null;
}

// An explicit quantity in a turn that carries no classifier count:
// "2 coke ring gara", "dui coke ring gara". Sizes ("20oz"), prices
// ("$2") and years are stripped first so they can never become a count.
// Number WORDS count too ("twenty-four ... ring gara" is 24) — the
// item's own name is masked first when the caller knows it, so a
// number word inside a name ("Five Hour Energy") is never a quantity.
// Returns null when no explicit quantity is spoken (the caller rings 1).
function golpos_qty_in(string $msg, ?array $product = null): ?int {
    $t = golpos_norm_digits($msg);
    if ($product !== null) { $t = golpos_mask_sizes($t, $product); }
    $t = preg_replace('/\$\s*\d+(?:\.\d+)?/u', ' ', $t) ?? $t;
    $t = preg_replace('/\d+(?:\.\d+)?[\s-]*(?:ounces?|oz|ml|l|lb|lbs|g|kg|pack|pk|ct|count|gram|grams)\b/iu', ' ', $t) ?? $t;
    $t = preg_replace('/\b(?:19|20)\d{2}\b/u', ' ', $t) ?? $t;
    $t = golpos_numwords_to_digits($t);
    if (preg_match('/(?<!\d)(\d{1,2})(?!\d)/u', $t, $m)) {
        $n = (int)$m[1];
        if ($n >= 1 && $n <= 99) { return $n; }
    }
    return null;
}

function golpos_brain_route(string $msg, array $in, array $catalog, array $products, string $shop, array $session, string $sessionId): void {
    $GLOBALS['golpos_products'] = $products;
    if (golpos_cancel_card_handle($in, $session, $sessionId)) { return; }
    if (golpos_is_who_intent($msg)) { golpos_who_answer($msg, $sessionId); }
    if (golpos_reason_layer($msg, $in, $products, $sessionId)) { return; }
    golpos_bigbrain_handle($msg, $in, $session, $sessionId, $shop);
    golpos_dispatch($msg, $in, $catalog, $products, $shop);
}

// Lazy AUTO batch pass first: any chat call can be the one that finds the
// store past closing with a card segment waiting (see store.php).
golpos_batch_auto_sweep();

$sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
if ($sessionId === '') { $sessionId = 'anon'; }
$GLOBALS['golpos_session_id'] = $sessionId;
$session = golpos_session($sessionId);

// An identified employee device (invite flow) marks the session as that
// employee. Identity grants the cashier domain only — never elevation.
if (isset($in['employee']) && is_array($in['employee'])) {
    $emp = golpos_employee_by_device((string)($in['employee']['email'] ?? ''),
                                     (string)($in['employee']['token'] ?? ''));
    if ($emp !== null) { $session['employee'] = (string)$emp['email']; }
}

// --- the morning lock: PIN please (employee lane, Oct 28) ----------------------
// When the roster holds any PIN, a session starts LOCKED: the camera
// may watch and Gol may chat, but the only conversation is "PIN
// please." A roster PIN opens the register IN THAT PERSON'S NAME —
// the employee's own PIN to her cashier session, the admin PIN to
// admin mode. The standing 3-strike lockout guards this door too, and
// a 3-strike lockout is itself stamped into the owner's unlock feed.
// The lock is the register UI's (v1): the brain refuses every lane
// while locked, which is the surface the till actually uses.
if (golpos_lock_state($session)) {
    $session['pin_stage'] = null;
    $session['pending'] = null;
    if (golpos_is_cancel_intent($msg) || golpos_is_lock_intent($msg)) {
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_please'), 'pin_mask' => true]);
    }
    $pin = golpos_pin_shaped($msg);
    if ($pin === null) {
        if (preg_match('/^\d+$/', golpos_norm_digits(trim($msg)))) {
            out(['reply' => t('pin_bad_format'), 'pin_mask' => true]);
        }
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_please'), 'pin_mask' => true]);
    }
    $now = time();
    if ((int)($session['locked_until'] ?? 0) > $now) {
        out(['reply' => t('pin_locked', (int)ceil(((int)$session['locked_until'] - $now) / 60)),
             'pin_mask' => true]);
    }
    $actor = null;
    $adminHash = golpos_admin_pin_hash();
    if ($adminHash !== null && password_verify($pin, $adminHash)) {
        $actor = ['role' => 'admin', 'email' => '', 'name' => 'Owner', 'record' => null];
    } else {
        $matches = [];
        foreach (golpos_people()['employees'] as $e) {
            if (!empty($e['pin_hash']) && password_verify($pin, (string)$e['pin_hash'])) {
                $matches[] = $e;
            }
        }
        if (count($matches) > 1) {
            out(['reply' => t('unlock_shared'), 'pin_mask' => true]);
        }
        if (count($matches) === 1) {
            $e = $matches[0];
            $actor = ['role' => 'employee', 'email' => (string)$e['email'],
                      'name' => golpos_display_name($e), 'record' => $e];
        }
    }
    if ($actor === null) {
        $session['attempts'] = (int)($session['attempts'] ?? 0) + 1;
        if ($session['attempts'] >= 3) {
            $session['attempts'] = 0;
            $session['locked_until'] = $now + 180;
            $GLOBALS['golpos_unlock_event'] = golpos_unlock_add(
                ['kind' => 'lockout', 'name' => 'Unknown', 'email' => '', 'role' => '']);
            golpos_save_session($sessionId, $session);
            out(['reply' => t('pin_lockout'), 'pin_mask' => true]);
        }
        golpos_save_session($sessionId, $session);
        $left = 3 - (int)$session['attempts'];
        out(['reply' => t('pin_wrong', $left, $left === 1 ? 'try' : 'tries'), 'pin_mask' => true]);
    }
    // The unlock itself: identity stamps, the feed event, the task
    // sync (Gol's own tasks are generated at unlock), the greeting.
    $firstUnlock = false;
    $prevUnlockAt = null;
    if ($actor['role'] === 'employee' && $actor['record'] !== null) {
        $pres = golpos_presence_for($actor['record']);
        $firstUnlock = empty($pres['first_unlock_at']);
        $prevUnlockAt = $pres['last_unlock_at'] ?? null;
    }
    $session['locked'] = false;
    $session['admin'] = $actor['role'] === 'admin';
    $session['attempts'] = 0;
    $session['locked_until'] = 0;
    $session['unlocked_by'] = $actor['role'] === 'admin' ? 'admin' : $actor['email'];
    $session['unlocked_name'] = $actor['name'];
    $session['unlocked_role'] = $actor['role'];
    if ($actor['role'] === 'employee') { $session['employee'] = $actor['email']; }
    $GLOBALS['golpos_unlock_event'] = golpos_unlock_add(
        ['kind' => 'unlock', 'name' => $actor['name'],
         'email' => $actor['role'] === 'admin' ? '' : $actor['email'], 'role' => $actor['role']]);
    golpos_save_session($sessionId, $session);
    golpos_tasks_sync();
    if ($actor['role'] === 'employee' && $actor['record'] !== null) {
        golpos_presence_touch_unlock($actor['record'], false);
    }
    out(['reply' => golpos_unlock_greeting($actor, $firstUnlock, $prevUnlockAt), 'pin_mask' => true,
         'unlocked' => ['name' => $actor['name'], 'role' => $actor['role'],
                        'email' => $actor['role'] === 'admin' ? '' : $actor['email']]]);
}

// "lock" / "admin bata bahira" — drop elevation on the spot AND lock
// the register: the next person opens it with their own PIN. In setup
// mode (NO roster PIN anywhere) a lock can never take effect —
// golpos_lock_state stays false and the overlay never rises — so the
// word used to "succeed" silently while nothing happened (owner trace,
// Oct 31: he pressed Lock on a PIN-less register and hit a wall). With
// no PIN the truthful answer is the PIN-creation path, not a fake lock.
if (golpos_is_lock_intent($msg)) {
    $session['pin_stage'] = null;
    $session['pin_tmp'] = null;
    $session['pending'] = null;
    $session['price_await'] = null;
    $session['ctx'] = null;
    golpos_save_session($sessionId, $session);
    if (!golpos_roster_has_pin()) {
        out(['reply' => golpos_pin_help_reply()]);
    }
    golpos_lock_session($sessionId);
    out(['reply' => t('admin_off')]);
}

// A live PIN flow consumes the next message before anything else runs.
if (!empty($session['pin_stage'])) {
    $stage = (string)$session['pin_stage'];
    if (golpos_is_cancel_intent($msg)) {
        $session['pin_stage'] = null;
        $session['pin_tmp'] = null;
        $session['pending'] = null;
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_cancelled'), 'pin_mask' => true]);
    }
    $pin = golpos_pin_shaped($msg);
    if ($pin === null) {
        if ($stage === 'verify') {
            // Not a PIN — the wait is abandoned and the message runs as a
            // fresh one (the gate below re-judges it on its own).
            $session['pin_stage'] = null;
            $session['pending'] = null;
            golpos_save_session($sessionId, $session);
        } elseif (preg_match('/^\d+$/', golpos_norm_digits(trim($msg)))) {
            // Pure digits in the wrong shape: a genuine PIN attempt with a
            // typo — keep the wait and say the format line.
            out(['reply' => t('pin_bad_format'), 'pin_mask' => true]);
        } else {
            // Create stages: a message carrying words is not a PIN attempt
            // at all — it is a new message that arrived while the setup
            // wait was armed. Disarm and let it run fresh (hisab test
            // D-11: five unrelated asks were eaten as PIN tries).
            $session['pin_stage'] = null;
            $session['pin_tmp'] = null;
            $session['pending'] = null;
            golpos_save_session($sessionId, $session);
        }
    } elseif ($stage === 'create1') {
        $session['pin_tmp'] = password_hash($pin, PASSWORD_DEFAULT);
        $session['pin_stage'] = 'create2';
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_create_confirm'), 'pin_mask' => true]);
    } elseif ($stage === 'create2') {
        if (!password_verify($pin, (string)($session['pin_tmp'] ?? ''))) {
            $session['pin_tmp'] = null;
            $session['pin_stage'] = 'create1';
            golpos_save_session($sessionId, $session);
            out(['reply' => t('pin_create_mismatch'), 'pin_mask' => true]);
        }
        golpos_set_admin_pin((string)$session['pin_tmp']);
        $session['admin'] = true;
        $session['locked'] = false;
        $session['unlocked_by'] = 'admin';
        $session['unlocked_name'] = 'Owner';
        $session['unlocked_role'] = 'admin';
        $session['pin_stage'] = null;
        $session['pin_tmp'] = null;
        $pending = $session['pending'];
        $session['pending'] = null;
        golpos_save_session($sessionId, $session);
        if (is_array($pending) && (string)($pending['message'] ?? '') !== '') {
            $GLOBALS['golpos_pin_mask'] = true;
            golpos_brain_route((string)$pending['message'],
                            ['confirm' => !empty($pending['confirm'])], $catalog, $products, $shop, $session, $sessionId);
        }
        out(['reply' => t('pin_set'), 'pin_mask' => true]);
    } else { // verify
        $now = time();
        if ((int)($session['locked_until'] ?? 0) > $now) {
            out(['reply' => t('pin_locked', (int)ceil(((int)$session['locked_until'] - $now) / 60)),
                 'pin_mask' => true]);
        }
        $hash = golpos_admin_pin_hash();
        if ($hash !== null && password_verify($pin, $hash)) {
            $session['admin'] = true;
            $session['locked'] = false;
            $session['unlocked_by'] = 'admin';
            $session['unlocked_name'] = 'Owner';
            $session['unlocked_role'] = 'admin';
            $session['pin_stage'] = null;
            $session['attempts'] = 0;
            $pending = $session['pending'];
            $session['pending'] = null;
            golpos_save_session($sessionId, $session);
            // A bare "admin"/"pin" ask has nothing behind it — just confirm.
            if (is_array($pending) && trim((string)($pending['message'] ?? '')) !== ''
                && !preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', (string)$pending['message'])) {
                $GLOBALS['golpos_pin_mask'] = true;
                golpos_brain_route((string)$pending['message'],
                                ['confirm' => !empty($pending['confirm'])], $catalog, $products, $shop, $session, $sessionId);
            }
            out(['reply' => t('admin_on'), 'pin_mask' => true]);
        }
        $session['attempts'] = (int)($session['attempts'] ?? 0) + 1;
        if ($session['attempts'] >= 3) {
            $session['attempts'] = 0;
            $session['locked_until'] = $now + 180;
            $session['pin_stage'] = null;
            $session['pending'] = null;
            golpos_save_session($sessionId, $session);
            out(['reply' => t('pin_lockout'), 'pin_mask' => true]);
        }
        golpos_save_session($sessionId, $session);
        $left = 3 - (int)$session['attempts'];
        out(['reply' => t('pin_wrong', $left, $left === 1 ? 'try' : 'tries'), 'pin_mask' => true]);
    }
}

// A bill waiting on shelf prices owns the next answers: the read bill
// rides the session (bill_await) until every new line is priced, the
// owner cancels, or the completed bill stages as one card. Staging is a
// cashier-domain act like any card's; the EXECUTION is gated at the
// confirm word (the 'bill' card type in golpos_needs_admin). While a
// card is staged — this bill's own, ready to execute — the gate and the
// confirm flow own the conversation instead.
if (!empty($session['bill_await']) && golpos_pending() === null) {
    $await = $session['bill_await'];
    if (golpos_is_cancel_intent($msg)) {
        $session['bill_await'] = null;
        golpos_save_session($sessionId, $session);
        out(['reply' => t('bill_cancelled')]);
    }
    if (in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true) || !empty($in['confirm'])) {
    if (golpos_echo_confirm($msg, $in)) { out(['reply' => 'That was my own voice. The card is still waiting. Tap Confirm after I am quiet.']); }

        out(['reply' => t('bill_reask', golpos_bill_missing_list($await))]);
    }
    $updated = golpos_bill_apply_prices($await, $msg);
    if ($updated !== null) {
        if (golpos_bill_missing_list($updated) === '') {
            $card = golpos_stage(['type' => 'bill', 'vendor' => (string)($updated['vendor'] ?? ''),
                                  'image' => (string)($updated['image'] ?? ''),
                                  'lines' => $updated['lines'],
                                  'total' => (float)($updated['total'] ?? 0)]);
            $session['bill_await'] = null;
            golpos_save_session($sessionId, $session);
            out(['reply' => t('card_staged'), 'card' => $card]);
        }
        $session['bill_await'] = $updated;
        golpos_save_session($sessionId, $session);
        out(['reply' => t('bill_reask', golpos_bill_missing_list($updated))]);
    }
    out(['reply' => t('bill_reask', golpos_bill_missing_list($await))]);
}

// A price-gap prompt Gol put in her chat owns the follow-up the same
// way (price-gap engine, Oct 28; pricegap.php): her number stages the
// fill card, "thaha chhaina" parks the item under a long backoff, a
// dismiss snoozes it for her shift, and anything else drops the wait
// and runs its normal course. Cashier domain — the handler sits with
// the other waits, before the gate; the fill card's EXECUTION carries
// the price lane's own gate, not this conversation.
if (!empty($session['gap_await']) && golpos_pending() === null) {
    $gapResp = golpos_gap_await_reply($session, $sessionId, $msg, $products);
    if ($gapResp !== null) { out($gapResp); }
}

// A price question Gol just asked owns the follow-up the same way a bill
// does: the owner's next words carry the missing piece (the price, or the
// item pick when the price was already said) and the staging completes —
// Gol never asks again for what it was already told. Three shapes ride
// here: the item is known and the PRICE is missing (sku); the price is
// known and the pick is one of a named tie (item_skus); the price is
// known and the ITEM was never named at all (need_item — armed when a
// price-change sentence carried its price but no item and the
// conversation held no topic to bind). Two standing rules bind every
// shape: a bare greeting cancels the wait and takes its normal path,
// and a QUESTION passes through — answered by its own lane, the wait
// untouched — because an interruption is not an answer. A follow-up
// with no number and no candidate item in it is a topic change: the
// wait drops and the message runs its normal course through the gate
// and dispatch. The one exception is need_item, where Gol asked the
// question itself and owns it: an answer that resolves nothing earns
// ONE plain re-ask before the wait drops.
if (!empty($session['price_await']) && golpos_pending() === null) {
    $await = $session['price_await'];
    if (golpos_is_cancel_intent($msg)) {
        $session['price_await'] = null;
        golpos_save_session($sessionId, $session);
        out(['reply' => t('ask_cancelled')]);
    }
    if (golpos_is_bare_greeting($msg) || golpos_is_smalltalk($msg)) {
        $session['price_await'] = null;
        golpos_save_session($sessionId, $session);
        // fall through: the greeting takes its normal path, wait-free
    } else {
    $isConfirm = in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true) || !empty($in['confirm']);
    $target = null; $newPrice = null; $reask = null; $topicChange = false; $passThrough = false;
    if (!empty($await['need_item']) && isset($await['price'])) {
        if ($isConfirm) { $reask = t('price_ask_item', money((float)$await['price'])); }
        elseif (golpos_is_question($msg)) { $passThrough = true; }
        else {
            $r = resolve_item($products, $msg);
            if (!empty($r['one'])) { $target = $r['one']; $newPrice = (float)$await['price']; }
            elseif (!empty($r['many'])) {
                // The answer narrowed the field to a tie: the wait
                // becomes the named-tie shape and asks the pick.
                $session['price_await'] = ['price' => (float)$await['price'],
                    'item_skus' => array_map(static fn($c) => (string)$c['sku'], $r['many'])];
                golpos_save_session($sessionId, $session);
                $list = implode(', ', array_map(static fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
                out(['reply' => t('which_price', $list)]);
            }
            elseif ((int)($await['reasks'] ?? 0) >= 1) { $topicChange = true; }
            else {
                $await['reasks'] = 1;
                $session['price_await'] = $await;
                golpos_save_session($sessionId, $session);
                $reask = t('price_ask_item', money((float)$await['price']));
            }
        }
    } elseif (!empty($await['sku'])) {
        foreach ($products as $p) {
            if ((string)$p['sku'] === (string)$await['sku']) { $target = $p; break; }
        }
        if ($target === null) { $topicChange = true; }
        elseif ($isConfirm) { $reask = t('price_ask', (string)$target['name']); }
        else {
            $ex = golpos_extract_price($msg, $target);
            if ($ex['status'] !== 'ok' && golpos_is_question($msg)) { $passThrough = true; $target = null; }
            elseif ($ex['status'] === 'ambiguous') {
                $reask = t('price_ask_which', (string)$target['name'], money($ex['candidates'][0]), money($ex['candidates'][1]));
            } elseif ($ex['status'] === 'ok') {
                $cands = array_map(static fn($v) => round((float)$v, 2), (array)($await['price_candidates'] ?? []));
                if ($cands !== [] && !in_array(round((float)$ex['value'], 2), $cands, true)) {
                    $reask = t('price_ask_which', (string)$target['name'], money($cands[0]), money($cands[1] ?? $cands[0]));
                } else { $newPrice = (float)$ex['value']; }
            } elseif (!preg_match('/\d|\$/u', $msg)) { $topicChange = true; $target = null; }
            else { $reask = t('price_ask', (string)$target['name']); }
        }
    } elseif (!empty($await['item_skus']) && isset($await['price'])) {
        $cands = array_values(array_filter($products,
            static fn($p) => in_array((string)$p['sku'], (array)$await['item_skus'], true)));
        $list = implode(', ', array_map(static fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $cands));
        if ($isConfirm) { $reask = t('which_price', $list); }
        else {
            $r = resolve_item($cands, $msg);
            if (!empty($r['one'])) { $target = $r['one']; $newPrice = (float)$await['price']; }
            elseif (!empty($r['many'])) { $reask = t('which_price', $list); }
            elseif (golpos_is_question($msg)) { $passThrough = true; }
            elseif (!preg_match('/\d|\$/u', $msg)) { $topicChange = true; }
            else { $reask = t('which_price', $list); }
        }
    } else { $topicChange = true; }

    if ($target !== null && $newPrice !== null) {
        $session['price_await'] = null;
        golpos_save_session($sessionId, $session);
        if ($newPrice === (float)$target['price']) {
            out(['reply' => t('price_same', (string)$target['name'], money($newPrice))]);
        }
        golpos_ctx_set($target, 'price_change');
        $card = golpos_stage(['type' => 'price_change', 'sku' => $target['sku'], 'name' => $target['name'],
                              'old_price' => $target['price'], 'new_price' => $newPrice]);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
    if ($reask !== null) { out(['reply' => $reask]); }
    if ($topicChange) {
        $session['price_await'] = null;
        golpos_save_session($sessionId, $session);
        // fall through: the gate and dispatch see the message fresh
    }
    // $passThrough: fall through with the wait intact — the question is
    // answered by its own lane and the answer to Gol's question can
    // still arrive as the next message.
    }
}

// --- PIN / admin guidance (owner trace, Oct 31) ---------------------------
// An already-elevated session never meets the gate below, so the owner's
// own PIN words ("admin", "set pin", "pin banaune", a bare 4–6 digit
// number typed as his intended PIN) used to fall through to the free-form
// composer and die there ("I can't help with that…", digits standing in
// the thread unmasked). This intercept owns those words BEFORE the gate
// and the dispatch — but AFTER every live wait above (a PIN flow, a bill,
// a price ask) has had its own claim on the message. A bare number here
// is never treated as a PIN, never verified, never echoed: the reply
// carries pin_mask so the client masks the typed bubble, and the answer
// is the real gate path for this register's PIN state.
if (golpos_pin_shaped($msg) !== null) {
    out(['reply' => t('pin_never_chat') . "\n" . golpos_pin_help_reply(), 'pin_mask' => true]);
}
// An actionable PIN-set ask (set verb + a named person or the admin
// PIN) is NOT guidance: it falls through to the gate and the pin_set
// lane, which stage the owner's card (TASKS 231).
if ((golpos_is_pin_help_intent($msg)
        && !(golpos_is_pin_set_intent($msg) && golpos_pin_set_target($msg) !== null))
    || (!empty($session['admin']) && preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg))) {
    out(['reply' => golpos_pin_help_reply()]);
}

// The gate itself: an admin-domain message from an unelevated session gets
// the PIN demand and NOTHING else — no figures, no hints, no partial answer.
if (empty($session['admin']) && golpos_needs_admin($msg, $in)) {
    if (golpos_admin_pin_hash() === null) {
        // No PIN exists on this register yet. The create-PIN wait arms
        // ONLY behind a genuinely pending gated action — executing a
        // staged card on the confirm word (the Beat-3 choreography) — or
        // when the owner explicitly asks for the admin/PIN setup. A plain
        // gated READ (a month total, a close, the board) must never arm
        // the PIN stage machine (hisab test R03/D-11: a month-total
        // question opened PIN-create and the armed wait then ate the
        // next five messages). The read gets the truthful gate line and
        // the chat stays free.
        $execute = !empty($in['confirm'])
            || in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true);
        $setupAsk = preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg) === 1;
        if (!$execute && !$setupAsk) {
            out(['reply' => t('pin_not_set')]);
        }
        $session['pending'] = ['message' => $msg, 'confirm' => !empty($in['confirm'])];
        $session['pin_stage'] = 'create1';
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_create_ask')]);
    }
    $session['pending'] = ['message' => $msg, 'confirm' => !empty($in['confirm'])];
    if ((int)($session['locked_until'] ?? 0) > time()) {
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_locked', (int)ceil(((int)$session['locked_until'] - time()) / 60))]);
    }
    $session['pin_stage'] = 'verify';
    golpos_save_session($sessionId, $session);
    out(['reply' => t('pin_ask')]);
}

// Calendar day taps arrive as a silent action, never as a message:
// they skip the trail log and every brain lane (owner catch, Oct 31 —
// a tap used to post "YYYY-MM-DD report" into the thread and stack a
// whole new report under it). Read-only; out() exits inside.
if (($in['action'] ?? '') === 'day_detail') { golpos_day_detail($in, $session); }

// The presence trail's question record: an EMPLOYEE session's turns
// are logged owner-side (what she asked Gol, and when). PIN-shaped
// turns and PIN-flow turns are never recorded — digits are not data.
if (empty($session['pin_stage']) && golpos_pin_shaped($msg) === null) {
    $trailEmail = null; $trailName = '';
    if (($session['unlocked_role'] ?? '') === 'employee' && !empty($session['unlocked_by'])) {
        $trailEmail = (string)$session['unlocked_by'];
        $trailName = (string)($session['unlocked_name'] ?? '');
    } elseif (!empty($session['employee'])) {
        $trailEmail = (string)$session['employee'];
        $ee = golpos_employee($trailEmail);
        $trailName = $ee !== null ? golpos_display_name($ee) : '';
    }
    if ($trailEmail !== null) { golpos_turn_log($sessionId, $trailEmail, $trailName, $msg); }
}

golpos_brain_route($msg, $in, $catalog, $products, $shop, $session, $sessionId);
