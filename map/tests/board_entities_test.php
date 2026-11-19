<?php
declare(strict_types=1);
require __DIR__.'/board_pages_test.php';

$pageId = mapBoardSnapshot(1,$playerOne)['playerPageId'];
$noteId = $playerPersonal['id'];
$pdo = mapGetDatabase();
$update = $pdo->prepare('UPDATE campaign_notes SET options=? WHERE id=?');
$avatar = 'data:image/png;base64,avatar';
$token = 'data:image/png;base64,token';
$update->execute([json_encode(['type'=>'character','image'=>$avatar,'tokenImage'=>$token]),$noteId]);
$payload = ['pageId'=>$pageId,'noteId'=>$noteId,'layer'=>'players','point'=>[100,100]];
$placed = mapMutateBoardEntity(1,'place_entity',$payload,$playerOne);
$entity = $placed['activePage']['entities'][0];
assertNotesTest($entity['cells'] === 1.0 && $entity['character'] && $entity['canResize'],'Le personnage occupe une case.');
assertNotesTest(mapBoardEntityImage(1,$entity['id'],$playerTwo) === $token,'Le jeton est prioritaire et visible après placement.');
$update->execute([json_encode(['type'=>'character','image'=>$avatar]),$noteId]);
assertNotesTest(mapBoardEntityImage(1,$entity['id'],$playerTwo) === $avatar,'Le portrait est utilisé sans jeton.');
$update->execute([json_encode(['image'=>$avatar,'tokenImage'=>$token]),$noteId]);
assertNotesTest(mapBoardEntityImage(1,$entity['id'],$playerTwo) === $avatar,'Un document utilise son illustration.');
$resize = ['pageId'=>$pageId,'entityId'=>$entity['id'],'layer'=>'players','cells'=>.5];
$updated = mapMutateBoardEntity(1,'resize_entity',$resize,$playerOne);
assertNotesTest($updated['activePage']['entities'][0]['cells'] === .5,'La taille est enregistrée.');
expectNotesException(MapHttpException::class,404,static fn()=>mapMutateBoardEntity(1,'resize_entity',$resize,$playerTwo),'Un joueur ne redimensionne pas une entité appartenant à un autre.');
expectNotesException(MapHttpException::class,403,static fn()=>mapMutateBoardEntity(1,'place_entity',array_replace($payload,['layer'=>'gm']),$playerOne),'Le joueur ne peut pas placer sur le calque MJ.');
$private = mapMutateBoardEntity(1,'place_entity',array_replace($payload,['layer'=>'gm','noteId'=>$ownerPersonal['id']]),$owner);
$hidden = array_values(array_filter($private['activePage']['entities'],static fn($entry)=>$entry['layer']==='gm'))[0];
assertNotesTest(count(mapBoardSnapshot(1,$playerTwo)['activePage']['entities']) === 1,'Le calque MJ ne figure pas dans la réponse joueur.');
expectNotesException(MapHttpException::class,404,static fn()=>mapBoardEntityImage(1,$hidden['id'],$playerOne),'L’image privée est protégée.');
expectNotesException(MapHttpException::class,404,static fn()=>mapBoardEntityImage(1,$hidden['id'],$owner,true),'L’image du calque MJ est absente de l’aperçu.');
$privatePage = mapBoardAction(1,'create',['name'=>'Préparation MJ'],$owner);
$privateId = end($privatePage['pages'])['id'];
mapBoardAction(1,'go',['pageId'=>$privateId],$owner);
$preview = mapBoardSnapshot(1,$owner,true);
assertNotesTest($preview['activePage']['id']===$pageId && !$preview['canManage'] && $preview['pages']===[],
    'L’aperçu utilise la page affichée aux joueurs, même depuis une autre page MJ.');
assertNotesTest(count($preview['activePage']['entities'])===1 && !isset($preview['activePage']['drawings']['gm']),
    'L’aperçu exclut le calque MJ.');
expectNotesException(MapHttpException::class,403,static fn()=>mapBoardSnapshot(1,$playerOne,true),'L’aperçu est réservé au MJ.');
mapBoardAction(1,'go',['pageId'=>$pageId],$owner);
echo "OK - placement, choix d’image, redimensionnement et permissions.\n";
