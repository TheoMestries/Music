<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';

try {
    $user = mapGetCurrentUser();
    if ($user === null) mapThrowForbidden('Authentification requise.');
    $id = mapValidatePositiveInt($_GET['id'] ?? null,'Partie');
    mapRequireCampaignAccess($id,$user);
    $stmt = mapGetDatabase()->prepare('SELECT cover_image FROM campaigns WHERE id=?');
    $stmt->execute([$id]);
    if (!preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=]+)$~D',(string)$stmt->fetchColumn(),$parts)) {
        mapThrowNotFound('Image introuvable.');
    }
    header('Content-Type: '.$parts[1]);
    header('Cache-Control: private, no-cache');
    header('X-Content-Type-Options: nosniff');
    echo base64_decode($parts[2],true);
} catch (Throwable $error) {
    http_response_code($error instanceof MapHttpException ? $error->getStatusCode() : 400);
}
