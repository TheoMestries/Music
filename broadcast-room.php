<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';

function musicBroadcastPath(string $room): string
{
    return MUSIC_DATA_DIR . '/music-room-' . $room . '.json';
}

function musicPublicBroadcast(string $room, string $owner): array
{
    $state = musicReadJson(musicBroadcastPath($room));
    if (($state['updatedAt'] ?? 0) < time() - 30) {
        return ['live' => false, 'tracks' => []];
    }
    $campaign = musicFindCampaign(musicReadJson(MUSIC_PROJECT_FILE), $owner, $state['campaignId'] ?? null);
    if ($campaign === null) {
        return ['live' => false, 'tracks' => []];
    }
    $files = musicMediaList([$campaign])['audio'];
    return [
        'live' => true,
        'campaignName' => $campaign['name'],
        'updatedAt' => $state['updatedAt'],
        'tracks' => array_values(array_filter($state['tracks'], static fn(array $track): bool => isset($files[$track['file']]))),
    ];
}

function musicBroadcastTracks(mixed $tracks, array $files): array
{
    if (!is_array($tracks)) {
        return [];
    }

    $result = [];
    foreach (array_slice($tracks, 0, 12) as $track) {
        $file = musicFileName($track['file'] ?? null);
        if ($file === null || !isset($files[$file])) {
            continue;
        }
        $result[] = [
            'instanceId' => musicBroadcastText($track['instanceId'] ?? null, 80),
            'trackId' => musicBroadcastText($track['trackId'] ?? null, 80),
            'title' => musicBroadcastText($track['title'] ?? null, 160) ?? 'Musique en direct',
            'file' => $file,
            'currentTime' => musicBroadcastNumber($track['currentTime'] ?? null, 86400, 0),
            'duration' => musicBroadcastNumber($track['duration'] ?? null, 86400, null),
            'volume' => musicBroadcastNumber($track['volume'] ?? null, 1, 1),
        ];
    }
    return $result;
}

function musicBroadcastText(mixed $value, int $limit): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr(trim($value), 0, $limit, 'UTF-8') : substr(trim($value), 0, $limit);
}

function musicBroadcastNumber(mixed $value, float $max, ?float $fallback): ?float
{
    return is_numeric($value) ? min(max((float) $value, 0), $max) : $fallback;
}
