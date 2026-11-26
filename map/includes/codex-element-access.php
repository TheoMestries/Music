<?php
declare(strict_types=1);

require_once __DIR__ . '/codex-permissions.php';

function mapCodexElementPermissions(PDO $pdo, int $campaignId, int $entryId, array $fichePermissions): array
{
    $context = [
        'manager' => mapCodexCanManage($fichePermissions),
        'userId' => (int) $fichePermissions['userId'],
        'elements' => [],
        'creation' => [],
    ];
    if ($context['manager']) {
        return $context;
    }

    $elements = $pdo->prepare('SELECT section, element_key, user_id, read, edit
        FROM campaign_codex_element_rules WHERE campaign_id = ? AND entry_id = ? AND (user_id IS NULL OR user_id = ?)');
    $elements->execute([$campaignId, $entryId, $context['userId']]);
    foreach ($elements as $row) {
        $context['elements'][$row['section']][$row['element_key']][(int) $row['user_id']] = [
            'read' => $row['read'], 'edit' => $row['edit'],
        ];
    }

    $creation = $pdo->prepare('SELECT section, user_id, create_rule
        FROM campaign_codex_element_create_rules WHERE campaign_id = ? AND entry_id = ? AND (user_id IS NULL OR user_id = ?)');
    $creation->execute([$campaignId, $entryId, $context['userId']]);
    foreach ($creation as $row) {
        $context['creation'][$row['section']][(int) $row['user_id']] = ['create' => $row['create_rule']];
    }
    return $context;
}

function mapCodexElementRuleState(array $rules, int $userId, string $action): string
{
    $individual = $rules[$userId][$action] ?? 'inherit';
    return $individual === 'inherit' ? ($rules[0][$action] ?? 'inherit') : $individual;
}

function mapCodexElementCanRead(array $context, string $section, string $key, bool $private = false): bool
{
    if ($context['manager']) {
        return true;
    }
    $state = mapCodexElementRuleState($context['elements'][$section][$key] ?? [], $context['userId'], 'read');
    return $state === 'allow' || ($state === 'inherit' && !$private);
}

function mapCodexElementCanEdit(array $context, string $section, string $key, bool $canEditFiche, bool $private = false): bool
{
    return $context['manager'] || (mapCodexElementCanRead($context, $section, $key, $private)
        && mapCodexElementRuleState($context['elements'][$section][$key] ?? [], $context['userId'], 'edit') === 'allow');
}

function mapCodexElementCanCreate(array $context, string $section, bool $canEditFiche): bool
{
    return $context['manager'] || mapCodexElementRuleState(
        $context['creation'][$section] ?? [], $context['userId'], 'create'
    ) === 'allow';
}

function mapCodexElementRuleScope(string $section, ?string $key = null): void
{
    if (!in_array($section, ['notes', 'relations'], true) && !isset(MAP_CODEX_SHEET_SECTIONS[$section])) {
        throw new DomainException('Section de fiche invalide.');
    }
    if ($key !== null && !preg_match('/^[a-f0-9]{24}$/D', $key)) {
        throw new DomainException('Identifiant d’élément invalide.');
    }
}

function mapCodexElementRules(PDO $pdo, int $campaignId, int $entryId, string $section, string $key): array
{
    mapCodexElementRuleScope($section, $key);
    $statement = $pdo->prepare('SELECT user_id, read, edit FROM campaign_codex_element_rules
        WHERE campaign_id = ? AND entry_id = ? AND section = ? AND element_key = ? ORDER BY user_id');
    $statement->execute([$campaignId, $entryId, $section, $key]);
    $rules = [];
    foreach ($statement as $row) {
        $rules[(int) $row['user_id']] = ['read' => $row['read'], 'edit' => $row['edit']];
    }
    return $rules;
}

function mapCodexElementCreateRules(PDO $pdo, int $campaignId, int $entryId, string $section): array
{
    mapCodexElementRuleScope($section);
    $statement = $pdo->prepare('SELECT user_id, create_rule FROM campaign_codex_element_create_rules
        WHERE campaign_id = ? AND entry_id = ? AND section = ? ORDER BY user_id');
    $statement->execute([$campaignId, $entryId, $section]);
    $rules = [];
    foreach ($statement as $row) {
        $rules[(int) $row['user_id']] = ['create' => $row['create_rule']];
    }
    return $rules;
}

function mapCodexSaveElementRule(PDO $pdo, int $campaignId, int $entryId, string $section, string $key, int $userId, array $rules): void
{
    mapCodexElementRuleScope($section, $key);
    $audience = mapCodexRuleAudience($pdo, $campaignId, $userId);
    $read = mapCodexRuleValue($rules, 'read');
    $edit = mapCodexRuleValue($rules, 'edit');
    $scope = [$campaignId, $entryId, $section, $key, $audience];
    if ($read === 'inherit' && $edit === 'inherit') {
        $pdo->prepare('DELETE FROM campaign_codex_element_rules
            WHERE campaign_id = ? AND entry_id = ? AND section = ? AND element_key = ? AND user_id IS ?')->execute($scope);
        return;
    }
    $pdo->prepare('INSERT INTO campaign_codex_element_rules (campaign_id, entry_id, section, element_key, user_id, read, edit)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(campaign_id, entry_id, section, element_key, coalesce(user_id, 0))
        DO UPDATE SET read = excluded.read, edit = excluded.edit')->execute([...$scope, $read, $edit]);
}

function mapCodexSaveElementCreateRule(PDO $pdo, int $campaignId, int $entryId, string $section, int $userId, array $rules): void
{
    mapCodexElementRuleScope($section);
    $audience = mapCodexRuleAudience($pdo, $campaignId, $userId);
    $create = mapCodexRuleValue($rules, 'create');
    $scope = [$campaignId, $entryId, $section, $audience];
    if ($create === 'inherit') {
        $pdo->prepare('DELETE FROM campaign_codex_element_create_rules
            WHERE campaign_id = ? AND entry_id = ? AND section = ? AND user_id IS ?')->execute($scope);
        return;
    }
    $pdo->prepare('INSERT INTO campaign_codex_element_create_rules (campaign_id, entry_id, section, user_id, create_rule)
        VALUES (?, ?, ?, ?, ?) ON CONFLICT(campaign_id, entry_id, section, coalesce(user_id, 0))
        DO UPDATE SET create_rule = excluded.create_rule')->execute([...$scope, $create]);
}

function mapCodexDeleteElementRules(PDO $pdo, int $campaignId, int $entryId, string $section, string $key): void
{
    mapCodexElementRuleScope($section, $key);
    $pdo->prepare('DELETE FROM campaign_codex_element_rules
        WHERE campaign_id = ? AND entry_id = ? AND section = ? AND element_key = ?')->execute([$campaignId, $entryId, $section, $key]);
}
