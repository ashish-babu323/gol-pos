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

function out(array $r): void {
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
  'board_head' => [
    'en' => 'Receipt Loyalty — leaderboard:',
    'np' => 'Receipt Loyalty — leaderboard:',
    'hi' => 'Receipt Loyalty — leaderboard:',
    'dev' => 'रसिद लोयाल्टी — लिडरबोर्ड:'],
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
    if (in_array($key, ['logo', 'auto_open'], true)) { return golpos_onoff((bool)$v); }
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
    return preg_match('/^\s*(cancel|chhod|chhoda|रद्द|छोड)\s*[.!]?\s*$/iu', $msg) === 1;
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
    if (preg_match_all('/\$\s*(\d+(?:\.\d+)?)|(\d+(?:\.\d+)?)/', $work, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $dollar = ($m[1][$i][1] ?? -1) >= 0;
            $out[] = ['value' => (float)($dollar ? $m[1][$i][0] : $m[2][$i][0]),
                      'start' => $full[1], 'len' => strlen($full[0]), 'dollar' => $dollar];
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
    if (preg_match_all('/\$\s*\d+(?:\.\d+)?/', $work, $dm, PREG_OFFSET_CAPTURE)) {
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
    out(['reply' => t('card_fail')]);
}

if ($msg === '') { out(['reply' => t('empty')]); }

// --- receipt print settings: read + staged changes ---------------------------
// The POS keeps only its selling screen — print settings are run from this
// chat (and from the owner panel's form, the manual door to the same store).
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
    preg_match('/(price|mulya|dam|daam|मूल्य|दाम).*\bto\s*(?:\$\s*\d|\d+\.\d{1,2})/iu', golpos_norm_digits($msg))) {
    $r = resolve_item($products, $msg);
    if (!empty($r['one'])) {
        $p = $r['one'];
        // The price the owner SAID, by the meaning rules — the product is
        // matched first, its size is never money, and an explicit $ amount
        // always wins. No readable price, or two live candidates: Gol asks
        // and stages nothing.
        $ex = golpos_extract_price($msg, $p);
        if ($ex['status'] === 'ambiguous') {
            out(['reply' => t('price_ask_which', $p['name'], money($ex['candidates'][0]), money($ex['candidates'][1]))]);
        }
        if ($ex['status'] !== 'ok') { out(['reply' => t('price_ask', $p['name'])]); }
        $new = $ex['value'];
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

// --- offer poster: the CREATE lane (Muse Image) -------------------------------
// "Make an offer poster for Coke" — Muse Image generates it, the chat shows
// the preview, and the poster stages as a card. Nothing publishes until the
// owner says "gol"; then it lands in the DEMO store's feed only — fictional
// Sunrise Mart branding, never a real store's data or pages.
if (preg_match('/(poster|banner)/iu', $msg) ||
    preg_match('/(make|bana|banau|generate|बना).*(image|photo|picture)/iu', $msg)) {
    $r = resolve_item($products, $msg);
    if (empty($r['one'])) { out(['reply' => t('poster_which')]); }
    $p = $r['one'];
    $price = (float)$p['price'];
    if (preg_match('/\$\s*(\d+(?:\.\d{1,2})?)/', $msg, $pm)) { $price = (float)$pm[1]; }
    $imgPrompt = "Retail offer poster for {$shop}, a small convenience store (fictional demo store). "
        . "Feature one product prominently: {$p['name']}. Big clear readable text: \"OFFER\", "
        . "\"{$p['name']}\" and the price \"" . money($price) . "\". Bright blue accents (#3F80F5), "
        . "clean modern design, appetising product photography style, portrait poster.";
    @set_time_limit(240);
    $png = golpos_meta_image($imgPrompt);
    if ($png === null) { out(['reply' => t('poster_fail')]); }
    $dir = dirname(__DIR__) . '/web/uploads/posters';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $file = 'poster-' . date('Ymd-His') . '-' . substr(sha1(uniqid('', true)), 0, 6) . '.png';
    file_put_contents($dir . '/' . $file, $png);
    $card = golpos_stage(['type' => 'poster', 'image' => 'uploads/posters/' . $file,
                          'name' => $p['name'], 'price' => $price,
                          'caption' => '🔥 OFFER at ' . $shop . ': ' . $p['name'] . ' — ' . money($price) . '! Grab yours today 🎉']);
    out(['reply' => t('poster_ready', $shop), 'card' => $card]);
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
            'bill',
        ], true);
    }
    if (preg_match('/^\s*(admin|login|pin)\s*[.!]?\s*$/iu', $msg)) { return true; }
    if (preg_match('/(receipt|print|bill|paper).{0,30}settings|settings.{0,30}(receipt|print|bill)|settings\s+(ke|kasto)/iu', $msg)) { return false; }
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
        } else {
            out(['reply' => t('pin_bad_format'), 'pin_mask' => true]);
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

// The gate itself: an admin-domain message from an unelevated session gets
// the PIN demand and NOTHING else — no figures, no hints, no partial answer.
if (empty($session['admin']) && golpos_needs_admin($msg, $in)) {
    $session['pending'] = ['message' => $msg, 'confirm' => !empty($in['confirm'])];
    if (golpos_admin_pin_hash() === null) {
        $session['pin_stage'] = 'create1';
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_create_ask')]);
    }
    if ((int)($session['locked_until'] ?? 0) > time()) {
        golpos_save_session($sessionId, $session);
        out(['reply' => t('pin_locked', (int)ceil(((int)$session['locked_until'] - time()) / 60))]);
    }
    $session['pin_stage'] = 'verify';
    golpos_save_session($sessionId, $session);
    out(['reply' => t('pin_ask')]);
}

golpos_dispatch($msg, $in, $catalog, $products, $shop);
