<?php
declare(strict_types=1);

require_once __DIR__ . '/broadcast-room.php';

$fileName = musicFileName($_GET['file'] ?? null);
if ($fileName === null) {
    http_response_code(404);
    exit;
}

if (isset($_GET['room'])) {
    $room = musicResolveRoom($_GET['room']);
    $allowed = $room !== null && in_array($fileName, array_column(musicPublicBroadcast($room['room'], $room['owner'])['tracks'], 'file'), true);
} else {
    $owner = requireLogin()['user_id'];
    session_write_close();
    $campaigns = musicOwnedCampaigns(musicReadJson(MUSIC_PROJECT_FILE), $owner);
    $allowed = isset(musicMediaList($campaigns)['audio'][$fileName]);
}
if (!$allowed) {
    http_response_code(404);
    exit;
}

$path = musicMediaPath('audio', $fileName);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = [
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'oga' => 'audio/ogg',
    'opus' => 'audio/ogg',
    'm4a' => 'audio/mp4',
    'mp4' => 'audio/mp4',
    'aac' => 'audio/aac',
    'flac' => 'audio/flac',
    'webm' => 'audio/webm',
    'aif' => 'audio/aiff',
    'aiff' => 'audio/aiff',
    'wma' => 'audio/x-ms-wma',
    'mid' => 'audio/midi',
    'midi' => 'audio/midi',
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
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

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
