<?php
declare(strict_types=1);

const MAP_CODEX_SHEET_SECTIONS = [
    'organisations' => 'Organisations',
    'inventaire' => 'Inventaire',
    'medias' => 'Médias',
    'pouvoirs' => 'Pouvoirs',
    'proprietes' => 'Propriétés',
    'rappels' => 'Rappels',
];

function mapCodexSectionDefinition(string $section, string $category): array
{
    return match ($section) {
        'organisations' => $category === 'Organisations'
            ? ['members', 'Personnages', 'characters', 'character_id', 'Membre', ['name' => ['Nom', 'text'], 'role' => ['Rôle', 'text']]]
            : ['organisation_memberships', 'Organisations', 'organisations', 'organisation_id', 'Organisation',
                ['name' => ['Nom', 'text'], 'role' => ['Rôle', 'text']]],
        'inventaire' => ['inventories', 'Objets', 'items', 'item_id', 'Objet',
            ['name' => ['Nom', 'text'], 'amount' => ['Quantité', 'number'], 'is_equipped' => ['Équipé', 'flag'],
                'description' => ['Description', 'memo']]],
        'medias' => ['assets', 'Galerie', 'gallery', 'image_uuid', 'Média',
            ['name' => ['Titre', 'text'], 'url' => ['Adresse web', 'url']]],
        'pouvoirs' => ['abilities', 'Capacités', 'abilities', 'ability_id', 'Pouvoir',
            ['name' => ['Nom', 'text'], 'charges' => ['Charges', 'text'], 'note' => ['Note', 'memo']]],
        'proprietes' => ['entity_attributes', null, null, null, 'Propriété',
            ['name' => ['Nom', 'text'], 'value' => ['Valeur', 'text']]],
        'rappels' => ['reminders', 'Calendriers', 'calendars', 'calendar_id', 'Rappel',
            ['name' => ['Titre', 'text'], 'date' => ['Échéance', 'date'], 'done' => ['Traité', 'flag'], 'description' => ['Note', 'memo']]],
    };
}

function mapCodexSheetDetailKeys(string $category): array
{
    return array_map(
        static fn (string $section): string => mapCodexSectionDefinition($section, $category)[0],
        array_keys(MAP_CODEX_SHEET_SECTIONS),
    );
}

function mapCodexInverseMemberships(PDO $pdo, int $campaignId, int $entryId, string $category): array
{
    [$sourceCategory, $sourcePath] = match ($category) {
        'Organisations' => ['Personnages', '$.organisation_memberships'],
        'Personnages' => ['Organisations', '$.members'],
        default => [null, null],
    };
    if ($sourceCategory === null) {
        return [];
    }
    $query = $pdo->prepare("SELECT entry.id, membership.value FROM campaign_codex_entries entry
        JOIN json_each(entry.details, :path) membership
        WHERE entry.campaign_id = :campaign AND entry.category = :category
        AND CASE WHEN membership.type = 'object'
            THEN CAST(json_extract(membership.value, '$.codex_entry_id') AS INTEGER) END = :entry
        ORDER BY entry.title COLLATE NOCASE, entry.id");
    $query->bindValue(':path', $sourcePath);
    $query->bindValue(':campaign', $campaignId, PDO::PARAM_INT);
    $query->bindValue(':category', $sourceCategory);
    $query->bindValue(':entry', $entryId, PDO::PARAM_INT);
    $query->execute();
    $memberships = [];
    foreach ($query->fetchAll() as $row) {
        $metadata = json_decode($row['value'], false, 32, JSON_THROW_ON_ERROR);
        $memberships[] = (object) ([
            'codex_entry_id' => (int) $row['id'],
            'codex_source_entry_id' => (int) $row['id'],
            'role' => is_string($metadata->role ?? null) ? $metadata->role : '',
        ] + array_intersect_key((array) $metadata, array_flip([
            'visibility_id', 'visibilityId', 'visibility', 'is_private', 'isPrivate', 'is_hidden', 'isHidden', 'private', 'hidden',
            'deleted_at', 'codex_element_key', 'codex_element_private',
        ])));
    }
    return $memberships;
}

function mapCodexSectionRows(stdClass $details, string $key): array
{
    return is_array($details->{$key} ?? null) ? $details->{$key} : [];
}

function mapCodexSectionValue(stdClass $row, string $field, string $type): string
{
    $value = $row->{$field} ?? match ($field) {
        'name' => $row->item->name ?? '',
        'url' => $row->metadata->url ?? '',
        default => '',
    };
    if ($type === 'flag') {
        return !empty($value) ? '1' : '0';
    }
    if ($type === 'memo') {
        return mapCodexPlainText($value);
    }
    return is_scalar($value) ? (string) $value : '';
}

function mapCodexMediaAddress(string $url): ?string
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        && parse_url($url, PHP_URL_USER) === null
        && parse_url($url, PHP_URL_PASS) === null ? $url : null;
}

