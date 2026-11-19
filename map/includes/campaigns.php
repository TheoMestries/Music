<?php
declare(strict_types=1);

require_once __DIR__ . '/users.php';

function mapCampaignAccessLabel(string $role): string
{
    return match ($role) {
        'admin' => 'Administrateur',
        'owner' => 'Proprietaire',
        'gm' => 'MJ secondaire',
        'player' => 'Joueur',
        default => 'Acces inconnu',
    };
}

function mapPublicCampaignData(array $campaign, ?array $access = null): array
{
    $accessRole = (string) ($access['current_user_role'] ?? $campaign['current_user_role'] ?? '');

    return [
        'id' => (int) $campaign['id'],
        'name' => (string) $campaign['name'],
        'description' => $campaign['description'] !== null ? (string) $campaign['description'] : null,
        'isArchived' => (int) $campaign['is_archived'] === 1,
        'createdAt' => (string) $campaign['created_at'],
        'updatedAt' => (string) $campaign['updated_at'],
        'memberCount' => (int) $campaign['member_count'],
        'owner' => [
            'id' => (int) $campaign['owner_user_id'],
            'username' => (string) $campaign['owner_username'],
            'displayName' => (string) $campaign['owner_display_name'],
            'isActive' => (int) $campaign['owner_is_active'] === 1,
        ],
        'access' => [
            'role' => $accessRole,
            'canManageCampaign' => (bool) ($access['can_manage_campaign'] ?? in_array($accessRole, ['admin', 'owner'], true)),
            'canManageMembers' => (bool) ($access['can_manage_members'] ?? in_array($accessRole, ['admin', 'owner'], true)),
            'canViewMembers' => (bool) ($access['can_view_members'] ?? true),
        ],
    ];
}

function mapCanCreateCampaign(array $user): bool
{
    return mapHasGlobalRole($user, ['admin', 'gm', 'player']);
}

function mapListCampaignOwnerOptions(array $currentUser): array
{
    return mapListActiveUsers(['admin', 'gm']);
}

function mapGetCampaignById(int $campaignId): ?array
{
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT c.id,
                c.name,
                c.description,
                c.cover_image <> \'\' AS has_cover,
                c.default_sheet_template_id,
                COALESCE(creator.display_name,owner.display_name) AS creator_display_name,
                c.owner_user_id,
                c.is_archived,
                c.created_at,
                c.updated_at,
                owner.username AS owner_username,
                owner.display_name AS owner_display_name,
                owner.role AS owner_role,
                owner.is_active AS owner_is_active,
                (
                    SELECT COUNT(*)
                    FROM campaign_members AS cm_total
                    WHERE cm_total.campaign_id = c.id
                ) + 1 AS member_count
         FROM campaigns AS c
         INNER JOIN users AS owner
             ON owner.id = c.owner_user_id
         LEFT JOIN users AS creator ON creator.id=c.creator_user_id
         WHERE c.id = :campaign_id
         LIMIT 1'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
    ]);

    $campaign = $statement->fetch();

    return is_array($campaign) ? $campaign : null;
}

function mapGetCampaignMemberRole(int $campaignId, int $userId): ?string
{
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT member_role
         FROM campaign_members
         WHERE campaign_id = :campaign_id
           AND user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
        ':user_id' => $userId,
    ]);

    $role = $statement->fetchColumn();

    return is_string($role) ? $role : null;
}

function mapGetCampaignAccess(int $campaignId, int $userId): ?array
{
    $campaign = mapGetCampaignById($campaignId);

    if ($campaign === null) {
        return null;
    }

    $user = mapFindUserById($userId);

    if ($user === null || (int) $user['is_active'] !== 1) {
        return null;
    }

    $accessRole = null;

    if ((string) $user['role'] === 'admin') {
        $accessRole = 'admin';
    } elseif ((int) $campaign['owner_user_id'] === $userId) {
        $accessRole = 'owner';
    } else {
        $memberRole = mapGetCampaignMemberRole($campaignId, $userId);
        if ($memberRole !== null) {
            $accessRole = $memberRole;
        }
    }

    if ($accessRole === null) {
        return null;
    }

    return [
        'campaign' => $campaign,
        'current_user_role' => $accessRole,
        'current_user_role_label' => mapCampaignAccessLabel($accessRole),
        'can_manage_campaign' => in_array($accessRole, ['admin', 'owner'], true),
        'can_manage_members' => in_array($accessRole, ['admin', 'owner'], true),
        'can_view_members' => true,
    ];
}

