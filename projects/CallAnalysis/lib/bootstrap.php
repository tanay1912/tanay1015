<?php

declare(strict_types=1);

/**
 * Paths and DB for Call Analysis app.
 */

if (!defined('CA_ROOT')) {
    define('CA_ROOT', dirname(__DIR__));
}

require_once dirname(__DIR__, 3) . '/config/database.php';

function ca_db(): PDO
{
    return getDb();
}

/**
 * Decode a value from a MySQL/MariaDB JSON column. PDO usually returns a JSON string;
 * some setups may return an already-decoded array — never cast arrays to string.
 *
 * @return array<mixed>|null
 */
function ca_decode_json_column(mixed $raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function ca_redis(): Redis
{
    static $r = null;
    if ($r instanceof Redis) {
        return $r;
    }
    $host = getenv('REDIS_HOST') ?: 'redis';
    $port = (int) (getenv('REDIS_PORT') ?: '6379');
    $r = new Redis();
    $r->connect($host, $port, 2.5);
    return $r;
}

function ca_openai_key(): string
{
    $k = getenv('OPENAI_API_KEY') ?: '';
    return trim($k);
}

function ca_storage_dir(): string
{
    $dir = CA_ROOT . '/storage/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function ca_queue_key(): string
{
    return 'callanalysis:queue';
}
