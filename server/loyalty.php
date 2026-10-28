<?php
// Gol POS — Receipt Loyalty endpoint (owner addition, Oct 26).
//
// The customer side of the Festival concept, on the fictional Sunrise Mart:
//   GET  ?leaderboard                 -> the live board (owner view + page)
//   POST {action:"register", name, phone, email}
//                                     -> join once; phone is the master key
//   POST {action:"standing", phone}   -> one customer's total + rank
//   POST multipart {phone, receipt:<photo>}
//                                     -> Gol vision-reads the receipt (store,
//                                        date, total, receipt no.), dedupes
//                                        by receipt number per store, saves
//                                        it under the customer and replies
//                                        with the running total + rank.
//
// A receipt counts once and only with a real read: if the vision lane
// cannot read the total or the receipt number, nothing is saved and the
// customer is asked for a clearer photo — figures are never invented, and
// an unreadable photo never silently becomes points.

declare(strict_types=1);
require __DIR__ . '/store.php';
require __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');

function lout(array $r): void {
    $r['meta'] = $GLOBALS['golpos_meta'] ?? ['provider' => 'deterministic', 'model' => null];
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}
$GLOBALS['golpos_meta'] = ['provider' => 'deterministic', 'model' => null];

function lmoney(float $n): string { return '$' . number_format($n, 2); }

$catalog = golpos_catalog();
$shopName = (string)($catalog['store']['name'] ?? 'Sunrise Mart');
$shopShort = trim((string)preg_replace('/\s*\(demo\)\s*/', '', $shopName));

function standing_line(array $s): string {
    return 'Your running total is ' . lmoney((float)$s['total']) . ' across '
        . (int)$s['receipts'] . ((int)$s['receipts'] === 1 ? ' receipt' : ' receipts')
        . ' — you are #' . (int)$s['rank'] . ' of ' . (int)$s['of'] . ' on the leaderboard.';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['leaderboard'])) {
    lout(['store' => $shopShort, 'board' => golpos_loyalty_board()]);
}