function mapCodexSectionDate(string $value): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function mapCodexApplySheetSections(stdClass $details, string $category, array $form): void
{
    $sections = $form['codexSection'] ?? [];
    if (!is_array($sections)) {
        throw new DomainException('Sections de fiche invalides.');
    }
    foreach ($sections as $section => $fields) {
        if (!isset(MAP_CODEX_SHEET_SECTIONS[$section]) || !is_array($fields) || ($fields['submitted'] ?? null) !== '1') {
            throw new DomainException('Section de fiche invalide.');
        }
        [$key, $targetCategory, , $legacyReference, $label, $definitions] = mapCodexSectionDefinition($section, $category);
        $definitions['is_pinned'] = ['Épinglé', 'flag'];
        $indexes = $fields['original'] ?? [];
        $selectedEntries = $fields['codex_entry_id'] ?? [];
        if (!is_array($indexes) || !is_array($selectedEntries)) {
            throw new DomainException('Liste de ' . mb_strtolower($label) . ' invalide.');
        }
        $previous = mapCodexSectionRows($details, $key);
        $saved = [];
        foreach ($indexes as $position => $original) {
            $existing = is_string($original) && ctype_digit($original) ? ($previous[(int) $original] ?? null) : null;
            $row = $existing instanceof stdClass ? clone $existing : new stdClass();
            $selected = $selectedEntries[$position] ?? '';
            if ($targetCategory !== null && $selected !== '') {
                $row->codex_entry_id = mapValidatePositiveInt($selected, 'Fiche liée');
                if ($row->codex_entry_id !== ($existing->codex_entry_id ?? null)) {
                    unset($row->{$legacyReference});
                }
            } elseif ($targetCategory !== null && isset($row->codex_entry_id)) {
                unset($row->codex_entry_id, $row->{$legacyReference});
            }
            foreach ($definitions as $field => [$name, $type]) {
                if (!is_array($fields[$field] ?? null) || !array_key_exists($position, $fields[$field])) {
                    throw new DomainException('La section est incomplète.');
                }
                $value = mapCodexText($fields[$field][$position], $type === 'memo' ? 20000 : 250, $name);
                if (mapCodexSectionValue($row, $field, $type) === $value) {
                    continue;
                }
                if ($type === 'url' && $value !== '' && mapCodexMediaAddress($value) === null) {
                    throw new DomainException('Indiquez une adresse commençant par https:// ou http://.');
                }
                if ($type === 'date' && $value !== ''
                    && ($selected === '' ? !mapCodexSectionDate($value) : mapCodexCalendarDate($value) === null)) {
                    throw new DomainException('Indiquez une date valide.');
                }
                if ($type === 'number' && $value !== '') {
                    $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999]]);
                    if ($value === false) {
                        throw new DomainException('La quantité doit être un entier entre 0 et 999 999.');
                    }
                }
                if ($type === 'flag') {
                    if (!in_array($value, ['0', '1'], true)) {
                        throw new DomainException($name . ' invalide.');
                    }
                    $value = $value === '1';
                }
                if ($type === 'memo') {
                    $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $row->{$field} = $value;
            }
            $name = mapCodexSectionValue($row, 'name', 'text');
            if ($existing === null && $name === '' && !isset($row->codex_entry_id)
                && ($section !== 'medias' || mapCodexSectionValue($row, 'url', 'url') === '')) {
                throw new DomainException('Renseignez le nom ou la fiche de chaque élément dans « ' . MAP_CODEX_SHEET_SECTIONS[$section] . ' ».');
            }
            if ($section === 'rappels' && $existing === null
                && ($name === '' || mapCodexSectionValue($row, 'date', 'date') === '')) {
                throw new DomainException('Renseignez le titre et l’échéance du rappel.');
            }
            $saved[] = $row;
        }
        if ($saved || isset($details->{$key})) {
            $details->{$key} = $saved;
        }
    }
}

