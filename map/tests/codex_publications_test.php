<?php
declare(strict_types=1);

require __DIR__ . '/notes_test.php';
require_once __DIR__ . '/../includes/codex-character-sheets.php';

$pdo = mapGetDatabase();
$avatar = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMqQAAAAASUVORK5CYII=', true);
$template = mapCreateSheetTemplate(1, 'Modèle fictif', ['data:image/png;base64,' . base64_encode($avatar)], [
    ['id' => 'force', 'page' => 0, 'label' => 'Force', 'x' => .1, 'y' => .1, 'w' => .3, 'h' => .05, 'font' => .015],
], $owner);
$title = str_repeat('Titre long ', 20) . 'X';
$description = '<p>' . str_repeat('a', 210000) . '</p>';
$codexId = mapCodexSaveEntry($pdo, 1, null, [
    'category' => 'Personnages', 'title' => $title, 'body_html' => $description, 'summary' => 'Résumé hors bibliothèque',
    'editPosts' => '1', 'postName' => ['Informations privées'], 'postBody' => ['Texte réservé'],
], $avatar . str_repeat('a', 2100000));
assertNotesTest(mapCodexEntry($pdo, 1, $codexId)['published_note_id'] === null, 'Une fiche ne doit pas être publiée par défaut.');
expectNotesException(MapHttpException::class, 403,
    static fn () => mapSetCodexPublished($pdo, 1, $codexId, true, $playerOne), 'Le joueur ne doit pas pouvoir publier.');
mapAssignCodexCharacterSheet($pdo, 1, $codexId, $template['id'], $owner);
mapSaveCodexCharacterSheet($pdo, 1, $codexId, 1, ['force' => '12'], $owner);
mapSetCodexPublished($pdo, 1, $codexId, true, $owner);
$codex = mapCodexEntry($pdo, 1, $codexId);
$noteId = (int) $codex['published_note_id'];
$note = mapGetCampaignNote(1, $noteId, $owner);
assertNotesTest($note['title'] === $title && $note['content'] === $description && $note['options']['type'] === 'character',
    'Le nom et la description complets sont disponibles dans la bibliothèque.');
assertNotesTest(strlen($note['options']['image']) > 2800000 && !str_contains($note['content'], 'Texte réservé'),
    'Le portrait et la description sont publiés sans les notes privées.');
assertNotesTest(mapGetCharacterSheet(1, $noteId, $owner)['values']->force === '12', 'Les zones remplies sont disponibles dans la bibliothèque.');
expectNotesException(MapHttpException::class, 404, static fn () => mapGetCampaignNote(1, $noteId, $playerOne), 'La publication ne donne pas de droits aux joueurs.');

mapCodexSaveEntry($pdo, 1, $codex, ['category' => 'Personnages', 'title' => $title . 'bis',
    'body_html' => $description . '<p>Autre texte</p>', 'revision' => $codex['revision']], null, (int) $owner['user_id'], true);
expectNotesException(MapNoteConflictException::class, 0,
    static fn () => mapUpdateCampaignNote(1, $noteId, $note['revision'], $title, $description, null, [], 'gm', $owner),
    'Une modification du Codex invalide l’ancienne version de la bibliothèque.');
$fresh = mapGetCampaignNote(1, $noteId, $owner);
assertNotesTest($fresh['title'] === $title . 'bis' && str_ends_with($fresh['content'], '<p>Autre texte</p>'),
    'Le texte complet doit aussi se mettre à jour au-delà des limites des documents classiques.');
$saved = mapUpdateCampaignNote(1, $noteId, $fresh['revision'], 'Nom depuis la bibliothèque', '<p>Description depuis la bibliothèque</p>',
    null, [], 'gm', $owner, ['rich' => true, 'editors' => ['*']]);
$updated = mapCodexEntry($pdo, 1, $codexId);
assertNotesTest($updated['title'] === $saved['title'] && $updated['body_html'] === $saved['content']
    && $updated['image_id'] === $codex['image_id'] && $saved['options']['editors'] === [],
    'La bibliothèque met à jour le Codex et conserve un portrait non remplacé et la confidentialité.');
mapUpdateCampaignNote(1, $noteId, $saved['revision'], $saved['title'], $saved['content'], null, [], 'gm', $owner, ['image' => '']);
assertNotesTest(mapCodexEntry($pdo, 1, $codexId)['image_id'] === null, 'Retirer le portrait dans la bibliothèque le retire du Codex.');

$publishedSheet = mapGetCharacterSheet(1, $noteId, $owner);
mapSaveCharacterSheet(1, $noteId, $publishedSheet['revision'], ['force' => '14'], $owner);
$codexSheet = mapCodexCharacterSheet($pdo, 1, $codexId);
assertNotesTest($codexSheet['values']->force === '14', 'Les valeurs saisies dans la bibliothèque remontent au Codex.');
mapSaveCodexCharacterSheet($pdo, 1, $codexId, $codexSheet['revision'], ['force' => '16'], $owner);
assertNotesTest(mapGetCharacterSheet(1, $noteId, $owner)['values']->force === '16', 'Les valeurs saisies dans le Codex remontent à la bibliothèque.');

mapSetCodexPublished($pdo, 1, $codexId, false, $owner);
expectNotesException(MapHttpException::class, 404, static fn () => mapGetCampaignNote(1, $noteId, $owner), 'La dépublication retire la fiche de la bibliothèque.');
assertNotesTest(!in_array($noteId, array_column(mapListCampaignNotes(1, $owner), 'id'), true), 'Une publication retirée disparaît aussi de la liste.');
mapSetCodexPublished($pdo, 1, $codexId, true, $owner);
assertNotesTest((int) mapCodexEntry($pdo, 1, $codexId)['published_note_id'] === $noteId, 'Republier réutilise la même entrée.');
$itemId = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Objets', 'title' => 'Un objet fictif', 'body' => 'Une description simple'], $avatar);
mapSetCodexPublished($pdo, 1, $itemId, true, $owner);
$itemNote = mapGetCampaignNote(1, (int) mapCodexEntry($pdo, 1, $itemId)['published_note_id'], $owner);
assertNotesTest($itemNote['options']['type'] === 'document' && $itemNote['content'] === '<p>Une description simple</p>',
    'Les autres fiches sont des documents avec titre, image et description.');

echo "OK - publication du Codex et synchronisation avec la bibliothèque.\n";
