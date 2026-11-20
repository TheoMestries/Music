<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';

$owner = requireLogin();
session_write_close();
$name = musicFileName($_GET['file'] ?? null);
$campaigns = musicOwnedCampaigns(musicReadJson(MUSIC_PROJECT_FILE), $owner);
if ($name === null || !isset(musicMediaList($campaigns)['images'][$name])) {
    http_response_code(404);
    exit;
}

$path = musicMediaPath('images', $name);
$type = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    default => null,
};
if ($type === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $type);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
