<?php
// Gol POS — scan-by-phone pairing (Register Watch pass, Oct 28).
//
// POST {mint:true, session} -> mints a pairing token bound to the
//   register's chat session and returns {token, code, page, expires_in}.
//   The register shows the phone a link (web/scan.html?t=<token>) and a
//   short code; the phone page uploads through photo.php with the token,
//   which binds the photo — and its bill/chat results — to the
//   register's own session (see photo.php + the inbox in store.php).
// GET  ?resolve=<code>      -> the phone page trades the typed 6-char
//   code for its token: {token} or a plain 404 {error}.
//
// Tokens are random, single-session, and live ~10 minutes. Nothing here
// grants any access beyond "upload a photo into this session's chat" —
// every write the photo can stage still waits for the owner's "gol".

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['resolve'])) {
    $token = golpos_pair_resolve_code((string)$_GET['resolve']);
    if ($token === null) {
        http_response_code(404);
        echo json_encode(['error' => 'That code is not live — check it on the register, or ask for a fresh link.']);
        exit;
    }
    echo json_encode(['token' => $token], JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if (!empty($in['mint'])) {
    $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
    if ($sessionId === '') {
        http_response_code(422);
        echo json_encode(['error' => 'a session is required to pair']);
        exit;
    }
    $rec = golpos_pair_mint($sessionId);
    echo json_encode(['token' => $rec['token'], 'code' => $rec['code'],
                      'page' => 'scan.html', 'expires_in' => max(0, $rec['expires'] - time())],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'bad request']);
