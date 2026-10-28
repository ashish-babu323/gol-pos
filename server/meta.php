<?php
// Gol POS — the Meta Model API client (the whole Muse family, server-side).
//
// One key home, four models, one law: the key NEVER leaves the server. It is
// read from the environment (META_MODEL_API_KEY), from a key file
// (META_API_KEY_FILE), or from the house env file outside the web root — and
// it is never sent to the browser, never logged, never committed.
//
//   muse-spark-1.3   — the fast thinker: register-speed composed replies.
//   muse-wonder-1.0  — the deep thinker: owner-level analysis & judgment.
//   sam-3.1          — the eyes: photo segmentation via the Responses API.
//   muse-image-1.0   — the creator: offer posters via image generation.
//
// Every caller treats a null return as "the API did not answer" and falls
// back silently — the register always rings.

declare(strict_types=1);

function golpos_meta_key(): ?string {
    $direct = getenv('META_MODEL_API_KEY');
    if (is_string($direct) && trim($direct) !== '') { return trim($direct); }
    $files = [];
    $kf = getenv('META_API_KEY_FILE');
    if (is_string($kf) && $kf !== '') { $files[] = $kf; }
    $files[] = dirname(__DIR__, 3) . '/salonbooks-private/meta.env';
    $files[] = dirname(__DIR__) . '/private/meta.env';
    foreach ($files as $f) {
        if (!is_file($f) || !is_readable($f)) { continue; }
        $raw = trim((string)file_get_contents($f));
        if ($raw === '') { continue; }
        if (preg_match('/^META_MODEL_API_KEY\s*=\s*(\S+)\s*$/m', $raw, $m)) { return trim($m[1], "\"'"); }
        if (preg_match('/^META_API_KEY\s*=\s*(\S+)\s*$/m', $raw, $m)) { return trim($m[1], "\"'"); }
        if (strpos($raw, '=') === false && strpos($raw, "\n") === false) { return $raw; } // bare-key file
    }
    return null;
}

// One raw Meta API call. Returns [http_code, decoded_json_or_null, raw_body].
function golpos_meta_http(string $path, array $payload, int $timeout): array {
    $key = golpos_meta_key();
    if ($key === null || !function_exists('curl_init')) { return [0, null, '']; }
    $ch = curl_init('https://api.meta.ai/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) { return [0, null, '']; }
    $j = json_decode((string)$raw, true);
    return [$code, is_array($j) ? $j : null, (string)$raw];
}

// Chat completion on any Muse text model. On success records the provider
// marker and returns the composed content; null on ANY failure.
function golpos_meta_chat(string $model, string $system, string $user, int $maxTokens = 1500, int $timeout = 40): ?string {
    [$code, $j] = golpos_meta_http('/chat/completions', [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => $maxTokens,
        'reasoning_effort' => 'minimal',
    ], $timeout);
    if ($code !== 200 || $j === null) { return null; }
    $content = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($content === '') { return null; }
    $GLOBALS['golpos_meta'] = ['provider' => 'meta-model-api', 'model' => $model];
    return $content;
}

// SAM 3.1 — the eyes. Sends one photo (as a data URL) + one text concept to
// the Responses API and parses Meta's SAM token output into regions (boxes).
// Returns ['regions'=>int, 'boxes'=>[...], 'excerpt'=>string,
// 'images_processed'=>int] when SAM answered (regions may honestly be 0), or
// null when the API did not answer at all.
function golpos_meta_sam(string $dataUrl, string $prompt): ?array {
    [$code, $j] = golpos_meta_http('/responses', [
        'model' => 'sam-3.1',
        'input' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $prompt],
                ['type' => 'input_image', 'image_url' => $dataUrl],
            ],
        ]],
    ], 90);
    if ($code !== 200 || $j === null) { return null; }
    // Collect the assistant text from the Responses shape (chat shape as backup).
    $text = '';
    foreach (($j['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $c) {
            if (isset($c['text'])) { $text .= (string)$c['text']; }
        }
    }
    if ($text === '' && isset($j['choices'][0]['message']['content'])) {
        $text = (string)$j['choices'][0]['message']['content'];
    }
    $boxes = [];
    if (preg_match_all('/<\|box;x1=(\d+);y1=(\d+);x2=(\d+);y2=(\d+);w=(\d+);h=(\d+)\|>/', $text, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $b) {
            $boxes[] = ['x1' => (int)$b[1], 'y1' => (int)$b[2], 'x2' => (int)$b[3],
                        'y2' => (int)$b[4], 'w' => (int)$b[5], 'h' => (int)$b[6]];
            if (count($boxes) >= 12) { break; }
        }
    }
    $GLOBALS['golpos_meta'] = ['provider' => 'meta-model-api', 'model' => 'sam-3.1'];
    return [
        'regions' => count($boxes),
        'boxes' => $boxes,
        'excerpt' => mb_substr(preg_replace('/<\|mask;[^|]*\|>/', '<|mask|>', $text) ?? $text, 0, 400),
        'images_processed' => (int)($j['usage']['images_processed'] ?? 0),
        'status' => (string)($j['status'] ?? ''),
    ];
}

