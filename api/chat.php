<?php
// api/chat.php — proxies chat messages to the n8n webhook (server-side to avoid CORS)
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth_guard.php';
header('Content-Type: application/json');

define('N8N_CHAT_WEBHOOK_URL', 'https://n8n.rijanshresthataujale.com.np/webhook/inventorywebsite');

$user    = currentUser();
$body    = json_decode(file_get_contents('php://input'), true);
$message = trim($body['message']    ?? '');
$session = trim($body['session_id'] ?? '');

if (!$message) {
    echo json_encode(['success' => false, 'message' => 'Message is required.']); exit;
}

$payload = json_encode([
    'chatInput' => $message,
    'sessionId' => $session ?: ('user-' . ($user['id'] ?? 'guest')),
    'userId'    => $user['id'] ?? null,
    'userName'  => $user['name'] ?? null,
]);

$ch = curl_init(N8N_CHAT_WEBHOOK_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    echo json_encode(['success' => false, 'message' => 'Could not reach the chat assistant.' . ($curlErr ? " ($curlErr)" : " (HTTP $httpCode)")]);
    exit;
}

// n8n workflows commonly respond with one of these shapes — try to find the reply text
$decoded = json_decode($response, true);
$reply   = null;
if (is_array($decoded)) {
    $node  = isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : $decoded;
    $reply = $node['output'] ?? $node['reply'] ?? $node['message'] ?? $node['text'] ?? null;
}
if ($reply === null) {
    $reply = is_string($response) && trim($response) !== '' ? $response : 'No response from the assistant.';
}

echo json_encode(['success' => true, 'reply' => $reply]);
