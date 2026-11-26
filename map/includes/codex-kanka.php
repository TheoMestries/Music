<?php
declare(strict_types=1);

require_once __DIR__ . '/codex-visibility.php';

require_once __DIR__ . '/campaign-codex.php';

const MAP_CODEX_ARCHIVE_BYTES = 768 * 1024 * 1024;
const MAP_CODEX_EXPANDED_BYTES = 1024 * 1024 * 1024;

function mapKankaJson(ZipArchive $archive, int $index): array
{
    $raw = $archive->getFromIndex($index, 1024 * 1024 + 1);
    if ($raw === false || strlen($raw) > 1024 * 1024) {
        throw new DomainException('Un fichier JSON de l’export est illisible ou dépasse 1 Mo.');
    }
    try {
        $data = json_decode(str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new DomainException('Un fichier JSON de l’export est invalide.');
        }
        return $data;
    } catch (JsonException) {
        throw new DomainException('Un fichier JSON de l’export est invalide.');
    }
}


function mapKankaId(mixed $id): ?string
{
    if ((!is_int($id) && !is_string($id)) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', (string) $id)) {
        return null;
    }
    return (string) $id;
}

function mapKankaArchiveFiles(ZipArchive $archive): array
{
    if ($archive->numFiles > 12000) {
        throw new DomainException('L’archive contient plus de 12 000 fichiers.');
    }
    $files = [];
    $campaigns = [];
    $expanded = 0;
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $stat = $archive->statIndex($index);
        if ($stat === false) {
            throw new DomainException('Le contenu de l’archive ZIP est illisible.');
        }
        $name = str_replace('\\', '/', $stat['name']);
        if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || !preg_match('~\.(json|png|jpe?g|gif|webp)$~iD', $name)) {
            continue;
        }
        // Member names are used as lookup keys. No member is extracted to disk.
        if (preg_match('~(^/|^[a-z]:|(?:^|/)\.\.?(/|$)|[\x00-\x1f])~i', $name) || isset($files[$name])) {
            throw new DomainException('L’archive contient des chemins invalides ou en double.');
        }
        $expanded += $stat['size'];
        if ($expanded > MAP_CODEX_EXPANDED_BYTES) {
            throw new DomainException('L’export décompressé dépasse 1 Gio.');
        }
        $files[$name] = $index;
        if (basename($name) === 'campaign.json') {
            $campaigns[] = $name;
        }
    }
    if (count($campaigns) !== 1) {
        throw new DomainException('L’archive doit contenir le fichier campaign.json d’un seul export Kanka.');
    }
    $prefix = substr($campaigns[0], 0, -strlen('campaign.json'));
    $export = [];
    foreach ($files as $name => $index) {
        if (str_starts_with($name, $prefix)) {
            $export[substr($name, strlen($prefix))] = $index;
        }
    }
    return $export;
}

function mapKankaImageIndex(array $images, mixed $reference): ?int
{
    if (!is_string($reference) || $reference === '') {
        return null;
    }
    $path = ltrim(str_replace('\\', '/', $reference), '/');
    $base = basename($path);
    return $images[$path] ?? $images[':' . $base] ?? $images[':' . pathinfo($base, PATHINFO_FILENAME)] ?? null;
}

function mapKankaDetails(array $values): array
{
    $details = [];
    $skip = [
        'id', 'entity', 'entity_id', 'name', 'entry', 'tooltip', 'campaign_id', 'created_by', 'created_at',
        'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'words', 'image_uuid', 'image_path',
        'header_image', 'header_uuid', 'focus_x', 'focus_y', 'marketplace_uuid', 'is_template',
    ];
    foreach ($values as $key => $value) {
        if ($value === null || $value === [] || $value === '' || in_array($key, $skip, true)) {
            continue;
        }
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key));
        $details[$snake] ??= $value;
    }
    return $details;
}

