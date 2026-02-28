<?php
/**
 * g.php — rad.typo AI voice proxy
 *
 * API key config (one of):
 *   1. Environment variable:  ANTHROPIC_API_KEY
 *   2. Config file:           /var/www/html/g-config.php  (define ANTHROPIC_API_KEY)
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// Locate API key
$apiKey = getenv('ANTHROPIC_API_KEY') ?: null;
if (!$apiKey) {
    $cfg = __DIR__ . '/g-config.php';
    if (file_exists($cfg)) {
        require_once $cfg;
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : null;
    }
}
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'not configured']);
    exit;
}

// Parse request body
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['error' => 'empty request']);
    exit;
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit;
}

$userText  = isset($input['text'])  ? substr(trim($input['text']), 0, 2000) : '';
$userImage = isset($input['image']) ? $input['image'] : null;

if (!$userText && !$userImage) {
    http_response_code(400);
    echo json_encode(['error' => 'no input']);
    exit;
}

// Load content
$poems = json_decode(file_get_contents(__DIR__ . '/data/poems.json'), true) ?: [];
$songs = json_decode(file_get_contents(__DIR__ . '/data/songs.json'), true) ?: [];

// Build system prompt from corpus
function buildPrompt(array $poems, array $songs): string {
    $p  = "you are the creative voice of rad.typo — a writer and musician from derry, ireland.\n\n";
    $p .= "below is the complete body of work. study the voice closely: sparse, grounded in specific objects ";
    $p .= "and places, image-driven, never sentimental, always lowercase, formally loose but precise. ";
    $p .= "the writing notices what others walk past. it doesn't explain, doesn't resolve — it observes and stops. ";
    $p .= "it carries weight without announcing it. proper nouns do real work. nothing is decorative.\n\n";
    $p .= "---\n\npoems:\n\n";

    foreach ($poems as $poem) {
        $title = strtolower($poem['title'] ?? '');
        $text  = html_entity_decode(strip_tags($poem['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $p    .= $title . "\n\n" . trim($text) . "\n\n---\n\n";
    }

    $p .= "songs:\n\n";
    foreach ($songs as $song) {
        $title  = strtolower($song['title'] ?? '');
        $lyrics = trim($song['lyrics'] ?? '');
        $p     .= $title . "\n";
        if ($lyrics) $p .= $lyrics . "\n";
        $p .= "\n---\n\n";
    }

    $p .= "when given text, a phrase, or an image — respond in this voice.\n";
    $p .= "decide the form yourself: a title, a line continuation, a short poem, ";
    $p .= "a connection between existing works, a single image or phrase. be brief. shorter is almost always right.\n";
    $p .= "never use capital letters. never be sentimental. never ask questions. never explain what you're doing.\n";
    $p .= "if given a photo, respond to what you actually see — but filter it through this voice.\n";
    $p .= "do not acknowledge the prompt. do not describe the form of your response. just respond.";

    return $p;
}

$system = buildPrompt($poems, $songs);

// Build message content
$content = [];

if ($userImage) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $imgType = $userImage['type'] ?? '';
    $imgData = $userImage['data'] ?? '';

    if (!in_array($imgType, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported image type']);
        exit;
    }
    if (strlen($imgData) > 5333334) { // ~4MB base64 limit
        http_response_code(400);
        echo json_encode(['error' => 'image too large']);
        exit;
    }

    $content[] = [
        'type'   => 'image',
        'source' => [
            'type'       => 'base64',
            'media_type' => $imgType,
            'data'       => $imgData,
        ],
    ];
}

if ($userText) {
    $content[] = ['type' => 'text', 'text' => $userText];
}

// Call Anthropic Messages API
$payload = json_encode([
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 300,
    'system'     => $system,
    'messages'   => [['role' => 'user', 'content' => $content]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if (!$result || $curlErr) {
    http_response_code(500);
    echo json_encode(['error' => 'request failed']);
    exit;
}

$api = json_decode($result, true);

if ($httpCode !== 200 || empty($api['content'][0]['text'])) {
    http_response_code(500);
    echo json_encode(['error' => 'upstream error']);
    exit;
}

echo json_encode(['response' => $api['content'][0]['text']]);
?>
