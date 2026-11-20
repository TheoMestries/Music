<?php
declare(strict_types=1);

require_once __DIR__ . '/broadcast-room.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $action = $_GET['action'] ?? 'state';
    if ($action === 'state') {
        $room = $_GET['room'] ?? null;
        $owner = musicRoomOwner($room);
        if ($owner === null) {
            throw new DomainException('Salle de diffusion introuvable.', 404);
        }
        echo json_encode(musicPublicBroadcast($room, $owner), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    $owner = requireApiLogin();
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validCsrfToken($csrf)) {
        throw new DomainException('Requête non autorisée.', 403);
    }
    session_write_close();
    if (!in_array($action, ['start', 'update', 'stop'], true)) {
        throw new DomainException('Action inconnue.', 404);
    }
    $room = musicRoomForUser($owner);

    $result = musicUpdateJson(musicBroadcastPath($room), static function (array &$state) use ($action, $owner, $payload): array {
        $data = $_POST !== [] ? $_POST : (is_array($payload) ? $payload : []);
        $run = $data['run'] ?? null;
        if ($action !== 'start' && (!is_string($run) || ($state['run'] ?? null) !== $run)) {
            if ($action === 'stop') {
                return ['success' => true];
            }
            throw new DomainException('Cette diffusion a été arrêtée ou reprise depuis un autre onglet.', 409);
        }
        if ($action === 'stop') {
            $state = [];
            return ['success' => true];
        }

        $campaign = musicFindCampaign(musicReadJson(MUSIC_PROJECT_FILE), $owner, $data['campaignId'] ?? null);
        if ($campaign === null) {
            throw new DomainException('Choisissez un projet audio qui vous appartient.', 409);
        }
        $files = musicMediaList([$campaign])['audio'];
        $state = [
            'run' => $action === 'start' ? bin2hex(random_bytes(16)) : $run,
            'campaignId' => $campaign['id'],
            'tracks' => musicBroadcastTracks($data['tracks'] ?? [], $files),
            'updatedAt' => time(),
        ];
        return ['run' => $state['run']];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (DomainException $error) {
    http_response_code($error->getCode() ?: 422);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    echo json_encode(['error' => 'Impossible d’accéder à la diffusion.'], JSON_UNESCAPED_UNICODE);
}
