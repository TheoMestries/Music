<?php
declare(strict_types=1);

require_once __DIR__ . '/campaign-codex.php';
require_once __DIR__ . '/codex-visibility.php';

function mapCodexPlayerVisibleReference(mixed $id, array $visibleEntries): bool
{
    if (!is_int($id) && !is_string($id)) {
        return false;
    }
    $entryId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $entryId !== false && isset($visibleEntries[$entryId]);
}

function mapCodexPlayerRedactMentions(string $text, array $visibleEntries): string
{
    return preg_replace_callback(MAP_CODEX_MENTION_TOKEN, static fn (array $token): string =>
        mapCodexPlayerVisibleReference($token[1], $visibleEntries) ? $token[0] : 'Fiche privée', $text);
}

function mapCodexPlayerRedactRichText(string $html, array $visibleEntries): string
{
    $filtered = mapCodexPlayerRedactMentions($html, $visibleEntries);
    $text = mapCodexPlainText($filtered);
    $filteredText = mapCodexPlayerRedactMentions($text, $visibleEntries);
    return $text === $filteredText ? $filtered : mapCodexTextAsHtml($filteredText);
}

function mapCodexPlayerCanSeeLink(array $link, array $visibleEntries): bool
{
    $source = (int) $link['entry_id'];
    $target = (int) $link['target_id'];
    if (!isset($visibleEntries[$source], $visibleEntries[$target])
        || (string) $link['label'] !== mapCodexPlayerRedactMentions((string) $link['label'], $visibleEntries)) {
        return false;
    }
    return in_array($link['players_visible'] ?? null, [true, 1, '1'], true);
}

function mapCodexProjectPlayerIndex(array $visibleEntries): array
{
    $projected = $visibleEntries;
    foreach ($projected as &$entry) {
        foreach (['title', 'summary'] as $field) {
            if (is_string($entry[$field] ?? null)) {
                $entry[$field] = mapCodexPlayerRedactMentions($entry[$field], $visibleEntries);
            }
        }
    }
    return $projected;
}

