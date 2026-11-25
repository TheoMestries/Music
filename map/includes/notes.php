<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/document-options.php';

final class MapNoteConflictException extends RuntimeException
{
    public function __construct(private readonly array $latestNote)
    {
        parent::__construct('Cette note a ete modifiee dans un autre onglet.');
    }

    public function getLatestNote(): array
    {
        return $this->latestNote;
    }
}

function mapValidateNoteTitle(string $title): string
{
    $title = trim($title);

    if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
        throw new DomainException('Le titre de la note doit contenir entre 1 et 160 caracteres.');
    }

    return $title;
}

function mapValidateNoteContent(string $content): string
{
    if (mb_strlen($content, 'UTF-8') > 200000) {
        throw new DomainException('Le contenu de la note ne peut pas depasser 200 000 caracteres.');
    }

    return $content;
}

function mapValidateNoteFolder(?string $folder): ?string
{
    $folder = trim((string) $folder);

    if ($folder === '') {
        return null;
    }

    if (mb_strlen($folder, 'UTF-8') > 80) {
        throw new DomainException('Le nom du dossier ne peut pas depasser 80 caracteres.');
    }

    return $folder;
}

function mapValidateNoteVisibility(string $visibility): string
{
    if (!in_array($visibility, ['personal', 'gm', 'shared'], true)) {
        throw new DomainException('Visibilite de note invalide.');
    }

    return $visibility;
}

function mapNormalizeNoteTags(mixed $tags): array
{
    if (is_string($tags)) {
        $tags = explode(',', $tags);
    }

    if (!is_array($tags)) {
        throw new DomainException('La liste des tags est invalide.');
    }

    $normalized = [];
    $seen = [];

    foreach ($tags as $tag) {
        if (!is_string($tag)) {
            throw new DomainException('Un tag est invalide.');
        }

        $tag = preg_replace('/\s+/u', ' ', trim($tag)) ?? '';
        if ($tag === '') {
            continue;
        }

        if (str_contains($tag, ',') || mb_strlen($tag, 'UTF-8') > 30) {
            throw new DomainException('Chaque tag doit contenir au maximum 30 caracteres et ne pas contenir de virgule.');
        }

        $key = mb_strtolower($tag, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $tag;
    }

    if (count($normalized) > 20) {
        throw new DomainException('Une note ne peut pas contenir plus de 20 tags.');
    }

    natcasesort($normalized);

    return array_values($normalized);
}

function mapCanUseGmNotes(array $access): bool
{
    return in_array((string) ($access['current_user_role'] ?? ''), ['admin', 'owner', 'gm'], true);
}

function mapGetCampaignNoteRecord(PDO $pdo, int $campaignId, int $noteId): ?array
{
    $statement = $pdo->prepare(
        'SELECT n.id,
                n.campaign_id,
                n.author_user_id,
                n.title,
                n.content, n.options,
                n.folder,
                n.visibility,
                n.revision,
                n.created_at,
                n.updated_at,
                author.username AS author_username,
                author.display_name AS author_display_name
         FROM campaign_notes AS n
         INNER JOIN users AS author ON author.id = n.author_user_id
         WHERE n.id = :note_id
           AND n.campaign_id = :campaign_id
         LIMIT 1'
    );
    $statement->execute([
        ':note_id' => $noteId,
        ':campaign_id' => $campaignId,
    ]);
    $note = $statement->fetch();

    return is_array($note) ? $note : null;
}

function mapGetCampaignNoteTags(PDO $pdo, int $noteId): array
{
    $statement = $pdo->prepare(
        'SELECT tag FROM campaign_note_tags WHERE note_id = :note_id ORDER BY tag COLLATE NOCASE ASC'
    );
    $statement->execute([':note_id' => $noteId]);

    return array_map(
        static fn(array $row): string => (string) $row['tag'],
        $statement->fetchAll()
    );
}

function mapCanViewCampaignNote(array $note, array $access, array $currentUser): bool
{
    if (mapDocumentGrant($note, $currentUser, 'readers') || mapDocumentGrant($note, $currentUser, 'editors')) return true;
    // Explicit journal permissions replace the former shared visibility setting.
    if (array_key_exists('readers', mapDocumentOptions($note))) {
        return (int) $note['author_user_id'] === (int) $currentUser['user_id']
            || ((string) $note['visibility'] !== 'personal' && mapCanUseGmNotes($access));
    }
    return match ((string) $note['visibility']) {
        'personal' => (int) $note['author_user_id'] === (int) $currentUser['user_id'],
        'gm' => mapCanUseGmNotes($access),
        'shared' => true,
        default => false,
    };
}

function mapCanEditCampaignNote(array $note, array $access, array $currentUser): bool
{
    if (mapDocumentGrant($note, $currentUser, 'editors')) return true;
    if ((int) $note['author_user_id'] === (int) $currentUser['user_id']) {
        return true;
    }

    return (string) $note['visibility'] !== 'personal' && mapCanUseGmNotes($access);
}

function mapAssertNoteVisibilityAllowed(string $visibility, array $access, array $currentUser, ?array $note = null): void
{
    if ($visibility === 'gm' && !mapCanUseGmNotes($access)) {
        mapThrowForbidden('Seul un MJ peut creer une note reservee aux MJ.');
    }

    if (
        $note !== null
        && (int) $note['author_user_id'] !== (int) $currentUser['user_id']
        && $visibility !== (string) $note['visibility']
    ) {
        mapThrowForbidden('Seul l auteur peut modifier la visibilite de cette note.');
    }
}

function mapPublicNoteData(PDO $pdo, array $note, array $access, array $currentUser): array
{
    return [
        'id' => (int) $note['id'],
        'campaignId' => (int) $note['campaign_id'],
        'title' => (string) $note['title'],
        'options' => array_merge(mapDocumentOptions($note), ['gmNotes' => mapCanUseGmNotes($access) ? (mapDocumentOptions($note)['gmNotes'] ?? '') : '']),
        'content' => (string) $note['content'],
        'folder' => $note['folder'] !== null ? (string) $note['folder'] : null,
        'tags' => mapGetCampaignNoteTags($pdo, (int) $note['id']),
        'visibility' => (string) $note['visibility'],
        'revision' => (int) $note['revision'],
        'createdAt' => (string) $note['created_at'],
        'updatedAt' => (string) $note['updated_at'],
        'author' => [
            'id' => (int) $note['author_user_id'],
            'username' => (string) $note['author_username'],
            'displayName' => (string) $note['author_display_name'],
        ],
        'canEdit' => mapCanEditCampaignNote($note, $access, $currentUser),
        'canManagePermissions' => mapCanUseGmNotes($access) || (int) $note['author_user_id'] === (int) $currentUser['user_id'],
        'canDelete' => mapCanEditCampaignNote($note, $access, $currentUser),
    ];
}

function mapListCampaignNotes(int $campaignId, array $currentUser): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    return array_map(
        static fn(array $note): array => mapPublicNoteData($pdo, $note, $access, $currentUser),
        mapVisibleCampaignNoteRows($pdo, $campaignId, $access, $currentUser)
    );
}

