<?php
declare(strict_types=1);
require __DIR__.'/characters_test.php';
require_once __DIR__.'/../includes/character-sheets.php';
require_once __DIR__.'/../includes/codex-character-sheets.php';
$fields=[['id'=>'name','page'=>0,'label'=>'Nom','x'=>.1,'y'=>.1,'w'=>.3,'h'=>.05,'font'=>.015]];
$template=mapCreateSheetTemplate(1,'Fiche test',[$avatar],$fields,$owner);
assertNotesTest(count($template['fields'])===1,'Zone enregistrée.');
expectNotesException(MapHttpException::class,403,static fn()=>mapCreateSheetTemplate(1,'Interdit',[$avatar],$fields,$playerOne),'Joueur ne crée pas de modèle.');
$otherCampaign=mapCreateCampaignNote(1,'Deuxième personnage','',null,[],'personal',$owner,'character');
$sheet=mapAssignCharacterSheet(1,$character['id'],$template['id'],$owner);
assertNotesTest($sheet['revision']===1,'Fiche attribuée.');
$result=mapSaveCharacterSheet(1,$character['id'],1,['name'=>'Ham Efoy'],$playerTwo);
assertNotesTest($result['revision']===2,'Editeur autorisé remplit la fiche.');
expectNotesException(MapHttpException::class,403,static fn()=>mapSaveCharacterSheet(1,$character['id'],2,['name'=>'Interdit'],$playerOne),'Lecteur ne modifie pas les valeurs.');
expectNotesException(MapHttpException::class,409,static fn()=>mapSaveCharacterSheet(1,$character['id'],1,['name'=>'Ancien'],$owner),'Conflit de révision préservé.');
$sheet=mapGetCharacterSheet(1,$character['id'],$playerOne);
assertNotesTest($sheet['values']->name==='Ham Efoy','Lecteur lit la fiche autorisée.');
mapAssignCharacterSheet(1,$otherCampaign['id'],$template['id'],$owner);
$other=mapGetCharacterSheet(1,$otherCampaign['id'],$owner);
assertNotesTest(!isset($other['values']->name),'Valeurs séparées par personnage.');
expectNotesException(DomainException::class,0,static fn()=>mapSaveCharacterSheet(1,$character['id'],2,['unknown'=>'x'],$owner),'Champ inconnu refusé.');
$bad=$fields;$bad[0]['x']=.99;
expectNotesException(DomainException::class,0,static fn()=>mapCreateSheetTemplate(1,'Hors page',[$avatar],$bad,$owner),'Zone hors page refusée.');
expectNotesException(DomainException::class,0,static fn()=>mapCreateSheetTemplate(1,'Vide',[$avatar],[],$owner),'Modèle vide refusé.');
expectNotesException(DomainException::class,0,static fn()=>mapGetCharacterSheet(1,$n['id'],$owner),'Fiche réservée aux personnages.');
$pdo=mapGetDatabase();$pdo->exec("INSERT INTO campaigns(name,owner_user_id) VALUES ('Autre campagne',1)");
$otherId=(int)$pdo->lastInsertId();
expectNotesException(MapHttpException::class,404,static fn()=>mapSheetTemplate($otherId,$template['id'],$owner),'Modèle isolé par campagne.');
$new=mapCreateSheetTemplate(1,'Autre modèle',[$avatar],$fields,$owner);
$codexId = mapCodexSaveEntry($pdo, 1, null, ['category' => 'Personnages', 'title' => 'Personnage Codex'], null);
mapCodexSaveEntryRule($pdo, 1, $codexId, (int) $playerOne['user_id'], ['read' => 'allow']);
mapCodexSaveEntryRule($pdo, 1, $codexId, (int) $playerTwo['user_id'], ['edit' => 'allow']);
assertNotesTest(mapCodexCharacterSheet($pdo, 1, $codexId) === null, 'Une fiche Codex commence sans modèle.');
expectNotesException(MapHttpException::class, 403,
    static fn () => mapAssignCodexCharacterSheet($pdo, 1, $codexId, $new['id'], $playerTwo), 'Un joueur éditeur ne choisit pas le modèle.');
$codexSheet = mapAssignCodexCharacterSheet($pdo, 1, $codexId, $new['id'], $owner);
assertNotesTest($codexSheet['template']['id'] === $new['id'] && $codexSheet['revision'] === 1, 'Les modèles Sus20 sont réutilisables dans le Codex.');
expectNotesException(MapHttpException::class, 403,
    static fn () => mapSaveCodexCharacterSheet($pdo, 1, $codexId, 1, ['name' => 'Interdit'], $playerOne), 'Le lecteur Codex ne remplit pas les zones.');
