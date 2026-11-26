<?php
declare(strict_types=1);

require_once __DIR__ . '/campaign-codex.php';

function mapCodexPermissionContext(PDO $pdo, int $campaignId, array $access, int $userId): array
{
    $role = $access['current_user_role'] ?? null;
    $context = [
        'manager' => in_array($role, ['owner', 'gm', 'admin'], true),
        'userId' => $userId,
        'modules' => [],
        'entries' => [],
    ];
    if ($context['manager'] || $role !== 'player') {
        return $context;
    }
    $statement = $pdo->prepare('SELECT category, user_id, read, create_rule AS "create", edit
        FROM campaign_codex_module_rules WHERE campaign_id = ? AND (user_id IS NULL OR user_id = ?)');
    $statement->execute([$campaignId, $userId]);
    foreach ($statement->fetchAll() as $rule) {
        $context['modules'][$rule['category']][(int) $rule['user_id']] = [
            'read' => $rule['read'], 'create' => $rule['create'], 'edit' => $rule['edit'],
        ];
    }
    $statement = $pdo->prepare('SELECT entry_id, user_id, read, edit FROM campaign_codex_entry_rules
        WHERE campaign_id = ? AND (user_id IS NULL OR user_id = ?)');
    $statement->execute([$campaignId, $userId]);
    foreach ($statement->fetchAll() as $rule) {
        $context['entries'][(int) $rule['entry_id']][(int) $rule['user_id']] = ['read' => $rule['read'], 'edit' => $rule['edit']];
    }
    return $context;
}

function mapCodexCanManage(array $permissions): bool
{
    return $permissions['manager'];
}

function mapCodexRuleState(array $permissions, string $action, string $category, ?int $entryId, bool $importPrivate = false): string
{
    $uid = $permissions['userId'];
    $entry = $permissions['entries'][$entryId] ?? [];
    $module = $permissions['modules'][$category] ?? [];
    foreach ([$entry, $module] as $fromModule => $scope) {
        foreach ([$uid, 0] as $audience) {
            $state = $scope[$audience][$action] ?? 'inherit';
            if ($state !== 'inherit') {
                return $importPrivate && $fromModule && $state === 'allow' ? 'inherit' : $state;
            }
        }
    }
    return 'inherit';
}

function mapCodexCanRead(array $permissions, string $category, ?int $entryId = null, bool $importPrivate = false): bool
{
    if (mapCodexCanManage($permissions)) {
        return true;
    }
    $read = mapCodexRuleState($permissions, 'read', $category, $entryId, $importPrivate);
    return $read === 'allow' || ($read === 'inherit' && mapCodexRuleState($permissions, 'edit', $category, $entryId, $importPrivate) === 'allow');
}

function mapCodexCanEdit(array $permissions, string $category, ?int $entryId = null, bool $importPrivate = false): bool
{
    return mapCodexCanManage($permissions) || (mapCodexCanRead($permissions, $category, $entryId, $importPrivate)
        && mapCodexRuleState($permissions, 'edit', $category, $entryId, $importPrivate) === 'allow');
}

function mapCodexCanCreate(array $permissions, string $category): bool
{
    return mapCodexCanManage($permissions) || mapCodexRuleState($permissions, 'create', $category, null) === 'allow';
}

function mapCodexModuleRules(PDO $pdo, int $campaignId): array
{
    $statement = $pdo->prepare('SELECT category, user_id, read, create_rule AS "create", edit
        FROM campaign_codex_module_rules WHERE campaign_id = ? ORDER BY category COLLATE NOCASE, user_id');
    $statement->execute([$campaignId]);
    $rules = [];
    foreach ($statement->fetchAll() as $row) {
        $rules[$row['category']][(int) $row['user_id']] = ['read' => $row['read'], 'create' => $row['create'], 'edit' => $row['edit']];
    }
    return $rules;
}

function mapCodexEntryRules(PDO $pdo, int $campaignId, int $entryId): array
{
    $statement = $pdo->prepare('SELECT user_id, read, edit FROM campaign_codex_entry_rules
        WHERE campaign_id = ? AND entry_id = ? ORDER BY user_id');
    $statement->execute([$campaignId, $entryId]);
    $rules = [];
    foreach ($statement->fetchAll() as $row) {
        $rules[(int) $row['user_id']] = ['read' => $row['read'], 'edit' => $row['edit']];
    }
    return $rules;
}

function mapCodexRuleAudience(PDO $pdo, int $campaignId, int $userId): ?int
{
    if ($userId === 0) {
        return null;
    }
    $statement = $pdo->prepare("SELECT 1 FROM campaign_members member JOIN users ON users.id = member.user_id
        WHERE member.campaign_id = ? AND member.user_id = ? AND member.member_role = 'player' AND users.is_active = 1");
    $statement->execute([$campaignId, $userId]);
    if ($statement->fetchColumn() === false) {
        throw new DomainException('Choisissez un joueur actif de cette campagne.');
    }
    return $userId;
}

function mapCodexRuleValue(array $rules, string $action): string
{
    $value = $rules[$action] ?? 'inherit';
    if (!in_array($value, ['inherit', 'allow', 'deny'], true)) {
        throw new DomainException('Réglage de permission invalide.');
    }
    return $value;
}

function mapCodexSaveModuleRule(PDO $pdo, int $campaignId, string $category, int $userId, array $rules): void
{
    $category = mapCodexText($category, 100, 'Le module', true);
    $audience = mapCodexRuleAudience($pdo, $campaignId, $userId);
    $read = mapCodexRuleValue($rules, 'read');
    $create = mapCodexRuleValue($rules, 'create');
    $edit = mapCodexRuleValue($rules, 'edit');
    if ($read === 'inherit' && $create === 'inherit' && $edit === 'inherit') {
        $pdo->prepare('DELETE FROM campaign_codex_module_rules WHERE campaign_id = ? AND category = ? AND user_id IS ?')
            ->execute([$campaignId, $category, $audience]);
        return;
    }
    $pdo->prepare('INSERT INTO campaign_codex_module_rules (campaign_id, category, user_id, read, create_rule, edit) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT (campaign_id, category, coalesce(user_id, 0))
        DO UPDATE SET read = excluded.read, create_rule = excluded.create_rule, edit = excluded.edit')
        ->execute([$campaignId, $category, $audience, $read, $create, $edit]);
}

function mapCodexSaveEntryRule(PDO $pdo, int $campaignId, int $entryId, int $userId, array $rules): void
{
    $audience = mapCodexRuleAudience($pdo, $campaignId, $userId);
    $statement = $pdo->prepare('SELECT 1 FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    $statement->execute([$campaignId, $entryId]);
    if ($statement->fetchColumn() === false) {
        mapThrowNotFound('La fiche n’appartient pas à cette campagne.');
    }
    $read = mapCodexRuleValue($rules, 'read');
    $edit = mapCodexRuleValue($rules, 'edit');
    if ($read === 'inherit' && $edit === 'inherit') {
        $pdo->prepare('DELETE FROM campaign_codex_entry_rules WHERE campaign_id = ? AND entry_id = ? AND user_id IS ?')
            ->execute([$campaignId, $entryId, $audience]);
        return;
    }
    $pdo->prepare('INSERT INTO campaign_codex_entry_rules (campaign_id, entry_id, user_id, read, edit) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (campaign_id, entry_id, coalesce(user_id, 0))
        DO UPDATE SET read = excluded.read, edit = excluded.edit')
        ->execute([$campaignId, $entryId, $audience, $read, $edit]);
}

function mapCodexGrantCreatorAccess(PDO $pdo, int $campaignId, int $entryId, int $userId, array $permissions, string $category): void
{
    if ($permissions['userId'] === $userId && mapCodexCanCreate($permissions, $category)
        && !mapCodexCanRead($permissions, $category, $entryId)) {
        mapCodexSaveEntryRule($pdo, $campaignId, $entryId, $userId, ['read' => 'allow']);
    }
}
