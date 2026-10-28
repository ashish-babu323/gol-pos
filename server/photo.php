<?php
// Gol POS — the photo lane: SAM 3.1 is Gol's eyes.
//
// POST (multipart): file field "photo", optional text field "prompt" — the
// concept to look for (a product name, "printer", "bottle"…; default
// "product"). The photo is saved under web/uploads/photos/, SAM reads it,
// and whatever it found stages as a photo_read card. SAM's boxes are
// CANDIDATES, never silent truth: the card shows the count, the boxes and
// the prompt, and nothing executes until the owner says "gol" in the brain
// (a matched catalog item goes to the cart; an unmatched read is logged).
//
// If SAM does not answer, the photo still stages with regions = null and an
// honest note — the lane degrades gracefully, the register never breaks.
//
// A photo the owner marks as a bill (prompt "bill" / "invoice", or mode=bill)
// takes the bill branch instead: Spark reads the printed lines and the bill
// lane matches them to the catalog (see golpos_bill_process in store.php).
//
// Two more riders (Oct 28): mode=sense is the Register Watch counter
// question (one frame, one answer, nothing staged), and a pair_token
// field marks a scan-by-phone upload — the token binds the photo to the
// REGISTER's session and the answer also lands in that session's inbox.

declare(strict_types=1);
require __DIR__ . '/store.php';
require __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');

function pout(array $r): void {
    $r['meta'] = $GLOBALS['golpos_meta'] ?? ['provider' => 'deterministic', 'model' => null];
    // Verify Loop tap: a staged bill card is a checkable claim — its
    // lines against its total (arithmetic facet). WHICH items the lines
    // name can never be re-proven from arithmetic (a same-sum swap
    // passes any sum check), so the identity facet is journaled as
    // uncheckable by design — the verifier logs it, never guesses it.
    if (!empty($r['card']) && ($r['card']['type'] ?? '') === 'bill') {
        $c = $r['card'];
        $lines = [];
        foreach ((array)($c['lines'] ?? []) as $l) {
            $lines[] = ['name' => (string)($l['name'] ?? ''), 'qty' => (float)($l['qty'] ?? 1),
                        'unit_cost' => round((float)($l['unit_cost'] ?? 0), 2),
                        'line_total' => round((float)($l['line_total'] ?? 0), 2)];
        }
        golpos_verify_journal(['class' => 'bill_arithmetic', 'label' => (string)($c['vendor'] ?? 'bill'),
            'figure' => round((float)($c['total'] ?? 0), 2), 'unit' => 'money',
            'inputs' => ['lines' => $lines], 'facets' => ['identity' => 'uncheckable'],
            'session' => (string)($GLOBALS['golpos_photo_session'] ?? '')]);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}
$GLOBALS['golpos_meta'] = ['provider' => 'deterministic', 'model' => null];

if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    pout(['reply' => 'No photo received — tap the 📷 button and pick a photo.']);
}
$tmp = (string)$_FILES['photo']['tmp_name'];
if (!is_uploaded_file($tmp) || (int)$_FILES['photo']['size'] > 10 * 1024 * 1024) {
    pout(['reply' => 'That photo could not be read — try a smaller image (under 10 MB).']);
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
$exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($exts[$mime])) {
    pout(['reply' => 'That file is not a photo I can read — JPEG, PNG or WebP please.']);
}
$bytes = (string)file_get_contents($tmp);

$dir = dirname(__DIR__) . '/web/uploads/photos';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$file = 'photo-' . date('Ymd-His') . '-' . substr(sha1(uniqid('', true)), 0, 6) . '.' . $exts[$mime];
file_put_contents($dir . '/' . $file, $bytes);
$imagePath = 'uploads/photos/' . $file;

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') { $prompt = 'product'; }

// The chat session rides along so a bill's price wait (below) waits in
// the SAME session the owner's chat answers in.
$sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['session'] ?? ''));
if ($sessionId === '') { $sessionId = 'anon'; }
$GLOBALS['golpos_photo_session'] = $sessionId;

// Scan-by-phone pairing: an upload carrying a pair_token was taken on a
// phone acting as THIS register's camera. The token — minted by the
// register (pair.php), bound to its session, dead after ~10 minutes —
// decides the session, whatever the phone claimed: the bill's price
// wait then waits in the register's own chat. The lane's answer ALSO
// lands in that session's inbox, which the register polls and renders.
// An unknown or expired token refuses plainly and processes nothing.
$paired = false;
$pairToken = trim((string)($_POST['pair_token'] ?? ''));
if ($pairToken !== '') {
    $rec = golpos_pair_lookup($pairToken);
    if ($rec === null) {
        pout(['reply' => 'That scan link has expired or is not recognized — ask the register for a fresh "Scan with phone" link, then take the photo again. Nothing was read or staged.']);
    }
    $sessionId = (string)$rec['session'];
    $GLOBALS['golpos_photo_session'] = $sessionId;
    $paired = true;
}
$inboxPush = static function (array $out) use ($paired, $sessionId): void {
    if (!$paired) { return; }
    $entry = ['reply' => (string)($out['reply'] ?? '')];
    if (isset($out['card'])) { $entry['card'] = $out['card']; }
    golpos_inbox_push($sessionId, $entry);
};

