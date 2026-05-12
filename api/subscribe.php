<?php
/**
 * Vanlectric subscribe endpoint.
 *
 * Receives JSON { "email": "...", "source": "..." } via POST and forwards
 * the address to MailerLite group 186263385902417862. The MailerLite API
 * key is loaded from a secrets file located OUTSIDE the public web root,
 * so it never ends up in the GitHub repository, the production repo, the
 * static dist bundle, or any frontend code.
 */

declare(strict_types=1);

// ---------- CORS ----------------------------------------------------------
$allowedOrigins = [
    'https://vanlectric.com',
    'https://www.vanlectric.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Reject non-POST (incl. GET)
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---------- Parse body ----------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$email  = isset($data['email'])  && is_string($data['email'])  ? trim($data['email'])  : '';
$source = isset($data['source']) && is_string($data['source']) ? trim($data['source']) : '';

if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email']);
    exit;
}
if (strlen($source) > 100) {
    $source = substr($source, 0, 100);
}

// ---------- Load secret ---------------------------------------------------
$secretsPath = '/home/sites/27a/5/51ff282fd6/vanlectric_secrets.php';
if (!is_file($secretsPath) || !is_readable($secretsPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}

$config = require $secretsPath;
$apiKey = is_array($config) && !empty($config['MAILERLITE_API_KEY'])
    ? (string) $config['MAILERLITE_API_KEY']
    : '';

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}

// ---------- Call MailerLite ----------------------------------------------
$groupId = '186263385902417862';
$payload = [
    'email'  => $email,
    'groups' => [$groupId],
    'fields' => [
        'source' => $source !== '' ? $source : 'Vanlectric',
    ],
];

$ch = curl_init('https://connect.mailerlite.com/api/subscribers');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response   = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr    = curl_error($ch);
curl_close($ch);

// Server-side log only; never returned to client.
if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
    error_log(sprintf(
        '[vanlectric subscribe] MailerLite failure status=%d curlErr=%s body=%s',
        $httpStatus,
        $curlErr,
        is_string($response) ? substr($response, 0, 500) : ''
    ));
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Subscription failed']);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true]);
