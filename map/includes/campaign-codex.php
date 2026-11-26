<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/codex-text.php';
require_once __DIR__ . '/codex-sections.php';
require_once __DIR__ . '/codex-calendar.php';
require_once __DIR__ . '/codex-timeline.php';
require_once __DIR__ . '/codex-maps.php';
require_once __DIR__ . '/codex-permissions.php';
require_once __DIR__ . '/codex-elements.php';
require_once __DIR__ . '/codex-activity.php';
require_once __DIR__ . '/codex-mentions.php';
require_once __DIR__ . '/codex-publications.php';

const MAP_CODEX_CATEGORIES = [
    'abilities' => 'Capacités',
    'calendars' => 'Calendriers',
    'characters' => 'Personnages',
    'creatures' => 'Créatures',
    'events' => 'Événements',
    'families' => 'Familles',
    'gallery' => 'Galerie',
    'items' => 'Objets',
    'journals' => 'Journaux',
    'locations' => 'Lieux',
    'maps' => 'Cartes',
    'notes' => 'Notes',
    'organisations' => 'Organisations',
    'quests' => 'Quêtes',
    'races' => 'Peuples',
    'tags' => 'Étiquettes',
    'timelines' => 'Chronologies',
];
const MAP_CODEX_IMAGE_BYTES = 8 * 1024 * 1024;
const MAP_CODEX_INDIVIDUAL_COLLECTIONS = [
    'posts', 'members', 'organisation_memberships', 'inventories', 'assets', 'abilities', 'entity_attributes', 'reminders',
];
const MAP_CODEX_PRIVATE_ORIGIN_SQL = "(source_key LIKE 'kanka:%' AND COALESCE(json_extract(details, '$.is_private'), 0) IN (1, '1', 'true'))";

const MAP_CODEX_MODULES = [
    'Personnages' => ['type' => 'Type', 'title' => 'Titre ou fonction', 'age' => 'Âge', 'sex' => 'Genre', 'pronouns' => 'Pronoms'],
    'Lieux' => ['type' => 'Type de lieu', 'title' => 'Autre nom', 'population' => 'Population', 'climate' => 'Climat'],
    'Organisations' => ['type' => 'Type', 'goal' => 'Objectif', 'headquarters' => 'Siège'],
    'Familles' => ['type' => 'Type', 'motto' => 'Devise'],
    'Peuples' => ['type' => 'Type', 'language' => 'Langues'],
    'Créatures' => ['type' => 'Type', 'habitat' => 'Habitat', 'size' => 'Taille'],
    'Objets' => ['type' => 'Type', 'price' => 'Prix', 'size' => 'Taille', 'weight' => 'Poids'],
    'Capacités' => ['type' => 'Type', 'charges' => 'Charges'],
    'Quêtes' => ['type' => 'Type', 'date' => 'Date', 'quest_status' => 'État', 'reward' => 'Récompense'],
    'Événements' => ['type' => 'Type', 'date' => 'Date'],
    'Journaux' => ['type' => 'Type', 'date' => 'Date', 'session' => 'Séance'],
    'Calendriers' => ['suffix' => 'Ère ou suffixe'],
    'Cartes' => ['type' => 'Type de carte', 'scale' => 'Échelle'],
];

function mapCodexText(mixed $value, int $max, string $label, bool $required = false): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new DomainException($label . ' invalide.');
    }
    $value = trim($value);
    if (mb_strlen($value) > $max || ($required && $value === '')) {
        throw new DomainException($label . ' doit contenir ' . ($required ? 'entre 1 et ' : 'au plus ') . $max . ' caractères.');
    }
    return $value;
}

function mapCodexPlainText(mixed $html): string
{
    if (!is_string($html) || $html === '') {
        return '';
    }
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
    $html = preg_replace('~<br\b[^>]*>|</(?:p|div|li|h[1-6]|blockquote|tr)\s*>~i', "\n", $html);
    $html = preg_replace('~<li\b[^>]*>~i', '• ', $html);
    $html = preg_replace('~</(?:td|th)\s*>~i', ' | ', $html);
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $plain));
}

