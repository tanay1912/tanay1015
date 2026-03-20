<?php

declare(strict_types=1);

/**
 * Queue worker: BRPOP call IDs from Redis, process transcription + analysis.
 * Run: php worker.php
 */

require_once __DIR__ . '/lib/init.php';

$key = ca_queue_key();
echo "Call Analysis worker listening on {$key}\n";

while (true) {
    try {
        $pdo = ca_db();
    } catch (Throwable $e) {
        fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
        sleep(5);
        continue;
    }

    try {
        $redis = ca_redis();
    } catch (Throwable $e) {
        fwrite(STDERR, "Redis connection failed: " . $e->getMessage() . "\n");
        sleep(5);
        continue;
    }

    $item = $redis->brPop([$key], 10);
    if ($item === false || !is_array($item) || count($item) < 2) {
        continue;
    }

    $callId = (int) $item[1];
    if ($callId <= 0) {
        continue;
    }

    echo date('c') . " Processing call {$callId}\n";
    $repo = new CallRepository($pdo);
    $client = new OpenAIClient(ca_openai_key());
    $processor = new CallProcessor($repo, $client);
    $processor->process($callId);
    echo date('c') . " Done call {$callId}\n";
}
