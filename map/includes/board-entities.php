<?php
declare(strict_types=1);

function mapCanControlBoardEntity(array $entity, array $note, array $access, array $user): bool
{
    return mapCanUseGmNotes($access) || (int)$entity['author_id'] === (int)$user['user_id']
        || (mapCanViewCampaignNote($note,$access,$user) && mapCanEditCampaignNote($note,$access,$user));
}

function mapBoardEntities(PDO $pdo, int $pageId, array $user, array $access): array
{
    $sql = "SELECT e.id,e.layer,e.x,e.y,e.cells,e.author_id,e.note_id,n.title,
        n.author_user_id,n.visibility,n.options,e.token_settings,sheet.field_values AS sheet_values,
        json_extract(n.options,'$.type') AS type,
        (COALESCE(json_extract(n.options,'$.image'),'') <> '' OR
         (json_extract(n.options,'$.type') = 'character' AND COALESCE(json_extract(n.options,'$.tokenImage'),'') <> '')) AS has_image
        FROM board_entities e JOIN campaign_notes n ON n.id=e.note_id
        LEFT JOIN character_sheets sheet ON sheet.note_id=n.id WHERE e.page_id=?";
    if (!mapCanUseGmNotes($access)) $sql .= " AND e.layer <> 'gm'";
    $stmt = $pdo->prepare($sql . ' ORDER BY e.stack_order,e.id');
    $stmt->execute([$pageId]);
    return array_map(static fn(array $row): array => [
        'id'=>(int)$row['id'], 'layer'=>$row['layer'], 'name'=>$row['title'],
        'character'=>$row['type'] === 'character', 'hasImage'=>(bool)$row['has_image'],
        'sheetNoteId'=>$row['type'] === 'character' && mapCanViewCampaignNote($row,$access,$user) ? (int)$row['note_id'] : null,
        'x'=>(float)$row['x'], 'y'=>(float)$row['y'],
        'cells'=>(float)$row['cells'], 'canResize'=>mapCanControlBoardEntity($row,$row,$access,$user),
        'token'=>$row['type'] === 'character' ? mapPublicTokenOptions($row,mapCanUseGmNotes($access),mapCanControlBoardEntity($row,$row,$access,$user)) : null,
    ], $stmt->fetchAll());
}

function mapRequireBoardEntityControl(PDO $pdo, int $campaignId, int $pageId, int $id, string $layer, array $access, array $user): array
{
    $stmt = $pdo->prepare('SELECT * FROM board_entities WHERE id=? AND page_id=? AND layer=?');
    $stmt->execute([$id,$pageId,$layer]);
    $entity = $stmt->fetch();
    $stmt->closeCursor();
    if (!$entity) mapThrowNotFound('Entité introuvable.');
    $note = mapGetCampaignNoteRecord($pdo,$campaignId,(int)$entity['note_id']);
    if (!$note || !mapCanControlBoardEntity($entity,$note,$access,$user)) mapThrowNotFound('Entité non modifiable.');
    return $entity;
}

function mapBoardEntityImage(int $campaignId, int $entityId, array $user, bool $playerPreview = false): string
{
    $access = mapRequireCampaignAccess($campaignId, $user);
    $stmt = mapGetDatabase()->prepare('SELECT n.options,e.layer,e.page_id,s.player_page_id
        FROM board_entities e
        JOIN campaign_board_pages p ON p.id=e.page_id
        JOIN campaign_board_state s ON s.campaign_id=p.campaign_id
        JOIN campaign_notes n ON n.id=e.note_id WHERE p.campaign_id=? AND e.id=?');
    $stmt->execute([$campaignId,$entityId]);
    $row = $stmt->fetch();
    if (!$row || (($playerPreview || !mapCanUseGmNotes($access)) && ($row['layer'] === 'gm' || (int)$row['page_id'] !== (int)$row['player_page_id']))) {
        mapThrowNotFound('Entité introuvable.');
    }
    $options = mapDocumentOptions($row);
    if (($options['type'] ?? null) === 'character' && !empty($options['tokenImage'])) return $options['tokenImage'];
    return $options['image'] ?? '';
}

function mapBoardEntityStackOrder(PDO $pdo, int $pageId, string $layer, bool $front): ?array
{
    $direction = $front ? 'DESC' : 'ASC';
    $stmt = $pdo->prepare("SELECT id,stack_order FROM board_entities WHERE page_id=? AND layer=?
        ORDER BY stack_order $direction,id $direction LIMIT 1");
    $stmt->execute([$pageId,$layer]);
    return $stmt->fetch() ?: null;
}

function mapCopyBoardEntity(int $campaignId, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $entityId = mapValidatePositiveInt($payload['entityId'] ?? null,'Jeton');
    $layer = $payload['layer'] ?? null;
    if (!in_array($layer,['players','map','gm'],true)) throw new DomainException('Calque invalide.');
    if (!$canManage && $layer !== 'players') mapThrowForbidden('Ce calque est réservé au MJ.');
    $pdo = mapGetDatabase();
    mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,$canManage);
    return mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$entityId,$layer,$access,$user);
}

