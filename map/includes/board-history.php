<?php
declare(strict_types=1);

function mapRequireActiveBoardPage(PDO $pdo, int $campaignId, int $pageId, array $user, bool $canManage): void
{
    $stmt = $pdo->prepare('SELECT s.player_page_id,v.page_id FROM campaign_board_state s
        LEFT JOIN campaign_board_views v ON v.campaign_id=s.campaign_id AND v.user_id=?
        WHERE s.campaign_id=?');
    $stmt->execute([$user['user_id'],$campaignId]);
    $state = $stmt->fetch();
    $active = $canManage ? ($state['page_id'] ?? $state['player_page_id'] ?? null) : ($state['player_page_id'] ?? null);
    if ((int)$active !== $pageId) mapThrowForbidden('Cette page n’est plus votre page active.');
}

function mapBoardHistoryRows(PDO $pdo, int $pageId, string $layer, string $table, ?array $ids = null): array
{
    if ($ids === []) return [];
    $sql = "SELECT * FROM $table WHERE page_id=? AND layer=?";
    if ($ids !== null) {
        $sql .= ' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pageId,$layer,...($ids ?? [])]);
    return array_column($stmt->fetchAll(),null,'id');
}

function mapRecordBoardHistory(PDO $pdo, int $pageId, string $layer, array $user, array $before, array $after): void
{
    $changes = [];
    foreach ($before as $table => $rows) {
        foreach (($rows + $after[$table]) as $id => $row) {
            $old = $rows[$id] ?? null;
            $new = $after[$table][$id] ?? null;
            if ($old !== $new) $changes[$table][$id] = [$old,$new];
        }
    }
    if (!$changes) return;
    $userId = $user['user_id'];
    $pdo->prepare('DELETE FROM board_history WHERE page_id=? AND user_id=? AND undone=1')->execute([$pageId,$userId]);
    $pdo->prepare('INSERT INTO board_history(page_id,user_id,layer,changes) VALUES (?,?,?,?)')
        ->execute([$pageId,$userId,$layer,json_encode($changes,JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
    $pdo->prepare('DELETE FROM board_history WHERE id IN (
        SELECT id FROM board_history WHERE page_id=? AND user_id=? ORDER BY id DESC LIMIT -1 OFFSET 40
    )')->execute([$pageId,$userId]);
}

function mapBoardHistoryAvailability(PDO $pdo, int $pageId, int $userId, bool $canManage): array
{
    $query = $pdo->prepare("SELECT DISTINCT undone FROM board_history WHERE page_id=? AND user_id=?
        AND (CAST(? AS INTEGER)=1 OR layer='players')");
    $query->execute([$pageId,$userId,(int)$canManage]);
    $states = array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
    return ['undo'=>in_array(0,$states,true),'redo'=>in_array(1,$states,true)];
}

function mapReplayBoardHistory(int $campaignId, bool $redo, array $payload, array $user): array
{
    $access = mapRequireCampaignAccess($campaignId,$user);
    $canManage = mapCanUseGmNotes($access);
    $pageId = mapValidatePositiveInt($payload['pageId'] ?? null,'Page');
    $pdo = mapGetDatabase();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE campaign_board_state SET revision=revision+1 WHERE campaign_id=?')->execute([$campaignId]);
        mapRequireActiveBoardPage($pdo,$campaignId,$pageId,$user,$canManage);
        $order = $redo ? 'ASC' : 'DESC';
        $stmt = $pdo->prepare("SELECT id,layer,changes FROM board_history WHERE page_id=? AND user_id=?
            AND undone=? AND (CAST(? AS INTEGER)=1 OR layer='players') ORDER BY id $order LIMIT 1");
        $stmt->execute([$pageId,$user['user_id'],(int)$redo,(int)$canManage]);
        $entry = $stmt->fetch();
        $stmt->closeCursor();
        if (!$entry) throw new DomainException($redo ? 'Aucune action à rétablir.' : 'Aucune action à annuler.');
        $changes = json_decode($entry['changes'],true,512,JSON_THROW_ON_ERROR);
        foreach ($changes as $table => $rows) {
            if (!in_array($table,['board_drawings','board_entities'],true)) throw new LogicException('Table inconnue.');
            $lookup = $pdo->prepare("SELECT * FROM $table WHERE id=? AND page_id=?");
            foreach ($rows as $id => [$before,$after]) {
                // Entries created before token ordering used the default stacking position.
                if ($table === 'board_entities') {
                    if ($before !== null) $before += ['stack_order'=>0];
                    if ($after !== null) $after += ['stack_order'=>0];
                }
                $expected = $redo ? $before : $after;
                $restored = $redo ? $after : $before;
                $lookup->execute([$id,$pageId]);
                $actual = $lookup->fetch() ?: null;
                $lookup->closeCursor();
                if ($actual !== $expected) {
                    mapThrowHttpException(409,'Cet élément a été modifié depuis. L’action ne peut plus être rejouée.');
                }
                if ($table === 'board_entities') {
                    foreach (array_unique(array_column(array_filter([$expected,$restored]),'note_id')) as $noteId) {
                        $note = mapGetCampaignNoteRecord($pdo,$campaignId,(int)$noteId);
                        if (!$note || !mapCanControlBoardEntity($expected ?? $restored,$note,$access,$user)) {
                            mapThrowForbidden('Vous ne contrôlez plus cet élément.');
                        }
                    }
                } elseif (!$canManage && (int)($expected ?? $restored)['author_id'] !== (int)$user['user_id']) {
                    mapThrowForbidden('Vous ne contrôlez plus ce dessin.');
                }
                if ($restored === null) {
                    $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
                } else {
                    $columns = $table === 'board_entities'
                        ? ['id','page_id','note_id','author_id','layer','x','y','cells','token_settings','stack_order']
                        : ['id','page_id','author_id','layer','drawing'];
                    $names = implode(',',$columns);
                    $parameters = implode(',',array_fill(0,count($columns),'?'));
                    $updates = implode(',',array_map(static fn($column) => "$column=excluded.$column",array_slice($columns,1)));
                    $pdo->prepare("INSERT INTO $table($names) VALUES ($parameters) ON CONFLICT(id) DO UPDATE SET $updates")
                        ->execute(array_map(static fn($column) => $restored[$column],$columns));
                }
            }
        }
        $pdo->prepare('UPDATE board_history SET undone=? WHERE id=?')->execute([(int)!$redo,$entry['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return mapBoardSnapshot($campaignId,$user);
}
