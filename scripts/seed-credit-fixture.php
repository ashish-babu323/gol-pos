<?php
// Gol POS — store-credit demo fixture (STANDALONE demo stage only).
//
// The Sunrise demo needs customers whose store credit the till can
// actually spend: two fictional loyalty customers with granted credit
// in the ONE ledger (loyalty.json), written through the same
// golpos_credit_add the live paths use — a 'grant' entry, labelled as
// what it is. Fictional customers only; never a real store, never a
// real balance.
//
// Usage (CLI, from the deployed demo tree):
//   php scripts/seed-credit-fixture.php
// Refuses to run in link mode (the Festival balance lives MR.STORE-
// side there). Idempotent: a phone that already holds a grant entry
// is left exactly as it stands, so re-runs never double-grant.

declare(strict_types=1);

// Shell-only: a real web request always carries REQUEST_METHOD and is
// refused. (The host's shell php is php-cgi — SAPI cgi-fcgi with no
// request — so a SAPI test cannot tell the two apart; this one can.)
if (isset($_SERVER['REQUEST_METHOD'])) { fwrite(STDERR, "Shell only\n"); exit(2); }
require __DIR__ . '/../server/store.php';

if (golpos_link_on()) { fwrite(STDERR, "link mode is ON — refusing to seed a linked stage\n"); exit(2); }

$fixture = [
    ['name' => 'Ava Demo', 'phone' => '2165550117', 'email' => 'ava.demo@example.com', 'grant' => 12.00],
    ['name' => 'Ben Demo', 'phone' => '2165550131', 'email' => 'ben.demo@example.com', 'grant' => 25.50],
];

foreach ($fixture as $f) {
    golpos_loyalty_register($f['name'], $f['phone'], $f['email']);
    $already = false;
    foreach (golpos_credit_entries() as $e) {
        if (($e['kind'] ?? '') === 'grant'
            && ($e['phone'] ?? '') === golpos_loyalty_phone_key($f['phone'])) { $already = true; break; }
    }
    if (!$already) {
        golpos_credit_add($f['phone'], (float)$f['grant'], 'grant',
            ['note' => 'Sunrise demo credit fixture (fictional customer)']);
    }
    echo $f['name'] . ' (' . $f['phone'] . '): balance $'
        . number_format(golpos_credit_balance($f['phone']), 2)
        . ($already ? ' (grant already present, untouched)' : ' (granted $' . number_format($f['grant'], 2) . ')')
        . PHP_EOL;
}
