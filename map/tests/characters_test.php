<?php
declare(strict_types=1);
require __DIR__ . '/document_options_test.php';
$character = mapCreateCampaignNote(1, 'Ham Efoy', '<p>Voyageur</p>', null, ['PNJ'], 'personal', $owner, 'character');
assertNotesTest($character['options']['type'] === 'character', 'Type personnage conservé à la création.');
$avatar = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO0cAAAAASUVORK5CYII=';
$token = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$character = mapUpdateCampaignNote(1, $character['id'], 1, 'Ham Efoy', '<p>Voyageur</p>', null, ['PNJ'], 'personal', $owner, [
 'readers'=>['3'], 'editors'=>['4'], 'gmNotes'=>'Identité secrète', 'image'=>$avatar, 'tokenImage'=>$token, 'rich'=>true
]);
assertNotesTest($character['options']['image'] === $avatar && $character['options']['tokenImage'] === $token, 'Avatar et jeton distincts enregistrés.');
$reader = mapGetCampaignNote(1, $character['id'], $playerOne);
assertNotesTest($reader['options']['gmNotes'] === '' && !$reader['canEdit'], 'Secret et droits du lecteur respectés.');
$editor = mapGetCampaignNote(1, $character['id'], $playerTwo);
assertNotesTest($editor['canEdit'], 'Editeur du personnage autorisé.');
$options = $character['options']; $options['tokenImage'] = 'data:image/png;base64,PHN2Zz4=';
expectNotesException(DomainException::class, 0, static fn() => mapUpdateCampaignNote(1, $character['id'], $character['revision'], 'Ham Efoy', '', null, [], 'personal', $owner, $options), 'Faux fichier image rejeté.');
$options = $character['options']; $options['tokenImage'] = ''; $options['type'] = 'document';
$character = mapUpdateCampaignNote(1, $character['id'], $character['revision'], 'Ham Efoy', '', null, [], 'personal', $owner, $options);
assertNotesTest($character['options']['tokenImage'] === '' && $character['options']['image'] === $avatar, 'Retrait du jeton sans supprimer l’avatar.');
assertNotesTest($character['options']['type'] === 'character', 'Type conservé pendant les modifications.');
echo "OK - création de personnage, avatar, jeton, permissions et secrets MJ.\n";
