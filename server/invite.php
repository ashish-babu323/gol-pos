<?php
// Gol POS — employee invite endpoint (owner directive, Oct 28;
// PIN-ownership correction, Nov 2 — TASKS 231).
// The owner adds an employee in the Gol chat; the invite email carries a
// one-time link to web/invite.html?t=<token>. This endpoint serves that
// page: lookup validates the token and names the invitee; activate flips
// the record to active and issues the device token the register will
// present with chat calls. Activation NEVER writes a PIN: the OWNER sets
// every PIN (his own and each employee's) — in the Owner panel's Team
// card or by a staged Gol-chat card — and hands it to the employee. An
// employee can never set or reset their own PIN, here or anywhere.
// Only SHA-256 hashes of the invite token and the device token are stored;
// PINs live as password_hash, written only by owner paths. Nothing here
// elevates anyone to admin — an employee session holds the cashier
// domain only.

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

function iout(array $r, int $code = 200): void {
    http_response_code($code);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$action = (string)($in['action'] ?? '');
$token = (string)($in['token'] ?? '');

$emp = golpos_employee_by_invite_token($token);
if ($emp === null) {
    iout(['error' => 'This invite link is not valid any more — ask the owner to send a new one.'], 404);
}

if ($action === 'lookup') {
    $settings = golpos_settings();
    iout(['ok' => true, 'email' => (string)$emp['email'],
          'name' => (string)($emp['name'] ?? ''),
          'shop' => (string)($settings['store_name'] ?? 'Sunrise Mart'),
          'status' => (string)($emp['status'] ?? '')]);
}

// Activation accepts the invite and issues the device token. The legacy
// action name 'setpin' is accepted for an already-open page, but any PIN
// field in the request is IGNORED — activation writes no PIN, ever
// (TASKS 231: the owner sets all PINs and hands them out).
if ($action === 'activate' || $action === 'setpin') {
    if (($emp['status'] ?? '') === 'active') {
        iout(['error' => 'This invite was already used — this employee is active. The owner manages PINs.'], 409);
    }
    $device = bin2hex(random_bytes(24));
    $emp['status'] = 'active';
    $emp['invite_token_hash'] = null;
    $emp['device_token_hash'] = hash('sha256', $device);
    $emp['activated_at'] = date('c');
    golpos_save_employee($emp);
    iout(['ok' => true, 'email' => (string)$emp['email'], 'emp_token' => $device,
          'has_pin' => !empty($emp['pin_hash'])]);
}

iout(['error' => 'Unknown action.'], 400);
