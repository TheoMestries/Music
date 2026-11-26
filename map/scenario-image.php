<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/scenarios.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $user = mapGetCurrentUser();
    if ($user === null) {
        mapThrowForbidden();
    }
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    $scenarioId = mapValidatePositiveInt($_GET['scenarioId'] ?? null, 'Scénario');
    $imageId = mapValidatePositiveInt($_GET['imageId'] ?? null, 'Image');
    mapRequireCampaignGm($campaignId, $user);
    session_write_close();
    $query = mapGetDatabase()->prepare('SELECT i.mime, i.content FROM campaign_scenario_images i
        JOIN campaign_scenarios s ON s.id = i.scenario_id WHERE s.campaign_id = ? AND s.id = ? AND i.id = ?');
    $query->execute([$campaignId, $scenarioId, $imageId]);
    $image = $query->fetch();
    if ($image === false) {
        mapThrowNotFound('Image introuvable.');
    }
    header('Content-Type: ' . $image['mime']);
    header('Content-Length: ' . strlen($image['content']));
    echo $image['content'];
} catch (MapHttpException $error) {
    http_response_code($error->getStatusCode());
} catch (DomainException) {
    http_response_code(400);
} catch (Throwable $error) {
    error_log('[scenario-image] ' . $error::class);
    http_response_code(500);
}
