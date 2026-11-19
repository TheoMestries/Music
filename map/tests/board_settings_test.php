<?php
declare(strict_types=1);
require __DIR__.'/board_pages_test.php';
$beforeBoard=mapBoardSnapshot(1,$owner);$page=$beforeBoard['activePage'];
$settings=$page['settings'];
$settings=array_replace($settings,['width'=>2100,'height'=>1400,'cellSize'=>35,'folder'=>'Extérieurs','gridType'=>'hex-v','gridColor'=>'#a6291e','gridOpacity'=>45,'offsetX'=>-14,'offsetY'=>-4,'boardColor'=>'#ddd4ba','backgroundColor'=>'#ffffff','autoDominant'=>false,'scale'=>100,'unit'=>'feet','diagonal'=>'pathfinder','useDistanceScale'=>true,'crop'=>['x'=>.1,'y'=>.2,'w'=>.8,'h'=>.7]]);
$result=mapBoardAction(1,'configure',['pageId'=>$page['id'],'name'=>'Village extérieur','settings'=>$settings,'settingsRevision'=>$page['settingsRevision']],$owner);
$saved=$result['activePage'];
assertNotesTest($saved['name']==='Village extérieur'&&$saved['settings']['gridType']==='hex-v','Nom et grille enregistrés.');
assertNotesTest((float)$saved['settings']['cellSize']===35.0&&(float)$saved['settings']['width']===2100.0,'Dimensions enregistrées.');
assertNotesTest($saved['settings']['crop']['x']===.1&&$saved['settingsRevision']===$page['settingsRevision']+1,'Recadrage et révision conservés.');
$player=mapBoardSnapshot(1,$playerOne);
assertNotesTest($player['activePage']['settings']===$saved['settings'],'Les joueurs reçoivent les paramètres de leur page.');
assertNotesTest($result['revision']===$beforeBoard['revision']+1,'Modification publiée au mécanisme de synchronisation.');
expectNotesException(MapHttpException::class,403,static fn()=>mapBoardAction(1,'configure',['pageId'=>$page['id'],'settings'=>$settings,'settingsRevision'=>$saved['settingsRevision']],$playerOne),'Joueur ne configure pas les pages.');
expectNotesException(MapHttpException::class,409,static fn()=>mapBoardAction(1,'configure',['pageId'=>$page['id'],'settings'=>$settings,'settingsRevision'=>$page['settingsRevision']],$owner),'Révision périmée refusée.');
foreach([['cellSize'=>0],['width'=>-1],['gridColor'=>'url(javascript:x)'],['gridType'=>'unsupported'],['unit'=>'unknown'],['crop'=>['x'=>.9,'y'=>0,'w'=>.5,'h'=>1]]] as $bad){
    expectNotesException(DomainException::class,0,static fn()=>mapValidateBoardSettings(array_replace($settings,$bad)),'Paramètres invalides refusés.');
}
$otherPage=mapBoardSnapshot($other,$owner)['activePage'];
expectNotesException(DomainException::class,0,static fn()=>mapBoardAction(1,'configure',['pageId'=>$page['id'],'settings'=>array_replace($settings,['width'=>-1]),'settingsRevision'=>$saved['settingsRevision']],$owner),'Modification invalide annulée.');
assertNotesTest(mapBoardSnapshot(1,$owner)['activePage']['settingsRevision']===$saved['settingsRevision'],'Modification invalide ne change pas la révision.');
assertNotesTest($otherPage['settings']['gridType']==='square','Paramètres indépendants entre pages.');
echo "OK - paramètres par page, validation, synchronisation et conflits.\n";