function mapMutateBoardEntity(int $campaignId, string $action, array $payload, array $user, ?array $clipboard = null): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $layer = $payload['layer'] ?? null;
    if (!in_array($layer,['players','map','gm'],true)) throw new DomainException('Calque invalide.');
    if (!$canManage && $layer !== 'players') mapThrowForbidden('Ce calque est réservé au MJ.');
    $arranging = in_array($action,['change_entity_layer','order_entity'],true);
    if ($arranging && !$canManage) mapThrowForbidden('Cette action est réservée au MJ.');
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?');
        $lock->execute([$campaignId]);
        $page = mapBoardPage($campaignId,$pageId);
        if (!$canManage) {
            $state = $pdo->prepare('SELECT player_page_id FROM campaign_board_state WHERE campaign_id=?');
            $state->execute([$campaignId]);
            if ((int)$state->fetchColumn() !== $pageId) mapThrowForbidden('Cette page n’est pas affichée aux joueurs.');
            $state->closeCursor();
        }
        $track = $action !== 'configure_token' || in_array($payload['change'] ?? null,['configure','markers'],true)
            && ($payload['saveDefault'] ?? false) !== true;
        $before = [];
        $destinationLayer = $layer;
        if ($action === 'paste_entity') {
            mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,$canManage);
            if (!$clipboard) throw new DomainException('Le presse-papiers est vide.');
            $note = mapGetCampaignNoteRecord($pdo,$campaignId,(int)$clipboard['note_id']);
            if ((!$canManage && $clipboard['layer'] !== 'players') || !$note
                || !mapCanViewCampaignNote($note,$access,$user) || !mapCanControlBoardEntity($clipboard,$note,$access,$user)) {
                mapThrowForbidden('Vous ne pouvez plus coller ce jeton.');
            }
            [$x,$y] = mapValidateBoardPoint($payload['point'] ?? null,$page['settings']);
            $front = mapBoardEntityStackOrder($pdo,$pageId,$layer,true);
            $pdo->prepare('INSERT INTO board_entities(page_id,note_id,author_id,layer,x,y,cells,token_settings,stack_order)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                    $pageId,$clipboard['note_id'],$user['user_id'],$layer,$x,$y,
                    $clipboard['cells'],$clipboard['token_settings'],($front['stack_order'] ?? -1)+1,
                ]);
            $entityId = (int)$pdo->lastInsertId();
        } elseif ($action === 'place_entity') {
            $noteId = mapValidatePositiveInt($payload['noteId'] ?? null,'Document');
            $note = mapGetCampaignNote($campaignId,$noteId,$user);
            if (!$note['canEdit']) mapThrowForbidden('Vous ne pouvez pas placer cet élément sur le plateau.');
            [$x,$y] = mapValidateBoardPoint($payload['point'] ?? null,$page['settings']);
            $front = mapBoardEntityStackOrder($pdo,$pageId,$layer,true);
            $stmt = $pdo->prepare('INSERT INTO board_entities(page_id,note_id,author_id,layer,x,y,token_settings,stack_order)
                SELECT ?,id,?,?,?,?,token_defaults,? FROM campaign_notes WHERE id=?');
            $stmt->execute([$pageId,$user['user_id'],$layer,$x,$y,($front['stack_order'] ?? -1)+1,$noteId]);
            $entityId = (int)$pdo->lastInsertId();
        } elseif ($action === 'configure_token') {
            $entityId = mapValidatePositiveInt($payload['entityId'] ?? null,'Jeton');
            $entity = mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$entityId,$layer,$access,$user);
            $before = [$entityId=>$entity];
            mapUpdateBoardToken($pdo,$campaignId,$entity,$payload,$access,$user);
        } elseif ($action === 'resize_entity') {
            $entityId = mapValidatePositiveInt($payload['entityId'] ?? null,'Entité');
            $cells = $payload['cells'] ?? null;
            if ((!is_int($cells) && !is_float($cells)) || !is_finite($cells) || $cells < .25 || $cells > 100) {
                throw new DomainException('Taille de l’entité invalide.');
            }
            $before = [$entityId=>mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$entityId,$layer,$access,$user)];
            $stmt = $pdo->prepare('UPDATE board_entities SET cells=? WHERE id=?');
            $stmt->execute([$cells,$entityId]);
        } elseif ($arranging) {
            mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,$canManage);
            $entityId = mapValidatePositiveInt($payload['entityId'] ?? null,'Jeton');
            $before = [$entityId=>mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$entityId,$layer,$access,$user)];
            $direction = $payload['direction'] ?? null;
            if ($action === 'change_entity_layer') {
                $destinationLayer = $payload['destinationLayer'] ?? null;
                if (!in_array($destinationLayer,['players','map','gm'],true)) throw new DomainException('Calque invalide.');
            } elseif (!in_array($direction,['front','back'],true)) {
                throw new DomainException('Ordre invalide.');
            }
            $front = $action === 'change_entity_layer' || $direction === 'front';
            $edge = mapBoardEntityStackOrder($pdo,$pageId,$destinationLayer,$front);
            if ($layer !== $destinationLayer || ($action === 'order_entity' && (int)($edge['id'] ?? 0) !== $entityId)) {
                $order = $edge === null ? 0 : (int)$edge['stack_order']+($front ? 1 : -1);
                $pdo->prepare('UPDATE board_entities SET layer=?,stack_order=? WHERE id=?')->execute([$destinationLayer,$order,$entityId]);
            }
        } else throw new DomainException('Action inconnue.');
        if ($track) {
            mapRecordBoardHistory($pdo,$pageId,$arranging ? 'gm' : $layer,$user,['board_entities'=>$before],[
                'board_entities'=>mapBoardHistoryRows($pdo,$pageId,$destinationLayer,'board_entities',[$entityId]),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId,$user);
}
