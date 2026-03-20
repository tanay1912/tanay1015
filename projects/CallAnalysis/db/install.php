<?php

declare(strict_types=1);

/**
 * One-time schema install. Safe to run multiple times (CREATE IF NOT EXISTS).
 * CLI: php projects/CallAnalysis/db/install.php
 * Web: /projects/CallAnalysis/db/install.php
 */

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap.php';

$sql = file_get_contents($root . '/schema.sql');
if ($sql === false) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "schema.sql not found\n");
    }
    exit(1);
}

$pdo = ca_db();
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$parts = array_map('trim', explode(';', $sql));
foreach ($parts as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $pdo->exec($stmt);
}

if (php_sapi_name() === 'cli') {
    echo "Schema applied.\n";
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
    <title>Schema installed — Call Analysis</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body class="install-page">
    <header class="header header--dense">
        <nav>
            <a href="/">Projects</a>
            <a href="<?= htmlspecialchars($home) ?>">Call Analysis</a>
        </nav>
        <span class="domain">Database</span>
    </header>
    <div class="install-shell">
        <div class="install-card">
            <h1>Schema applied</h1>
            <p>Call Analysis tables are ready. You can close this page and return to the dashboard.</p>
            <p class="success" style="margin:0">All <code>ca_*</code> tables were created or already existed.</p>
            <p style="margin-top:1.25rem; margin-bottom:0">
                <a href="<?= htmlspecialchars($home) ?>" style="color:var(--color-accent-hover); font-weight:600">Go to dashboard →</a>
            </p>
        </div>
    </div>
</body>
</html>
