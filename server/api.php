<?php
// Gol POS — register API.
// GET  ?products            -> catalog
// POST {sale:{lines,tender}}-> records the sale, returns it with totals
// GET  ?summary             -> today's summary
// GET  ?pending             -> the staged card, if any

declare(strict_types=1);
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['products'])) {
    echo json_encode(golpos_catalog(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['summary'])) {
    echo json_encode(golpos_day_summary(date('Y-m-d')), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['closes'])) {
    // Day-close records — one per date, written when the owner closes the
    // day in the Gol chat; the owner view reads them back here.
    echo json_encode(golpos_read('closes.json', []), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['pending'])) {
    echo json_encode(golpos_pending() ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && isset($_GET['feed'])) {
    // The demo store's social feed — posters Gol staged and the owner
    // confirmed with "gol". Fictional demo content only.
    echo json_encode(golpos_read('feed.json', []), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $sale = $in['sale'] ?? null;
    if (is_array($sale) && !empty($sale['lines'])) {
        $catalog = golpos_catalog();
        $bySku = [];
        foreach ($catalog['products'] as $p) { $bySku[$p['sku']] = $p; }
        $subtotal = 0.0; $lines = [];
        foreach ($sale['lines'] as $l) {
            $p = $bySku[$l['sku']] ?? null;
            if (!$p) { continue; }
            $qty = max(1, (int)($l['qty'] ?? 1));
            $lineTotal = round((float)$p['price'] * $qty, 2);
            $subtotal += $lineTotal;
            $lines[] = ['sku' => $p['sku'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty, 'line_total' => $lineTotal];
        }
        $taxRate = (float)($catalog['store']['tax_rate'] ?? 0.0);
        $tax = round($subtotal * $taxRate, 2);
        $record = golpos_append_sale([
            'lines' => $lines, 'subtotal' => round($subtotal, 2), 'tax' => $tax,
            'total' => round($subtotal + $tax, 2), 'tender' => ($sale['tender'] ?? 'cash') === 'card' ? 'card' : 'cash',
        ]);
        echo json_encode($record, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
http_response_code(400);
echo json_encode(['error' => 'bad request']);