function mapCodexEntries(PDO $pdo, int $campaignId): array
{
    $query = $pdo->prepare('SELECT entry.id, category, title, summary, image_id, source_key, ' . MAP_CODEX_PRIVATE_ORIGIN_SQL . ' AS private_origin,
                            (SELECT note.id FROM campaign_notes note WHERE note.codex_entry_id = entry.id AND note.codex_published = 1) AS published_note_id
                            FROM campaign_codex_entries entry WHERE entry.campaign_id = ?
                            ORDER BY title COLLATE NOCASE, id');
    $query->execute([$campaignId]);
    return $query->fetchAll(PDO::FETCH_UNIQUE);
}

function mapCodexEntry(PDO $pdo, int $campaignId, int $entryId): array
{
    $query = $pdo->prepare('SELECT entry.*, ' . MAP_CODEX_PRIVATE_ORIGIN_SQL . ' AS private_origin,
        (SELECT note.id FROM campaign_notes note WHERE note.codex_entry_id = entry.id AND note.codex_published = 1) AS published_note_id
        FROM campaign_codex_entries entry WHERE entry.campaign_id = ? AND entry.id = ?');
    $query->execute([$campaignId, $entryId]);
    $entry = $query->fetch();
    if ($entry === false) {
        mapThrowNotFound('Fiche introuvable.');
    }

    $query = $pdo->prepare(
        'SELECT entry_id, target_id, label, kind, players_visible, element_key FROM campaign_codex_links
         WHERE campaign_id = ? AND (entry_id = ? OR target_id = ?)'
    );
    $query->execute([$campaignId, $entryId, $entryId]);
    $entry['links'] = $query->fetchAll();
    return $entry;
}

function mapCodexCanReadImage(PDO $pdo, int $campaignId, int $imageId, array $permissions): bool
{
    if (mapCodexCanManage($permissions)) {
        return true;
    }
    $query = $pdo->prepare('SELECT id, category, ' . MAP_CODEX_PRIVATE_ORIGIN_SQL . ' AS private_origin
        FROM campaign_codex_entries WHERE campaign_id = ? AND image_id = ?');
    $query->execute([$campaignId, $imageId]);
    foreach ($query as $row) {
        if (mapCodexCanRead($permissions, $row['category'], (int) $row['id'], (bool) $row['private_origin'])) {
            return true;
        }
    }
    return false;
}

function mapCodexStoreImage(PDO $pdo, int $campaignId, string $bytes): int
{
    $type = strlen($bytes) <= MAP_CODEX_IMAGE_BYTES ? @getimagesizefromstring($bytes) : false;
    $mime = $type['mime'] ?? '';
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
        throw new DomainException('Choisissez une image PNG, JPEG, WebP ou GIF de 8 Mo maximum.');
    }

    $fingerprint = hash('sha256', $bytes);
    $insert = $pdo->prepare('INSERT INTO campaign_codex_media (campaign_id, fingerprint, mime, content)
        VALUES (?, ?, ?, ?) ON CONFLICT(campaign_id, fingerprint) DO NOTHING');
    $insert->bindValue(1, $campaignId, PDO::PARAM_INT);
    $insert->bindValue(2, $fingerprint);
    $insert->bindValue(3, $mime);
    $insert->bindValue(4, $bytes, PDO::PARAM_LOB);
    $insert->execute();

    $find = $pdo->prepare('SELECT id FROM campaign_codex_media WHERE campaign_id = ? AND fingerprint = ?');
    $find->execute([$campaignId, $fingerprint]);
    return (int) $find->fetchColumn();
}

function mapCodexRemoveUnusedImage(PDO $pdo, int $campaignId, ?int $imageId): void
{
    if ($imageId === null) {
        return;
    }
    $query = $pdo->prepare('DELETE FROM campaign_codex_media WHERE campaign_id = ? AND id = ?
        AND NOT EXISTS (SELECT 1 FROM campaign_codex_entries WHERE campaign_id = ? AND image_id = ?)');
    $query->execute([$campaignId, $imageId, $campaignId, $imageId]);
}

function mapCodexSaveEntry(PDO $pdo, int $campaignId, ?array $entry, array $form, ?string $image, ?int $actorId = null, ?bool $actorWasManager = null): int
{
    $category = mapCodexText($form['category'] ?? '', 100, 'La catégorie', true);
    $title = mapCodexText($form['title'] ?? '', 250, 'Le titre', true);
    $summary = mapCodexText($form['summary'] ?? '', 5000, 'Le résumé');
    $body = mapCodexText($form['body'] ?? '', 250000, 'La description');
    $bodyHtml = null;
    if (array_key_exists('body_html', $form)) {
        $bodyHtml = mapCodexRichHtml(mapCodexText($form['body_html'], 300000, 'La description mise en forme'));
        $body = mapCodexText(mapCodexPlainText($bodyHtml), 250000, 'La description');
    } elseif ($entry !== null && $body === $entry['body']) {
        $bodyHtml = $entry['body_html'];
    }
    $details = mapCodexText($form['details'] ?? '{}', 1000000, 'Les champs complémentaires') ?: '{}';
    try {
        $decoded = json_decode($details, false, 32, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new DomainException('Les champs complémentaires doivent former un objet JSON.');
        }
        $moduleFields = $form['moduleFields'] ?? [];
        if (!is_array($moduleFields)) {
            throw new DomainException('Champs du module invalides.');
        }
        foreach (MAP_CODEX_MODULES[$category] ?? [] as $key => $label) {
            if (!array_key_exists($key, $moduleFields)) {
                continue;
            }
            $value = mapCodexText($moduleFields[$key], 5000, $label);
            if ($value === '') {
                unset($decoded->{$key});
            } elseif (!is_scalar($decoded->{$key} ?? null) || (string) $decoded->{$key} !== $value) {
                $decoded->{$key} = $value;
            }
        }
        if (!is_array($form['fieldName'] ?? []) || !is_array($form['fieldValue'] ?? [])) {
            throw new DomainException('Champs complémentaires invalides.');
        }
        foreach ($form['fieldName'] ?? [] as $index => $name) {
            $value = $form['fieldValue'][$index] ?? '';
            if ($name !== '' || $value !== '') {
                $key = mapCodexText($name, 120, 'Le nom du champ', true);
                if (!isset(MAP_CODEX_MODULES[$category][$key], $moduleFields[$key])) {
                    $decoded->{$key} = mapCodexText($value, 5000, 'La valeur du champ');
                }
            }
        }
        if ($actorWasManager === null && isset($form['editPosts'])) {
            if (!is_array($form['postName'] ?? []) || !is_array($form['postBody'] ?? []) || !is_array($form['postOriginal'] ?? [])) {
                throw new DomainException('Notes associées invalides.');
            }
            $previousPosts = is_array($decoded->posts ?? null) ? $decoded->posts : [];
            $posts = [];
            foreach ($form['postName'] ?? [] as $index => $name) {
                $body = mapCodexText($form['postBody'][$index] ?? '', 100000, 'Le texte de la note');
                $postTitle = mapCodexText($name, 250, 'Le titre de la note', true);
                $original = filter_var($form['postOriginal'][$index] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $previous = $original === false ? null : ($previousPosts[$original] ?? null);
                $post = $previous instanceof stdClass ? clone $previous : new stdClass();
                $post->name = $postTitle;
                // Keep any original formatting when the visible text did not change.
                if (mapCodexPlainText($post->entry ?? '') !== $body) {
                    $post->entry = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $posts[] = $post;
            }
            $decoded->posts = $posts;
        }
        $entryIdForValidation = $entry === null ? null : (int) $entry['id'];
        if ($actorWasManager === null) {
            mapCodexApplySheetSections($decoded, $category, $form);
            mapCodexValidateSheetTargets($pdo, $campaignId, $entryIdForValidation, $category, $form);
        }
        if ($category === 'Calendriers') {
            mapCodexApplyCalendar($decoded, $form);
        }
        mapCodexApplyTimeline($pdo, $campaignId, $category, $entryIdForValidation, $decoded, $form);
        mapCodexApplyMapPins($pdo, $campaignId, $category, $decoded, $form, $entryIdForValidation);
        if ($actorWasManager !== null) {
            $previousDetails = json_decode($entry['details'] ?? '{}', false, 32, JSON_THROW_ON_ERROR);
            foreach ([...MAP_CODEX_INDIVIDUAL_COLLECTIONS, 'is_attributes_private', 'attributes_private'] as $collection) {
                unset($decoded->{$collection});
                if (property_exists($previousDetails, $collection)) {
                    $decoded->{$collection} = $previousDetails->{$collection};
                }
            }
        }
        mapCodexAssignElementKeys($decoded, $category);
        $details = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException) {
        throw new DomainException('Le JSON des champs complémentaires est invalide.');
    }

    if (!is_array($form['target'] ?? []) || !is_array($form['label'] ?? []) || !is_array($form['linkVisibility'] ?? [])) {
        throw new DomainException('Fiches liées invalides.');
    }
    $oldImage = $entry['image_id'] ?? null;
    $pdo->beginTransaction();
    try {
        $permissions = null;
        if ($actorWasManager !== null) {
            $actor = ['user_id' => $actorId];
            $access = mapRequireCampaignAccess($campaignId, $actor);
            $permissions = mapCodexPermissionContext($pdo, $campaignId, $access, (int) $actorId);
            $canSave = $entry === null ? mapCodexCanCreate($permissions, $category) :
                mapCodexCanEdit($permissions, $entry['category'], (int) $entry['id'], (bool) ($entry['private_origin'] ?? false));
            if (!$canSave || $actorWasManager !== mapCodexCanManage($permissions)
                || ($entry !== null && $category !== $entry['category'] && !mapCodexCanManage($permissions))) {
                mapThrowForbidden('Vous n’avez pas le droit d’enregistrer cette fiche.');
            }
        }
        $imageId = isset($form['removeImage']) ? null : $oldImage;
        if ($image !== null) {
            $imageId = mapCodexStoreImage($pdo, $campaignId, $image);
        }

        if ($entry === null) {
            $query = $pdo->prepare('INSERT INTO campaign_codex_entries
                (campaign_id, category, title, summary, body, body_html, details, image_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$campaignId, $category, $title, $summary, $body, $bodyHtml, $details, $imageId]);
            $entryId = (int) $pdo->lastInsertId();
            if ($permissions !== null && !mapCodexCanManage($permissions)) {
                mapCodexGrantCreatorAccess($pdo, $campaignId, $entryId, (int) $actorId, $permissions, $category);
            }
            mapCodexRecordActivity($pdo, $campaignId, $entryId, 1, 'created', $actorId);
        } else {
            $entryId = (int) $entry['id'];
            $revision = mapValidatePositiveInt($form['revision'] ?? null, 'Version de la fiche');
            $query = $pdo->prepare('UPDATE campaign_codex_entries SET category = ?, title = ?, summary = ?, body = ?, body_html = ?,
                details = ?, image_id = ?, revision = revision + 1 WHERE campaign_id = ? AND id = ? AND revision = ?');
            $query->execute([$category, $title, $summary, $body, $bodyHtml, $details, $imageId, $campaignId, $entryId, $revision]);
            if ($query->rowCount() !== 1) {
                throw new DomainException('Cette fiche a été modifiée ailleurs. Rechargez-la avant de réessayer.');
            }
            mapCodexRecordActivity($pdo, $campaignId, $entryId, $revision + 1, 'updated', $actorId);
            if ($actorWasManager === null) {
                $pdo->prepare("DELETE FROM campaign_codex_links WHERE campaign_id = ? AND entry_id = ? AND kind = 'relation'")
                    ->execute([$campaignId, $entryId]);
            }
        }

        $link = $pdo->prepare('INSERT INTO campaign_codex_links (campaign_id, entry_id, target_id, label, players_visible)
            SELECT campaign_id, ?, id, ?, ? FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?
            ON CONFLICT DO NOTHING');
        foreach ($actorWasManager === null ? ($form['target'] ?? []) : [] as $index => $target) {
            if ($target === '') {
                continue;
            }
            $targetId = mapValidatePositiveInt($target, 'Fiche liée');
            if ($targetId === $entryId) {
                throw new DomainException('Une fiche ne peut pas être liée à elle-même.');
            }
            $label = mapCodexText($form['label'][$index] ?? '', 250, 'Le libellé du lien');
            $visibility = $form['linkVisibility'][$index] ?? '1';
            if (!in_array($visibility, ['0', '1', 0, 1], true)) {
                throw new DomainException('Visibilité du lien invalide.');
            }
            $link->execute([$entryId, $label, (int) $visibility, $campaignId, $targetId]);
        }
        mapCodexSaveMentions($pdo, $campaignId, $entryId, $bodyHtml === null ? $body : mapCodexPlainText($bodyHtml), $decoded);
        $sourceChanged = $entry !== null && ($title !== $entry['title'] || $body !== $entry['body']
            || $bodyHtml !== $entry['body_html'] || $category !== $entry['category'] || $imageId !== $oldImage);
        mapSyncCodexPublishedNote($pdo, $campaignId, $entryId, $sourceChanged);
        if ($oldImage !== $imageId) {
            mapCodexRemoveUnusedImage($pdo, $campaignId, $oldImage);
        }
        $pdo->commit();
        return $entryId;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function mapCodexDeleteEntry(PDO $pdo, int $campaignId, array $entry, int $revision): void
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('DELETE FROM campaign_codex_entries WHERE campaign_id = ? AND id = ? AND revision = ?');
        $query->execute([$campaignId, $entry['id'], $revision]);
        if ($query->rowCount() !== 1) {
            throw new DomainException('Cette fiche a été modifiée ailleurs. Rechargez-la avant de réessayer.');
        }
        mapCodexRemoveUnusedImage($pdo, $campaignId, $entry['image_id']);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