// --- receipt photo (multipart) ---------------------------------------------
if ($method === 'POST' && isset($_FILES['receipt'])) {
    $phone = golpos_loyalty_phone_key((string)($_POST['phone'] ?? ''));
    $customer = $phone !== '' ? golpos_loyalty_customer($phone) : null;
    if ($customer === null) {
        lout(['reply' => 'I don\'t have that phone number on the loyalty list yet — '
            . 'join first with your name, phone and email, then send the receipt photo.',
              'needs_registration' => true]);
    }
    if (($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        lout(['reply' => 'No receipt photo arrived — tap the 📷 button and pick the photo of your receipt.']);
    }
    $tmp = (string)$_FILES['receipt']['tmp_name'];
    if (!is_uploaded_file($tmp) || (int)$_FILES['receipt']['size'] > 10 * 1024 * 1024) {
        lout(['reply' => 'That photo could not be read — try a smaller image (under 10 MB).']);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime])) {
        lout(['reply' => 'That file is not a photo I can read — JPEG, PNG or WebP please.']);
    }
    $bytes = (string)file_get_contents($tmp);
    $dir = dirname(__DIR__) . '/web/uploads/receipts';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $file = 'receipt-' . date('Ymd-His') . '-' . substr(sha1(uniqid('', true)), 0, 6) . '.' . $exts[$mime];
    file_put_contents($dir . '/' . $file, $bytes);
    $imagePath = 'uploads/receipts/' . $file;

    $read = golpos_meta_read_receipt('data:' . $mime . ';base64,' . base64_encode($bytes));
    if ($read === null || $read['total'] === null || $read['receipt_no'] === null) {
        // Honest dead-end, labelled as such: nothing was counted.
        lout(['reply' => 'I couldn\'t read that receipt clearly, so nothing was counted. '
            . 'Lay it flat, fill the frame with the receipt, and send the photo again.',
              'unread' => true, 'image' => $imagePath]);
    }
    $store = $read['store'] ?? $shopShort;
    if ($read['store'] !== null && stripos($read['store'], 'sunrise') === false) {
        $s = golpos_loyalty_standing($phone);
        lout(['reply' => 'That receipt is from ' . $read['store'] . ' — only ' . $shopShort
            . ' receipts count here. ' . ($s ? standing_line($s) : ''),
              'wrong_store' => true, 'standing' => $s]);
    }
    $seen = golpos_loyalty_receipt_seen($store, $read['receipt_no']);
    if ($seen !== null) {
        $s = golpos_loyalty_standing($phone);
        lout(['reply' => 'That receipt (' . $store . ' #' . $read['receipt_no'] . ') is already counted — '
            . 'each receipt counts once. ' . ($s ? standing_line($s) : ''),
              'duplicate' => true, 'standing' => $s]);
    }
    $savedReceipt = golpos_loyalty_add_receipt($phone, [
        'store' => $store,
        'date' => $read['date'],
        'total' => $read['total'],
        'receipt_no' => $read['receipt_no'],
        'image' => $imagePath,
    ]);
    // Festival points: 1 point per $20 on this receipt, 1 point = $1
    // of store credit, into the ONE ledger (keyed by this receipt's id,
    // so a receipt can never earn twice — it can only count once
    // anyway). The points are spendable at the till's Credit tender.
    $earned = (int)floor((float)$read['total'] / 20);
    if ($earned > 0) {
        golpos_credit_add($phone, (float)$earned, 'earn',
            ['receipt_id' => (string)$savedReceipt['id'], 'receipt_no' => (string)$read['receipt_no']]);
    }
    $s = golpos_loyalty_standing($phone);
    $dateLine = $read['date'] !== null ? ' dated ' . $read['date'] : '';
    $pointsLine = $earned > 0
        ? ' You earned ' . $earned . ' point' . ($earned === 1 ? '' : 's') . ' = '
            . lmoney((float)$earned) . ' store credit — balance '
            . lmoney((float)($s['credit'] ?? 0)) . '.'
        : '';
    lout(['reply' => '✓ Receipt counted — ' . $store . ' #' . $read['receipt_no'] . $dateLine
        . ': ' . lmoney((float)$read['total']) . '. ' . ($s ? standing_line($s) : '') . $pointsLine,
          'counted' => true, 'standing' => $s,
          'receipt' => ['store' => $store, 'date' => $read['date'],
                        'total' => $read['total'], 'receipt_no' => $read['receipt_no']]]);
}

// --- JSON actions ------------------------------------------------------------
if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $action = (string)($in['action'] ?? '');
    if ($action === 'register') {
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 60);
        $phone = golpos_loyalty_phone_key((string)($in['phone'] ?? ''));
        $email = mb_substr(trim((string)($in['email'] ?? '')), 0, 120);
        if ($name === '' || strlen($phone) < 7 || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            lout(['reply' => 'I need all three to join: your name, a phone number, and an email that looks right.',
                  'invalid' => true]);
        }
        $isNew = golpos_loyalty_customer($phone) === null;
        $c = golpos_loyalty_register($name, (string)($in['phone'] ?? ''), $email);
        $s = golpos_loyalty_standing($phone);
        lout(['reply' => ($isNew ? 'Welcome to ' . $shopShort . ' loyalty, ' . $name . '! '
                        : 'Welcome back, ' . $name . '! ')
            . 'Now send a photo of any ' . $shopShort . ' receipt and I\'ll add it to your total. '
            . ($s ? standing_line($s) : ''),
              'customer' => $c, 'standing' => $s]);
    }
    if ($action === 'standing') {
        $s = golpos_loyalty_standing((string)($in['phone'] ?? ''));
        if ($s === null) {
            lout(['reply' => 'That phone number is not on the loyalty list yet — join first.',
                  'needs_registration' => true]);
        }
        lout(['reply' => standing_line($s), 'standing' => $s]);
    }
}

lout(['reply' => 'Loyalty is here — join with name, phone and email, then send receipt photos.']);
