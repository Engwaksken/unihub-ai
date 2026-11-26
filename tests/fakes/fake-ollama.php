<?php
// Minimal Ollama stand-in for local smoke/load tests (NOT for production).
// Run: php -S 127.0.0.1:11999 tests/fakes/fake-ollama.php
// Env FAKE_OLLAMA_DELAY_MS simulates inference time (default 300).
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen2.5:1.5b', 'digest' => 'sha256:fake', 'size' => 986000000]]]);
    return;
}
if ($path === '/api/ps') {
    echo json_encode(['models' => [['name' => 'qwen2.5:1.5b']]]);
    return;
}
if ($path === '/api/chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    usleep(((int) (getenv('FAKE_OLLAMA_DELAY_MS') ?: 300)) * 1000);
    $user = $body['messages'][1]['content'] ?? '';
    echo json_encode([
        'model' => $body['model'] ?? '',
        'message' => ['role' => 'assistant', 'content' => '08:00 Review notes ('.strlen($user).' chars received)'],
        'done' => true,
        'prompt_eval_count' => 20 + intdiv(strlen($user), 4),
        'eval_count' => 14,
    ]);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'not found']);
