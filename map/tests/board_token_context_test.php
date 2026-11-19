<?php
declare(strict_types=1);
require __DIR__.'/board_entities_test.php';

$target = ['pageId'=>$pageId,'layer'=>'players','entityId'=>$entity['id']];
$place = ['pageId'=>$pageId,'layer'=>'players','point'=>[100,100]];
$update->execute([json_encode(['type'=>'character']),$noteId]);
$visible = mapBoardSnapshot(1,$playerOne)['activePage']['entities'][0];
assertNotesTest($visible['sheetNoteId']===$noteId,'Le contrôleur peut ouvrir sa fiche de personnage.');
assertNotesTest(mapBoardSnapshot(1,$playerTwo)['activePage']['entities'][0]['sheetNoteId']===null,'La fiche personnelle reste inaccessible.');
expectNotesException(MapHttpException::class,404,static fn()=>mapCopyBoardEntity(1,$target,$playerTwo),'Le jeton d’un autre joueur ne peut pas être copié.');
$clipboard = mapCopyBoardEntity(1,$target,$playerOne);
$remove = $target + ['items'=>[['kind'=>'entity','id'=>$entity['id']]]];
mapMutateBoardSelection(1,'delete_board_selection',$remove,$playerOne);
$pasted = mapMutateBoardEntity(1,'paste_entity',$place,$playerOne,$clipboard)['activePage']['entities'][0];
assertNotesTest($pasted['id']!==$entity['id'] && $pasted['cells']===.5 && $pasted['sheetNoteId']===$noteId,
    'Le collage recrée une copie indépendante avec la taille et la fiche du jeton copié.');
mapReplayBoardHistory(1,false,$target,$playerOne);
assertNotesTest(mapBoardSnapshot(1,$playerOne)['activePage']['entities']===[],'Le collage peut être annulé.');
mapReplayBoardHistory(1,true,$target,$playerOne);

$second = mapMutateBoardEntity(1,'paste_entity',$place,$playerOne,$clipboard)['activePage']['entities'][1];
$token = array_replace($target,['entityId'=>$pasted['id']]);
$front = $token + ['direction'=>'front'];
$layerChange = $token + ['destinationLayer'=>'gm'];
expectNotesException(MapHttpException::class,403,static fn()=>mapMutateBoardEntity(1,'order_entity',$front,$playerOne),'L’ordre est réservé au MJ.');
expectNotesException(MapHttpException::class,403,static fn()=>mapMutateBoardEntity(1,'change_entity_layer',$layerChange,$playerOne),'Le changement de calque est réservé au MJ.');
$playerIds = static fn($board) => array_column(
    array_filter($board['activePage']['entities'],static fn($item)=>$item['layer']==='players'),'id');
assertNotesTest($playerIds(mapMutateBoardEntity(1,'order_entity',$front,$owner))===[$second['id'],$pasted['id']],'Le jeton passe au-dessus.');
assertNotesTest($playerIds(mapMutateBoardEntity(1,'order_entity',$token + ['direction'=>'back'],$owner))===[$pasted['id'],$second['id']],'Le jeton passe derrière.');
mapMutateBoardEntity(1,'change_entity_layer',$layerChange,$owner);
assertNotesTest($playerIds(mapBoardSnapshot(1,$playerOne))===[$second['id']],'Un jeton déplacé sur le calque MJ disparaît de la vue joueur.');
assertNotesTest($playerIds(mapReplayBoardHistory(1,false,$token,$owner))===[$pasted['id'],$second['id']],'Annuler restaure le calque et l’ordre.');
mapReplayBoardHistory(1,true,$token,$owner);
assertNotesTest($playerIds(mapBoardSnapshot(1,$playerOne))===[$second['id']],'Rétablir déplace à nouveau le jeton.');
echo "OK - presse-papiers de jetons, fiche, droits MJ et ordre des calques.\n";
