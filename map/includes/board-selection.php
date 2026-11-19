<?php
declare(strict_types=1);

function mapRequireBoardDrawingControl(PDO $pdo, int $id, int $pageId, string $layer, array $user, bool $canManage): array
{
    $stmt = $pdo->prepare('SELECT author_id,drawing FROM board_drawings WHERE id=? AND page_id=? AND layer=?');
    $stmt->execute([$id,$pageId,$layer]);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    if (!$row || (!$canManage && (int)$row['author_id'] !== (int)$user['user_id'])) {
        mapThrowNotFound('Dessin ou texte non modifiable.');
    }
    return json_decode($row['drawing'], true, 512, JSON_THROW_ON_ERROR);
}

function mapMutateBoardSelection(int $campaignId, string $action, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $layer = $payload['layer'] ?? null;
    if (!in_array($layer,['players','map','gm'],true)) throw new DomainException('Calque invalide.');
    if (!$canManage && $layer !== 'players') mapThrowForbidden('Ce calque est réservé au MJ.');
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        $settings = mapBoardPage($campaignId,$pageId)['settings'];
        if (!$canManage) {
            $stmt = $pdo->prepare('SELECT player_page_id FROM campaign_board_state WHERE campaign_id=?');
            $stmt->execute([$campaignId]);
            $published = (int)$stmt->fetchColumn();
            $stmt->closeCursor();
            if ($published !== $pageId) mapThrowForbidden('Cette page n’est pas affichée aux joueurs.');
        }
        $before = ['board_drawings'=>[], 'board_entities'=>[]];
        if ($action === 'edit_board_text') {
            $id = mapValidatePositiveInt($payload['drawingId'] ?? null,'Texte');
            $drawing = mapRequireBoardDrawingControl($pdo,$id,$pageId,$layer,$user,$canManage);
            $before['board_drawings'] = mapBoardHistoryRows($pdo,$pageId,$layer,'board_drawings',[$id]);
            if ($drawing['type'] !== 'text') throw new DomainException('Cet élément n’est pas un texte.');
            $drawing['text'] = $payload['text'] ?? null;
            $drawing = mapValidateBoardDrawing($drawing,$settings);
            $pdo->prepare('UPDATE board_drawings SET drawing=? WHERE id=?')->execute([json_encode($drawing,JSON_THROW_ON_ERROR),$id]);
        } elseif (in_array($action,['move_board_selection','delete_board_selection'],true)) {
            $items = $payload['items'] ?? null;
            $deleting = $action === 'delete_board_selection';
            $delta = $deleting ? [0,0] : ($payload['delta'] ?? null);
            if (!is_array($items) || !array_is_list($items) || !$items || !is_array($delta) || !array_is_list($delta) || count($delta)!==2) {
                throw new DomainException('Sélection invalide.');
            }
            foreach ($delta as $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite($value) || abs($value)>20000) throw new DomainException('Déplacement invalide.');
            }
            $drawingUpdate = $pdo->prepare($deleting ? 'DELETE FROM board_drawings WHERE id=?' : 'UPDATE board_drawings SET drawing=? WHERE id=?');
            $entityUpdate = $pdo->prepare($deleting ? 'DELETE FROM board_entities WHERE id=?' : 'UPDATE board_entities SET x=?,y=? WHERE id=?');
            $seen = [];
            foreach ($items as $item) {
                if (!is_array($item)) throw new DomainException('Élément invalide.');
                $id = mapValidatePositiveInt($item['id'] ?? null,'Élément');
                $kind = $item['kind'] ?? null;
                if (!in_array($kind,['drawing','entity'],true)) throw new DomainException('Type d’élément invalide.');
                if (isset($seen[$kind][$id])) continue;
                $seen[$kind][$id] = true;
                if ($kind === 'entity') {
                    $entity = mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$id,$layer,$access,$user);
                    $before['board_entities'][$id] = $entity;
                    if ($deleting) {
                        $entityUpdate->execute([$id]);
                        continue;
                    }
                    [$x,$y] = mapValidateBoardPoint([$entity['x']+$delta[0],$entity['y']+$delta[1]],$settings);
                    $entityUpdate->execute([$x,$y,$id]);
                } else {
                    $drawing = mapRequireBoardDrawingControl($pdo,$id,$pageId,$layer,$user,$canManage);
                    $before['board_drawings'] += mapBoardHistoryRows($pdo,$pageId,$layer,'board_drawings',[$id]);
                    if ($deleting) {
                        $drawingUpdate->execute([$id]);
                        continue;
                    }
                    $drawing['points'] = array_map(static fn($p)=>[$p[0]+$delta[0],$p[1]+$delta[1]],$drawing['points']);
                    $drawing = mapValidateBoardDrawing($drawing,$settings);
                    $drawingUpdate->execute([json_encode($drawing,JSON_THROW_ON_ERROR),$id]);
                }
            }
        } else throw new DomainException('Action de sélection inconnue.');
        $after = [];
        foreach ($before as $table=>$rows) {
            $after[$table] = mapBoardHistoryRows($pdo,$pageId,$layer,$table,array_keys($rows));
        }
        mapRecordBoardHistory($pdo,$pageId,$layer,$user,$before,$after);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId,$user);
}
