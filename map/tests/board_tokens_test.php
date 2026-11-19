<?php
declare(strict_types=1);
require __DIR__.'/board_entities_test.php';

$update->execute([json_encode(['type'=>'character','image'=>$avatar]),$noteId]);
$pdo->prepare("UPDATE campaign_notes SET visibility='shared' WHERE id=?")->execute([$noteId]);
$pdo->prepare('INSERT INTO character_sheet_templates(campaign_id,author_user_id,name,pages,fields) VALUES (?,?,?,?,?)')
    ->execute([1,$owner['user_id'],'Modèle','[]','[{"id":"hp","label":"Points de vie"}]']);
$template = (int)$pdo->lastInsertId();
mapAssignCharacterSheet(1,$noteId,$template,$owner);
mapSaveCharacterSheet(1,$noteId,1,['hp'=>'12'],$playerOne);
$base = ['pageId'=>$pageId,'layer'=>'players','entityId'=>$entity['id']];
$options = mapTokenDefaults();
$options = array_replace($options,['name'=>'Éclaireur','showName'=>true,'opacity'=>75,'tint'=>'#cc8844',
    'tooltip'=>'Information MJ','showTooltip'=>true,'gmTooltip'=>true,'barStyle'=>'compact']);
$options['bars'][0] = ['value'=>null,'max'=>20,'field'=>'hp','show'=>true];
mapMutateBoardEntity(1,'configure_token',$base+['change'=>'configure','options'=>$options],$owner);
$controls = mapBoardTokenSettings(1,$pageId,$entity['id'],'players',$playerOne);
assertNotesTest($controls['options']['bars'][0]['value']===12.0,'Valeur lue dans la fiche.');
assertNotesTest($controls['options']['tooltip']==='' && $controls['options']['gmTooltip'],'Le contrôleur non MJ ne reçoit pas le secret.');
$opponent = mapBoardSnapshot(1,$playerTwo)['activePage']['entities'][0]['token'];
assertNotesTest($opponent['tooltip']==='' && !isset($opponent['bars'][0]['field']),'Ni secret ni identifiant de caractéristique dans le rendu public.');
expectNotesException(MapHttpException::class,404,static fn()=>mapMutateBoardEntity(1,'configure_token',$base+['change'=>'markers','markers'=>['poison']],$playerTwo),'Le joueur externe au jeton ne peut pas le modifier.');
$updated = mapMutateBoardEntity(1,'configure_token',$base+['change'=>'bar','index'=>0,'value'=>8],$playerOne);
assertNotesTest($updated['activePage']['entities'][0]['token']['bars'][0]['value']===8.0,'La valeur du jeton est mise à jour.');
assertNotesTest(mapGetCharacterSheet(1,$noteId,$playerOne)['values']->hp==='8','Le réglage rapide met à jour la fiche.');
mapMutateBoardEntity(1,'configure_token',$base+['change'=>'markers','markers'=>['poison','dot-orange']],$playerOne);
mapMutateBoardEntity(1,'configure_token',$base+['change'=>'default'],$playerOne);
$placedAgain = mapMutateBoardEntity(1,'place_entity',$payload,$playerOne);
$newToken = $placedAgain['activePage']['entities'][1]['token'];
assertNotesTest($newToken['name']==='Éclaireur' && $newToken['markers']===['poison','dot-orange'],'Le réglage par défaut est réutilisé.');
assertNotesTest($newToken['tooltip']==='' && $newToken['bars'][0]['value']===8.0,'Les réglages copiés préservent droits et liaison.');
echo "OK - jetons, barres liées, marqueurs, préréglages et infobulles MJ.\n";
