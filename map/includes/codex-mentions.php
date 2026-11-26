<?php
declare(strict_types=1);

require_once __DIR__ . '/codex-visibility.php';

const MAP_CODEX_MENTION_TOKEN = '~\[\[fiche:([1-9][0-9]{0,18})\]\]~';
const MAP_CODEX_KANKA_TOKEN = '~\[(?:entity|item|character|location|organisation|organization|ability|family|race|note|map|quest|event|journal|creature|tag|calendar|timeline|custom):([0-9]{1,19})\]~i';

function mapCodexIsMention(array $link): bool
{
    return ($link['kind'] ?? 'relation') === 'mention';
}

function mapCodexMentionLinks(array $entry): array
{
    $mentions = [];
    foreach ($entry['links'] ?? [] as $link) {
        if (mapCodexIsMention($link)) {
            $mentions[$link['entry_id'] . ':' . $link['target_id']] = $link;
        }
    }
    return array_values($mentions);
}

function mapCodexRichMentionMarkup(string $safeHtml, array $entries, callable $url, bool $openInNewTab = false): string
{
    // The supplied markup must be escaped text or sanitized formatting without attributes.
    return preg_replace_callback(MAP_CODEX_MENTION_TOKEN, static function (array $match) use ($entries, $url, $openInNewTab): string {
        $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !isset($entries[$id])) {
            return '<span class="codex-mention-unavailable">Fiche indisponible</span>';
        }
        $target = $openInNewTab ? ' target="_blank" rel="noopener"' : '';
        return '<a class="codex-mention" href="' . mapEscape($url(['fiche' => $id])) . '"' . $target . '>'
            . mapEscape($entries[$id]['title']) . '</a>';
    }, $safeHtml);
}

function mapCodexMentionMarkup(string $text, array $entries, callable $url): string
{
    return mapCodexRichMentionMarkup(mapEscape($text), $entries, $url);
}

function mapCodexSaveMentions(PDO $pdo, int $campaignId, int $entryId, string $body, stdClass $details): void
{
    $texts = [[$body, true]];
    if (is_array($details->posts ?? null)) {
        foreach ($details->posts as $post) {
            if ($post instanceof stdClass) {
                $public = is_bool($post->codex_element_private ?? null) ? !$post->codex_element_private : mapCodexPlayerRowIsPublic($post);
                $texts[] = [mapCodexPlainText($post->entry ?? ''), $public];
            }
        }
    }
    $targets = [];
    foreach ($texts as [$text, $playersVisible]) {
        preg_match_all(MAP_CODEX_MENTION_TOKEN, $text, $matches);
        foreach ($matches[1] as $number) {
            $id = filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && $id !== $entryId) {
                $targets[$id] = ($targets[$id] ?? false) || $playersVisible;
            }
        }
    }
    // Imported structured references use a separate label so re-saving a text cannot delete them.
    $pdo->prepare("DELETE FROM campaign_codex_links WHERE campaign_id = ? AND entry_id = ?
        AND kind = 'mention' AND label = ''")->execute([$campaignId, $entryId]);
    if (!$targets) {
        return;
    }
    $insert = $pdo->prepare("INSERT INTO campaign_codex_links (campaign_id, entry_id, target_id, label, kind, players_visible)
        SELECT campaign_id, ?, id, '', 'mention', ? FROM campaign_codex_entries WHERE campaign_id = ? AND id = ?
        ON CONFLICT DO NOTHING");
    foreach ($targets as $id => $playersVisible) {
        $insert->execute([$entryId, $playersVisible ? 1 : 0, $campaignId, $id]);
    }
}

function mapCodexLocalizeKankaText(string $text, array $entryIds, array &$references): string
{
    return preg_replace_callback(MAP_CODEX_KANKA_TOKEN, static function (array $match) use ($entryIds, &$references): string {
        $target = $entryIds['entity:' . $match[1]] ?? null;
        if ($target === null) {
            return $match[0];
        }
        $references[$target] = true;
        return '[[fiche:' . $target . ']]';
    }, $text);
}

function mapCodexContainsKankaMentions(string $html): bool
{
    return stripos($html, 'data-mention') !== false || preg_match(MAP_CODEX_KANKA_TOKEN, $html) === 1;
}

function mapCodexLocalizeKankaMentions(string $html, array $entryIds, array &$references): string
{
    if (!mapCodexContainsKankaMentions($html)) {
        return $html;
    }
    $doc = new DOMDocument();
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $query = new DOMXPath($doc);
    $changed = false;
    foreach ($query->query('//a[@data-mention]') as $anchor) {
        $original = $anchor->getAttribute('data-mention');
        $localized = mapCodexLocalizeKankaText($original, $entryIds, $references);
        if ($localized !== $original) {
            $anchor->replaceWith($doc->createTextNode($localized));
            $changed = true;
        }
    }
    foreach ($query->query('//body//text()[not(ancestor::script or ancestor::style)]') as $node) {
        $localized = mapCodexLocalizeKankaText($node->nodeValue, $entryIds, $references);
        if ($localized !== $node->nodeValue) {
            $node->nodeValue = $localized;
            $changed = true;
        }
    }
    if (!$changed) {
        return $html;
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    $rendered = '';
    foreach ($body->childNodes ?? [] as $node) {
        $rendered .= $doc->saveHTML($node);
    }
    return $rendered;
}