function mapVisibleCampaignNoteRows(PDO $pdo, int $campaignId, array $access, array $currentUser, bool $summary = false): array
{
    $documentFields = $summary
        ? "json_remove(n.options, '$.image', '$.tokenImage', '$.gmNotes') AS options"
        : 'n.content, n.options';
    $statement = $pdo->prepare(
        "SELECT n.id,
                n.campaign_id,
                n.author_user_id,
                n.title,
                $documentFields,
                n.folder,
                n.visibility,
                n.revision,
                n.created_at,
                n.updated_at,
                author.username AS author_username,
                author.display_name AS author_display_name
         FROM campaign_notes AS n
         INNER JOIN users AS author ON author.id = n.author_user_id
         WHERE n.campaign_id = :campaign_id
           AND (
               json_type(n.options, '$.readers') IS NOT NULL OR json_type(n.options, '$.editors') IS NOT NULL OR n.visibility = 'shared'
               OR (n.visibility = 'personal' AND n.author_user_id = :user_id)
               OR (n.visibility = 'gm' AND :can_view_gm = 1)
           )
         ORDER BY datetime(n.updated_at) DESC, n.id DESC"
    );
    $statement->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
    $statement->bindValue(':user_id', (int) $currentUser['user_id'], PDO::PARAM_INT);
    $statement->bindValue(':can_view_gm', mapCanUseGmNotes($access) ? 1 : 0, PDO::PARAM_INT);
    $statement->execute();

    return array_values(array_filter($statement->fetchAll(), static fn(array $note): bool => mapCanViewCampaignNote($note, $access, $currentUser)));
}

