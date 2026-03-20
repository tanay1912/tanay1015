<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/lib/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
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
$stats = $repo->dashboardStats();
$keywords = $repo->aggregateTopKeywordCounts(10);

$avgSent = $stats['avg_sentiment_index'] ?? null;
$avgSent = $avgSent !== null && $avgSent !== '' ? round((float) $avgSent, 4) : null;
$avgScr = $stats['avg_score'] ?? null;
$avgScr = $avgScr !== null && $avgScr !== '' ? round((float) $avgScr, 4) : null;
$avgDur = $stats['avg_duration'] ?? null;
$avgDur = $avgDur !== null && $avgDur !== '' ? round((float) $avgDur, 4) : null;

echo json_encode([
    'ok' => true,
    'stats' => [
        'total_calls' => (int) ($stats['total_calls'] ?? 0),
        'avg_sentiment_index' => $avgSent,
        'avg_score' => $avgScr,
        'avg_duration_seconds' => $avgDur,
        'action_items_total' => (int) ($stats['action_items_total'] ?? 0),
    ],
    'keywords' => array_values($keywords),
], JSON_UNESCAPED_UNICODE);
