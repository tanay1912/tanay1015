<?php

declare(strict_types=1);

/**
 * Re-queue a call for full transcription + analysis (same pipeline as new upload).
 * POST call_id. Requires Redis + worker.
 */

require_once __DIR__ . '/lib/init.php';

$baseUrl = '/projects/CallAnalysis';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Location: ' . $baseUrl . '/');
    exit;
}

$id = (int) ($_POST['call_id'] ?? 0);
if ($id <= 0) {
    http_response_code(302);
    header('Location: ' . $baseUrl . '/');
    exit;
}

try {
    $pdo = ca_db();
    $repo = new CallRepository($pdo);
    $call = $repo->getCall($id);
    if (!$call) {
        http_response_code(302);
        header('Location: ' . $baseUrl . '/');
        exit;
    }

    $redis = ca_redis();
    $repo->updateStatus($id, 'pending', null);
    $redis->lPush(ca_queue_key(), (string) $id);
} catch (Throwable) {
    http_response_code(302);
    header('Location: ' . $baseUrl . '/call.php?id=' . $id . '&requeue=0');
    exit;
}

http_response_code(302);
header('Location: ' . $baseUrl . '/call.php?id=' . $id . '&requeue=1');
