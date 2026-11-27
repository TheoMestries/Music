<?php
declare(strict_types=1);
require __DIR__.'/characters_test.php';
require_once __DIR__.'/../includes/character-sheets.php';
require_once __DIR__.'/../includes/codex-character-sheets.php';
require_once __DIR__.'/../includes/board-tokens.php';
$fields=[['id'=>'name','page'=>0,'label'=>'Nom','x'=>.1,'y'=>.1,'w'=>.3,'h'=>.05,'font'=>.015]];
$template=mapCreateSheetTemplate(1,'Fiche test',[$avatar],$fields,$owner);
assertNotesTest(count($template['fields'])===1,'Zone enregistrée.');
assertNotesTest($template['visibility'] === 'private' && mapSheetTemplates(1, $playerOne) === [], 'Un nouveau modèle est privé pour son créateur.');
expectNotesException(MapHttpException::class, 404, static fn () => mapSheetTemplate(1, $template['id'], $playerOne), 'Les pages du modèle privé sont protégées.');
expectNotesException(MapHttpException::class, 404, static fn () => mapCopySheetTemplate(1, $template['id'], $playerOne), 'Un autre membre ne copie pas un modèle privé.');
expectNotesException(MapHttpException::class, 404,
    static fn () => mapAssignCharacterSheet(1, $character['id'], $template['id'], $secondaryGm), 'Même un autre MJ ne peut pas attribuer le modèle privé.');
mapGetDatabase()->prepare("UPDATE character_sheet_templates SET fields=json_remove(fields, '$[0].type') WHERE id=?")->execute([$template['id']]);
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
expectNotesException(MapHttpException::class, 403, static fn () => mapSetSheetTemplateVisibility(1, $new['id'], 'public', $secondaryGm), 'Seul le créateur peut publier.');
mapSetSheetTemplateVisibility(1, $new['id'], 'campaign', $owner);
$copy = mapCopySheetTemplate(1, $new['id'], $playerOne);
assertNotesTest($copy['id'] !== $new['id'] && $copy['fields'] === $new['fields'] && $copy['pages'] === $new['pages']
    && $copy['visibility'] === 'private', 'La copie reprend les pages et les zones avec une visibilité privée.');
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

$mixedFields = [
    $fields[0],
    ['id' => 'ready', 'page' => 0, 'label' => 'Prêt', 'type' => 'checkbox', 'x' => .1, 'y' => .2, 'w' => .03, 'h' => .03, 'font' => .015],
    ['id' => 'rested', 'page' => 0, 'label' => 'Reposé', 'type' => 'checkbox', 'x' => .2, 'y' => .2, 'w' => .03, 'h' => .03, 'font' => .015],
];
$mixedTemplate = mapCreateSheetTemplate(1, 'Cases et texte', [$avatar], $mixedFields, $owner);
assertNotesTest(array_column($mixedTemplate['fields'], 'type') === ['text', 'checkbox', 'checkbox'], 'Le modèle enregistre le type de chaque zone.');
$mixedSheet = mapAssignCharacterSheet(1, $character['id'], $mixedTemplate['id'], $owner);
assertNotesTest(array_column(mapTokenSheetFields($pdo, $character['id']), 'id') === ['name'], 'Seules les zones de texte sont proposées pour les barres de jeton.');
expectNotesException(DomainException::class, 0,
    static fn () => mapSaveCharacterSheet(1, $character['id'], $mixedSheet['revision'], ['ready' => 'true'], $owner), 'Une case attend un booléen.');
mapSaveCharacterSheet(1, $character['id'], $mixedSheet['revision'], ['name' => 'Ham', 'ready' => true, 'rested' => false], $playerTwo);
$mixedCodex = mapAssignCodexCharacterSheet($pdo, 1, $codexId, $mixedTemplate['id'], $owner);
mapSaveCodexCharacterSheet($pdo, 1, $codexId, $mixedCodex['revision'], ['name' => 'Codex', 'ready' => false, 'rested' => true], $playerTwo);
$mixedFields[1]['label'] = 'Prêt à partir';
mapUpdateSheetTemplate(1, $mixedTemplate['id'], 'Cases renommées', [$avatar], $mixedFields, $owner);
$currentValues = mapGetCharacterSheet(1, $character['id'], $owner)['values'];
assertNotesTest($currentValues->name === 'Ham' && $currentValues->ready === true && $currentValues->rested === false,
    'La bibliothèque conserve le texte et les deux états des cases après modification du modèle.');
$codexValues = mapCodexCharacterSheet($pdo, 1, $codexId)['values'];
assertNotesTest($codexValues->name === 'Codex' && $codexValues->ready === false && $codexValues->rested === true,
    'Le Codex conserve ses propres valeurs typées.');
$mixedFields[1]['type'] = 'text';
$mixedFields[0]['type'] = 'checkbox';
mapUpdateSheetTemplate(1, $mixedTemplate['id'], 'Types modifiés', [$avatar], $mixedFields, $owner);
assertNotesTest((array) mapGetCharacterSheet(1, $character['id'], $owner)['values'] === ['rested' => false]
    && (array) mapCodexCharacterSheet($pdo, 1, $codexId)['values'] === ['rested' => true],
    'Une modification de type retire les anciennes valeurs incompatibles dans les deux fiches.');
echo "OK - modèles, zones, fiches indépendantes, permissions, conflits et isolation.\n";
