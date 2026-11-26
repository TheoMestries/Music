<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/codex-kanka.php';
require_once __DIR__ . '/../includes/codex-player-view.php';

function assertCodexProjection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = mapOpenDatabase(':memory:');
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role) VALUES ('gm-fictif', 'MJ fictif', 'test', 'gm')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Campagne fictive', 1)");
$archivePath = tempnam(sys_get_temp_dir(), 'codex-visibility-');
try {
    $zip = new ZipArchive();
    assertCodexProjection($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Archive de test inaccessible.');
    $records = [
        'campaign.json' => ['id' => 192],
        'locations/poste.json' => ['entity' => ['id' => 11, 'name' => 'Poste partagé']],
        'locations/archive.json' => ['entity' => ['id' => 12, 'name' => 'Archive réservée']],
        'characters/agent.json' => ['entity' => [
            'id' => 13,
            'name' => 'Personnage fictif',
            'entry' => '<p>Voir les <a data-mention="[entity:12]" href="#">archives secrètes</a>.</p>',
            'relationships' => [
                ['target_id' => 11, 'relation' => 'Réside ici', 'visibility_id' => 1],
                ['target_id' => 11, 'relation' => 'Lieu du sabotage', 'visibility_id' => 3],
            ],
            'entity_attributes' => [
                ['name' => 'Capacité publique', 'value' => 'Écoute'],
                ['name' => 'Propriété réservée', 'value' => 'Code', 'is_private' => true],
            ],
            'posts' => [
                ['name' => 'Rapport public', 'entry' => 'Consigne publique', 'visibility_id' => 1],
                ['name' => 'Rapport secret', 'entry' => '<a data-mention="[entity:11]" href="#">Coordonnées réservées</a>', 'visibility_id' => 3],
            ],
            'meta_gm' => 'Détail sensible',
        ]],
    ];
    foreach ($records as $file => $record) {
        $zip->addFromString($file, json_encode($record, JSON_THROW_ON_ERROR));
    }
    $zip->close();
    mapImportKankaCodex($pdo, 1, $archivePath);
} finally {
    unlink($archivePath);
}

$lookup = static fn (int $sourceId): int => (int) $pdo
    ->query("SELECT id FROM campaign_codex_entries WHERE source_key = 'kanka:192:entity:" . $sourceId . "'")->fetchColumn();
$agentId = $lookup(13);
$sharedId = $lookup(11);
$hiddenId = $lookup(12);
$allEntries = mapCodexEntries($pdo, 1);
$visibleEntries = array_diff_key($allEntries, [$hiddenId => true]);
$agent = mapCodexEntry($pdo, 1, $agentId);
$projection = mapCodexProjectEntry($agent, $visibleEntries);
$agentEditors = mapCodexPlayerEditableSections($agent, $visibleEntries);
assertCodexProjection(!$agentEditors['notes'] && !$agentEditors['proprietes'] && $agentEditors['inventaire'],
    'Les sections avec des lignes privées restent verrouillées sans bloquer les autres.');
$playerContent = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
foreach (['Détail sensible', 'Propriété réservée', 'Rapport secret', 'Lieu du sabotage', 'archives secrètes', '[[fiche:' . $hiddenId . ']]'] as $secret) {
    assertCodexProjection(!str_contains($playerContent, $secret), 'Une donnée privée a été exposée.');
}
foreach (['Capacité publique', 'Rapport public', 'Réside ici'] as $public) {
    assertCodexProjection(str_contains($playerContent, $public), 'Une donnée publique n’est pas consultable.');
}
assertCodexProjection($projection['player_description_locked'], 'Le texte avec une mention privée doit être protégé contre la réécriture.');
$publicPlace = mapCodexProjectEntry(mapCodexEntry($pdo, 1, $sharedId), $visibleEntries);
assertCodexProjection(!mapCodexMentionLinks($publicPlace), 'Une mention provenant uniquement d’une note secrète a été révélée.');

$form = mapCodexPlayerSaveForm([
    'category' => 'Objets', 'title' => 'Personnage renommé', 'body_html' => '<p>Texte remplacé</p>', 'revision' => '1',
    'target' => [$sharedId], 'label' => ['Lieu du sabotage'], 'linkVisibility' => ['0'],
    'details' => '{"override":"Valeur imposée"}', 'editPosts' => '1', 'postName' => ['Modifiée'],
], $agent, $visibleEntries);
mapCodexSaveEntry($pdo, 1, $agent, $form, null);
$updated = mapCodexEntry($pdo, 1, $agentId);
assertCodexProjection($updated['category'] === 'Personnages' && $updated['body_html'] === $agent['body_html'],
    'Une modification par un joueur ne permet ni de déplacer la fiche ni de remplacer les mentions protégées.');
assertCodexProjection(str_contains($updated['details'], 'Rapport secret') && !str_contains($updated['details'], 'Valeur imposée'),
    'Les données secrètes doivent rester intactes malgré un formulaire forgé.');
$matching = array_filter($updated['links'], static fn (array $link): bool => $link['label'] === 'Lieu du sabotage');
$visibilities = array_map('intval', array_column($matching, 'players_visible'));
sort($visibilities);
assertCodexProjection($visibilities === [0, 1], 'Une relation identique créée par un joueur doit garder la version masquée intacte.');
assertCodexProjection(!mapCodexMentionLinks(mapCodexProjectEntry(mapCodexEntry($pdo, 1, $sharedId), $visibleEntries)),
    'Réenregistrer la fiche ne doit pas rendre publiques les mentions d’une note secrète.');

try {
    mapCodexPlayerSaveForm(['category' => 'Notes', 'title' => 'Création', 'target' => [$hiddenId]], null, $visibleEntries);
    throw new RuntimeException('Une relation vers une fiche hors droits a été acceptée.');
} catch (DomainException) {
}

$nativeId = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Notes', 'title' => 'Carnet de terrain', 'details' => json_encode([
    'posts' => [['name' => 'Compte rendu', 'entry' => '<p>Retour prévu</p>', 'created_at' => '2025-01-01']],
], JSON_THROW_ON_ERROR)], null);
$native = mapCodexEntry($pdo, 1, $nativeId);
assertCodexProjection(mapCodexPlayerEditableSections($native, $visibleEntries)['notes'],
    'Une note entièrement visible doit rester éditable.');
