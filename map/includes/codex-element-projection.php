<?php
declare(strict_types=1);

require_once __DIR__ . '/codex-elements.php';
require_once __DIR__ . '/codex-player-view.php';

function mapCodexWithoutIndividualCollections(stdClass $details): stdClass
{
    $result = clone $details;
    foreach (MAP_CODEX_INDIVIDUAL_COLLECTIONS as $key) {
        unset($result->{$key});
    }
    return $result;
}

function mapCodexMainFormInput(array $form): array
{
    return array_diff_key($form, array_flip([
        'target', 'label', 'linkVisibility', 'editPosts', 'postName', 'postBody', 'postOriginal', 'codexSection',
    ]));
}

function mapCodexElementContextLoader(PDO $pdo, int $campaignId, array $permissions): Closure
{
    $contexts = [];
    return static function (int $entryId) use ($pdo, $campaignId, $permissions, &$contexts): array {
        return $contexts[$entryId] ??= mapCodexElementPermissions($pdo, $campaignId, $entryId, $permissions);
    };
}

function mapCodexPlayerChildDetails(array $entry, array $elementPermissions): stdClass
{
    $details = json_decode($entry['details'], false, 32, JSON_THROW_ON_ERROR);
    $sections = ['notes' => 'posts'];
    foreach (MAP_CODEX_SHEET_SECTIONS as $section => $_) {
        [$sections[$section]] = mapCodexSectionDefinition($section, $entry['category']);
    }
    foreach (array_diff(MAP_CODEX_INDIVIDUAL_COLLECTIONS, $sections) as $property) {
        unset($details->{$property});
    }
    foreach ($sections as $section => $property) {
        $elements = mapCodexElementRows($entry, $section);
        if (!is_array($details->{$property} ?? null)) {
            unset($details->{$property});
            continue;
        }
        $visible = [];
        foreach ($details->{$property} as $row) {
            $key = $row->codex_element_key ?? '';
            if (!is_string($key) || !isset($elements[$key]) || !empty($row->deleted_at)
                || mapCodexElementHasUnresolvedReference($entry, $section, $elements[$key])
                || !mapCodexElementCanRead($elementPermissions, $section, $key, $elements[$key]['private'])) {
                continue;
            }
            foreach (['is_private', 'isPrivate', 'is_hidden', 'isHidden', 'private', 'hidden', 'visibility_id', 'visibilityId', 'visibility'] as $flag) {
                unset($row->{$flag});
            }
            $visible[] = $row;
        }
        $details->{$property} = $visible;
    }
    unset($details->is_attributes_private, $details->attributes_private);
    return $details;
}

function mapCodexPlayerCanReadChildSummary(array $entryIndex, int $source, string $section, array|object $item, callable $contextFor): bool
{
    $row = (array) $item;
    $key = $row['codex_element_key'] ?? null;
    $private = is_bool($row['codex_element_private'] ?? null) ? $row['codex_element_private'] : !mapCodexPlayerRowIsPublic($row);
    return isset($entryIndex[$source]) && is_string($key) && empty($row['deleted_at'])
        && mapCodexElementCanRead($contextFor($source), $section, $key, $private);
}

function mapCodexPlayerMentionTargets(array $entry, stdClass $details): array
{
    $content = [mapCodexPlainText($entry['body_html'] ?? mapCodexTextAsHtml($entry['body']))];
    foreach ($details->posts ?? [] as $post) {
        $content[] = mapCodexPlainText($post->entry ?? '');
    }
    $targets = [];
    foreach ($content as $text) {
        preg_match_all(MAP_CODEX_MENTION_TOKEN, $text, $matches);
        foreach ($matches[1] as $number) {
            $targets[(int) $number] = true;
        }
    }
    return $targets;
}

function mapCodexLegacyChildAliases(array $entry, array $elementPermissions): array
{
    if (empty($entry['source_key'])) {
        return [];
    }
    $aliases = [];
    foreach ([
        'organisations' => $entry['category'] === 'Organisations' ? 'Personnage' : 'Organisation',
        'inventaire' => 'Objet', 'medias' => 'Illustration', 'pouvoirs' => 'Capacité',
    ] as $section => $label) {
        foreach (mapCodexElementRows($entry, $section) as $key => $item) {
            if ($item['targetId'] !== null && !mapCodexElementCanRead($elementPermissions, $section, $key, $item['private'])) {
                $aliases[$label][$item['targetId']] = true;
            }
        }
    }
    foreach (mapCodexElementRows($entry, 'notes') as $key => $item) {
        if (!mapCodexElementCanRead($elementPermissions, 'notes', $key, $item['private'])) {
            // Older imports did not distinguish images in notes from the main illustration.
            $aliases['Illustration'][0] = true;
            if (!empty($item['raw']->location_id)) {
                $aliases['Lieu'][0] = true;
            }
        }
    }
    return $aliases;
}

function mapCodexProjectElementAwareEntry(PDO $pdo, int $campaignId, array $entry, array $visibleEntries, callable $contextFor): array
{
    $entryId = (int) $entry['id'];
    $sources = [$entryId => $entry];
    $aliasesBySource = [];
    $details = mapCodexPlayerChildDetails($entry, $contextFor($entryId));
    $mentionsBySource = [$entryId => mapCodexPlayerMentionTargets($entry, $details)];
    $entry['details'] = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $links = [];
    foreach ($entry['links'] as $link) {
        $source = (int) $link['entry_id'];
        $target = (int) $link['target_id'];
        if (!isset($visibleEntries[$source], $visibleEntries[$target])) {
            continue;
        }
        if (mapCodexIsMention($link)) {
            if (!isset($mentionsBySource[$source])) {
                $other = $sources[$source] ??= mapCodexEntry($pdo, $campaignId, $source);
                $mentionsBySource[$source] = mapCodexPlayerMentionTargets(
                    $other, mapCodexPlayerChildDetails($other, $contextFor($source))
                );
            }
            if (!isset($mentionsBySource[$source][$target])) {
                continue;
            }
        } else {
            $key = $link['element_key'];
            if (!is_string($key) || !mapCodexElementCanRead($contextFor($source), 'relations', $key, !(bool) $link['players_visible'])) {
                continue;
            }
            if (!empty($visibleEntries[$source]['source_key'])) {
                $rawSource = $sources[$source] ??= mapCodexEntry($pdo, $campaignId, $source);
                $sourcePermissions = $contextFor($source);
                $aliases = $aliasesBySource[$source] ??= mapCodexLegacyChildAliases($rawSource, $sourcePermissions);
                $relationRules = $sourcePermissions['elements']['relations'][$key] ?? [];
                if ((isset($aliases[$link['label']][$target]) || isset($aliases[$link['label']][0]))
                    && mapCodexElementRuleState($relationRules, $sourcePermissions['userId'], 'read') !== 'allow') {
                    continue;
                }
            }
        }
        $link['players_visible'] = 1;
        $links[] = $link;
    }
    $entry['links'] = $links;
    return mapCodexProjectEntry($entry, $visibleEntries);
}

function mapCodexPlayerProjectedChildFields(array $element, array $visibleEntries): array
{
    foreach ($element['fields'] as &$value) {
        if (is_string($value)) {
            $value = mapCodexPlayerRedactMentions($value, $visibleEntries);
        }
    }
    unset($value);
    unset($element['raw']);
    return $element;
}
