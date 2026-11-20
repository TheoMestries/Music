<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/campaigns.php';

$username = $argv[1] ?? '';
$apply = ($argv[2] ?? null) === '--apply';
if ($username === '' || count($argv) > 3 || (count($argv) === 3 && !$apply)) {
    fwrite(STDERR, "Usage: php bin/recover-audio.php IDENTIFIANT_SUS20 [--apply]\n");
    exit(1);
}

try {
    $user = mapFindUserByUsername($username);
    if ($user === null || (int) $user['is_active'] !== 1) {
        throw new RuntimeException('Compte Sus20 actif introuvable.');
    }

    $owner = (int) $user['id'];
    $library = musicReadJson(MUSIC_PROJECT_FILE);
    if (!($library['legacyAdminMigrated'] ?? false)) {
        throw new RuntimeException('Ouvre d’abord la bibliothèque SuSZic pour terminer sa migration.');
    }
    $files = unreferencedAudioFiles($library);
    printf("%d fichiers audio sans projet. Compte destinataire : %s (n°%d).\n", count($files), $user['username'], $owner);
    if (!$apply || $files === []) {
        exit;
    }

    $tracks = [];
    foreach ($files as $index => $file) {
        $path = musicMediaPath('audio', $file);
        $tracks[$file] = [
            'id' => bin2hex(random_bytes(16)),
            'title' => recoveredAudioTitle($path) ?? (preg_match('/\A[0-9a-f-]{32,36}\z/i', pathinfo($file, PATHINFO_FILENAME))
                ? sprintf('Piste %03d', $index + 1)
                : pathinfo($file, PATHINFO_FILENAME)),
            'fileName' => $file,
            'fileSize' => filesize($path) ?: 0,
            'audioUrl' => 'uploads/audio/' . $file,
            'loop' => false,
            'volume' => 1,
        ];
    }

    $result = musicCampaignTransaction(static function (array &$current) use ($tracks, $owner): ?array {
        $available = array_intersect_key($tracks, array_fill_keys(unreferencedAudioFiles($current), true));
        if ($available === []) {
            return null;
        }

        $backup = MUSIC_DATA_DIR . '/music-recovery-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        if (!copy(MUSIC_PROJECT_FILE, $backup)) {
            throw new RuntimeException('Impossible de créer la sauvegarde.');
        }

        $date = gmdate(DATE_ATOM);
        $playlists = [];
        foreach (array_chunk($available, 50) as $index => $batch) {
            $playlists[] = [
                'id' => bin2hex(random_bytes(16)),
                'name' => 'Musiques récupérées ' . ($index + 1),
                'tracks' => $batch,
            ];
        }
        $current['projects'][] = [
            'owner' => $owner,
            'campaign' => [
                'id' => bin2hex(random_bytes(16)),
                'name' => 'Bibliothèque récupérée',
                'createdAt' => $date,
                'updatedAt' => $date,
                'playlists' => $playlists,
                'tracks' => [],
                'folders' => [],
            ],
        ];

        return ['count' => count($available), 'backup' => $backup];
    });
    if ($result === null) {
        echo "Aucun fichier à rattacher.\n";
    } else {
        printf("Bibliothèque récupérée : %d pistes. Sauvegarde : %s\n", $result['count'], $result['backup']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

function unreferencedAudioFiles(array $library): array
{
    $used = musicMediaList(array_column($library['projects'] ?? [], 'campaign'))['audio'];
    $used += $library['pending']['audio'] ?? [];
    $files = [];
    foreach (new DirectoryIterator(musicMediaPath('audio', '')) as $entry) {
        $name = musicFileName($entry->getFilename());
        if ($name !== null && $entry->isFile() && !$entry->isLink() && !isset($used[$name])
            && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'mp4', 'aac', 'flac', 'webm', 'opus', 'aif', 'aiff', 'wma', 'mid', 'midi', 'audio'], true)) {
            $files[] = $name;
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function recoveredAudioTitle(string $path): ?string
{
    static $available = null;
    if (!function_exists('proc_open')) {
        return null;
    }
    if ($available === null) {
        $available = runAudioProbe(['-version']) !== null;
    }
    if (!$available) {
        return null;
    }

    $data = runAudioProbe(['-v', 'error', '-show_entries', 'format_tags=title', '-of', 'json', $path]);
    $tags = json_decode($data ?? '', true)['format']['tags'] ?? [];
    foreach ($tags as $key => $value) {
        if (strcasecmp($key, 'title') === 0 && is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return null;
}

function runAudioProbe(array $args): ?string
{
    $process = @proc_open(['ffprobe', ...$args], [1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return proc_close($process) === 0 && is_string($output) ? $output : null;
}
