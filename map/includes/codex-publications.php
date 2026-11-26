<?php
declare(strict_types=1);

require_once __DIR__ . '/codex-text.php';
require_once __DIR__ . '/codex-mentions.php';
require_once __DIR__ . '/document-options.php';

function mapSetCodexPublished(PDO $pdo, int $campaignId, int $entryId, bool $published, array $user): void
{
    $pdo->beginTransaction();
    try {
        mapRequireCampaignGm($campaignId, $user);
        $lookup = $pdo->prepare('SELECT 1 FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
        $lookup->execute([$campaignId, $entryId]);
        if ($lookup->fetchColumn() === false) {
            mapThrowNotFound('Fiche introuvable.');
        }
        $lookup->closeCursor();
        if ($published) {
            $statement = $pdo->prepare("INSERT INTO campaign_notes
                (campaign_id, author_user_id, title, content, visibility, codex_entry_id, codex_published)
                SELECT campaign_id, ?, substr(title, 1, 160), '', 'gm', id, 1 FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?
                ON CONFLICT(codex_entry_id) DO UPDATE SET codex_published = 1, revision = campaign_notes.revision + 1
                WHERE campaign_notes.codex_published = 0");
            $statement->execute([$user['user_id'], $campaignId, $entryId]);
            mapSyncCodexPublishedNote($pdo, $campaignId, $entryId);
            mapSyncCodexPublicationSheet($pdo, $campaignId, $entryId, true);
        } else {
            $statement = $pdo->prepare('UPDATE campaign_notes SET codex_published = 0, revision = revision + 1
                WHERE campaign_id = ? AND codex_entry_id = ? AND codex_published = 1');
            $statement->execute([$campaignId, $entryId]);
        }
        mapTouchCampaign($pdo, $campaignId);
        $pdo->prepare('UPDATE campaign_board_state SET revision = revision + 1 WHERE campaign_id = ?')->execute([$campaignId]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function mapSyncCodexPublishedNote(PDO $pdo, int $campaignId, int $entryId, bool $sourceChanged = false): void
{
    $statement = $pdo->prepare('SELECT entry.title, entry.category, entry.body, entry.body_html, image.mime, image.content AS bytes,
            note.id, note.title AS note_title, note.content AS note_content, note.options
        FROM campaign_notes note
        JOIN campaign_codex_entries entry ON entry.id = note.codex_entry_id AND entry.campaign_id = note.campaign_id
        LEFT JOIN campaign_codex_media image ON image.id = entry.image_id AND image.campaign_id = entry.campaign_id
        WHERE note.campaign_id = ? AND note.codex_entry_id = ?');
    $statement->execute([$campaignId, $entryId]);
    $row = $statement->fetch();
    $statement->closeCursor();
    if (!$row) {
        return;
    }
    $options = mapDocumentOptions($row);
    $wasCharacter = ($options['type'] ?? null) === 'character';
    $content = mapCodexLibraryStoredContent($row['body_html'] ?? mapCodexTextAsHtml($row['body']));
    $title = mb_substr($row['title'], 0, 160);
    $options['image'] = $row['bytes'] === null ? '' : 'data:' . $row['mime'] . ';base64,' . base64_encode($row['bytes']);
    $options['type'] = $row['category'] === 'Personnages' ? 'character' : 'document';
    $options['rich'] = true;
    $encoded = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if ($sourceChanged || $row['note_title'] !== $title || $row['note_content'] !== $content || $row['options'] !== $encoded) {
        $pdo->prepare("UPDATE campaign_notes SET title = ?, content = ?, options = ?, revision = revision + 1,
            updated_at = strftime('%Y-%m-%d %H:%M:%f', 'now') WHERE id = ?")
            ->execute([$title, $content, $encoded, $row['id']]);
        $pdo->prepare('UPDATE campaign_board_state SET revision = revision + 1 WHERE campaign_id = ?')->execute([$campaignId]);
    }
    if ($wasCharacter !== ($options['type'] === 'character')) {
        mapSyncCodexPublicationSheet($pdo, $campaignId, $entryId, true);
    }
}

function mapCodexLibraryStoredContent(string $html): string
{
    return mb_strlen($html) <= 200000 ? $html : '';
}

function mapSyncLibraryNoteToCodex(PDO $pdo, array $note, string $title, string $content, array $options, int $userId): void
{
    $previous = mapDocumentOptions($note);
    $sourceContent = $note['codex_body_html'] ?? mapCodexTextAsHtml($note['codex_body']);
    if ($title === $note['codex_title'] && $content === $sourceContent && ($options['image'] ?? '') === ($previous['image'] ?? '')) {
        return;
    }
    $campaignId = (int) $note['campaign_id'];
    $entryId = (int) $note['codex_entry_id'];
    $entry = mapCodexEntry($pdo, $campaignId, $entryId);
    $imageId = $entry['image_id'];
    if (($options['image'] ?? '') !== ($previous['image'] ?? '')) {
        $imageId = empty($options['image']) ? null :
            mapCodexStoreImage($pdo, $campaignId, base64_decode(explode(',', $options['image'], 2)[1], true));
    }
    $plain = mapCodexPlainText($content);
    $pdo->prepare('UPDATE campaign_codex_entries SET title = ?, body = ?, body_html = ?, image_id = ?, revision = revision + 1
        WHERE campaign_id = ? AND id = ?')->execute([$title, $plain, $content, $imageId, $campaignId, $entryId]);
    $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
    mapCodexSaveMentions($pdo, $campaignId, $entryId, $plain, $details);
    mapCodexRecordActivity($pdo, $campaignId, $entryId, (int) $entry['revision'] + 1, 'updated', $userId);
    if ($imageId !== $entry['image_id']) {
        mapCodexRemoveUnusedImage($pdo, $campaignId, $entry['image_id']);
    }
    $pdo->prepare('UPDATE campaign_board_state SET revision = revision + 1 WHERE campaign_id = ?')->execute([$campaignId]);
}

function mapSyncCodexPublicationSheet(PDO $pdo, int $campaignId, int $id, bool $fromCodex): void
{
    if ($fromCodex) {
        $statement = $pdo->prepare("SELECT note.id AS target_id, sheet.template_id, sheet.field_values
            FROM campaign_notes note
            LEFT JOIN campaign_codex_character_sheets sheet ON sheet.entry_id = note.codex_entry_id
                AND json_extract(note.options, '$.type') = 'character'
            WHERE note.campaign_id = ? AND note.codex_entry_id = ?");
        $table = 'character_sheets';
        $identity = 'note_id';
    } else {
        $statement = $pdo->prepare('SELECT note.codex_entry_id AS target_id, sheet.template_id, sheet.field_values
            FROM campaign_notes note JOIN character_sheets sheet ON sheet.note_id = note.id
            WHERE note.campaign_id = ? AND note.id = ? AND note.codex_entry_id IS NOT NULL');
        $table = 'campaign_codex_character_sheets';
        $identity = 'entry_id';
    }
    $statement->execute([$campaignId, $id]);
    $row = $statement->fetch();
    $statement->closeCursor();
    if (!$row) {
        return;
    }
    if ($row['template_id'] === null) {
        $pdo->prepare("DELETE FROM $table WHERE $identity = ?")->execute([$row['target_id']]);
        return;
    }
    $columns = $fromCodex ? '' : ', campaign_id';
    $placeholders = $fromCodex ? '' : ', ?';
    $values = [$row['target_id'], $row['template_id'], $row['field_values']];
    if (!$fromCodex) {
        $values[] = $campaignId;
    }
    $pdo->prepare("INSERT INTO $table ($identity, template_id, field_values$columns) VALUES (?, ?, ?$placeholders)
        ON CONFLICT ($identity) DO UPDATE SET template_id = excluded.template_id, field_values = excluded.field_values,
            revision = $table.revision + 1
        WHERE $table.template_id <> excluded.template_id OR $table.field_values <> excluded.field_values")->execute($values);
}
