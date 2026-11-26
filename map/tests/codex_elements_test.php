<?php
declare(strict_types=1);

putenv('ATLAS_JDR_DATABASE_PATH=:memory:');
require_once __DIR__ . '/../includes/codex-elements.php';

function assertCodexElementTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCodexElementStatus(int $status, callable $action): void
{
    try {
        $action();
    } catch (MapHttpException $error) {
        assertCodexElementTest($error->getStatusCode() === $status, 'Statut de permission inattendu.');
        return;
    }
    throw new RuntimeException('Une opération non autorisée a été acceptée.');
}

$pdo = mapGetDatabase();
$pdo->exec("INSERT INTO users (username, display_name, password_hash, role)
    VALUES ('mj', 'MJ', 'fixture', 'gm'), ('player', 'Joueur', 'fixture', 'player')");
$pdo->exec("INSERT INTO campaigns (name, owner_user_id) VALUES ('Première table', 1), ('Autre table', 1)");
$pdo->exec("INSERT INTO campaign_members (campaign_id, user_id, member_role) VALUES (1, 2, 'player')");
$gm = ['user_id' => 1];
$player = ['user_id' => 2];
$parent = mapCodexSaveEntry($pdo, 1, null, ['title' => 'Fiche fictive', 'category' => 'Personnages'], null);
$target = mapCodexSaveEntry($pdo, 1, null, ['title' => 'Lieu fictif', 'category' => 'Lieux'], null);
$outside = mapCodexSaveEntry($pdo, 2, null, ['title' => 'Lieu extérieur', 'category' => 'Lieux'], null);
$revision = static fn (): int => (int) mapCodexEntry($pdo, 1, $parent)['revision'];
$notesForm = ['name' => 'Titre de la note', 'entry' => 'Texte de la note'];
mapCodexSaveEntryRule($pdo, 1, $parent, 2, ['read' => 'allow', 'edit' => 'allow']);
mapCodexSaveEntryRule($pdo, 1, $target, 2, ['read' => 'allow']);

expectCodexElementStatus(403, static fn () => mapCodexSaveElement($pdo, 1, $parent, 'notes', null,
    $notesForm + ['revision' => $revision()], $player));
$secret = mapCodexSaveElement($pdo, 1, $parent, 'notes', null,
    ['name' => 'Note secrète', 'entry' => 'Texte à garder', 'revision' => $revision()], $gm);
assertCodexElementTest(mapCodexElement($pdo, 1, $parent, 'notes', $secret)['private'], 'Une nouvelle note du MJ est privée par défaut.');
expectCodexElementStatus(404, static fn () => mapCodexSaveElement($pdo, 1, $parent, 'notes', $secret,
    $notesForm + ['revision' => $revision()], $player));

mapCodexSaveElementCreateRule($pdo, 1, $parent, 'notes', 2, ['create' => 'allow']);
mapCodexSaveEntryRule($pdo, 1, $parent, 2, ['read' => 'allow', 'edit' => 'deny']);
$created = mapCodexSaveElement($pdo, 1, $parent, 'notes', null, $notesForm + ['revision' => $revision()], $player);
assertCodexElementTest(!mapCodexElement($pdo, 1, $parent, 'notes', $created)['private'], 'Une note liée hérite de la consultation de la fiche.');
expectCodexElementStatus(403, static fn () => mapCodexSaveElement($pdo, 1, $parent, 'notes', $created,
    $notesForm + ['revision' => $revision()], $player));
mapCodexSaveElementRule($pdo, 1, $parent, 'notes', $created, 2, ['edit' => 'allow']);
mapCodexSaveElement($pdo, 1, $parent, 'notes', $created, ['name' => 'Titre modifié', 'revision' => $revision()], $player);
$notes = mapCodexElementRows(mapCodexEntry($pdo, 1, $parent), 'notes');
assertCodexElementTest(count($notes) === 2 && $notes[$created]['fields']['name'] === 'Titre modifié'
    && $notes[$secret]['fields']['entry'] === 'Texte à garder', 'La modification doit préserver les éléments voisins.');

try {
    mapCodexSaveElement($pdo, 1, $parent, 'notes', $created, [
        'entry' => '[[fiche:' . $outside . ']]', 'revision' => $revision(),
    ], $player);
    throw new RuntimeException('Une mention externe a été acceptée.');
} catch (DomainException) {
}

$relation = mapCodexSaveElement($pdo, 1, $parent, 'relations', null, [
    'label' => 'Relation fictive', 'target' => (string) $target, 'private' => '0', 'revision' => $revision(),
], $gm);
mapCodexSaveElementRule($pdo, 1, $parent, 'relations', $relation, 2, ['read' => 'deny']);
mapCodexSaveElementCreateRule($pdo, 1, $parent, 'relations', 2, ['create' => 'allow']);
$duplicate = mapCodexSaveElement($pdo, 1, $parent, 'relations', null, [
    'label' => 'Relation fictive', 'target' => (string) $target, 'revision' => $revision(),
], $player);
assertCodexElementTest($duplicate !== $relation, 'Les relations distinctes ont des identifiants indépendants de leurs données.');
mapCodexDeleteElement($pdo, 1, $parent, 'relations', $relation, $revision(), $gm);
assertCodexElementTest(mapCodexElementRules($pdo, 1, $parent, 'relations', $relation) === []
    && isset(mapCodexElementRows(mapCodexEntry($pdo, 1, $parent), 'relations')[$duplicate]),
    'Supprimer une relation doit préserver les autres et retirer ses propres règles.');

echo "OK - Création et édition individuelles, protection des éléments voisins et des références.\n";
