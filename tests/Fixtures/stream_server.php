<?php

// Router for `php -S` used by provider HTTP tests. The first path segment
// picks the scenario:
//   /fail/...    → 401
//   /flaky/{id}  → 500 on the first request for {id}, then a normal response
//   /hang/...    → streams a first delta then stalls forever (never sends done)
//   /ok/...      → a normal response for the endpoint that was called
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

if (str_starts_with($path, '/fail')) {
    http_response_code(401);
    echo '{"error":"invalid api key"}';

    return;
}

if (str_starts_with($path, '/hang')) {
    header('Content-Type: text/event-stream');
    echo 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n";
    while (true) {
        usleep(100_000);
    }
}

if (preg_match('#^/flaky/([\w-]+)#', $path, $m)) {
    $marker = sys_get_temp_dir().'/lumina-flaky-'.$m[1];
    if (! file_exists($marker)) {
        touch($marker);
        http_response_code(500);
        echo '{"error":"temporarily unavailable"}';

        return;
    }
    $path = substr($path, strlen($m[0]));
}

if (str_ends_with($path, '/api/chat')) {
    if (($body['stream'] ?? true) === false) {
        echo json_encode(['model' => $body['model'], 'message' => ['content' => 'Hello world'], 'done_reason' => 'stop']);

        return;
    }
    echo json_encode(['message' => ['content' => 'Hello']])."\n";
    echo json_encode(['message' => ['content' => ' world'], 'done' => true]);

    return;
}

if (str_ends_with($path, '/api/embed')) {
    echo json_encode(['embeddings' => array_map(fn () => [0.1, 0.2], $body['input'])]);

    return;
}

if (str_contains($path, ':batchEmbedContents')) {
    if (isset($_GET['key']) || ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') !== 'gemini-key') {
        http_response_code(400);
        echo '{"error":"api key must be sent in the x-goog-api-key header"}';

        return;
    }
    echo json_encode(['embeddings' => array_map(fn () => ['values' => [0.3, 0.4]], $body['requests'])]);

    return;
}

if (str_ends_with($path, '/embeddings')) {
    // Return out of order to exercise index sorting.
    $data = [];
    foreach ($body['input'] as $i => $_) {
        $data[] = ['index' => $i, 'embedding' => [(float) $i]];
    }
    echo json_encode(['data' => array_reverse($data)]);

    return;
}

if (empty($body['stream'])) {
    echo json_encode([
        'model' => $body['model'],
        'choices' => [['message' => ['content' => 'Hello world'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
    ]);

    return;
}

header('Content-Type: text/event-stream');
echo 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n";
echo 'data: '.json_encode(['choices' => [['delta' => ['content' => ' world']]]])."\n\n";
echo "data: [DONE]\n\n";
