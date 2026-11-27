<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notes.php';
require_once __DIR__ . '/../includes/response.php';

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        mapJsonError('Methode non autorisee.', 405);
    }

    $currentUser = mapGetCurrentUser();

    if ($currentUser === null) {
        mapJsonError('Authentification requise.', 401);
    }

    if ($method === 'GET') {
        $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Identifiant de campagne');
        $access = mapRequireCampaignAccess($campaignId, $currentUser);
        if (($_GET['image'] ?? null) === '1') {
            $noteId = mapValidatePositiveInt($_GET['noteId'] ?? null, 'Document');
            $note = mapGetCampaignNoteRecord(mapGetDatabase(), $campaignId, $noteId);
            if ($note === null || !mapCanViewCampaignNote($note, $access, $currentUser)) {
                mapThrowNotFound('Image introuvable.');
            }
            session_write_close();
            [$format, $encoded] = array_pad(explode(',', mapDocumentOptions($note)['image'] ?? '', 2), 2, '');
            if (!preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64$~D', $format, $mime)) {
                mapThrowNotFound('Image introuvable.');
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false || $bytes === '') mapThrowNotFound('Image introuvable.');
            header('Content-Type: ' . $mime[1]);
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            echo $bytes;
            exit;
        }
        $folders = mapListNoteFolders($campaignId, $currentUser);
        $revision = null;
        if (!isset($_GET['noteId'])) {
            $revision = mapCampaignLibraryRevision($campaignId, $access, $currentUser, $folders);
            if (($_GET['afterLibrary'] ?? null) === $revision) {
                mapJsonSuccess(['libraryRevision' => $revision]);
            }
        }

        mapJsonSuccess([
            'folders' => $folders,
            'libraryRevision' => $revision,
            'notes' => isset($_GET['noteId'])
                ? [mapGetCampaignNote($campaignId, mapValidatePositiveInt($_GET['noteId'], 'Document'), $currentUser)]
                : mapListCampaignNotes($campaignId, $currentUser, ($_GET['imageLinks'] ?? null) === '1'),
            'permissions' => [
                'canUseGmNotes' => mapCanUseGmNotes($access),
            ],
        ]);
    }

    mapEnforceCsrfToken(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null);
    $payload = mapReadJsonBody(28 * 1024 * 1024);
    $action = (string) ($payload['action'] ?? '');
    $campaignId = mapValidatePositiveInt($payload['campaignId'] ?? null, 'Identifiant de campagne');

    if ($action === 'create') {
        if (isset($payload['options']) && !is_array($payload['options'])) {
            throw new DomainException('Options de document invalides.');
        }
        $note = mapCreateCampaignNote(
            $campaignId,
            (string) ($payload['title'] ?? 'Nouvelle note'),
            (string) ($payload['content'] ?? ''),
            isset($payload['folder']) ? (string) $payload['folder'] : null,
            $payload['tags'] ?? [],
            (string) ($payload['visibility'] ?? 'personal'),
            $currentUser,
            (string) ($payload['type'] ?? 'document'),
            $payload['options'] ?? null
        );
        unset($payload);

        mapJsonSuccess(['note' => $note], 201);
    }

    if ($action === 'create_folder') {
        mapCreateNoteFolder($campaignId, (string) ($payload['name'] ?? ''), $currentUser);
        mapJsonSuccess(['folders' => mapListNoteFolders($campaignId, $currentUser)]);
    }

    $noteId = mapValidatePositiveInt($payload['noteId'] ?? null, 'Identifiant de note');
    if ($action === 'move') {
        mapJsonSuccess(['note' => mapMoveCampaignNote($campaignId, $noteId, isset($payload['folder']) ? (string) $payload['folder'] : null, $currentUser), 'folders' => mapListNoteFolders($campaignId, $currentUser)]);
    }

    if ($action === 'update') {
        $note = mapUpdateCampaignNote(
            $campaignId,
            $noteId,
            mapValidatePositiveInt($payload['revision'] ?? null, 'Revision de note'),
            (string) ($payload['title'] ?? ''),
            (string) ($payload['content'] ?? ''),
            isset($payload['folder']) ? (string) $payload['folder'] : null,
            $payload['tags'] ?? [],
            (string) ($payload['visibility'] ?? ''),
            $currentUser, $payload['options'] ?? null
        );
        unset($payload);

        mapJsonSuccess(['note' => $note]);
    }

    if ($action === 'delete') {
        mapDeleteCampaignNote($campaignId, $noteId, $currentUser);
        mapJsonSuccess(['deletedNoteId' => $noteId]);
    }

    mapJsonError('Action inconnue.', 404);
} catch (MapNoteConflictException $exception) {
    mapSendJson([
        'success' => false,
        'error' => $exception->getMessage(),
        'data' => [
            'latestNote' => $exception->getLatestNote(),
        ],
    ], 409);
} catch (MapHttpException $exception) {
    mapJsonError($exception->getMessage(), $exception->getStatusCode());
} catch (DomainException $exception) {
    mapJsonError($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('[map/api/notes] ' . $exception->getMessage());
    mapJsonError('Une erreur interne est survenue.', 500);
}