function mapKankaReferences(array $record): array
{
    $links = [];
    $keys = [
        'target_id' => ['entity', 'Relation'], 'parent_id' => ['entity', 'Fiche parente'],
        'entity_id' => ['entity', 'Fiche associée'], 'location_id' => ['locations', 'Lieu'],
        'instigator_id' => ['entity', 'Instigateur'], 'character_id' => ['characters', 'Personnage'],
        'creator_id' => ['entity', 'Créateur'], 'author_id' => ['characters', 'Auteur'],
        'race_id' => ['races', 'Peuple'], 'family_id' => ['families', 'Famille'],
        'organisation_id' => ['organisations', 'Organisation'], 'ability_id' => ['abilities', 'Capacité'],
        'item_id' => ['items', 'Objet'], 'tag_id' => ['tags', 'Étiquette'], 'map_id' => ['maps', 'Carte'],
    ];
    $walk = static function (array $row, string $context = '', bool $ancestorsPublic = true) use (&$walk, &$links, $keys): void {
        $playersVisible = $ancestorsPublic && ($context === '' || mapCodexPlayerRowIsPublic($row, $context !== 'assets'));
        $unmarkedRelation = in_array($context, ['relations', 'relationships'], true)
            && !isset($row['visibility_id']) && !isset($row['visibilityId']) && !isset($row['visibility'])
            && !array_key_exists('is_private', $row) && !array_key_exists('isPrivate', $row);
        if (!array_is_list($row) && ($unmarkedRelation || $context === 'mentions')) {
            $playersVisible = false;
        }
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $storage = is_string($key) ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key)) : '';
                // Individually managed rows supply their own links through their own permissions.
                if ($key !== 'entity' && !in_array($key, ['item', 'family', 'race', 'map'], true)
                    && !in_array($storage, MAP_CODEX_INDIVIDUAL_COLLECTIONS, true)) {
                    $walk($value, is_string($key) ? $key : $context, $playersVisible);
                }
                continue;
            }
            if (($key === 'entry' || $key === 'description') && is_string($value)) {
                preg_match_all('~\bdata-gallery-id\s*=\s*["\x27]([a-z0-9_-]{1,80})["\x27]~i', $value, $matches);
                foreach ($matches[1] as $id) {
                    $links[] = ['gallery:' . $id, 'Illustration', 'relation', $playersVisible];
                }
            }
            if (!isset($keys[$key]) || ($key === 'entity_id' && in_array($context, ['posts', 'mentions', 'assets', 'inventories'], true))) {
                continue;
            }
            $id = mapKankaId($value);
            if ($id === null || $id === '0') {
                continue;
            }
            [$type, $label] = $keys[$key];
            if ($key === 'target_id') {
                $label = $context === 'mentions' ? 'Mention' : (is_string($row['relation'] ?? null) ? $row['relation'] : $label);
            }
            $kind = $context === 'mentions' && $key === 'target_id' ? 'mention' : 'relation';
            $links[] = [$type . ':' . $id, mb_substr((string) $label, 0, 250), $kind, $playersVisible];
        }
    };
    $entity = $record['entity'];
    unset($entity['entity_id']);
    $walk($entity);
    $walk($record);
    return $links;
}

function mapImportKankaCodex(PDO $pdo, int $campaignId, string $zipPath, ?int $actorId = null): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new DomainException('L’extension PHP zip est nécessaire pour importer un export Kanka.');
    }
    $archive = new ZipArchive();
    if ($archive->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new DomainException('Impossible d’ouvrir cette archive ZIP.');
    }
    try {
        return mapImportKankaArchive($pdo, $campaignId, $archive, $actorId);
    } finally {
        $archive->close();
    }
}

