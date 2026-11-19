<?php
declare(strict_types=1);
require __DIR__.'/board_pages_test.php';

$board = mapBoardSnapshot(1, $owner);
$page = $board['activePage']['id'];
$drawing = ['type'=>'rectangle', 'points'=>[[10,20],[60,80]], 'stroke'=>'#ffffff', 'fill'=>'transparent', 'width'=>4];
$payload = ['pageId'=>$page, 'layer'=>'players', 'drawing'=>$drawing];
$freehand = array_replace($drawing, ['type'=>'freehand', 'points'=>[[10,20],[60,80],[100,20]], 'fill'=>'#cc8877']);
$filled = mapMutateBoardDrawing(1, 'draw', array_replace($payload, ['layer'=>'gm','drawing'=>$freehand]), $owner);
assertNotesTest($filled['activePage']['drawings']['gm'][0]['fill'] === '#cc8877', 'Le remplissage à main levée est conservé.');
mapMutateBoardDrawing(1, 'clear_drawings', array_replace($payload, ['layer'=>'gm']), $owner);
mapMutateBoardDrawing(1, 'draw', $payload, $playerOne);
mapMutateBoardDrawing(1, 'draw', $payload, $playerTwo);
mapMutateBoardDrawing(1, 'draw', array_replace($payload, ['layer'=>'gm']), $owner);
mapMutateBoardDrawing(1, 'draw', array_replace($payload, ['layer'=>'map']), $owner);
$playerDrawings = mapBoardSnapshot(1, $playerOne)['activePage']['drawings'];
assertNotesTest(count($playerDrawings['players']) === 2 && count($playerDrawings['map']) === 1, 'Les deux calques publics sont visibles.');
assertNotesTest(!isset($playerDrawings['gm']), 'Le calque MJ ne quitte pas le serveur pour un joueur.');
assertNotesTest(count(mapBoardSnapshot(1, $owner)['activePage']['drawings']['gm']) === 1, 'Calque MJ visible pour le MJ.');
expectNotesException(MapHttpException::class,403,static fn()=>mapMutateBoardDrawing(1,'draw',array_replace($payload, ['layer'=>'map']),$playerOne),'Le joueur ne peut pas dessiner sur la carte.');
$private = array_values(array_filter($board['pages'], static fn($entry)=>$entry['id'] !== $page))[0]['id'];
expectNotesException(MapHttpException::class,403,static fn()=>mapMutateBoardDrawing(1,'draw',array_replace($payload, ['pageId'=>$private]),$playerOne),'Le joueur ne peut pas dessiner sur une page non publiée.');
mapMutateBoardDrawing(1, 'clear_drawings', $payload, $playerOne);
assertNotesTest(count(mapBoardSnapshot(1, $playerOne)['activePage']['drawings']['players']) === 1, 'Le joueur efface seulement ses dessins.');
$cleared = mapMutateBoardDrawing(1, 'clear_drawings', $payload, $owner);
assertNotesTest($cleared['activePage']['drawings']['players'] === [] && count($cleared['activePage']['drawings']['gm']) === 1, 'Le MJ efface seulement le calque ciblé.');
echo "OK - dessins par calque, droits et effacement.\n";
$text = ['type'=>'text', 'points'=>[[20,40]], 'text'=>"Entrée\n<réservée>", 'font'=>'Georgia',
    'size'=>18, 'bold'=>true, 'italic'=>true, 'fill'=>'#000000', 'stroke'=>'#ffffff'];
$placed = mapMutateBoardDrawing(1, 'draw', array_replace($payload, ['drawing'=>$text]), $playerOne);
assertNotesTest(array_intersect_key($placed['activePage']['drawings']['players'][0],$text) == $text, 'Le texte et sa mise en forme sont synchronisés.');
expectNotesException(DomainException::class, 0, static fn()=>mapValidateBoardDrawing(array_replace($text,['size'=>161]), $board['activePage']['settings']), 'Une taille invalide est rejetée.');
