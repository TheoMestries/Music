<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/scenarios.php';

$_SERVER['SCRIPT_NAME'] = '/map/scenarios.php';

function assertScenariosTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapOpenDatabase(':memory:');
$pdo->exec("INSERT INTO users (id, username, display_name, password_hash, role) VALUES (1, 'gm', 'MJ', 'unused', 'gm')");
$pdo->exec("INSERT INTO campaigns (id, name, owner_user_id) VALUES (1, 'Campagne A', 1), (2, 'Campagne B', 1)");
$pdo->exec("INSERT INTO campaign_scenarios (id, campaign_id, title) VALUES (1, 1, 'Test A'), (2, 2, 'Test B')");
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMqQAAAAASUVORK5CYII=', true);
$validImage = mapScenarioStoreImage($pdo, 1, 1, $png);
$otherImage = mapScenarioStoreImage($pdo, 2, 2, $png);
$payload = ['title' => 'Nouveau nom', 'blocks' => [
    ['title' => 'Rencontre', 'html' => '<h2>[[fiche:12]]</h2><p><strong>Texte</strong></p><script>secret</script>'
        . "<img data-scenario-image=\"$validImage\" src=\"https://example.test/image\" onerror=\"action()\"><img data-scenario-image=\"$otherImage\">"
        . '<a href="javascript:action()">Lien</a>', 'collapsed' => true],
    ['title' => 'Introduction', 'html' => '<p>Après la rencontre</p>'],
]];

assertScenariosTest(mapScenarioSave($pdo, 1, 1, 1, $payload) === 2, 'La sauvegarde doit avancer la révision.');
$scenario = mapScenarioFind($pdo, 1, 1);
assertScenariosTest($scenario['title'] === 'Nouveau nom' && array_column($scenario['blocks'], 'title') === ['Rencontre', 'Introduction'],
    'Le scénario doit retrouver le nom et les blocs dans l’ordre envoyé.');
$stored = $scenario['blocks'][0];
assertScenariosTest($stored['collapsed'] && $stored['html'] === '<h2>[[fiche:12]]</h2><p><strong>Texte</strong></p>'
    . '<img data-scenario-image="' . $validImage . '" alt="">Lien', 'Le texte doit conserver la mise en forme et les références autorisées.');
$rendered = mapScenarioHtml($stored['html'], mapScenarioImageIds($pdo, 1), static fn (int $id): string => mapScenarioImageUrl(1, 1, $id));
assertScenariosTest(str_contains($rendered, 'src="/map/scenario-image.php?campaignId=1&amp;scenarioId=1&amp;imageId=' . $validImage . '"'),
    'L’image doit utiliser la route privée du scénario à la lecture.');
$readHtml = mapScenarioHtml($stored['html'] . '<p>[[fiche:99]]</p><a href="https://example.test/[[fiche:12]]"><strong>[[fiche:12]]</strong></a>',
    mapScenarioImageIds($pdo, 1), null, static fn (string $text): string => mapCodexRichMentionMarkup($text, [12 => ['title' => 'Le Phare']],
        static fn (array $query): string => mapUrlWithQuery('/codex.php', ['campaignId' => 1] + $query), true));
assertScenariosTest(str_contains($readHtml, '<h2><a class="codex-mention" href="/map/codex.php?campaignId=1&amp;fiche=12" target="_blank" rel="noopener">Le Phare</a></h2>')
    && str_contains($readHtml, '<span class="codex-mention-unavailable">Fiche indisponible</span>')
    && str_contains($readHtml, '<a href="https://example.test/[[fiche:12]]" target="_blank" rel="noopener noreferrer"><strong>[[fiche:12]]</strong></a>'),
    'La lecture doit transformer les références dans le texte sans modifier les liens existants.');

foreach ([[1, 1, 409], [2, 2, 404]] as [$campaignId, $revision, $status]) {
    try {
        mapScenarioSave($pdo, $campaignId, 1, $revision, $payload);
        throw new RuntimeException('La sauvegarde aurait dû échouer.');
    } catch (MapHttpException $error) {
        assertScenariosTest($error->getStatusCode() === $status, 'Statut inattendu lors du refus de sauvegarde.');
    }
}
echo "Scénarios OK\n";
