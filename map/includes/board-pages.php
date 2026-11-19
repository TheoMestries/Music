<?php
declare(strict_types=1);
require_once __DIR__.'/notes.php';
require_once __DIR__.'/board-settings.php';
require_once __DIR__.'/board-drawings.php';
require_once __DIR__.'/board-measurements.php';
require_once __DIR__.'/board-entities.php';
require_once __DIR__.'/board-selection.php';
require_once __DIR__.'/board-tokens.php';
require_once __DIR__.'/board-history.php';
require_once __DIR__.'/board-pings.php';
require_once __DIR__.'/board-turns.php';

function mapEnsureBoard(int $campaignId):void {
    $pdo=mapGetDatabase();
    $query=$pdo->prepare('SELECT campaign_id FROM campaign_board_state WHERE campaign_id=?');
    $query->execute([$campaignId]);$exists=$query->fetchColumn();$query->closeCursor();
    if($exists!==false)return;
    try {
        $pdo->beginTransaction();
        // The unique state row serializes simultaneous first visits.
        $insert=$pdo->prepare('INSERT OR IGNORE INTO campaign_board_state(campaign_id) VALUES (?)');$insert->execute([$campaignId]);
        if($insert->rowCount()){
            $page=$pdo->prepare("INSERT INTO campaign_board_pages(campaign_id,name) VALUES (?,'Page 1')");$page->execute([$campaignId]);
            $id=(int)$pdo->lastInsertId();$update=$pdo->prepare('UPDATE campaign_board_state SET player_page_id=? WHERE campaign_id=?');$update->execute([$id,$campaignId]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mapBoardPage(int $campaignId,int $pageId):array {
    $stmt=mapGetDatabase()->prepare('SELECT id,name,image,thumbnail,settings,settings_revision FROM campaign_board_pages WHERE campaign_id=? AND id=?');$stmt->execute([$campaignId,$pageId]);$page=$stmt->fetch();
    if(!$page)mapThrowNotFound('Page introuvable.');
    $page['id']=(int)$page['id'];$page['settings']=array_replace(mapBoardSettingsDefaults(),json_decode($page['settings'],true)?:[]);$page['settingsRevision']=(int)$page['settings_revision'];unset($page['settings_revision']);return $page;
}
function mapEnsureBoardView(int $campaignId,array $user,array $access):void {
    if(!mapCanUseGmNotes($access))return;
    $stmt=mapGetDatabase()->prepare('INSERT OR IGNORE INTO campaign_board_views(campaign_id,user_id,page_id) SELECT campaign_id,?,player_page_id FROM campaign_board_state WHERE campaign_id=?');
    $stmt->execute([$user['user_id'],$campaignId]);
}
function mapBoardSnapshot(int $campaignId,array $user,bool $playerPreview=false):array {
    $access=mapRequireCampaignAccess($campaignId,$user);mapEnsureBoard($campaignId);$pdo=mapGetDatabase();
    if ($playerPreview && !mapCanUseGmNotes($access)) mapThrowForbidden('L’aperçu joueur est réservé au MJ.');
    if (!$playerPreview) mapEnsureBoardView($campaignId,$user,$access);
    $viewer = $playerPreview ? ['user_id'=>0] : $user;
    if ($playerPreview) $access['current_user_role'] = 'player';
    $color=mapBoardParticipantColor($pdo,$campaignId,(int)$user['user_id']);
    // Keep revision, player selection and visible page consistent while another MJ writes.
    try {
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT player_page_id,revision FROM campaign_board_state WHERE campaign_id=?');$stmt->execute([$campaignId]);$state=$stmt->fetch();
        $canManage=mapCanUseGmNotes($access);$selected=(int)$state['player_page_id'];$pages=[];
        if($canManage){
            $view=$pdo->prepare('SELECT page_id FROM campaign_board_views WHERE campaign_id=? AND user_id=?');$view->execute([$campaignId,$user['user_id']]);$id=$view->fetchColumn();
            if($id!==false)$selected=(int)$id;
            $list=$pdo->prepare('SELECT id,name,thumbnail,settings FROM campaign_board_pages WHERE campaign_id=? ORDER BY id');$list->execute([$campaignId]);
            $pages=array_map(static fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name'],'thumbnail'=>$p['thumbnail'],'folder'=>(json_decode($p['settings'],true)['folder']??'')],$list->fetchAll());
        }
        $page=mapBoardPage($campaignId,$selected);unset($page['thumbnail']);
        $page['drawings'] = mapBoardDrawings($pdo, $selected, $viewer, $canManage);
        $page['entities'] = mapBoardEntities($pdo, $selected, $viewer, $access);
        $page['turns'] = mapBoardTurnSnapshot($pdo,$selected,$page['entities'],$canManage);
        $page['measurements'] = $selected === (int) $state['player_page_id'] ? mapBoardMeasurements($pdo, $selected, (int) $viewer['user_id']) : [];
        $page['pings'] = mapBoardPings($pdo,$selected);
        $page['history'] = mapBoardHistoryAvailability($pdo,$selected,(int)$viewer['user_id'],$canManage);
        $pdo->commit();
        return ['revision'=>(int)$state['revision'],'canManage'=>$canManage,'playerPageId'=>(int)$state['player_page_id'],'participantColor'=>$color,'activePage'=>$page,'pages'=>$pages];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mapBoardAction(int $campaignId,string $action,array $payload,array $user):array {
    $access=mapRequireCampaignAccess($campaignId,$user);
    if(!mapCanUseGmNotes($access))mapThrowForbidden('Seul un MJ peut gérer les pages du plateau.');
    mapEnsureBoard($campaignId);$pdo=mapGetDatabase();
    mapEnsureBoardView($campaignId,$user,$access);
    $name='';$image='';$thumbnail='';$pageId=null;
    if($action==='create'){
        $name=mapValidateNoteTitle((string)($payload['name']??''));
        $image=mapValidateDocumentImage((string)($payload['image']??''));
        $thumbnail=mapValidateDocumentImage((string)($payload['thumbnail']??''));
        if(strlen($thumbnail)>100000)throw new DomainException('La vignette est trop volumineuse.');
    }elseif(in_array($action,['go','send','delete','configure'],true)){
        $pageId=mapValidatePositiveInt($payload['pageId']??null,'Page');
    }else throw new DomainException('Action inconnue.');
    try{
        $pdo->beginTransaction();
        // Take the write lock before selecting/deleting to serialize concurrent MJ actions.
        $lock=$pdo->prepare('UPDATE campaign_board_state SET revision=revision WHERE campaign_id=?');$lock->execute([$campaignId]);
        if($pageId!==null)$currentPage=mapBoardPage($campaignId,$pageId);
        if($action==='create'){
            $settings=mapBoardSettingsDefaults();
            if(isset($payload['dominantColor'])){
                if(!is_string($payload['dominantColor'])||!preg_match('/^#[a-fA-F0-9]{6}$/D',$payload['dominantColor']))throw new DomainException('Couleur dominante invalide.');
                $settings['dominantColor']=strtolower($payload['dominantColor']);
            }
            if($image!==''){$size=getimagesizefromstring(base64_decode(explode(',',$image,2)[1]));$settings['width']=max(50,min(20000,$size[0]));$settings['height']=max(50,min(20000,$size[1]));}
            $stmt=$pdo->prepare('INSERT INTO campaign_board_pages(campaign_id,name,image,thumbnail,settings) VALUES (?,?,?,?,?)');$stmt->execute([$campaignId,$name,$image,$thumbnail,json_encode($settings,JSON_THROW_ON_ERROR)]);
        }elseif($action==='configure'){
            $expected=mapValidatePositiveInt($payload['settingsRevision']??null,'Révision des paramètres');
            if($expected!==$currentPage['settingsRevision'])mapThrowHttpException(409,'Cette page a changé ailleurs. Fermez puis rouvrez les paramètres pour charger la dernière version.');
            if(!is_array($payload['settings']??null))throw new DomainException('Paramètres incomplets.');
            $settings=mapValidateBoardSettings($payload['settings']);
            $name=mapValidateNoteTitle((string)($payload['name']??$currentPage['name']));
            $image=array_key_exists('image',$payload)?mapValidateDocumentImage((string)$payload['image']):$currentPage['image'];
            $thumbnail=array_key_exists('thumbnail',$payload)?mapValidateDocumentImage((string)$payload['thumbnail']):$currentPage['thumbnail'];
            if(strlen($thumbnail)>100000)throw new DomainException('La vignette est trop volumineuse.');
            $stmt=$pdo->prepare('UPDATE campaign_board_pages SET name=?,image=?,thumbnail=?,settings=?,settings_revision=settings_revision+1 WHERE campaign_id=? AND id=?');$stmt->execute([$name,$image,$thumbnail,json_encode($settings,JSON_THROW_ON_ERROR),$campaignId,$pageId]);
        }elseif($action==='go'){
            $stmt=$pdo->prepare('INSERT INTO campaign_board_views(campaign_id,user_id,page_id) VALUES (?,?,?) ON CONFLICT(campaign_id,user_id) DO UPDATE SET page_id=excluded.page_id');$stmt->execute([$campaignId,$user['user_id'],$pageId]);
        }elseif($action==='send'){
            $stmt=$pdo->prepare('UPDATE campaign_board_state SET player_page_id=? WHERE campaign_id=?');$stmt->execute([$pageId,$campaignId]);
        }elseif($action==='delete'){
            $fallback=$pdo->prepare('SELECT id FROM campaign_board_pages WHERE campaign_id=? AND id<>? ORDER BY id LIMIT 1');$fallback->execute([$campaignId,$pageId]);$other=$fallback->fetchColumn();$fallback->closeCursor();
            if($other===false)throw new DomainException('Gardez au moins une page dans la campagne.');
            $update=$pdo->prepare('UPDATE campaign_board_state SET player_page_id=? WHERE campaign_id=? AND player_page_id=?');$update->execute([$other,$campaignId,$pageId]);
            $stmt=$pdo->prepare('DELETE FROM campaign_board_pages WHERE campaign_id=? AND id=?');$stmt->execute([$campaignId,$pageId]);
        }
        // Visiting a private MJ page does not change the players' screen.
        if($action!=='go'){$stmt=$pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?');$stmt->execute([$campaignId]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return mapBoardSnapshot($campaignId,$user);
}
