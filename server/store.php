<?php
// Gol POS — tiny JSON store. One shop, one data dir, boring on purpose.
// Live files live in data/ and are created from the seed on first use.

declare(strict_types=1);

require_once __DIR__ . '/mrstore.php';

function golpos_data_dir(): string {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    return $dir;
}

function golpos_read(string $name, $fallback) {
    $path = golpos_data_dir() . '/' . $name;
    if (!file_exists($path)) { return $fallback; }
    $raw = file_get_contents($path);
    $data = json_decode($raw === false ? '' : $raw, true);
    return $data === null ? $fallback : $data;
}

function golpos_write(string $name, $data): void {
    $path = golpos_data_dir() . '/' . $name;
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

// The live catalog starts as a copy of the seed so staged writes never touch the seed.
// LINK MODE (MR.STORE connected): the catalog IS the linked store's live
// catalog — names, prices and on-hand counts from MR.STORE itself. The
// local seed/live files are not consulted at all; if the link is down the
// register falls back to them so it never stops ringing.
function golpos_catalog(): array {
    if (golpos_link_on()) {
        $linked = mrstore_catalog_with_stock();
        if ($linked !== null) { return $linked; }
    }
    $live = golpos_read('catalog.live.json', null);
    if ($live === null) {
        $seed = golpos_read('catalog.seed.json', ['store' => [], 'products' => []]);
        golpos_write('catalog.live.json', $seed);
        return $seed;
    }
    return golpos_catalog_fill_shelf($live);
}

// The living shelf (owner directive, Oct 28) rides on three seed-owned
// fields — stock (on hand), par (shelf capacity) and img (packshot). A
// live catalog written before the shelf existed carries none of them, so
// any product the seed knows gets the missing fields filled in from the
// seed on every read. Name, price and every other live value stay the
// live file's — a staged price change is never rolled back by the fill.
// A product the seed does not know (added in chat) has no stock to fill:
// stock stays unset, which the register reads as "not tracked".
function golpos_catalog_fill_shelf(array $live): array {
    $seed = golpos_read('catalog.seed.json', ['products' => []]);
    $bySku = [];
    foreach (($seed['products'] ?? []) as $sp) {
        if (isset($sp['sku'])) { $bySku[(string)$sp['sku']] = $sp; }
    }
    if (!$bySku) { return $live; }
    foreach ($live['products'] as $i => $p) {
        $sp = $bySku[(string)($p['sku'] ?? '')] ?? null;
        if ($sp === null) { continue; }
        foreach (['stock', 'par', 'img'] as $k) {
            if (!isset($p[$k]) && isset($sp[$k])) { $live['products'][$i][$k] = $sp[$k]; }
        }
    }
    return $live;
}

// A rung sale takes its units off the shelf (standalone mode only — the
// API gates the call on the link being off; in link mode MR.STORE moves
// its own stock and there is nothing local to decrement). Stock floors
// at zero: the shelf can read empty, never negative. Untracked products
// (no stock field) are skipped, exactly as MR.STORE skips its untracked.
function golpos_decrement_stock(array $lines): void {
    $cat = golpos_catalog();
    $idx = [];
    foreach ($cat['products'] as $i => $p) { $idx[(string)($p['sku'] ?? '')] = $i; }
    $moved = false;
    foreach ($lines as $l) {
        $sku = (string)($l['sku'] ?? '');
        if ($sku === '' || !isset($idx[$sku])) { continue; }
        $i = $idx[$sku];
        if (!isset($cat['products'][$i]['stock']) || !is_numeric($cat['products'][$i]['stock'])) { continue; }
        $cat['products'][$i]['stock'] = max(0, $cat['products'][$i]['stock'] - max(1, (int)($l['qty'] ?? 1)));
        $moved = true;
    }
    if ($moved) { golpos_write('catalog.live.json', $cat); }
}

// A delivery lands on the shelf: ONE receive path for every door. In
// link mode it runs the MR.STORE bridge receive — mrstore_adjust_stock,
// the exact function the Gol chat's stock_receive card executes — and
// logs the movement beside the chat's own receives ('via' says which
// door). Standing alone it increments the live catalog's stock, the
// same store golpos_decrement_stock decrements, so screen receives and
// rung sales compose on one number. An untracked product (no stock
// field — e.g. one added in chat) starts tracking from zero: the
// delivery is what puts it on the shelf. Returns the movement, or null
// when the SKU is unknown or the bridge could not write.
function golpos_receive_stock(string $sku, int $qty): ?array {
    if ($qty < 1) { return null; }
    $cat = golpos_catalog();
    $found = null;
    foreach ($cat['products'] as $i => $p) {
        if ((string)($p['sku'] ?? '') === $sku) { $found = [$i, $p]; break; }
    }
    if ($found === null) { return null; }
    [$i, $p] = $found;
    if (golpos_link_on()) {
        $res = mrstore_adjust_stock((string)($p['mr_name'] ?? $p['name']), (float)$qty);
        if ($res === null) { return null; }
        golpos_link_log(['kind' => 'receive', 'name' => $res['name'], 'qty' => $qty,
                         'before' => $res['before'], 'after' => $res['after'], 'via' => 'screen']);
        return ['sku' => $sku, 'name' => $res['name'],
                'before' => $res['before'], 'after' => $res['after']];
    }
    $before = (isset($p['stock']) && is_numeric($p['stock'])) ? $p['stock'] + 0 : 0;
    $after = $before + $qty;
    $cat['products'][$i]['stock'] = $after;
    golpos_write('catalog.live.json', $cat);
    return ['sku' => $sku, 'name' => (string)($p['name'] ?? ''),
            'before' => $before, 'after' => $after];
}

function golpos_save_catalog(array $catalog): void {
    if (golpos_link_on()) {
        // In link mode the catalog lives in MR.STORE: owner price changes
        // and new items sync there; the local file is never written.
        mrstore_sync_catalog($catalog);
        return;
    }
    golpos_write('catalog.live.json', $catalog);
}

// --- product creation: ONE constructor for every door ------------------------
// The Gol chat's new-item executor builds its record inline in brain.php
// ({sku, barcode, name, category, price} with sku = 1000 + count + 1 and
// category defaulting to Counter). This constructor builds the SAME record
// the SAME way — the register's Add Product sheet adds the fields a form
// can carry and a chat card cannot (cost, opening stock, par, a taxable
// flag, an image), each stored exactly the way the living shelf and the
// sale path already read them. One shape, never a second one: a product
// made here is indistinguishable in kind from one made in chat, and in
// link mode it lands in MR.STORE's catalog through the bridge's own
// create (mrstore_create_product) instead of the local file.
function golpos_create_product(array $f): array {
    $name = trim((string)($f['name'] ?? ''));
    if ($name === '') { return ['error' => 'Give the product a name.']; }
    $name = mb_substr($name, 0, 80);
    if (!isset($f['price']) || !is_numeric($f['price'])) {
        return ['error' => 'Give the product a price above $0.00.'];
    }
    $price = round((float)$f['price'], 2);
    if (!($price > 0) || $price > 9999.99) {
        return ['error' => 'Price must be between $0.01 and $9,999.99.'];
    }
    $cost = null;
    if (isset($f['cost']) && $f['cost'] !== '' && is_numeric($f['cost'])) {
        $cost = round((float)$f['cost'], 2);
        if ($cost < 0) { return ['error' => 'Cost cannot be negative.']; }
    }
    $category = trim((string)($f['category'] ?? ''));
    if ($category === '') { $category = 'Counter'; }
    $category = mb_substr($category, 0, 40);
    $stock = isset($f['stock']) && is_numeric($f['stock']) ? (int)$f['stock'] : 0;
    if ($stock < 0 || $stock > 99999) { return ['error' => 'Opening stock must be 0 or more.']; }
    $par = isset($f['par']) && is_numeric($f['par']) ? (int)$f['par'] : 0;
    if ($par < 0 || $par > 99999) { return ['error' => 'Par must be 0 or more.']; }
    $barcode = trim((string)($f['barcode'] ?? ''));
    $img = trim((string)($f['img'] ?? ''));
    $taxableOff = isset($f['taxable']) && !$f['taxable'];

    $catalog = golpos_catalog();
    $products = $catalog['products'] ?? [];
    if ($barcode !== '') {
        foreach ($products as $p) {
            if ((string)($p['barcode'] ?? '') === $barcode || (string)($p['sku'] ?? '') === $barcode) {
                return ['error' => 'That barcode is already on ' . (string)($p['name'] ?? 'another product') . '.'];
            }
        }
    }

    if (golpos_link_on()) {
        $created = mrstore_create_product([
            'name' => $name, 'price' => $price, 'cost' => $cost, 'category' => $category,
            'barcode' => $barcode, 'stock' => $stock,
        ]);
        if ($created === null) {
            return ['error' => 'MR.STORE could not take the new product — nothing was added.'];
        }
        return ['product' => $created];
    }

    // The brain's SKU scheme — 1000 + count + 1 — stepped past any
    // collision, so the generated code is always unique and scannable
    // (the register's scan matches sku as well as barcode; a blank
    // barcode takes the generated SKU as its barcode).
    $taken = [];
    foreach ($products as $p) {
        $taken[(string)($p['sku'] ?? '')] = true;
        $taken[(string)($p['barcode'] ?? '')] = true;
    }
    $sku = 1000 + count($products) + 1;
    while (isset($taken[(string)$sku])) { $sku++; }
    if ($barcode === '') { $barcode = (string)$sku; }
    $rec = ['sku' => (string)$sku, 'barcode' => $barcode, 'name' => $name,
            'category' => $category, 'price' => $price];
    if ($cost !== null) { $rec['cost'] = $cost; }
    $rec['stock'] = $stock;
    if ($par > 0) { $rec['par'] = $par; }
    if ($taxableOff) { $rec['taxable'] = false; }
    if ($img !== '') { $rec['img'] = $img; }
    $products[] = $rec;
    $catalog['products'] = $products;
    golpos_save_catalog($catalog);
    return ['product' => $rec];
}

// --- vendor bills: scan -> catalog ---------------------------------------------
// A vendor bill photo is read by the vision lane (meta.php
// golpos_meta_read_bill) into lines of name / qty / unit cost / barcode.
// From there the flow is ONE path for every door:
//   golpos_bill_process  — matches each line to the catalog. Matched lines
//     are ready at once; UNMATCHED lines are new-product proposals and a
//     bill NEVER stages half-ready: while any new line lacks a shelf price
//     (a price is never invented — the bill prints costs, not prices), the
//     whole read waits in the session (bill_await, the existing session
//     store) and the owner is asked. With prices complete, everything
//     stages as ONE 'bill' card.
//   golpos_execute_bill  — on the confirm word: creates land first (via
//     golpos_create_product, so receives can land), then the purchase is
//     recorded (golpos_record_purchase) and every line's stock arrives via
//     golpos_receive_stock — the one create and the one receive, no second
//     writers. A matched line's unit cost refreshes the product's stored
//     cost when the record carries one. A line that cannot land is
//     reported by name — a partial bill is never reported as a full one.

// The purchase record: one append per executed bill, lines marked with
// what actually landed, so the store's bill history can never claim more
// than the execution did. In link mode the same record rides the link log
// beside the bridge's own movement entries.
function golpos_record_purchase(array $rec): array {
    $all = golpos_read('purchases.json', []);
    $rec['id'] = 'bill_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['at'] = date('c');
    $all[] = $rec;
    golpos_write('purchases.json', $all);
    if (golpos_link_on()) {
        golpos_link_log(['kind' => 'purchase', 'id' => $rec['id'],
                         'vendor' => (string)($rec['vendor'] ?? ''),
                         'total' => (float)($rec['total'] ?? 0),
                         'lines' => count($rec['lines'] ?? [])]);
    }
    return $rec;
}

// A bill name's comparison key: case, punctuation and size-gluing folded,
// so the bill's "COCA-COLA 20OZ" and the catalog's "Coca-Cola 20oz" are
// the same product.
function golpos_bill_name_key(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/(\d)(oz|pk|ct|ml|kg|gm|lb)\b/u', '$1 $2', $s);
    $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', (string)$s);
    return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
}

// The catalog product a bill line names, or null when the line is a new
// product: an exact name/alias key first, then the shared resolver's one
// confident pick. A barcode never matches a line UP into the catalog — a
// new line carrying a code another product already holds is created (and
// refused, by name) by golpos_create_product's own dedupe at execution,
// never silently merged into the holder.
function golpos_bill_match(array $products, string $name): ?array {
    $key = golpos_bill_name_key($name);
    if ($key === '') { return null; }
    foreach ($products as $p) {
        if (golpos_bill_name_key((string)($p['name'] ?? '')) === $key) { return $p; }
        foreach (($p['aliases'] ?? []) as $a) {
            if (golpos_bill_name_key((string)$a) === $key) { return $p; }
        }
    }
    $r = resolve_item($products, $name);
    return !empty($r['one']) ? $r['one'] : null;
}

// The post-vision stage — the seam the bill battery drives directly with
// a canned read. $read is golpos_meta_read_bill's shape. Returns
// ['reply' => string] plus 'card' when the bill staged. When new products
// wait on prices, the read rides the session as bill_await and the reply
// asks — nothing is staged and nothing is written.
function golpos_bill_process(array $read, string $imagePath, string $sessionId): array {
    $money = static fn($v) => '$' . number_format((float)$v, 2);
    $catalog = golpos_catalog();
    $products = $catalog['products'] ?? [];
    $lines = [];
    $slot = []; // merge key -> index into $lines (a bill may print a line twice)
    foreach (($read['lines'] ?? []) as $l) {
        if (!is_array($l)) { continue; }
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($l['name'] ?? '')));
        $name = trim($name, " \t\"'");
        if ($name === '') { continue; }
        $qty = (isset($l['qty']) && is_numeric($l['qty']) && (float)$l['qty'] > 0)
            ? max(1, (int)round((float)$l['qty'])) : 1;
        $cost = (isset($l['unit_cost']) && is_numeric($l['unit_cost']) && (float)$l['unit_cost'] >= 0)
            ? round((float)$l['unit_cost'], 2) : 0.0;
        $bc = (isset($l['barcode']) && is_string($l['barcode'])
               && preg_match('/^\d{8,14}$/', $l['barcode'])) ? $l['barcode'] : '';
        $hit = golpos_bill_match($products, $name);
        if ($hit !== null) {
            $line = ['sku' => (string)$hit['sku'], 'name' => (string)$hit['name'],
                     'qty' => $qty, 'unit_cost' => $cost,
                     'line_total' => round($qty * $cost, 2), 'new' => false];
            $k = 'sku:' . $line['sku'];
        } else {
            $line = ['name' => $name, 'qty' => $qty, 'unit_cost' => $cost,
                     'line_total' => round($qty * $cost, 2),
                     'barcode' => $bc, 'price' => null, 'new' => true];
            $k = 'new:' . golpos_bill_name_key($name) . '|' . $bc;
        }
        if (isset($slot[$k])) {
            $i = $slot[$k];
            $lines[$i]['qty'] += $qty;
            $lines[$i]['line_total'] = round($lines[$i]['qty'] * $lines[$i]['unit_cost'], 2);
            continue;
        }
        $slot[$k] = count($lines);
        $lines[] = $line;
    }
    if (!$lines) {
        return ['reply' => 'I read the photo but found no bill lines on it — nothing was staged. '
            . 'If it is a vendor bill, try a clearer photo.'];
    }
    $total = 0.0;
    $newLines = [];
    $matched = 0;
    foreach ($lines as $l) {
        $total += (float)$l['line_total'];
        if (!empty($l['new'])) { $newLines[] = $l; } else { $matched++; }
    }
    $total = round($total, 2);
    $vendor = trim((string)($read['vendor'] ?? ''));
    $head = 'Bill read' . ($vendor !== '' ? ' — ' . $vendor : '') . ': '
        . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ', ' . $money($total) . '.';
    if (!$newLines) {
        $card = golpos_stage(['type' => 'bill', 'vendor' => $vendor, 'image' => $imagePath,
                              'lines' => $lines, 'total' => $total]);
        return ['reply' => $head . ' Every line matched your catalog — card ready, say "gol" to confirm.',
                'card' => $card];
    }
    // New products wait on the owner's prices: the read rides the session,
    // the brain collects the prices (or one margin) and stages the card.
    $s = golpos_session($sessionId);
    $s['bill_await'] = ['vendor' => $vendor, 'image' => $imagePath,
                        'lines' => $lines, 'total' => $total, 'at' => date('c')];
    golpos_save_session($sessionId, $s);
    $ask = [$head];
    if ($matched > 0) {
        $ask[] = $matched . ' line' . ($matched === 1 ? '' : 's') . ' matched your catalog.';
    }
    $ask[] = 'New on this bill — I need a shelf price for each before the bill can stage (a price is never guessed from a cost):';
    foreach ($newLines as $l) {
        $ask[] = '• ' . $l['name'] . ' — ' . $l['qty'] . ' × cost ' . $money($l['unit_cost'])
            . ($l['barcode'] !== '' ? ' · barcode ' . $l['barcode'] : '');
    }
    $ask[] = 'Reply with the prices (e.g. "' . $newLines[0]['name'] . ' '
        . number_format((float)$newLines[0]['unit_cost'] * 1.5, 2) . '") or one margin for all '
        . '("40% margin on all") — the whole bill then stages as one card. Say "cancel" to drop the bill.';
    return ['reply' => implode("\n", $ask)];
}