// Muse Image — the creator. Generates one image and returns the raw bytes
// (decoded from b64_json, or fetched when the API returns a URL), null on
// any failure.
function golpos_meta_image(string $prompt, string $size = '1024x1792'): ?string {
    [$code, $j] = golpos_meta_http('/images/generations', [
        'model' => 'muse-image-1.0',
        'prompt' => $prompt,
        'n' => 1,
        'size' => $size,
        'response_format' => 'b64_json',
        'output_format' => 'png',
    ], 240);
    if ($code !== 200 || $j === null) { return null; }
    $item = $j['data'][0] ?? [];
    if (!empty($item['b64_json'])) {
        $raw = base64_decode((string)$item['b64_json'], true);
        if ($raw !== false && $raw !== '') {
            $GLOBALS['golpos_meta'] = ['provider' => 'meta-model-api', 'model' => 'muse-image-1.0'];
            return $raw;
        }
        return null;
    }
    if (!empty($item['url']) && function_exists('curl_init')) {
        $ch = curl_init((string)$item['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 60]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw !== false && $raw !== '') {
            $GLOBALS['golpos_meta'] = ['provider' => 'meta-model-api', 'model' => 'muse-image-1.0'];
            return (string)$raw;
        }
    }
    return null;
}

// Vision read — Muse Spark looks at one photo and answers as JSON (receipt
// fields, a shelf count, any structured read a lane asks for). The chat
// completions multimodal shape goes first; the Responses shape (the one SAM
// rides) is the fallback. Returns the decoded array, or null when the API
// did not answer or did not answer in JSON — callers degrade honestly and
// never invent the values the read was supposed to supply.
function golpos_meta_vision_json(string $dataUrl, string $prompt, int $timeout = 60): ?array {
    [$code, $j] = golpos_meta_http('/chat/completions', [
        'model' => 'muse-spark-1.3',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]],
        'max_tokens' => 1500,
        'reasoning_effort' => 'minimal',
    ], $timeout);
    $text = '';
    if ($code === 200 && $j !== null) {
        $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    }
    if ($text === '') {
        [$code2, $j2] = golpos_meta_http('/responses', [
            'model' => 'muse-spark-1.3',
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_image', 'image_url' => $dataUrl],
                ],
            ]],
        ], $timeout);
        if ($code2 === 200 && $j2 !== null) {
            foreach (($j2['output'] ?? []) as $item) {
                foreach (($item['content'] ?? []) as $c) {
                    if (isset($c['text'])) { $text .= (string)$c['text']; }
                }
            }
            if ($text === '' && isset($j2['choices'][0]['message']['content'])) {
                $text = (string)$j2['choices'][0]['message']['content'];
            }
            $text = trim($text);
        }
    }
    if ($text === '') { return null; }
    $GLOBALS['golpos_meta'] = ['provider' => 'meta-model-api', 'model' => 'muse-spark-1.3'];
    // The model is asked for pure JSON; tolerate a fenced or chatty reply.
    if (preg_match('/\{.*\}/s', $text, $m)) { $text = $m[0]; }
    $out = json_decode($text, true);
    return is_array($out) ? $out : null;
}

