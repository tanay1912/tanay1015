<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/lib/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$callId = (int) ($_POST['call_id'] ?? 0);
$email = trim((string) ($_POST['email'] ?? ''));

if ($callId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid call.']);
    exit;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

try {
    $pdo = ca_db();
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.']);
    exit;
}

try {
    $pdo->query('SELECT 1 FROM ca_calls LIMIT 1');
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database is not ready.']);
    exit;
}

$repo = new CallRepository($pdo);
$call = $repo->getCall($callId);
if (!$call) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Call not found.']);
    exit;
}

if (($call['status'] ?? '') !== 'ready') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Summary can only be sent for completed calls.']);
    exit;
}

$analysis = $repo->getAnalysis($callId);
if (!$analysis) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No analysis is available for this call yet.']);
    exit;
}

$filename = (string) ($call['original_filename'] ?? 'Recording');
$summary = trim((string) ($analysis['summary'] ?? ''));
if ($summary === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'This call has no summary text to send.']);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$callPath = '/projects/CallAnalysis/call.php?id=' . $callId;
$callUrl = $scheme . '://' . $host . $callPath;

$score = $analysis['overall_score'] !== null
    ? number_format((float) $analysis['overall_score'], 1) . ' / 10'
    : '—';

$body = "Call analysis summary\r\n";
$body .= str_repeat('=', 44) . "\r\n\r\n";
$body .= "File: {$filename}\r\n";
$body .= "Call ID: {$callId}\r\n";
$body .= "Open in app: {$callUrl}\r\n\r\n";
$body .= "--- Summary ---\r\n\r\n";
$body .= $summary . "\r\n\r\n";
$body .= "--- Details ---\r\n\r\n";
$body .= 'Purpose: ' . trim((string) ($analysis['purpose'] ?? '—')) . "\r\n";
$body .= 'Main topics: ' . trim((string) ($analysis['main_topics'] ?? '—')) . "\r\n";
$body .= 'Outcome: ' . trim((string) ($analysis['outcome'] ?? '—')) . "\r\n\r\n";
$body .= 'Sentiment: ' . ucfirst((string) ($analysis['sentiment'] ?? 'neutral')) . "\r\n";
$body .= 'Overall score: ' . $score . "\r\n";

$subject = 'Call analysis summary: ' . $filename;
if (function_exists('mb_encode_mimeheader') && !preg_match('/^[\x20-\x7E]+$/u', $subject)) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}

$from = getenv('CALL_ANALYSIS_MAIL_FROM') ?: 'noreply@local.vibecode.com';

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: Call Analysis <' . $from . '>',
];

$headerStr = implode("\r\n", $headers);

$sent = @mail($email, $subject, $body, $headerStr);
if (!$sent) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not send email. Check server mail configuration.']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Summary sent to ' . $email], JSON_UNESCAPED_UNICODE);
