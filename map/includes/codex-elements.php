<?php
declare(strict_types=1);

require_once __DIR__ . '/campaign-codex.php';
require_once __DIR__ . '/codex-element-access.php';

const MAP_CODEX_ELEMENT_SECTIONS = ['relations' => 'Relations', 'notes' => 'Notes associées'] + MAP_CODEX_SHEET_SECTIONS;

function mapCodexNewElementKey(): string
{
    return bin2hex(random_bytes(12));
}

function mapCodexIsElementKey(mixed $key): bool
{
    return is_string($key) && preg_match('/^[a-f0-9]{24}$/D', $key) === 1;
}

function mapCodexElementDefinition(string $section, string $category): array
{
    if ($section === 'relations') {
        return ['storage' => null, 'targetCategory' => '', 'label' => 'Relation', 'fields' => ['label' => ['Libellé', 'text']]];
    }
    if ($section === 'notes') {
        return ['storage' => 'posts', 'targetCategory' => null, 'label' => 'Note', 'fields' => [
            'name' => ['Titre', 'text'], 'entry' => ['Texte', 'memo'], 'is_pinned' => ['Épinglé', 'flag'],
        ]];
    }
    if (!isset(MAP_CODEX_SHEET_SECTIONS[$section])) {
        mapThrowNotFound('Section introuvable.');
    }
    [$storage, $targetCategory, , , $label, $fields] = mapCodexSectionDefinition($section, $category);
    $fields['is_pinned'] = ['Épinglé', 'flag'];
    return ['storage' => $storage, 'targetCategory' => $targetCategory, 'label' => $label, 'fields' => $fields];
}

function mapCodexAssignElementKeys(stdClass $details, string $category): bool
{
    $changed = false;
    $seen = [];
    $keys = array_unique(array_merge(['posts', 'members', 'organisation_memberships'], mapCodexSheetDetailKeys($category)));
    foreach ($keys as $storage) {
        foreach (mapCodexSectionRows($details, $storage) as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $key = $row->codex_element_key ?? null;
            if (!mapCodexIsElementKey($key) || isset($seen[$key])) {
                $key = $row->codex_element_key = mapCodexNewElementKey();
                $changed = true;
            }
            $seen[$key] = true;
        }
    }
    return $changed;
}

function mapCodexElementRows(array $entry, string $section): array
{
    $definition = mapCodexElementDefinition($section, $entry['category']);
    $result = [];
    if ($section === 'relations') {
        foreach ($entry['links'] ?? [] as $link) {
            $key = $link['element_key'] ?? null;
            if ((int) $link['entry_id'] === (int) $entry['id'] && !mapCodexIsMention($link) && mapCodexIsElementKey($key)) {
                $result[$key] = ['key' => $key, 'private' => empty($link['players_visible']),
                    'targetId' => (int) $link['target_id'], 'fields' => ['label' => $link['label']], 'raw' => $link];
            }
        }
        return $result;
    }

    $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
    foreach (mapCodexSectionRows($details, $definition['storage']) as $row) {
        if (!$row instanceof stdClass || !mapCodexIsElementKey($row->codex_element_key ?? null)) {
            continue;
        }
        $key = $row->codex_element_key;
        $private = is_bool($row->codex_element_private ?? null) ? $row->codex_element_private
            : (!mapCodexPlayerRowIsPublic($row, $section !== 'medias')
                || ($section === 'proprietes' && (!empty($details->is_attributes_private) || !empty($details->attributes_private))));
        $fields = [];
        foreach ($definition['fields'] as $field => [, $type]) {
            $fields[$field] = mapCodexSectionValue($row, $field, $type);
        }
        $targetId = $row->codex_entry_id ?? null;
        $result[$key] = ['key' => $key, 'private' => $private,
            'targetId' => (is_numeric($targetId) && (int) $targetId > 0) ? (int) $targetId : null,
            'fields' => $fields, 'raw' => $row];
    }
    return $result;
}