// Execute a staged bill card: creates first (so receives can land), the
// purchase record once, receives through the one receive path, matched
// cost refresh. Returns what landed and what did not, by name — the
// caller composes the report from exactly this.
function golpos_execute_bill(array $card): array {
    $created = []; $received = []; $failed = []; $landed = [];
    $skuOf = []; // line index -> sku that receives its stock
    foreach (($card['lines'] ?? []) as $i => $l) {
        if (empty($l['new'])) { $skuOf[$i] = (string)($l['sku'] ?? ''); continue; }
        $res = golpos_create_product([
            'name' => (string)$l['name'], 'price' => (float)($l['price'] ?? 0),
            'cost' => (float)($l['unit_cost'] ?? 0), 'barcode' => (string)($l['barcode'] ?? ''),
            'category' => 'Counter', 'stock' => 0,
        ]);
        if (isset($res['error'])) {
            $failed[] = ['name' => (string)$l['name'], 'reason' => (string)$res['error']];
            $landed[$i] = false;
            continue;
        }
        $p = $res['product'];
        $skuOf[$i] = (string)$p['sku'];
        $created[] = ['name' => (string)$p['name'], 'price' => (float)$p['price'],
                      'cost' => (float)($l['unit_cost'] ?? 0), 'qty' => (int)$l['qty'],
                      'line' => $i, 'received' => false, 'after' => null];
    }
    foreach (($card['lines'] ?? []) as $i => $l) {
        if (isset($landed[$i])) { continue; } // its create already failed
        $sku = $skuOf[$i] ?? '';
        $res = $sku !== '' ? golpos_receive_stock($sku, (int)$l['qty']) : null;
        if ($res === null) {
            $failed[] = ['name' => (string)$l['name'],
                         'reason' => !empty($l['new'])
                             ? 'added to the catalog, but its stock could not be received.'
                             : 'its stock could not be received.'];
            $landed[$i] = false;
            continue;
        }
        $landed[$i] = true;
        if (!empty($l['new'])) {
            foreach ($created as $ci => $c) {
                if ($c['line'] === $i) {
                    $created[$ci]['received'] = true;
                    $created[$ci]['after'] = $res['after'];
                }
            }
        } else {
            $received[] = ['name' => $res['name'], 'qty' => (int)$l['qty'],
                           'before' => $res['before'], 'after' => $res['after']];
        }
    }
    // A matched line's bill cost refreshes the stored cost when the record
    // carries one (standalone catalog; in link mode MR.STORE keeps its own
    // cost from its own bill lane and the sync never writes cost back).
    if (!golpos_link_on()) {
        $cat = golpos_catalog();
        $dirty = false;
        foreach (($card['lines'] ?? []) as $i => $l) {
            if (!empty($l['new']) || empty($landed[$i])) { continue; }
            foreach ($cat['products'] as $pi => $p) {
                if ((string)($p['sku'] ?? '') === (string)($l['sku'] ?? '')
                    && array_key_exists('cost', $p)
                    && abs((float)$p['cost'] - (float)$l['unit_cost']) > 0.0001) {
                    $cat['products'][$pi]['cost'] = (float)$l['unit_cost'];
                    $dirty = true;
                }
                if ((string)($p['sku'] ?? '') === (string)($l['sku'] ?? '')) { break; }
            }
        }
        if ($dirty) { golpos_save_catalog($cat); }
    }
    $recLines = [];
    foreach (($card['lines'] ?? []) as $i => $l) {
        $recLines[] = ['sku' => $skuOf[$i] ?? null, 'name' => (string)$l['name'],
                       'qty' => (int)$l['qty'], 'unit_cost' => (float)$l['unit_cost'],
                       'line_total' => (float)$l['line_total'],
                       'new' => !empty($l['new']),
                       'price' => isset($l['price']) && is_numeric($l['price']) ? (float)$l['price'] : null,
                       'barcode' => (string)($l['barcode'] ?? ''),
                       'landed' => !empty($landed[$i])];
    }
    $purchase = golpos_record_purchase([
        'vendor' => (string)($card['vendor'] ?? ''), 'image' => (string)($card['image'] ?? ''),
        'lines' => $recLines, 'total' => (float)($card['total'] ?? 0),
    ]);
    return ['created' => $created, 'received' => $received, 'failed' => $failed,
            'purchase' => $purchase];
}

// --- the sheet PIN gate -------------------------------------------------------
// Admin executions from a register sheet (Add Product today; the same
// gate serves any sheet) run the chat gate's own rules on the chat's own
// session store: an elevated session passes silently; with no admin PIN
// set anywhere, the sheet walks the two-step create (type it, type it
// again) before its first save; otherwise the PIN verifies against the
// admin hash with the chat's shared attempt counter and 3-strike,
// 10-minute lockout. The create dance stages under sheet-private keys
// (pc_pin_stage / pc_pin_tmp) so a half-finished sheet flow never
// hijacks a chat message, while elevation, attempts and lockout are the
// one shared session state both doors read.
function golpos_sheet_pin(array $in): array {
    $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['session'] ?? ''));
    if ($sid === '') { $sid = 'anon'; }
    $s = golpos_session($sid);
    if (!empty($s['admin'])) { return ['ok' => true]; }
    $pinIn = null;
    if (isset($in['pin']) && (string)$in['pin'] !== '') {
        $pinIn = golpos_norm_pin((string)$in['pin']);
        if ($pinIn === null) {
            return ['error' => 'PIN must be 4–6 digits.', 'pin_required' => true,
                    'stage' => golpos_admin_pin_hash() === null ? 'create1' : 'verify'];
        }
    }
    $hash = golpos_admin_pin_hash();
    if ($hash === null) {
        // No admin PIN exists yet: this sheet sets it (two entries),
        // exactly as the chat's first gated ask does.
        if ($pinIn === null) {
            return ['pin_required' => true, 'stage' => 'create1',
                    'note' => 'No admin PIN yet — choose one (4–6 digits) to continue.'];
        }
        if (($s['pc_pin_stage'] ?? null) === 'create2' && !empty($s['pc_pin_tmp'])) {
            if (!password_verify($pinIn, (string)$s['pc_pin_tmp'])) {
                $s['pc_pin_tmp'] = null; $s['pc_pin_stage'] = 'create1';
                golpos_save_session($sid, $s);
                return ['error' => 'The two PINs did not match — choose it again.',
                        'pin_required' => true, 'stage' => 'create1'];
            }
            golpos_set_admin_pin((string)$s['pc_pin_tmp']);
            $s['admin'] = true; $s['pc_pin_tmp'] = null; $s['pc_pin_stage'] = null;
            $s['locked'] = false; $s['unlocked_by'] = 'admin';
            $s['unlocked_name'] = 'Owner'; $s['unlocked_role'] = 'admin';
            golpos_save_session($sid, $s);
            return ['ok' => true];
        }
        $s['pc_pin_tmp'] = password_hash($pinIn, PASSWORD_DEFAULT);
        $s['pc_pin_stage'] = 'create2';
        golpos_save_session($sid, $s);
        return ['pin_required' => true, 'stage' => 'create2',
                'note' => 'Once more — type the same PIN again to set it.'];
    }
    // A PIN exists: verify, on the shared attempts / lockout state.
    $now = time();
    if ((int)($s['locked_until'] ?? 0) > $now) {
        return ['error' => 'PIN entry is locked for '
                . (int)ceil(((int)$s['locked_until'] - $now) / 60) . ' more minute(s).',
                'locked' => true];
    }
    if ($pinIn === null) {
        return ['pin_required' => true, 'stage' => 'verify',
                'note' => 'That is an admin action — enter the admin PIN.'];
    }
    if (password_verify($pinIn, $hash)) {
        $s['admin'] = true; $s['attempts'] = 0;
        $s['locked'] = false; $s['unlocked_by'] = 'admin';
        $s['unlocked_name'] = 'Owner'; $s['unlocked_role'] = 'admin';
        golpos_save_session($sid, $s);
        return ['ok' => true];
    }
    $s['attempts'] = (int)($s['attempts'] ?? 0) + 1;
    if ($s['attempts'] >= 3) {
        $s['attempts'] = 0; $s['locked_until'] = $now + 600;
        golpos_save_session($sid, $s);
        return ['error' => 'Three wrong PINs — PIN entry is locked for 10 minutes.', 'locked' => true];
    }
    golpos_save_session($sid, $s);
    $left = 3 - (int)$s['attempts'];
    return ['error' => 'Wrong PIN — ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.',
            'pin_required' => true, 'stage' => 'verify'];
}

function golpos_sales(): array {
    return golpos_read('sales.json', []);
}

// $at lets the simulation runner record a sale at its simulated moment;
// the register always passes null and stamps the real time.
function golpos_append_sale(array $sale, ?string $at = null): array {
    $sales = golpos_sales();
    $sale['id'] = 'txn_' . substr(sha1(uniqid('', true)), 0, 10);
    $sale['at'] = $at ?? date('c');
    $sales[] = $sale;
    golpos_write('sales.json', $sales);
    return $sale;
}

// One staged card at a time — the register shows it, the confirm word executes it.
function golpos_pending(): ?array {
    return golpos_read('pending.json', null);
}

function golpos_stage(array $card): array {
    $card['id'] = 'card_' . substr(sha1(uniqid('', true)), 0, 8);
    $card['staged_at'] = date('c');
    golpos_write('pending.json', $card);
    return $card;
}

function golpos_clear_pending(): void {
    $path = golpos_data_dir() . '/pending.json';
    if (file_exists($path)) { unlink($path); }
}

// --- item resolution: exact name > alias > distinctive token > tie -> clarify
// A person hears "Coke" and knows the item: the catalog carries the nicknames
// people actually use, and resolution checks name, aliases, and name tokens.
// Shared by the brain and the photo lane.
function resolve_item(array $products, string $text): array {
    $t = mb_strtolower($text);
    $hits = [];
    foreach ($products as $p) {
        $name = mb_strtolower($p['name']);
        if (mb_strpos($t, $name) !== false) { return ['one' => $p]; }
    }
    // A nickname shared by several products (chips, beer) is a tie, not a first-match win.
    $aliasHits = [];
    foreach ($products as $p) {
        foreach (($p['aliases'] ?? []) as $a) {
            $a = (string)$a;
            if ($a === '') { continue; }
            // Latin aliases match at a word start, so "pani" never fires
            // inside "company" and "dew" never inside "dewalt"; a trailing
            // plural s still counts ("bananas"). Devanagari aliases stay
            // substring matches — postpositions attach to the word
            // (कोकको) and must still resolve.
            $hit = preg_match('/[\x{0900}-\x{097F}]/u', $a)
                ? (mb_strpos($t, mb_strtolower($a)) !== false)
                : (preg_match('/\b' . preg_quote(mb_strtolower($a), '/') . '(?:s|es)?\b/u', $t) === 1);
            if ($hit) { $aliasHits[] = $p; break; }
        }
    }
    // A size the owner NAMED picks between siblings: "coke 20 ota ayo" is
    // the 20oz Coke, not the 12pk that happens to own the bare "coke"
    // alias — the number identifies the product (and, classifier-bound,
    // doubles as its count). The pick counts only when exactly one product
    // carries that size AND shares a non-size name word with the text, so a
    // price or a count can never impersonate a size.
    $sized = golpos_size_pick($products, $t);
    if ($sized !== null) {
        if (count($aliasHits) === 0) { return ['one' => $sized]; }
        if (count($aliasHits) === 1 && $aliasHits[0]['sku'] !== $sized['sku']) {
            return ['one' => $sized];
        }
        if (count($aliasHits) > 1) {
            foreach ($aliasHits as $h) {
                if ($h['sku'] === $sized['sku']) { return ['one' => $sized]; }
            }
        }
    }
    if (count($aliasHits) === 1) { return ['one' => $aliasHits[0]]; }
    if (count($aliasHits) > 1) { return ['many' => $aliasHits]; }
    foreach ($products as $p) {
        $score = 0;
        $tokens = preg_split('/[^a-z0-9]+/u', mb_strtolower($p['name'] . ' ' . implode(' ', $p['aliases'] ?? [])));
        foreach ($tokens as $tok) {
            $tok = trim((string)$tok);
            if (mb_strlen($tok) >= 3 && preg_match('/\b' . preg_quote($tok, '/') . '\b/u', $t)) { $score++; }
        }
        if ($score > 0) { $hits[] = ['p' => $p, 's' => $score]; }
    }
    if (!$hits) { return ['none' => true]; }
    usort($hits, fn($a, $b) => $b['s'] <=> $a['s']);
    $top = $hits[0]['s'];
    $best = array_values(array_filter($hits, fn($h) => $h['s'] === $top));
    if (count($best) === 1) { return ['one' => $best[0]['p']]; }
    return ['many' => array_map(fn($h) => $h['p'], $best)];
}

