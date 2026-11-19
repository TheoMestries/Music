<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/board-pages.php';
require_once __DIR__.'/../includes/response.php';
try{
    $user=mapGetCurrentUser();if(!$user)mapJsonError('Authentification requise.',401);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))mapJsonError('Méthode non autorisée.',405);
    $payload=$method==='GET'?$_GET:mapReadJsonBody(3500000);
    $id=mapValidatePositiveInt($payload['campaignId']??null,'Campagne');
    if($method==='GET'){
        $playerPreview = ($payload['playerPreview'] ?? null) === '1';
        if (isset($payload['tokenSettings'])) {
            $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
            $entityId = mapValidatePositiveInt($payload['tokenSettings'],'Jeton');
            mapJsonSuccess(['token'=>mapBoardTokenSettings($id,$pageId,$entityId,(string)($payload['layer'] ?? ''),$user)]);
        }
        if (isset($payload['entityImage'])) {
            $image = mapBoardEntityImage($id,mapValidatePositiveInt($payload['entityImage'],'Entité'),$user,$playerPreview);
            if (!preg_match('~^data:(image/(?:png|jpeg|gif|webp));base64,([a-zA-Z0-9+/=]+)$~D', $image, $parts)) {
                mapThrowNotFound('Image indisponible.');
            }
            header('Content-Type: '.$parts[1]);
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            echo base64_decode($parts[2],true);
            exit;
        }
        if(isset($payload['pageId'])){
            $access=mapRequireCampaignAccess($id,$user);if(!mapCanUseGmNotes($access))mapThrowForbidden('Seul un MJ peut consulter les paramètres des pages.');
            mapJsonSuccess(['page'=>mapBoardPage($id,mapValidatePositiveInt($payload['pageId'],'Page'))]);
        }
        mapJsonSuccess(['board'=>mapBoardSnapshot($id,$user,$playerPreview)]);
    }
    mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??null);
    $action = $payload['action'] ?? '';
    if (is_string($action) && str_starts_with($action,'turn_')) {
        mapJsonSuccess(['board'=>mapMutateBoardTurns($id,$action,$payload,$user)]);
    }
    if ($action === 'copy_entity') {
        $_SESSION['map_board_clipboard'][$user['user_id']][$id] = mapCopyBoardEntity($id,$payload,$user);
        mapJsonSuccess(['copied'=>true]);
    }
    if ($action === 'paste_entity') {
        $clipboard = $_SESSION['map_board_clipboard'][$user['user_id']][$id] ?? null;
        mapJsonSuccess(['board'=>mapMutateBoardEntity($id,$action,$payload,$user,$clipboard)]);
    }
    if ($action === 'ping') mapJsonSuccess(['board'=>mapPublishBoardPing($id,$payload,$user)]);
    if (in_array($action,['undo','redo'],true)) mapJsonSuccess(['board'=>mapReplayBoardHistory($id,$action === 'redo',$payload,$user)]);
    if (in_array($action,['move_board_selection','delete_board_selection','edit_board_text'],true)) mapJsonSuccess(['board'=>mapMutateBoardSelection($id,$action,$payload,$user)]);
    if (in_array($action,['place_entity','resize_entity','configure_token','change_entity_layer','order_entity'],true)) mapJsonSuccess(['board'=>mapMutateBoardEntity($id,$action,$payload,$user)]);
    if ($action === 'measure') mapJsonSuccess(['board'=>mapPublishBoardMeasurement($id, $payload, $user)]);
    if (in_array($action, ['draw', 'clear_drawings'], true)) {
        mapJsonSuccess(['board'=>mapMutateBoardDrawing($id, $action, $payload, $user)]);
    }
    mapJsonSuccess(['board'=>mapBoardAction($id,(string)($payload['action']??''),$payload,$user)]);
}catch(MapHttpException $e){mapJsonError($e->getMessage(),$e->getStatusCode());
}catch(DomainException $e){mapJsonError($e->getMessage(),422);
}catch(Throwable $e){error_log('[board-pages] '.$e->getMessage());mapJsonError('Impossible de gérer les pages.',500);}
