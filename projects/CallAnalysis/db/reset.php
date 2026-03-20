<?php

declare(strict_types=1);

/**
 * Truncate all Call Analysis tables, clear the Redis job queue, and remove uploaded audio files.
 *
 * CLI:
 *   docker compose exec fpm php /var/www/html/projects/CallAnalysis/db/reset.php
 *
 * Web (POST confirm=RESET):
 *   /projects/CallAnalysis/db/reset.php
 */

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap.php';

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['confirm'] ?? '') !== 'RESET') {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Call Analysis data</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="install-page">
    <header class="header header--dense">
        <nav>
            <a href="/">Projects</a>
            <a href="/projects/CallAnalysis/">Call Analysis</a>
        </nav>
        <span class="domain">Reset</span>
    </header>
    <div class="install-shell">
        <div class="install-card">
            <h1>Reset all Call Analysis data</h1>
            <p>This will <strong>permanently delete</strong> all calls, transcripts, analyses, action items, queued jobs in Redis, and files under <code>storage/uploads/</code>.</p>
            <form method="post" action="" class="form">
                <input type="hidden" name="confirm" value="RESET">
                <p>
                    <button type="submit" style="background:#b91c1c;border:none;color:#fff;padding:0.55rem 1.25rem;border-radius:var(--radius-sm,6px);font:inherit;font-weight:600;cursor:pointer">Yes, reset everything</button>
                    <a href="/projects/CallAnalysis/" style="margin-left:1rem;color:var(--color-accent-hover)">Cancel</a>
                </p>
            </form>
        </div>
    </div>
</body>
</html>
        <?php
        exit(0);
    }
}

try {
    $pdo = ca_db();
} catch (Throwable $e) {
    $msg = 'Database connection failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
    } else {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo $msg;
    }
    exit(1);
}

$tables = [
    'ca_action_items',
    'ca_agent_dimension_scores',
    'ca_analyses',
    'ca_transcript_segments',
    'ca_calls',
];

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $msg = 'Database reset failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
    } else {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo $msg;
    }
    exit(1);
}

$uploadDir = CA_ROOT . '/storage/uploads';
$filesRemoved = 0;
if (is_dir($uploadDir)) {
    foreach (scandir($uploadDir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '.gitignore') {
            continue;
        }
        $path = $uploadDir . '/' . $f;
        if (is_file($path) && @unlink($path)) {
            $filesRemoved++;
        }
    }
}

$redisOk = false;
$redisMsg = '';
try {
    $redis = ca_redis();
    $redis->del(ca_queue_key());
    $redisOk = true;
} catch (Throwable $e) {
    $redisMsg = 'Redis queue not cleared: ' . $e->getMessage();
}

if ($isCli) {
    echo "Call Analysis reset complete.\n";
    echo "- Truncated tables: " . implode(', ', $tables) . "\n";
    echo "- Removed {$filesRemoved} file(s) from storage/uploads/\n";
    echo $redisOk ? "- Redis queue cleared.\n" : "- {$redisMsg}\n";
    exit(0);
}

header('Content-Type: text/html; charset=utf-8');
$home = '/projects/CallAnalysis/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset complete</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="install-page">
    <header class="header header--dense">
        <nav>
            <a href="/">Projects</a>
            <a href="<?= htmlspecialchars($home) ?>">Call Analysis</a>
        </nav>
        <span class="domain">Done</span>
    </header>
    <div class="install-shell">
        <div class="install-card">
            <h1>Reset complete</h1>
            <p class="success" style="margin:0">All <code>ca_*</code> tables were truncated.</p>
            <p>Removed <strong><?= (int) $filesRemoved ?></strong> file(s) from uploads. <?= $redisOk ? 'Redis queue cleared.' : htmlspecialchars($redisMsg) ?></p>
            <p style="margin-bottom:0"><a href="<?= htmlspecialchars($home) ?>" style="color:var(--color-accent-hover); font-weight:600">Back to dashboard →</a></p>
        </div>
    </div>
</body>
</html>
