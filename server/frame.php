<?php
// Gol POS — counter frames (Register Watch pass, Oct 28).
//
// POST (multipart): file field "photo", text field "txn" — saves the
//   register camera's frame for one completed CASH sale to
//   data/frames/<txn>.<ext> and flags the sale record frame: true, so
//   the receipt and the moment stay paired in Transactions history.
//   Cash only (the owner's rule is about cash): a card sale, an unknown
//   txn, or a non-photo upload refuses plainly and saves nothing. The
//   frame is a passenger — the sale already stands before this is ever
//   called, and nothing here can change it.
// GET  ?txn=<id>            — serves the saved frame's bytes to the
//   Transactions panel (data/ is outside the web root, so this is the
//   only window onto it). Unknown txn -> 404.

declare(strict_types=1);
require __DIR__ . '/store.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['unlock'])) {
    // The unlock feed's photo window (employee lane §3b): serves the
    // frame saved for one unlock event to the Owner panel. A photo is
    // all it is — never a biometric template, nothing is matched.
    $found = golpos_unlock_file((string)$_GET['unlock']);
    if ($found === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'no photo for that unlock']);
        exit;
    }
    [$path, $mime] = $found;
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

if ($method === 'GET' && isset($_GET['txn'])) {
    $found = golpos_frame_file((string)$_GET['txn']);
    if ($found === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'no frame for that sale']);
        exit;
    }
    [$path, $mime] = $found;
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($method === 'POST' && isset($_POST['unlock'])) {
    // The unlock stamp (§3b): one frame for one unlock event, saved as
    // data/frames/unlock_<event>.<ext> and flagged on the event record
    // the owner's unlock feed reads. No event, no save — a stray photo
    // never lands without the unlock it belongs to.
    $uid = trim((string)$_POST['unlock']);
    $ev = preg_match('/^unl_[a-z0-9]+$/', $uid) ? golpos_unlock_by_id($uid) : null;
    if ($ev === null) {
        http_response_code(404);
        echo json_encode(['error' => 'unknown unlock — no photo saved']);
        exit;
    }
    if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'no frame received']);
        exit;
    }
    $tmp = (string)$_FILES['photo']['tmp_name'];
    if (!is_uploaded_file($tmp) || (int)$_FILES['photo']['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['error' => 'that frame could not be read']);
        exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime])) {
        http_response_code(422);
        echo json_encode(['error' => 'frames are photos — JPEG, PNG or WebP']);
        exit;
    }
    foreach (array_values($exts) as $e) { @unlink(golpos_frames_dir() . '/unlock_' . $uid . '.' . $e); }
    $path = golpos_frames_dir() . '/unlock_' . $uid . '.' . $exts[$mime];
    if (!move_uploaded_file($tmp, $path)) {
        http_response_code(500);
        echo json_encode(['error' => 'the frame could not be saved']);
        exit;
    }
    golpos_unlock_mark_photo($uid);
    if (!empty($ev['email'])) { golpos_presence_photo_mark((string)$ev['email']); }
    echo json_encode(['ok' => true, 'unlock' => $uid, 'photo' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $txn = trim((string)($_POST['txn'] ?? ''));
    $sale = preg_match('/^txn_[a-z0-9]+$/', $txn) ? golpos_sale_by_id($txn) : null;
    if ($sale === null) {
        http_response_code(404);
        echo json_encode(['error' => 'unknown sale — no frame saved']);
        exit;
    }
    if (($sale['tender'] ?? '') !== 'cash') {
        http_response_code(422);
        echo json_encode(['error' => 'frames pair with cash sales only']);
        exit;
    }
    if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'no frame received']);
        exit;
    }
    $tmp = (string)$_FILES['photo']['tmp_name'];
    if (!is_uploaded_file($tmp) || (int)$_FILES['photo']['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['error' => 'that frame could not be read']);
        exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime])) {
        http_response_code(422);
        echo json_encode(['error' => 'frames are photos — JPEG, PNG or WebP']);
        exit;
    }
    // One frame per sale: a re-take replaces the earlier frame (and its
    // extension), never a second file claiming the same moment.
    foreach (array_values($exts) as $e) { @unlink(golpos_frames_dir() . '/' . $txn . '.' . $e); }
    $path = golpos_frames_dir() . '/' . $txn . '.' . $exts[$mime];
    if (!move_uploaded_file($tmp, $path)) {
        http_response_code(500);
        echo json_encode(['error' => 'the frame could not be saved']);
        exit;
    }
    golpos_mark_sale_frame($txn);
    echo json_encode(['ok' => true, 'txn' => $txn, 'frame' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'bad request']);
