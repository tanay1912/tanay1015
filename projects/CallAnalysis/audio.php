<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/init.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Bad request');
}

$pdo = ca_db();
$repo = new CallRepository($pdo);
$call = $repo->getCall($id);
if (!$call) {
    http_response_code(404);
    exit('Not found');
}

$rel = (string) $call['stored_path'];
if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
    http_response_code(403);
    exit('Invalid path');
}

$full = realpath(CA_ROOT . '/' . $rel);
$base = realpath(ca_storage_dir());
if ($full === false || $base === false || !str_starts_with($full, $base)) {
    http_response_code(403);
    exit('Invalid path');
}

$mime = $call['mime'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($full);