function mapRequireCampaignAccess(int $campaignId, ?array $currentUser = null): array
{
    $currentUser ??= mapRequireLogin();
    $access = mapGetCampaignAccess($campaignId, (int) $currentUser['user_id']);

    if ($access === null) {
        mapThrowForbidden('Vous n avez pas acces a cette campagne.');
    }

    return $access;
}

function mapRequireCampaignGm(int $campaignId, ?array $currentUser = null): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);

    if (!in_array($access['current_user_role'], ['admin', 'owner', 'gm'], true)) {
        mapThrowForbidden('Vous n avez pas les droits MJ necessaires sur cette campagne.');
    }

    return $access;
}

function mapRequireCampaignOwnerOrAdmin(int $campaignId, ?array $currentUser = null): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);

    if (!in_array($access['current_user_role'], ['admin', 'owner'], true)) {
        mapThrowForbidden('Seul le proprietaire principal ou un administrateur peut modifier cette campagne.');
    }

    return $access;
}

function mapTouchCampaign(PDO $pdo, int $campaignId): void
{
    $statement = $pdo->prepare(
        'UPDATE campaigns
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = :campaign_id'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
    ]);
}

function mapResolveCampaignTransferOwnerId(int $requestedOwnerId): int
{
    $owner = mapFindUserById($requestedOwnerId);

    if ($owner === null || (int) $owner['is_active'] !== 1) {
        throw new DomainException('Le proprietaire choisi est introuvable ou desactive.');
    }

    if (!in_array((string) $owner['role'], ['admin', 'gm'], true)) {
        throw new DomainException('Le proprietaire principal doit avoir un role global administrateur ou MJ.');
    }

    return (int) $owner['id'];
}

function mapResolveCampaignOwnerIdForCreate(array $currentUser, int $requestedOwnerId): int
{
    if ((string) $currentUser['role'] !== 'admin') {
        return (int) $currentUser['user_id'];
    }

    return mapResolveCampaignTransferOwnerId($requestedOwnerId);
}

function mapCreateCampaign(
    string $name,
    ?string $description,
    int $requestedOwnerId,
    array $currentUser
): int {
    if (!mapCanCreateCampaign($currentUser)) {
        mapThrowForbidden('Vous n avez pas le droit de creer une campagne.');
    }

    $pdo = mapGetDatabase();
    $name = mapValidateCampaignName($name);
    $description = mapValidateCampaignDescription($description);
    $ownerUserId = mapResolveCampaignOwnerIdForCreate($currentUser, $requestedOwnerId);

    $statement = $pdo->prepare(
        'INSERT INTO campaigns (name, description, owner_user_id, creator_user_id, is_archived, created_at, updated_at)
         VALUES (:name, :description, :owner_user_id, :creator_user_id, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        ':name' => $name,
        ':description' => $description,
        ':owner_user_id' => $ownerUserId,
        ':creator_user_id' => (int)$currentUser['user_id'],
    ]);

    return (int) $pdo->lastInsertId();
}

