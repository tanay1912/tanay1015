<?php

declare(strict_types=1);

/**
 * Validate and store an uploaded recording; enqueue for processing.
 *
 * @return array{ok: true, call_id: int, call: array<string, mixed>}|array{ok: false, error: string}
 */
function ca_handle_recording_upload(PDO $pdo): array
{
    $f = $_FILES['recording'] ?? null;
    if (!is_array($f)) {
        return ['ok' => false, 'error' => 'Please choose an audio file.'];
    }

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose an audio file.'];
    }

    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (code ' . (int) ($f['error'] ?? 0) . ').'];
    }

    $tmp = (string) $f['tmp_name'];
    $orig = (string) ($f['name'] ?? 'recording');
    $mime = (string) ($f['type'] ?? 'application/octet-stream');
    $size = (int) ($f['size'] ?? 0);
    $openAiMax = 24 * 1024 * 1024;

    if ($size > $openAiMax) {
        return ['ok' => false, 'error' => 'File is too large for OpenAI transcription (max ~24 MB). Trim or compress the audio.'];
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['mp3', 'wav', 'm4a', 'mp4', 'webm', 'mpeg', 'mpga', 'oga', 'ogg'];
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Unsupported format. Use mp3, wav, m4a, webm, or similar.'];
    }

    if (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $destDir = ca_storage_dir();
    $destAbs = $destDir . '/' . $name;

    if (!move_uploaded_file($tmp, $destAbs)) {
        return ['ok' => false, 'error' => 'Could not save file.'];
    }

    $rel = 'storage/uploads/' . $name;
    $repo = new CallRepository($pdo);
    $callId = $repo->createCall($orig, $rel, $mime, $size);

    try {
        ca_redis()->lPush(ca_queue_key(), (string) $callId);
    } catch (Throwable $e) {
        $repo->updateStatus($callId, 'failed', 'Queue unavailable: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Saved file but could not queue job. Is Redis running? ' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'call_id' => $callId,
        'call' => [
            'id' => $callId,
            'original_filename' => $orig,
            'status' => 'pending',
            'overall_score' => null,
            'sentiment' => null,
        ],
    ];
}
