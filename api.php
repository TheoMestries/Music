<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$owner = requireApiLogin();
$action = $_GET['action'] ?? '';
if ($action !== 'load' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    fail('Requête non autorisée.', 403);
}
session_write_close();

try {
    if ($action === 'load') {
        respond(musicCampaignTransaction(static fn(array &$library): array => musicCampaignResponse(musicOwnedCampaigns($library, $owner))));
    }

    if ($action === 'upload' || $action === 'uploadPlaylistImage') {
        $kind = $action === 'upload' ? 'audio' : 'images';
        respond(uploadMusicMedia($owner, $kind, $_FILES[$kind === 'audio' ? 'audio' : 'image'] ?? []));
    }

    if (!in_array($action, ['save', 'deleteCampaign', 'transferCampaign'], true)) {
        fail('Action inconnue.', 404);
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        fail('Requête invalide.', 422);
    }

    $result = musicCampaignTransaction(static function (array &$library) use ($action, $owner, $payload): array {
        $current = musicCampaignResponse(musicOwnedCampaigns($library, $owner));
        if ($current['revision'] !== ($payload['revision'] ?? null)) {
            throw new DomainException('Les projets ont changé. Rechargez la page avant de reprendre vos modifications.', 409);
        }

        if ($action === 'save') {
            saveMusicCampaigns($library, $owner, $current['campaigns'], $payload['campaigns'] ?? null);
        } else {
            $id = $payload['id'] ?? null;
            if (musicFindCampaign($library, $owner, $id) === null) {
                throw new DomainException('Projet audio introuvable.', 404);
            }
            if ($action === 'transferCampaign') {
                $recipient = is_string($payload['username'] ?? null) ? findMusicUser(trim($payload['username']), true) : null;
                if ($recipient === null || $recipient['username'] === $owner) {
                    throw new DomainException('Indiquez l’identifiant d’un autre compte SuSZic.');
                }
            }
            foreach ($library['projects'] as $index => $project) {
                if ($project['owner'] === $owner && $project['campaign']['id'] === $id) {
                    if ($action === 'transferCampaign') {
                        $project['owner'] = $recipient['username'];
                        array_splice($library['projects'], $index, 1);
                        array_unshift($library['projects'], $project);
                    } else {
                        array_splice($library['projects'], $index, 1);
                    }
                    break;
                }
            }
        }

        return musicCampaignResponse(musicOwnedCampaigns($library, $owner));
    });
    respond($result);
} catch (DomainException $error) {
    fail($error->getMessage(), $error->getCode() ?: 422);
} catch (Throwable $error) {
    error_log((string) $error);
    fail('Impossible d’accéder à la bibliothèque musicale.', 500);
}

function saveMusicCampaigns(array &$library, string $owner, array $current, mixed $campaigns): void
{
    if (!is_array($campaigns) || !array_is_list($campaigns)) {
        throw new DomainException('Liste des projets audio invalide.');
    }

    $otherProjects = array_values(array_filter($library['projects'] ?? [], static fn(array $project): bool => $project['owner'] !== $owner));
    $otherIds = array_fill_keys(array_column(array_column($otherProjects, 'campaign'), 'id'), true);
    $seen = [];
    $ownedProjects = [];
    foreach ($campaigns as $campaign) {
        $id = $campaign['id'] ?? null;
        $name = $campaign['name'] ?? null;
        if (!validMusicProjectId($id) || !is_string($name) || trim($name) === '' || isset($seen[$id]) || isset($otherIds[$id])) {
            throw new DomainException('Projet audio invalide.');
        }
        $seen[$id] = true;
        $ownedProjects[] = ['owner' => $owner, 'campaign' => $campaign];
    }
    if (array_diff(array_column($current, 'id'), array_keys($seen)) !== []) {
        throw new DomainException('Utilisez la commande Supprimer pour retirer un projet audio.');
    }

    $ownedMedia = musicMediaList($current);
    foreach (musicMediaList($campaigns) as $kind => $files) {
        foreach (array_diff_key($files, $ownedMedia[$kind]) as $name => $_) {
            if (($library['pending'][$kind][$name] ?? null) !== $owner) {
                throw new DomainException('Ce fichier ne fait pas partie de votre bibliothèque.', 403);
            }
            unset($library['pending'][$kind][$name]);
        }
    }
    $library['projects'] = array_merge($otherProjects, $ownedProjects);
}

function uploadMusicMedia(string $owner, string $kind, array $file): array
{
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK) {
        throw new DomainException('Fichier manquant ou trop volumineux.');
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $valid = $kind === 'audio'
        ? in_array($extension, ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'mp4', 'aac', 'flac', 'webm', 'opus', 'aif', 'aiff', 'wma', 'mid', 'midi'], true)
            && is_string($mime) && (str_starts_with($mime, 'audio/') || in_array($mime, ['application/ogg', 'application/x-riff', 'application/vnd.ms-asf', 'video/webm', 'video/mp4'], true))
        : in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)
            && in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true);
    if (!$valid) {
        throw new DomainException($kind === 'audio' ? 'Format audio non pris en charge.' : 'Format d’image non pris en charge.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = musicMediaPath($kind, $name);
    try {
        musicCampaignTransaction(static function (array &$library) use ($file, $path, $kind, $name, $owner): void {
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                throw new RuntimeException('Impossible d’enregistrer le fichier téléversé.');
            }
            $library['pending'][$kind][$name] = $owner;
        });
    } catch (Throwable $error) {
        if (is_file($path)) {
            unlink($path);
        }
        throw $error;
    }
    return ['success' => true, 'url' => 'uploads/' . $kind . '/' . $name];
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function fail(string $message, int $status): void
{
    http_response_code($status);
    respond(['success' => false, 'error' => $message]);
}
