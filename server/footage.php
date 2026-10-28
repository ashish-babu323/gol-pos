<?php
// Gol POS — the register's footage list (Register Camera Part B, Oct 28).
//
// A read-only shelf: every sale that carries a counter frame or an
// event clip, newest first, each with its moment playable beside its
// receipt. Owner-domain like the Team read — when an admin PIN exists,
// the listing demands an admin session (?session=<id> from the
// register); a virgin setup till reads openly. Nothing here writes.
//
// This is the register-side surface. The MR.STORE desk integration (the
// same list inside the store's own backend) rides the bridge in a later
// leg; this page is the endpoint it will read.

declare(strict_types=1);
require __DIR__ . '/store.php';

$sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['session'] ?? ''));
$sess = $sid !== '' ? golpos_session($sid) : [];
if (golpos_admin_pin_hash() !== null && empty($sess['admin'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Footage</title>'
       . '<p style="font-family:system-ui;padding:24px">Owner sign-in needed — open this list from the register with an admin session.</p>';
    exit;
}

$rows = golpos_footage_list(200);
$rc = golpos_regcam_state();
$onoff = static fn(bool $b): string => $b ? 'on' : 'off';
$camLine = $rc['mode'] === 'link'
    ? 'Register Camera (MR.STORE master): ' . $onoff($rc['master'])
      . ' · audio ' . $onoff($rc['audio']) . ' · footage ' . $onoff($rc['record'])
      . ' · this till\'s flip ' . $onoff($rc['flip'])
    : 'Standalone register — flip ' . $onoff($rc['flip'])
      . ' · audio ' . $onoff($rc['audio']) . ' · footage ' . $onoff($rc['record']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>Register footage</title>';
echo '<style>body{font-family:system-ui,sans-serif;margin:24px;color:#122}h1{font-size:20px}'
   . 'table{border-collapse:collapse;width:100%;max-width:860px}td,th{border-bottom:1px solid #dde;padding:8px;text-align:left}'
   . 'a{color:#0a58ca}.muted{color:#667}video{max-width:320px;width:100%}img{max-width:160px}</style>';
echo '<h1>Register footage</h1>';
echo '<p class="muted">' . htmlspecialchars($camLine, ENT_QUOTES) . ' — event clips only, never a 24/7 tape. Kept in this register\'s own records.</p>';
if (!$rows) {
    echo '<p>No footage yet — a cash sale with the camera on leaves its moment here.</p>';
    exit;
}
echo '<table><tr><th>When</th><th>Sale</th><th>Total</th><th>Frame</th><th>Clip</th></tr>';
foreach ($rows as $r) {
    $txn = htmlspecialchars($r['txn'], ENT_QUOTES);
    echo '<tr><td>' . htmlspecialchars($r['at'], ENT_QUOTES) . '</td><td>' . $txn . '</td>'
       . '<td>$' . number_format($r['total'], 2) . ' · ' . htmlspecialchars($r['tender'], ENT_QUOTES) . '</td>'
       . '<td>' . ($r['frame'] ? '<a href="frame.php?txn=' . $txn . '">photo</a>' : '—') . '</td>'
       . '<td>' . ($r['clip'] !== null
            ? '<video controls preload="metadata" src="frame.php?clip=' . $txn . '"></video>'
              . '<div class="muted">' . htmlspecialchars((string)($r['clip']['at'] ?? ''), ENT_QUOTES)
              . (!empty($r['clip']['audio']) ? ' · with sound' : ' · silent') . '</div>'
            : '—') . '</td></tr>';
}
echo '</table>';