function mapCodexProjectPlayerDetails(stdClass $details, string $category, bool $imported, array $visibleEntries): stdClass
{
    $result = new stdClass();
    $publicFields = array_keys(MAP_CODEX_MODULES[$category] ?? []);
    if ($category === 'Calendriers') {
        array_push($publicFields, 'date', 'start_offset', 'skip_year_zero');
    }
    foreach ($details as $key => $value) {
        if (!is_scalar($value) || (!in_array((string) $key, $publicFields, true) && ($imported
            || preg_match('/(?:^|[\W_])(?:private|priv[ée]e?s?|secret|gm|mj|hidden)(?:$|[\W_])/iu', (string) $key)
            || str_ends_with((string) $key, '_id') || str_starts_with((string) $key, 'is_')))) {
            continue;
        }
        $result->{$key} = is_string($value) ? mapCodexPlayerRedactMentions($value, $visibleEntries) : $value;
    }

    $collections = [
        'posts' => ['name', 'entry', 'is_pinned'],
        'members' => ['name', 'role', 'is_pinned', 'codex_entry_id'],
        'organisation_memberships' => ['name', 'role', 'is_pinned', 'codex_entry_id'],
        'inventories' => ['name', 'amount', 'description', 'is_equipped', 'is_pinned', 'codex_entry_id'],
        'assets' => ['name', 'url', 'is_pinned', 'codex_entry_id'],
        'abilities' => ['name', 'charges', 'note', 'is_pinned', 'codex_entry_id'],
        'entity_attributes' => ['name', 'value', 'is_pinned'],
        'reminders' => ['name', 'date', 'done', 'description', 'is_pinned', 'codex_entry_id'],
        'codex_pins' => ['label', 'x', 'y', 'target_id'],
        'codex_timeline' => ['era', 'date', 'title', 'description', 'target'],
    ];
    if (!empty($details->is_attributes_private) || !empty($details->attributes_private)) {
        unset($collections['entity_attributes']);
    }
    if ($category === 'Calendriers') {
        $collections['months'] = ['name', 'length'];
        $result->weekdays = is_array($details->weekdays ?? null)
            ? array_map(static fn (string $value): string => mapCodexPlayerRedactMentions($value, $visibleEntries),
                array_values(array_filter($details->weekdays, 'is_string'))) : [];
    }

    foreach ($collections as $property => $allowedFields) {
        if (!is_array($details->{$property} ?? null)) {
            continue;
        }
        $rows = [];
        foreach ($details->{$property} as $row) {
            if (!$row instanceof stdClass || !mapCodexPlayerRowIsPublic($row, $property !== 'assets')) {
                continue;
            }
            $targetKey = match ($property) {
                'codex_pins' => 'target_id',
                'codex_timeline' => 'target',
                default => 'codex_entry_id',
            };
            $target = $row->{$targetKey} ?? null;
            if ($target !== null && $target !== '' && !mapCodexPlayerVisibleReference($target, $visibleEntries)) {
                continue;
            }
            if ($imported && ($target === null || $target === '')) {
                $unresolvedKey = match ($property) {
                    'members' => 'character_id',
                    'organisation_memberships' => 'organisation_id',
                    'inventories' => 'item_id',
                    'assets' => 'image_uuid',
                    'abilities' => 'ability_id',
                    'reminders' => 'calendar_id',
                    default => null,
                };
                if ($unresolvedKey !== null && !empty($row->{$unresolvedKey})) {
                    continue;
                }
                // Older exports may embed the linked record rather than its identifier.
                if ($property === 'inventories' && isset($row->item)) {
                    continue;
                }
            }
            $fields = new stdClass();
            foreach (in_array($property, MAP_CODEX_INDIVIDUAL_COLLECTIONS, true) ? [...$allowedFields, 'codex_element_key'] : $allowedFields as $field) {
                $value = $row->{$field} ?? null;
                if ($property === 'assets' && $field === 'url') {
                    $value ??= $row->metadata->url ?? null;
                    if (!is_string($value) || mapCodexMediaAddress($value) === null) {
                        continue;
                    }
                }
                if (is_scalar($value)) {
                    $fields->{$field} = $field === $targetKey ? (int) $value : $value;
                    if (is_string($value) && $field !== $targetKey) {
                        $fields->{$field} = $field === 'entry' ? mapCodexPlayerRedactRichText($value, $visibleEntries)
                            : mapCodexPlayerRedactMentions($value, $visibleEntries);
                    }
                }
            }
            $rows[] = $fields;
        }
        $result->{$property} = $rows;
    }
    return $result;
}