function mapCodexElement(PDO $pdo, int $campaignId, int $entryId, string $section, string $key): array
{
    return mapCodexElementFromEntry(mapCodexEntry($pdo, $campaignId, $entryId), $section, $key);
}

function mapCodexElementFromEntry(array $entry, string $section, string $key): array
{
    $element = mapCodexElementRows($entry, $section)[$key] ?? null;
    if ($element === null) {
        mapThrowNotFound('Élément introuvable.');
    }
    return $element;
}

function mapCodexElementTargetReadable(PDOStatement $query, int $campaignId, int $targetId, array $permissions): bool
{
    $query->execute([$campaignId, $targetId]);
    $target = $query->fetch();
    $query->closeCursor();
    return $target !== false
        && mapCodexCanRead($permissions, $target['category'], $targetId, (bool) $target['private_origin']);
}

function mapCodexElementHasUnresolvedReference(array $entry, string $section, array $element): bool
{
    if ($element['targetId'] !== null || !isset(MAP_CODEX_SHEET_SECTIONS[$section]) || empty($entry['source_key'])) {
        return false;
    }
    [, , , $legacyKey] = mapCodexSectionDefinition($section, $entry['category']);
    return ($legacyKey !== null && !empty($element['raw']->{$legacyKey}))
        || ($section === 'inventaire' && isset($element['raw']->item));
}

function mapCodexElementCheckReferences(PDOStatement $query, int $campaignId, array $fields, array $permissions): void
{
    if (mapCodexCanManage($permissions)) {
        return;
    }
    $references = [];
    foreach ($fields as $value) {
        if (is_string($value)) {
            preg_match_all(MAP_CODEX_MENTION_TOKEN, $value, $matches);
            foreach ($matches[1] as $match) {
                $references[$match] = true;
            }
        }
    }
    foreach (array_keys($references) as $reference) {
        if (!mapCodexElementTargetReadable($query, $campaignId, (int) $reference, $permissions)) {
            throw new DomainException('Utilisez uniquement des mentions vers les fiches que vous pouvez consulter.');
        }
    }
}

