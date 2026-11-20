<?php
declare(strict_types=1);

require_once __DIR__.'/character-sheets.php';

function mapInvitationToken(mixed $value): ?string
{
    return is_string($value) && preg_match('/\A[a-f0-9]{48}\z/', $value) ? $value : null;
}

function mapAccountEntryQuery(?string $invite): array
{
    if ($invite !== null) {
        return ['invite' => $invite];
    }
    return ($_GET['next'] ?? null) === 'suszic' ? ['next' => 'suszic'] : [];
}

function mapFinishAccountEntry(?string $invite): never
{
    if ($invite !== null) mapRedirectWithQuery('/campaign-join.php', ['invite'=>$invite]);
    if (mapAccountEntryQuery(null) !== []) {
        header('Location: ' . mapMusicUrl());
        exit;
    }
    mapRedirect('/index.php');
}

function mapGetCampaignInvite(int $campaignId): ?string
{
    $stmt = mapGetDatabase()->prepare('SELECT token FROM campaign_invites WHERE campaign_id=?');
    $stmt->execute([$campaignId]);
    return $stmt->fetchColumn() ?: null;
}

function mapCreateCampaignInvite(int $campaignId, array $user): void
{
    $campaign = mapRequireCampaignOwnerOrAdmin($campaignId,$user)['campaign'];
    if ($campaign['is_archived']) throw new DomainException('Cette partie est archivée.');
    mapGetDatabase()->prepare('INSERT INTO campaign_invites(campaign_id,token) VALUES (?,?) ON CONFLICT(campaign_id) DO NOTHING')
        ->execute([$campaignId,bin2hex(random_bytes(24))]);
}

function mapFindInvitedCampaign(string $token): array
{
    $stmt = mapGetDatabase()->prepare('SELECT c.id,c.name,c.owner_user_id FROM campaign_invites i
        JOIN campaigns c ON c.id=i.campaign_id WHERE i.token=? AND c.is_archived=0');
    $stmt->execute([$token]);
    $campaign = $stmt->fetch();
    if (!$campaign) mapThrowNotFound('Cette invitation est invalide ou la partie est archivée.');
    return $campaign;
}

function mapAcceptCampaignInvite(string $token, array $user): int
{
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO campaign_members(campaign_id,user_id,member_role)
            SELECT c.id,?,'player' FROM campaign_invites i JOIN campaigns c ON c.id=i.campaign_id
            WHERE i.token=? AND c.is_archived=0 AND c.owner_user_id<>?
            ON CONFLICT(campaign_id,user_id) DO NOTHING");
        $stmt->execute([$user['user_id'],$token,$user['user_id']]);
        $campaignId = (int)mapFindInvitedCampaign($token)['id'];
        if ($stmt->rowCount()) mapTouchCampaign($pdo,$campaignId);
        $pdo->commit();
        return $campaignId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function mapReusableSheetTemplates(PDO $pdo, array $user, ?int $templateId = null): array
{
    $stmt = $pdo->prepare("SELECT t.id,t.name,c.name AS campaign_name
        FROM character_sheet_templates t JOIN campaigns c ON c.id=t.campaign_id
        WHERE (t.author_user_id=:user OR c.owner_user_id=:user OR :role='admin'
            OR EXISTS (SELECT 1 FROM campaign_members m WHERE m.campaign_id=c.id AND m.user_id=:user AND m.member_role='gm'))
            AND (:template IS NULL OR t.id=:template)
        ORDER BY t.name COLLATE NOCASE,t.id");
    $stmt->execute([':user'=>(int)$user['user_id'],':role'=>$user['role'],':template'=>$templateId]);
    return $stmt->fetchAll();
}

function mapCreateCampaignWithTemplate(string $name, int $ownerId, ?int $templateId, array $user, ?string $description = null): int
{
    if ($templateId === null) return mapCreateCampaign($name,$description,$ownerId,$user);
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $campaignId = mapCreateCampaign($name,$description,$ownerId,$user);
        if (!mapReusableSheetTemplates($pdo,$user,$templateId)) {
            throw new DomainException('Ce modèle de fiche n’est plus disponible.');
        }
        $stmt = $pdo->prepare('INSERT INTO character_sheet_templates(campaign_id,author_user_id,name,pages,fields)
            SELECT ?,?,name,pages,fields FROM character_sheet_templates WHERE id=?');
        $stmt->execute([$campaignId,$user['user_id'],$templateId]);
        $copyId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE campaigns SET default_sheet_template_id=? WHERE id=?')->execute([$copyId,$campaignId]);
        $pdo->commit();
        return $campaignId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function mapCampaignCoverUpload(mixed $file, bool $remove): ?string
{
    if (!is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) return $remove ? '' : null;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2097152 || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Choisissez une image de 2 Mo maximum.');
    }
    $contents = file_get_contents($file['tmp_name']);
    $info = $contents === false ? false : @getimagesizefromstring($contents);
    if (!$info || !in_array($info['mime'],['image/png','image/jpeg','image/gif','image/webp'],true)) {
        throw new DomainException('Choisissez une image PNG, JPEG, GIF ou WebP.');
    }
    return mapValidateDocumentImage('data:'.$info['mime'].';base64,'.base64_encode($contents));
}
