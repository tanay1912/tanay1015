<?php

$dbHost = getenv('DB_HOST') ?: 'db';
$dbName = getenv('MYSQL_DATABASE') ?: 'vibecode';
$dbUser = getenv('MYSQL_USER') ?: 'root';
$dbPass = getenv('MYSQL_PASSWORD') ?: 'server';

function getDb(): PDO
{
    global $dbHost, $dbName, $dbUser, $dbPass;
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
