<?php
declare(strict_types=1);

require_once __DIR__ . '/character-sheets.php';
require_once __DIR__ . '/campaign-codex.php';

function mapCodexCharacterSheetRights(PDO $pdo, int $campaignId, int $entryId, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId, $user);
    $permissions = mapCodexPermissionContext($pdo, $campaignId, $access, (int) $user['user_id']);
    $statement = $pdo->prepare('SELECT category, ' . MAP_CODEX_PRIVATE_ORIGIN_SQL . ' AS private_origin
        FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    $statement->execute([$campaignId, $entryId]);
    $entry = $statement->fetch();
    if (!$entry || $entry['category'] !== 'Personnages'
        || !mapCodexCanRead($permissions, $entry['category'], $entryId, (bool) $entry['private_origin'])) {
        mapThrowNotFound('Fiche de personnage introuvable.');
    }
    return [
        'canManage' => mapCodexCanManage($permissions),
        'canEdit' => mapCodexCanEdit($permissions, $entry['category'], $entryId, (bool) $entry['private_origin']),
    ];
}

function mapCodexCharacterSheet(PDO $pdo, int $campaignId, int $entryId): ?array
{
    $statement = $pdo->prepare('SELECT template.id, template.name, template.pages, template.fields, sheet.field_values, sheet.revision
        FROM campaign_codex_character_sheets sheet
        JOIN character_sheet_templates template ON template.campaign_id = sheet.campaign_id AND template.id = sheet.template_id
        WHERE sheet.campaign_id = ? AND sheet.entry_id = ?');
    $statement->execute([$campaignId, $entryId]);
    $row = $statement->fetch();
    return $row ? mapDecodeCharacterSheet($row) : null;
}

function mapAssignCodexCharacterSheet(PDO $pdo, int $campaignId, int $entryId, int $templateId, array $user): array
{
    $pdo->beginTransaction();
    try {
        if (!mapCodexCharacterSheetRights($pdo, $campaignId, $entryId, $user)['canManage']) {
            mapThrowForbidden('Seul un MJ peut choisir le modèle.');
        }
        $statement = $pdo->prepare("INSERT INTO campaign_codex_character_sheets (entry_id, campaign_id, template_id)
            SELECT ?, campaign_id, id FROM character_sheet_templates WHERE campaign_id = ? AND id = ? AND deleted_at IS NULL
                AND (author_user_id = ? OR visibility <> 'private')
            ON CONFLICT(entry_id) DO UPDATE SET
                field_values = CASE WHEN campaign_codex_character_sheets.template_id = excluded.template_id
                    THEN campaign_codex_character_sheets.field_values ELSE '{}' END,
                template_id = excluded.template_id, revision = campaign_codex_character_sheets.revision + 1");
        $statement->execute([$entryId, $campaignId, $templateId, (int) $user['user_id']]);
        if (!$statement->rowCount()) {
            mapThrowNotFound('Modèle introuvable.');
        }
        mapSyncCodexPublicationSheet($pdo, $campaignId, $entryId, true);
        $pdo->prepare('UPDATE campaign_board_state SET revision = revision + 1 WHERE campaign_id = ?')->execute([$campaignId]);
        $sheet = mapCodexCharacterSheet($pdo, $campaignId, $entryId);
        $pdo->commit();
        return $sheet;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function mapSaveCodexCharacterSheet(PDO $pdo, int $campaignId, int $entryId, int $revision, array $values, array $user): array
{
    $pdo->beginTransaction();
    try {
        if (!mapCodexCharacterSheetRights($pdo, $campaignId, $entryId, $user)['canEdit']) {
            mapThrowForbidden('Vous ne pouvez pas modifier cette fiche.');
        }
        $sheet = mapCodexCharacterSheet($pdo, $campaignId, $entryId);
        $clean = mapCharacterSheetValues($sheet, $revision, $values);
        $statement = $pdo->prepare('UPDATE campaign_codex_character_sheets SET field_values = ?, revision = revision + 1
            WHERE campaign_id = ? AND entry_id = ? AND revision = ?');
        $statement->execute([$clean, $campaignId, $entryId, $revision]);
        if ($statement->rowCount() !== 1) {
            mapThrowHttpException(409, MAP_CHARACTER_SHEET_CONFLICT);
        }
        mapSyncCodexPublicationSheet($pdo, $campaignId, $entryId, true);
        $pdo->prepare('UPDATE campaign_board_state SET revision = revision + 1 WHERE campaign_id = ?')->execute([$campaignId]);
        $pdo->commit();
        return ['revision' => $revision + 1];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