mapSaveCodexCharacterSheet($pdo, 1, $codexId, 1, ['name' => 'Texte Codex'], $playerTwo);
expectNotesException(MapHttpException::class, 409,
    static fn () => mapSaveCodexCharacterSheet($pdo, 1, $codexId, 1, ['name' => 'Conflit'], $playerTwo), 'La fiche Codex protège les révisions.');
expectNotesException(MapHttpException::class, 404,
    static fn () => mapCodexCharacterSheetRights($pdo, $otherId, $codexId, $owner), 'La fiche Codex est isolée par campagne.');
$reassigned=mapAssignCharacterSheet(1,$character['id'],$new['id'],$owner);
assertNotesTest(!isset($reassigned['values']->name),'Changer de modèle remet la fiche à zéro.');
assertNotesTest(mapCodexCharacterSheet($pdo, 1, $codexId)['values']->name === 'Texte Codex', 'Les valeurs de la note et du Codex sont indépendantes.');

expectNotesException(MapHttpException::class,403,static fn()=>mapUpdateSheetTemplate(1,$new['id'],'Interdit',[$avatar],$fields,$playerOne),'Seul le créateur modifie le modèle.');
expectNotesException(MapHttpException::class,403,static fn()=>mapDeleteSheetTemplate(1,$new['id'],$playerOne),'Seul le créateur supprime le modèle.');
$copy = mapCopySheetTemplate(1, $new['id'], $playerOne);
assertNotesTest($copy['id'] !== $new['id'] && $copy['fields'] === $new['fields'] && $copy['pages'] === $new['pages'], 'La copie reprend les pages et les zones.');
$list = array_column(mapSheetTemplates(1, $playerOne), null, 'id');
assertNotesTest($list[$copy['id']]['isOwner'] && !$list[$new['id']]['isOwner'], 'Les droits suivent le créateur de chaque modèle.');
mapUpdateSheetTemplate(1, $copy['id'], 'Ma fiche', [$avatar], $fields, $playerOne);
assertNotesTest(mapSheetTemplate(1, $new['id'], $owner)['name'] === $new['name'], 'Modifier la copie conserve le modèle original.');
mapDeleteSheetTemplate(1, $copy['id'], $playerOne);

$revision = $reassigned['revision'];
mapSaveCharacterSheet(1, $character['id'], $revision, ['name' => 'Personnage'], $owner);
$renamed = $fields;
$renamed[0]['label'] = 'Prénom';
mapUpdateSheetTemplate(1, $new['id'], 'Modèle modifié', [$avatar], $renamed, $owner);
$updated = mapGetCharacterSheet(1, $character['id'], $owner);
assertNotesTest($updated['values']->name === 'Personnage' && $updated['revision'] === $revision + 2, 'Modifier le modèle conserve les valeurs des zones existantes et actualise la révision.');
$codexUpdated = mapCodexCharacterSheet($pdo, 1, $codexId);
assertNotesTest($codexUpdated['values']->name === 'Texte Codex' && $codexUpdated['revision'] === 3, 'Le modèle partagé actualise aussi les fiches du Codex.');
$renamed[0]['id'] = 'first_name';
mapUpdateSheetTemplate(1, $new['id'], 'Modèle modifié', [$avatar], $renamed, $owner);
$updated = mapGetCharacterSheet(1, $character['id'], $owner);
assertNotesTest(!isset($updated['values']->name), 'Les zones supprimées ne laissent pas de valeurs impossibles à enregistrer.');
assertNotesTest(!isset(mapCodexCharacterSheet($pdo, 1, $codexId)['values']->name), 'Les zones retirées sont aussi nettoyées dans le Codex.');
mapSaveCharacterSheet(1, $character['id'], $updated['revision'], ['first_name' => 'Ham'], $owner);

$pdo->prepare('UPDATE campaigns SET default_sheet_template_id=? WHERE id=1')->execute([$new['id']]);
mapDeleteSheetTemplate(1, $new['id'], $owner);
assertNotesTest($pdo->query('SELECT default_sheet_template_id FROM campaigns WHERE id=1')->fetchColumn() === null, 'Le modèle supprimé cesse d’être le modèle par défaut.');
assertNotesTest(!in_array($new['id'],array_column(mapSheetTemplates(1,$owner),'id'),true),'Le modèle supprimé disparaît du sélecteur.');
expectNotesException(MapHttpException::class,404,static fn()=>mapCopySheetTemplate(1,$new['id'],$playerOne),'Un modèle supprimé ne peut plus être copié.');
expectNotesException(MapHttpException::class,404,static fn()=>mapAssignCharacterSheet(1,$otherCampaign['id'],$new['id'],$owner),'Un modèle supprimé ne peut plus être attribué.');
assertNotesTest(mapGetCharacterSheet(1,$character['id'],$owner)['values']->first_name === 'Ham','La fiche attribuée conserve ses valeurs après la suppression du modèle.');
echo "OK - modèles, zones, fiches indépendantes, permissions, conflits et isolation.\n";
