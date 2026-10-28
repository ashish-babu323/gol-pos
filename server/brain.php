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
    // When the access gate consumed this turn's message as a PIN (including
    // when it replayed the pending intent after a successful PIN), the
    // client masks the typed bubble — the digits never stand in the history.
    if (!empty($GLOBALS['golpos_pin_mask'])) { $r['pin_mask'] = true; }
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
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
           'bhannus', 'saman', 'naya', 'pasal', 'banda', ' kun ', ' tyo ', ' lekha ', ' din '];
    $hi = ['kitna', 'kitne', 'daam', 'jodo', 'lagao', 'saaman', 'karo', 'badlo',
           'hisaab', ' hai ', ' aaj ', ' mein '];
    $n = 0; foreach ($np as $w) { if (mb_strpos($t, $w) !== false) { $n++; } }
    $h = 0; foreach ($hi as $w) { if (mb_strpos($t, $w) !== false) { $h++; } }
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
    'en' => 'Which product should the poster feature? Say its name — e.g. "make an offer poster for Coke".',
    'np' => 'Poster ma kun saman rakhne? Naam bhannus — jastai "Coke ko offer poster bana".',
    'hi' => 'Poster mein kaun sa saaman ho? Naam bataiye — jaise "Coke ka offer poster banao".',
    'dev' => 'पोस्टरमा कुन सामान राख्ने? नाम भन्नुस् — जस्तै "कोकको अफर पोस्टर बनाऊ"।'],
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
    'en' => 'I can add items, quote or change prices (staged as a card), give today\'s total, and run the receipt print settings. Try: "print settings" or "How much is Coke?"',
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
  'card_cancelled' => [
    'en' => 'Cancelled — the staged card is cleared; nothing was changed.',
    'np' => 'Radda bhayo — stage bhayeko card clear bhayo; kei change bhayena.',
    'hi' => 'Cancel ho gaya — staged card clear hai; kuchh change nahi hua.',
    'dev' => 'रद्द भयो — stage भएको card clear भयो; केही change भएन।'],
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
    'en' => 'I haven\'t staged anything — no card is waiting. Tell me the item and the exact new price (like "change Coke to $3") and I\'ll stage the real card here.',
    'np' => 'Maile kunai card banaeko chhaina — kehi parkhi rako chhaina. Saman ra naya mulya spashta bhannus (jastai "Coke to $3") — asal card yahi stage garchhu.',
    'hi' => 'Maine koi card nahi banaya hai — kuchh intezaar nahi kar raha. Saaman aur naya daam saaf bataiye (jaise "Coke to $3") — asal card yahin stage karunga.',
    'dev' => 'मैले कुनै कार्ड बनाएको छैन — केही पर्खिरहेको छैन। सामान र नयाँ मूल्य स्पष्ट भन्नुस् (जस्तै "Coke to $3") — असली कार्ड यहीँ स्टेज गर्छु।'],
  'already_staged' => [
    'en' => 'That card is already staged: %s — say "gol" to run it.',
    'np' => 'Tyo card pahile nai stage chha: %s — chalauna "gol" bhannus.',
    'hi' => 'Woh card pehle se stage hai: %s — chalane ke liye "gol" kahiye.',
    'dev' => 'त्यो कार्ड पहिल्यै स्टेज छ: %s — चलाउन "गोल" भन्नुस्।'],
  'stage_what' => [
    'en' => 'Stage what? Nothing is waiting right now — tell me the item and the new price (like "change Coke to $3") and I\'ll stage the card.',
    'np' => 'Ke stage garne? Ahile kehi parkhi rako chhaina — saman ra naya mulya bhannus (jastai "Coke to $3"), card stage garchhu.',
    'hi' => 'Kya stage karoon? Abhi kuchh intezaar nahi kar raha — saaman aur naya daam bataiye (jaise "Coke to $3"), card stage karunga.',
    'dev' => 'के स्टेज गर्ने? अहिले केही पर्खिरहेको छैन — सामान र नयाँ मूल्य भन्नुस् (जस्तै "Coke to $3"), कार्ड स्टेज गर्छु।'],
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
  // --- register order prep (owner directive, Oct 28) -------------------------
  'ord_none_needed' => [
    'en' => '%s — nothing needs ordering today: every line on its list is covered (%d lines checked). No card staged.',
    'np' => '%s — aaja order chahinna: usko list ko sabai line covered chha (%d line heriyo). Card stage bhayena.',
    'hi' => '%s — aaj order ki zaroorat nahi: uski list ki har line covered hai (%d line dekhi). Card stage nahi hua.',
    'dev' => '%s — आज अर्डर चाहिन्न: उसको सूचीको सबै लाइन covered छ (%d लाइन हेरियो)। कार्ड स्टेज भएन।'],
  'ord_head' => [
    'en' => 'Order draft for %s (rep day %s) — Gol\'s draft from the shelf, sales since the last delivery, and the usual bill lines. Edit in chat ("Coke 12 hata", "Pepsi 20 thap"), then say "gol" to confirm the sheet.',
    'np' => 'Order draft — %s (rep din %s) — shelf, pachhillo delivery pachhi ko bikri, ra sadhain ko bill line bata Gol ko draft. Chat ma edit garnus ("Coke 12 hata", "Pepsi 20 thap"), ani sheet pakka garna "gol" bhannus.',
    'hi' => 'Order draft — %s (rep din %s) — shelf, pichhli delivery ke baad ki bikri, aur aam bill lines se Gol ka draft. Chat mein edit kijiye ("Coke 12 hata", "Pepsi 20 thap"), phir sheet pakka karne ke liye "gol" kahiye.',
    'dev' => 'अर्डर ड्राफ्ट — %s (रेप दिन %s) — सेल्फ, पछिल्लो डेलिभरीपछिको बिक्री, र सधैंको बिल लाइनबाट Gol को ड्राफ्ट। च्याटमा edit गर्नुस् ("Coke 12 hata", "Pepsi 20 thap"), अनि sheet पक्का गर्न "गोल" भन्नुस्।'],
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
    'en' => 'I couldn\'t read that edit — say it like "Coke 12 hata" (strike) or "Pepsi 20 thap" (set 20). The order card is unchanged.',
    'np' => 'Tyo edit bujhina — "Coke 12 hata" (kataune) wa "Pepsi 20 thap" (20 rakhne) jastai bhannus. Order card ustai chha.',
    'hi' => 'Woh edit samajh nahi aaya — "Coke 12 hata" (kaatna) ya "Pepsi 20 thap" (20 rakhna) jaise kahiye. Order card waisa hi hai.',
    'dev' => 'त्यो edit बुझिन — "Coke 12 hata" (काट्ने) वा "Pepsi 20 thap" (20 राख्ने) जस्तै भन्नुस्। अर्डर कार्ड उस्तै छ।'],
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
    if (in_array($key, ['logo', 'auto_open', 'watch_frame'], true)) { return golpos_onoff((bool)$v); }
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
function golpos_is_cancel_intent(string $msg): bool {
    return preg_match('/^\s*(cancel|radda|chhod|chhoda|nahune|hudaina|nakara|रद्द|छोड|नगर|नहुने)\s*[.!]?\s*$/iu', $msg) === 1;
}
function golpos_is_batch_close_intent(string $msg): bool {
    return preg_match('/(batch\s*(close|banda|seal)|ब्याच)/iu', $msg) === 1;
}
function golpos_is_batch_mode_set(string $msg): bool {
    return preg_match('/batch/iu', $msg) === 1 && preg_match('/\b(auto|manual)\b/iu', $msg) === 1;
}
function golpos_is_employee_intent(string $msg): bool {
    return preg_match('/(employee|employees|staff|karmachari|कर्मचारी)/iu', $msg) === 1;
}
function golpos_is_loyalty_board_intent(string $msg): bool {
    return preg_match('/(loyalty|leader\s*board)/iu', $msg) === 1;
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
// The invite link an employee receives. Derived from the request so the
// same code works on the demo preview and any future install.
function golpos_invite_link(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/server/brain.php'))), '/');
    return $scheme . '://' . $host . $dir . '/web/invite.html?t=' . $token;
}
// Send the invite in Gol's voice. Returns false when mail() refuses, so the
// caller shows the link in chat instead of pretending it sent.
function golpos_send_invite(string $email, string $name, string $link, string $shop): bool {
    $who = $name !== '' ? $name : $email;
    $subject = "You're invited to join {$shop} on Gol POS";
    $body = "Hi {$who},\n\n"
        . "You have been added as an employee at {$shop}. Gol runs the shop by chat — "
        . "set your own employee PIN from this one-time link and your device will "
        . "remember you at the register:\n\n{$link}\n\n"
        . "The link works once. If you didn't expect it, you can ignore this email.\n\n"
        . "— Gol, your store brain";
    $headers = "From: Gol POS <no-reply@" . preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) . ">\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";
    return @mail($email, $subject, $body, $headers);
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
        . "the confirm word \"gol\"), or give today's total.\nFacts:\n{$facts}";
    return golpos_meta_chat($model, $system, $userMsg, 1500, 30);
}