$nativeForm = mapCodexPlayerSaveForm([
    'revision' => '1', 'editPosts' => '1', 'postOriginal' => ['0'], 'postName' => ['Compte rendu'],
    'postBody' => ['Retour confirmé'],
    'codexSection' => ['proprietes' => [
        'submitted' => '1', 'original' => [''], 'name' => ['Terrain'], 'value' => ['Stable'], 'is_pinned' => ['0'],
    ]],
], $native, $visibleEntries);
mapCodexSaveEntry($pdo, 1, $native, $nativeForm, null);
$nativeDetails = json_decode(mapCodexEntry($pdo, 1, $nativeId)['details'], false, 32, JSON_THROW_ON_ERROR);
assertCodexProjection($nativeDetails->posts[0]->entry === 'Retour confirmé' && $nativeDetails->posts[0]->created_at === '2025-01-01',
    'Modifier une note autorisée doit conserver ses métadonnées.');
assertCodexProjection($nativeDetails->entity_attributes[0]->name === 'Terrain',
    'Un joueur doit pouvoir enrichir une section sans données privées.');

foreach ([
    ['pinEntry' => [$hiddenId]],
    ['timeline' => [['title' => 'Repère secret', 'target' => $hiddenId]]],
    ['codexSection' => ['inventaire' => ['original' => [''], 'codex_entry_id' => [$hiddenId]]]],
    ['editPosts' => '1', 'postOriginal' => [''], 'postName' => ['Note'], 'postBody' => ['[[fiche:' . $hiddenId . ']]']],
] as $unsafeFields) {
    try {
        mapCodexPlayerSaveForm(['category' => 'Notes', 'title' => 'Fiche de test'] + $unsafeFields, null, $visibleEntries);
        throw new RuntimeException('Un champ spécialisé a accepté la référence privée.');
    } catch (DomainException) {
    }
}
echo "OK - projection du codex par joueur, protection des données imbriquées et conservation lors des modifications.\n";
