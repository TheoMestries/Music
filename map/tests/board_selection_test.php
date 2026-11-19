<?php
declare(strict_types=1);
require __DIR__.'/board_entities_test.php';

$text = ['type'=>'text','points'=>[[20,40]],'text'=>'Ancien','font'=>'Arial','size'=>18,
    'bold'=>true,'italic'=>false,'fill'=>'#000000','stroke'=>'#ffffff'];
$snapshot = mapMutateBoardDrawing(1,'draw',['pageId'=>$pageId,'layer'=>'players','drawing'=>$text],$playerOne);
$drawingId = $snapshot['activePage']['drawings']['players'][0]['id'];
$items = [['kind'=>'drawing','id'=>$drawingId],['kind'=>'entity','id'=>$entity['id']]];
$move = ['pageId'=>$pageId,'layer'=>'players','items'=>$items,'delta'=>[10,15]];
$moved = mapMutateBoardSelection(1,'move_board_selection',$move,$playerOne)['activePage'];
assertNotesTest($moved['entities'][0]['x']===110.0 && $moved['drawings']['players'][0]['points'][0]===[30,55],'Le groupe conserve ses positions relatives.');
$edit = ['pageId'=>$pageId,'layer'=>'players','drawingId'=>$drawingId,'text'=>'Nouveau'];
$updated = mapMutateBoardSelection(1,'edit_board_text',$edit,$playerOne)['activePage']['drawings']['players'][0];
assertNotesTest($updated['text']==='Nouveau' && $updated['bold'] && $updated['points'][0]===[30,55],'La réédition préserve le style et la position.');
expectNotesException(MapHttpException::class,404,static fn()=>mapMutateBoardSelection(1,'edit_board_text',$edit,$playerTwo),'Le texte d’un autre joueur est protégé.');
$grant = ['type'=>'character','editors'=>[(string)$playerTwo['user_id']]];
$update->execute([json_encode($grant),$noteId]);
$playerPage = mapBoardSnapshot(1,$playerTwo)['activePage'];
assertNotesTest($playerPage['entities'][0]['canResize'],'Le droit sur la fiche donne le contrôle du jeton.');
$entityFirst = array_replace($move,['items'=>array_reverse($items)]);
expectNotesException(MapHttpException::class,404,static fn()=>mapMutateBoardSelection(1,'move_board_selection',$entityFirst,$playerTwo),'Une sélection mixte non autorisée est rejetée.');
assertNotesTest(mapBoardSnapshot(1,$playerTwo)['activePage']['entities'][0]['x']===110.0,'Aucun déplacement partiel.');
$entityOnly = array_replace($move,['items'=>[$items[1]]]);
$final = mapMutateBoardSelection(1,'move_board_selection',$entityOnly,$playerTwo);
assertNotesTest($final['activePage']['entities'][0]['x']===120.0,'Le joueur autorisé déplace le jeton.');
expectNotesException(MapHttpException::class,404,static fn()=>mapMutateBoardSelection(1,'delete_board_selection',$entityFirst,$playerTwo),'Suppression groupée protégée.');
assertNotesTest(mapBoardSnapshot(1,$playerTwo)['activePage']['entities'][0]['x']===120.0,'La suppression refusée ne retire aucun élément.');
$deleted = mapMutateBoardSelection(1,'delete_board_selection',$move,$playerOne)['activePage'];
assertNotesTest($deleted['entities']===[] && $deleted['drawings']['players']===[],'Suppression de la sélection mixte.');
echo "OK - déplacement groupé, droits de contrôle et édition du texte.\n";