// Vendor bill read for the bill lane: the supplier name and every printed
// line — name, quantity, per-unit cost, and the item's barcode when the
// bill prints one. The bill's numbers are COSTS, never shelf prices; a
// line's shelf price is the owner's to give, downstream. Any value the
// photo does not show comes back null and nothing is reconstructed here.
function golpos_meta_read_bill(string $dataUrl): ?array {
    $prompt = 'Read this vendor bill / supplier invoice photo. Reply with ONLY a JSON object and no other text: '
        . '{"vendor": "<the supplier / vendor name exactly as printed>", '
        . '"lines": [{"name": "<the product name exactly as printed on that line>", '
        . '"qty": <the quantity delivered on that line as a plain number>, '
        . '"unit_cost": <the price of ONE unit on that line as a plain number, no currency sign or commas>, '
        . '"barcode": "<the item barcode digits printed for that line, or null>"}]}. '
        . 'One entry per printed product line, in the order printed. unit_cost is per single unit: '
        . 'if a line shows only an extended amount for several units, give the extended amount divided by the quantity. '
        . 'Use null for any value you cannot read clearly.';
    $r = golpos_meta_vision_json($dataUrl, $prompt);
    if ($r === null || !isset($r['lines']) || !is_array($r['lines'])) { return null; }
    $vendor = (isset($r['vendor']) && is_string($r['vendor']) && trim($r['vendor']) !== '')
        ? trim($r['vendor']) : null;
    $lines = [];
    foreach ($r['lines'] as $l) {
        if (!is_array($l)) { continue; }
        $name = isset($l['name']) && is_string($l['name']) ? trim($l['name']) : '';
        if ($name === '') { continue; }
        $qty = $l['qty'] ?? null;
        if (is_string($qty)) { $qty = preg_replace('/[^0-9.]/', '', $qty); }
        $cost = $l['unit_cost'] ?? null;
        if (is_string($cost)) { $cost = preg_replace('/[^0-9.]/', '', $cost); }
        $bc = isset($l['barcode']) && is_string($l['barcode'])
            ? (preg_replace('/\D+/', '', $l['barcode']) ?? '') : '';
        $lines[] = [
            'name' => $name,
            'qty' => is_numeric($qty) && (float)$qty > 0 ? (float)$qty : 1.0,
            'unit_cost' => is_numeric($cost) && (float)$cost >= 0 ? round((float)$cost, 2) : null,
            // A code counts only at barcode length (the barcode doors'
            // discipline: 8–14 digits); anything shorter is print noise.
            'barcode' => ($bc !== '' && strlen($bc) >= 8 && strlen($bc) <= 14) ? $bc : null,
        ];
    }
    return ['vendor' => $vendor, 'lines' => $lines];
}

// Receipt read for the loyalty lane: store name, date, grand total and the
// receipt number, exactly as printed. Any field the photo does not show
// comes back null — the loyalty endpoint decides what a partial read means,
// and nothing is ever reconstructed here.
function golpos_meta_read_receipt(string $dataUrl): ?array {
    $prompt = 'Read this store receipt photo. Reply with ONLY a JSON object and no other text: '
        . '{"store": "<store name exactly as printed>", "date": "<the receipt date as YYYY-MM-DD>", '
        . '"total": <the grand TOTAL amount due as a plain number, no currency sign or commas>, '
        . '"receipt_no": "<the receipt number / transaction number exactly as printed>"}. '
        . 'The total is the final TOTAL, never the subtotal or the cash tendered. '
        . 'Use null for any field you cannot read clearly.';
    $r = golpos_meta_vision_json($dataUrl, $prompt);
    if ($r === null) { return null; }
    $clean = static function ($v): ?string {
        if (!is_string($v)) { return null; }
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    $total = $r['total'] ?? null;
    if (is_string($total)) { $total = preg_replace('/[^0-9.]/', '', $total); }
    return [
        'store' => $clean($r['store'] ?? null),
        'date' => $clean($r['date'] ?? null),
        'total' => is_numeric($total) ? round((float)$total, 2) : null,
        'receipt_no' => $clean($r['receipt_no'] ?? null),
    ];
}