function mapImportKankaArchive(PDO $pdo, int $campaignId, ZipArchive $archive, ?int $actorId = null): array
{
    $files = mapKankaArchiveFiles($archive);
    $originId = mapKankaId(mapKankaJson($archive, $files['campaign.json'])['id'] ?? null);
    if ($originId === null) {
        throw new DomainException('L’identifiant de la campagne d’origine est manquant ou invalide.');
    }
    $origin = 'kanka:' . $originId . ':';
    $categories = MAP_CODEX_CATEGORIES;
    if (isset($files['settings/custom-modules.json'])) {
        foreach (mapKankaJson($archive, $files['settings/custom-modules.json']) as $module) {
            $id = mapKankaId($module['id'] ?? null);
            $name = $module['plural'] ?? $module['singular'] ?? null;
            if ($id !== null && is_string($name) && trim($name) !== '') {
                $categories['custom:' . $id] = mb_substr(trim($name), 0, 100);
            }
        }
    }

    $images = [];
    foreach ($files as $path => $index) {
        if (preg_match('~^(gallery|w)/.*\.(png|jpe?g|webp|gif)$~iD', $path)) {
            $images[$path] = $index;
            $images[':' . basename($path)] ??= $index;
            $images[':' . pathinfo($path, PATHINFO_FILENAME)] ??= $index;
        }
    }

    $insert = $pdo->prepare('INSERT INTO campaign_codex_entries
        (campaign_id, category, title, summary, body, body_html, details, source_key, image_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(campaign_id, source_key) DO NOTHING');
    $existing = $pdo->prepare('SELECT id FROM campaign_codex_entries WHERE campaign_id = ? AND source_key = ?');
    $link = $pdo->prepare('INSERT INTO campaign_codex_links (campaign_id, entry_id, target_id, label, kind, players_visible)
        VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING');
    $readDetails = $pdo->prepare('SELECT category, details, body FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    $saveDetails = $pdo->prepare('UPDATE campaign_codex_entries SET details = ? WHERE campaign_id = ? AND id = ?');
    $saveBody = $pdo->prepare('UPDATE campaign_codex_entries SET body_html = ?, body = ? WHERE campaign_id = ? AND id = ?');
    $imageIds = $entryIds = $pendingLinks = $pendingBodies = [];
    $created = $skipped = $missingImages = 0;
    $pdo->beginTransaction();
    try {
        foreach ($files as $path => $index) {
            if (!preg_match('~^([a-zA-Z0-9_-]+)/.+\.json$~D', $path, $parts) || in_array($parts[1], ['settings', 'w'], true)) {
                continue;
            }
            $module = $parts[1];
            $customId = preg_match('/_(\d+)$/D', $module, $suffix) ? $suffix[1] : '';
            $category = $categories[$module] ?? $categories['custom:' . $customId] ?? mb_substr($module, 0, 100);
            $record = mapKankaJson($archive, $index);
            $entity = $record['entity'] ?? null;
            if ($module === 'gallery') {
                if (!empty($record['is_folder'])) {
                    continue;
                }
                $id = mapKankaId($record['id'] ?? null);
                $key = 'gallery:' . $id;
                $title = $record['name'] ?? null;
                $body = mapCodexPlainText($record['description'] ?? null);
                $bodySource = $record['description'] ?? null;
                $summary = '';
                $details = array_intersect_key($record, array_flip(['author', 'metadata']));
                if (in_array($record['is_private'] ?? false, [true, 1, '1'], true)
                    || in_array($record['visibility'] ?? null, ['admin', 'self', 'admin-self', 'self-admin', 'private'], true)) {
                    $details['is_private'] = true;
                }
                $details['Dossier'] = $record['image_folder']['name'] ?? null;
                $references = [$id];
            } elseif (is_array($entity)) {
                $id = mapKankaId($entity['id'] ?? null);
                $key = 'entity:' . $id;
                $title = $entity['name'] ?? $record['name'] ?? null;
                $bodySource = $entity['entry'] ?? $record['entry'] ?? null;
                $body = mapCodexPlainText($bodySource);
                $summary = mapCodexPlainText($entity['tooltip'] ?? null);
                $details = mapKankaDetails($entity) + mapKankaDetails($record);
                $references = array_filter([$entity['image_uuid'] ?? null, $entity['image_path'] ?? null,
                    $entity['header_uuid'] ?? null, $entity['header_image'] ?? null]);
            } else {
                continue;
            }
            if ($id === null || !is_string($title) || trim($title) === '') {
                throw new DomainException('Une fiche Kanka ne possède pas de titre ou d’identifiant valide.');
            }

            $sourceKey = $origin . $key;
            $existing->execute([$campaignId, $sourceKey]);
            $entryId = $existing->fetchColumn();
            $existing->closeCursor();
            if ($entryId === false) {
                $imageId = null;
                foreach ($references as $reference) {
                    $imageIndex = mapKankaImageIndex($images, $reference);
                    if ($imageIndex === null) {
                        continue;
                    }
                    if (!array_key_exists($imageIndex, $imageIds)) {
                        $bytes = $archive->getFromIndex($imageIndex, MAP_CODEX_IMAGE_BYTES + 1);
                        try {
                            $imageIds[$imageIndex] = $bytes === false ? null : mapCodexStoreImage($pdo, $campaignId, $bytes);
                        } catch (DomainException) {
                            $imageIds[$imageIndex] = null;
                        }
                    }
                    $imageId = $imageIds[$imageIndex];
                    if ($imageId !== null) {
                        break;
                    }
                }
                if ($references !== [] && $imageId === null) {
                    $missingImages++;
                }
                $bodyHtml = is_string($bodySource) ? mapCodexRichHtml(mb_substr($bodySource, 0, 300000)) : null;
                $structured = json_decode(json_encode((object) $details, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
                mapCodexAssignElementKeys($structured, $category);
                $insert->execute([$campaignId, $category,
                    mb_substr(trim($title), 0, 250), mb_substr($summary, 0, 5000), mb_substr($body, 0, 250000),
                    $bodyHtml, json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $sourceKey, $imageId]);
                $entryId = (int) $pdo->lastInsertId();
                mapCodexRecordActivity($pdo, $campaignId, $entryId, 1, 'imported', $actorId);
                if (is_string($bodySource) && mapCodexContainsKankaMentions($bodySource)) {
                    $pendingBodies[$entryId] = $index;
                }
                $created++;
                $pendingLinks[$entryId] = $module === 'gallery' ? [] : mapKankaReferences($record);
            } else {
                $skipped++;
            }
            $entryIds[$key] = (int) $entryId;
            $typeId = mapKankaId($record['id'] ?? null);
            if ($typeId !== null) {
                $entryIds[$module . ':' . $typeId] = (int) $entryId;
            }
        }
        if ($created + $skipped === 0) {
            throw new DomainException('Aucune fiche Kanka n’a été trouvée dans cet export.');
        }
        foreach ($pendingLinks as $entryId => $references) {
            $readDetails->execute([$campaignId, $entryId]);
            $row = $readDetails->fetch();
            $readDetails->closeCursor();
            $inlineTargets = [];
            $publicInlineTargets = [];
            if (isset($pendingBodies[$entryId])) {
                $record = mapKankaJson($archive, $pendingBodies[$entryId]);
                $body = $record['entity']['entry'] ?? $record['entry'] ?? $record['description'] ?? '';
                $body = mapCodexLocalizeKankaMentions(mb_substr($body, 0, 300000), $entryIds, $inlineTargets);
                $publicInlineTargets = $inlineTargets;
                $plainTargets = [];
                $plain = mapCodexLocalizeKankaText($row['body'], $entryIds, $plainTargets);
                $saveBody->execute([mapCodexRichHtml($body), $plain, $campaignId, $entryId]);
            }

            $details = json_decode($row['details'], false, 32, JSON_THROW_ON_ERROR);
            $changed = mapCodexBindImportedSectionReferences($details, $row['category'], $entryIds);
            if (is_array($details->posts ?? null)) {
                foreach ($details->posts as $post) {
                    $original = $post->entry ?? null;
                    if (is_string($original) && mapCodexContainsKankaMentions($original)) {
                        $postTargets = [];
                        $post->entry = mapCodexRichHtml(mapCodexLocalizeKankaMentions($original, $entryIds, $postTargets));
                        $inlineTargets += $postTargets;
                        if (mapCodexPlayerRowIsPublic($post)) {
                            $publicInlineTargets += $postTargets;
                        }
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $saveDetails->execute([json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $campaignId, $entryId]);
            }

            foreach (array_keys($inlineTargets) as $targetId) {
                if ($targetId !== $entryId) {
                    $link->execute([$campaignId, $entryId, $targetId, '', 'mention', isset($publicInlineTargets[$targetId]) ? 1 : 0]);
                }
            }
            $added = [];
            foreach ($references as $reference) {
                [$key, $label] = $reference;
                $targetId = $entryIds[$key] ?? null;
                $kind = $reference[2] ?? 'relation';
                if ($targetId !== null && $targetId !== $entryId && ($kind !== 'mention' || !isset($inlineTargets[$targetId]))) {
                    $visibility = !empty($reference[3]) ? 1 : 0;
                    $tuple = json_encode([$targetId, $label, $kind, $visibility], JSON_THROW_ON_ERROR);
                    if (!isset($added[$tuple])) {
                        $link->execute([$campaignId, $entryId, $targetId, $label, $kind, $visibility]);
                        $added[$tuple] = true;
                    }
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    return ['created' => $created, 'skipped' => $skipped, 'missingImages' => $missingImages];
}
