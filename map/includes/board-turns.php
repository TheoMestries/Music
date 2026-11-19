<?php
declare(strict_types=1);

function mapBoardTurnTracker(PDO $pdo, int $pageId): array
{
    $query = $pdo->prepare('SELECT active_entry_id,sort_mode,auto_sort FROM board_turn_trackers WHERE page_id=?');
    $query->execute([$pageId]);
    return $query->fetch() ?: ['active_entry_id'=>null,'sort_mode'=>'high','auto_sort'=>0];
}

function mapBoardTurnEntries(PDO $pdo, int $pageId): array
{
    $query = $pdo->prepare("SELECT t.*,e.layer,COALESCE(NULLIF(json_extract(e.token_settings,'$.name'),''),n.title,t.label) AS name,
        COALESCE(json_extract(e.token_settings,'$.tint'),'') AS tint
        FROM board_turn_entries t LEFT JOIN board_entities e ON e.id=t.entity_id
        LEFT JOIN campaign_notes n ON n.id=e.note_id WHERE t.page_id=? ORDER BY t.position,t.id");
    $query->execute([$pageId]);
    return $query->fetchAll();
}

function mapBoardTurnSnapshot(PDO $pdo, int $pageId, array $entities, bool $canManage): array
{
    $tracker = mapBoardTurnTracker($pdo,$pageId);
    $rows = mapBoardTurnEntries($pdo,$pageId);
    $active = (int)($tracker['active_entry_id'] ?? $rows[0]['id'] ?? 0);
    $visible = array_column($entities,null,'id');
    $result = ['activeId'=>null,'activeEntityId'=>null,'entries'=>[],
        'autoSort'=>(bool)$tracker['auto_sort'],'sortMode'=>$tracker['sort_mode']];
    foreach ($rows as $row) {
        $entityId = $row['entity_id'] === null ? null : (int)$row['entity_id'];
        $entity = $visible[$entityId] ?? null;
        if ($entityId !== null && !$entity) continue;
        $id = (int)$row['id'];
        if ($id === $active) {
            $result['activeId'] = $id;
            $result['activeEntityId'] = $entityId;
        }
        $result['entries'][] = ['id'=>$id,'entityId'=>$entityId,'name'=>$row['name'],
            'hasImage'=>$entity['hasImage'] ?? false,'initiative'=>(float)$row['initiative'],
            'increment'=>(float)$row['increment'],
            'canEdit'=>$canManage || ($row['layer'] === 'players' && ($entity['canResize'] ?? false))];
    }
    return $result;
}

function mapBoardTurnNumber(mixed $value): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite($value) || abs($value)>1000000000) {
        throw new DomainException('Saisissez un nombre compris entre −1 000 000 000 et 1 000 000 000.');
    }
    return round((float)$value,2);
}

function mapSortBoardTurns(PDO $pdo, int $pageId, string $mode): void
{
    $rows = mapBoardTurnEntries($pdo,$pageId);
    $layers = ['players'=>0,'map'=>1,'gm'=>2,''=>3];
    $collator = class_exists(Collator::class) ? new Collator('fr') : null;
    $alphabetical = static function (string $a, string $b) use ($collator): int {
        return $collator ? (int)$collator->compare($a,$b) : strnatcmp(mb_strtolower($a),mb_strtolower($b));
    };
    usort($rows, static fn($a,$b) => match ($mode) {
        'high' => $b['initiative'] <=> $a['initiative'],
        'low' => $a['initiative'] <=> $b['initiative'],
        'az' => $alphabetical($a['name'],$b['name']),
        'za' => $alphabetical($b['name'],$a['name']),
        'layer' => [$layers[$a['layer'] ?? ''],$a['tint']] <=> [$layers[$b['layer'] ?? ''],$b['tint']],
    });
    $update = $pdo->prepare('UPDATE board_turn_entries SET position=? WHERE id=?');
    foreach ($rows as $position=>$row) $update->execute([$position,$row['id']]);
}

