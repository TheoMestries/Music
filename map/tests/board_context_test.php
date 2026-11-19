<?php
declare(strict_types=1);
require __DIR__.'/board_selection_test.php';

$page = ['pageId'=>$pageId];
$restored = mapReplayBoardHistory(1,false,$page,$playerOne)['activePage'];
assertNotesTest($restored['entities'][0]['id']===$entity['id'] && $restored['entities'][0]['x']===120.0
    && $restored['drawings']['players'][0]['id']===$drawingId,'Annuler la suppression restaure la sélection avec ses identifiants.');
assertNotesTest($restored['history']['redo'],'Rétablissement disponible après annulation.');
$beforeTextEdit = mapReplayBoardHistory(1,false,$page,$playerOne)['activePage'];
assertNotesTest($beforeTextEdit['drawings']['players'][0]['text']==='Ancien','Annuler une édition restaure le texte.');
expectNotesException(MapHttpException::class,409,static fn()=>mapReplayBoardHistory(1,false,$page,$playerOne),
    'Une modification ultérieure par un autre joueur est protégée.');
mapReplayBoardHistory(1,true,$page,$playerOne);
$redone = mapReplayBoardHistory(1,true,$page,$playerOne)['activePage'];
assertNotesTest(!$redone['entities'] && !$redone['drawings']['players'],'Rétablir supprime à nouveau toute la sélection.');
mapReplayBoardHistory(1,false,$page,$playerOne);
$undoPlayerTwo = mapReplayBoardHistory(1,false,$page,$playerTwo)['activePage'];
assertNotesTest($undoPlayerTwo['entities'][0]['x']===110.0,'Chaque joueur annule son propre déplacement.');
$newEdit = mapMutateBoardSelection(1,'edit_board_text',array_replace($edit,['text'=>'Dernière version']),$playerOne);
assertNotesTest(!$newEdit['activePage']['history']['redo'],'Une nouvelle action remplace le rétablissement.');

$one = mapBoardSnapshot(1,$playerOne);
$two = mapBoardSnapshot(1,$playerTwo);
assertNotesTest($one['participantColor'] !== $two['participantColor'],'Les participants reçoivent des couleurs distinctes.');
$published = mapPublishBoardPing(1,$page + ['point'=>[-20.5,77.25]],$playerOne);
$ping = mapBoardSnapshot(1,$playerTwo)['activePage']['pings'][0];
assertNotesTest($ping['color']===$one['participantColor'] && $published['participantColor']===$one['participantColor']
    && $ping['x']===-20.5 && $ping['y']===77.25 && $published['revision']>$one['revision'],
    'Le signal est synchronisé aux coordonnées exactes et avec la couleur de son auteur.');
$pdo->exec('UPDATE board_pings SET created_ms=0');
assertNotesTest(mapBoardSnapshot(1,$playerTwo)['activePage']['pings']===[],'Le signal expire.');

$pages = mapBoardSnapshot(1,$owner)['pages'];
$private = end($pages)['id'];
mapBoardAction(1,'go',['pageId'=>$private],$owner);
mapPublishBoardPing(1,['pageId'=>$private,'point'=>[10,10]],$owner);
assertNotesTest(mapBoardSnapshot(1,$playerOne)['activePage']['pings']===[],'Le signal du MJ reste sur sa page.');
expectNotesException(MapHttpException::class,403,static fn()=>mapPublishBoardPing(1,['pageId'=>$private,'point'=>[10,10]],$playerOne),
    'Un joueur ne peut pas envoyer un signal sur une autre page.');
echo "OK - historique personnel, conflits, signaux et couleurs.\n";
