<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/character-sheets.php';
require_once __DIR__.'/../includes/codex-character-sheets.php';
require_once __DIR__.'/../includes/response.php';
try {
    $user=mapGetCurrentUser();if (!$user) mapJsonError('Authentification requise.',401);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) mapJsonError('Méthode non autorisée.',405);
    $payload=$method==='GET'?$_GET:mapReadJsonBody(7500000);
    $campaignId=mapValidatePositiveInt($payload['campaignId']??null,'Campagne');
    $access=mapRequireCampaignAccess($campaignId,$user);
    if (array_key_exists('entryId', $payload) && array_key_exists('noteId', $payload)) {
        throw new DomainException('Choisissez une seule fiche.');
    }
    $entryId = array_key_exists('entryId', $payload) ? mapValidatePositiveInt($payload['entryId'], 'Personnage') : null;
    $pdo = $entryId === null ? null : mapGetDatabase();
    $codexRights = $entryId === null ? null : mapCodexCharacterSheetRights($pdo, $campaignId, $entryId, $user);
    if ($method==='GET') {
        if (isset($payload['templateId'])) {
            if ($codexRights !== null && !$codexRights['canManage']) {
                mapThrowForbidden('Seul un MJ peut gérer les modèles de fiche.');
            }
            $id = mapValidatePositiveInt($payload['templateId'], 'Modèle');
            mapJsonSuccess(['template' => mapSheetTemplate($campaignId, $id, $user)]);
        }
        if ($entryId !== null) {
            mapJsonSuccess($codexRights + [
                'sheet' => mapCodexCharacterSheet($pdo, $campaignId, $entryId),
                'templates' => $codexRights['canManage'] ? mapSheetTemplates($campaignId, $user) : [],
            ]);
        }
        $noteId=mapValidatePositiveInt($payload['noteId']??null,'Personnage');
        $note=mapSheetCharacter($campaignId,$noteId,$user);
        mapJsonSuccess(['sheet'=>mapGetCharacterSheet($campaignId,$noteId,$user),'templates'=>mapSheetTemplates($campaignId,$user),'canManage'=>mapCanUseGmNotes($access)&&$note['canEdit'],'canEdit'=>$note['canEdit']]);
    }
    mapEnforceCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??null);
    $action=$payload['action']??'';
    if ($codexRights !== null && !$codexRights['canManage']
        && in_array($action, ['create_template', 'copy_template', 'update_template', 'delete_template'], true)) {
        mapThrowForbidden('Seul un MJ peut gérer les modèles de fiche.');
    }
    if (in_array($action, ['copy_template', 'update_template', 'delete_template'], true)) {
        $id = mapValidatePositiveInt($payload['templateId'] ?? null, 'Modèle');
        if ($action === 'copy_template') {
            mapJsonSuccess(['template' => mapCopySheetTemplate($campaignId, $id, $user)], 201);
        }
        if ($action === 'delete_template') {
            mapDeleteSheetTemplate($campaignId, $id, $user);
            mapJsonSuccess();
        }
        if (!is_array($payload['pages'] ?? null) || !is_array($payload['fields'] ?? null)) {
            throw new DomainException('Modèle incomplet.');
        }
        mapJsonSuccess(['template' => mapUpdateSheetTemplate($campaignId, $id, (string)($payload['name'] ?? ''), $payload['pages'], $payload['fields'], $user)]);
    }
    if ($action==='create_template') {
        if (!is_array($payload['pages']??null)||!is_array($payload['fields']??null)) throw new DomainException('Modèle incomplet.');
        mapJsonSuccess(['template'=>mapCreateSheetTemplate($campaignId,(string)($payload['name']??''),$payload['pages'],$payload['fields'],$user)],201);
    }
    if ($entryId !== null) {
        if ($action === 'assign') {
            $templateId = mapValidatePositiveInt($payload['templateId'] ?? null, 'Modèle');
            mapJsonSuccess(['sheet' => mapAssignCodexCharacterSheet($pdo, $campaignId, $entryId, $templateId, $user)]);
        }
        if ($action === 'save') {
            if (!is_array($payload['values'] ?? null)) throw new DomainException('Valeurs invalides.');
            $revision = mapValidatePositiveInt($payload['revision'] ?? null, 'Révision');
            mapJsonSuccess(mapSaveCodexCharacterSheet($pdo, $campaignId, $entryId, $revision, $payload['values'], $user));
        }
        mapJsonError('Action inconnue.', 404);
    }
    $noteId=mapValidatePositiveInt($payload['noteId']??null,'Personnage');
    if ($action==='assign') mapJsonSuccess(['sheet'=>mapAssignCharacterSheet($campaignId,$noteId,mapValidatePositiveInt($payload['templateId']??null,'Modèle'),$user)]);
    if ($action==='save') {
        if (!is_array($payload['values']??null)) throw new DomainException('Valeurs invalides.');
        mapJsonSuccess(mapSaveCharacterSheet($campaignId,$noteId,mapValidatePositiveInt($payload['revision']??null,'Révision'),$payload['values'],$user));
    }
    mapJsonError('Action inconnue.',404);
} catch(MapHttpException $e){mapJsonError($e->getMessage(),$e->getStatusCode());
} catch(DomainException $e){mapJsonError($e->getMessage(),422);
} catch(Throwable $e){error_log('[character-sheets] '.$e->getMessage());mapJsonError('Impossible de traiter la fiche de personnage.',500);}
