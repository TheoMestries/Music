<?php
declare(strict_types=1);

require_once __DIR__ . '/campaign-codex.php';

const MAP_SCENARIO_IMAGE_BYTES = 8 * 1024 * 1024;

function mapScenarioFind(PDO $pdo, int $campaignId, int $scenarioId): array
{
    $query = $pdo->prepare('SELECT id, title, blocks, revision FROM campaign_scenarios WHERE campaign_id = ? AND id = ?');
    $query->execute([$campaignId, $scenarioId]);
    $scenario = $query->fetch();
    if ($scenario === false) {
        mapThrowNotFound('Scénario introuvable.');
    }
    $scenario['blocks'] = json_decode($scenario['blocks'], true, 64, JSON_THROW_ON_ERROR);
    return $scenario;
}

function mapScenarioImageUrl(int $campaignId, int $scenarioId, int $imageId): string
{
    return mapUrlWithQuery('/scenario-image.php', ['campaignId' => $campaignId, 'scenarioId' => $scenarioId, 'imageId' => $imageId]);
}

function mapScenarioStoreImage(PDO $pdo, int $campaignId, int $scenarioId, string $bytes): int
{
    $image = strlen($bytes) <= MAP_SCENARIO_IMAGE_BYTES ? @getimagesizefromstring($bytes) : false;
    if ($image === false || !in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
        throw new DomainException('Choisissez une image PNG, JPEG, WebP ou GIF de 8 Mio maximum.');
    }
    $query = $pdo->prepare('INSERT INTO campaign_scenario_images (scenario_id, mime, content)
        SELECT id, ?, ? FROM campaign_scenarios WHERE campaign_id = ? AND id = ?');
    $query->bindValue(1, $image['mime']);
    $query->bindValue(2, $bytes, PDO::PARAM_LOB);
    $query->bindValue(3, $campaignId, PDO::PARAM_INT);
    $query->bindValue(4, $scenarioId, PDO::PARAM_INT);
    $query->execute();
    if ($query->rowCount() !== 1) {
        mapThrowNotFound('Scénario introuvable.');
    }
    return (int) $pdo->lastInsertId();
}

function mapScenarioHtml(string $source, array $imageIds, ?callable $imageUrl = null, ?callable $mentionMarkup = null): string
{
    $renderText = $mentionMarkup === null ? null : static function (DOMText $node, string $text) use ($mentionMarkup): string {
        if (!str_contains($text, '[[fiche:')) {
            return $text;
        }
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ($parent->tagName === 'a') {
                return $text;
            }
        }
        return $mentionMarkup($text);
    };
    return mapCodexRichHtml($source, static function (DOMElement $node, string $tag, string $content) use ($imageIds, $imageUrl): ?string {
        if ($tag === 'img') {
            $id = filter_var($node->getAttribute('data-scenario-image'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !isset($imageIds[$id])) {
                return '';
            }
            $src = $imageUrl === null ? '' : ' src="' . mapEscape($imageUrl($id)) . '" loading="lazy"';
            return '<img data-scenario-image="' . $id . '" alt="' . mapEscape(mb_substr($node->getAttribute('alt'), 0, 200)) . '"' . $src . '>';
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            return preg_match('~^(?:https?://|mailto:)[^\p{C}\s]+$~iuD', $href) === 1
                ? '<a href="' . mapEscape($href) . '" target="_blank" rel="noopener noreferrer">' . $content . '</a>' : $content;
        }
        if (in_array($tag, ['div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $alignment = $node->getAttribute('align');
            if (preg_match('~^text-align:\s*(left|center|right|justify);?$~iD', trim($node->getAttribute('style')), $match)) {
                $alignment = strtolower($match[1]);
            }
            if (in_array($alignment, ['left', 'center', 'right', 'justify'], true)) {
                return '<' . $tag . ' style="text-align: ' . $alignment . '">' . $content . '</' . $tag . '>';
            }
        }
        return null;
    }, $renderText);
}

function mapScenarioImageIds(PDO $pdo, int $scenarioId): array
{
    $query = $pdo->prepare('SELECT id FROM campaign_scenario_images WHERE scenario_id = ?');
    $query->execute([$scenarioId]);
    return array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
}

function mapScenarioSave(PDO $pdo, int $campaignId, int $scenarioId, int $revision, array $payload): int
{
    $title = mapCodexText($payload['title'] ?? null, 160, 'Le nom du scénario', true);
    $blocks = $payload['blocks'] ?? null;
    if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 250) {
        throw new DomainException('Un scénario peut contenir jusqu’à 250 blocs.');
    }
    mapScenarioFind($pdo, $campaignId, $scenarioId);
    $imageIds = mapScenarioImageIds($pdo, $scenarioId);
    foreach ($blocks as &$block) {
        if (!is_array($block)) {
            throw new DomainException('Bloc de texte invalide.');
        }
        $block = [
            'title' => mapCodexText($block['title'] ?? null, 160, 'Le titre du bloc'),
            'html' => mapScenarioHtml(mapCodexText($block['html'] ?? null, 350000, 'Le texte du bloc'), $imageIds),
            'collapsed' => ($block['collapsed'] ?? false) === true,
        ];
    }
    unset($block);
    $query = $pdo->prepare('UPDATE campaign_scenarios SET title = ?, blocks = ?, revision = revision + 1
        WHERE campaign_id = ? AND id = ? AND revision = ?');
    $query->execute([$title, json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $campaignId, $scenarioId, $revision]);
    if ($query->rowCount() !== 1) {
        throw new MapHttpException(409, 'Ce scénario a changé dans un autre onglet. Vos modifications restent dans cet onglet.');
    }
    return $revision + 1;
}