function mapCodexApplyElementRow(PDO $pdo, int $campaignId, array $entry, string $section, ?array $element,
    array $fields, ?int $targetId): stdClass
{
    $previous = $element['raw'] ?? null;
    if ($section === 'notes') {
        $post = $previous instanceof stdClass ? clone $previous : new stdClass();
        $post->name = mapCodexText($fields['name'], 250, 'Le titre de la note', true);
        $body = mapCodexText($fields['entry'], 100000, 'Le texte de la note');
        if (mapCodexPlainText($post->entry ?? '') !== $body) {
            $post->entry = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $post->is_pinned = $fields['is_pinned'] === '1';
        return $post;
    }

    $storage = mapCodexElementDefinition($section, $entry['category'])['storage'];
    $sectionForm = [
        'submitted' => '1',
        'original' => [$previous === null ? '' : '0'],
        'codex_entry_id' => [$targetId === null ? '' : (string) $targetId],
    ];
    foreach ($fields as $field => $value) {
        $sectionForm[$field] = [$value];
    }
    $submission = ['codexSection' => [$section => $sectionForm]];
    $draft = (object) [$storage => $previous === null ? [] : [$previous]];
    mapCodexApplySheetSections($draft, $entry['category'], $submission);
    mapCodexValidateSheetTargets($pdo, $campaignId, (int) $entry['id'], $entry['category'], $submission);
    return $draft->{$storage}[0];
}

function mapCodexElementAccess(PDO $pdo, int $campaignId, int $entryId, array $actor): array
{
    $actorId = mapValidatePositiveInt($actor['user_id'] ?? null, 'Utilisateur');
    $access = mapRequireCampaignAccess($campaignId, $actor);
    $permissions = mapCodexPermissionContext($pdo, $campaignId, $access, $actorId);
    $entry = mapCodexEntry($pdo, $campaignId, $entryId);
    if (!mapCodexCanRead($permissions, $entry['category'], $entryId, (bool) $entry['private_origin'])) {
        mapThrowNotFound('Fiche introuvable.');
    }
    $elementPermissions = mapCodexElementPermissions($pdo, $campaignId, $entryId, $permissions);
    $canEditFiche = mapCodexCanEdit($permissions, $entry['category'], $entryId, (bool) $entry['private_origin']);
    return [$entry, $actorId, $permissions, $elementPermissions, $canEditFiche];
}

function mapCodexTouchElementEntry(PDO $pdo, int $campaignId, array $entry, int $actorId, stdClass $details): void
{
    $revision = (int) $entry['revision'];
    $encoded = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $update = $pdo->prepare('UPDATE campaign_codex_entries SET details = ?, revision = revision + 1
        WHERE campaign_id = ? AND id = ? AND revision = ?');
    $update->execute([$encoded, $campaignId, $entry['id'], $revision]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('La fiche a été modifiée ailleurs. Rechargez-la avant de réessayer.');
    }
    mapCodexRecordActivity($pdo, $campaignId, (int) $entry['id'], $revision + 1, 'updated', $actorId);
}

function mapCodexSaveElement(PDO $pdo, int $campaignId, int $entryId, string $section, ?string $key, array $form, array $actor): string
{
    $revision = mapValidatePositiveInt($form['revision'] ?? null, 'Version de la fiche');
    $pdo->beginTransaction();
    try {
        [$entry, $actorId, $permissions, $elementPermissions, $canEditFiche] = mapCodexElementAccess($pdo, $campaignId, $entryId, $actor);
        $definition = mapCodexElementDefinition($section, $entry['category']);
        $element = $key === null ? null : mapCodexElementFromEntry($entry, $section, $key);
        $key ??= mapCodexNewElementKey();
        if ($element !== null && !mapCodexElementCanRead($elementPermissions, $section, $key, $element['private'])) {
            mapThrowNotFound('Élément introuvable.');
        }
        $canSave = $element === null ? mapCodexElementCanCreate($elementPermissions, $section, $canEditFiche)
            : mapCodexElementCanEdit($elementPermissions, $section, $key, $canEditFiche, $element['private']);
        if (!$canSave) {
            mapThrowForbidden('Vous n’avez pas le droit d’enregistrer cet élément.');
        }
        if ((int) $entry['revision'] !== $revision) {
            throw new DomainException('La fiche a été modifiée ailleurs. Rechargez-la avant de réessayer.');
        }

        $manager = mapCodexCanManage($permissions);
        $private = $element['private'] ?? $manager;
        if ($manager && array_key_exists('private', $form)) {
            if (!in_array($form['private'], ['0', '1'], true)) {
                throw new DomainException('La confidentialité de l’élément est invalide.');
            }
            $private = $form['private'] === '1';
        }
        $fields = [];
        foreach ($definition['fields'] as $field => [, $type]) {
            $value = $form[$field] ?? ($element['fields'][$field] ?? ($type === 'flag' ? '0' : ''));
            if (!is_string($value) || ($type === 'flag' && !in_array($value, ['0', '1'], true))) {
                throw new DomainException('Les données de l’élément sont invalides.');
            }
            $fields[$field] = $value;
        }
        $targetId = $element['targetId'] ?? null;
        $query = $pdo->prepare('SELECT category, ' . MAP_CODEX_PRIVATE_ORIGIN_SQL . ' AS private_origin
            FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
        if (!$manager && $element !== null && (mapCodexElementHasUnresolvedReference($entry, $section, $element)
            || ($targetId !== null && !mapCodexElementTargetReadable($query, $campaignId, $targetId, $permissions)))) {
            mapThrowNotFound('Élément introuvable.');
        }
        if ($definition['targetCategory'] !== null && array_key_exists('target', $form)) {
            $targetId = $form['target'] === '' ? null : mapValidatePositiveInt($form['target'], 'Fiche liée');
        }
        if ($targetId !== null && ($targetId === $entryId || !mapCodexElementTargetReadable($query, $campaignId, $targetId, $permissions))) {
            throw new DomainException('Choisissez une autre fiche disponible dans cette campagne.');
        }
        if (!$manager && $element !== null) {
            mapCodexElementCheckReferences($query, $campaignId, $element['fields'], $permissions);
        }
        mapCodexElementCheckReferences($query, $campaignId, $fields, $permissions);

        $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
        if ($section === 'relations') {
            if ($targetId === null) {
                throw new DomainException('Choisissez la fiche à relier.');
            }
            $label = mapCodexText($fields['label'], 250, 'Le libellé de la relation');
            if ($element === null) {
                $relation = $pdo->prepare("INSERT INTO campaign_codex_links
                    (campaign_id, entry_id, target_id, label, kind, players_visible, element_key)
                    VALUES (?, ?, ?, ?, 'relation', ?, ?)");
                $relation->execute([$campaignId, $entryId, $targetId, $label, $private ? 0 : 1, $key]);
            } else {
                $relation = $pdo->prepare("UPDATE campaign_codex_links SET target_id = ?, label = ?, players_visible = ?
                    WHERE campaign_id = ? AND entry_id = ? AND kind = 'relation' AND element_key = ?");
                $relation->execute([$targetId, $label, $private ? 0 : 1, $campaignId, $entryId, $key]);
            }
        } else {
            $storage = $definition['storage'];
            $rows = mapCodexSectionRows($details, $storage);
            $row = mapCodexApplyElementRow($pdo, $campaignId, $entry, $section, $element, $fields, $targetId);
            $row->codex_element_key = $key;
            if ($manager && ($element === null || array_key_exists('private', $form))) {
                $row->codex_element_private = $private;
            }
            if ($element === null) {
                $rows[] = $row;
            } else {
                foreach ($rows as $index => $previous) {
                    if (($previous->codex_element_key ?? null) === $key) {
                        $rows[$index] = $row;
                        break;
                    }
                }
            }
            $details->{$storage} = $rows;
        }
        if ($section === 'notes') {
            $body = $entry['body_html'] === null ? $entry['body'] : mapCodexPlainText($entry['body_html']);
            mapCodexSaveMentions($pdo, $campaignId, $entryId, $body, $details);
        }
        mapCodexTouchElementEntry($pdo, $campaignId, $entry, $actorId, $details);
        $pdo->commit();
        return $key;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function mapCodexDeleteElement(PDO $pdo, int $campaignId, int $entryId, string $section, string $key, int $revision, array $actor): void
{
    $pdo->beginTransaction();
    try {
        [$entry, $actorId, $permissions] = mapCodexElementAccess($pdo, $campaignId, $entryId, $actor);
        if (!mapCodexCanManage($permissions)) {
            mapThrowForbidden('Seuls les MJ peuvent supprimer un élément.');
        }
        mapCodexElementFromEntry($entry, $section, $key);
        if ((int) $entry['revision'] !== $revision) {
            throw new DomainException('La fiche a été modifiée ailleurs. Rechargez-la avant de réessayer.');
        }
        $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
        if ($section === 'relations') {
            $statement = $pdo->prepare("DELETE FROM campaign_codex_links
                WHERE campaign_id = ? AND entry_id = ? AND kind = 'relation' AND element_key = ?");
            $statement->execute([$campaignId, $entryId, $key]);
        } else {
            $storage = mapCodexElementDefinition($section, $entry['category'])['storage'];
            $details->{$storage} = array_values(array_filter(mapCodexSectionRows($details, $storage),
                static fn (mixed $row): bool => ($row->codex_element_key ?? null) !== $key));
        }
        mapCodexDeleteElementRules($pdo, $campaignId, $entryId, $section, $key);
        if ($section === 'notes') {
            $body = $entry['body_html'] === null ? $entry['body'] : mapCodexPlainText($entry['body_html']);
            mapCodexSaveMentions($pdo, $campaignId, $entryId, $body, $details);
        }
        mapCodexTouchElementEntry($pdo, $campaignId, $entry, $actorId, $details);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