function mapCampaignLibraryRevision(int $campaignId, array $access, array $currentUser, array $folders): string
{
    $rows = mapVisibleCampaignNoteRows(mapGetDatabase(), $campaignId, $access, $currentUser, true);
    $versions = array_map(static fn(array $note): array => [
        $note['id'], $note['revision'], $note['author_username'], $note['author_display_name'],
    ], $rows);
    return hash('sha256', json_encode([$access['current_user_role'], $folders, $versions], JSON_THROW_ON_ERROR));
}

function mapGetCampaignNote(int $campaignId, int $noteId, array $currentUser): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    $note = mapGetCampaignNoteRecord($pdo, $campaignId, $noteId);

    if ($note === null || !mapCanViewCampaignNote($note, $access, $currentUser)) {
        mapThrowNotFound('Note introuvable.');
    }

    return mapPublicNoteData($pdo, $note, $access, $currentUser);
}

function mapReplaceCampaignNoteTags(PDO $pdo, int $noteId, array $tags): void
{
    $deleteStatement = $pdo->prepare('DELETE FROM campaign_note_tags WHERE note_id = :note_id');
    $deleteStatement->execute([':note_id' => $noteId]);

    if ($tags === []) {
        return;
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO campaign_note_tags (note_id, tag) VALUES (:note_id, :tag)'
    );

    foreach ($tags as $tag) {
        $insertStatement->execute([
            ':note_id' => $noteId,
            ':tag' => $tag,
        ]);
    }
}

