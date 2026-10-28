<?php
// Gol POS — MR.STORE link (Gol Connect, simulation build).
//
// When the link is ON, this POS stops being an island: the register catalog
// IS the linked MR.STORE store's catalog, every rung sale moves that
// store's real stock, and Gol's stock answers come from MR.STORE's live
// state. The link speaks MR.STORE's own language — the same MySQL ops_kv
// rows, the same fixed ops owner, the same load -> edit -> save pattern and
// the same backup convention that MR.STORE's gol-chat.php itself uses
// (gol_catalog_load / gol_catalog_save / gol_pos_apply_stock /
// gol_apply_stock_counts). There is NO mirror file: a write here is a write
// in MR.STORE, readable by MR.STORE's own Gol on its next turn.
//
// FENCING (hard law): this simulation build may write ONLY to the Muse QA
// Test Store. Every write path re-checks the configured store id against
// the QA id and refuses anything else — a past incident let this QA
// store's test data contaminate a real store, and the guard exists so the
// bridge can never be pointed at a live shop by accident.
//
// Config (never committed): env LINK_MRSTORE=on, MRSTORE_STORE_ID,
// MRSTORE_DIR (path to the store-api dir), or a link.json in the POS data
// dir: {"on": true, "store_id": "...", "api_dir": "..."}.

declare(strict_types=1);

// The ONLY store this simulation build may touch.
const GOLPOS_QA_STORE_ID = '22af5007-3fa8-4804-ac6a-5cf19e64c697';
// MR.STORE's fixed ops owner for the Floor stock system (see
// gol_apply_stock_counts / gol_catalog_load in store-api/gol-chat.php).
const GOLPOS_MR_OPS_OWNER = 'luckymart323@gmail.com';

// POS-side demo prices, used ONLY for register display when MR.STORE has
// no price for a product (the QA store's bill-learned lines carry none).
// They are never written back into MR.STORE.
const GOLPOS_SIM_PRICES = [
    '049000050110' => 8.99,   // Coca-Cola 12oz Cans 12pk
    '018200001476' => 16.99,  // Bud Light 12pk Bottles
    '071279001234' => 2.49,   // Ice Bag 10lb
    '049000000000' => 8.49,   // Pepsi 12oz Cans 12pk
    '012000008103' => 2.19,   // Mountain Dew 20oz Bottles
    '037000123456' => 1.29,   // Paper Towels Single Roll
];

function golpos_link_config(): array {
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $cfg = ['on' => false, 'store_id' => '', 'api_dir' => ''];
    $file = __DIR__ . '/../data/link.json';
    if (is_file($file)) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j)) {
            $cfg['on'] = !empty($j['on']);
            $cfg['store_id'] = (string)($j['store_id'] ?? '');
            $cfg['api_dir'] = (string)($j['api_dir'] ?? '');
        }
    }
    if (getenv('LINK_MRSTORE') === 'on') { $cfg['on'] = true; }
    if (getenv('LINK_MRSTORE') === 'off') { $cfg['on'] = false; }
    $sid = getenv('MRSTORE_STORE_ID');
    if (is_string($sid) && $sid !== '') { $cfg['store_id'] = $sid; }
    $dir = getenv('MRSTORE_DIR');
    if (is_string($dir) && $dir !== '') { $cfg['api_dir'] = $dir; }
    if ($cfg['api_dir'] === '') {
        // Deployed layout: <public_html>/gol-pos-*/server -> <public_html>/store-api
        $cfg['api_dir'] = dirname(__DIR__, 2) . '/store-api';
    }
    return $cfg;
}

function golpos_link_on(): bool {
    $cfg = golpos_link_config();
    if (!$cfg['on'] || $cfg['store_id'] === '' || !is_dir($cfg['api_dir'])) { return false; }
    // Launch binding (the MR.STORE settings link opens the POS with
    // ?store=<id>&name=<name>&unlock=1): when a bind request exists, the
    // link is live only for the store it names. A bind naming any other
    // store refuses link mode — the register falls back to standalone.
    $bind = golpos_bind();
    if ($bind !== null) { return $bind['store'] === $cfg['store_id']; }
    return true;
}

