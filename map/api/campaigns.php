<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/campaigns.php';
require_once __DIR__ . '/../includes/response.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        mapJsonError('Methode non autorisee.', 405);
    }

    $currentUser = mapGetCurrentUser();

    if ($currentUser === null) {
        mapJsonError('Authentification requise.', 401);
    }

    $campaignIdInput = $_GET['id'] ?? null;

    if ($campaignIdInput !== null) {
        $campaignId = mapValidatePositiveInt($campaignIdInput, 'Identifiant de campagne');
        $access = mapRequireCampaignAccess($campaignId, $currentUser);

        mapJsonSuccess([
            'storage' => 'sqlite',
            'schemaVersion' => mapCurrentDatabaseVersion(mapGetDatabase()),
            'campaign' => mapPublicCampaignData($access['campaign'], $access),
        ]);
    }

    $campaigns = array_map(
        static fn(array $campaign): array => mapPublicCampaignData($campaign),
        mapListCampaignsForUser($currentUser)
    );

    mapJsonSuccess([
        'storage' => 'sqlite',
        'schemaVersion' => mapCurrentDatabaseVersion(mapGetDatabase()),
        'campaigns' => $campaigns,
    ]);
} catch (MapHttpException $exception) {
    mapJsonError($exception->getMessage(), $exception->getStatusCode());
} catch (DomainException $exception) {
    mapJsonError($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('[map/api/campaigns] ' . $exception->getMessage());
    mapJsonError('Une erreur interne est survenue.', 500);
}
