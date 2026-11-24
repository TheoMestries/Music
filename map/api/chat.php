<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/dice.php';
require_once __DIR__ . '/../includes/response.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) mapJsonError('Méthode non autorisée.', 405);
    $user = mapGetCurrentUser();
    if ($user === null) mapJsonError('Authentification requise.', 401);
    if ($method === 'POST') mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    session_write_close();
    $payload = $method === 'GET' ? $_GET : mapReadJsonBody(16384);
    $campaignId = mapValidatePositiveInt($payload['campaignId'] ?? null, 'Campagne');
    mapRequireCampaignAccess($campaignId, $user);
    $pdo = mapGetDatabase();
    if ($method === 'POST') {
        $body = $payload['body'] ?? null;
        if (!is_string($body) || trim($body) === '' || mb_strlen($body) > 2000) {
            throw new DomainException('Écrivez un message de 1 à 2 000 caractères.');
        }
        $stmt = $pdo->prepare('INSERT INTO campaign_chat(campaign_id,author_id,body) VALUES (?,?,?)');
        $stmt->execute([$campaignId, $user['user_id'], trim($body)]);
        mapJsonSuccess([], 201);
    }
    $stmt = $pdo->prepare('SELECT m.id,m.body,m.created_at AS createdAt,u.display_name AS author
        FROM campaign_chat m JOIN users u ON u.id=m.author_id WHERE m.campaign_id=? ORDER BY m.id DESC LIMIT 60');
    $stmt->execute([$campaignId]);
    $messages = $stmt->fetchAll();
    mapTableTimingMark('chat_messages');
    $rolls = mapListCampaignDiceRolls($campaignId, $user, 60, compact: true);
    $history = mapListCampaignDiceRolls($campaignId, $user, 10, ownOnly: true, compact: true);
    mapTableTimingMark('chat_rolls');
    mapJsonSuccess(['messages'=>$messages, 'rolls'=>$rolls, 'history'=>$history]);
} catch (MapHttpException $error) {
    mapJsonError($error->getMessage(), $error->getStatusCode());
} catch (DomainException $error) {
    mapJsonError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('[map/api/chat] ' . $error->getMessage());
    mapJsonError('Impossible de charger le chat.', 500);
}
