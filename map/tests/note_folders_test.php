<?php
declare(strict_types=1);
require __DIR__ . '/document_options_test.php';
mapCreateNoteFolder(1, 'Objets', $owner);
assertNotesTest(in_array('Objets', mapListNoteFolders(1, $owner), true), 'Dossier vide conservé.');
assertNotesTest(!in_array('Objets', mapListNoteFolders(1, $playerOne), true), 'Dossier privé non divulgué.');
$before = mapGetCampaignNote(1, $n['id'], $owner);
$moved = mapMoveCampaignNote(1, $n['id'], 'Objets', $owner);
assertNotesTest($moved['folder'] === 'Objets', 'Document déplacé.');
assertNotesTest($moved['content'] === $before['content'] && $moved['options'] === $before['options'], 'Contenu et permissions conservés.');
assertNotesTest($moved['revision'] === $before['revision'] + 1, 'Révision mise à jour.');
expectNotesException(MapHttpException::class, 403, static fn() => mapMoveCampaignNote(1, $n['id'], null, $playerOne), 'Lecteur ne peut pas déplacer.');
$moved = mapMoveCampaignNote(1, $n['id'], null, $playerTwo);
assertNotesTest($moved['folder'] === null, 'Editeur peut retirer le document du dossier.');
assertNotesTest(in_array('Objets', mapListNoteFolders(1, $owner), true), 'Dossier conservé après retrait du dernier document.');
expectNotesException(DomainException::class, 0, static fn() => mapCreateNoteFolder(1, '', $owner), 'Dossier vide invalide.');
echo "OK - dossiers persistants, déplacement, permissions et conservation du document.\n";
