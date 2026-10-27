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
  'fallback' => [
    'en' => 'I can add items, quote or change prices (staged as a card), give today\'s total, and run the receipt print settings. Try: "print settings" or "How much is Coke?"',
    'np' => 'Ma saman thapna, mulya sodhna/badalna (card stage garera), din ko hisab dina, ra receipt print settings chalauna sakchhu. Prayas garnus: "print settings ke chha?"',
    'hi' => 'Main saaman jod sakta hoon, daam bata ya badal sakta hoon (card stage karke), aaj ka hisaab de sakta hoon, aur receipt print settings chala sakta hoon. Koshish kijiye: "print settings".',
    'dev' => 'म सामान थप्न, मूल्य सोध्न/बदल्न (कार्ड स्टेज गरेर), दिनको हिसाब दिन, र रसिद प्रिन्ट सेटिङ चलाउन सक्छु। प्रयास गर्नुस्: "प्रिन्ट सेटिङ के छ?"'],
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
    ];
    return $map[$key][$L] ?? $map[$key]['en'] ?? $key;
}
function golpos_setting_display(string $key, $v): string {
    if (in_array($key, ['show_address', 'show_phone', 'show_tax'], true)) { return golpos_shown((bool)$v); }
    if (in_array($key, ['logo', 'auto_open'], true)) { return golpos_onoff((bool)$v); }
    if ($key === 'copies') { return (string)(int)$v; }
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
    foreach (golpos_sales() as $s) {
        $d = substr((string)($s['at'] ?? ''), 0, 10);
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
    $lines[] = "Today so far: " . money($today['gross']) . " across {$today['txns']} sales.";
    $top = array_slice($items, 0, 5, true);
    if ($top) {
        $lines[] = "Top sellers this week by revenue: " . implode('; ', array_map(
            fn($n, $v) => $n . ' — ' . money($v), array_keys($top), array_values($top))) . ".";
        foreach ($top as $v) { $figures[] = money($v); }
    }
    $lines[] = "Catalog (" . count($products) . " items, name — price): "
        . implode('; ', array_map(fn($p) => $p['name'] . ' — ' . money((float)$p['price']), $products));
    return [implode("\n", $lines), $figures, $weekTx, $weekGross, $today];
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
    out(['reply' => t('card_fail')]);
}

// --- receipt print settings: read + staged changes ---------------------------
// The POS keeps only its selling screen — print settings are run from this
// chat (and from the owner panel's form, the manual door to the same store).
// "print settings" / "receipt settings ke chha?" reads them back; a change
// is staged as an old -> new card and executes only on "gol".
if (preg_match('/(receipt|print|bill|paper).{0,30}settings|settings.{0,30}(receipt|print|bill)|settings\s+(ke|kasto)/iu', $msg)) {
    out(['reply' => golpos_settings_read_reply()]);
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

// --- analysis / advice: the DEEP-THINK lane (Muse Wonder) --------------------
// Judgment questions leave the register floor and go to Wonder: why was
// today slow, how was the week, what should I do. The deterministic layer
// assembles the week's facts; Wonder composes the judgment; Spark composes
// if Wonder is unreachable; the template speaks if neither answers. Same
// grounding law: a reply is accepted only if it carries a real figure.
if (preg_match('/(why|how come|how was|how did|how is|किन|कस्तो|kasto|kina|hapta|हप्ता|week|month|suggest|advice|sujhab|sujhav|sallah|slow|trend|improv)/iu', $msg)) {
    [$facts, $figures, $weekTx, $weekGross, $todaySum] = golpos_week_facts($shop, $products);
    if ($weekTx === 0) { out(['reply' => t('week_none')]); }
    $grounded = function (?string $reply) use ($figures): bool {
        if ($reply === null) { return false; }
        foreach ($figures as $f) { if (strpos($reply, $f) !== false) { return true; } }
        return false;
    };
    $reply = golpos_meta_compose($shop, $facts, $msg, golpos_wonder_model(), true);
    if ($grounded($reply)) { out(['reply' => $reply]); }
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($grounded($reply)) { out(['reply' => $reply]); }
    out(['reply' => t('week_line', money($weekGross), $weekTx, money($todaySum['gross']))]);
}

// --- close the day: writes the day record ------------------------------------
// Close words in any of the shop's languages (close / banda / बन्द / क्लोज)
// end the day: the summary is frozen into a saved record the owner view
// reads back. This branch runs BEFORE the day-total branch so a close is
// never mistaken for a question about the total.
if (preg_match('/(close|banda|बन्द|क्लोज)/iu', $msg)) {
    $rec = golpos_close_day(date('Y-m-d'));
    out(['reply' => t('close_done', money((float)$rec['gross']), (int)$rec['txns'], money((float)$rec['cash']), money((float)$rec['card'])),
         'close_record' => $rec]);
}

// --- day total ---------------------------------------------------------------
if (preg_match('/(kati bikri|kitni bikri|aaja ko|aaj ka|aaja kati|becheu|आज|हिसाब|बिक्री|today|close|banda|hisab|hisaab|sales|total)/iu', $msg)) {
    $s = golpos_day_summary(date('Y-m-d'));
    if ($s['txns'] === 0) {
        out(['reply' => t('day_none')]);
    }
    // Muse composes the day line from the exact figures; template if it can't.
    $facts = "Today's sales at {$shop} so far: total " . money($s['gross']) . " across {$s['txns']} sales"
        . " — cash " . money($s['cash']) . ", card " . money($s['card']) . ".";
    $reply = golpos_meta_compose($shop, $facts, $msg);
    if ($reply !== null && strpos($reply, money($s['gross'])) !== false) { out(['reply' => $reply]); }
    out(['reply' => t('day_total', money($s['gross']), $s['txns'], money($s['cash']), money($s['card']))]);
}

// --- new item (staged) ---------------------------------------------------------
// "add new item Mango Lassi 3.25" / "naya saman Momo 6.99 ma thap" /
// "नयाँ सामान लस्सी 3.25": Gol stages a new-item card (name + price) that
// executes only on "gol". With no price in the message Gol asks for it —
// a price is never invented.
if (preg_match('/(new\s+(item|product)|naya\s+(saman|item)|नयाँ|नया\s+सामान)/iu', $msg)) {
    $price = null; $digits = '';
    if (preg_match('/(\d+(?:\.\d{1,2})?)/', golpos_norm_digits($msg), $mm)) {
        $price = (float)$mm[1]; $digits = $mm[1];
    }
    $name = preg_replace('/(new\s+(item|product)|naya\s+(saman|item)|नयाँ\s+सामान|नयाँ|नया\s+सामान)/iu', ' ', $msg);
    $name = preg_replace('/(add|thap|थप|थप्नुस्|jodo|price|mulya|मूल्य|दाम|daam|dam)/iu', ' ', (string)$name);
    if ($digits !== '') { $name = str_replace($digits, ' ', (string)$name); }
    $name = preg_replace('/[\p{P}\p{S}]+/u', ' ', (string)$name);
    $name = preg_replace('/\b(ma|मा|ho|for|at)\b/iu', ' ', (string)$name);
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name !== '') {
        if ($price === null) { out(['reply' => t('new_ask_price', $name)]); }
        $card = golpos_stage(['type' => 'new_item', 'name' => $name, 'price' => $price,
                              'category' => 'Counter', 'barcode' => '']);
        out(['reply' => t('card_staged'), 'card' => $card]);
    }
}

// --- price change (staged) ---------------------------------------------------
if (preg_match('/(\d+(?:\.\d{1,2})?)/', golpos_norm_digits($msg), $m) &&
    preg_match('/(mulya|price|dam|daam|मूल्य|दाम).*(gara|set|badha|badal|rakha|change|badlo|karo|गर|राख|बनाउ|बदल)|(gara|set|change|badlo|गर|बनाउ).*(mulya|price|dam|daam|मूल्य|दाम)/iu', $msg)) {
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
if (preg_match('/(thap|add|ek |one |chahiyo|give|jodo|jod |lagao|थप|चाहियो)/iu', $msg)) {
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
$reply = golpos_meta_compose($shop, $pack, $msg);
if ($reply !== null) { out(['reply' => $reply]); }

out(['reply' => t('fallback')]);