function mapCodexProjectEntry(array $entry, array $visibleEntries): array
{
    $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
    $entry['details'] = json_encode(mapCodexProjectPlayerDetails(
        $details instanceof stdClass ? $details : new stdClass(), $entry['category'], !empty($entry['source_key']), $visibleEntries,
    ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $entry['links'] = array_values(array_filter($entry['links'], static fn (array $link): bool =>
        mapCodexPlayerCanSeeLink($link, $visibleEntries)));

    $entry['player_description_locked'] = false;
    $entry['player_summary_locked'] = false;
    $entry['player_title_locked'] = false;
    foreach (['body', 'body_html', 'summary', 'title'] as $key) {
        if (!is_string($entry[$key] ?? null)) {
            continue;
        }
        $filtered = $key === 'body_html' ? mapCodexPlayerRedactRichText($entry[$key], $visibleEntries)
            : mapCodexPlayerRedactMentions($entry[$key], $visibleEntries);
        if ($filtered !== $entry[$key]) {
            $lock = match ($key) {
                'summary' => 'player_summary_locked',
                'title' => 'player_title_locked',
                default => 'player_description_locked',
            };
            $entry[$lock] = true;
            $entry[$key] = $filtered;
            if ($key === 'body_html') {
                $entry['body'] = mapCodexPlainText($filtered);
            }
        }
    }
    unset($entry['source_key']);
    return $entry;
}

function mapCodexPlayerHasHiddenTokens(mixed $value, array $visibleEntries): bool
{
    if (is_string($value)) {
        return $value !== mapCodexPlayerRedactMentions($value, $visibleEntries)
            || (($text = mapCodexPlainText($value)) !== mapCodexPlayerRedactMentions($text, $visibleEntries));
    }
    if (is_array($value) || $value instanceof stdClass) {
        foreach ($value as $child) {
            if (mapCodexPlayerHasHiddenTokens($child, $visibleEntries)) {
                return true;
            }
        }
    }
    return false;
}

function mapCodexPlayerEditableSections(?array $rawEntry, array $visibleEntries): array
{
    $sections = [
        'notes' => ['posts'],
        'carte' => ['codex_pins'],
        'calendrier' => ['months', 'weekdays', 'date'],
        'chronologie' => ['codex_timeline'],
    ];
    foreach (MAP_CODEX_SHEET_SECTIONS as $section => $_) {
        [$key] = mapCodexSectionDefinition($section, $rawEntry['category'] ?? '');
        $sections[$section] = [$key];
    }
    $editable = array_fill_keys(array_keys($sections), true);
    if ($rawEntry === null) {
        return $editable;
    }
    $details = json_decode($rawEntry['details'], false, 32, JSON_THROW_ON_ERROR);
    $projection = mapCodexProjectPlayerDetails($details, $rawEntry['category'], !empty($rawEntry['source_key']), $visibleEntries);
    foreach ($sections as $section => $properties) {
        foreach ($properties as $property) {
            $original = $details->{$property} ?? null;
            $filtered = $projection->{$property} ?? null;
            if ($original === null) {
                continue;
            }
            if (mapCodexPlayerHasHiddenTokens($original, $visibleEntries)
                || (is_array($original)
                    ? !array_is_list($original) || !is_array($filtered) || count($original) !== count($filtered)
                    : $original !== $filtered)) {
                $editable[$section] = false;
                break;
            }
            foreach (is_array($original) ? $original : [] as $index => $row) {
                if ($row instanceof stdClass && !mapCodexPlayerEditableRow($row, $filtered[$index], $property)) {
                    $editable[$section] = false;
                    break 2;
                }
            }
            if (isset(MAP_CODEX_SHEET_SECTIONS[$section])) {
                [, , , , , $fields] = mapCodexSectionDefinition($section, $rawEntry['category']);
                foreach ($original as $index => $row) {
                    foreach ($fields as $name => [, $type]) {
                        if (mapCodexSectionValue($row, $name, $type) !== mapCodexSectionValue($filtered[$index], $name, $type)) {
                            $editable[$section] = false;
                            break 3;
                        }
                    }
                }
            }
        }
    }
    if (!empty($details->is_attributes_private) || !empty($details->attributes_private)) {
        $editable['proprietes'] = false;
    }
    return $editable;
}

function mapCodexPlayerEditableRow(stdClass $original, stdClass $projected, string $property): bool
{
    // The section editors retain these metadata fields via the row's original index.
    $retainedFields = in_array($property, ['codex_timeline', 'codex_pins'], true) ? [] : [
        'id', 'entity_id', 'created_at', 'updated_at', 'created_by', 'updated_by',
        'visibility_id', 'visibilityId', 'visibility', 'is_private', 'isPrivate', 'is_hidden', 'isHidden', 'deleted_at',
    ];
    $legacyReference = match ($property) {
        'members' => 'character_id',
        'organisation_memberships' => 'organisation_id',
        'inventories' => 'item_id',
        'assets' => 'image_uuid',
        'abilities' => 'ability_id',
        'reminders' => 'calendar_id',
        default => null,
    };
    if (isset($projected->codex_entry_id) && $legacyReference !== null) {
        $retainedFields[] = $legacyReference;
    }
    foreach (get_object_vars($original) as $name => $value) {
        if ($value !== null && !property_exists($projected, $name) && !in_array($name, $retainedFields, true)) {
            return false;
        }
    }
    return true;
}

function mapCodexPlayerValidateSectionTargets(mixed $targets, array $visibleEntries): void
{
    if (!is_array($targets)) {
        throw new DomainException('Fiches liées invalides.');
    }
    foreach ($targets as $target) {
        if ($target !== '' && !mapCodexPlayerVisibleReference($target, $visibleEntries)) {
            throw new DomainException('Choisissez une fiche que vous pouvez consulter.');
        }
    }
}

function mapCodexPlayerValidateOriginalRows(mixed $references, mixed $rows): void
{
    if (!is_array($references)) {
        throw new DomainException('Éléments de fiche invalides.');
    }
    $previous = is_array($rows) ? $rows : [];
    $seen = [];
    foreach ($references as $reference) {
        if ($reference === '') {
            continue;
        }
        $index = filter_var($reference, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($index === false || !array_key_exists($index, $previous) || isset($seen[$index])) {
            throw new DomainException('Éléments de fiche invalides.');
        }
        $seen[$index] = true;
    }
}

function mapCodexPlayerSectionForm(array $form, ?array $rawEntry, string $category, array $visibleEntries): array
{
    $editable = mapCodexPlayerEditableSections($rawEntry, $visibleEntries);
    $details = json_decode($rawEntry['details'] ?? '{}', false, 32, JSON_THROW_ON_ERROR);
    $fields = [];
    $widgetFields = [
        'notes' => ['editPosts', 'postName', 'postBody', 'postOriginal'],
        'carte' => ['editMapPins', 'pinLabel', 'pinX', 'pinY', 'pinEntry'],
        'calendrier' => ['codexCalendarSubmitted', 'codexCalendar'],
        'chronologie' => ['editTimeline', 'timeline'],
    ];
    foreach ($widgetFields as $section => $keys) {
        if ($editable[$section]) {
            $fields += array_intersect_key($form, array_flip($keys));
        }
    }
    mapCodexPlayerValidateOriginalRows($fields['postOriginal'] ?? [], $details->posts ?? []);
    mapCodexPlayerValidateOriginalRows($fields['codexCalendar']['monthOriginal'] ?? [], $details->months ?? []);
    mapCodexPlayerValidateSectionTargets($fields['pinEntry'] ?? [], $visibleEntries);
    if (isset($fields['timeline'])) {
        if (!is_array($fields['timeline'])) {
            throw new DomainException('Chronologie invalide.');
        }
        foreach ($fields['timeline'] as $event) {
            if (!is_array($event)) {
                throw new DomainException('Événement invalide.');
            }
            mapCodexPlayerValidateSectionTargets([$event['target'] ?? ''], $visibleEntries);
        }
    }

    $submitted = $form['codexSection'] ?? [];
    if (!is_array($submitted)) {
        throw new DomainException('Sections de fiche invalides.');
    }
    foreach (MAP_CODEX_SHEET_SECTIONS as $section => $_) {
        if (!$editable[$section] || !array_key_exists($section, $submitted)) {
            continue;
        }
        $data = $submitted[$section];
        if (!is_array($data)) {
            throw new DomainException('Section de fiche invalide.');
        }
        [$key] = mapCodexSectionDefinition($section, $category);
        mapCodexPlayerValidateOriginalRows($data['original'] ?? [], $details->{$key} ?? []);
        mapCodexPlayerValidateSectionTargets($data['codex_entry_id'] ?? [], $visibleEntries);
        $fields['codexSection'][$section] = $data;
    }
    if (mapCodexPlayerHasHiddenTokens($fields, $visibleEntries)) {
        throw new DomainException('Ajoutez uniquement des mentions de fiches que vous pouvez consulter.');
    }
    return $fields;
}

function mapCodexPlayerSaveForm(array $form, ?array $rawEntry, array $visibleEntries): array
{
    $allowed = array_intersect_key($form, array_flip(['category', 'title', 'summary', 'body', 'body_html',
        'revision', 'moduleFields', 'removeImage']));
    $category = $rawEntry['category'] ?? $form['category'] ?? '';
    $allowed['category'] = $category;
    $allowed['details'] = $rawEntry['details'] ?? '{}';
    $projected = [];
    if ($rawEntry !== null) {
        $allowed += array_intersect_key($rawEntry, array_flip(['title', 'summary', 'body']));
        $projected = mapCodexProjectEntry($rawEntry, $visibleEntries);
        if ($projected['player_description_locked']) {
            unset($allowed['body_html']);
            $allowed['body'] = $rawEntry['body'];
            $allowed['preserveDescription'] = true;
        }
        if ($projected['player_summary_locked']) {
            $allowed['summary'] = $rawEntry['summary'];
        }
        if ($projected['player_title_locked']) {
            $allowed['title'] = $rawEntry['title'];
        }
        $previous = json_decode($rawEntry['details'], false, 32, JSON_THROW_ON_ERROR);
        if (is_array($allowed['moduleFields'] ?? null)) {
            foreach (array_keys($allowed['moduleFields']) as $key) {
                $value = $previous->{$key} ?? null;
                if ($value !== null && (!is_scalar($value) || (is_string($value) && $value !== mapCodexPlayerRedactMentions($value, $visibleEntries)))) {
                    unset($allowed['moduleFields'][$key]);
                }
            }
        }
    }
    foreach (['body' => 250000, 'body_html' => 300000, 'summary' => 5000, 'title' => 250] as $key => $limit) {
        $lock = match ($key) {
            'summary' => 'player_summary_locked',
            'title' => 'player_title_locked',
            default => 'player_description_locked',
        };
        if (!array_key_exists($key, $allowed) || !empty($projected[$lock])) {
            continue;
        }
        $value = mapCodexText($allowed[$key], $limit, 'Le texte saisi');
        $plain = $key === 'body_html' ? mapCodexPlainText(mapCodexRichHtml($value)) : $value;
        if ($plain !== mapCodexPlayerRedactMentions($plain, $visibleEntries)) {
            throw new DomainException('Ajoutez uniquement des mentions de fiches que vous pouvez consulter.');
        }
    }
    if (is_array($allowed['moduleFields'] ?? null)) {
        foreach ($allowed['moduleFields'] as $value) {
            if (is_string($value) && $value !== mapCodexPlayerRedactMentions($value, $visibleEntries)) {
                throw new DomainException('Ajoutez uniquement des mentions de fiches que vous pouvez consulter.');
            }
        }
    }
    $targets = $form['target'] ?? [];
    $labels = $form['label'] ?? [];
    if (!is_array($targets) || !is_array($labels)) {
        throw new DomainException('Relations de la fiche invalides.');
    }
    foreach ($targets as $index => $target) {
        if ($target === '') {
            continue;
        }
        if (!mapCodexPlayerVisibleReference($target, $visibleEntries)) {
            throw new DomainException('Choisissez une fiche que vous pouvez consulter.');
        }
        $allowed['target'][] = $target;
        $label = mapCodexText($labels[$index] ?? '', 250, 'Le libellé de la relation');
        if ($label !== mapCodexPlayerRedactMentions($label, $visibleEntries)) {
            throw new DomainException('Ajoutez uniquement des mentions de fiches que vous pouvez consulter.');
        }
        $allowed['label'][] = $label;
        $allowed['linkVisibility'][] = 1;
    }
    foreach ($rawEntry['links'] ?? [] as $link) {
        if (mapCodexIsMention($link) || (int) $link['entry_id'] !== (int) $rawEntry['id']
            || mapCodexPlayerCanSeeLink($link, $visibleEntries)) {
            continue;
        }
        $allowed['target'][] = (int) $link['target_id'];
        $allowed['label'][] = $link['label'];
        $allowed['linkVisibility'][] = (int) ($link['players_visible'] ?? 0);
    }
    return $allowed + mapCodexPlayerSectionForm($form, $rawEntry, mapCodexText($category, 100, 'La catégorie', true), $visibleEntries);
}
