<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$stateFile = $dataDir . DIRECTORY_SEPARATOR . 'broadcast.json';
ensureBroadcastDirectory($dataDir);

$action = $_GET['action'] ?? 'state';

try {
    if ($action === 'health') {
        respond([
            'success' => true,
            'phpVersion' => PHP_VERSION,
            'dataDir' => [
                'exists' => is_dir($dataDir),
                'writable' => is_writable($dataDir),
            ],
            'stateFile' => [
                'exists' => is_file($stateFile),
                'writable' => !is_file($stateFile) || is_writable($stateFile),
            ],
            'state' => normalizeBroadcastState(readBroadcastState($stateFile)),
        ]);
    }

    if ($action === 'state') {
        respond(normalizeBroadcastState(readBroadcastState($stateFile)));
    }

    if ($action === 'update') {
        requireApiLogin();
        $payload = readBroadcastJsonBody();
        $current = readBroadcastState($stateFile);
        $state = buildBroadcastState($payload, $current);
        writeBroadcastState($stateFile, $state);
        respond($state);
    }

    if ($action === 'stop') {
        requireApiLogin();
        $state = normalizeBroadcastState(readBroadcastState($stateFile));
        $state['live'] = false;
        $state['isPlaying'] = false;
        $state['tracks'] = [];
        $state['updatedAt'] = time();
        writeBroadcastState($stateFile, $state);
        respond($state);
    }

    failBroadcast('Action inconnue.', 404);
} catch (Throwable $error) {
    failBroadcast($error->getMessage(), 500);
}

function ensureBroadcastDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Impossible de creer le dossier data.');
    }
}

function readBroadcastState(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return defaultBroadcastState();
    }

    $content = file_get_contents($stateFile);
    $decoded = json_decode($content ?: '{}', true);
    return is_array($decoded) ? $decoded : defaultBroadcastState();
}

function defaultBroadcastState(): array
{
    return [
        'live' => false,
        'isPlaying' => false,
        'trackId' => null,
        'title' => null,
        'campaignName' => null,
        'file' => null,
        'currentTime' => 0,
        'duration' => null,
        'volume' => 1,
        'tracks' => [],
        'updatedAt' => time(),
    ];
}

function normalizeBroadcastState(array $state): array
{
    $normalized = [
        ...defaultBroadcastState(),
        ...$state,
        'live' => filter_var($state['live'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'isPlaying' => filter_var($state['isPlaying'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'currentTime' => normalizeBroadcastNumber($state['currentTime'] ?? 0, 0, 86400, 0),
        'duration' => isset($state['duration']) ? normalizeBroadcastNumber($state['duration'], 0, 86400, null) : null,
        'volume' => normalizeBroadcastNumber($state['volume'] ?? 1, 0, 1, 1),
        'tracks' => normalizeBroadcastTracks($state['tracks'] ?? []),
        'updatedAt' => (int) ($state['updatedAt'] ?? time()),
    ];

    if ($normalized['live'] && time() - $normalized['updatedAt'] > 30) {
        $normalized['live'] = false;
        $normalized['isPlaying'] = false;
        $normalized['tracks'] = [];
    }

    return $normalized;
}

function buildBroadcastState(array $payload, array $current): array
{
    $state = normalizeBroadcastState($current);
    $file = array_key_exists('file', $payload)
        ? sanitizeBroadcastFile($payload['file'])
        : sanitizeBroadcastFile($state['file']);

    if (isset($payload['live'])) {
        $state['live'] = filter_var($payload['live'], FILTER_VALIDATE_BOOLEAN);
    }

    $state['isPlaying'] = filter_var($payload['isPlaying'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $state['campaignName'] = sanitizeBroadcastText($payload['campaignName'] ?? $state['campaignName'] ?? null, 160);
    $state['trackId'] = sanitizeBroadcastText($payload['trackId'] ?? null, 80);
    $state['title'] = sanitizeBroadcastText($payload['title'] ?? null, 160);
    $state['file'] = $file;
    $state['currentTime'] = normalizeBroadcastNumber($payload['currentTime'] ?? 0, 0, 86400, 0);
    $state['duration'] = isset($payload['duration']) ? normalizeBroadcastNumber($payload['duration'], 0, 86400, null) : null;
    $state['volume'] = normalizeBroadcastNumber($payload['volume'] ?? 1, 0, 1, 1);
    $state['tracks'] = normalizeBroadcastTracks($payload['tracks'] ?? []);
    $state['updatedAt'] = time();

    if (!$state['live']) {
        $state['isPlaying'] = false;
        $state['tracks'] = [];
        clearCurrentBroadcastTrack($state);
    } elseif ($state['tracks'] !== []) {
        $firstTrack = $state['tracks'][0];
        $state['isPlaying'] = true;
        $state['trackId'] = $firstTrack['trackId'];
        $state['title'] = $firstTrack['title'];
        $state['file'] = $firstTrack['file'];
        $state['currentTime'] = $firstTrack['currentTime'];
        $state['duration'] = $firstTrack['duration'];
        $state['volume'] = $firstTrack['volume'];
    } else {
        $state['isPlaying'] = false;
        clearCurrentBroadcastTrack($state);
    }

    return $state;
}

function clearCurrentBroadcastTrack(array &$state): void
{
    $state['trackId'] = null;
    $state['title'] = null;
    $state['file'] = null;
    $state['currentTime'] = 0;
    $state['duration'] = null;
    $state['volume'] = 1;
}

function normalizeBroadcastTracks(mixed $tracks): array
{
    if (!is_array($tracks)) {
        return [];
    }

    $normalized = [];
    foreach ($tracks as $track) {
        if (!is_array($track)) {
            continue;
        }

        $file = sanitizeBroadcastFile($track['file'] ?? null);
        if ($file === null) {
            continue;
        }

        $normalized[] = [
            'instanceId' => sanitizeBroadcastText($track['instanceId'] ?? null, 80),
            'trackId' => sanitizeBroadcastText($track['trackId'] ?? null, 80),
            'title' => sanitizeBroadcastText($track['title'] ?? null, 160) ?? 'Musique en direct',
            'file' => $file,
            'currentTime' => normalizeBroadcastNumber($track['currentTime'] ?? 0, 0, 86400, 0),
            'duration' => isset($track['duration']) ? normalizeBroadcastNumber($track['duration'], 0, 86400, null) : null,
            'volume' => normalizeBroadcastNumber($track['volume'] ?? 1, 0, 1, 1),
        ];
    }

    return array_slice($normalized, 0, 12);
}

function sanitizeBroadcastFile(mixed $value): ?string
{
    $file = basename((string) $value);
    return preg_match('/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$/', $file) ? $file : null;
}

function sanitizeBroadcastText(mixed $value, int $maxLength): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
}

function normalizeBroadcastNumber(mixed $value, float $min, float $max, mixed $fallback): mixed
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    return min(max((float) $value, $min), $max);
}

function readBroadcastJsonBody(): array
{
    $content = file_get_contents('php://input');
    $decoded = json_decode($content ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function writeBroadcastState(string $stateFile, array $state): void
{
    $written = file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Impossible d\'ecrire dans data/broadcast.json.');
    }
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function failBroadcast(string $message, int $status): void
{
    http_response_code($status);
    respond([
        'success' => false,
        'error' => $message,
    ]);
}