// Customer sensing (Register Watch): the owner asks "counter ma
// customer chha?" and the register sends one frame with mode=sense.
// ONE question, one composed reply — the frame never enters the bill,
// catalog or purchase lanes and nothing is ever staged from it.
if ((string)($_POST['mode'] ?? '') === 'sense') {
    $sense = golpos_meta_sense_counter('data:' . $mime . ';base64,' . base64_encode($bytes));
    if ($sense === null) {
        $out = ['reply' => "Photo saved — my eyes didn't answer just now, so I can't say who's at the counter. Ask me again in a moment."];
        $inboxPush($out);
        pout($out);
    }
    $people = (int)$sense['people'];
    if (!empty($sense['customer_present'])) {
        $reply = 'Yes — I can see ' . $people . ($people === 1 ? ' person' : ' people')
            . ' in view, with a customer at the counter.';
    } elseif ($people > 0) {
        $reply = 'No customer at the counter right now — I can see ' . $people
            . ($people === 1 ? ' person' : ' people') . ' in view, none at the counter.';
    } else {
        $reply = 'No customer at the counter right now — the counter looks empty.';
    }
    $out = ['reply' => $reply, 'sense' => $sense];
    $inboxPush($out);
    pout($out);
}

// A vendor bill is a different read from a product photo: Spark reads the
// printed lines (name / qty / unit cost / barcode) and the bill lane
// (store.php golpos_bill_process) matches them to the catalog — matched
// lines stage at once as one bill card; new lines wait in the session on
// the owner's shelf prices, never on invented ones. If the read fails,
// the photo is saved and the reply says so honestly — nothing staged.
$isBill = (string)($_POST['mode'] ?? '') === 'bill'
    || preg_match('/(bill|invoice|बिल)/iu', $prompt) === 1;
if ($isBill) {
    $read = golpos_meta_read_bill('data:' . $mime . ';base64,' . base64_encode($bytes));
    if ($read === null) {
        $out = ['reply' => "Photo saved — my eyes didn't answer just now, so there's no bill read on it. "
            . 'Nothing was staged or entered; try the bill photo again.'];
        $inboxPush($out);
        pout($out);
    }
    $r = golpos_bill_process($read, $imagePath, $sessionId);
    $out = ['reply' => $r['reply']];
    if (isset($r['card'])) { $out['card'] = $r['card']; }
    $inboxPush($out);
    pout($out);
}

$sam = golpos_meta_sam('data:' . $mime . ';base64,' . base64_encode($bytes), $prompt);

// If the concept the owner named resolves to exactly one catalog item, the
// card carries the match so "gol" can put it in the cart.
$catalog = golpos_catalog();
$products = $catalog['products'] ?? [];
$r = resolve_item($products, $prompt);
$match = !empty($r['one']) ? $r['one'] : null;

$card = golpos_stage([
    'type' => 'photo_read',
    'image' => $imagePath,
    'prompt' => $prompt,
    'regions' => $sam['regions'] ?? null,
    'boxes' => $sam['boxes'] ?? [],
    'sam_excerpt' => $sam['excerpt'] ?? null,
    'match_sku' => $match['sku'] ?? null,
    'match_name' => $match['name'] ?? null,
    'match_price' => $match['price'] ?? null,
]);

if ($sam === null) {
    $out = ['reply' => "Photo received and saved — my eyes (SAM) didn't answer just now, so there's no read on it. "
        . "The card is staged anyway; say \"gol\" to log it, or tell me the item name and I'll handle it.",
          'card' => $card];
    $inboxPush($out);
    pout($out);
}
$reply = "SAM read your photo: {$sam['regions']} region(s) found for \"{$prompt}\".";
if ($match) {
    $reply .= " That matches {$match['name']} (" . '$' . number_format((float)$match['price'], 2) . ") in the catalog"
        . " — say \"gol\" and I'll add it to the cart.";
} else {
    $reply .= " No catalog item matches \"{$prompt}\" — say \"gol\" to log the photo, or name the item if it's new.";
}
$out = ['reply' => $reply, 'card' => $card];
$inboxPush($out);
pout($out);