function mapListCampaignsForUser(array $currentUser): array
{
    $pdo = mapGetDatabase();
    $isAdmin = (string) $currentUser['role'] === 'admin';
    $statement = $pdo->prepare(
        'SELECT c.id,
                c.name,
                c.description,
                c.cover_image <> \'\' AS has_cover,
                c.owner_user_id,
                c.is_archived,
                c.created_at,
                c.updated_at,
                owner.username AS owner_username,
                owner.display_name AS owner_display_name,
                owner.role AS owner_role,
                owner.is_active AS owner_is_active,
                CASE
                    WHEN CAST(:is_admin AS INTEGER) = 1 THEN "admin"
                    WHEN c.owner_user_id = :user_id THEN "owner"
                    ELSE cm_self.member_role
                END AS current_user_role,
                (
                    SELECT COUNT(*)
                    FROM campaign_members AS cm_total
                    WHERE cm_total.campaign_id = c.id
                ) + 1 AS member_count
         FROM campaigns AS c
         INNER JOIN users AS owner
             ON owner.id = c.owner_user_id
         LEFT JOIN campaign_members AS cm_self
             ON cm_self.campaign_id = c.id
            AND cm_self.user_id = :user_id
         WHERE CAST(:is_admin AS INTEGER) = 1
            OR c.owner_user_id = :user_id
            OR cm_self.user_id IS NOT NULL
         ORDER BY c.is_archived ASC, datetime(c.updated_at) DESC, LOWER(c.name) ASC'
    );
    $statement->execute([
        ':is_admin' => $isAdmin ? 1 : 0,
        ':user_id' => (int) $currentUser['user_id'],
    ]);

    $campaigns = $statement->fetchAll();

    foreach ($campaigns as &$campaign) {
        $campaign['current_user_role_label'] = mapCampaignAccessLabel((string) $campaign['current_user_role']);
    }
    unset($campaign);

    return $campaigns;
}

function mapUpdateCampaignDetails(
    int $campaignId,
    string $name,
    ?string $description,
    int $requestedOwnerId,
    array $currentUser,
    ?string $cover = null
): void {
    $access = mapRequireCampaignOwnerOrAdmin($campaignId, $currentUser);
    $campaign = $access['campaign'];
    $pdo = mapGetDatabase();
    $name = mapValidateCampaignName($name);
    $description = mapValidateCampaignDescription($description);
    $ownerUserId = $requestedOwnerId === (int)$campaign['owner_user_id']
        ? $requestedOwnerId : mapResolveCampaignTransferOwnerId($requestedOwnerId);

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'UPDATE campaigns
             SET name = :name,
                 description = :description,
                 cover_image = COALESCE(:cover_image, cover_image),
                 owner_user_id = :owner_user_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :campaign_id'
        );
        $statement->execute([
            ':name' => $name,
            ':description' => $description,
            ':cover_image' => $cover,
            ':owner_user_id' => $ownerUserId,
            ':campaign_id' => $campaignId,
        ]);

        if ($ownerUserId !== (int) $campaign['owner_user_id']) {
            $removeDuplicateMembership = $pdo->prepare(
                'DELETE FROM campaign_members
                 WHERE campaign_id = :campaign_id
                   AND user_id = :owner_user_id'
            );
            $removeDuplicateMembership->execute([
                ':campaign_id' => $campaignId,
                ':owner_user_id' => $ownerUserId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function mapSetCampaignArchivedState(int $campaignId, bool $isArchived, array $currentUser): void
{
    mapRequireCampaignOwnerOrAdmin($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'UPDATE campaigns
         SET is_archived = :is_archived,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :campaign_id'
    );
    $statement->execute([
        ':is_archived' => $isArchived ? 1 : 0,
        ':campaign_id' => $campaignId,
    ]);
}

function mapGetCampaignMembers(int $campaignId): array
{
    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT cm.campaign_id,
                cm.user_id,
                cm.member_role,
                cm.created_at,
                COALESCE(cm.updated_at, cm.created_at) AS updated_at,
                u.username,
                u.display_name,
                u.role AS global_role,
                u.is_active
         FROM campaign_members AS cm
         INNER JOIN users AS u
             ON u.id = cm.user_id
         WHERE cm.campaign_id = :campaign_id
         ORDER BY CASE cm.member_role WHEN "gm" THEN 0 ELSE 1 END,
                  LOWER(u.display_name) ASC,
                  LOWER(u.username) ASC'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
    ]);

    return $statement->fetchAll();
}

function mapListEligibleCampaignMembers(int $campaignId): array
{
    $campaign = mapGetCampaignById($campaignId);

    if ($campaign === null) {
        return [];
    }

    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.display_name, u.role, u.is_active
         FROM users AS u
         WHERE u.is_active = 1
           AND u.id != :owner_user_id
           AND NOT EXISTS (
               SELECT 1
               FROM campaign_members AS cm
               WHERE cm.campaign_id = :campaign_id
                 AND cm.user_id = u.id
           )
         ORDER BY CASE u.role WHEN "admin" THEN 0 WHEN "gm" THEN 1 ELSE 2 END,
                  LOWER(u.display_name) ASC,
                  LOWER(u.username) ASC'
    );
    $statement->execute([
        ':owner_user_id' => (int) $campaign['owner_user_id'],
        ':campaign_id' => $campaignId,
    ]);

    return $statement->fetchAll();
}

