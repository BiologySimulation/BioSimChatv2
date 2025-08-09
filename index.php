<?php
$ALLOWED_ORIGINS = [
    'https://bio-sim.us',
];

$API_KEY = 'ur api key lol';
$GEMINI_MODEL = 'gemini-2.0-flash';
$GEMINI_URL = "https://generativelanguage.googleapis.com/v1beta/models/{$GEMINI_MODEL}:generateContent?key={$API_KEY}";

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Vary: Origin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST is allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Unable to read request body.']);
    exit;
}

$body = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Body must be valid JSON.']);
    exit;
}

if (!isset($body['userinput']) || !is_string($body['userinput']) || $body['userinput'] === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing "userinput" (non-empty string).']);
    exit;
}

$userInput = $body['userinput'];

$infoPath = __DIR__ . '/info.txt';
$info = is_file($infoPath) ? (file_get_contents($infoPath) ?: '') : '';

$buttonsPath = __DIR__ . '/buttons.json';
$buttons = [];
if (is_file($buttonsPath)) {
    $btnRaw = file_get_contents($buttonsPath);
    $decoded = json_decode($btnRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $buttons = $decoded;
    }
}

$buttonsKeys = array_keys($buttons);
$buttonsKeysJson = json_encode($buttonsKeys, JSON_UNESCAPED_UNICODE);

$systemText = <<<EOT
Answer the prompt delimited by the triple apostrophes in the best way possible using your knowledge about biology.
When possible, incorporate information delimited by the triple backticks in your answer.

Limit yourself to 5 sentences unless otherwise specified by the prompt. 

When possible, make your answer a bulleted list, adding "<br><br>" after each line break. Do not bold any texts by wrapping the texts with **.
Highlight any key/important words in your response. In order to highlight a text, wrap the text with <b> and </b>.
Use "-" before each bullet point.

If you are unable to respond to the prompt using either your knowledge about biology, or by using the information delimited by the triple backticks, 
then add 'what is' in front of the prompt and then attempt to respond to it.

If you still are unable to respond to the prompt, then respond with the response "Sorry, I cannot help you with that".

```{$info}```

'''{$userInput}'''
EOT;

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $userInput],
            ],
        ],
    ],
    'systemInstruction' => [
        'parts' => [
            ['text' => $systemText],
        ],
    ],
];

$ch = curl_init($GEMINI_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'bio-sim-php-proxy/1.0',
]);

$response = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr   = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrNo) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream request failed', 'detail' => $curlErr]);
    exit;
}

if ($response === false || $response === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Empty response from upstream']);
    exit;
}

$gemini = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid JSON from Gemini', 'raw' => $response]);
    exit;
}

$replyText = null;
if (isset($gemini['candidates'][0]['content']['parts']) && is_array($gemini['candidates'][0]['content']['parts'])) {
    $parts = $gemini['candidates'][0]['content']['parts'];
    $texts = [];
    foreach ($parts as $p) {
        if (isset($p['text']) && is_string($p['text'])) {
            $texts[] = $p['text'];
        }
    }
    if (!empty($texts)) {
        $replyText = implode("\n", $texts);
    }
}
if ($replyText === null) {
    $reason = $gemini['candidates'][0]['finishReason'] ?? null;
    $replyText = $reason
        ? "Sorry, I couldn’t produce a response (finishReason: {$reason})."
        : "Sorry, I couldn’t produce a response.";
}

$buttonPrompt = <<<EOT
The given prompt is delimited by the triple apostrophes.
The given Array is delimited by the triple backticks.

Your task is to pick one of the strings in the Array which is the most relevant to the prompt.
Your response should only include that string, and nothing else.
If none of the keys in the JSON text are relevant to the prompt, your response should be "none".

'''{$userInput}'''

```{$buttonsKeysJson}```
EOT;

$payload2 = [
    'contents' => [
        [
            'parts' => [
                ['text' => $buttonPrompt],
            ],
        ],
    ],
];

$ch2 = curl_init($GEMINI_URL);
curl_setopt_array($ch2, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload2, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'bio-sim-php-proxy/1.0',
]);

$response2 = curl_exec($ch2);
$curlErrNo2 = curl_errno($ch2);
$curlErr2   = curl_error($ch2);
curl_close($ch2);

if ($curlErrNo2) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream request failed (button selection)', 'detail' => $curlErr2]);
    exit;
}

if ($response2 === false || $response2 === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Empty response from upstream (button selection)']);
    exit;
}

$gemini2 = json_decode($response2, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid JSON from Gemini (button selection)', 'raw' => $response2]);
    exit;
}

$buttonResponse = null;
if (isset($gemini2['candidates'][0]['content']['parts']) && is_array($gemini2['candidates'][0]['content']['parts'])) {
    $bparts = $gemini2['candidates'][0]['content']['parts'];
    $btexts = [];
    foreach ($bparts as $p) {
        if (isset($p['text']) && is_string($p['text'])) {
            $btexts[] = $p['text'];
        }
    }
    if (!empty($btexts)) {
        $buttonResponse = implode("\n", $btexts);
    }
}
if ($buttonResponse !== null) {
    $buttonResponse = rtrim($buttonResponse, " \t\n\r\0\x0B");
}
if ($buttonResponse === null || $buttonResponse === '') {
    $buttonResponse = 'none';
}
$buttonOut = array_key_exists($buttonResponse, $buttons) ? $buttons[$buttonResponse] : 'none';
$out = [
    'reply'  => $replyText,
    'button' => $buttonOut,
];
http_response_code($httpCode >= 200 && $httpCode < 300 ? 200 : 502);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
