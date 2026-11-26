<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/campaign-codex.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $user = mapGetCurrentUser();
    if ($user === null) {
        mapThrowForbidden();
    }
    $campaignId = mapValidatePositiveInt($_GET['campaignId'] ?? null, 'Campagne');
    $access = mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    $permissions = mapCodexPermissionContext($pdo, $campaignId, $access, (int) $user['user_id']);
    session_write_close();
    $imageId = mapValidatePositiveInt($_GET['imageId'] ?? null, 'Image');
    if (!mapCodexCanReadImage($pdo, $campaignId, $imageId, $permissions)) {
        mapThrowNotFound();
    }
    $query = $pdo->prepare('SELECT mime, content FROM campaign_codex_media WHERE campaign_id = ? AND id = ?');
    $query->execute([$campaignId, $imageId]);
    $image = $query->fetch();
    if ($image === false) {
        mapThrowNotFound();
    }
    header('Content-Type: ' . $image['mime']);
    header('Content-Length: ' . strlen($image['content']));
    echo $image['content'];
} catch (MapHttpException $error) {
    http_response_code($error->getStatusCode());
} catch (DomainException) {
    http_response_code(400);
} catch (Throwable $error) {
    error_log('[codex-image] ' . $error::class);
    http_response_code(500);
}