// The product a named size points at, or null. Sizes are read off product
// names (20oz, 12pk, 12 Count); the text names a size glued/spaced (20oz,
// 20 oz) or as a bare number equal to a size's numeric part (20, for the
// 20oz bottle). Exactly one carrier + a shared non-size word, or no pick.
function golpos_size_pick(array $products, string $t): ?array {
    $canon = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $sizeRe = '/(\d+(?:\.\d+)?)\s*(?:fl\s*oz|oz|ml|ltr|litres?|liters?|l|gm|g|kg|lb|pk|packs?|count|ct)\b/iu';
    $named = [];
    if (preg_match_all($sizeRe, $t, $sm)) {
        foreach ($sm[1] as $v) { $named[] = $canon($v); }
    }
    if (!$named && preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)(?![\d.])/u', $t, $bm)) {
        foreach ($bm[1] as $v) { $named[] = $canon($v); }
    }
    if (!$named) { return null; }
    $textWords = array_filter(preg_split('/[^a-z0-9]+/u', $t) ?: [], fn($w) => $w !== '');
    $found = [];
    foreach ($products as $p) {
        $sizes = [];
        if (preg_match_all($sizeRe, mb_strtolower($p['name']), $pm)) {
            foreach ($pm[1] as $v) { $sizes[] = $canon($v); }
        }
        if (!array_intersect($named, $sizes)) { continue; }
        // Identity words are NON-SIZE words: a token carrying a digit
        // ('20oz') is the size itself, never identity — with it, every
        // 20oz sibling "shared a word" with the text and the pick
        // drowned (battery catch on the QA catalog, Oct 28: 'coca cola
        // 20oz' tied Coke 20oz against Mountain Dew 20oz Bottles).
        $words = array_filter(preg_split('/[^a-z0-9]+/u', mb_strtolower($p['name'])) ?: [],
                              fn($w) => $w !== '' && !preg_match('/\d/', $w));
        foreach (($p['aliases'] ?? []) as $a) {
            foreach (preg_split('/\s+/u', mb_strtolower((string)$a)) ?: [] as $w) {
                if ($w !== '' && !preg_match('/\d/u', $w)) { $words[] = $w; }
            }
        }
        if (array_intersect($words, $textWords)) { $found[] = $p; }
    }
    return count($found) === 1 ? $found[0] : null;
}

// The day-close record: closing the day freezes the summary into ONE saved
// record per date (a re-close refreshes it) that the owner view reads back.
// The record composes WITH batch state: the batches sealed that day and any
// card sales still unbatched, so a close can never silently skip a batch.
function golpos_close_day(string $ymd): array {
    $rec = golpos_day_summary($ymd);
    $rec['closed_at'] = date('c');
    $rec['batches'] = array_map(static fn($b) => [
        'n' => $b['n'], 'count' => $b['count'], 'total' => $b['total'],
        'closed_by' => $b['closed_by'], 'close_time' => $b['close_time'] ?? null,
    ], golpos_batches_on($ymd));
    $unb = golpos_unbatched_card_sales($ymd);
    $ut = 0.0; foreach ($unb as $s) { $ut += golpos_batch_amount($s); }
    $rec['unbatched_card'] = ['count' => count($unb), 'total' => round($ut, 2)];
    $closes = golpos_read('closes.json', []);
    $out = [];
    foreach ($closes as $c) { if (($c['date'] ?? '') !== $ymd) { $out[] = $c; } }
    $out[] = $rec;
    golpos_write('closes.json', $out);
    return $rec;
}

// A sale's money split, one shape for every reader: the recorded
// tenders map when the sale carries one (a credit split), else the
// whole total on its single tender. Credit is a TENDER, never a
// discount — the sale's total stands; the split only says whose money
// it was.
function golpos_sale_tenders(array $sale): array {
    $out = ['cash' => 0.0, 'card' => 0.0, 'credit' => 0.0];
    if (isset($sale['tenders']) && is_array($sale['tenders'])) {
        foreach ($out as $k => $_) {
            if (isset($sale['tenders'][$k]) && is_numeric($sale['tenders'][$k])) {
                $out[$k] = round((float)$sale['tenders'][$k], 2);
            }
        }
        return $out;
    }
    $t = (string)($sale['tender'] ?? 'cash');
    $out[$t === 'card' ? 'card' : ($t === 'credit' ? 'credit' : 'cash')] = round((float)($sale['total'] ?? 0), 2);
    return $out;
}

