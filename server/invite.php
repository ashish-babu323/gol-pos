<?php
// Gol POS — employee invite endpoint (owner directive, Oct 28).
// The owner adds an employee in the Gol chat; the invite email carries a
// one-time link to web/invite.html?t=<token>. This endpoint serves that
// page: lookup validates the token and names the invitee; setpin lets the
// employee choose their own 4–6 digit PIN, flips the record to active, and
// issues the device token the register will present with chat calls.
// Only SHA-256 hashes of the invite token and the device token are stored;
// the employee PIN is a password_hash. Nothing here elevates anyone to
// admin — an employee session holds the cashier domain only.

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

if ($action === 'setpin') {
    if (($emp['status'] ?? '') === 'active') {
        iout(['error' => 'This invite was already used — the employee PIN is set on their device.'], 409);
    }
    $pin = golpos_norm_pin((string)($in['pin'] ?? ''));
    if ($pin === null) { iout(['error' => 'Choose a PIN of 4–6 digits.'], 422); }
    $device = bin2hex(random_bytes(24));
    $emp['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
    $emp['status'] = 'active';
    $emp['invite_token_hash'] = null;
    $emp['device_token_hash'] = hash('sha256', $device);
    $emp['activated_at'] = date('c');
    golpos_save_employee($emp);
    iout(['ok' => true, 'email' => (string)$emp['email'], 'emp_token' => $device]);
}

iout(['error' => 'Unknown action.'], 400);