function mapAssertCampaignMemberEligibility(array $campaign, array $user, string $memberRole): void
{
    if ((int) $campaign['owner_user_id'] === (int) $user['id']) {
        throw new DomainException('Le proprietaire principal ne peut pas etre ajoute comme membre en doublon.');
    }

    if ((int) $user['is_active'] !== 1) {
        throw new DomainException('Un compte desactive ne peut pas etre ajoute a une campagne.');
    }

    if ($memberRole === 'gm' && (string) $user['role'] === 'player') {
        throw new DomainException('Un joueur global ne peut pas devenir MJ secondaire.');
    }
}

function mapAddCampaignMember(int $campaignId, int $userId, string $memberRole, array $currentUser): void
{
    $access = mapRequireCampaignOwnerOrAdmin($campaignId, $currentUser);
    $campaign = $access['campaign'];
    $targetUser = mapFindUserById($userId);

    if ($targetUser === null) {
        throw new DomainException('Utilisateur introuvable.');
    }

    $memberRole = mapValidateCampaignMemberRole($memberRole);
    mapAssertCampaignMemberEligibility($campaign, $targetUser, $memberRole);

    if (mapGetCampaignMemberRole($campaignId, $userId) !== null) {
        throw new DomainException('Cet utilisateur est deja membre de la campagne.');
    }

    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'INSERT INTO campaign_members (campaign_id, user_id, member_role, created_at, updated_at)
         VALUES (:campaign_id, :user_id, :member_role, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
        ':user_id' => $userId,
        ':member_role' => $memberRole,
    ]);

    mapTouchCampaign($pdo, $campaignId);
}

function mapUpdateCampaignMemberRole(int $campaignId, int $userId, string $memberRole, array $currentUser): void
{
    $access = mapRequireCampaignOwnerOrAdmin($campaignId, $currentUser);
    $campaign = $access['campaign'];
    $targetUser = mapFindUserById($userId);

    if ($targetUser === null) {
        throw new DomainException('Utilisateur introuvable.');
    }

    $existingMemberRole = mapGetCampaignMemberRole($campaignId, $userId);
    if ($existingMemberRole === null) {
        throw new DomainException('Ce membre n existe pas dans la campagne.');
    }

    $memberRole = mapValidateCampaignMemberRole($memberRole);
    mapAssertCampaignMemberEligibility($campaign, $targetUser, $memberRole);

    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'UPDATE campaign_members
         SET member_role = :member_role,
             updated_at = CURRENT_TIMESTAMP
         WHERE campaign_id = :campaign_id
           AND user_id = :user_id'
    );
    $statement->execute([
        ':member_role' => $memberRole,
        ':campaign_id' => $campaignId,
        ':user_id' => $userId,
    ]);

    mapTouchCampaign($pdo, $campaignId);
}

function mapRemoveCampaignMember(int $campaignId, int $userId, array $currentUser): void
{
    $access = mapRequireCampaignOwnerOrAdmin($campaignId, $currentUser);
    $campaign = $access['campaign'];

    if ((int) $campaign['owner_user_id'] === $userId) {
        throw new DomainException('Le proprietaire principal ne peut pas etre retire comme simple membre.');
    }

    if (mapGetCampaignMemberRole($campaignId, $userId) === null) {
        throw new DomainException('Ce membre n existe pas dans la campagne.');
    }

    $pdo = mapGetDatabase();
    $statement = $pdo->prepare(
        'DELETE FROM campaign_members
         WHERE campaign_id = :campaign_id
           AND user_id = :user_id'
    );
    $statement->execute([
        ':campaign_id' => $campaignId,
        ':user_id' => $userId,
    ]);

    mapTouchCampaign($pdo, $campaignId);
}