function golpos_day_summary(string $ymd): array {
    $gross = 0.0; $cash = 0.0; $card = 0.0; $credit = 0.0; $count = 0;
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $gross += (float)($s['total'] ?? 0); $count++;
        $t = golpos_sale_tenders($s);
        $cash += $t['cash']; $card += $t['card']; $credit += $t['credit'];
    }
    // Refunds executed this day adjust this day (refund law): a refund
    // of an older sale is a negative entry in the CURRENT day, never a
    // rewrite of the day the sale was rung.
    $refunded = 0.0;
    foreach (golpos_refunds() as $r) {
        if (substr((string)($r['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $refunded += (float)($r['total'] ?? 0);
        $gross -= (float)($r['total'] ?? 0);
        $t = (isset($r['tenders']) && is_array($r['tenders'])) ? $r['tenders'] : [];
        $cash -= (float)($t['cash'] ?? 0); $card -= (float)($t['card'] ?? 0);
        $credit -= (float)($t['credit'] ?? 0);
    }
    return ['date' => $ymd, 'gross' => round($gross, 2), 'cash' => round($cash, 2),
            'card' => round($card, 2), 'credit' => round($credit, 2),
            'refunds' => round($refunded, 2), 'txns' => $count];
}

// The saved close record for one date, if the day was closed (chat or panel).
function golpos_close_record(string $ymd): ?array {
    foreach (golpos_read('closes.json', []) as $c) {
        if (($c['date'] ?? '') === $ymd) { return $c; }
    }
    return null;
}

// --- receipt print settings: ONE store, TWO doors ----------------------------
// The Gol chat stages changes as cards (executed on "gol") — the only
// door that writes. The owner panel MIRRORS the same values read-only
// (owner 85/15 rule, Oct 28: configuration lives in chat; the panel is a
// light mirror, never a second settings desk). The receipt view renders from these values.
function golpos_settings_defaults(): array {
    return [
        'store_name'   => 'Sunrise Mart (demo)',
        'address'      => '123 Demo Street, Cleveland, OH 44113',
        'show_address' => true,
        'phone'        => '(216) 555-0100',
        'show_phone'   => true,
        'logo'         => true,
        'footer'       => 'Thank you — visit again!',
        'copies'       => 1,
        'paper_width'  => '80mm',
        'show_tax'     => true,
        'auto_open'    => true,
        // Store hours + card-batch mode (owner directive, Oct 28): the batch
        // close is manual by default; in auto mode the day's card batch seals
        // itself at the store's closing time (see golpos_batch_auto_sweep).
        'store_open'   => '08:00',
        'store_close'  => '21:00',
        'batch_mode'   => 'manual',
        // Card surcharge (owner directive, Oct 28): a percent of
        // subtotal + tax added to CARD sales only, computed by the
        // sale endpoint from this saved value — never from the client.
        // 0 = off; cash never carries it.
        'card_surcharge_pct' => 0,
        // Register open/closed (owner directive, Oct 28): the Settings
        // dropdown's Store toggle. Closed pauses NEW sales — the register
        // blocks the tap and the sale endpoint refuses the POST — while
        // chat, browsing and the owner panel keep working.
        'register_open' => true,
        // Register Watch (owner directive, Oct 28): when ON and this
        // register has a camera, completing a CASH sale saves one
        // counter frame against the sale's id (data/frames/<txn>.jpg),
        // so the receipt and the moment stay paired. Default OFF; card
        // sales never take a frame; a missing camera never blocks a sale.
        'watch_frame' => false,
        // Register Camera Part B (Oct 28): on a STANDALONE register the
        // local flip above stands in for the MR.STORE master, and these
        // two are the register's own audio / event-footage switches —
        // the same names MR.STORE's Settings carry per store. In link
        // mode they are ignored: the master's children rule (see
        // golpos_regcam_state). Defaults OFF: video-only still frames,
        // the pre-Part-B behavior, until the owner turns them on.
        'register_camera_audio' => false,
        'register_camera_record' => false,
        // Refunds + customer credit (owner directive, Oct 28): refunds
        // are allowed by default and demand the admin PIN; a sale stays
        // refundable at the till for 30 days (older routes the owner to
        // MR.STORE). A refund always attaches to a recorded sale — by
        // design there is no setting that frees the amount. Credit
        // redeem is on wherever a credit program exists (the till reads
        // the balance; it never invents one).
        'refunds_allowed' => true,
        'refund_pin' => true,
        'refund_window_days' => 30,
        'credit_redeem' => true,
    ];
}

function golpos_settings(): array {
    $saved = golpos_read('settings.json', []);
    return array_merge(golpos_settings_defaults(), is_array($saved) ? $saved : []);
}

// Whitelist + validate, then persist. Unknown keys and out-of-range values
// are ignored rather than stored — a settings file is never a junk drawer.
function golpos_save_settings(array $in): array {
    $cur = golpos_settings();
    foreach (['show_address', 'show_phone', 'logo', 'show_tax', 'auto_open', 'register_open', 'watch_frame',
              'register_camera_audio', 'register_camera_record',
              'refunds_allowed', 'refund_pin', 'credit_redeem'] as $k) {
        if (array_key_exists($k, $in)) { $cur[$k] = (bool)$in[$k]; }
    }
    if (isset($in['refund_window_days']) && is_numeric($in['refund_window_days'])) {
        $w = (int)$in['refund_window_days'];
        if ($w >= 0 && $w <= 365) { $cur['refund_window_days'] = $w; }
    }
    foreach (['store_name' => 60, 'address' => 120, 'phone' => 40, 'footer' => 120] as $k => $max) {
        if (isset($in[$k]) && is_string($in[$k])) { $cur[$k] = mb_substr(trim($in[$k]), 0, $max); }
    }
    if (isset($in['copies'])) {
        $c = (int)$in['copies'];
        if ($c >= 1 && $c <= 3) { $cur['copies'] = $c; }
    }
    if (isset($in['paper_width']) && in_array($in['paper_width'], ['58mm', '80mm'], true)) {
        $cur['paper_width'] = $in['paper_width'];
    }
    if (isset($in['batch_mode']) && in_array($in['batch_mode'], ['manual', 'auto'], true)) {
        $cur['batch_mode'] = $in['batch_mode'];
    }
    if (isset($in['card_surcharge_pct']) && is_numeric($in['card_surcharge_pct'])) {
        $pct = round((float)$in['card_surcharge_pct'], 1);
        if ($pct >= 0 && $pct <= 10) { $cur['card_surcharge_pct'] = $pct; }
    }
    foreach (['store_open', 'store_close'] as $k) {
        if (isset($in[$k]) && is_string($in[$k])
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($in[$k]))) {
            $cur[$k] = trim($in[$k]);
        }
    }
    golpos_write('settings.json', $cur);
    return $cur;
}

// --- Receipt Loyalty (owner addition, Oct 26) -------------------------------
// The customer joins once (name / phone / email), then sends PHOTOS of their
// receipts; Gol vision-reads each one, saves it under the customer (phone =
// master key), dedupes by receipt number per store, and the owner sees a
// live leaderboard built from the saved receipts — loyalty with no app, no
// card, no counter sheet. Loyalty lives in its OWN store file, seeded empty,
// and never touches the catalog seed or the sales data formats.
function golpos_loyalty(): array {
    $d = golpos_read('loyalty.json', null);
    if (!is_array($d) || !isset($d['customers'], $d['receipts'])
        || !is_array($d['customers']) || !is_array($d['receipts'])) {
        return ['customers' => [], 'receipts' => []];
    }
    return $d;
}

// Phone is the master key: digits only, and a leading US country code folds
// away so "+1 (216) 555-0142" and "2165550142" are the same customer.
function golpos_loyalty_phone_key(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 11 && $d[0] === '1') { $d = substr($d, 1); }
    return $d;
}

function golpos_loyalty_customer(string $phone): ?array {
    $key = golpos_loyalty_phone_key($phone);
    if ($key === '') { return null; }
    foreach (golpos_loyalty()['customers'] as $c) {
        if (($c['phone'] ?? '') === $key) { return $c; }
    }
    return null;
}

// Join once; joining again with the same phone refreshes name/email and
// keeps every receipt already counted.
function golpos_loyalty_register(string $name, string $phone, string $email): array {
    $key = golpos_loyalty_phone_key($phone);
    $d = golpos_loyalty();
    foreach ($d['customers'] as $i => $c) {
        if (($c['phone'] ?? '') === $key) {
            $d['customers'][$i]['name'] = $name;
            $d['customers'][$i]['email'] = $email;
            golpos_write('loyalty.json', $d);
            return $d['customers'][$i];
        }
    }
    $c = ['name' => $name, 'phone' => $key, 'email' => $email, 'joined' => date('c')];
    $d['customers'][] = $c;
    golpos_write('loyalty.json', $d);
    return $c;
}

// A receipt counts once: the same receipt number at the same store is the
// same receipt, whoever sends the photo. Numbers compare without spacing
// or punctuation ("SM-10482" and "SM 10482" are one receipt).
function golpos_loyalty_receipt_key(string $store, string $receiptNo): string {
    $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
    return $norm($store) . '|' . $norm($receiptNo);
}

function golpos_loyalty_receipt_seen(string $store, string $receiptNo): ?array {
    $k = golpos_loyalty_receipt_key($store, $receiptNo);
    foreach (golpos_loyalty()['receipts'] as $r) {
        if (golpos_loyalty_receipt_key((string)($r['store'] ?? ''), (string)($r['receipt_no'] ?? '')) === $k) {
            return $r;
        }
    }
    return null;
}

function golpos_loyalty_add_receipt(string $phone, array $receipt): array {
    $d = golpos_loyalty();
    $receipt['id'] = 'rcpt_' . substr(sha1(uniqid('', true)), 0, 10);
    $receipt['phone'] = golpos_loyalty_phone_key($phone);
    $receipt['at'] = date('c');
    $d['receipts'][] = $receipt;
    golpos_write('loyalty.json', $d);
    return $receipt;
}

// The leaderboard is COMPUTED from the saved receipts on every read, so the
// owner view can never drift from what customers were told.
function golpos_loyalty_board(): array {
    $d = golpos_loyalty();
    $rows = [];
    foreach ($d['customers'] as $c) {
        $total = 0.0; $count = 0;
        foreach ($d['receipts'] as $r) {
            if (($r['phone'] ?? '') === ($c['phone'] ?? '')) {
                $total += (float)($r['total'] ?? 0);
                $count++;
            }
        }
        $rows[] = ['name' => (string)($c['name'] ?? ''), 'phone' => (string)($c['phone'] ?? ''),
                   'total' => round($total, 2), 'receipts' => $count,
                   'credit' => golpos_credit_balance((string)($c['phone'] ?? ''))];
    }
    usort($rows, static fn($a, $b) => ($b['total'] <=> $a['total']) ?: ($b['receipts'] <=> $a['receipts']));
    foreach ($rows as $i => &$row) { $row['rank'] = $i + 1; }
    unset($row);
    return $rows;
}

// One customer's standing: running total, receipt count, rank, field size.
function golpos_loyalty_standing(string $phone): ?array {
    $key = golpos_loyalty_phone_key($phone);
    $board = golpos_loyalty_board();
    foreach ($board as $row) {
        if ($row['phone'] === $key) {
            $row['of'] = count($board);
            // The spendable balance rides the same read, so the
            // customer page and the till can never show two numbers.
            $row['credit'] = golpos_credit_balance($key);
            return $row;
        }
    }
    return null;
}

// --- Customer credit: the points ledger (owner directive, Oct 28) ------------
// Festival's rule is the ledger's rule: 1 point per $20 of counted
// receipt spend, 1 point = $1 of store credit. The ledger lives INSIDE
// loyalty.json beside the customers and receipts it derives from — one
// ledger of truth: the balance the till spends is the balance the
// customer page shows, computed from these entries on every read.
// Entries are signed amounts: earn/grant/restore add, redeem spends.
// A redeem is keyed by its sale id and a restore by its refund id, and
// the ledger itself refuses a second entry under the same key — a
// retried tender can never double-spend, whatever door retries it.
function golpos_credit_entries(): array {
    $d = golpos_read('loyalty.json', null);
    if (!is_array($d) || !isset($d['credit']) || !is_array($d['credit'])) { return []; }
    return array_values(array_filter($d['credit'], 'is_array'));
}

function golpos_credit_balance(string $phone): float {
    $key = golpos_loyalty_phone_key($phone);
    if ($key === '') { return 0.0; }
    $bal = 0.0;
    foreach (golpos_credit_entries() as $e) {
        if (($e['phone'] ?? '') === $key) { $bal += (float)($e['amount'] ?? 0); }
    }
    return round($bal, 2);
}

// The credit account for one phone: the loyalty customer plus their
// balance, or null when the number has no record at all (the till says
// "no credit on this number" — it never guesses a balance).
function golpos_credit_account(string $phone): ?array {
    $c = golpos_loyalty_customer($phone);
    if ($c === null) { return null; }
    return ['name' => (string)($c['name'] ?? ''), 'phone' => (string)$c['phone'],
            'balance' => golpos_credit_balance((string)$c['phone'])];
}

function golpos_credit_add(string $phone, float $amount, string $kind, array $extra = []): ?array {
    $key = golpos_loyalty_phone_key($phone);
    if ($key === '' || $amount == 0.0) { return null; }
    $dedupeKey = null; $dedupeVal = null;
    if ($kind === 'redeem' && !empty($extra['sale_id'])) { $dedupeKey = 'sale_id'; $dedupeVal = (string)$extra['sale_id']; }
    if ($kind === 'restore' && !empty($extra['refund_id'])) { $dedupeKey = 'refund_id'; $dedupeVal = (string)$extra['refund_id']; }
    if ($kind === 'earn' && !empty($extra['receipt_id'])) { $dedupeKey = 'receipt_id'; $dedupeVal = (string)$extra['receipt_id']; }
    $d = golpos_loyalty();
    if (!isset($d['credit']) || !is_array($d['credit'])) { $d['credit'] = []; }
    if ($dedupeKey !== null) {
        foreach ($d['credit'] as $e) {
            if (is_array($e) && ($e['kind'] ?? '') === $kind
                && (string)($e[$dedupeKey] ?? '') === $dedupeVal) { return null; }
        }
    }
    $entry = ['id' => 'cred_' . substr(sha1(uniqid('', true)), 0, 10),
              'phone' => $key, 'amount' => round($amount, 2), 'kind' => $kind, 'at' => date('c')];
    foreach (['sale_id', 'refund_id', 'receipt_id', 'receipt_no', 'note'] as $k) {
        if (isset($extra[$k]) && (string)$extra[$k] !== '') { $entry[$k] = (string)$extra[$k]; }
    }
    $d['credit'][] = $entry;
    golpos_write('loyalty.json', $d);
    return $entry;
}

function golpos_credit_redeem_for_sale(string $saleId): ?array {
    foreach (golpos_credit_entries() as $e) {
        if (($e['kind'] ?? '') === 'redeem' && (string)($e['sale_id'] ?? '') === $saleId) { return $e; }
    }
    return null;
}

// --- Refunds (owner directive, Oct 28) ----------------------------------------
// A refund attaches to a RECORDED sale — never a free-typed amount —
// and money returns to the sale's ORIGINAL tenders. refunds.json is
// the one record: what a sale has already given back (per line) is
// DERIVED from it, so a line can never refund twice or beyond what was
// sold, and the day/batch math above reads the same facts.
function golpos_refunds(): array {
    $r = golpos_read('refunds.json', []);
    return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
}

function golpos_refunds_for_sale(string $saleId): array {
    return array_values(array_filter(golpos_refunds(),
        static fn($r) => (string)($r['sale_id'] ?? '') === $saleId));
}

function golpos_refund_by_id(string $id): ?array {
    foreach (golpos_refunds() as $r) {
        if ((string)($r['id'] ?? '') === $id) { return $r; }
    }
    return null;
}

// A sale line's stable identity for refund picks: its SKU, or its
// position for manual lines (a manual line has no SKU to hold).
function golpos_sale_line_key(array $line, int $i): string {
    $sku = (string)($line['sku'] ?? '');
    return $sku !== '' ? 'sku:' . $sku : 'manual:' . $i;
}

// What has already gone back on one line: [qty, amount].
function golpos_refunded_on_line(array $refunds, string $key): array {
    $qty = 0; $amt = 0.0;
    foreach ($refunds as $r) {
        foreach ((array)($r['lines'] ?? []) as $l) {
            if ((string)($l['key'] ?? '') === $key) {
                $qty += (int)($l['qty'] ?? 0);
                $amt += (float)($l['line_total'] ?? 0);
            }
        }
    }
    return [$qty, round($amt, 2)];
}

// Quote a refund against a recorded sale — the register math both
// doors share (Transactions sheet and the Gol chat card). $picks is a
// list of ['key' => line key, 'qty' => n]; null picks everything still
// refundable. Returns the full breakdown or ['error' => …] — policy
// (window), state (already refunded) and quantity refusals all speak
// here, so no door can talk its way past them.
function golpos_refund_quote(array $sale, ?array $picks): array {
    $st = golpos_settings();
    $saleDay = substr((string)($sale['at'] ?? ''), 0, 10);
    $age = (strtotime(date('Y-m-d')) - strtotime($saleDay)) / 86400;
    $window = (int)($st['refund_window_days'] ?? 30);
    if ($age > $window) {
        return ['error' => 'That sale is ' . (int)$age . ' days old — past the ' . $window
            . '-day refund window. Older refunds run through MR.STORE; nothing was refunded.'];
    }
    $prior = golpos_refunds_for_sale((string)($sale['id'] ?? ''));
    $priorTax = 0.0; $priorSurcharge = 0.0; $priorTotal = 0.0;
    foreach ($prior as $r) {
        $priorTax += (float)($r['tax'] ?? 0);
        $priorSurcharge += (float)($r['surcharge'] ?? 0);
        $priorTotal += (float)($r['total'] ?? 0);
    }
    $states = [];
    foreach ((array)($sale['lines'] ?? []) as $i => $l) {
        if (!is_array($l)) { continue; }
        $key = golpos_sale_line_key($l, (int)$i);
        [$rQty, $rAmt] = golpos_refunded_on_line($prior, $key);
        $sold = max(1, (int)($l['qty'] ?? 1));
        $states[$key] = ['key' => $key, 'sku' => (string)($l['sku'] ?? ''),
            'name' => (string)($l['name'] ?? ''), 'manual' => !empty($l['manual']),
            'taxable' => ($l['taxable'] ?? true) !== false,
            'price' => (float)($l['price'] ?? 0), 'sold' => $sold,
            'refunded_qty' => $rQty, 'refunded_amt' => $rAmt,
            'remaining' => max(0, $sold - $rQty),
            'line_total' => (float)($l['line_total'] ?? 0)];
    }
    $want = [];
    if ($picks === null) {
        foreach ($states as $key => $s) { if ($s['remaining'] > 0) { $want[$key] = $s['remaining']; } }
        if (!$want) { return ['error' => 'That sale is already fully refunded — nothing left to give back.']; }
    } else {
        foreach ($picks as $p) {
            $key = (string)($p['key'] ?? '');
            $qty = (int)($p['qty'] ?? 0);
            if (!isset($states[$key])) { return ['error' => 'One of those lines is not on this sale.']; }
            if ($qty < 1) { return ['error' => 'A refund line needs at least one item.']; }
            if ($qty > $states[$key]['remaining']) {
                return ['error' => $states[$key]['name'] . ' — only ' . $states[$key]['remaining']
                    . ' left to refund on this sale (a line can never refund beyond what was sold, or twice).'];
            }
            $want[$key] = $qty;
        }
        if (!$want) { return ['error' => 'Pick at least one line to refund.']; }
    }
    $lines = []; $subtotal = 0.0; $taxableSub = 0.0; $saleTaxableSub = 0.0;
    foreach ($states as $s) { if ($s['taxable']) { $saleTaxableSub += $s['line_total']; } }
    $completes = true;
    foreach ($states as $s) {
        if (($want[$s['key']] ?? 0) < $s['remaining']) { $completes = false; }
    }
    foreach ($want as $key => $qty) {
        $s = $states[$key];
        // Picking everything left on a line returns the line's exact
        // remaining cents — partial picks price at the recorded unit.
        $amt = ($qty === $s['remaining'])
            ? round($s['line_total'] - $s['refunded_amt'], 2)
            : round($s['price'] * $qty, 2);
        $lines[] = ['key' => $key, 'sku' => $s['sku'], 'name' => $s['name'],
                    'qty' => $qty, 'price' => $s['price'], 'line_total' => $amt,
                    'manual' => $s['manual']];
        $subtotal += $amt;
        if ($s['taxable']) { $taxableSub += $amt; }
    }
    $subtotal = round($subtotal, 2);
    $saleTax = (float)($sale['tax'] ?? 0);
    $tax = 0.0;
    if ($saleTax > 0 && $saleTaxableSub > 0) {
        $tax = $completes ? round($saleTax - $priorTax, 2)
            : round($saleTax * ($taxableSub / $saleTaxableSub), 2);
    }
    $saleSurcharge = (float)($sale['surcharge'] ?? 0);
    $surcharge = 0.0;
    $saleBase = (float)($sale['subtotal'] ?? 0) + $saleTax;
    if ($saleSurcharge > 0 && $saleBase > 0) {
        $surcharge = $completes ? round($saleSurcharge - $priorSurcharge, 2)
            : round($saleSurcharge * (($subtotal + $tax) / $saleBase), 2);
    }
    $total = round($subtotal + $tax + $surcharge, 2);
    // Money returns to the ORIGINAL tenders, in their proportions,
    // with the rounding remainder settled on the largest part.
    $src = array_filter(golpos_sale_tenders($sale), static fn($v) => $v > 0);
    $srcSum = array_sum($src);
    $alloc = ['cash' => 0.0, 'card' => 0.0, 'credit' => 0.0];
    if ($srcSum > 0 && $total > 0) {
        $given = 0.0; $biggest = null;
        foreach ($src as $k => $v) {
            $alloc[$k] = round($total * ($v / $srcSum), 2);
            $given += $alloc[$k];
            if ($biggest === null || $v > $src[$biggest]) { $biggest = $k; }
        }
        $drift = round($total - $given, 2);
        if ($biggest !== null && $drift != 0.0) { $alloc[$biggest] = round($alloc[$biggest] + $drift, 2); }
    }
    $cardPart = (float)$alloc['card'];
    $batchEffect = 'none';
    if ($cardPart > 0) {
        $sealedIds = [];
        foreach (golpos_batches() as $b) {
            foreach ((array)($b['sale_ids'] ?? []) as $id) { $sealedIds[(string)$id] = true; }
        }
        $batchEffect = isset($sealedIds[(string)($sale['id'] ?? '')])
            ? 'negative_in_current' : 'removed_from_open';
    }
    return ['lines' => $lines, 'subtotal' => $subtotal, 'tax' => $tax,
            'surcharge' => $surcharge, 'total' => $total, 'tenders' => $alloc,
            'batch_effect' => $batchEffect, 'completes' => $completes,
            'states' => array_values($states)];
}

// Returned goods go back on the shelf — the count changes because the
// goods physically returned (unlike a What's Out report). ONE path:
// standalone increments the live catalog exactly where rung sales
// decrement it; link mode moves MR.STORE stock up through
// mrstore_adjust_stock — the same bridge function a receive executes
// — and logs the movement as a refund restock. Manual lines carry no
// product and never touch stock.
function golpos_restock_lines(array $lines): array {
    $notes = [];
    if (golpos_link_on()) {
        foreach ($lines as $l) {
            if (!empty($l['manual']) || (string)($l['name'] ?? '') === '') { continue; }
            $res = mrstore_adjust_stock((string)($l['mr_name'] ?? $l['name']), (float)($l['qty'] ?? 0));
            if ($res !== null) {
                $notes[] = $res;
                golpos_link_log(['kind' => 'refund_restock', 'name' => $res['name'],
                                 'qty' => (float)($l['qty'] ?? 0),
                                 'before' => $res['before'], 'after' => $res['after']]);
            }
        }
        return $notes;
    }
    $cat = golpos_catalog();
    $idx = [];
    foreach ($cat['products'] as $i => $p) { $idx[(string)($p['sku'] ?? '')] = $i; }
    $moved = false;
    foreach ($lines as $l) {
        $sku = (string)($l['sku'] ?? '');
        if ($sku === '' || !isset($idx[$sku])) { continue; }
        $i = $idx[$sku];
        if (!isset($cat['products'][$i]['stock']) || !is_numeric($cat['products'][$i]['stock'])) { continue; }
        $before = $cat['products'][$i]['stock'] + 0;
        $cat['products'][$i]['stock'] = $before + max(1, (int)($l['qty'] ?? 1));
        $notes[] = ['name' => (string)($cat['products'][$i]['name'] ?? ''),
                    'before' => $before, 'after' => $cat['products'][$i]['stock']];
        $moved = true;
    }
    if ($moved) { golpos_write('catalog.live.json', $cat); }
    return $notes;
}

// Execute a refund ONCE: the quote is recomputed at execution (a card
// staged earlier can never spend stale state), the record lands, the
// goods restock, and any credit portion returns to the customer's
// balance as a restore entry keyed by this refund's id.
function golpos_execute_refund(array $sale, ?array $picks, string $byName, string $byEmail): array {
    if (empty(golpos_settings()['refunds_allowed'])) {
        return ['error' => 'Refunds are off for this store — turn them on in Gol chat (a settings card) first.'];
    }
    $q = golpos_refund_quote($sale, $picks);
    if (isset($q['error'])) { return $q; }
    $rec = ['id' => 'ref_' . substr(sha1(uniqid('', true)), 0, 10),
            'sale_id' => (string)($sale['id'] ?? ''), 'at' => date('c'),
            'lines' => $q['lines'], 'subtotal' => $q['subtotal'], 'tax' => $q['tax'],
            'surcharge' => $q['surcharge'], 'total' => $q['total'], 'tenders' => $q['tenders'],
            'batch_effect' => $q['batch_effect'],
            'by' => $byName, 'by_email' => $byEmail];
    if ((float)($q['tenders']['credit'] ?? 0) > 0 && !empty($sale['credit_phone'])) {
        $rec['credit_phone'] = (string)$sale['credit_phone'];
    }
    $all = golpos_refunds();
    $all[] = $rec;
    golpos_write('refunds.json', $all);
    $rec['restocked'] = golpos_restock_lines($q['lines']);
    if (isset($rec['credit_phone'])) {
        golpos_credit_add($rec['credit_phone'], (float)$q['tenders']['credit'], 'restore',
            ['refund_id' => $rec['id'], 'sale_id' => $rec['sale_id']]);
        $rec['credit_restored'] = (float)$q['tenders']['credit'];
    }
    return ['refund' => $rec];
}

// --- People & access (owner directive, Oct 28) -------------------------------
// Two roles live in data/people.json: the ADMIN (one PIN hash — the PIN is
// the key to every admin-domain answer in the Gol chat) and EMPLOYEES, who
// are invited by email, set their own PIN from a one-time link, and hold the
// cashier domain only. PINs are never stored in plain text and never leave
// the server: password_hash / password_verify throughout.
function golpos_people(): array {
    $d = golpos_read('people.json', null);
    if (!is_array($d)) { return ['admin' => null, 'employees' => []]; }
    if (!array_key_exists('admin', $d)) { $d['admin'] = null; }
    if (!isset($d['employees']) || !is_array($d['employees'])) { $d['employees'] = []; }
    return $d;
}

function golpos_save_people(array $d): void {
    golpos_write('people.json', $d);
}

function golpos_admin_pin_hash(): ?string {
    $h = golpos_people()['admin']['pin_hash'] ?? null;
    return is_string($h) && $h !== '' ? $h : null;
}

function golpos_set_admin_pin(string $pinHash): void {
    $d = golpos_people();
    $d['admin'] = ['pin_hash' => $pinHash, 'set_at' => date('c')];
    golpos_save_people($d);
}

function golpos_norm_email(string $email): string {
    return mb_strtolower(trim($email));
}

// A PIN as typed at a prompt: Devanagari digits normalized, and valid only
// as exactly 4–6 digits. Returns the normalized digits or null.
function golpos_norm_pin(string $pin): ?string {
    $d = strtr(trim($pin), ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
                            '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    return preg_match('/^\d{4,6}$/', $d) ? $d : null;
}

function golpos_employee(string $email): ?array {
    $key = golpos_norm_email($email);
    foreach (golpos_people()['employees'] as $e) {
        if (($e['email'] ?? '') === $key) { return $e; }
    }
    return null;
}

// The one-time invite link carries the raw token; the store keeps only its
// SHA-256, so a leaked data file never hands out a working invite.
function golpos_employee_by_invite_token(string $token): ?array {
    if ($token === '') { return null; }
    $hash = hash('sha256', $token);
    foreach (golpos_people()['employees'] as $e) {
        $h = (string)($e['invite_token_hash'] ?? '');
        if ($h !== '' && hash_equals($h, $hash)) { return $e; }
    }
    return null;
}

// After the invite, the employee's device holds a device token (again only
// its SHA-256 is stored). Presenting it with chat calls identifies the
// session as that employee — cashier domain; it never elevates to admin.
function golpos_employee_by_device(string $email, string $token): ?array {
    if ($token === '') { return null; }
    $e = golpos_employee($email);
    if ($e === null || ($e['status'] ?? '') !== 'active') { return null; }
    $h = (string)($e['device_token_hash'] ?? '');
    return ($h !== '' && hash_equals($h, hash('sha256', $token))) ? $e : null;
}

function golpos_save_employee(array $emp): void {
    $d = golpos_people();
    foreach ($d['employees'] as $i => $e) {
        if (($e['email'] ?? '') === ($emp['email'] ?? '')) {
            $d['employees'][$i] = $emp;
            golpos_save_people($d);
            golpos_presence_ensure($emp);
            return;
        }
    }
    $d['employees'][] = $emp;
    golpos_save_people($d);
    golpos_presence_ensure($emp);
}

function golpos_remove_employee(string $email): bool {
    $key = golpos_norm_email($email);
    $d = golpos_people();
    $before = count($d['employees']);
    $d['employees'] = array_values(array_filter(
        $d['employees'], static fn($e) => ($e['email'] ?? '') !== $key));
    if (count($d['employees']) === $before) { return false; }
    golpos_save_people($d);
    return true;
}

// --- Chat sessions -----------------------------------------------------------
// The register client holds a session id (localStorage) and sends it with
// every chat call. Elevation, the PIN-flow stage, failed attempts and the
// intent waiting behind a PIN demand all live here, server-side, per
// session — the browser never decides who is admin.
function golpos_session(string $id): array {
    $all = golpos_read('sessions.json', []);
    $s = (is_array($all) && isset($all[$id]) && is_array($all[$id])) ? $all[$id] : [];
    return array_merge([
        'admin' => false, 'pin_stage' => null, 'pin_tmp' => null,
        'attempts' => 0, 'locked_until' => 0, 'pending' => null, 'employee' => null,
        'bill_await' => null, 'price_await' => null,
        // The morning lock (employee lane, Oct 28): a session starts
        // LOCKED and a roster PIN is what opens it. 'unlocked_by' is the
        // identity the session carries — an employee email, or 'admin'.
        'locked' => true, 'unlocked_by' => null, 'unlocked_name' => '',
        'unlocked_role' => null,
    ], $s);
}

function golpos_save_session(string $id, array $s): void {
    $all = golpos_read('sessions.json', []);
    if (!is_array($all)) { $all = []; }
    $all[$id] = $s;
    golpos_write('sessions.json', $all);
}

// --- Card batches (owner directive, Oct 28) ----------------------------------
// A batch close seals the card sales rung since the last batch into ONE
// numbered record (data/batches.json) — the POS twin of the card terminal's
// batch-out. Cash never batches. A sale belongs to a batch when its id is in
// the batch's sale_ids, so "unbatched" is always computed, never flagged by
// hand, and a segment can never be sealed twice.
function golpos_batches(): array {
    $b = golpos_read('batches.json', []);
    return is_array($b) ? $b : [];
}

// The card money one segment row contributes to a batch. A sale
// contributes its card tender MINUS the card portions of refunds that
// removed money from the open segment (batch_effect removed_from_open);
// a refund pseudo-row (a sealed sale's refund, riding as a negative
// entry in the current segment) contributes its negative total as-is.
function golpos_batch_amount(array $row): float {
    if (!empty($row['refund'])) { return round((float)($row['total'] ?? 0), 2); }
    $due = golpos_sale_tenders($row)['card'];
    foreach (golpos_refunds_for_sale((string)($row['id'] ?? '')) as $r) {
        if (($r['batch_effect'] ?? '') === 'removed_from_open') {
            $due -= (float)($r['tenders']['card'] ?? 0);
        }
    }
    return round($due, 2);
}

function golpos_unbatched_card_sales(?string $ymd = null): array {
    $sealed = [];
    foreach (golpos_batches() as $b) {
        foreach (($b['sale_ids'] ?? []) as $id) { $sealed[(string)$id] = true; }
    }
    $out = [];
    foreach (golpos_sales() as $s) {
        if (isset($sealed[(string)($s['id'] ?? '')])) { continue; }
        if (golpos_batch_amount($s) <= 0.004) { continue; } // no card money owed
        if ($ymd !== null && substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $out[] = $s;
    }
    // Negative entries: refunds of sales already inside a SEALED batch
    // ride the current open segment as negative rows (a sealed batch is
    // never edited) until a seal takes them in by id, like a sale.
    foreach (golpos_refunds() as $r) {
        if (($r['batch_effect'] ?? '') !== 'negative_in_current') { continue; }
        if (isset($sealed[(string)($r['id'] ?? '')])) { continue; }
        if ($ymd !== null && substr((string)($r['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $cardPart = round((float)($r['tenders']['card'] ?? 0), 2);
        if ($cardPart <= 0) { continue; }
        $out[] = ['id' => (string)$r['id'], 'at' => (string)($r['at'] ?? ''),
                  'tender' => 'card', 'total' => -$cardPart, 'refund' => true,
                  'sale_id' => (string)($r['sale_id'] ?? ''), 'lines' => (array)($r['lines'] ?? [])];
    }
    return $out;
}

function golpos_seal_batch(array $sales, string $closedBy, ?string $closeTime = null): array {
    $batches = golpos_batches();
    $total = 0.0;
    foreach ($sales as $s) { $total += golpos_batch_amount($s); }
    $rec = [
        'n' => count($batches) + 1,
        'date' => date('Y-m-d'),
        'sealed_at' => date('c'),
        'closed_by' => $closedBy,
        'close_time' => $closeTime,
        'count' => count($sales),
        'total' => round($total, 2),
        'from' => $sales ? (string)($sales[0]['at'] ?? '') : '',
        'to' => $sales ? (string)($sales[count($sales) - 1]['at'] ?? '') : '',
        'sale_ids' => array_map(static fn($s) => (string)($s['id'] ?? ''), $sales),
    ];
    $batches[] = $rec;
    golpos_write('batches.json', $batches);
    return $rec;
}

function golpos_batches_on(string $ymd): array {
    return array_values(array_filter(
        golpos_batches(), static fn($b) => ($b['date'] ?? '') === $ymd));
}

// AUTO batch mode, evaluated lazily: the POS is request-driven PHP with no
// daemon, so every API/chat call first asks this — if the store is in auto
// mode, today's close time has passed, and an unsealed card segment exists
// for today, the segment seals itself as the next batch (closed_by "auto",
// the close time recorded). At most ONE auto batch per day; a manual close
// earlier that day leaves nothing for the pass; cash sales never batch.
function golpos_batch_auto_sweep(): ?array {
    $st = golpos_settings();
    if (($st['batch_mode'] ?? 'manual') !== 'auto') { return null; }
    $today = date('Y-m-d');
    foreach (golpos_batches() as $b) {
        if (($b['date'] ?? '') === $today && ($b['closed_by'] ?? '') === 'auto') { return null; }
    }
    $close = (string)($st['store_close'] ?? '21:00');
    // Past means past: compare against today's close as a full timestamp,
    // so 9:00:30 PM is already past a 9:00 PM close (a minute-granularity
    // string compare would hold the seal until 9:01).
    $closeTs = strtotime($today . ' ' . $close);
    if ($closeTs === false || time() <= $closeTs) { return null; }
    $seg = golpos_unbatched_card_sales($today);
    if (!$seg) { return null; }
    return golpos_seal_batch($seg, 'auto', $close);
}

// --- Vendors + register order prep (owner directive, Oct 28) -----------------
// The vendor pack lives per store in data/vendors.json: one record per
// vendor — name, aliases, rep visiting day(s) (3-letter weekday keys
// mon..sun), delivery day, cadence (weekly / biweekly + a due-week anchor
// date), account / phone where known, the last bill, and the typical
// lines from bill history (name, usual qty, last cost, par). The pack is
// taught by Gol chat ("lipton ko rep sombar aaucha") through a staged
// card; this layer only stores it. Prepared orders live in
// data/orders.json — one record per vendor per rep day, moving
// prepared -> confirmed -> given. An order record is the register's own
// sheet: NOTHING here submits anything to a vendor, ever.
function golpos_vendors(): array {
    $v = golpos_read('vendors.json', []);
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

function golpos_save_vendors(array $vendors): void {
    golpos_write('vendors.json', array_values($vendors));
}

function golpos_vendor_by_id(string $id): ?array {
    foreach (golpos_vendors() as $v) {
        if ((string)($v['id'] ?? '') === $id) { return $v; }
    }
    return null;
}

// A vendor is due on a date when the weekday is one of its rep days and,
// for a biweekly cadence, the week parity matches the anchor (a known due
// date). No anchor yet -> due every rep day until one is taught.
function golpos_vendor_due_on(array $v, string $ymd): bool {
    $dow = strtolower(date('D', strtotime($ymd)));
    if (!in_array($dow, (array)($v['rep_days'] ?? []), true)) { return false; }
    if ((string)($v['cadence'] ?? 'weekly') !== 'biweekly') { return true; }
    $anchor = (string)($v['anchor'] ?? '');
    if ($anchor === '' || strtotime($anchor) === false) { return true; }
    $weeks = (int)floor((strtotime($ymd) - strtotime($anchor)) / 604800);
    return (($weeks % 2) + 2) % 2 === 0;
}

function golpos_vendors_due(string $ymd): array {
    return array_values(array_filter(golpos_vendors(),
        static fn($v) => golpos_vendor_due_on($v, $ymd)));
}

function golpos_orders(): array {
    $o = golpos_read('orders.json', []);
    return is_array($o) ? array_values(array_filter($o, 'is_array')) : [];
}

// Upsert one order record by id (a re-stage refreshes the same record's
// lines; a confirm/given moves its status). Returns the stored record.
function golpos_order_put(array $rec): array {
    $all = golpos_orders();
    foreach ($all as $i => $o) {
        if ((string)($o['id'] ?? '') === (string)($rec['id'] ?? '')) {
            $all[$i] = $rec;
            golpos_write('orders.json', $all);
            return $rec;
        }
    }
    $all[] = $rec;
    golpos_write('orders.json', $all);
    return $rec;
}

// The latest order record for one vendor on one date, if any.
function golpos_order_for(string $vendorId, string $ymd): ?array {
    $found = null;
    foreach (golpos_orders() as $o) {
        if ((string)($o['vendor_id'] ?? '') === $vendorId && (string)($o['date'] ?? '') === $ymd) {
            $found = $o;
        }
    }
    return $found;
}

// --- Scan-by-phone pairing + the session inbox (owner directive, Oct 28) -----
// A register without a camera borrows the owner's phone: the register
// mints a short-lived pairing token bound to ITS chat session, the phone
// opens web/scan.html?t=<token> and uploads through the ordinary photo
// lane (photo.php) with the token riding along. photo.php resolves the
// token back to the register's session — so a bill's price wait waits in
// the register's own chat — and ALSO drops the lane's reply (and any
// staged card) into that session's inbox here. The register polls the
// inbox (api.php ?inbox) and renders what arrived into its chat. Tokens
// are random, bound to one session, and die after ~10 minutes; expired
// or unknown tokens refuse plainly at the photo lane's door.
function golpos_pair_prune(array $tokens): array {
    $now = time();
    return array_filter($tokens, static fn($t) => is_array($t) && (int)($t['expires'] ?? 0) > $now);
}

// Mint a token for a session. Returns ['token'=>…, 'code'=>…, 'expires'=>…]
// — the code is the short human form of the same secret (the phone page
// accepts either the ?t= link or the typed code).
function golpos_pair_mint(string $sessionId): array {
    $tokens = golpos_pair_prune(golpos_read('pair_tokens.json', []));
    if (!is_array($tokens)) { $tokens = []; }
    do {
        $token = bin2hex(random_bytes(12));
        $code = strtoupper(substr($token, 0, 6));
        $clash = false;
        foreach ($tokens as $t) { if (($t['code'] ?? '') === $code) { $clash = true; break; } }
    } while (isset($tokens[$token]) || $clash);
    $rec = ['session' => $sessionId, 'code' => $code, 'expires' => time() + 600];
    $tokens[$token] = $rec;
    golpos_write('pair_tokens.json', $tokens);
    return ['token' => $token, 'code' => $code, 'expires' => $rec['expires']];
}

// A live token's record, or null when unknown/expired (expired tokens are
// pruned on the way through).
function golpos_pair_lookup(string $token): ?array {
    if ($token === '') { return null; }
    $all = golpos_read('pair_tokens.json', []);
    if (!is_array($all)) { return null; }
    $live = golpos_pair_prune($all);
    if (count($live) !== count($all)) { golpos_write('pair_tokens.json', $live); }
    $rec = $live[$token] ?? null;
    return is_array($rec) ? $rec : null;
}

// The typed-code door: the phone page trades a live 6-char code for its
// token. Returns the token or null.
function golpos_pair_resolve_code(string $code): ?string {
    $code = strtoupper(trim($code));
    if ($code === '') { return null; }
    $all = golpos_read('pair_tokens.json', []);
    if (!is_array($all)) { return null; }
    foreach (golpos_pair_prune($all) as $token => $rec) {
        if (($rec['code'] ?? '') === $code) { return (string)$token; }
    }
    return null;
}

// One session's waiting photo-lane results. photo.php pushes; the
// register's poll takes (reads + clears), so each result renders once.
function golpos_inbox_push(string $sessionId, array $entry): void {
    if ($sessionId === '') { return; }
    $all = golpos_read('inbox.json', []);
    if (!is_array($all)) { $all = []; }
    $list = (isset($all[$sessionId]) && is_array($all[$sessionId])) ? $all[$sessionId] : [];
    $entry['at'] = date('c');
    $list[] = $entry;
    if (count($list) > 20) { $list = array_slice($list, -20); }
    $all[$sessionId] = $list;
    golpos_write('inbox.json', $all);
}

function golpos_inbox_take(string $sessionId): array {
    $all = golpos_read('inbox.json', []);
    if (!is_array($all) || empty($all[$sessionId]) || !is_array($all[$sessionId])) { return []; }
    $list = $all[$sessionId];
    unset($all[$sessionId]);
    golpos_write('inbox.json', $all);
    return $list;
}

// --- Team lane: presence, tasks, notes, unlocks, What's Out ------------------
// The employee lane (owner directive, Oct 28). One Gol, every screen,
// applied to people: the owner assigns work in chat, Gol itself
// originates work from the store's real state, the employee answers
// back through Gol, and the boss's view (Team card + presence reads)
// is composed from these same records — never from a second copy.
// Stores: data/presence.json (one record per roster identity, created
// the moment the roster gains the person), data/tasks.json,
// data/team_notes.json, data/unlocks.json, data/whatsout.json
// (standalone What's Out reports; link mode reports ride the MR.STORE
// Floor team-chat bridge in mrstore.php instead).

// The ONE mail path for team mail (invites ride it too — see brain.php
// golpos_send_invite). A mail sink is a TEST door only: when the
// instance's data dir carries mail_sink.json, mail is recorded to
// mail_sink_log.json and nothing leaves the box. Live stages carry no
// sink file, so live mail is real mail.
function golpos_mail(string $to, string $subject, string $body): bool {
    if (is_file(golpos_data_dir() . '/mail_sink.json')) {
        $log = golpos_read('mail_sink_log.json', []);
        if (!is_array($log)) { $log = []; }
        $log[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'at' => date('c')];
        golpos_write('mail_sink_log.json', $log);
        return true;
    }
    $headers = "From: Gol POS <no-reply@"
        . preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))
        . ">\r\nContent-Type: text/plain; charset=utf-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

// The register's own web root, derived from the request the way the
// invite link is — the address a task email sends the employee back to.
function golpos_register_link(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/server/brain.php'))), '/');
    return $scheme . '://' . $host . $dir . '/web/';
}

// How a roster record is NAMED in speech: its roster name when it has
// one; otherwise the name its email carries ("ashish.adhikari323" ->
// "Ashish Adhikari" — the digits are the mailbox's, not the person's).
function golpos_display_name(array $e): string {
    $name = trim((string)($e['name'] ?? ''));
    if ($name !== '') { return $name; }
    $local = explode('@', (string)($e['email'] ?? ''))[0];
    $parts = [];
    foreach (preg_split('/[._\-]+/u', $local) ?: [] as $w) {
        if ($w === '' || ctype_digit($w)) { continue; }
        $parts[] = mb_convert_case(mb_strtolower($w), MB_CASE_TITLE, 'UTF-8');
    }
    return $parts ? implode(' ', $parts) : (string)($e['email'] ?? '');
}

// Resolve a spoken name to ONE roster record: exact email, roster name,
// an alias, or the name the email itself carries (full or first name).
// Roster names/aliases only — a stranger resolves to nothing, and a
// first name shared by two people resolves to nothing rather than a
// guess (the standing roster-refusal rule). PIN state is irrelevant:
// an invited employee who has not set a PIN yet is still resolvable,
// so work assigned before their first unlock simply waits for them.
function golpos_employee_resolve(string $who): ?array {
    $w = mb_strtolower(trim($who));
    if ($w === '') { return null; }
    $emps = golpos_people()['employees'];
    foreach ($emps as $e) {
        if (mb_strtolower((string)($e['email'] ?? '')) === $w) { return $e; }
    }
    foreach ($emps as $e) {
        if (mb_strtolower(trim((string)($e['name'] ?? ''))) === $w
            && trim((string)($e['name'] ?? '')) !== '') { return $e; }
        foreach ((array)($e['aliases'] ?? []) as $a) {
            if (mb_strtolower(trim((string)$a)) === $w) { return $e; }
        }
    }
    $full = []; $first = [];
    foreach ($emps as $e) {
        $dn = mb_strtolower(golpos_display_name($e));
        if ($dn === $w) { $full[] = $e; }
        $tok = explode(' ', $dn)[0] ?? '';
        if ($tok !== '' && $tok === $w) { $first[] = $e; }
    }
    if (count($full) === 1) { return $full[0]; }
    if (count($first) === 1) { return $first[0]; }
    return null;
}

// --- presence: ONE identity across register, roster and chat ------------
// Gol is a manager (owner rule, Oct 28, 12:53): the moment a person is
// ADDED to the roster their presence exists — no separate setup step.
// The record is initialized by golpos_save_employee (every roster
// write rides it), carries an empty task list and a job-pack stub for
// their role, and accumulates only facts: first/last unlock, the
// photo-stamp state of the last unlock. Presence reads answer from
// this record + tasks + notes, honestly, from day one. Identity key:
// the email; a second roster write under a name that already has a
// presence never mints a second presence for that name.
function golpos_presence_all(): array {
    $p = golpos_read('presence.json', []);
    return is_array($p) ? $p : [];
}

// --- the duty checklist + role presets (owner, Oct 28, 13:01) --------------------
// The checklist is the truth; a role is only a PRESET that pre-checks
// a bundle on it. The table lives as DATA in the lane's records
// (data/personas.json, seeded from these defaults on first use) so the
// owner can correct the draft without a code change. A persona is
// COPIED from the owner's final checked set at add time — after that
// the pack is HERS: later template edits never rewrite a grown pack.
function golpos_persona_defaults(): array {
    $checklist = [
        'Counter & Register' => ['register kholne/banda garne', 'customer sewa', 'lottery bikri',
                                 'age-restricted check (vape/liquor)', 'refund ma owner lai bolauune'],
        'Money & Close' => ['day close garne', 'Z report ko photo pathaune', 'cash ginti (drawer)',
                            'card batch seal garne'],
        'Bills & Deliveries' => ['delivery lina', 'bill sanga saman check garne',
                                 'bill ko photo pathaune/halnee', 'vendor lai sodhne kura note garne'],
        'Stock & Shelf' => ["What's Out report garne", 'stock ginti garne',
                            'shelf bharee milaaune (facing)', 'cooler bharnee', 'expiry/miti check garne'],
        'Store Care' => ['pasal safa garne', 'cooler/fridge ko temperature herne', 'fohor bahira rakhne'],
        'Notes & Messages' => ['boss lai note pathaune', 'customer le sodheko kura record garne'],
    ];
    $all = [];
    foreach ($checklist as $items) { $all = array_merge($all, $items); }
    $presets = [
        'cashier' => array_merge($checklist['Counter & Register'], $checklist['Notes & Messages'],
                                 ["What's Out report garne"]),
        'closer' => array_merge($checklist['Money & Close'],
                                ['register kholne/banda garne', 'bill ko photo pathaune/halnee',
                                 'boss lai note pathaune']),
        'stocker' => array_merge($checklist['Stock & Shelf'],
                                 ['delivery lina', 'bill sanga saman check garne']),
        'manager' => $all,
    ];
    return ['checklist' => $checklist, 'presets' => $presets];
}

function golpos_persona_data(): array {
    $d = golpos_read('personas.json', null);
    if (!is_array($d) || !isset($d['checklist'], $d['presets'])) {
        $d = golpos_persona_defaults();
        golpos_write('personas.json', $d);
    }
    return $d;
}

// The duties a preset pre-checks, in checklist order.
function golpos_preset_duties(string $role): array {
    $data = golpos_persona_data();
    $want = $data['presets'][strtolower(trim($role))] ?? $data['presets']['cashier'] ?? [];
    $wantKeys = array_map('golpos_bill_name_key', $want);
    $out = [];
    foreach ($data['checklist'] as $items) {
        foreach ($items as $duty) {
            if (in_array(golpos_bill_name_key((string)$duty), $wantKeys, true)) { $out[] = (string)$duty; }
        }
    }
    return $out;
}

// The first roster member whose CHECKED duties include this duty —
// generated-task routing reads the checklist, never the preset name.
function golpos_roster_with_duty(string $duty): ?array {
    $key = golpos_bill_name_key($duty);
    foreach (golpos_people()['employees'] as $e) {
        if (($e['status'] ?? '') === 'removed') { continue; }
        $pres = golpos_presence_for($e);
        foreach ((array)($pres['job_pack']['items'] ?? []) as $have) {
            if (golpos_bill_name_key((string)$have) === $key) { return $e; }
        }
    }
    return null;
}

function golpos_presence_ensure(array $emp): ?array {
    $email = golpos_norm_email((string)($emp['email'] ?? ''));
    if ($email === '') { return null; }
    $all = golpos_presence_all();
    if (isset($all[$email]) && is_array($all[$email])) { return $all[$email]; }
    $name = golpos_display_name($emp);
    $key = golpos_bill_name_key($name);
    if ($key !== '') {
        foreach ($all as $rec) {
            if (is_array($rec) && golpos_bill_name_key((string)($rec['name'] ?? '')) === $key) {
                return $rec; // the name already has a presence — never two
            }
        }
    }
    $role = strtolower(trim((string)($emp['role'] ?? '')));
    if (!isset(golpos_persona_data()['presets'][$role])) { $role = 'cashier'; }
    $duties = isset($emp['duties']) && is_array($emp['duties']) && $emp['duties']
        ? array_values(array_map('strval', $emp['duties']))
        : golpos_preset_duties($role);
    $rec = ['email' => $email, 'name' => $name, 'role' => $role,
            'added_at' => (string)($emp['created'] ?? date('c')),
            'job_pack' => ['role' => $role, 'items' => $duties],
            'first_unlock_at' => null, 'last_unlock_at' => null,
            'last_unlock_photo' => null];
    $all[$email] = $rec;
    golpos_write('presence.json', $all);
    return $rec;
}

// The presence record for one roster member — ensured on read too, so
// a person added before this lane existed still answers honestly.
function golpos_presence_for(array $emp): array {
    $rec = golpos_presence_ensure($emp);
    if ($rec !== null) { return $rec; }
    return ['email' => (string)($emp['email'] ?? ''), 'name' => golpos_display_name($emp),
            'role' => (string)($emp['role'] ?? 'employee'), 'added_at' => (string)($emp['created'] ?? ''),
            'job_pack' => ['role' => (string)($emp['role'] ?? 'employee'), 'items' => []],
            'first_unlock_at' => null, 'last_unlock_at' => null, 'last_unlock_photo' => null];
}

// Stamp an unlock onto the person's presence (first + last + photo).
function golpos_presence_touch_unlock(array $emp, bool $photo): void {
    $email = golpos_norm_email((string)($emp['email'] ?? ''));
    if ($email === '') { return; }
    $rec = golpos_presence_for($emp);
    $all = golpos_presence_all();
    $key = isset($all[$email]) ? $email : (string)($rec['email'] ?? $email);
    if (!isset($all[$key]) || !is_array($all[$key])) { $all[$key] = $rec; }
    if (empty($all[$key]['first_unlock_at'])) { $all[$key]['first_unlock_at'] = date('c'); }
    $all[$key]['last_unlock_at'] = date('c');
    $all[$key]['last_unlock_photo'] = $photo;
    golpos_write('presence.json', $all);
}

// --- the register lock ------------------------------------------------------
// A roster PIN exists when the admin has one or any employee has set
// theirs. With NO roster PIN anywhere the register is in setup mode
// and never locks — a virgin till must not brick itself.
function golpos_roster_has_pin(): bool {
    if (golpos_admin_pin_hash() !== null) { return true; }
    foreach (golpos_people()['employees'] as $e) {
        if (!empty($e['pin_hash'])) { return true; }
    }
    return false;
}

function golpos_lock_state(array $session): bool {
    return golpos_roster_has_pin() && !empty($session['locked']);
}

// Return a session to the PIN-please state (lock word, day close).
function golpos_lock_session(string $sid): void {
    $s = golpos_session($sid);
    $s['locked'] = true;
    $s['admin'] = false;
    $s['unlocked_by'] = null;
    $s['unlocked_name'] = '';
    $s['unlocked_role'] = null;
    $s['pin_stage'] = null;
    $s['pin_tmp'] = null;
    $s['pending'] = null;
    golpos_save_session($sid, $s);
}

// --- unlock feed --------------------------------------------------------------
// Every successful unlock and every 3-strike lockout is an EVENT with a
// name and a time; the register camera's frame for the event lands in
// data/frames/unlock_<id>.<ext> through frame.php and flips 'photo' to
// true. Photos only — no biometric template is created or stored
// anywhere in this system (owner rule, Oct 28, 12:40).
function golpos_unlocks(): array {
    $u = golpos_read('unlocks.json', []);
    return is_array($u) ? array_values(array_filter($u, 'is_array')) : [];
}

function golpos_unlock_add(array $rec): array {
    $rec['id'] = 'unl_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['at'] = date('c');
    if (!isset($rec['photo'])) { $rec['photo'] = false; }
    $all = golpos_unlocks();
    $all[] = $rec;
    if (count($all) > 200) { $all = array_slice($all, -200); }
    golpos_write('unlocks.json', $all);
    return $rec;
}

function golpos_unlock_by_id(string $id): ?array {
    foreach (golpos_unlocks() as $u) {
        if ((string)($u['id'] ?? '') === $id) { return $u; }
    }
    return null;
}

function golpos_unlock_mark_photo(string $id): bool {
    $all = golpos_unlocks();
    foreach ($all as $i => $u) {
        if ((string)($u['id'] ?? '') === $id) {
            $all[$i]['photo'] = true;
            golpos_write('unlocks.json', $all);
            return true;
        }
    }
    return false;
}

function golpos_unlock_file(string $id): ?array {
    if (!preg_match('/^unl_[a-z0-9]+$/', $id)) { return null; }
    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    foreach ($mimes as $ext => $mime) {
        $p = golpos_frames_dir() . '/unlock_' . $id . '.' . $ext;
        if (is_file($p)) { return [$p, $mime]; }
    }
    return null;
}

// The latest unlock event for one email (presence reads), or null.
function golpos_unlock_latest_for(string $email): ?array {
    $found = null;
    foreach (golpos_unlocks() as $u) {
        if (($u['kind'] ?? '') === 'unlock' && ($u['email'] ?? '') === $email) { $found = $u; }
    }
    return $found;
}

// --- tasks --------------------------------------------------------------------
// One record per assignment: {id, employee_email|null, employee_name,
// role|null, items:[{text,done,done_at}], source: owner|gol, kind:
// manual|report_due|bill_waiting|whatsout_aging, subject, reason,
// created, due, status: open|partial|done|superseded}. A null
// employee_email is a ROLE task (the data could not name a person —
// the task text says so honestly) and shows to every employee.
function golpos_tasks(): array {
    $t = golpos_read('tasks.json', []);
    return is_array($t) ? array_values(array_filter($t, 'is_array')) : [];
}

function golpos_save_tasks(array $tasks): void {
    golpos_write('tasks.json', array_values($tasks));
}

function golpos_task_status(array $task): string {
    if (($task['status'] ?? '') === 'superseded') { return 'superseded'; }
    $items = (array)($task['items'] ?? []);
    if (!$items) { return (string)($task['status'] ?? 'open'); }
    $done = 0;
    foreach ($items as $it) { if (!empty($it['done'])) { $done++; } }
    if ($done === count($items)) { return 'done'; }
    return $done > 0 ? 'partial' : 'open';
}

function golpos_task_add(array $rec): array {
    $rec['id'] = 'task_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['created'] = date('c');
    if (!isset($rec['due'])) { $rec['due'] = null; }
    $rec['status'] = golpos_task_status($rec);
    $all = golpos_tasks();
    $all[] = $rec;
    golpos_save_tasks($all);
    return $rec;
}

function golpos_task_put(array $rec): void {
    $all = golpos_tasks();
    foreach ($all as $i => $t) {
        if ((string)($t['id'] ?? '') === (string)($rec['id'] ?? '')) {
            $rec['status'] = golpos_task_status($rec);
            $all[$i] = $rec;
            golpos_save_tasks($all);
            return;
        }
    }
}

// The tasks one person sees: their own, plus open role tasks.
function golpos_tasks_for(string $email): array {
    $out = [];
    foreach (golpos_tasks() as $t) {
        if (($t['status'] ?? '') === 'superseded') { continue; }
        if (($t['employee_email'] ?? null) === $email || empty($t['employee_email'])) { $out[] = $t; }
    }
    return $out;
}

// Execute one confirmed task_assign card: record the assignment, then
// email the numbered list through the ONE mail path. This is the exact
// function the chat confirm runs — the record lands even when the mail
// cannot, and the caller reports both halves truthfully.
function golpos_execute_task_assign(array $card, string $shop): array {
    $items = [];
    foreach ((array)($card['items'] ?? []) as $txt) {
        $txt = trim((string)$txt);
        if ($txt !== '') { $items[] = ['text' => $txt, 'done' => false, 'done_at' => null]; }
    }
    $rec = golpos_task_add([
        'employee_email' => (string)($card['employee_email'] ?? ''),
        'employee_name' => (string)($card['employee_name'] ?? ''),
        'role' => null, 'items' => $items, 'source' => 'owner', 'kind' => 'manual',
        'subject' => '', 'reason' => '', 'due' => $card['due'] ?? null,
    ]);
    $email = (string)($card['employee_email'] ?? '');
    $sent = false;
    if ($email !== '') {
        $lines = [];
        foreach ($items as $i => $it) { $lines[] = ($i + 1) . '. ' . $it['text']; }
        $body = 'Hi ' . (string)($card['employee_name'] ?? '') . ",\n\n"
            . "Gol at {$shop} has work for you:\n\n" . implode("\n", $lines) . "\n";
        if (!empty($card['due'])) { $body .= "\nDue: " . date('g:i A, M j', strtotime((string)$card['due'])) . "\n"; }
        $body .= "\nOpen Gol at the register to see your tasks and mark them done: "
            . golpos_register_link() . "\n\n— Gol, your store brain";
        $sent = golpos_mail($email, "Your tasks at {$shop} — from Gol", $body);
    }
    return ['task' => $rec, 'sent' => $sent];
}

// --- team notes: the employee's voice back to the boss -------------------------
function golpos_team_notes(): array {
    $n = golpos_read('team_notes.json', []);
    return is_array($n) ? array_values(array_filter($n, 'is_array')) : [];
}

function golpos_team_note_add(array $rec): array {
    $rec['id'] = 'note_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['at'] = date('c');
    $rec['read'] = false;
    $all = golpos_team_notes();
    $all[] = $rec;
    golpos_write('team_notes.json', $all);
    return $rec;
}

function golpos_team_notes_mark_read(string $email): void {
    $all = golpos_team_notes();
    $changed = false;
    foreach ($all as $i => $n) {
        if (($n['from_email'] ?? '') === $email && empty($n['read'])) {
            $all[$i]['read'] = true; $changed = true;
        }
    }
    if ($changed) { golpos_write('team_notes.json', $all); }
}

// --- stock count reports (the What's Out discipline, applied to counts) --------
// A count filed from the register is an EVENT — item, counted qty, the
// system qty at that moment, the variance, by whom, when — kept in
// data/counts.json for the presence trail and the Owner panel. Filing
// a count NEVER edits the shelf stock: the variance waits for the
// owner's word, exactly as a What's Out report does.
function golpos_counts(): array {
    $c = golpos_read('counts.json', []);
    return is_array($c) ? array_values(array_filter($c, 'is_array')) : [];
}

function golpos_count_add(array $rec): array {
    $rec['id'] = 'cnt_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['at'] = date('c');
    $rec['status'] = 'filed';
    $all = golpos_counts();
    $all[] = $rec;
    golpos_write('counts.json', $all);
    return $rec;
}

// --- employee session turns (the presence trail's question record) --------------
// What an employee asked Gol in her own session, kept owner-side only:
// turn text + time + her identity, appended by the brain for EMPLOYEE
// sessions. PIN-shaped turns and PIN-flow turns are never recorded —
// the digits are not data. Capped so the file stays a trail, not an
// archive.
// Mark an employee's latest unlock as photo-stamped (frame.php calls
// this when the unlock frame lands).
function golpos_presence_photo_mark(string $email): void {
    $key = golpos_norm_email($email);
    if ($key === '') { return; }
    $all = golpos_presence_all();
    if (!isset($all[$key]) || !is_array($all[$key])) { return; }
    $all[$key]['last_unlock_photo'] = true;
    golpos_write('presence.json', $all);
}

function golpos_turn_log(string $sessionId, string $email, string $name, string $text): void {
    if ($email === '' || trim($text) === '') { return; }
    $all = golpos_read('turns.json', []);
    if (!is_array($all)) { $all = []; }
    $all[] = ['session' => $sessionId, 'email' => $email, 'name' => $name,
              'text' => mb_substr(trim($text), 0, 300), 'at' => date('c')];
    if (count($all) > 500) { $all = array_slice($all, -500); }
    golpos_write('turns.json', $all);
}

function golpos_turns(): array {
    $t = golpos_read('turns.json', []);
    return is_array($t) ? array_values(array_filter($t, 'is_array')) : [];
}

// --- the presence activity trail --------------------------------------------------
// Her whole day, read over the records this lane already keeps — no
// parallel log: tasks done (with times) + still open, notes sent,
// What's Out reports filed, counts filed, and the questions she asked
// Gol in her session (asked-and-answered: learning, never errors).
function golpos_presence_trail(string $email, string $ymd): array {
    $done = []; $open = [];
    foreach (golpos_tasks_for($email) as $t) {
        foreach ((array)($t['items'] ?? []) as $it) {
            if (!empty($it['done'])) {
                if (substr((string)($it['done_at'] ?? ''), 0, 10) === $ymd) {
                    $done[] = ['text' => (string)($it['text'] ?? ''), 'at' => (string)($it['done_at'] ?? '')];
                }
            } else {
                $open[] = ['text' => (string)($it['text'] ?? ''),
                           'source' => (string)($t['source'] ?? 'owner')];
            }
        }
    }
    $notes = [];
    foreach (golpos_team_notes() as $n) {
        if (($n['from_email'] ?? '') === $email && substr((string)($n['at'] ?? ''), 0, 10) === $ymd) {
            $notes[] = ['text' => (string)($n['text'] ?? ''), 'at' => (string)($n['at'] ?? '')];
        }
    }
    $outs = [];
    foreach (golpos_whatsout_open() as $w) {
        if (($w['by_email'] ?? '') === $email && substr((string)($w['at'] ?? ''), 0, 10) === $ymd) {
            $outs[] = ['name' => (string)($w['name'] ?? ''), 'at' => (string)($w['at'] ?? '')];
        }
    }
    $counts = [];
    foreach (golpos_counts() as $c) {
        if (($c['by_email'] ?? '') === $email && substr((string)($c['at'] ?? ''), 0, 10) === $ymd) {
            $counts[] = ['name' => (string)($c['name'] ?? ''), 'counted' => $c['counted'] ?? null,
                         'system' => $c['system'] ?? null, 'variance' => $c['variance'] ?? null,
                         'at' => (string)($c['at'] ?? '')];
        }
    }
    $questions = [];
    foreach (golpos_turns() as $q) {
        if (($q['email'] ?? '') !== $email || substr((string)($q['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $txt = trim((string)($q['text'] ?? ''));
        if ($txt === '' || in_array(mb_strtolower($txt), ['gol', 'गोल', 'cancel', 'ho', 'हो'], true)) { continue; }
        $questions[] = ['text' => $txt, 'at' => (string)($q['at'] ?? '')];
    }
    return ['done' => $done, 'open' => $open, 'notes' => $notes, 'outs' => $outs,
            'counts' => $counts, 'questions' => $questions];
}

// --- What's Out reports (standalone store) --------------------------------------
// A report is a SIGNAL with a name and a time — it never edits a stock
// count. Link mode writes the same report to the MR.STORE Floor board
// (mrstore_append_whats_out) and reads it back from there; standalone
// keeps its own record here, which the Owner panel and order prep read.
function golpos_whatsout(): array {
    $w = golpos_read('whatsout.json', []);
    return is_array($w) ? array_values(array_filter($w, 'is_array')) : [];
}

function golpos_whatsout_add(array $rec): array {
    $rec['id'] = 'wo_' . substr(sha1(uniqid('', true)), 0, 10);
    $rec['at'] = date('c');
    $rec['status'] = 'open';
    $all = golpos_whatsout();
    $all[] = $rec;
    golpos_write('whatsout.json', $all);
    return $rec;
}

// Open out-reports as order-prep seeds + aging-generator input, ONE
// shape for both modes: [{name, at, by_name, by_email}].
function golpos_whatsout_open(): array {
    if (golpos_link_on()) {
        return mrstore_whatsout_read();
    }
    $out = [];
    foreach (golpos_whatsout() as $w) {
        if (($w['status'] ?? 'open') !== 'open') { continue; }
        $out[] = ['name' => (string)($w['name'] ?? ''), 'at' => (string)($w['at'] ?? ''),
                  'by_name' => (string)($w['by_name'] ?? ''), 'by_email' => (string)($w['by_email'] ?? '')];
    }
    return $out;
}

// --- Gol-originated tasks (owner addition, Oct 28, 12:41) -----------------------
// MR.STORE is an inner intelligence: it watches the store's own state
// and hands the register's Gol the work it can see waiting. The
// generators below are DETERMINISTIC — every task names the real
// condition that created it, carries source "gol", dedupes to one open
// task per (kind, subject), and yields to an owner task on the same
// subject. A generated task is an instruction only; it never executes
// anything. Evaluated at the register's sync points: unlock, task
// view, owner inbox read.
function golpos_task_subject_key(string $s): string {
    return golpos_bill_name_key($s);
}

// Does an OPEN owner task already cover this generated subject?
function golpos_owner_covers(string $kind, string $subject): bool {
    $sk = golpos_task_subject_key($subject);
    foreach (golpos_tasks() as $t) {
        if (($t['source'] ?? '') !== 'owner' || golpos_task_status($t) === 'done'
            || ($t['status'] ?? '') === 'superseded') { continue; }
        foreach ((array)($t['items'] ?? []) as $it) {
            $txt = mb_strtolower((string)($it['text'] ?? ''));
            if ($kind === 'report_due' && preg_match('/(report|close|रिपोर्ट|बन्द)/iu', $txt)) { return true; }
            if ($kind === 'bill_waiting' && $sk !== ''
                && mb_strpos(golpos_bill_name_key($txt), $sk) !== false) { return true; }
            if ($kind === 'whatsout_aging' && $sk !== ''
                && mb_strpos(golpos_bill_name_key($txt), $sk) !== false) { return true; }
        }
    }
    return false;
}

// --- closure by evidence (owner definition, Oct 28, 13:06) -----------------------
// "Control yani — AI persona le kam dinchha, ani tyo kam garnu
// parcha." A task is a DUTY: it NEVER closes by time passing or by
// being shown in a greeting. It closes only by evidence — a generated
// task self-closes when its feed event lands (the close is recorded,
// the bill is entered, the count is filed), or when she completes its
// staged work; an owner-given task closes by her explicit done-mark
// or its linked work event. Until evidence, it stays open, resurfaces
// at her next greeting, and stands on the owner's trail WITH ITS AGE.
// And the fairness flip side: a task that was never in her list can
// never appear as "missed" anywhere — only given work counts.
function golpos_task_close_by_feed(array $t): array {
    $now = date('c');
    foreach ($t['items'] as $i => $it) {
        if (empty($it['done'])) {
            $t['items'][$i]['done'] = true;
            $t['items'][$i]['done_at'] = $now;
        }
    }
    $t['closed_by'] = 'feed';
    $t['closed_at'] = $now;
    golpos_task_put($t);
    return $t;
}

function golpos_tasks_reconcile(): void {
    $waiting = function_exists('mrstore_bill_vault_waiting') && golpos_link_on()
        ? mrstore_bill_vault_waiting() : [];
    $waitingVendors = [];
    foreach ($waiting as $b) { $waitingVendors[golpos_bill_name_key((string)($b['vendor'] ?? ''))] = true; }
    $counts = golpos_counts();
    foreach (golpos_tasks() as $t) {
        if (($t['status'] ?? '') === 'superseded' || golpos_task_status($t) === 'done') { continue; }
        $kind = (string)($t['kind'] ?? 'manual');
        $subject = (string)($t['subject'] ?? '');
        $created = strtotime((string)($t['created'] ?? '')) ?: 0;
        $closeIt = false;
        if ($kind === 'report_due' && preg_match('/^report:(\d{4}-\d{2}-\d{2})$/', $subject, $m)) {
            $closeIt = golpos_close_record($m[1]) !== null;
        } elseif ($kind === 'bill_waiting' && strpos($subject, 'bill:') === 0) {
            $closeIt = golpos_link_on()
                && !isset($waitingVendors[golpos_bill_name_key(substr($subject, 5))]);
        } elseif ($kind === 'whatsout_aging' && strpos($subject, 'count:') === 0) {
            $itemKey = golpos_bill_name_key(substr($subject, 6));
            foreach ($counts as $c) {
                if (golpos_bill_name_key((string)($c['name'] ?? '')) === $itemKey
                    && strtotime((string)($c['at'] ?? '')) >= $created) { $closeIt = true; break; }
            }
        } elseif ($kind === 'manual') {
            // An owner-given task closes by its linked work event too:
            // "Report hal" when the close lands, "bill hal — <vendor>"
            // when that bill is entered, "<item> count gara" when the
            // count is filed.
            $texts = [];
            foreach ((array)($t['items'] ?? []) as $it) {
                if (empty($it['done'])) { $texts[] = golpos_bill_name_key((string)($it['text'] ?? '')); }
            }
            $joined = implode(' ', $texts);
            if ($joined !== '' && preg_match('/report|close/', $joined)) {
                $closeIt = golpos_close_record(date('Y-m-d', $created)) !== null
                    || golpos_close_record(date('Y-m-d')) !== null
                    || golpos_close_record(date('Y-m-d', strtotime('-1 day'))) !== null;
            }
            if (!$closeIt && preg_match('/bill/', $joined)) {
                // Evidence = a purchase entered at/after the task was
                // given, for a vendor her task text names.
                foreach ((array)golpos_read('purchases.json', []) as $purch) {
                    if (!is_array($purch)) { continue; }
                    $vk = golpos_bill_name_key((string)($purch['vendor'] ?? ''));
                    $pat = strtotime((string)($purch['at'] ?? $purch['date'] ?? '')) ?: 0;
                    if ($vk !== '' && strpos($joined, $vk) !== false && $pat >= $created) {
                        $closeIt = true; break;
                    }
                }
            }
            if (!$closeIt && preg_match('/count|ginti/', $joined)) {
                foreach ($counts as $c) {
                    $ck = golpos_bill_name_key((string)($c['name'] ?? ''));
                    if ($ck !== '' && strpos($joined, $ck) !== false
                        && strtotime((string)($c['at'] ?? '')) >= $created) { $closeIt = true; break; }
                }
            }
        }
        if ($closeIt) { golpos_task_close_by_feed($t); }
    }
}

function golpos_tasks_sync(): array {
    golpos_tasks_reconcile();
    $created = [];
    $tasks = golpos_tasks();
    $openGol = [];
    foreach ($tasks as $t) {
        if (($t['source'] ?? '') === 'gol' && golpos_task_status($t) !== 'done'
            && ($t['status'] ?? '') !== 'superseded') {
            $openGol[($t['kind'] ?? '') . '|' . golpos_task_subject_key((string)($t['subject'] ?? ''))] = $t;
        }
    }
    $spawn = function (array $rec) use (&$created, &$openGol): void {
        $key = ($rec['kind'] ?? '') . '|' . golpos_task_subject_key((string)($rec['subject'] ?? ''));
        if (isset($openGol[$key])) {
            // An owner task on the same subject retires the generated one.
            if (golpos_owner_covers((string)$rec['kind'], (string)$rec['subject'])) {
                $old = $openGol[$key];
                $old['status'] = 'superseded';
                golpos_task_put($old);
                unset($openGol[$key]);
            }
            return;
        }
        if (golpos_owner_covers((string)$rec['kind'], (string)$rec['subject'])) { return; }
        // A generated task already DONE for this exact condition instance
        // (same subject, created at/after the condition's own stamp) is
        // finished work — regeneration must not resurrect it.
        foreach (golpos_tasks() as $t) {
            if (($t['source'] ?? '') === 'gol' && ($t['kind'] ?? '') === ($rec['kind'] ?? '')
                && golpos_task_subject_key((string)($t['subject'] ?? '')) === golpos_task_subject_key((string)$rec['subject'])
                && golpos_task_status($t) === 'done'
                && strtotime((string)($t['created'] ?? '')) >= strtotime((string)($rec['since'] ?? 'now'))) {
                return;
            }
        }
        $new = golpos_task_add($rec);
        $openGol[$key] = $new;
        $created[] = $new;
    };

    // (1) Report due — yesterday has no close record by morning. Only a
    // store that actually traded can owe a report: no sales and no
    // closes ever means a fresh till, not a missing close. Routing
    // reads the CHECKLIST (owner, 13:01): the task lands on the roster
    // member whose checked duties carry the day close; when nobody's
    // do, it stays unassigned in the owner's inbox, named as such.
    $yday = date('Y-m-d', strtotime('-1 day'));
    if (golpos_close_record($yday) === null && golpos_sales() !== []) {
        $closer = golpos_roster_with_duty('day close garne');
        if ($closer !== null) {
            $spawn(['employee_email' => (string)$closer['email'],
                    'employee_name' => golpos_display_name($closer), 'role' => null,
                    'items' => [['text' => 'Report hal — hijo ko close record chhaina',
                                 'done' => false, 'done_at' => null]],
                    'source' => 'gol', 'kind' => 'report_due', 'subject' => 'report:' . $yday,
                    'reason' => 'Hijo (' . $yday . ') ko day close / night close record aayena',
                    'since' => $yday . 'T00:00:00']);
        } else {
            $spawn(['employee_email' => null, 'employee_name' => 'Unassigned (owner inbox)', 'role' => 'closing',
                    'items' => [['text' => 'Report hal — hijo ko close record chhaina (kunai employee ko checklist ma day-close duty checked chhaina, tyasaile yo kam kasailai assign bhayeko chhaina — owner inbox ma chha)',
                                 'done' => false, 'done_at' => null]],
                    'source' => 'gol', 'kind' => 'report_due', 'subject' => 'report:' . $yday,
                    'reason' => 'Hijo (' . $yday . ') ko day close / night close record aayena',
                    'since' => $yday . 'T00:00:00']);
        }
    }
    // (2) Bill waiting — a bill in the store's vault with no entered
    // purchase behind it. LINK MODE ONLY: standalone bills arrive as
    // photo sessions and enter through the bill card at once, so a
    // standalone register has no waiting-bill source and this generator
    // stays silent there. Routes to whoever's checklist carries bills.
    if (golpos_link_on()) {
        $biller = golpos_roster_with_duty('bill ko photo pathaune/halnee');
        foreach (mrstore_bill_vault_waiting() as $b) {
            $vendor = (string)($b['vendor'] ?? '');
            if ($vendor === '') { continue; }
            $spawn(['employee_email' => $biller !== null ? (string)$biller['email'] : null,
                    'employee_name' => $biller !== null ? golpos_display_name($biller) : 'Unassigned (owner inbox)',
                    'role' => $biller !== null ? null : 'team',
                    'items' => [['text' => 'Bill hal — ' . $vendor . ' ko bill aayeko chha, entry gara',
                                 'done' => false, 'done_at' => null]],
                    'source' => 'gol', 'kind' => 'bill_waiting', 'subject' => 'bill:' . $vendor,
                    'reason' => 'Bill vault ma ' . $vendor . ' ko bill chha, entered purchase match chhaina',
                    'since' => (string)($b['date'] ?? date('Y-m-d')) . 'T00:00:00']);
        }
    }
    // (3) What's Out aging — an out report open beyond the store's
    // usual cycle (7 days) with no count since. The count task routes
    // to whoever's checklist carries stock counts; the original
    // reporter keeps it when their checklist carries the duty;
    // otherwise it lands unassigned in the owner's inbox.
    foreach (golpos_whatsout_open() as $w) {
        $at = strtotime((string)($w['at'] ?? ''));
        if ($at === false || $at > time() - 7 * 86400) { continue; }
        $name = (string)($w['name'] ?? '');
        if ($name === '') { continue; }
        $counter = golpos_roster_with_duty('stock ginti garne');
        $toEmail = null; $toName = 'Unassigned (owner inbox)'; $toRole = 'team';
        if (!empty($w['by_email'])) {
            $rep = golpos_employee((string)$w['by_email']);
            if ($rep !== null && $counter !== null && (string)$counter['email'] === (string)$rep['email']) {
                $toEmail = (string)$rep['email']; $toName = golpos_display_name($rep); $toRole = null;
            } elseif ($counter !== null) {
                $toEmail = (string)$counter['email']; $toName = golpos_display_name($counter); $toRole = null;
            } else {
                $toEmail = (string)$w['by_email']; $toName = (string)($w['by_name'] ?: 'Team'); $toRole = null;
            }
        } elseif ($counter !== null) {
            $toEmail = (string)$counter['email']; $toName = golpos_display_name($counter); $toRole = null;
        }
        $spawn(['employee_email' => $toEmail, 'employee_name' => $toName, 'role' => $toRole,
                'items' => [['text' => $name . ' count garera bhana', 'done' => false, 'done_at' => null]],
                'source' => 'gol', 'kind' => 'whatsout_aging', 'subject' => 'count:' . $name,
                'reason' => "What's Out ma " . date('M j', $at) . ' dekhi khula chha, count aayeko chhaina',
                'since' => (string)($w['at'] ?? '')]);
    }
    return $created;
}

// --- the NEXT due duty (owner principle, Oct 28, 13:07) ---------------------------
// "Human le list garne bittikai sabai kam garne bani hudaina." A dumped
// list builds no habit — so her working surface is ONE next step, at
// its moment, with its reason. The full list stays as overview (her
// task view, the owner's Team card); the next line is computed, never
// stored: due-timestamped tasks first (most overdue), then Gol's duty
// moments (a report due is due NOW, every morning), then the oldest
// open owner task. Nothing due -> null, and the surface stays silent:
// no nagging.
function golpos_next_due(string $email): ?array {
    $cands = [];
    foreach (golpos_tasks_for($email) as $t) {
        if (($t['status'] ?? '') === 'superseded' || golpos_task_status($t) === 'done') { continue; }
        foreach ((array)($t['items'] ?? []) as $idx => $it) {
            if (!empty($it['done'])) { continue; }
            $cands[] = ['task_id' => (string)$t['id'], 'index' => $idx,
                        'text' => (string)($it['text'] ?? ''),
                        'source' => (string)($t['source'] ?? 'owner'),
                        'kind' => (string)($t['kind'] ?? 'manual'),
                        'reason' => (string)($t['reason'] ?? ''),
                        'due' => (string)($t['due'] ?? ''),
                        'created' => (string)($t['created'] ?? '')];
        }
    }
    if (!$cands) { return null; }
    $now = time();
    $score = static function (array $c) use ($now): array {
        $dueTs = $c['due'] !== '' ? (strtotime($c['due']) ?: null) : null;
        if ($dueTs !== null && $dueTs <= $now) { return [0, $dueTs]; }        // due now / overdue
        if ($c['source'] === 'gol') { return [1, strtotime($c['created']) ?: $now]; } // duty moments
        if ($dueTs !== null) { return [2, $dueTs]; }                          // later today
        return [3, strtotime($c['created']) ?: $now];                         // oldest owner task
    };
    usort($cands, static fn($a, $b) => $score($a) <=> $score($b));
    return $cands[0];
}

// --- Counter frames (Register Watch, owner directive, Oct 28) ----------------
// One frame per cash sale, saved beside the till data as
// data/frames/<txn>.<ext> — the store's own record on the store's own
// server path, paired to the sale by its id. The sale record carries
// frame: true once a frame lands; frame.php serves the bytes back to
// the Transactions panel.
function golpos_frames_dir(): string {
    $dir = golpos_data_dir() . '/frames';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    return $dir;
}

function golpos_sale_by_id(string $txn): ?array {
    foreach (golpos_sales() as $s) {
        if ((string)($s['id'] ?? '') === $txn) { return $s; }
    }
    return null;
}

// Flag a recorded sale as frame-paired. Returns false when the sale is
// unknown — a frame never invents the sale it claims to witness.
function golpos_mark_sale_frame(string $txn): bool {
    $sales = golpos_sales();
    foreach ($sales as $i => $s) {
        if ((string)($s['id'] ?? '') === $txn) {
            $sales[$i]['frame'] = true;
            golpos_write('sales.json', $sales);
            return true;
        }
    }
    return false;
}

// The saved frame's [path, mime] for a sale, or null.
function golpos_frame_file(string $txn): ?array {
    if (!preg_match('/^txn_[a-z0-9]+$/', $txn)) { return null; }
    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    foreach ($mimes as $ext => $mime) {
        $p = golpos_frames_dir() . '/' . $txn . '.' . $ext;
        if (is_file($p)) { return [$p, $mime]; }
    }
    return null;
}

// --- Event clips (Register Camera Part B, Oct 28) ---------------------------
// When footage recording is effective, a cash tender also keeps a short
// clip beside the still frame: data/frames/<txn>_clip.<ext>, with its
// capture facts (extension, mime, whether the mic was in the stream,
// when) recorded on the sale itself as 'clip' — the same pairing rule as
// frames: a clip never invents the sale it claims to witness, and card
// sales never carry one (frame.php refuses them at the door).
function golpos_mark_sale_clip(string $txn, array $meta): bool {
    $sales = golpos_sales();
    foreach ($sales as $i => $s) {
        if ((string)($s['id'] ?? '') === $txn) {
            $sales[$i]['clip'] = $meta;
            golpos_write('sales.json', $sales);
            return true;
        }
    }
    return false;
}

// The saved clip's [path, mime] for a sale, or null.
function golpos_clip_file(string $txn): ?array {
    if (!preg_match('/^txn_[a-z0-9]+$/', $txn)) { return null; }
    $mimes = ['webm' => 'video/webm', 'mp4' => 'video/mp4'];
    foreach ($mimes as $ext => $mime) {
        $p = golpos_frames_dir() . '/' . $txn . '_clip.' . $ext;
        if (is_file($p)) { return [$p, $mime]; }
    }
    return null;
}

// The footage shelf for one register: every sale carrying a frame or a
// clip, newest first — the read model behind api.php?footage and the
// footage list page. Ids and facts only; frame.php serves the bytes.
function golpos_footage_list(int $cap = 100): array {
    $rows = [];
    foreach (golpos_sales() as $s) {
        $hasFrame = !empty($s['frame']) && golpos_frame_file((string)($s['id'] ?? '')) !== null;
        $clip = (isset($s['clip']) && is_array($s['clip'])) ? $s['clip'] : null;
        if ($clip !== null && golpos_clip_file((string)($s['id'] ?? '')) === null) { $clip = null; }
        if (!$hasFrame && $clip === null) { continue; }
        $rows[] = ['txn' => (string)($s['id'] ?? ''), 'at' => (string)($s['at'] ?? ''),
                   'total' => (float)($s['total'] ?? 0), 'tender' => (string)($s['tender'] ?? ''),
                   'frame' => $hasFrame, 'clip' => $clip];
    }
    return array_slice(array_reverse($rows), 0, $cap);
}
