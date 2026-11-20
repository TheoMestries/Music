<?php
declare(strict_types=1);

require_once __DIR__ . '/music-accounts.php';

function musicCampaignTransaction(callable $update): mixed
{
    return musicUpdateJson(MUSIC_PROJECT_FILE, static function (array &$library) use ($update): mixed {
        musicMigrateLegacyAdmin($library);
        return $update($library);
    }, 'musicRemoveUnusedMedia');
}

function musicOwnedCampaigns(array $library, int $owner): array
{
    return array_values(array_map(
        static fn(array $project): array => $project['campaign'],
        array_filter($library['projects'] ?? [], static fn(array $project): bool => $project['owner'] === $owner)
    ));
}

function musicCampaignResponse(array $campaigns): array
{
    return [
        'campaigns' => $campaigns,
        'revision' => hash('sha256', json_encode($campaigns, JSON_THROW_ON_ERROR)),
    ];
}

function musicFindCampaign(array $library, int $owner, mixed $id): ?array
{
    foreach ($library['projects'] ?? [] as $project) {
        if ($project['owner'] === $owner && $project['campaign']['id'] === $id) {
            return $project['campaign'];
        }
    }
    return null;
}

function validMusicProjectId(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $id) === 1;
}

function musicFileName(mixed $name): ?string
{
    return is_string($name) && preg_match('/\A[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+\z/', $name) ? $name : null;
}

function musicMediaPath(string $kind, string $name): string
{
    return __DIR__ . '/uploads/' . $kind . '/' . $name;
}

function musicMediaList(array $campaigns): array
{
    $media = ['audio' => [], 'images' => []];
    $add = static function (mixed $url, string $kind) use (&$media): void {
        $prefix = 'uploads/' . $kind . '/';
        if ($url === null || $url === '') {
            return;
        }
        if (is_string($url) && str_starts_with($url, $prefix) && ($name = musicFileName(substr($url, strlen($prefix)))) !== null) {
            $media[$kind][$name] = true;
            return;
        }
        if (!is_string($url) || !preg_match('~\Ahttps?://~i', $url)) {
            throw new DomainException('Adresse de fichier invalide.');
        }
    };
    $addTracks = static function (array $container) use ($add): void {
        foreach (musicProjectItems($container, 'tracks') as $track) {
            $add($track['audioUrl'] ?? null, 'audio');
        }
    };
    $addPlaylists = static function (array $container) use ($add, $addTracks): void {
        foreach (musicProjectItems($container, 'playlists') as $playlist) {
            $add($playlist['imageUrl'] ?? null, 'images');
            $addTracks($playlist);
        }
    };

    foreach ($campaigns as $campaign) {
        $addTracks($campaign);
        $addPlaylists($campaign);
        foreach (musicProjectItems($campaign, 'folders') as $folder) {
            $addPlaylists($folder);
        }
    }
    return $media;
}

function musicProjectItems(array $container, string $key): array
{
    $items = $container[$key] ?? [];
    if (!is_array($items) || !array_is_list($items)) {
        throw new DomainException('Structure du projet audio invalide.');
    }
    foreach ($items as $item) {
        if (!is_array($item) || !validMusicProjectId($item['id'] ?? null)) {
            throw new DomainException('Élément du projet audio invalide.');
        }
    }
    return $items;
}

function musicRemoveUnusedMedia(array $before, array $after): void
{
    if (($before['projects'] ?? []) === ($after['projects'] ?? [])) {
        return;
    }
    $previous = musicMediaList(array_column($before['projects'] ?? [], 'campaign'));
    $remaining = musicMediaList(array_column($after['projects'] ?? [], 'campaign'));
    foreach ($previous as $kind => $files) {
        foreach (array_diff_key($files, $remaining[$kind]) as $name => $_) {
            $path = musicMediaPath($kind, $name);
            if (is_file($path) && !@unlink($path)) {
                error_log('SuSZic : impossible de supprimer ' . $path);
            }
        }
    }
}
