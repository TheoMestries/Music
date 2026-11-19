<?php
declare(strict_types=1);
require __DIR__ . '/document_options_test.php';
$shared = mapCreateCampaignNote(1, 'Ancien document partagé', '', null, [], 'shared', $owner);
$shared = mapUpdateCampaignNote(1, $shared['id'], 1, $shared['title'], '', null, [], 'shared', $owner, [
 'readers'=>['3'], 'editors'=>[], 'gmNotes'=>'', 'rich'=>false, 'image'=>''
]);
assertNotesTest(mapGetCampaignNote(1, $shared['id'], $playerOne)['id'] === $shared['id'], 'Lecteur sélectionné autorisé.');
expectNotesException(MapHttpException::class,404,static fn()=>mapGetCampaignNote(1,$shared['id'],$playerTwo),'Ancienne visibilité ne contourne pas la sélection du journal.');
$library = static fn(array $viewer): string => mapCampaignLibraryRevision(
    1,mapRequireCampaignAccess(1,$viewer),$viewer,mapListNoteFolders(1,$viewer)
);
$before = $library($playerTwo);
$character = mapCreateCampaignNote(1,'Personnage à partager','',null,[],'gm',$owner,'character');
assertNotesTest($library($playerTwo) === $before,'Une création privée ne modifie pas le curseur du joueur.');
$granted = mapUpdateCampaignNote(1,$character['id'],1,$character['title'],'',null,[],'gm',$owner,[
    'readers'=>[], 'editors'=>[(string)$playerTwo['user_id']],
]);
$after = $library($playerTwo);
assertNotesTest($after !== $before,'Donner accès change le curseur du joueur.');
$visible = array_column(mapListCampaignNotes(1,$playerTwo),null,'id');
assertNotesTest($visible[$character['id']]['canEdit'],'Le personnage partagé est visible et contrôlable.');
mapUpdateCampaignNote(1,$character['id'],$granted['revision'],$character['title'],'',null,[],'gm',$owner,[
    'readers'=>[], 'editors'=>[],
]);
assertNotesTest($library($playerTwo) === $before,'Retirer l’accès rétablit la liste visible initiale.');
echo "OK - permissions du journal et suivi de la bibliothèque.\n";