// The launch context recorded by the register when MR.STORE's settings
// link opens it (same params MR.STORE's own pos.html boot() reads).
function golpos_bind(): ?array {
    $path = __DIR__ . '/../data/bind.json';
    if (!is_file($path)) { return null; }
    $j = json_decode((string)@file_get_contents($path), true);
    if (!is_array($j) || trim((string)($j['store'] ?? '')) === '') { return null; }
    return ['store' => (string)$j['store'], 'name' => (string)($j['name'] ?? ''),
            'unlock' => !empty($j['unlock']), 'at' => (string)($j['at'] ?? '')];
}

function golpos_bind_save(array $bind): void {
    $path = __DIR__ . '/../data/bind.json';
    $bind['at'] = date('c');
    @file_put_contents($path, json_encode($bind, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// What the register shows about its binding: bound to the linked store, or
// refused with a plain note (this sim build is fenced to the QA store).
function golpos_bind_state(): array {
    $cfg = golpos_link_config();
    $bind = golpos_bind();
    $state = ['link_configured' => $cfg['on'], 'bound' => false,
              'requested_store' => $bind['store'] ?? null,
              'requested_name' => $bind['name'] ?? null, 'note' => ''];
    if ($bind === null) {
        $state['bound'] = $cfg['on'] && $cfg['store_id'] !== '';
        return $state;
    }
    if ($cfg['on'] && $bind['store'] === $cfg['store_id']) {
        $state['bound'] = true;
        return $state;
    }
    $state['note'] = 'This demo build is fenced to the Muse QA Test Store — the launch for "'
        . ($bind['name'] !== '' ? $bind['name'] : $bind['store'])
        . '" was refused; showing the standalone register.';
    return $state;
}

// The fence: writes happen only when the configured store IS the QA store.
function mrstore_write_allowed(): bool {
    return golpos_link_config()['store_id'] === GOLPOS_QA_STORE_ID;
}

function mrstore_pdo(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) { return $pdo instanceof PDO ? $pdo : null; }
    $pdo = null;
    try {
        $dir = golpos_link_config()['api_dir'];
        require_once $dir . '/bootstrap.php';
        if (!function_exists('fb_nk')) { require_once $dir . '/floor-stock-apply.php'; }
        $C = require $dir . '/config.php';
        $pdo = db_connect($C);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

// Name-key exactly the way MR.STORE does (fb_nk in floor-stock-apply.php);
// a local copy so read paths never depend on the include having run.
function mrstore_nk(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';
}

// --- catalog load/save: gol_catalog_load / gol_catalog_save, mirrored -------
function mrstore_load_catalog(PDO $pdo, string $sid): array {
    $row = ops_kv_get($pdo, GOLPOS_MR_OPS_OWNER, $sid, 'product_catalog');
    $cat = (is_array($row) && isset($row['value']) && is_array($row['value'])) ? $row['value'] : ['products' => []];
    if (!isset($cat['products']) || !is_array($cat['products'])) { $cat['products'] = []; }
    return $cat;
}

function mrstore_save_catalog(PDO $pdo, string $sid, array $cat): void {
    $dir = golpos_link_config()['api_dir'];
    $bakDir = $dir . '/data/gol-backups';
    if (!is_dir($bakDir)) { @mkdir($bakDir, 0775, true); }
    $old = ops_kv_get($pdo, GOLPOS_MR_OPS_OWNER, $sid, 'product_catalog');
    @file_put_contents($bakDir . '/catalog_' . preg_replace('/[^a-z0-9]+/i', '_', $sid) . '_' . date('YmdHis') . '.json',
        json_encode($old, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $cat['at'] = round(microtime(true) * 1000);
    $cat['updatedAt'] = date('c');
    $cat['count'] = count($cat['products']);
    if (function_exists('ensure_ops_kv_table')) { ensure_ops_kv_table($pdo); }
    $st = $pdo->prepare("INSERT INTO lm_ops_kv (owner_key, store_id, data_key, data_value) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE data_value = VALUES(data_value), updated_at = CURRENT_TIMESTAMP");
    $st->execute([GOLPOS_MR_OPS_OWNER, $sid, 'product_catalog',
        json_encode($cat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

function mrstore_load_overrides(PDO $pdo, string $sid): array {
    $row = ops_kv_get($pdo, GOLPOS_MR_OPS_OWNER, $sid, 'stock_overrides');
    $ov = (is_array($row) && isset($row['value']) && is_array($row['value'])) ? $row['value'] : ['map' => []];
    if (!isset($ov['map']) || !is_array($ov['map'])) { $ov['map'] = []; }
    return $ov;
}

function mrstore_save_overrides(PDO $pdo, string $sid, array $ov): void {
    $ov['at'] = round(microtime(true) * 1000);
    ops_kv_set($pdo, GOLPOS_MR_OPS_OWNER, $sid, 'stock_overrides', $ov);
}

// The stock_overrides patch MR.STORE writes after every count/sale move:
// the same value under the product id, the name key, the barcode and a
// 'name:<lower>' key, so every reader sees the same number.
function mrstore_override_patch(array &$ov, array $pit, string $nameKey, float $q): void {
    $patch = ['name' => (string)($pit['name'] ?? ''), 'onHand' => $q, 'qty' => $q, 'updatedAt' => date('c')];
    $id = (string)($pit['id'] ?? $pit['barcode'] ?? $nameKey);
    foreach (array_unique([$id, $nameKey, (string)($pit['barcode'] ?? ''), 'name:' . strtolower((string)($pit['name'] ?? ''))]) as $k) {
        if ($k === '') { continue; }
        $prev = isset($ov['map'][$k]) && is_array($ov['map'][$k]) ? $ov['map'][$k] : [];
        $ov['map'][$k] = array_merge($prev, $patch);
    }
}

// --- settings: name/owner from MR.STORE's crew file, profile row if any ----
function mrstore_settings(): ?array {
    $cfg = golpos_link_config();
    if (!golpos_link_on()) { return null; }
    $sid = $cfg['store_id'];
    $name = 'MR.STORE store';
    $owner = '';
    $crewFile = $cfg['api_dir'] . '/data/owner_crew.json';
    if (is_file($crewFile)) {
        $crew = json_decode((string)@file_get_contents($crewFile), true);
        if (is_array($crew)) {
            foreach ($crew as $email => $info) {
                foreach ((array)($info['stores'] ?? []) as $s) {
                    if (is_array($s) && (string)($s['id'] ?? '') === $sid) {
                        $name = (string)($s['name'] ?? $name);
                        $owner = (string)$email;
                        break 2;
                    }
                }
            }
        }
    }
    $profile = null;
    $taxRate = 0.08; // MR.STORE keeps tax in the Books ledger, not a POS rate;
                     // the register keeps its own demo rate, stated openly.
    $pdo = mrstore_pdo();
    if ($pdo) {
        try {
            $row = ops_kv_get($pdo, GOLPOS_MR_OPS_OWNER, $sid, 'shop_profile');
            if (is_array($row) && isset($row['value']) && is_array($row['value'])) {
                $profile = $row['value'];
                if (isset($profile['name']) && trim((string)$profile['name']) !== '') {
                    $name = (string)$profile['name'];
                }
                if (isset($profile['tax_rate']) && is_numeric($profile['tax_rate'])) {
                    $taxRate = (float)$profile['tax_rate'];
                }
            }
        } catch (Throwable $e) { /* profile is optional */ }
    }
    return ['name' => $name, 'owner' => $owner, 'store_id' => $sid,
            'tax_rate' => $taxRate, 'currency' => 'USD', 'profile' => $profile,
            'source' => 'mrstore'];
}

// --- the register catalog, from MR.STORE live state --------------------------
function mrstore_sim_price(array $p): array {
    foreach (['retail', 'price'] as $k) {
        if (isset($p[$k]) && is_numeric($p[$k]) && (float)$p[$k] > 0) {
            return [(float)$p[$k], 'mrstore'];
        }
    }
    $bc = (string)($p['barcode'] ?? '');
    if (isset(GOLPOS_SIM_PRICES[$bc])) { return [GOLPOS_SIM_PRICES[$bc], 'sim-demo']; }
    $nm = strtolower((string)($p['name'] ?? ''));
    if (preg_match('/dutch|swisher|white owl|backwoods|game|optimo|garcia|clipper|prime time|good times|black|blk|show/', $nm)) {
        return [1.49, 'sim-demo'];
    }
    return [2.49, 'sim-demo'];
}

function mrstore_catalog_with_stock(): ?array {
    $pdo = mrstore_pdo();
    $set = mrstore_settings();
    if (!$pdo || !$set) { return null; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
    } catch (Throwable $e) {
        return null;
    }
    $aliasMap = [
        '049000050110' => ['coke', 'coca cola', 'कोक'],
        '049000000000' => ['pepsi', 'पेप्सी'],
        '012000008103' => ['dew', 'mountain dew'],
        '018200001476' => ['bud', 'bud light', 'beer', 'बियर'],
        '071279001234' => ['ice', 'baraf', 'बरफ'],
        '037000123456' => ['paper towels', 'towels'],
    ];
    $products = [];
    foreach ($cat['products'] as $i => $p) {
        if (!is_array($p)) { continue; }
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') { continue; }
        [$price, $src] = mrstore_sim_price($p);
        $bc = (string)($p['barcode'] ?? '');
        $onHand = isset($p['onHand']) && is_numeric($p['onHand']) ? (float)$p['onHand']
            : (isset($p['qty']) && is_numeric($p['qty']) ? (float)$p['qty'] : null);
        $products[] = [
            'sku' => $bc !== '' ? 'bc-' . $bc : 'mr-' . $i,
            'barcode' => $bc,
            'name' => $name,
            'mr_name' => $name,
            'category' => 'MR.STORE',
            'price' => $price,
            'price_source' => $src,
            'aliases' => $aliasMap[$bc] ?? [],
            'onHand' => $onHand,
        ];
    }
    return ['store' => ['name' => $set['name'], 'tax_rate' => $set['tax_rate'],
                        'currency' => 'USD', 'linked' => 'mrstore', 'store_id' => $sid],
            'products' => $products];
}

// --- one stock read -----------------------------------------------------------
function mrstore_stock_of(string $nameOrBarcode): ?array {
    $pdo = mrstore_pdo();
    if (!$pdo) { return null; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
    } catch (Throwable $e) {
        return null;
    }
    $want = mrstore_nk($nameOrBarcode);
    foreach ($cat['products'] as $p) {
        if (!is_array($p)) { continue; }
        $bc = (string)($p['barcode'] ?? '');
        if (($bc !== '' && $bc === $nameOrBarcode) || mrstore_nk((string)($p['name'] ?? '')) === $want) {
            $onHand = isset($p['onHand']) && is_numeric($p['onHand']) ? (float)$p['onHand']
                : (isset($p['qty']) && is_numeric($p['qty']) ? (float)$p['qty'] : null);
            return ['name' => (string)($p['name'] ?? ''), 'barcode' => $bc, 'onHand' => $onHand];
        }
    }
    return null;
}

// --- a rung sale moves MR.STORE stock: gol_pos_apply_stock, mirrored ---------
// $lines: POS sale lines [{name, qty, ...}]. Returns per-line notes
// [{name, qty, before, after}] or null when nothing tracked moved.
function mrstore_record_sale(array $lines): ?array {
    if (!mrstore_write_allowed()) { return null; }
    $pdo = mrstore_pdo();
    if (!$pdo) { return null; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
        $ov = mrstore_load_overrides($pdo, $sid);
        $by = [];
        foreach ($cat['products'] as $i => $pit) {
            if (!is_array($pit)) { continue; }
            $k = mrstore_nk((string)($pit['name'] ?? ''));
            if ($k !== '' && !isset($by[$k])) { $by[$k] = $i; }
        }
        $notes = [];
        $touched = false;
        foreach ($lines as $ln) {
            if (!is_array($ln)) { continue; }
            $k = mrstore_nk((string)($ln['mr_name'] ?? $ln['name'] ?? ''));
            if ($k === '' || !isset($by[$k])) { continue; }
            $idx = $by[$k];
            $cur = isset($cat['products'][$idx]['onHand']) && is_numeric($cat['products'][$idx]['onHand'])
                ? (float)$cat['products'][$idx]['onHand'] : null;
            if ($cur === null) { continue; } // stock not tracked for this product
            $qty = (float)($ln['qty'] ?? 0);
            $new = max(0.0, round($cur - $qty, 3));
            $cat['products'][$idx]['onHand'] = $new;
            $cat['products'][$idx]['qty'] = $new;
            $cat['products'][$idx]['updatedAt'] = date('c');
            $touched = true;
            $notes[] = ['name' => (string)$cat['products'][$idx]['name'], 'qty' => $qty,
                        'before' => $cur, 'after' => $new];
            mrstore_override_patch($ov, $cat['products'][$idx], $k, $new);
        }
        if (!$touched) { return null; }
        mrstore_save_catalog($pdo, $sid, $cat);
        mrstore_save_overrides($pdo, $sid, $ov);
        return $notes;
    } catch (Throwable $e) {
        return null;
    }
}

// --- the register journal: MR.STORE's own record of a sale --------------------
// MR.STORE's register posts every sale to /store-api/?route=pos_sale,
// which appends a ticket to data/pos/sales-<store>-<day>.jsonl via
// lm_pos_append_ticket() — the journal its card reports and card batches
// are built from. The bridge appends the same ticket, by calling the same
// function, so a Gol POS sale is indistinguishable in the journal from a
// sale rung on MR.STORE's own pos.html. (Stock moves separately, through
// mrstore_record_sale above — exactly as MR.STORE splits the two.)
function mrstore_append_ticket(array $sale, string $storeName, string $day, ?string $atIso = null): ?array {
    if (!mrstore_write_allowed()) { return null; }
    $dir = golpos_link_config()['api_dir'];
    try {
        if (!function_exists('lm_pos_append_ticket')) { require_once $dir . '/card-report.php'; }
        $tender = (string)($sale['tender'] ?? 'cash');
        $total = (float)($sale['total'] ?? 0);
        $lines = [];
        foreach (($sale['lines'] ?? []) as $l) {
            if (!is_array($l)) { continue; }
            $lines[] = ['name' => (string)($l['name'] ?? ''), 'qty' => (float)($l['qty'] ?? 0),
                        'price' => (float)($l['price'] ?? 0), 'line_total' => (float)($l['line_total'] ?? 0)];
        }
        $row = ['storeId' => golpos_link_config()['store_id'], 'storeName' => $storeName,
                'day' => $day, 'at' => $atIso ?? (string)($sale['at'] ?? date('c')),
                'type' => 'sale', 'receiptId' => (string)($sale['id'] ?? ''),
                'pay' => $tender, 'total' => $total,
                'cash' => $tender === 'cash' ? $total : 0.0,
                'card' => $tender === 'card' ? $total : 0.0,
                'tax' => (float)($sale['tax'] ?? 0), 'lines' => $lines];
        return lm_pos_append_ticket($row);
    } catch (Throwable $e) {
        return null;
    }
}

// --- a receive moves MR.STORE stock up: the chat receive + the sim runner ---
function mrstore_adjust_stock(string $name, float $delta): ?array {
    if (!mrstore_write_allowed()) { return null; }
    $pdo = mrstore_pdo();
    if (!$pdo) { return null; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
        $ov = mrstore_load_overrides($pdo, $sid);
        $want = mrstore_nk($name);
        foreach ($cat['products'] as $i => $pit) {
            if (!is_array($pit)) { continue; }
            $bc = (string)($pit['barcode'] ?? '');
            if (!(($bc !== '' && $bc === $name) || mrstore_nk((string)($pit['name'] ?? '')) === $want)) { continue; }
            $cur = isset($pit['onHand']) && is_numeric($pit['onHand']) ? (float)$pit['onHand']
                : (isset($pit['qty']) && is_numeric($pit['qty']) ? (float)$pit['qty'] : 0.0);
            $new = round($cur + $delta, 3);
            $cat['products'][$i]['onHand'] = $new;
            $cat['products'][$i]['qty'] = $new;
            $cat['products'][$i]['updatedAt'] = date('c');
            mrstore_override_patch($ov, $cat['products'][$i], mrstore_nk((string)$pit['name']), $new);
            mrstore_save_catalog($pdo, $sid, $cat);
            mrstore_save_overrides($pdo, $sid, $ov);
            return ['name' => (string)$pit['name'], 'before' => $cur, 'after' => $new];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

// --- a physical count SETS stock: gol_apply_stock_counts, mirrored -----------
// Used by the simulation runner to seed the QA store's opening stock.
function mrstore_set_stock(string $name, float $qty): ?array {
    if (!mrstore_write_allowed()) { return null; }
    $pdo = mrstore_pdo();
    if (!$pdo) { return null; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
        $ov = mrstore_load_overrides($pdo, $sid);
        $want = mrstore_nk($name);
        $q = round($qty, 3);
        foreach ($cat['products'] as $i => $pit) {
            if (!is_array($pit)) { continue; }
            if (mrstore_nk((string)($pit['name'] ?? '')) !== $want) { continue; }
            $before = isset($pit['onHand']) && is_numeric($pit['onHand']) ? (float)$pit['onHand'] : null;
            $cat['products'][$i]['onHand'] = $q;
            $cat['products'][$i]['qty'] = $q;
            $cat['products'][$i]['updatedAt'] = date('c');
            $cat['products'][$i]['_fromCount'] = true;
            mrstore_override_patch($ov, $cat['products'][$i], $want, $q);
            mrstore_save_catalog($pdo, $sid, $cat);
            mrstore_save_overrides($pdo, $sid, $ov);
            return ['name' => (string)$pit['name'], 'before' => $before, 'after' => $q];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

// --- POS-side catalog writes land in MR.STORE too ------------------------------
// A price the owner changes at this register, and an item the owner adds,
// are MR.STORE catalog facts — the sync writes them with the same
// load -> edit -> save pattern. POS-side demo prices (price_source
// 'sim-demo') are NEVER written back: they exist only on this register.
function mrstore_sync_catalog(array $posCatalog): void {
    if (!mrstore_write_allowed()) { return; }
    $pdo = mrstore_pdo();
    if (!$pdo) { return; }
    $sid = golpos_link_config()['store_id'];
    try {
        $cat = mrstore_load_catalog($pdo, $sid);
        $byBc = [];
        $byNk = [];
        foreach ($cat['products'] as $i => $pit) {
            if (!is_array($pit)) { continue; }
            $bc = (string)($pit['barcode'] ?? '');
            if ($bc !== '' && !isset($byBc[$bc])) { $byBc[$bc] = $i; }
            $k = mrstore_nk((string)($pit['name'] ?? ''));
            if ($k !== '' && !isset($byNk[$k])) { $byNk[$k] = $i; }
        }
        $dirty = false;
        foreach (($posCatalog['products'] ?? []) as $p) {
            if (!is_array($p)) { continue; }
            $bc = (string)($p['barcode'] ?? '');
            $idx = null;
            if ($bc !== '' && isset($byBc[$bc])) { $idx = $byBc[$bc]; }
            if ($idx === null) {
                $k = mrstore_nk((string)($p['mr_name'] ?? $p['name'] ?? ''));
                if ($k !== '' && isset($byNk[$k])) { $idx = $byNk[$k]; }
            }
            if ($idx === null) {
                // A genuinely new item from this register joins the store catalog.
                $cat['products'][] = ['name' => (string)($p['name'] ?? ''), 'vendor' => '',
                    'barcode' => $bc, 'cost' => null, 'caseCost' => null, 'packSize' => '',
                    'retail' => (float)($p['price'] ?? 0), 'price' => (float)($p['price'] ?? 0),
                    'onHand' => null, 'qty' => null, 'storeId' => $sid];
                $dirty = true;
                continue;
            }
            $cur = null;
            foreach (['retail', 'price'] as $k2) {
                if (isset($cat['products'][$idx][$k2]) && is_numeric($cat['products'][$idx][$k2])) {
                    $cur = (float)$cat['products'][$idx][$k2];
                    break;
                }
            }
            $new = (float)($p['price'] ?? 0);
            if ($cur === null) {
                // MR.STORE has no price: the register was showing a POS-side
                // demo price. Write only when the price in hand is NOT that
                // untouched fallback — i.e. the owner actually set it.
                [$fallback] = mrstore_sim_price($cat['products'][$idx]);
                if (abs($new - $fallback) < 0.001) { continue; }
            }
            if ($cur === null || abs($cur - $new) > 0.001) {
                $cat['products'][$idx]['retail'] = $new;
                $cat['products'][$idx]['price'] = $new;
                $cat['products'][$idx]['updatedAt'] = date('c');
                $dirty = true;
            }
        }
        if ($dirty) { mrstore_save_catalog($pdo, $sid, $cat); }
    } catch (Throwable $e) { /* the register never stops for the link */ }
}

// Append-only evidence trail on the POS side: every stock movement the
// bridge makes, so a simulation can be cross-checked line by line.
function golpos_link_log(array $entry): void {
    $path = __DIR__ . '/../data/mrstore_link_log.jsonl';
    $entry['at'] = date('c');
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
