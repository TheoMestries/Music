<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/scenarios.php';
require_once __DIR__ . '/../includes/response.php';

try {
    if (!mapIsPostRequest()) {
        header('Allow: POST');
        mapJsonError('Méthode non autorisée.', 405);
    }
    $user = mapGetCurrentUser();
    if ($user === null) {
        mapJsonError('Authentification requise.', 401);
    }
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    $scenarioId = mapValidatePositiveInt($_GET['scenarioId'] ?? null, 'Scénario');
    mapRequireCampaignGm($campaignId, $user);
    mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    session_write_close();
    $pdo = mapGetDatabase();

    if (($_GET['action'] ?? '') === 'image') {
        $bytes = file_get_contents('php://input', false, null, 0, MAP_SCENARIO_IMAGE_BYTES + 1);
        $id = mapScenarioStoreImage($pdo, $campaignId, $scenarioId, $bytes === false ? '' : $bytes);
        mapJsonSuccess(['id' => (string) $id, 'url' => mapScenarioImageUrl($campaignId, $scenarioId, $id)], 201);
    }
    $payload = mapReadJsonBody(8 * 1024 * 1024);
    $revision = mapValidatePositiveInt($payload['revision'] ?? null, 'Version du scénario');
    mapJsonSuccess(['revision' => mapScenarioSave($pdo, $campaignId, $scenarioId, $revision, $payload)]);
} catch (MapHttpException $error) {
    mapJsonError($error->getMessage(), $error->getStatusCode());
} catch (DomainException $error) {
    mapJsonError($error->getMessage());
} catch (Throwable $error) {
    error_log('[scenarios] ' . $error::class);
    mapJsonError('L’enregistrement a échoué. Réessayez dans un instant.', 500);
}
