<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$fileName = basename((string) ($_GET['file'] ?? ''));
if ($fileName === '' || !preg_match('/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$/', $fileName)) {
    http_response_code(404);
    exit;
}

$isBroadcastRequest = ($_GET['broadcast'] ?? '') === '1';
if ($isBroadcastRequest) {
    if (!isBroadcastFileAllowed($fileName)) {
        http_response_code(404);
        exit;
    }
} else {
    requireLogin();
}
session_write_close();

$path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'audio' . DIRECTORY_SEPARATOR . $fileName;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = [
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4',
    'aac' => 'audio/aac',
    'flac' => 'audio/flac',
    'webm' => 'audio/webm',
];
$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
$fileSize = filesize($path);
if ($fileSize === false) {
    http_response_code(500);
    exit;
}

$start = 0;
$end = $fileSize - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches)) {
    if ($matches[1] === '' && $matches[2] !== '') {
        $suffixLength = min((int) $matches[2], $fileSize);
        $start = $fileSize - $suffixLength;
    } elseif ($matches[1] !== '') {
        $start = (int) $matches[1];
        if ($matches[2] !== '') {
            $end = (int) $matches[2];
        }
    }

    $start = max(0, min($start, $fileSize - 1));
    $end = max($start, min($end, $fileSize - 1));
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
}

$length = $end - $start + 1;
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Cache-Control: private, max-age=3600');

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, (int) min(8192, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);

function isBroadcastFileAllowed(string $fileName): bool
{
    $stateFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'broadcast.json';
    if (!is_file($stateFile)) {
        return false;
    }

    $content = file_get_contents($stateFile);
    $state = json_decode($content ?: '{}', true);
    if (!is_array($state)) {
        return false;
    }

    $updatedAt = (int) ($state['updatedAt'] ?? 0);
    if (($state['live'] ?? false) !== true || time() - $updatedAt > 30) {
        return false;
    }

    if (($state['file'] ?? null) === $fileName) {
        return true;
    }

    foreach (($state['tracks'] ?? []) as $track) {
        if (is_array($track) && ($track['file'] ?? null) === $fileName) {
            return true;
        }
    }

    return false;
}