function mapMutateBoardTurns(int $campaignId, string $action, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    if (!$canManage && !in_array($action,['turn_add_entity','turn_update'],true)) {
        mapThrowForbidden('Cette action est réservée au MJ.');
    }
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,$canManage);
        $pdo->prepare('INSERT OR IGNORE INTO board_turn_trackers(page_id) VALUES (?)')->execute([$pageId]);
        $tracker = mapBoardTurnTracker($pdo,$pageId);
        $rows = mapBoardTurnEntries($pdo,$pageId);
        $byId = array_column($rows,null,'id');
        $ids = array_column($rows,'id');
        $active = $tracker['active_entry_id'] ?? $ids[0] ?? null;
        $index = array_search($active,$ids,true);
        $setActive = $pdo->prepare('UPDATE board_turn_trackers SET active_entry_id=? WHERE page_id=?');
        $autoSort = (bool)$tracker['auto_sort'];
        switch ($action) {
            case 'turn_add_entity':
            case 'turn_add_custom':
                $entityId = null;
                $label = '';
                $increment = 0;
                if ($action === 'turn_add_entity') {
                    $entityId = mapValidatePositiveInt($payload['entityId'] ?? null,'Jeton');
                    $layer = $payload['layer'] ?? null;
                    if (!in_array($layer,['players','map','gm'],true)) throw new DomainException('Calque invalide.');
                    if (!$canManage && $layer !== 'players') mapThrowForbidden('Ce calque est réservé au MJ.');
                    mapRequireBoardEntityControl($pdo,$campaignId,$pageId,$entityId,$layer,$access,$user);
                    if (in_array($entityId,array_column($rows,'entity_id'),true)) break;
                } else {
                    $label = mapValidateNoteTitle(is_string($payload['label'] ?? null) ? $payload['label'] : '');
                    $increment = mapBoardTurnNumber($payload['increment'] ?? 0);
                }
                $initiative = mapBoardTurnNumber($payload['initiative'] ?? 0);
                $position = $rows ? end($rows)['position']+1 : 0;
                $pdo->prepare('INSERT INTO board_turn_entries(page_id,entity_id,label,initiative,increment,position) VALUES (?,?,?,?,?,?)')
                    ->execute([$pageId,$entityId,$label,$initiative,$increment,$position]);
                $setActive->execute([$active ?? (int)$pdo->lastInsertId(),$pageId]);
                if ($autoSort) mapSortBoardTurns($pdo,$pageId,$tracker['sort_mode']);
                break;
            case 'turn_update':
            case 'turn_remove':
                $id = mapValidatePositiveInt($payload['entryId'] ?? null,'Tour');
                $entry = $byId[$id] ?? null;
                if (!$entry) mapThrowNotFound('Tour introuvable.');
                if (!$canManage) {
                    if ($entry['layer'] !== 'players') mapThrowNotFound('Tour introuvable.');
                    mapRequireBoardEntityControl($pdo,$campaignId,$pageId,(int)$entry['entity_id'],'players',$access,$user);
                }
                if ($action === 'turn_update') {
                    $initiative = mapBoardTurnNumber($payload['initiative'] ?? null);
                    $pdo->prepare('UPDATE board_turn_entries SET initiative=? WHERE id=?')->execute([$initiative,$id]);
                    if ($autoSort) mapSortBoardTurns($pdo,$pageId,$tracker['sort_mode']);
                } else {
                    if ($id === (int)$active) $setActive->execute([count($ids)>1 ? $ids[($index+1)%count($ids)] : null,$pageId]);
                    $pdo->prepare('DELETE FROM board_turn_entries WHERE id=?')->execute([$id]);
                }
                break;
            case 'turn_next':
            case 'turn_previous':
                if (!$ids) break;
                $forward = $action === 'turn_next';
                $next = $ids[($index+($forward ? 1 : count($ids)-1))%count($ids)];
                $counter = $byId[$forward ? $active : $next];
                if ((float)$counter['increment'] !== 0.0) {
                    $score = round($counter['initiative']+($forward ? 1 : -1)*$counter['increment'],2);
                    $pdo->prepare('UPDATE board_turn_entries SET initiative=? WHERE id=?')->execute([$score,$counter['id']]);
                }
                $setActive->execute([$next,$pageId]);
                break;
            case 'turn_sort':
                $mode = $payload['mode'] ?? null;
                if (!in_array($mode,['high','low','az','za','layer'],true)) throw new DomainException('Tri invalide.');
                $setActive->execute([$active,$pageId]);
                $pdo->prepare('UPDATE board_turn_trackers SET sort_mode=? WHERE page_id=?')->execute([$mode,$pageId]);
                mapSortBoardTurns($pdo,$pageId,$mode);
                break;
            case 'turn_auto_sort':
                if (!is_bool($payload['enabled'] ?? null)) throw new DomainException('Option de tri invalide.');
                $pdo->prepare('UPDATE board_turn_trackers SET auto_sort=? WHERE page_id=?')->execute([(int)$payload['enabled'],$pageId]);
                break;
            case 'turn_clear':
                $pdo->prepare('DELETE FROM board_turn_entries WHERE page_id=?')->execute([$pageId]);
                break;
            default:
                throw new DomainException('Action de tour inconnue.');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId,$user);
}
