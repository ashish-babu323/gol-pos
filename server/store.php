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
        $words = array_filter(preg_split('/[^a-z0-9]+/u', mb_strtolower($p['name'])) ?: [],
                              fn($w) => $w !== '' && !is_numeric($w));
        foreach (($p['aliases'] ?? []) as $a) {
            foreach (preg_split('/\s+/u', mb_strtolower((string)$a)) ?: [] as $w) {
                if ($w !== '') { $words[] = $w; }
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
    $ut = 0.0; foreach ($unb as $s) { $ut += (float)($s['total'] ?? 0); }
    $rec['unbatched_card'] = ['count' => count($unb), 'total' => round($ut, 2)];
    $closes = golpos_read('closes.json', []);
    $out = [];
    foreach ($closes as $c) { if (($c['date'] ?? '') !== $ymd) { $out[] = $c; } }
    $out[] = $rec;
    golpos_write('closes.json', $out);
    return $rec;
}

function golpos_day_summary(string $ymd): array {
    $gross = 0.0; $cash = 0.0; $card = 0.0; $count = 0;
    foreach (golpos_sales() as $s) {
        if (substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $total = (float)($s['total'] ?? 0);
        $gross += $total; $count++;
        if (($s['tender'] ?? '') === 'cash') { $cash += $total; } else { $card += $total; }
    }
    return ['date' => $ymd, 'gross' => round($gross, 2), 'cash' => round($cash, 2),
            'card' => round($card, 2), 'txns' => $count];
}

// The saved close record for one date, if the day was closed (chat or panel).
function golpos_close_record(string $ymd): ?array {
    foreach (golpos_read('closes.json', []) as $c) {
        if (($c['date'] ?? '') === $ymd) { return $c; }
    }
    return null;
}

// --- receipt print settings: ONE store, TWO doors ----------------------------
// The Gol chat stages changes as cards (executed on "gol"); the owner panel
// edits the same fields in a form. Both read and write THIS one settings
// store, so the two doors can never disagree — there is no settings menu
// anywhere else. The receipt view renders from these values.
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
    foreach (['show_address', 'show_phone', 'logo', 'show_tax', 'auto_open', 'register_open', 'watch_frame'] as $k) {
        if (array_key_exists($k, $in)) { $cur[$k] = (bool)$in[$k]; }
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
                   'total' => round($total, 2), 'receipts' => $count];
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
        if ($row['phone'] === $key) { $row['of'] = count($board); return $row; }
    }
    return null;
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
            return;
        }
    }
    $d['employees'][] = $emp;
    golpos_save_people($d);
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

function golpos_unbatched_card_sales(?string $ymd = null): array {
    $sealed = [];
    foreach (golpos_batches() as $b) {
        foreach (($b['sale_ids'] ?? []) as $id) { $sealed[(string)$id] = true; }
    }
    $out = [];
    foreach (golpos_sales() as $s) {
        if (($s['tender'] ?? '') !== 'card') { continue; }
        if (isset($sealed[(string)($s['id'] ?? '')])) { continue; }
        if ($ymd !== null && substr((string)($s['at'] ?? ''), 0, 10) !== $ymd) { continue; }
        $out[] = $s;
    }
    return $out;
}

function golpos_seal_batch(array $sales, string $closedBy, ?string $closeTime = null): array {
    $batches = golpos_batches();
    $total = 0.0;
    foreach ($sales as $s) { $total += (float)($s['total'] ?? 0); }
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
