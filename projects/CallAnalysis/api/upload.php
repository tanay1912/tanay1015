<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/lib/init.php';
require_once dirname(__DIR__) . '/lib/upload_recording.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = ca_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

try {
    $pdo->query('SELECT 1 FROM ca_calls LIMIT 1');
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database tables are missing. Run db/install.php first.']);
    exit;
}

$result = ca_handle_recording_upload($pdo);

if ($result['ok']) {
    echo json_encode([
        'ok' => true,
        'call_id' => $result['call_id'],
        'message' => 'Recording queued for analysis. Call #' . $result['call_id'],
        'call' => $result['call'],
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'ok' => false,
        'error' => $result['error'],
    ], JSON_UNESCAPED_UNICODE);
}