function mapCodexValidateSheetTargets(PDO $pdo, int $campaignId, ?int $entryId, string $category, array $form): void
{
    $sections = $form['codexSection'] ?? [];
    if (!$sections) {
        return;
    }
    $find = $pdo->prepare('SELECT category, details FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
    foreach ($sections as $section => $fields) {
        [, $targetCategory] = mapCodexSectionDefinition($section, $category);
        if ($targetCategory === null) {
            continue;
        }
        foreach ($fields['codex_entry_id'] ?? [] as $position => $selected) {
            if ($selected === '') {
                continue;
            }
            $targetId = mapValidatePositiveInt($selected, 'Fiche liée');
            $find->execute([$campaignId, $targetId]);
            $target = $find->fetch();
            $find->closeCursor();
            if ($targetId === $entryId || ($target['category'] ?? null) !== $targetCategory) {
                throw new DomainException('Choisissez une fiche disponible dans le module « ' . $targetCategory . ' ».');
            }
            if ($section === 'rappels' && ($fields['date'][$position] ?? '') !== '') {
                $date = mapCodexCalendarDate($fields['date'][$position]);
                $calendar = json_decode($target['details'], false, 32, JSON_THROW_ON_ERROR);
                if ($date === null || !mapCodexCalendarContains($calendar, $date)) {
                    throw new DomainException('L’échéance doit correspondre à une date du calendrier choisi.');
                }
            }
        }
    }
}

function mapCodexBindImportedSectionReferences(stdClass $details, string $category, array $entryIds): bool
{
    $changed = false;
    foreach (MAP_CODEX_SHEET_SECTIONS as $section => $label) {
        [$key, , $referenceModule, $legacyReference] = mapCodexSectionDefinition($section, $category);
        if ($referenceModule === null) {
            continue;
        }
        foreach (mapCodexSectionRows($details, $key) as $row) {
            if (!$row instanceof stdClass || !is_scalar($row->{$legacyReference} ?? null)) {
                continue;
            }
            $linkedEntry = $entryIds[$referenceModule . ':' . $row->{$legacyReference}] ?? null;
            if ($linkedEntry !== null) {
                $row->codex_entry_id = (int) $linkedEntry;
                $changed = true;
            }
        }
    }
    return $changed;
}

function mapCodexSheetPins(stdClass $details, string $category = '', array $entries = []): array
{
    $pins = [];
    foreach (MAP_CODEX_SHEET_SECTIONS as $section => $sectionLabel) {
        [$key, , , , $label, $definitions] = mapCodexSectionDefinition($section, $category);
        foreach (mapCodexSectionRows($details, $key) as $row) {
            if (!$row instanceof stdClass || empty($row->is_pinned)) {
                continue;
            }
            $entry = is_int($row->codex_entry_id ?? null) ? ($entries[$row->codex_entry_id] ?? null) : null;
            $name = mapCodexSectionValue($row, 'name', 'text');
            $value = '';
            foreach ($definitions as $field => [, $type]) {
                if ($field !== 'name' && $type !== 'flag' && $type !== 'url') {
                    $value = mapCodexSectionValue($row, $field, $type);
                    if ($value !== '') {
                        break;
                    }
                }
            }
            $pins[] = ['label' => $name !== '' ? $name : ($entry['title'] ?? $label), 'value' => $value, 'section' => $section];
        }
    }
    return $pins;
}

function mapCodexLinkedDescriptions(PDO $pdo, int $campaignId, array $visibleEntries, callable $url): Closure
{
    $query = null;
    $descriptions = [];
    return static function (int $id) use ($pdo, $campaignId, $visibleEntries, $url, &$query, &$descriptions): string {
        if (!isset($visibleEntries[$id])) {
            return '';
        }
        if (!array_key_exists($id, $descriptions)) {
            $query ??= $pdo->prepare('SELECT body, body_html FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?');
            $query->execute([$campaignId, $id]);
            $entry = $query->fetch();
            $query->closeCursor();
            if ($entry === false) {
                return $descriptions[$id] = '';
            }
            $body = $entry['body_html'] === null ? mapCodexTextAsHtml($entry['body']) : mapCodexRichHtml($entry['body_html']);
            if (mapCodexPlainText($body) === '') {
                $body = mapCodexTextAsHtml($visibleEntries[$id]['summary']);
            }
            $descriptions[$id] = mapCodexRichMentionMarkup($body, $visibleEntries, $url);
        }
        return $descriptions[$id];
    };
}

function mapRenderCodexSheetSection(
    string $section,
    stdClass $details,
    array $entry,
    array $entries,
    callable $url,
    callable $mediaUrl,
    ?callable $renderElementActions = null,
    ?callable $linkedDescription = null,
): void {
    [$key, $targetCategory, , , $label, $definitions] = mapCodexSectionDefinition($section, $entry['category']);
    $rows = mapCodexSectionRows($details, $key);
    $represented = [];
    $today = date('Y-m-d');
    ?>
    <div class="codex-posts codex-section-cards">
      <?php if ($section === 'medias' && isset($entry['image_id'])): ?>
        <article><h3>Illustration de la fiche</h3>
          <a href="<?= mapEscape($mediaUrl((int) $entry['image_id'])) ?>" class="codex-image" target="_blank" rel="noopener">
            <img src="<?= mapEscape($mediaUrl((int) $entry['image_id'])) ?>" alt="" loading="lazy">
          </a>
        </article>
      <?php endif; ?>
      <?php foreach ($rows as $row):
          if (!$row instanceof stdClass) continue;
          $targetId = is_int($row->codex_entry_id ?? null) ? $row->codex_entry_id : 0;
          $target = $targetId ? ($entries[$targetId] ?? null) : null;
          $target = ($target['category'] ?? null) === $targetCategory ? $target : null;
          $name = mapCodexSectionValue($row, 'name', 'text');
          if ($target !== null) $represented[$targetId] = true;
          $title = $name !== '' ? $name : ($target['title'] ?? $label);
      ?>
        <article>
          <h3><?php if ($target !== null && $title === $target['title']): ?>
            <a href="<?= mapEscape($url(['fiche' => $targetId])) ?>"><?= mapEscape($title) ?></a>
          <?php else: ?><?= mapEscape($title) ?><?php endif; ?></h3>
          <?php if ($target !== null && $title !== $target['title']): ?>
            <p><a href="<?= mapEscape($url(['fiche' => $targetId])) ?>"><?= mapEscape($target['title']) ?></a></p>
          <?php endif; ?>
          <?php if ($section === 'medias' && isset($target['image_id'])): ?>
            <a class="codex-image" href="<?= mapEscape($url(['fiche' => $targetId])) ?>">
              <img src="<?= mapEscape($mediaUrl((int) $target['image_id'])) ?>" alt="" loading="lazy">
            </a>
          <?php endif; ?>
          <?php $description = $target !== null && $linkedDescription !== null ? $linkedDescription($targetId) : ''; ?>
          <?php if ($description !== ''): ?>
            <div class="codex-rich-body codex-linked-description"><?= $description ?></div>
          <?php endif; ?>
          <?php if ($section === 'rappels'):
              $date = mapCodexSectionValue($row, 'date', 'date');
              $due = !empty($row->done) ? 'Traité' : ($date === '' || $target !== null ? '' : ($date <= $today ? 'Échéance atteinte' : 'À venir'));
          ?>
            <?php if ($due !== ''): ?><p class="muted"><?= mapEscape($due) ?></p><?php endif; ?>
          <?php endif; ?>
          <dl class="codex-values">
            <?php foreach ($definitions as $field => [$name, $type]):
                if ($field === 'name' || $field === 'done') continue;
                $value = mapCodexSectionValue($row, $field, $type);
                if ($value === '' || ($type === 'flag' && $value !== '1')) continue;
            ?>
              <div><dt><?= mapEscape($name) ?></dt><dd>
                <?php if ($type === 'url' && ($address = mapCodexMediaAddress($value)) !== null): ?>
                  <a href="<?= mapEscape($address) ?>" target="_blank" rel="noopener noreferrer"><?= mapEscape($value) ?></a>
                <?php else: ?>
                  <?= mapEscape($type === 'flag' ? 'Oui' : $value) ?>
                <?php endif; ?>
              </dd></div>
            <?php endforeach; ?>
          </dl>
          <?php if ($renderElementActions !== null && is_string($row->codex_element_key ?? null)):
              $renderElementActions($section, $row->codex_element_key, $row->codex_source_entry_id ?? null);
          endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
    $related = [];
    if ($targetCategory !== null) {
        foreach ($entry['links'] as $link) {
            if (mapCodexIsMention($link)) {
                continue;
            }
            $id = (int) ((int) $entry['id'] === (int) $link['entry_id'] ? $link['target_id'] : $link['entry_id']);
            if (!isset($represented[$id]) && ($entries[$id]['category'] ?? null) === $targetCategory) {
                $related[$id] = $entries[$id]['title'];
            }
        }
    }
    if ($related): ?>
      <h3>Fiches liées</h3>
      <ul class="codex-related"><?php foreach ($related as $id => $name): ?>
        <li>
          <a href="<?= mapEscape($url(['fiche' => $id])) ?>"><?= mapEscape($name) ?></a>
          <?php $description = $linkedDescription === null ? '' : $linkedDescription($id); ?>
          <?php if ($description !== ''): ?>
            <details class="codex-related-description">
              <summary>Description</summary>
              <div class="codex-rich-body codex-linked-description"><?= $description ?></div>
            </details>
          <?php endif; ?>
        </li>
      <?php endforeach; ?></ul>
    <?php elseif (!$rows && !($section === 'medias' && isset($entry['image_id']))): ?>
      <p class="muted">Aucun élément enregistré.</p>
    <?php endif;
}