// The deterministic layer assembles the week's facts for the Wonder lane:
// per-day totals for the last 7 days, the week aggregate, the tender split,
// and the top sellers by revenue. Returns [facts string, grounding figures].
function golpos_week_facts(string $shop, array $products): array {
    $days = [];
    for ($i = 6; $i >= 0; $i--) { $days[date('Y-m-d', strtotime("-{$i} days"))] = ['gross' => 0.0, 'txns' => 0]; }
    $items = []; $weekGross = 0.0; $weekTx = 0; $cash = 0.0; $card = 0.0;
    $monthPrefix = date('Y-m'); $monthGross = 0.0; $monthTx = 0; $monthCash = 0.0; $monthCard = 0.0;
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
        if (strpos($d, $monthPrefix) === 0) {
            $mTot = (float)($s['total'] ?? 0);
            $monthGross += $mTot; $monthTx++;
            if (($s['tender'] ?? '') === 'cash') { $monthCash += $mTot; } else { $monthCard += $mTot; }
        }
        if (!isset($days[$d])) { continue; }
        $tot = (float)($s['total'] ?? 0);
        $days[$d]['gross'] += $tot; $days[$d]['txns']++;
        $weekGross += $tot; $weekTx++;
        if (($s['tender'] ?? '') === 'cash') { $cash += $tot; } else { $card += $tot; }
        foreach (($s['lines'] ?? []) as $l) {
            $n = (string)($l['name'] ?? '');
            if ($n !== '') { $items[$n] = ($items[$n] ?? 0.0) + (float)($l['line_total'] ?? 0); }
        }
    }
    arsort($items);
    $today = golpos_day_summary(date('Y-m-d'));
    $lines = ["Shop: {$shop}. Last 7 days (date — total — sales):"];
    $figures = [money($weekGross), money($today['gross'])];
    foreach ($days as $d => $v) {
        $lines[] = "{$d} — " . money($v['gross']) . " — {$v['txns']}";
        $figures[] = money($v['gross']);
    }
    $lines[] = "Week total: " . money($weekGross) . " across {$weekTx} sales (cash " . money($cash) . ", card " . money($card) . ").";
    $lines[] = "Month to date (" . date('F Y') . "): " . money($monthGross) . " across {$monthTx} sales (cash " . money($monthCash) . ", card " . money($monthCard) . "). The POS records sales only — no purchases or expenses — so for a 'money left / how much money' question this month-taken-in figure is the POS truth; profit is computed in the books. Never present a profit figure for the POS.";
    $figures[] = money($monthGross);
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
    if (preg_match_all('/\d+(?:\.\d+)?[\s-]*(?:fl\s*oz|oz|ml|ltr|litres?|liters?|l|gm|g|kg|lb|pk|packs?|count|ct)\b/iu',
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
function golpos_extract_price(string $msg, ?array $product): array {
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
        case 'new_item':
            return 'new item ' . ($card['name'] ?? '') . ' — ' . money((float)($card['price'] ?? 0));
        case 'setting_change':
            return ($card['label'] ?? ($card['setting'] ?? 'setting')) . ': '
                . golpos_setting_display((string)($card['setting'] ?? ''), $card['old'] ?? null)
                . ' → ' . golpos_setting_display((string)($card['setting'] ?? ''), $card['new'] ?? null);
        case 'stock_receive':
            return 'stock receive — ' . ($card['name'] ?? '') . ' × ' . $qty($card['qty'] ?? 0);
        case 'batch_close':
            return 'batch close — ' . (int)($card['count'] ?? 0) . ' card sales, ' . money((float)($card['total'] ?? 0));
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
        if ($sug <= 0) { $fine++; continue; }
        $cost = $seed['cost'] ?? null;
        if ($cost === null && $p !== null && isset($p['cost']) && is_numeric($p['cost'])) {
            $cost = (float)$p['cost'];
        }
        $flag = ($onHand !== null && $onHand <= 0) ? 'out'
            : ($topup > 0 ? 'low' : 'sold');
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

// --- a staged card + a cancel word: cleared here, deterministically --------
// The word alone, with a card waiting, is the whole instruction — it must
// never fall through to the composer (hisab test R08b: "cancel" after a
// staged price card got "I can't cancel that here" and the card stayed
// armed). One truth, one line, before every lane.
if (golpos_is_cancel_intent($msg) && golpos_pending() !== null) {
    golpos_clear_pending();
    out(['reply' => t('card_cancelled'), 'cancelled' => true]);
}

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
    // An employee-remove card: the record leaves the people store.
    if ($card['type'] === 'employee_remove') {
        golpos_clear_pending();
        if (golpos_remove_employee((string)$card['email'])) {
            out(['reply' => t('emp_removed', (string)$card['email']), 'done' => $card]);
        }
        out(['reply' => t('emp_not_found', (string)$card['email'])]);
    }
    // A stock-receive card (link mode): the goods physically arrived, the
    // owner confirmed — MR.STORE stock goes up by the received quantity.
    // A stock write is an admin execution: the gate's card-type list
    // includes stock_receive, so "gol" from an unelevated session demands
    // the PIN before this runs, exactly like a price change.
    if ($card['type'] === 'stock_receive') {
        $res = mrstore_adjust_stock((string)$card['name'], (float)$card['qty']);
        golpos_clear_pending();
        if ($res === null) { out(['reply' => t('card_fail')]); }
        golpos_link_log(['kind' => 'receive', 'name' => $res['name'], 'qty' => (float)$card['qty'],
                         'before' => $res['before'], 'after' => $res['after'], 'via' => 'chat']);
        $fmt = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        out(['reply' => t('stock_recv_done', $res['name'], $fmt($res['before']), $fmt($res['after'])), 'done' => $card]);
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

// --- edits to a staged ORDER card ---------------------------------------------
// While an order_prep card waits, "coke 12 hata / pepsi 20 thap" edits its
// lines and re-stages the same order. A message that parses as no edit at
// all falls through untouched — the card simply stays where it is.
$pendNow = golpos_pending();
if ($pendNow !== null && ($pendNow['type'] ?? '') === 'order_prep') {
    golpos_order_apply_edits($pendNow, $msg, $products);
}

// --- "stage it": speak only the truth about the card on the till -------------
if (preg_match('/\bstage\b|स्टेज/iu', $msg) && !preg_match('/\d|\$/u', $msg)) {
    $pendingCard = golpos_pending();
    if ($pendingCard !== null) {
        out(['reply' => t('already_staged', golpos_card_summary($pendingCard)), 'card' => $pendingCard]);
    }
    out(['reply' => t('stage_what')]);
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

// --- batch close: stage the unbatched card segment (execution on "gol") -----
if (golpos_is_batch_close_intent($msg)) {
    $seg = golpos_unbatched_card_sales();
    if (!$seg) { out(['reply' => t('batch_none')]); }
    $total = 0.0;
    foreach ($seg as $s) { $total += (float)($s['total'] ?? 0); }
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
    if ($waiting) {
        out(['reply' => t('morning_due',
            implode(' + ', array_map(static fn($v) => (string)$v['name'], $waiting)),
            (string)$waiting[0]['name'])]);
    }
}

// --- employees: add (invite by email), list, staged remove -------------------
// Admin domain — the gate has already run, so a session reaching here with
// an employee intent is elevated (or no admin exists yet).
if (golpos_is_employee_intent($msg)) {
    $email = null;
    if (preg_match('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $msg, $em)) { $email = golpos_norm_email($em[0]); }
    if ($email !== null && preg_match('/(remove|hata|hatau|nikal|निकाल|हटाउ)/iu', $msg)) {
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
        $name = trim((string)preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', ' ', $msg));
        $name = trim((string)preg_replace('/\b(add|thap|as|an|a|employee|staff|worker|new|naya|invite|please|plz)\b/iu', ' ', $name));
        $name = trim((string)preg_replace('/[^\p{L}\s.\'-]/u', ' ', $name));
        $name = (string)preg_replace('/\s+/', ' ', $name);
        if (!preg_match('/^[\p{L}][\p{L} .\'-]{0,40}$/u', $name)) { $name = ''; }
        $token = bin2hex(random_bytes(16));
        golpos_save_employee(['name' => $name, 'email' => $email, 'role' => 'employee',
            'status' => 'invited', 'pin_hash' => null,
            'invite_token_hash' => hash('sha256', $token), 'created' => date('c')]);
        $display = $name !== '' ? "{$name} ({$email})" : $email;
        if (golpos_send_invite($email, $name, golpos_invite_link($token), $shop)) {
            out(['reply' => t('emp_added_sent', $display)]);
        }
        out(['reply' => t('emp_added_link', $display, golpos_invite_link($token))]);
    }
    if (preg_match('/(list|dekha|dekhaunus|show|ko ko|kati|हेर|देखा)/iu', $msg)) {
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

// --- MR.STORE stock lane (link mode only) --------------------------------------
// With MR.STORE connected, stock questions are answered from MR.STORE's
// live state — never from the register's memory — and a delivery is staged
// as a card that moves MR.STORE stock on "gol". This lane sits before the
// price and day-total lanes because "stock kati chha?" is a stock question,
// not a price question.
if (golpos_link_on() && preg_match('/(stock|maujud|on\s*hand|sakina|sakiyo|khatam|restock|received|delivery|ayo|aayo|स्टक|आयो)/iu', $msg)) {
    $fmtq = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    // a receive: "<item> <qty> ayo" / "received 24 Coke" — staged, not instant
    if (preg_match('/(ayo|aayo|aaya|received|restock|delivery|आयो)/iu', $msg)
        && !preg_match('/(kati|kun|which|list|dekha|कति|कुन)/iu', $msg)) {
        $r = resolve_item($products, $msg);
        if (!empty($r['one'])) {
            $p = $r['one'];
            // The COUNT the owner said, by the shared meaning rules — never
            // the last number standing: in "12 ota lays ayo, $1.19 ma" the
            // count is 12 and the money stays out of the card entirely.
            $qty = golpos_extract_count($msg, $p);
            if ($qty !== null && $qty > 0) {
                $card = golpos_stage(['type' => 'stock_receive',
                                      'name' => $p['mr_name'] ?? $p['name'], 'qty' => $qty]);
                out(['reply' => t('card_staged'), 'card' => $card]);
            }
        }
    }
    // out / running-low list
    if (preg_match('/(sakina|sakiyo|khatam|low|out|kun kun|कुन कुन)/iu', $msg)) {
        $rows = [];
        foreach ($products as $p) {
            if (!isset($p['onHand']) || $p['onHand'] === null) { continue; }
            if ((float)$p['onHand'] <= 2) { $rows[] = $p; }
        }
        usort($rows, static fn($a, $b) => (float)$a['onHand'] <=> (float)$b['onHand']);
        if (!$rows) { out(['reply' => t('stock_low_none')]); }
        $lines = [t('stock_low_head', $shop)];
        foreach ($rows as $p) {
            $lines[] = '• ' . $p['name'] . ' — ' . ((float)$p['onHand'] <= 0 ? '0 (out)' : $fmtq($p['onHand']) . ' left');
        }
        out(['reply' => implode("\n", $lines)]);
    }
    // one item's stock, read live from MR.STORE
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $st = mrstore_stock_of((string)($r['one']['mr_name'] ?? $r['one']['name']));
        if ($st === null) { out(['reply' => t('stock_link_down')]); }
        if ($st['onHand'] === null) { out(['reply' => t('stock_untracked', $st['name'])]); }
        out(['reply' => t('stock_one', $st['name'], $fmtq($st['onHand']))]);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'], $r['many']));
        out(['reply' => t('which_one', $list)]);
    }
    out(['reply' => t('stock_notfound')]);
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
        out(['reply' => t('cmp_line', golpos_day_label($yday), money((float)$a['gross']), (int)$a['txns'],
                          golpos_day_label($today), money((float)$b['gross']), (int)$b['txns'], $tail)]);
    }
    $ymd = preg_match('/(hijo|yesterday|हिजो)/iu', $msg) === 1 ? $yday : $today;
    $items = golpos_day_items($ymd);
    if (!$items) { out(['reply' => t('best_none', golpos_day_label($ymd))]); }
    $fmtTop = static function (array $list): string {
        return implode('; ', array_map(static fn($i) => $i['name'] . ' ×' . $i['qty']
            . ' (' . money((float)$i['rev']) . ')', $list));
    };
    $byQty = $items;
    usort($byQty, static fn($x, $y) => ($y['qty'] <=> $x['qty']) ?: ($y['rev'] <=> $x['rev']));
    $byRev = $items;
    usort($byRev, static fn($x, $y) => ($y['rev'] <=> $x['rev']) ?: ($y['qty'] <=> $x['qty']));
    out(['reply' => t('best_line', golpos_day_label($ymd),
                      $fmtTop(array_slice($byQty, 0, 3)), $fmtTop(array_slice($byRev, 0, 3)))]);
}

// --- analysis / advice: the DEEP-THINK lane (Muse Wonder) --------------------
// Judgment questions leave the register floor and go to Wonder: why was
// today slow, how was the week, what should I do. The deterministic layer
// assembles the week's facts; Wonder composes the judgment; Spark composes
// if Wonder is unreachable; the template speaks if neither answers. Same
// grounding law: a reply is accepted only if it carries a real figure.
if (preg_match('/(why|how come|how was|how did|how is|किन|कस्तो|kasto|kina|hapta|हप्ता|week|month|mahina|महिना|suggest|advice|sujhab|sujhav|sallah|slow|trend|improv)/iu', $msg)) {
    [$facts, $figures, $weekTx, $weekGross, $todaySum, $monthGross, $monthTx, $monthCash, $monthCard] = golpos_week_facts($shop, $products);
    if ($weekTx === 0 && $monthTx === 0) { out(['reply' => t('week_none')]); }
    $grounded = function (?string $reply) use ($figures): bool {
        if ($reply === null) { return false; }
        foreach ($figures as $f) { if (strpos($reply, $f) !== false) { return true; } }
        return false;
    };
    $reply = golpos_meta_compose($shop, $facts, $msg, golpos_wonder_model(), true);
    if ($grounded($reply)) { out(['reply' => $reply]); }
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($grounded($reply)) { out(['reply' => $reply]); }
    if (preg_match('/(month|mahina|महिना)/iu', $msg)) { out(['reply' => t('month_line', money($monthGross), $monthTx, money($monthCash), money($monthCard))]); }
    out(['reply' => t('week_line', money($weekGross), $weekTx, money($todaySum['gross']))]);
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
    out(['reply' => $reply, 'close_record' => $rec]);
}

// --- day total ---------------------------------------------------------------
if (preg_match('/(kati bikri|kitni bikri|aaja ko|aaj ka|aaja kati|becheu|आज|हिसाब|बिक्री|today|close|banda|hisab|hisaab|sales|total)/iu', $msg)) {
    $s = golpos_day_summary(date('Y-m-d'));
    if ($s['txns'] === 0) {
        out(['reply' => t('day_none')]);
    }
    // Muse composes the day line from the exact figures; template if it can't.
    $facts = "Today's sales at {$shop} so far: total " . money($s['gross']) . " across {$s['txns']} sales"
        . " — cash " . money($s['cash']) . ", card " . money($s['card']) . "."
        . (golpos_link_on() ? " These are the POS till's own figures; stock figures live in MR.STORE." : "");
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($reply !== null && strpos($reply, money($s['gross'])) !== false) { out(['reply' => $reply]); }
    out(['reply' => t('day_total', money($s['gross']), $s['txns'], money($s['cash']), money($s['card']))
                    . (golpos_link_on() ? "\n" . t('src_till') : '')]);
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
    golpos_bare_price_change($msg, $products)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
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
    golpos_price_await_set(null);
    out(['reply' => t('not_found_price')]);
}

// --- price query --------------------------------------------------------------
if (preg_match('/(kati|kitna|kitne|price|mulya|dam|daam|how much|मूल्य|कति)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        // Muse phrases the answer around the exact resolved price.
        $reply = golpos_meta_compose($shop, "Price fact: {$p['name']} costs " . money((float)$p['price']) . ". The owner asked its price.", $msg);
        if ($reply !== null && strpos($reply, money((float)$p['price'])) !== false) { out(['reply' => $reply]); }
        out(['reply' => $p['name'] . ' — ' . money((float)$p['price']) . '.']);
    }
    if (!empty($r['many'])) {
        $list = implode(', ', array_map(fn($p) => $p['name'] . ' (' . money((float)$p['price']) . ')', $r['many']));
        $reply = golpos_meta_compose($shop, "Several products match the owner's words. Candidates with exact prices: {$list}. Ask which one they mean.", $msg);
        if ($reply !== null) {
            foreach ($r['many'] as $cand) {
                if (strpos($reply, money((float)$cand['price'])) !== false) { out(['reply' => $reply]); }
            }
        }
        out(['reply' => t('which_one', $list)]);
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
    $r = resolve_item($products, $wmsg);
    $ex = golpos_extract_price($wmsg, null);
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
        $qty = golpos_extract_count($wmsg, $r['one']);
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
if (golpos_link_on()) {
    $pack .= "\nStock on hand (MR.STORE live): " . implode('; ', array_map(
        fn($p) => $p['name'] . ' — ' . (isset($p['onHand']) && $p['onHand'] !== null ? (string)(float)$p['onHand'] : 'not tracked'),
        $products)) . ". Sales totals above are the POS till's own figures.";
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
        $pend = golpos_pending();
        return $pend !== null && in_array($pend['type'] ?? '', [
            'price_change', 'new_item', 'setting_change', 'batch_close', 'employee_remove', 'stock_receive',
            'bill', 'order_prep', 'vendor_teach',
        ], true);
    }
    if (preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg)) { return true; }
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

// "lock" / "admin bata bahira" — drop elevation on the spot.
if (golpos_is_lock_intent($msg)) {
    $session['admin'] = false;
    $session['pin_stage'] = null;
    $session['pin_tmp'] = null;
    $session['pending'] = null;
    $session['price_await'] = null;
    golpos_save_session($sessionId, $session);
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
        $session['pin_stage'] = null;
        $session['pin_tmp'] = null;
        $pending = $session['pending'];
        $session['pending'] = null;
        golpos_save_session($sessionId, $session);
        if (is_array($pending) && (string)($pending['message'] ?? '') !== '') {
            $GLOBALS['golpos_pin_mask'] = true;
            golpos_dispatch((string)$pending['message'],
                            ['confirm' => !empty($pending['confirm'])], $catalog, $products, $shop);
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
            $session['pin_stage'] = null;
            $session['attempts'] = 0;
            $pending = $session['pending'];
            $session['pending'] = null;
            golpos_save_session($sessionId, $session);
            // A bare "admin"/"pin" ask has nothing behind it — just confirm.
            if (is_array($pending) && trim((string)($pending['message'] ?? '')) !== ''
                && !preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', (string)$pending['message'])) {
                $GLOBALS['golpos_pin_mask'] = true;
                golpos_dispatch((string)$pending['message'],
                                ['confirm' => !empty($pending['confirm'])], $catalog, $products, $shop);
            }
            out(['reply' => t('admin_on'), 'pin_mask' => true]);
        }
        $session['attempts'] = (int)($session['attempts'] ?? 0) + 1;
        if ($session['attempts'] >= 3) {
            $session['attempts'] = 0;
            $session['locked_until'] = $now + 600;
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

// A price question Gol just asked owns the follow-up the same way a bill
// does: the owner's next words carry the missing piece (the price, or the
// item pick when the price was already said) and the staging completes —
// Gol never asks again for what it was already told. A follow-up with no
// number and no candidate item in it is a topic change: the wait drops
// and the message runs its normal course through the gate and dispatch.
if (!empty($session['price_await']) && golpos_pending() === null) {
    $await = $session['price_await'];
    if (golpos_is_cancel_intent($msg)) {
        $session['price_await'] = null;
        golpos_save_session($sessionId, $session);
        out(['reply' => t('ask_cancelled')]);
    }
    $isConfirm = in_array(mb_strtolower(trim($msg)), ['gol', 'गोल'], true) || !empty($in['confirm']);
    $target = null; $newPrice = null; $reask = null; $topicChange = false;
    if (!empty($await['sku'])) {
        foreach ($products as $p) {
            if ((string)$p['sku'] === (string)$await['sku']) { $target = $p; break; }
        }
        if ($target === null) { $topicChange = true; }
        elseif ($isConfirm) { $reask = t('price_ask', (string)$target['name']); }
        else {
            $ex = golpos_extract_price($msg, $target);
            if ($ex['status'] === 'ambiguous') {
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

golpos_dispatch($msg, $in, $catalog, $products, $shop);
