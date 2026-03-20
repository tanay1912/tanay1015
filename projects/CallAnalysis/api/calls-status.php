<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/lib/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = $_GET['ids'] ?? '';
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
$ids = array_slice($ids, 0, 50);

if ($ids === []) {
    echo json_encode(['ok' => true, 'calls' => []]);
    exit;
}

try {
    $pdo = ca_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

try {
    $pdo->query('SELECT 1 FROM ca_calls LIMIT 1');
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Schema missing']);
    exit;
}

$repo = new CallRepository($pdo);
$calls = $repo->getCallsStatusForIds($ids);

echo json_encode(['ok' => true, 'calls' => $calls], JSON_UNESCAPED_UNICODE);
