<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data';
$uploadDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'audio';
$dataFile = $dataDir . DIRECTORY_SEPARATOR . 'campaigns.json';

ensureDirectory($dataDir);
ensureDirectory($uploadDir);

$action = $_GET['action'] ?? '';

try {
    if ($action === 'load') {
        respond([
            'campaigns' => readCampaigns($dataFile),
        ]);
    }

    if ($action === 'save') {
        $payload = readJsonBody();
        $campaigns = $payload['campaigns'] ?? null;

        if (!is_array($campaigns)) {
            fail('Payload campaigns invalide.', 422);
        }

        file_put_contents(
            $dataFile,
            json_encode($campaigns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        respond(['success' => true]);
    }

    if ($action === 'upload') {
        $trackId = sanitizeId($_POST['trackId'] ?? '');
        if ($trackId === '') {
            fail('Identifiant de musique manquant.', 422);
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            fail('Upload audio invalide.', 422);
        }

        $file = $_FILES['audio'];
        if (!str_starts_with((string) $file['type'], 'audio/')) {
            fail('Le fichier doit être un fichier audio.', 422);
        }

        deleteAudioById($uploadDir, $trackId);

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'audio';
        }

        $fileName = $trackId . '.' . preg_replace('/[^a-z0-9]/', '', $extension);
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            fail('Impossible de sauvegarder le fichier audio.', 500);
        }

        respond([
            'success' => true,
            'url' => 'uploads/audio/' . $fileName,
        ]);
    }

    if ($action === 'deleteAudio') {
        $payload = readJsonBody();
        $trackId = sanitizeId($payload['trackId'] ?? '');
        if ($trackId === '') {
            fail('Identifiant de musique manquant.', 422);
        }

        deleteAudioById($uploadDir, $trackId);
        respond(['success' => true]);
    }

    fail('Action inconnue.', 404);
} catch (Throwable $error) {
    fail($error->getMessage(), 500);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function readCampaigns(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }

    $content = file_get_contents($dataFile);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function readJsonBody(): array
{
    $content = file_get_contents('php://input');
    $decoded = json_decode($content ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function sanitizeId(mixed $value): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value);
}

function deleteAudioById(string $uploadDir, string $trackId): void
{
    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $trackId . '.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status): void
{
    http_response_code($status);
    respond([
        'success' => false,
        'error' => $message,
    ]);
}