function mapCreateCampaignNote(
    int $campaignId,
    string $title,
    string $content,
    ?string $folder,
    mixed $tags,
    string $visibility,
    array $currentUser,
    string $type = 'document',
    ?array $options = null
): array {
    if (!in_array($type, ['document', 'character'], true)) throw new DomainException('Type de document invalide.');
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $title = mapValidateNoteTitle($title);
    $content = mapValidateNoteContent($content);
    $folder = mapValidateNoteFolder($folder);
    $tags = mapNormalizeNoteTags($tags);
    $visibility = mapValidateNoteVisibility($visibility);
    mapAssertNoteVisibilityAllowed($visibility, $access, $currentUser);
    $validatedOptions = ['type' => $type];
    if ($options !== null) {
        $validatedOptions = mapValidateDocumentOptions($options, [
            'campaign_id' => $campaignId,
            'author_user_id' => $currentUser['user_id'],
            'options' => json_encode($validatedOptions, JSON_THROW_ON_ERROR),
        ], $access, $currentUser);
        if ($validatedOptions['rich']) $content = mapDocumentHtml($content);
    }
    $pdo = mapGetDatabase();

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO campaign_notes (
                campaign_id, author_user_id, title, content, folder, visibility, revision, created_at, updated_at, options
             ) VALUES (
                :campaign_id, :author_user_id, :title, :content, :folder, :visibility, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :options
             )'
        );
        $statement->execute([
            ':campaign_id' => $campaignId,
            ':author_user_id' => (int) $currentUser['user_id'],
            ':options' => json_encode($validatedOptions, JSON_THROW_ON_ERROR),
            ':title' => $title,
            ':content' => $content,
            ':folder' => $folder,
            ':visibility' => $visibility,
        ]);
        $noteId = (int) $pdo->lastInsertId();
        if ($type === 'character' && $access['campaign']['default_sheet_template_id'] !== null) {
            $pdo->prepare('INSERT INTO character_sheets(note_id,template_id)
                SELECT ?,t.id FROM campaigns c JOIN character_sheet_templates t ON t.id=c.default_sheet_template_id
                WHERE c.id=? AND t.campaign_id=c.id')->execute([$noteId,$campaignId]);
        }
        mapReplaceCampaignNoteTags($pdo, $noteId, $tags);
        mapTouchCampaign($pdo, $campaignId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return mapGetCampaignNote($campaignId, $noteId, $currentUser);
}

function mapUpdateCampaignNote(
    int $campaignId,
    int $noteId,
    int $expectedRevision,
    string $title,
    string $content,
    ?string $folder,
    mixed $tags,
    string $visibility,
    array $currentUser,
    ?array $options = null
): array {
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    $note = mapGetCampaignNoteRecord($pdo, $campaignId, $noteId);

    if ($note === null || !mapCanViewCampaignNote($note, $access, $currentUser)) {
        mapThrowNotFound('Note introuvable.');
    }

    if (!mapCanEditCampaignNote($note, $access, $currentUser)) {
        mapThrowForbidden('Vous ne pouvez pas modifier cette note.');
    }

    if ($expectedRevision < 1) {
        throw new DomainException('Revision de note invalide.');
    }

    $title = mapValidateNoteTitle($title);
    $content = mapValidateNoteContent($content);
    $folder = mapValidateNoteFolder($folder);
    $tags = mapNormalizeNoteTags($tags);
    $visibility = mapValidateNoteVisibility($visibility);
    mapAssertNoteVisibilityAllowed($visibility, $access, $currentUser, $note);

    $validatedOptions = $options === null ? mapDocumentOptions($note) : mapValidateDocumentOptions($options, $note, $access, $currentUser);
    if (!empty($validatedOptions['rich'])) $content = mapDocumentHtml($content);
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "UPDATE campaign_notes
             SET options = :options, title = :title,
                 content = :content,
                 folder = :folder,
                 visibility = :visibility,
                 revision = revision + 1,
                 updated_at = strftime('%Y-%m-%d %H:%M:%f', 'now')
             WHERE id = :note_id
               AND campaign_id = :campaign_id
               AND revision = :revision"
        );
        $statement->execute([
            ':options' => json_encode($validatedOptions, JSON_THROW_ON_ERROR),
            ':title' => $title,
            ':content' => $content,
            ':folder' => $folder,
            ':visibility' => $visibility,
            ':note_id' => $noteId,
            ':campaign_id' => $campaignId,
            ':revision' => $expectedRevision,
        ]);

        if ($statement->rowCount() !== 1) {
            $pdo->rollBack();
            throw new MapNoteConflictException(mapGetCampaignNote($campaignId, $noteId, $currentUser));
        }

        mapReplaceCampaignNoteTags($pdo, $noteId, $tags);
        mapTouchCampaign($pdo, $campaignId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    return mapGetCampaignNote($campaignId, $noteId, $currentUser);
}

function mapListNoteFolders(int $campaignId, array $currentUser): array
{
    mapRequireCampaignAccess($campaignId, $currentUser);
    $statement = mapGetDatabase()->prepare('SELECT name FROM campaign_note_folders WHERE campaign_id = ? AND user_id = ? ORDER BY name COLLATE NOCASE');
    $statement->execute([$campaignId, $currentUser['user_id']]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function mapCreateNoteFolder(int $campaignId, string $name, array $currentUser): string
{
    mapRequireCampaignAccess($campaignId, $currentUser);
    $name = mapValidateNoteFolder($name);
    if ($name === null) throw new DomainException('Le nom du dossier est obligatoire.');
    $statement = mapGetDatabase()->prepare('INSERT OR IGNORE INTO campaign_note_folders (campaign_id,user_id,name) VALUES (?,?,?)');
    $statement->execute([$campaignId, $currentUser['user_id'], $name]);
    return $name;
}

function mapMoveCampaignNote(int $campaignId, int $noteId, ?string $folder, array $currentUser): array
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    $note = mapGetCampaignNoteRecord($pdo, $campaignId, $noteId);
    if (!$note || !mapCanViewCampaignNote($note, $access, $currentUser)) mapThrowNotFound('Document introuvable.');
    if (!mapCanEditCampaignNote($note, $access, $currentUser)) mapThrowForbidden('Vous ne pouvez pas déplacer ce document.');
    $folder = mapValidateNoteFolder($folder);
    // Remember the source folder even when its final document is moved away.
    if ($note['folder']) mapCreateNoteFolder($campaignId, $note['folder'], $currentUser);
    if ($folder) mapCreateNoteFolder($campaignId, $folder, $currentUser);
    $statement = $pdo->prepare('UPDATE campaign_notes SET folder = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE campaign_id = ? AND id = ?');
    $statement->execute([$folder, $campaignId, $noteId]);
    return mapGetCampaignNote($campaignId, $noteId, $currentUser);
}

function mapDeleteCampaignNote(int $campaignId, int $noteId, array $currentUser): void
{
    $access = mapRequireCampaignAccess($campaignId, $currentUser);
    $pdo = mapGetDatabase();
    $note = mapGetCampaignNoteRecord($pdo, $campaignId, $noteId);

    if ($note === null || !mapCanViewCampaignNote($note, $access, $currentUser)) {
        mapThrowNotFound('Note introuvable.');
    }

    if (!mapCanEditCampaignNote($note, $access, $currentUser)) {
        mapThrowForbidden('Vous ne pouvez pas supprimer cette note.');
    }

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'DELETE FROM campaign_notes WHERE id = :note_id AND campaign_id = :campaign_id'
        );
        $statement->execute([
            ':note_id' => $noteId,
            ':campaign_id' => $campaignId,
        ]);
        mapTouchCampaign($pdo, $campaignId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
