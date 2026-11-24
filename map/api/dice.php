<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dice.php';
require_once __DIR__ . '/../includes/response.php';

const MAP_DICE_RATE_LIMIT_SESSION_KEY = 'map_dice_last_roll_at';

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
        session_write_close();
        $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Identifiant de campagne');
        if (isset($_GET['rollId'])) {
            $rollId = mapValidatePositiveInt($_GET['rollId'], 'Identifiant du jet');
            mapJsonSuccess(['roll' => mapGetCampaignDiceRoll($campaignId, $rollId, $currentUser)]);
        }
        $limitInput = $_GET['limit'] ?? '50';
        $limit = mapValidatePositiveInt($limitInput, 'Limite d historique');

        mapJsonSuccess([
            'rolls' => mapListCampaignDiceRolls($campaignId, $currentUser, $limit),
        ]);
    }

    mapEnforceCsrfToken(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null);
    $payload = mapReadJsonBody(16384);
    mapTableTimingMark('dice_request');

    if ((string) ($payload['action'] ?? '') !== 'roll') {
        mapJsonError('Action inconnue.', 404);
    }

    mapStartSession();
    $now = microtime(true);
    $lastRollAt = $_SESSION[MAP_DICE_RATE_LIMIT_SESSION_KEY] ?? null;

    if (is_float($lastRollAt) && $now - $lastRollAt < 0.4) {
        mapJsonError('Patientez un instant avant de relancer les des.', 429);
    }
    $_SESSION[MAP_DICE_RATE_LIMIT_SESSION_KEY] = $now;

    $campaignId = mapValidatePositiveInt($payload['campaignId'] ?? null, 'Identifiant de campagne');
    if (isset($payload['success']) && !is_array($payload['success'])) mapJsonError('Seuil de réussite invalide.',422);
    session_write_close();
    $roll = mapCreateCampaignDiceRoll(
        $campaignId,
        (string) ($payload['expression'] ?? ''),
        (string) ($payload['mode'] ?? 'normal'),
        $currentUser,
        (string) ($payload['visibility'] ?? 'public'),
        $payload['success'] ?? null
    );

    mapJsonSuccess(['roll' => $roll], 201);
} catch (MapHttpException $exception) {
    mapJsonError($exception->getMessage(), $exception->getStatusCode());
} catch (DomainException $exception) {
    mapJsonError($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('[map/api/dice] ' . $exception->getMessage());
    mapJsonError('Une erreur interne est survenue.', 500);
}
