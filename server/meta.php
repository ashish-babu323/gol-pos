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
