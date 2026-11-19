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
mapUpdateCampaignDetails($id,'Campagne renommée',null,$playerOne['user_id'],$playerOne,$avatar);
$updated = mapGetCampaignById($id);
assertNotesTest($updated['name']==='Campagne renommée' && $updated['has_cover'],'Le propriétaire joueur peut modifier le nom et la couverture.');
echo "OK - création, modèles par défaut, invitations et paramètres.\n";
