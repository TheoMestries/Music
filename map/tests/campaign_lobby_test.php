<?php
declare(strict_types=1);
require __DIR__.'/character_sheets_test.php';
require_once __DIR__.'/../includes/campaign-lobby.php';

$id = mapCreateCampaignWithTemplate('Campagne joueur',999,null,$playerOne);
assertNotesTest(mapGetCampaignAccess($id,$playerOne['user_id'])['current_user_role']==='owner','Un compte joueur crée une partie dont il devient MJ.');
assertNotesTest(mapGetCampaignAccess($id,$playerTwo['user_id'])===null,'Les parties restent privées.');
expectNotesException(MapHttpException::class,403,static fn()=>mapCreateCampaignInvite($id,$playerTwo),'Invitation réservée au propriétaire.');
mapCreateCampaignInvite($id,$playerOne);
$token = mapGetCampaignInvite($id);
assertNotesTest(mapInvitationToken($token)===$token,'Le lien contient un jeton opaque.');
assertNotesTest(mapAcceptCampaignInvite($token,$playerTwo)===$id,'Le lien inscrit le joueur à la bonne partie.');
mapAcceptCampaignInvite($token,$playerTwo);
assertNotesTest(mapGetCampaignMemberRole($id,$playerTwo['user_id'])==='player' && count(mapGetCampaignMembers($id))===1,'L’acceptation est idempotente.');
mapSetCampaignArchivedState($id,true,$playerOne);
expectNotesException(MapHttpException::class,404,static fn()=>mapAcceptCampaignInvite($token,$secondaryGm),'Une partie archivée ne reçoit plus de nouveaux membres.');
assertNotesTest(mapGetCampaignMemberRole($id,$secondaryGm['user_id'])===null,'L’invitation archivée ne crée aucune adhésion.');

$total = count(mapListCampaignsForUser($playerOne));
expectNotesException(DomainException::class,0,static fn()=>mapCreateCampaignWithTemplate('Interdite',3,$template['id'],$playerOne),'Un modèle privé ne peut pas être copié par un joueur.');
assertNotesTest(count(mapListCampaignsForUser($playerOne))===$total,'La création est annulée si le modèle est inaccessible.');
$withTemplate = mapCreateCampaignWithTemplate('Campagne avec fiche',1,$template['id'],$owner);
$newCharacter = mapCreateCampaignNote($withTemplate,'Personnage','',null,[],'personal',$owner,'character');
$defaultSheet = mapGetCharacterSheet($withTemplate,$newCharacter['id'],$owner);
assertNotesTest($defaultSheet['template']['name']===$template['name'] && $defaultSheet['template']['id']!==$template['id'],'Le modèle est copié et attaché aux nouveaux personnages.');
mapSetSheetTemplateVisibility(1, $template['id'], 'campaign', $owner);
assertNotesTest(mapSheetTemplates($id, $playerOne) === [], 'Un modèle partagé dans sa campagne ne s’affiche pas dans une autre.');
mapSetSheetTemplateVisibility(1, $template['id'], 'public', $owner);
$published = array_column(mapSheetTemplates($id, $playerOne), null, 'id');
assertNotesTest($published[$template['id']]['requiresCopy'] && !$published[$template['id']]['isOwner'], 'Le modèle public est proposé à la copie dans une autre campagne.');
assertNotesTest(mapSheetTemplates($id, $playerTwo) === [], 'Un joueur ne récupère pas un modèle externe dans une campagne où il ne gère pas les fiches.');
$reusable = array_column(mapReusableSheetTemplates($pdo, $playerOne), null, 'id');
assertNotesTest(isset($reusable[$template['id']]) && $reusable[$template['id']]['campaign_name'] === null, 'Le modèle public est disponible à la création sans exposer le nom de la campagne source.');
$localCopy = mapCopySheetTemplate($id, $template['id'], $playerOne);
assertNotesTest($localCopy['visibility'] === 'private', 'Copier un modèle public crée un modèle privé.');
$publicDefault = mapCreateCampaignWithTemplate('Campagne avec modèle public', 3, $template['id'], $playerOne);
assertNotesTest(mapGetCampaignById($publicDefault)['default_sheet_template_id'] !== null, 'Un modèle public peut aussi servir de modèle par défaut.');
mapSetSheetTemplateVisibility(1, $template['id'], 'private', $owner);
expectNotesException(MapHttpException::class, 404, static fn () => mapCopySheetTemplate($id, $template['id'], $playerOne), 'Rendre privé empêche les nouvelles copies.');
assertNotesTest(mapSheetTemplate($id, $localCopy['id'], $playerOne)['fields'] === mapSheetTemplate(1, $template['id'], $owner)['fields'],
    'Les copies existantes sont indépendantes.');
mapUpdateCampaignDetails($id,'Campagne renommée',null,$playerOne['user_id'],$playerOne,$avatar);
$updated = mapGetCampaignById($id);
assertNotesTest($updated['name']==='Campagne renommée' && $updated['has_cover'],'Le propriétaire joueur peut modifier le nom et la couverture.');
echo "OK - création, modèles par défaut, invitations et paramètres.\n";
